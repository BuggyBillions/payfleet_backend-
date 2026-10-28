
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();

            // Unique + always true, so the database itself refuses a second row
            $table->boolean('singleton')->default(true)->unique();

            $table->decimal('vat', 12, 2)->default(0);
            $table->decimal('federal_government_fee', 12, 2)->default(0);
            $table->decimal('payfleet_fee', 12, 2)->default(0);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};