<?php

namespace App\Http\Requests\Admin;

use App\Models\ClassRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('classes.manage') ?? false;
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'academic_term_id' => [$required, 'integer', 'exists:academic_terms,id'],
            'code' => [$required, 'string', 'max:64'],
            'name' => [$required, 'string', 'max:120'],
            'program_study' => [$required, 'string', 'max:128'],
            'semester' => [$required, 'integer', 'min:1', 'max:14'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $class = $this->route('class');
            $duplicate = ClassRoom::query()
                ->where('academic_term_id', $this->input('academic_term_id', $class?->academic_term_id))
                ->where('code', $this->input('code', $class?->code))
                ->when($class, fn ($q) => $q->whereKeyNot($class->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('code', 'Kode kelas sudah digunakan pada tahun akademik ini.');
            }
        }];
    }
}
