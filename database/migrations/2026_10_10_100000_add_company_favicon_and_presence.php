<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company logos already live on companies.logo_path. This adds the favicon beside it and a
 * lightweight last-seen stamp for chat presence. Neither column is taken from request input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('favicon_path', 500)->nullable()->after('logo_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
