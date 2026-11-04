<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportStatus;
use App\Enums\ReportUpdateType;
use App\Enums\SecurityEventCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Http\Requests\Reports\StoreReportUpdateRequest;
use App\Http\Requests\Reports\UpdateReportStatusRequest;
use App\Http\Resources\ReportDetailResource;
use App\Http\Resources\ReportResource;
use App\Http\Resources\ReportSummaryResource;
use App\Models\Report;
use App\Models\ReportCategory;
use App\Services\ReportService;
use App\Services\ReportWorkflowService;
use App\Services\SecurityEventService;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportWorkflowService $workflow,
        private readonly SecurityEventService $security,
    ) {}

    public function categories(): JsonResponse
    {
        $categories = ReportCategory::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ReportCategory $c) => [
                'id' => $c->id,
                'code' => $c->code?->value,
                'name' => $c->name,
                'sensitive' => $c->is_sensitive,
            ]);

        return $this->respond($categories, 'Kategori pengaduan.', 200, [
            'uploads' => [
                'max_files' => (int) config('security.uploads.max_report_attachments'),
                'max_file_bytes' => (int) config('security.uploads.report_max_kb') * 1024,
                'max_total_bytes' => (int) config('security.uploads.report_max_total_bytes'),
            ],
        ]);
    }

    /**
     * Daftar laporan yang boleh dilihat pengguna.
     *
     * - Mahasiswa: hanya laporan miliknya.
     * - Ketua kelas / dosen: laporan pada kelas yang boleh diakses,
     *   hanya metadata (tanpa isi).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $classIds = $user->accessibleClassIds();

        $query = Report::query()->visibleTo($user)->with(['category:id,code,name', 'classRoom:id,code,name']);

        $scope = $request->string('scope', 'mine')->toString();

        $mine = $scope === 'mine' || ! $user->hasAnyPermission(['reports.view.class', 'reports.view.assigned']);

        if ($mine) {
            $query->where('reporter_id', $user->id);
        } else {
            $classIds = $user->managedClassIds();
            $query->whereIn('class_id', $classIds);
        }

        if ($request->filled('class_id')) {
            $classId = (int) $request->integer('class_id');
            if (! in_array($classId, $classIds, true)
                && ! ($mine && Report::query()->where('reporter_id', $user->id)->where('class_id', $classId)->exists())) {
                $event = $this->security->recordDenial(
                    SecurityEventCategory::CrossClassAccessAttempt,
                    $user,
                    $request,
                    null,
                    'Filter kelas di luar wewenang.',
                    $classId,
                );

                return $this->fail('Anda tidak memiliki akses ke kelas tersebut.', 'CLASS_ACCESS_DENIED', 403, null, [], $event);
            }
            $query->where('class_id', $classId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $sort = $request->string('sort', 'last_activity_at')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $sortable = ['last_activity_at', 'created_at', 'status'];
        $query->orderBy(in_array($sort, $sortable, true) ? $sort : 'last_activity_at', $direction);

        $paginator = $query->paginate($this->perPage($request));

        return $this->respond(
            ReportResource::collection($paginator->getCollection())->resolve(),
            'Daftar pengaduan.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $report = $this->reports->create($request->user(), $request->validated());

        return $this->respond(
            ReportResource::make($report->load(['category:id,code,name', 'classRoom:id,code,name'])),
            'Pengaduan terkirim.',
            201,
        );
    }

    /**
     * Detail laporan. Isi privat hanya dimuat setelah policy lolos.
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('view', $report)) {
            $event = $this->security->recordDenial(
                in_array($report->class_id, $user->accessibleClassIds(), true)
                    ? SecurityEventCategory::AccessDenied
                    : SecurityEventCategory::CrossClassAccessAttempt,
                $user,
                $request,
                $report,
                'Detail laporan tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail(
                'Anda tidak berwenang melihat pengaduan ini.',
                'REPORT_ACCESS_DENIED',
                403,
                null,
                [],
                $event,
            );
        }

        $canReadPrivate = $user->can('viewPrivateDetails', $report);

        if (! $canReadPrivate) {
            return $this->respond(
                new ReportSummaryResource($report->load(['category', 'classRoom'])),
                'Detail pengaduan (tanpa isi privat).',
            );
        }

        $report->load([
            'category',
            'classRoom',
            'reporter:id,name,nim',
            'currentHandler:id,name',
            'privateDetails',
            'attachments',
            'openAppeal',
        ]);

        $includeReporterIdentity = $report->reporter_id !== $user->id;

        return $this->respond(
            new ReportDetailResource($report, $includeReporterIdentity),
            'Detail pengaduan.',
        );
    }

    /**
     * Perubahan status melalui state machine.
     */
    public function updateStatus(UpdateReportStatusRequest $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('updateStatus', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::AccessDenied,
                $request->user(),
                $request,
                $report,
                'Perubahan status tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail(
                'Anda tidak berwenang mengubah status pengaduan ini.',
                'STATUS_CHANGE_DENIED',
                403,
                null,
                [],
                $event,
            );
        }

        $data = $request->validated();

        $updated = $this->workflow->transition(
            $report,
            ReportStatus::from($data['status']),
            $request->user(),
            [
                'reason' => $data['reason'] ?? null,
                'resolution_summary' => $data['resolution_summary'] ?? null,
            ],
        );

        return $this->respond(
            ReportResource::make($updated->load('category:id,code,name')),
            'Status pengaduan diperbarui.',
        );
    }

    public function addUpdate(StoreReportUpdateRequest $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('addUpdate', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::AccessDenied,
                $request->user(),
                $request,
                $report,
                'Menambah pembaruan tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail('Anda tidak berwenang menambah pembaruan.', 'UPDATE_DENIED', 403, null, [], $event);
        }

        $data = $request->validated();
        $type = match ($data['type'] ?? 'update') {
            'clarification_response' => ReportUpdateType::ClarificationResponse,
            default => ReportUpdateType::Update,
        };

        $update = $this->reports->addUpdate(
            $report,
            $request->user(),
            $data['body'],
            $type,
            (bool) ($data['internal'] ?? false),
            $data['reply_to_id'] ?? null,
        );

        return $this->respond([
            'id' => $update->id,
            'type' => $update->type->value,
            'body' => $update->body,
            'is_internal' => $update->is_internal,
            'created_at' => $update->created_at?->toIso8601String(),
        ], 'Pembaruan ditambahkan.', 201);
    }

    /**
     * Minta klarifikasi dari pelapor.
     */
    public function requestClarification(Request $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('requestClarification', $report)) {
            return $this->fail('Anda tidak berwenang meminta klarifikasi.', 'CLARIFICATION_DENIED', 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $update = DB::transaction(function () use ($request, $report, $validated) {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);
            $update = $this->reports->addUpdate(
                $locked, $request->user(), $validated['body'], ReportUpdateType::ClarificationRequest,
            );

            if ($locked->status === ReportStatus::UnderReview) {
                $this->workflow->transition($locked, ReportStatus::NeedsClarification, $request->user(), [
                    'reason' => 'Permintaan klarifikasi dikirim ke pelapor.',
                ]);
            }

            return $update;
        });

        return $this->respond([
            'id' => $update->id,
            'created_at' => $update->created_at?->toIso8601String(),
        ], 'Permintaan klarifikasi dikirim.', 201);
    }

    /**
     * Statistik ringkas per laporan untuk penangan.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewStatistics', Report::class);

        $stats = app(StatisticsService::class)->summary(
            $request->user(),
            $request->filled('class_id') ? $request->integer('class_id') : null,
        );

        return $this->respond($stats, 'Statistik penanganan.');
    }
}
