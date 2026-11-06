<?php

namespace Database\Factories;

use App\Models\AttendanceStudent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceStudent>
 */
class AttendanceStudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'nim' => fake()->unique()->numerify('00########'),
            'is_active' => true,
        ];
    }
}
