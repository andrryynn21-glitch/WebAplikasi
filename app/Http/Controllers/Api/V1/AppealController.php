<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppealStatus;
use App\Enums\SecurityEventCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DecideAppealRequest;
use App\Http\Requests\Reports\SubmitAppealRequest;
use App\Models\Appeal;
use App\Models\Report;
use App\Models\User;
use App\Services\AppealService;
use App\Services\ReportWorkflowService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function __construct(
        private readonly AppealService $appeals,
        private readonly SecurityEventService $security,
    ) {}

    public function store(SubmitAppealRequest $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('submitAppeal', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::AccessDenied,
                $request->user(),
                $request,
                $report,
                'Pengajuan banding tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail('Anda tidak dapat mengajukan banding untuk pengaduan ini.', 'APPEAL_DENIED', 403, null, [], $event);
        }

        $appeal = $this->appeals->submit($report, $request->user(), $request->validated('reason'));

        return $this->respond([
            'id' => $appeal->id,
            'status' => $appeal->status->value,
        ], 'Banding diajukan.', 201);
    }

    /**
     * Daftar peninjau yang memenuhi syarat untuk banding (tanpa peninjau
     * yang pernah menangani laporan).
     */
    public function eligibleReviewers(Request $request, Appeal $appeal): JsonResponse
    {
        $report = $appeal->report;

        if (! $request->user()->can('reviewAppeal', $report)) {
            return $this->fail('Anda tidak berwenang meninjau banding ini.', 'APPEAL_REVIEW_DENIED', 403);
        }

        return $this->respond(
            $this->appeals->eligibleReviewers($report)
                ->map(fn (User $u) => $u->only(['id', 'name'])),
            'Peninjau banding yang memenuhi syarat.',
        );
    }

    public function assignReviewer(Request $request, Appeal $appeal): JsonResponse
    {
        $report = $appeal->report;

        if (! $request->user()->can('reviewAppeal', $report)) {
            return $this->fail('Anda tidak berwenang meninjau banding ini.', 'APPEAL_REVIEW_DENIED', 403);
        }

        $validated = $request->validate([
            'reviewer_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $reviewer = User::query()->findOrFail($validated['reviewer_id']);

        $updated = $this->appeals->assignReviewer($appeal, $reviewer, $request->user());

        return $this->respond([
            'id' => $updated->id,
            'reviewer' => $reviewer->only(['id', 'name']),
            'status' => $updated->status->value,
        ], 'Peninjau banding ditetapkan.');
    }

    public function decide(DecideAppealRequest $request, Appeal $appeal): JsonResponse
    {
        $report = $appeal->report;

        if (! $request->user()->can('reviewAppeal', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::ConflictOfInterestViolation,
                $request->user(),
                $request,
                $report,
                'Keputusan banding oleh pihak yang tidak memenuhi syarat.',
                $report->class_id,
            );

            return $this->fail('Anda tidak berwenang memutuskan banding ini.', 'APPEAL_DECISION_DENIED', 403, null, [], $event);
        }

        $data = $request->validated();

        $decided = $this->appeals->decide(
            $appeal,
            $request->user(),
            AppealStatus::from($data['status']),
            $data['decision_note'],
        );

        return $this->respond([
            'id' => $decided->id,
            'status' => $decided->status->value,
        ], 'Keputusan banding tercatat.');
    }

    public function show(Request $request, Appeal $appeal): JsonResponse
    {
        $report = $appeal->report;

        $isReporter = $report->reporter_id === $request->user()->id;

        $isReviewer = $appeal->reviewer_id === $request->user()->id
            && $request->user()->hasPermission('reports.appeal_review')
            && $request->user()->isLecturerOf($report->class_id)
            && ! $report->hasConflictFor($request->user())
            && ! app(ReportWorkflowService::class)->isSubjectOfReport($report, $request->user());

        if (! $isReporter && ! $isReviewer) {
            return $this->fail('Anda tidak berwenang melihat banding ini.', 'APPEAL_ACCESS_DENIED', 403);
        }

        return $this->respond([
            'id' => $appeal->id,
            'status' => $appeal->status?->value,
            'reason' => $isReporter || $request->user()->can('reviewAppeal', $report) ? $appeal->reason : null,
            'reviewer' => $appeal->reviewer?->only(['id', 'name']),
            'decision_note' => $appeal->decision_note,
            'decided_at' => $appeal->decided_at?->toIso8601String(),
            'created_at' => $appeal->created_at?->toIso8601String(),
        ], 'Detail banding.');
    }
}
