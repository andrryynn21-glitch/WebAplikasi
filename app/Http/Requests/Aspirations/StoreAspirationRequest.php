<?php

namespace App\Http\Requests\Aspirations;

use App\Models\Aspiration;
use Illuminate\Foundation\Http\FormRequest;

class StoreAspirationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Aspiration::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }
}
