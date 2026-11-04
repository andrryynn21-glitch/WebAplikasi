<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AspirationStatus;
use App\Enums\ContentFlagStatus;
use App\Enums\ModerationActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Aspirations\FlagContentRequest;
use App\Http\Requests\Aspirations\ModerateContentRequest;
use App\Http\Requests\Aspirations\StoreAspirationRequest;
use App\Http\Requests\Aspirations\StoreCommentRequest;
use App\Models\Aspiration;
use App\Models\AspirationComment;
use App\Models\ContentFlag;
use App\Models\User;
use App\Services\AspirationService;
use App\Services\ModerationService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AspirationController extends Controller
{
    public function __construct(
        private readonly AspirationService $aspirations,
        private readonly ModerationService $moderation,
        private readonly SecurityEventService $security,
    ) {}

    /**
     * Daftar aspirasi kelas pengguna. Tidak ada aspirasi lintas kelas.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Aspiration::class);

        $user = $request->user();
        $classIds = $user->accessibleClassIds();

        $filters = $request->validate(['class_id' => ['sometimes', 'integer', 'min:1']]);
        if (isset($filters['class_id'])) {
            if (! in_array((int) $filters['class_id'], $classIds, true)) {
                return $this->fail('Kelas berada di luar kewenangan Anda.', 'CLASS_ACCESS_DENIED', 403);
            }
            $classIds = [(int) $filters['class_id']];
        }

        if ($classIds === []) {
            return $this->fail('Keanggotaan kelas belum aktif.', 'MEMBERSHIP_NOT_ACTIVE', 403);
        }

        $query = Aspiration::query()
            ->whereIn('class_id', $classIds)
            ->with('author:id,name')
            ->withCount('supports');

        // Moderator dapat melihat konten yang disembunyikan bila diminta eksplisit.
        if ($request->boolean('include_hidden') && $user->hasPermission('aspirations.moderate')
            && ($user->isLecturerOf($classIds[0]) || $user->isClassLeaderOf($classIds[0]))) {
            $query->whereIn('status', [
                AspirationStatus::Published->value,
                AspirationStatus::Locked->value,
                AspirationStatus::Hidden->value,
                AspirationStatus::Removed->value,
            ]);
        } else {
            $query->visible();
        }

        $sort = $request->string('sort', 'last_activity_at')->toString();
        $sortable = ['last_activity_at', 'supports_count', 'comments_count', 'created_at'];
        $query->orderByDesc('is_pinned')
            ->orderBy(in_array($sort, $sortable, true) ? $sort : 'last_activity_at', 'desc')
            ->orderByDesc('id');

        $paginator = $query->paginate($this->perPage($request));

        $items = collect($paginator->items())->map(fn (Aspiration $a) => $this->present($a, $user));

        return $this->respond($items, 'Aspirasi kelas.', 200, [
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(Request $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('view', $aspiration);

        $aspiration->load(['author:id,name'])->loadCount('supports');

        $supports = $aspiration->supports()->where('user_id', $request->user()->id)->exists();

        return $this->respond(
            array_merge($this->present($aspiration, $request->user()), ['supported_by_me' => $supports]),
            'Detail aspirasi.',
        );
    }

    public function store(StoreAspirationRequest $request): JsonResponse
    {
        $aspiration = $this->aspirations->create($request->user(), $request->validated());

        return $this->respond($this->present($aspiration->load('author:id,name'), $request->user()), 'Aspirasi dibuat.', 201);
    }

    public function comments(Request $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('view', $aspiration);

        $comments = $aspiration->comments()
            ->visible()
            ->with('author:id,name')
            ->oldest()
            ->limit(200)
            ->get()
            ->map(fn (AspirationComment $c) => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'body' => $c->body,
                'author' => $c->author?->only(['id', 'name']),
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return $this->respond($comments, 'Komentar aspirasi.');
    }

    public function storeComment(StoreCommentRequest $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('comment', $aspiration);

        $data = $request->validated();

        $comment = $this->aspirations->comment(
            $aspiration,
            $request->user(),
            $data['body'],
            $data['parent_id'] ?? null,
        );

        return $this->respond([
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 'Komentar ditambahkan.', 201);
    }

    public function support(Request $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('support', $aspiration);

        $created = $this->aspirations->support($aspiration, $request->user());

        return $this->respond([
            'supported' => true,
            'created' => $created,
            'supports_count' => $aspiration->fresh()->supports_count,
        ], $created ? 'Upvote tercatat.' : 'Anda sudah mendukung aspirasi ini.');
    }

    public function unsupport(Request $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('support', $aspiration);

        $removed = $this->aspirations->unsupport($aspiration, $request->user());

        return $this->respond([
            'supported' => false,
            'removed' => $removed,
            'supports_count' => $aspiration->fresh()->supports_count,
        ], $removed ? 'Upvote dibatalkan.' : 'Anda belum mendukung aspirasi ini.');
    }

    public function flag(FlagContentRequest $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('flag', $aspiration);

        $data = $request->validated();

        $flag = $this->moderation->flag($aspiration, $request->user(), $data['reason'], $data['note'] ?? null);

        return $this->respond(['id' => $flag->id], 'Laporan konten diterima.', 201);
    }

    public function flags(Request $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('viewFlags', $aspiration);

        $filters = $request->validate(['status' => ['sometimes', Rule::enum(ContentFlagStatus::class)]]);
        $paginator = ContentFlag::query()
            ->where(function ($query) use ($aspiration) {
                $query->where(function ($q) use ($aspiration) {
                    $q->where('flaggable_type', $aspiration->getMorphClass())
                        ->where('flaggable_id', $aspiration->id);
                })->orWhere(function ($q) use ($aspiration) {
                    $q->where('flaggable_type', (new AspirationComment)->getMorphClass())
                        ->whereIn('flaggable_id', $aspiration->comments()->select('id'));
                });
            })
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->with('flaggable')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->respond($paginator->getCollection()->map(fn (ContentFlag $flag) => [
            'id' => $flag->id,
            'content_type' => $flag->flaggable instanceof Aspiration ? 'aspiration' : 'comment',
            'content_id' => $flag->flaggable_id,
            'content_status' => $flag->flaggable->status?->value,
            'body' => $flag->flaggable->body,
            'can_moderate' => $request->user()->can('moderate', $flag->flaggable),
            'reason' => $flag->reason,
            'note' => $flag->note,
            'status' => $flag->status?->value,
            'reviewed_at' => $flag->reviewed_at?->toIso8601String(),
            'created_at' => $flag->created_at?->toIso8601String(),
        ]), 'Laporan konten aspirasi.', 200, ['pagination' => $this->paginationMeta($paginator)]);
    }

    public function flagComment(FlagContentRequest $request, Aspiration $aspiration, AspirationComment $comment): JsonResponse
    {
        abort_unless($comment->aspiration_id === $aspiration->id, 404);
        $this->authorize('flag', $comment);
        $data = $request->validated();
        $flag = $this->moderation->flag($comment, $request->user(), $data['reason'], $data['note'] ?? null);

        return $this->respond(['id' => $flag->id], 'Laporan konten diterima.', 201);
    }

    public function moderateComment(ModerateContentRequest $request, Aspiration $aspiration, AspirationComment $comment): JsonResponse
    {
        abort_unless($comment->aspiration_id === $aspiration->id, 404);
        $this->authorize('moderate', $comment);
        $data = $request->validated();
        $flag = isset($data['flag_id']) ? $comment->contentFlags()->find($data['flag_id']) : null;
        if (isset($data['flag_id']) && $flag === null) {
            throw ValidationException::withMessages(['flag_id' => 'Laporan konten tidak sesuai.']);
        }

        $action = $this->moderation->moderate($comment, $request->user(),
            ModerationActionType::from($data['action']), $data['reason'], $flag);

        return $this->respond([
            'id' => $action->id,
            'action' => $action->action->value,
            'resulting_status' => $action->resulting_status,
        ], 'Tindakan moderasi tercatat.');
    }

    /**
     * Moderasi konten oleh pengelola kelas.
     */
    public function moderate(ModerateContentRequest $request, Aspiration $aspiration): JsonResponse
    {
        $this->authorize('moderate', $aspiration);

        $data = $request->validated();

        $flag = isset($data['flag_id'])
            ? $aspiration->contentFlags()->find($data['flag_id'])
            : null;

        if (isset($data['flag_id']) && $flag === null) {
            throw ValidationException::withMessages(['flag_id' => 'Laporan konten tidak sesuai.']);
        }

        $action = $this->moderation->moderate(
            $aspiration,
            $request->user(),
            ModerationActionType::from($data['action']),
            $data['reason'],
            $flag,
        );

        return $this->respond([
            'id' => $action->id,
            'action' => $action->action->value,
            'resulting_status' => $action->resulting_status,
        ], 'Tindakan moderasi tercatat.');
    }

    private function present(Aspiration $aspiration, User $user): array
    {
        return [
            'id' => $aspiration->id,
            'title' => $aspiration->isVisible() || $aspiration->author_id === $user->id || $user->can('moderate', $aspiration)
                ? $aspiration->title : null,
            'body' => $aspiration->isVisible() || $aspiration->author_id === $user->id || $user->can('moderate', $aspiration)
                ? $aspiration->body
                : null,
            'status' => $aspiration->status?->value,
            'is_pinned' => $aspiration->is_pinned,
            'supports_count' => $aspiration->supports_count,
            'comments_count' => $aspiration->comments_count,
            'author' => $aspiration->author?->only(['id', 'name']),
            'is_mine' => $aspiration->author_id === $user->id,
            'last_activity_at' => $aspiration->last_activity_at?->toIso8601String(),
            'created_at' => $aspiration->created_at?->toIso8601String(),
        ];
    }
}
