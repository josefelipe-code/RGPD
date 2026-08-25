<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('case_deadline_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $table->string('case_status');
            $table->timestamp('deadline');
            $table->string('alert_type');
            $table->date('reminder_date');
            $table->timestamps();
            $table->unique(['case_id', 'case_status', 'deadline', 'alert_type', 'reminder_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_deadline_reminders');
    }
};
