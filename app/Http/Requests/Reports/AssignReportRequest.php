<?php

namespace App\Http\Requests\Reports;

use App\Enums\AssignmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'assignment_type' => ['nullable', Rule::enum(AssignmentType::class)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
