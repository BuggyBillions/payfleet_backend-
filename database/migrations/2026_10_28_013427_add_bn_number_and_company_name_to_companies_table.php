<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bn_number', 20)->nullable()->unique();   // digits only, e.g. 76543456789
            $table->string('company_name')->nullable();              // the name registered with CAC
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['bn_number']);
            $table->dropColumn(['bn_number', 'company_name']);
        });
    }
};