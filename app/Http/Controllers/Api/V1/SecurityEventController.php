<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Security event untuk role berwenang. Output sengaja dibatasi:
 * tidak ada context mentah yang dapat memuat data sensitif, dan tidak
 * pernah menyertakan isi pengaduan.
 */
class SecurityEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SecurityEvent::class);

        $query = SecurityEvent::query()
            ->when(! $request->user()->hasRole(UserRole::SuperAdmin), function ($q) use ($request) {
                $user = $request->user();
                $ids = array_filter($user->accessibleClassIds(), fn ($id) => $user->isClassLeaderOf($id) || $user->isLecturerOf($id));
                $q->whereIn('class_id', $ids);
            })
            ->with(['user:id,name,email', 'classRoom:id,code,name'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('created_at');

        $paginator = $query->paginate($this->perPage($request));

        return $this->respond(
            $paginator->getCollection()->map(fn (SecurityEvent $e) => [
                'id' => $e->id,
                'category' => $e->category?->value,
                'category_label' => $e->category?->label(),
                'severity' => $e->severity?->value,
                'user' => $e->user?->only(['id', 'name', 'email']),
                'class' => $e->classRoom?->only(['id', 'code', 'name']),
                'resource_type' => $e->resource_type,
                'resource_ref' => $e->resource_ref,
                'denial_reason' => $e->denial_reason,
                'route' => $e->route,
                'method' => $e->method,
                'ip_address' => $e->ip_address,
                'created_at' => $e->created_at?->toIso8601String(),
            ]),
            'Security events.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }
}
