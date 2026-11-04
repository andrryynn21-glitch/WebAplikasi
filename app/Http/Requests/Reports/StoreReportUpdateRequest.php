<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ditegakkan policy ReportPolicy::addUpdate
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'internal' => ['nullable', 'boolean'],
            'reply_to_id' => ['nullable', 'integer', 'exists:report_updates,id'],
            'type' => ['nullable', 'in:update,clarification_response'],
        ];
    }
}
