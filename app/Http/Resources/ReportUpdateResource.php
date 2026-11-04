<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportUpdateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'author' => $this->whenLoaded('author', fn () => $this->author?->only(['id', 'name'])),
            'reply_to_id' => $this->reply_to_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
