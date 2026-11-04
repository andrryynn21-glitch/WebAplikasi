<?php

namespace App\Http\Requests\Reports;

use App\Enums\AppealStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([AppealStatus::Accepted->value, AppealStatus::Rejected->value])],
            'decision_note' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
