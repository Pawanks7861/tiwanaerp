<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;
use RuntimeException;

class LiveBootstrapper
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly CompanyProvisioner $provisioner,
    ) {}

    /**
     * Create or update the live superadmin and Tiwana company. Always writes the given password.
     *
     * @return array{user: User, company: Company, created_user: bool}
     */
    public function ensure(?string $password = null): array
    {
        $email = strtolower(trim((string) config('bootstrap.superadmin_email')));
        $name = (string) config('bootstrap.superadmin_name');
        $code = strtoupper((string) config('bootstrap.company_code'));
        $companyName = (string) config('bootstrap.company_name');
        $password = $password ?? $this->passwordFromConfig();

        $user = User::query()->firstOrNew(['email' => $email]);
        $createdUser = ! $user->exists;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_super_admin' => true,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $company = Company::query()->withTrashed()->where('code', $code)->first();
        if ($company === null) {
            $company = $this->companies->create([
                'name' => $companyName,
                'legal_name' => $companyName,
                'code' => $code,
                'currency' => 'INR',
                'fy_start_month' => 4,
                'timezone' => 'Asia/Kolkata',
                'is_active' => true,
            ], $user);
        } else {
            if ($company->trashed()) {
                $company->restore();
            }
            $company->forceFill([
                'name' => $companyName,
                'legal_name' => $company->legal_name ?: $companyName,
                'is_active' => true,
            ])->save();
            $this->provisioner->provision($company);
            $this->companies->addMember($company, $user, [DefaultRoles::COMPANY_ADMIN]);
        }

        $user->forceFill(['current_company_id' => $company->id])->saveQuietly();

        return [
            'user' => $user->fresh() ?? $user,
            'company' => $company->fresh() ?? $company,
            'created_user' => $createdUser,
        ];
    }

    public function passwordFromConfig(): string
    {
        $password = config('bootstrap.superadmin_password') ?: env('SUPERADMIN_PASSWORD');
        if (filled($password)) {
            return (string) $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException('Set SUPERADMIN_PASSWORD in .env, or pass --password.');
        }

        return 'password';
    }
}
