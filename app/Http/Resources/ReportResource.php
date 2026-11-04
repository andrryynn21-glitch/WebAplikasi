<?php

namespace App\Http\Resources;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 *
 * Isi privat TIDAK pernah ada di resource ini. Detail dimuat terpisah
 * melalui ReportDetailResource yang memeriksa policy.
 */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_ref' => $this->public_ref,
            'title' => $request->user()?->can('viewPrivateDetails', $this->resource) ? $this->title : null,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'sensitivity' => $this->sensitivity?->value,
            'routing_mode' => $this->routing_mode?->value,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'code' => $this->category->code?->value,
                'name' => $this->category->name,
            ]),
            'class' => $this->whenLoaded('classRoom', fn () => [
                'id' => $this->classRoom->id,
                'code' => $this->classRoom->code,
                'name' => $this->classRoom->name,
            ]),
            'is_mine' => $this->reporter_id === $request->user()?->id,
            'has_open_appeal' => $this->when(
                $this->relationLoaded('openAppeal'),
                fn () => $this->openAppeal !== null,
            ),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
