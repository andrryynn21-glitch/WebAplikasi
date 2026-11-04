<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail laporan untuk pelapor atau penangan berwenang.
 * ONLY dibuat setelah ReportPolicy::viewPrivateDetails lolos.
 *
 * Identitas pelapor hanya disertakan bila peminta adalah penangan
 * (bukan pelapor sendiri) — untuk keperluan koordinasi penanganan,
 * bukan konsumsi publik.
 */
class ReportDetailResource extends JsonResource
{
    public function __construct(Report $report, private readonly bool $includeReporterIdentity)
    {
        parent::__construct($report);
    }

    public function toArray(Request $request): array
    {
        /** @var Report $report */
        $report = $this->resource;

        return [
            'public_ref' => $report->public_ref,
            'title' => $report->title,
            'status' => $report->status?->value,
            'status_label' => $report->status?->label(),
            'sensitivity' => $report->sensitivity?->value,
            'routing_mode' => $report->routing_mode?->value,
            'category' => [
                'id' => $report->category?->id,
                'code' => $report->category?->code?->value,
                'name' => $report->category?->name,
            ],
            'class' => [
                'id' => $report->classRoom?->id,
                'code' => $report->classRoom?->code,
                'name' => $report->classRoom?->name,
            ],
            'private_details' => [
                'description' => $report->privateDetails?->description,
                'involved_parties' => $report->privateDetails?->involved_parties,
                'desired_outcome' => $report->privateDetails?->desired_outcome,
            ],
            'resolution_summary' => $report->resolution_summary,
            'rejection_reason' => $report->rejection_reason,
            'reporter' => $this->includeReporterIdentity ? [
                'id' => $report->reporter?->id,
                'name' => $report->reporter?->name,
                'nim' => $report->reporter?->nim,
            ] : null,
            'current_handler' => $report->currentHandler ? [
                'id' => $report->currentHandler->id,
                'name' => $report->currentHandler->name,
            ] : null,
            'timeline' => ReportStatusHistoryResource::collection(
                $report->statusHistories()->with('actor:id,name')->limit(100)->get()
            ),
            'updates' => ReportUpdateResource::collection(
                $report->updates()
                    ->with('author:id,name')
                    ->when(
                        $report->reporter_id === $request->user()?->id,
                        fn ($q) => $q->where('is_internal', false),
                    )
                    ->latest()
                    ->limit(100)
                    ->get()
            ),
            'assignments' => $report->assignments()
                ->with(['assignee:id,name', 'assigner:id,name'])
                ->get()
                ->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'assignee' => $assignment->assignee?->only(['id', 'name']),
                    'assigned_by' => $assignment->assigner?->only(['id', 'name']),
                    'type' => $assignment->assignment_type?->value,
                    'reason' => $assignment->reason,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'revoked_at' => $assignment->revoked_at?->toIso8601String(),
                ]),
            'attachments' => $report->attachments->map(fn ($file) => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'mime' => $file->mime,
                'size' => $file->size,
                'download_url' => '/api/v1/attachments/'.$file->id,
            ]),
            'open_appeal' => $report->openAppeal ? [
                'id' => $report->openAppeal->id,
                'status' => $report->openAppeal->status?->value,
                'created_at' => $report->openAppeal->created_at?->toIso8601String(),
            ] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'last_activity_at' => $report->last_activity_at?->toIso8601String(),
        ];
    }
}
