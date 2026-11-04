<?php

namespace Database\Seeders;

use App\Enums\ReportCategoryCode;
use App\Models\ReportCategory;
use Illuminate\Database\Seeder;

class ReportCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Kategori yang menyinggung perilaku orang (dosen, interpersonal)
        // ditandai sensitif: routing otomatis tidak diizinkan.
        $categories = [
            [ReportCategoryCode::Academic, false, false],
            [ReportCategoryCode::LecturerConduct, true, true],
            [ReportCategoryCode::ClassDisruption, false, false],
            [ReportCategoryCode::Facilities, false, false],
            [ReportCategoryCode::Grouping, false, false],
            [ReportCategoryCode::Interpersonal, true, true],
            [ReportCategoryCode::Suggestion, false, false],
        ];

        foreach ($categories as [$code, $sensitive, $humanReview]) {
            ReportCategory::query()->updateOrCreate(
                ['code' => $code->value],
                [
                    'name' => $code->label(),
                    'is_sensitive' => $sensitive,
                    'requires_human_review' => $humanReview,
                    'auto_route_role' => $sensitive ? null : 'class_leader',
                ],
            );
        }
    }
}
