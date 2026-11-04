<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'nim' => $this->nim,
            'phone' => $this->phone,
            'role' => $this->role?->key?->value,
            'role_name' => $this->role?->name,
            'identity_status' => $this->identity_status?->value,
            'identity_message' => $this->identity_status?->publicMessage(),
            'is_active' => $this->is_active,
            'membership' => $this->whenLoaded('activeMembership', fn () => [
                'status' => $this->activeMembership?->status?->value,
                'class_id' => $this->activeMembership?->class_id,
            ]),
            'permissions' => $this->whenLoaded('role', fn () => $this->role?->permissions->pluck('key')->all() ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
