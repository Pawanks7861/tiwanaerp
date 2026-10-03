<?php

namespace Database\Seeders;

use App\Enums\ProjectRole;
use App\Enums\ProjectStatus;
use App\Models\Core\Company;
use App\Models\Crm\Client;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local development data only. All demo accounts use the password "password".
 */
class DemoSeeder extends Seeder
{
    public function run(CompanyService $companies, ProjectService $projects, CurrentCompany $tenancy): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder may only run in the local environment.');
        }

        $this->user('Platform Admin', 'superadmin@buildify360.test', superAdmin: true);
        $admin = $this->user('Harpreet Tiwana', 'admin@buildify360.test');
        $pm = $this->user('Rajesh Sharma', 'pm@buildify360.test');
        $engineer = $this->user('Amit Verma', 'engineer@buildify360.test');
        $purchase = $this->user('Sunita Rao', 'purchase@buildify360.test');

        $company = Company::query()->where('code', 'TIWANA')->first() ?? $companies->create([
            'name' => 'Tiwana Constructions',
            'legal_name' => 'Tiwana Constructions Private Limited',
            'code' => 'TIWANA',
            'gstin' => '03AABCT1234F1Z5',
            'pan' => 'AABCT1234F',
            'state_code' => '03',
            'address' => 'SCO 12, Phase 7, Industrial Area',
            'city' => 'Mohali',
            'pincode' => '160055',
            'email' => 'info@tiwana.test',
            'is_active' => true,
        ], $admin);

        $companies->addMember($company, $admin, [DefaultRoles::COMPANY_ADMIN]);
        $companies->addMember($company, $pm, [DefaultRoles::PROJECT_MANAGER]);
        $companies->addMember($company, $engineer, [DefaultRoles::SITE_ENGINEER]);
        $companies->addMember($company, $purchase, [DefaultRoles::PURCHASE_MANAGER]);

        // A second company proves isolation in manual testing (admin is a member of both).
        Company::query()->where('code', 'NORTHBLD')->exists() || $companies->create([
            'name' => 'North Builders',
            'code' => 'NORTHBLD',
            'state_code' => '06',
            'city' => 'Gurugram',
            'is_active' => true,
        ], $admin);

        $tenancy->runAs($company, function () use ($projects, $pm, $engineer) {
            if (Client::query()->exists()) {
                return;
            }

            $client = Client::query()->create([
                'code' => app(DocumentNumberService::class)->next('client'),
                'company_name' => 'Greenfield Developers LLP',
                'contact_person' => 'Neha Kapoor',
                'mobile' => '9876543210',
                'state_code' => '03',
                'city' => 'Ludhiana',
                'is_active' => true,
            ]);

            $tower = $projects->create([
                'name' => 'Greenfield Residency Tower A',
                'client_id' => $client->id,
                'project_type' => 'residential',
                'city' => 'Ludhiana',
                'state_code' => '03',
                'project_manager_id' => $pm->id,
                'start_date' => now()->subMonths(3)->toDateString(),
                'expected_end_date' => now()->addMonths(15)->toDateString(),
                'contract_value' => '185000000.00',
            ]);
            $projects->changeStatus($tower, ProjectStatus::Active);
            $projects->assignMember($tower, $engineer->id, ProjectRole::Engineer);
            $projects->createSite($tower, ['name' => 'Main Site', 'address' => 'Pakhowal Road, Ludhiana', 'is_active' => true]);

            $mall = $projects->create([
                'name' => 'City Centre Mall Fit-out',
                'project_type' => 'commercial',
                'city' => 'Mohali',
                'state_code' => '03',
                'project_manager_id' => $pm->id,
                'contract_value' => '42500000.00',
            ]);
            $projects->createSite($mall, ['name' => 'Mall Site', 'is_active' => true]);
        });

        $this->command?->info('Demo data ready. Accounts (password "password"): superadmin@, admin@, pm@, engineer@, purchase@buildify360.test');
    }

    private function user(string $name, string $email, bool $superAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->fill(['name' => $name, 'password' => 'password']);
            $user->forceFill(['is_active' => true, 'is_super_admin' => $superAdmin, 'email_verified_at' => now()])->save();
        }

        return $user;
    }
}
