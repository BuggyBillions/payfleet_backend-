<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('processing_fee', 12, 2)->default(0)->after('amount');
            $table->string('flutterwave_transfer_id', 50)->nullable()->after('reference');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['reference']);
            $table->dropColumn(['processing_fee', 'flutterwave_transfer_id']);
        });
    }
};