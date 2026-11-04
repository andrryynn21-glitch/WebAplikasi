<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\SecurityEventCategory;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignClassLeaderRequest;
use App\Http\Requests\Admin\AssignLecturerRequest;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\StoreClassRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\ClassAdministratorAssignment;
use App\Models\ClassInvitationCode;
use App\Models\ClassRoom;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ClassroomService;
use App\Services\RoleAssignmentService;
use App\Services\SecurityEventService;
use App\Services\UserAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Operasi administratif Super Admin.
 *
 * Sengaja tidak ada endpoint yang membuka isi pengaduan untuk Super Admin.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly RoleAssignmentService $roles,
        private readonly ClassroomService $classes,
        private readonly AuditLogService $audit,
        private readonly SecurityEventService $security,
        private readonly UserAdministrationService $usersService,
    ) {}

    public function users(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->with(['role:id,key,name', 'activeMembership.classRoom:id,code,name'])
            ->when($request->filled('role'), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('key', $request->string('role'))))
            ->when($request->filled('identity_status'), fn ($q) => $q->where('identity_status', $request->string('identity_status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($q2) => $q2->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('nim', 'like', $term));
            })
            ->latest();

        $paginator = $query->paginate($this->perPage($request));

        return $this->respond(
            UserResource::collection($paginator->getCollection())->resolve(),
            'Daftar pengguna.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }

    /**
     * Perubahan role. Pemanggil tidak dapat menaikkan role dirinya sendiri;
     * setiap perubahan dicatat di audit log.
     */
    public function assignRole(AssignRoleRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $targetRole = UserRole::from($data['role']);

        if ($user->id === $request->user()->id) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::PrivilegeEscalationAttempt,
                $request->user(),
                $request,
                $user,
                'Mencoba mengubah role sendiri.',
            );

            return $this->fail('Anda tidak dapat mengubah role akun Anda sendiri.', 'SELF_ROLE_CHANGE_DENIED', 403, null, [], $event);
        }

        return $this->respond(
            $this->roles->assign($user, $targetRole, $request->user(), $data['reason']),
            'Role diperbarui dan tercatat di audit log.',
        );
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        if ($user->id === $request->user()->id) {
            return $this->fail('Anda tidak dapat menonaktifkan akun sendiri.', 'SELF_SUSPEND_DENIED', 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->usersService->suspend($user, $request->user(), $validated['reason']);

        return $this->respond(null, 'Akun dinonaktifkan.');
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $this->usersService->reactivate($user, $request->user());

        return $this->respond(null, 'Akun diaktifkan kembali.');
    }

    public function storeClass(StoreClassRequest $request): JsonResponse
    {
        $class = $this->classes->create($request->validated(), $request->user());

        return $this->respond([
            'id' => $class->id,
            'code' => $class->code,
            'name' => $class->name,
            'join_code' => $class->join_code,
        ], 'Kelas dibuat.', 201);
    }

    public function updateClass(StoreClassRequest $request, ClassRoom $class): JsonResponse
    {
        $updated = $this->classes->update($class, $request->validated(), $request->user());

        return $this->respond([
            'id' => $updated->id,
            'code' => $updated->code,
            'name' => $updated->name,
        ], 'Kelas diperbarui.');
    }

    public function assignClassLeader(AssignClassLeaderRequest $request, ClassRoom $class): JsonResponse
    {
        $this->authorize('assignLeader', $class);

        $leader = User::query()->findOrFail($request->integer('user_id'));

        $assignment = $this->classes->assignClassLeader($class, $leader, $request->user());

        return $this->respond([
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'class_id' => $assignment->class_id,
            'starts_at' => $assignment->starts_at?->toIso8601String(),
        ], 'Ketua kelas ditetapkan.');
    }

    public function revokeClassLeader(Request $request, ClassAdministratorAssignment $assignment): JsonResponse
    {
        $this->authorize('assignLeader', $assignment->classRoom);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->classes->revokeClassLeader($assignment, $request->user(), $validated['reason']);

        return $this->respond(null, 'Ketua kelas dicabut dan tercatat.');
    }

    public function assignLecturer(AssignLecturerRequest $request, ClassRoom $class): JsonResponse
    {
        $this->authorize('assignLecturer', $class);

        $lecturer = User::query()->findOrFail($request->integer('lecturer_id'));

        $assignment = $this->classes->assignLecturer(
            $class,
            $lecturer,
            $request->user(),
            $request->string('role', 'coordinator')->toString(),
        );

        return $this->respond([
            'id' => $assignment->id,
            'lecturer_id' => $assignment->lecturer_id,
            'class_id' => $assignment->class_id,
        ], 'Dosen ditugaskan.', 201);
    }

    public function issueInvitation(Request $request, ClassRoom $class): JsonResponse
    {
        $this->authorize('manageInvitations', $class);

        $validated = $request->validate([
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $code = ClassInvitationCode::create([
            'class_id' => $class->id,
            'code' => strtoupper(Str::random(10)),
            'expires_at' => isset($validated['expires_in_days'])
                ? now()->addDays($validated['expires_in_days'])
                : null,
            'max_uses' => $validated['max_uses'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record(AuditAction::InvitationIssued, $request->user(), $code, $class->id, [
            'max_uses' => $code->max_uses,
            'expires_at' => $code->expires_at?->toIso8601String(),
        ]);

        return $this->respond([
            'id' => $code->id,
            'code' => $code->code,
            'expires_at' => $code->expires_at?->toIso8601String(),
            'max_uses' => $code->max_uses,
        ], 'Kode undangan dibuat.', 201);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        if (! $request->user()->is_active || ! $request->user()->hasPermission('audit.view')) {
            return $this->fail('Anda tidak berwenang melihat audit log.', 'AUDIT_ACCESS_DENIED', 403);
        }

        $paginator = AuditLog::query()
            ->with('actor:id,name')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return $this->respond(
            $paginator->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor?->only(['id', 'name']),
                'action' => $log->action?->value,
                'action_label' => $log->action?->label(),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'class_id' => $log->class_id,
                'changes' => $log->changes === null ? null : $this->audit->sanitizeChanges($log->changes),
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'Audit log.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }
}
