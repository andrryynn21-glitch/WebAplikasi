<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

/**
 * payload pembuatan pengaduan.
 *
 * Tidak ada field `status` atau `routing_mode` di sini: status awal dan
 * routing ditentukan backend, bukan klien.
 */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('reports.create') ?? false;
    }

    public function rules(): array
    {
        $maxAttachments = (int) config('security.uploads.max_report_attachments', 5);
        $maxKb = (int) config('security.uploads.report_max_kb', 8192);

        return [
            'category_id' => ['required', 'integer', 'exists:report_categories,id'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:30', 'max:20000'],
            'desired_outcome' => ['nullable', 'string', 'max:5000'],
            'involved_parties' => ['nullable', 'array', 'max:20'],
            'involved_parties.*' => ['string', 'max:200'],
            'academic_term_id' => ['nullable', 'integer', 'exists:academic_terms,id'],

            'attachments' => ['nullable', 'array', 'max:'.$maxAttachments],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf,txt', 'max:'.$maxKb],
        ];
    }

    public function messages(): array
    {
        return [
            'description.min' => 'Uraian pengaduan minimal 30 karakter agar dapat diproses.',
        ];
    }
}
