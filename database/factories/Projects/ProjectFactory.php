<?php

namespace Database\Factories\Projects;

use App\Enums\ProjectStatus;
use App\Models\Projects\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Projects are tenant-owned: create them inside CurrentCompany::runAs() (company_id is set by the model).
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'project_number' => 'PRJ-'.fake()->unique()->numerify('####-####'),
            'code' => strtoupper(fake()->unique()->bothify('P??###')),
            'name' => fake()->streetName().' Residency',
            'project_type' => 'residential',
            'city' => fake()->city(),
            'state_code' => '27',
            'start_date' => now()->toDateString(),
            'contract_value' => '25000000.00',
            'status' => ProjectStatus::Active,
        ];
    }
}
