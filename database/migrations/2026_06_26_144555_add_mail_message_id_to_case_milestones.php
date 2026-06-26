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
        Schema::table('case_milestones', function (Blueprint $table) {
            $table->foreignId('mail_message_id')
                ->nullable()
                ->after('notes')
                ->constrained('mail_messages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('case_milestones', function (Blueprint $table) {
            $table->dropForeign(['mail_message_id']);
            $table->dropColumn('mail_message_id');
        });
    }
};
