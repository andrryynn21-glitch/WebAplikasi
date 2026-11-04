<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use Illuminate\Database\Seeder;

class AcademicTermSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $startYear = 2023;
        $endYear = 2035;
        $activeYear = 2026;
        $activeTerm = 1;

        for ($year = $startYear; $year <= $endYear; $year++) {
            foreach ([1, 2] as $term) {
                $code = "{$year}-{$term}";
                $isGanjil = $term === 1;

                if ($isGanjil) {
                    $name = "Semester Ganjil {$year}/".($year + 1);
                    $startsOn = "{$year}-09-01";
                    $endsOn = "".($year + 1)."-01-31";
                } else {
                    $name = "Semester Genap {$year}/".($year + 1);
                    $startsOn = "".($year + 1)."-02-01";
                    $endsOn = "".($year + 1)."-07-31";
                }

                $isActive = $year === $activeYear && $term === $activeTerm;

                AcademicTerm::query()->firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_active' => $isActive,
                ]);
            }
        }
    }
}
