<?php

namespace Database\Factories\Core;

use App\Models\Core\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Private Limited',
            'code' => strtoupper(fake()->unique()->bothify('C??##')),
            'state_code' => '27',
            'city' => fake()->city(),
            'email' => fake()->companyEmail(),
            'currency' => 'INR',
            'fy_start_month' => 4,
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
