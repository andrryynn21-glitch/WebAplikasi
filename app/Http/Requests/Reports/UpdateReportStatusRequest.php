<?php

namespace App\Http\Requests\Reports;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Perubahan status.
 *
 * Klien mengirim status TUJUAN; backend memvalidasi bahwa transisi sah
 * menurut state machine dan bahwa aktor berwenang. Klien tidak dapat
 * melompati tahap atau memalsukan riwayat.
 */
class UpdateReportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ditegakkan ReportWorkflowService + policy
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReportStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
            'internal' => ['nullable', 'boolean'],
        ];
    }
}
