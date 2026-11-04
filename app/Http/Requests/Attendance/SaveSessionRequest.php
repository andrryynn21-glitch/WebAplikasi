<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRecord;
use App\Services\AttendanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(AttendanceService::class)->classFor($this->user());

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'meeting_number' => ['required', 'integer', 'between:1,100'],
            'held_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Jakarta')->toDateString()],
            'starts_at' => ['nullable', 'required_with:ends_at', 'date_format:H:i'],
            'ends_at' => ['nullable', 'required_with:starts_at', 'date_format:H:i', 'after:starts_at'],
            'topic' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isMethod('POST')) {
            $rules['course_id'] = ['required', 'integer', 'min:1'];
        } else {
            $rules += [
                'version' => ['required', 'integer', 'min:1'],
                'lecturer_name' => ['required', 'string', 'max:160'],
                'records' => ['required', 'array', 'list', 'min:1', 'max:1000'],
                'records.*' => ['required', 'array:user_id,status,note'],
                'records.*.user_id' => ['required', 'integer', 'distinct', 'min:1'],
                'records.*.status' => ['present', 'nullable', Rule::in(AttendanceRecord::STATUSES)],
                'records.*.note' => ['nullable', 'string', 'max:500'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'held_on.before_or_equal' => 'Tanggal absensi tidak boleh melewati hari ini (WIB).',
            'held_on.date_format' => 'Tanggal absensi harus berupa tanggal yang valid.',
            'ends_at.after' => 'Jam selesai harus setelah jam mulai pada hari yang sama.',
            'records.*.user_id.distinct' => 'Mahasiswa tidak boleh tercatat dua kali.',
            'records.*.status.in' => 'Pilih Hadir, Izin, Sakit, atau Alpa.',
            'records.*.note.max' => 'Catatan mahasiswa maksimal 500 karakter.',
            'lecturer_name.required' => 'Nama dosen pengampu wajib diisi.',
        ];
    }
}
