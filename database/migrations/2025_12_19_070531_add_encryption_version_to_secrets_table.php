<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secrets', function (Blueprint $table) {
            $table->string('encryption_version', 16)
                ->default('v1')
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('secrets', function (Blueprint $table) {
            $table->dropColumn('encryption_version');
        });
    }
};
