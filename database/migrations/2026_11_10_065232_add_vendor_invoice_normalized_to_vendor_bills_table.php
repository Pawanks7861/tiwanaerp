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
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->string('vendor_invoice_no_normalized', 60)->nullable()->after('vendor_invoice_no');
        });

        DB::table('vendor_bills')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('vendor_bills')->where('id', $row->id)->update([
                    'vendor_invoice_no_normalized' => VendorInvoiceNormalizer::normalize($row->vendor_invoice_no),
                ]);
            }
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropUnique('vendor_bills_invoice_unique');
            $table->unique(['vendor_id', 'vendor_invoice_no_normalized', 'live_key'], 'vendor_bills_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropUnique('vendor_bills_invoice_unique');
            $table->unique(['vendor_id', 'vendor_invoice_no', 'live_key'], 'vendor_bills_invoice_unique');
            $table->dropColumn('vendor_invoice_no_normalized');
        });
    }
};
