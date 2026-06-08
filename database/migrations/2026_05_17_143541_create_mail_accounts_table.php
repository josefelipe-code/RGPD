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
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('email_address');
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_encryption')->default('ssl');
            $table->string('imap_username');
            $table->text('imap_password');
            $table->text('imap_options')->nullable();
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_encryption')->default('tls');
            $table->string('smtp_username');
            $table->text('smtp_password');
            $table->text('smtp_options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unique(['user_id', 'email_address']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
