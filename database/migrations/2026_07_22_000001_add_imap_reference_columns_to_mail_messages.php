<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('imap_uid')->nullable()->after('message_id');
            $table->boolean('is_read')->default(false)->after('folder');
            $table->unique(['mail_account_id', 'folder', 'imap_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropUnique(['mail_account_id', 'folder', 'imap_uid']);
            $table->dropColumn(['imap_uid', 'is_read']);
        });
    }
};
