<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AppNotification::class);
        $query = $request->user()->notifications()
            ->visibleTo($request->user())
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest();

        $paginator = $query->paginate($this->perPage($request));

        return $this->respond(
            $paginator->getCollection()->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'type' => $n->type?->value,
                'title' => $n->title,
                'message' => $n->message,
                'data' => $n->data,
                'severity' => $n->severity,
                'action_url' => $n->action_url,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'Notifikasi.',
            200,
            [
                'pagination' => $this->paginationMeta($paginator),
                'unread_count' => $request->user()->notifications()->visibleTo($request->user())->whereNull('read_at')->count(),
            ],
        );
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->respond(null, 'Notifikasi ditandai sudah dibaca.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AppNotification::class);
        $count = $request->user()->notifications()->visibleTo($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return $this->respond(['updated' => $count], 'Semua notifikasi ditandai sudah dibaca.');
    }
}
