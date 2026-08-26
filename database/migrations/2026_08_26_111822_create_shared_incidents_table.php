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
        Schema::create('shared_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('fingerprint')->unique();
            $table->string('title');
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->string('status')->default('open')->index();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_incidents');
    }
};
