<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewMembershipRequest;
use App\Http\Requests\Classes\JoinClassRequest;
use App\Models\AcademicTerm;
use App\Models\ClassMembership;
use App\Models\ClassRoom;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
    ) {}

    /**
     * Daftar kelas yang dapat diikuti (tanpa data anggota).
     */
    public function classes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClassRoom::class);

        $classes = ClassRoom::query()
            ->where('is_active', true)
            ->with('academicTerm:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (ClassRoom $class) => [
                'id' => $class->id,
                'code' => $class->code,
                'name' => $class->name,
                'program_study' => $class->program_study,
                'semester' => $class->semester,
                'academic_term' => $class->academicTerm?->only(['id', 'code', 'name']),
            ]);

        return $this->respond($classes, 'Daftar kelas aktif.');
    }

    public function academicTerms(): JsonResponse
    {
        return $this->respond(
            AcademicTerm::query()->orderByDesc('starts_on')->get()->map(fn (AcademicTerm $term) => [
                'id' => $term->id,
                'code' => $term->code,
                'name' => $term->name,
                'starts_on' => $term->starts_on?->toDateString(),
                'ends_on' => $term->ends_on?->toDateString(),
                'is_active' => $term->is_active,
            ]),
            'Daftar periode akademik.',
        );
    }

    /**
     * Ajukan bergabung dengan kode undangan. Hasilnya SELALU pending.
     */
    public function join(JoinClassRequest $request): JsonResponse
    {
        $membership = $this->memberships->requestJoin(
            $request->user(),
            $request->string('invitation_code')->toString(),
        );

        return $this->respond([
            'id' => $membership->id,
            'status' => $membership->status->value,
            'class' => $membership->classRoom?->only(['id', 'code', 'name']),
        ], 'Permintaan bergabung dikirim. Menunggu persetujuan pengelola kelas.', 202);
    }

    public function myMemberships(Request $request): JsonResponse
    {
        $memberships = $request->user()->memberships()
            ->with('classRoom:id,code,name')
            ->latest()
            ->get()
            ->map(fn (ClassMembership $m) => [
                'id' => $m->id,
                'status' => $m->status->value,
                'class' => $m->classRoom?->only(['id', 'code', 'name']),
                'decision_note' => $m->status->value === 'rejected' ? $m->decision_note : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return $this->respond($memberships, 'Keanggotaan kelas Anda.');
    }

    /**
     * Daftar permintaan bergabung untuk kelas yang dikelola.
     */
    public function pending(Request $request, ClassRoom $class): JsonResponse
    {
        $this->authorize('manageMemberships', $class);

        $paginator = $class->memberships()
            ->where('status', 'pending')
            ->with('user:id,name,nim,email')
            ->latest()
            ->paginate($this->perPage($request));

        return $this->respond(
            $paginator->getCollection()->map(fn (ClassMembership $m) => [
                'id' => $m->id,
                'user' => $m->user?->only(['id', 'name', 'nim', 'email']),
                'status' => $m->status->value,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
            'Permintaan bergabung yang menunggu.',
            200,
            ['pagination' => $this->paginationMeta($paginator)],
        );
    }

    public function decide(ReviewMembershipRequest $request, ClassMembership $membership): JsonResponse
    {
        $this->authorize('review', $membership);

        $data = $request->validated();

        $result = $data['decision'] === 'approve'
            ? $this->memberships->approve($membership, $request->user(), $data['note'] ?? null)
            : $this->memberships->reject($membership, $request->user(), (string) $data['note']);

        return $this->respond([
            'id' => $result->id,
            'status' => $result->status->value,
        ], $data['decision'] === 'approve' ? 'Keanggotaan disetujui.' : 'Permintaan ditolak.');
    }
}
