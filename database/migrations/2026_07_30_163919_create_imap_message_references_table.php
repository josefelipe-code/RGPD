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
        Schema::create('imap_message_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('folder');
            $table->string('imap_uid');
            $table->string('uid_validity')->nullable();
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->string('subject')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('reconciliation_status')->nullable();
            $table->string('reconciliation_target_folder')->nullable();
            $table->text('reconciliation_error')->nullable();
            $table->timestamps();

            $table->unique(['mail_account_id', 'folder', 'imap_uid']);
            $table->index(['mail_account_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imap_message_references');
    }
};
