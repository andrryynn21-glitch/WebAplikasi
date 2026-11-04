<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AssignmentType;
use App\Enums\SecurityEventCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\AssignReportRequest;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportAssignmentService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;

class ReportAssignmentController extends Controller
{
    public function __construct(
        private readonly ReportAssignmentService $assignments,
        private readonly SecurityEventService $security,
    ) {}

    public function store(AssignReportRequest $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('assign', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::AccessDenied,
                $request->user(),
                $request,
                $report,
                'Penugasan tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail('Anda tidak berwenang menugaskan pengaduan ini.', 'ASSIGNMENT_DENIED', 403, null, [], $event);
        }

        $data = $request->validated();

        $assignee = User::query()->findOrFail($data['assignee_id']);
        $type = isset($data['assignment_type'])
            ? AssignmentType::from($data['assignment_type'])
            : AssignmentType::Primary;

        $assignment = $this->assignments->assign(
            $report,
            $assignee,
            $request->user(),
            $type,
            $data['reason'],
        );

        return $this->respond([
            'id' => $assignment->id,
            'assignee' => $assignee->only(['id', 'name']),
            'type' => $assignment->assignment_type->value,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
        ], 'Pengaduan ditugaskan.', 201);
    }

    /**
     * Penangan mengalihkan laporan ke pihak lain (tercatat).
     */
    public function forward(AssignReportRequest $request, Report $report): JsonResponse
    {
        if (! $request->user()->can('assign', $report)) {
            $event = $this->security->recordDenial(
                SecurityEventCategory::AccessDenied,
                $request->user(),
                $request,
                $report,
                'Pengalihan tanpa wewenang.',
                $report->class_id,
            );

            return $this->fail('Anda tidak berwenang mengalihkan pengaduan ini.', 'FORWARD_DENIED', 403, null, [], $event);
        }

        $data = $request->validated();
        $assignee = User::query()->findOrFail($data['assignee_id']);

        $assignment = $this->assignments->forward($report, $assignee, $request->user(), $data['reason']);

        return $this->respond([
            'id' => $assignment->id,
            'assignee' => $assignee->only(['id', 'name']),
        ], 'Pengaduan dialihkan dan tercatat.');
    }
}
