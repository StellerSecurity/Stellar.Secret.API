<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secrets', function (Blueprint $table) {
            $table->boolean('has_password')
                ->default(false)
                ->after('message');
        });

        // Backfill existing rows where password column is not null
        DB::table('secrets')
            ->whereNotNull('password')
            ->update(['has_password' => true]);
    }

    public function down(): void
    {
        Schema::table('secrets', function (Blueprint $table) {
            $table->dropColumn('has_password');
        });
    }
};
