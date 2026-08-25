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
        Schema::create('expedient_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key');
            $table->string('imap_folder')->nullable();
            $table->unsignedSmallInteger('deadline_days')->nullable();
            $table->boolean('is_final')->default(false);
            $table->timestamps();

            $table->unique(['mail_account_id', 'key']);
            $table->index(['mail_account_id', 'is_final']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expedient_states');
    }
};
