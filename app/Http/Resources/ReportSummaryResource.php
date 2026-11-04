<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata laporan untuk pihak yang boleh melihat keberadaannya
 * (mis. ketua kelas atas laporan yang belum ditugaskan kepadanya).
 * Isi privat, riwayat, dan pembaruan tidak disertakan.
 */
class ReportSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Report $report */
        $report = $this->resource;

        return [
            'public_ref' => $report->public_ref,
            'title' => null,
            'status' => $report->status?->value,
            'status_label' => $report->status?->label(),
            'sensitivity' => $report->sensitivity?->value,
            'routing_mode' => $report->routing_mode?->value,
            'category' => $report->category ? [
                'id' => $report->category->id,
                'code' => $report->category->code?->value,
                'name' => $report->category->name,
            ] : null,
            'class' => $report->classRoom ? [
                'id' => $report->classRoom->id,
                'code' => $report->classRoom->code,
                'name' => $report->classRoom->name,
            ] : null,
            'private_details' => null,
            'can_read_private' => false,
            'created_at' => $report->created_at?->toIso8601String(),
            'last_activity_at' => $report->last_activity_at?->toIso8601String(),
        ];
    }
}
