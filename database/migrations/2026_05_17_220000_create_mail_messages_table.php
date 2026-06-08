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
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            // FK nullable — se conectará a `cases` cuando el modelo Case exista.
            // Se deja sin constraint por ahora para no bloquear esta fase.
            $table->unsignedBigInteger('case_id')->nullable();
            $table->foreignId('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('message_id')->comment('IMAP UID o Message-ID header');
            $table->string('subject')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('received_at');
            $table->string('direction')->default('incoming');
            $table->string('status')->default('new');
            $table->timestamps();

            $table->index('case_id');
            $table->index(['mail_account_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
