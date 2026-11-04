<?php

namespace App\Http\Requests\Aspirations;

use App\Enums\ModerationActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('aspirations.moderate') ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(ModerationActionType::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'flag_id' => ['nullable', 'integer', 'exists:content_flags,id'],
        ];
    }
}
