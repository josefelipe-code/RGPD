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
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('to_email')->nullable()->after('message_id');
            $table->json('cc')->nullable()->after('to_email');
            $table->json('bcc')->nullable()->after('cc');
            $table->timestamp('sent_at')->nullable()->after('received_at');
            $table->string('in_reply_to')->nullable()->after('sent_at');
            $table->text('references')->nullable()->after('in_reply_to');
            $table->string('folder')->nullable()->after('references');
            $table->string('thread_id')->nullable()->after('folder');
            $table->string('sender_phone')->nullable()->after('from_name');

            $table->index(['mail_account_id', 'in_reply_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropIndex(['mail_account_id', 'in_reply_to']);
            $table->dropColumn([
                'to_email',
                'cc',
                'bcc',
                'sent_at',
                'in_reply_to',
                'references',
                'folder',
                'thread_id',
                'sender_phone',
            ]);
        });
    }
};
