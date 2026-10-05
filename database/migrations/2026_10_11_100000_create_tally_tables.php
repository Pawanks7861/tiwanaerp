<?php

use App\Support\Database\Columns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company TallyPrime connection, ledger and cost-centre mappings, and sync history.
 * These tables are an external side effect. They are not operational ledgers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tally_connections', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->boolean('enabled')->default(false);
            $table->string('transport', 20)->default('direct');
            $table->string('protocol', 8)->default('http');
            $table->string('format', 8)->default('xml');
            $table->string('host', 255)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('tally_company_name', 255)->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->boolean('auto_sync')->default(false);
            $table->boolean('sync_approved_transactions')->default(true);
            $table->boolean('dry_run')->default(false);
            $table->boolean('cost_centres_enabled')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_status', 40)->nullable();
            $table->string('last_status_message', 500)->nullable();
            $table->timestamps();

            $table->unique('company_id');
        });

        Schema::create('tally_ledger_mappings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('mapping_type', 40);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_key', 80)->nullable();
            $table->string('map_key', 120);
            $table->string('tally_ledger_name', 255);
            $table->string('tally_parent_group', 255)->nullable();
            $table->boolean('auto_create_allowed')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'map_key']);
            $table->index(['company_id', 'mapping_type']);
        });

        Schema::create('tally_cost_centre_mappings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('tally_cost_centre_name', 255);
            $table->string('tally_guid', 80)->nullable();
            $table->string('status', 20)->default('mapped');
            $table->timestamps();

            $table->unique(['company_id', 'project_id']);
        });

        Schema::create('tally_master_mappings', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('tally_ledger_name', 255);
            $table->string('tally_guid', 80)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('tally_sync_records', function (Blueprint $table) {
            $table->id();
            Columns::company($table);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('action', 20);
            $table->string('voucher_type', 40)->nullable();
            $table->string('erp_reference', 80);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->date('document_date')->nullable();
            Columns::money($table, 'amount')->nullable();
            $table->string('tally_guid', 80)->nullable();
            $table->string('tally_master_id', 80)->nullable();
            $table->string('tally_alter_id', 80)->nullable();
            $table->string('status', 30);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->char('request_hash', 64)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'source_type', 'source_id', 'action'], 'tally_sync_source_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'source_type']);
            $table->index(['project_id', 'document_date']);
        });

        $this->grantAccountantTallyPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', $this->accountantTallyPermissions())->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('tally_sync_records');
        Schema::dropIfExists('tally_master_mappings');
        Schema::dropIfExists('tally_cost_centre_mappings');
        Schema::dropIfExists('tally_ledger_mappings');
        Schema::dropIfExists('tally_connections');
    }

    /**
     * Existing companies are not re-provisioned. Company Admin already has *.
     * Accountant roles receive view, sync, retry and mapping, not manage.
     */
    private function grantAccountantTallyPermissions(): void
    {
        $now = now();
        $existing = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_values(array_diff($this->accountantTallyPermissions(), $existing));
        if ($missing !== []) {
            DB::table('permissions')->insert(array_map(fn (string $name) => [
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ], $missing));
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', ['tally.view', 'tally.sync', 'tally.retry', 'tally.mapping'])
            ->pluck('id');
        $roleIds = DB::table('roles')->where('name', 'Accountant')->where('guard_name', 'web')->pluck('id');
        $rows = [];
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = ['permission_id' => $permissionId, 'role_id' => $roleId];
            }
        }
        if ($rows !== []) {
            foreach ($rows as $row) {
                $exists = DB::table('role_has_permissions')
                    ->where('permission_id', $row['permission_id'])
                    ->where('role_id', $row['role_id'])
                    ->exists();
                if (! $exists) {
                    DB::table('role_has_permissions')->insert($row);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function accountantTallyPermissions(): array
    {
        return ['tally.view', 'tally.manage', 'tally.sync', 'tally.retry', 'tally.mapping'];
    }
};
