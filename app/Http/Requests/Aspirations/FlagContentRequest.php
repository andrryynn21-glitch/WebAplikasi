<?php

namespace App\Http\Requests\Aspirations;

use Illuminate\Foundation\Http\FormRequest;

class FlagContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('aspirations.flag') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'in:spam,harassment,offensive,private_information,other'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
