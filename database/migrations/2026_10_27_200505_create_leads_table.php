<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('address');
            $table->string('phone', 50)->unique();          // stops two sales experts chasing the same lead
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->text('response')->nullable();           // what the lead said
            $table->date('follow_up_date')->nullable();
            $table->string('follow_up_process', 30)->nullable(); // call, visit, email, whatsapp, demo, meeting
            $table->string('status', 20)->default('new');   // new, contacted, interested, follow_up, converted, lost
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('follow_up_date');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};