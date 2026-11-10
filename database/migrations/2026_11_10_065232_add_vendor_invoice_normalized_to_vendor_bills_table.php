<?php

use App\Support\Finance\VendorInvoiceNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendor_bills', 'vendor_invoice_no_normalized')) {
            Schema::table('vendor_bills', function (Blueprint $table) {
                $table->string('vendor_invoice_no_normalized', 60)->nullable()->after('vendor_invoice_no');
            });
        }

        DB::table('vendor_bills')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('vendor_bills')->where('id', $row->id)->update([
                    'vendor_invoice_no_normalized' => VendorInvoiceNormalizer::normalize($row->vendor_invoice_no),
                ]);
            }
        });

        $this->ensureVendorIdIndexForForeignKey();
        $this->replaceInvoiceUniqueIndex(['vendor_id', 'vendor_invoice_no_normalized', 'live_key']);
    }

    public function down(): void
    {
        $this->ensureVendorIdIndexForForeignKey();
        $this->replaceInvoiceUniqueIndex(['vendor_id', 'vendor_invoice_no', 'live_key']);

        if (Schema::hasColumn('vendor_bills', 'vendor_invoice_no_normalized')) {
            Schema::table('vendor_bills', function (Blueprint $table) {
                $table->dropColumn('vendor_invoice_no_normalized');
            });
        }
    }

    /**
     * MySQL/MariaDB may reuse vendor_bills_invoice_unique as the supporting
     * index for vendor_bills.vendor_id FK. Add a dedicated index first so the
     * unique key can be dropped safely.
     */
    private function ensureVendorIdIndexForForeignKey(): void
    {
        $hasDedicatedVendorIdIndex = collect(Schema::getIndexes('vendor_bills'))
            ->contains(function (array $index): bool {
                if (($index['name'] ?? '') === 'vendor_bills_invoice_unique') {
                    return false;
                }

                return ($index['columns'][0] ?? null) === 'vendor_id';
            });

        if ($hasDedicatedVendorIdIndex) {
            return;
        }

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->index('vendor_id', 'vendor_bills_vendor_id_fk_support');
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function replaceInvoiceUniqueIndex(array $columns): void
    {
        $indexes = collect(Schema::getIndexes('vendor_bills'));
        $current = $indexes->firstWhere('name', 'vendor_bills_invoice_unique');

        if ($current !== null && ($current['columns'] ?? []) === $columns && ($current['unique'] ?? false)) {
            return;
        }

        if ($current !== null) {
            Schema::table('vendor_bills', function (Blueprint $table) {
                $table->dropUnique('vendor_bills_invoice_unique');
            });
        }

        Schema::table('vendor_bills', function (Blueprint $table) use ($columns) {
            $table->unique($columns, 'vendor_bills_invoice_unique');
        });
    }
};
