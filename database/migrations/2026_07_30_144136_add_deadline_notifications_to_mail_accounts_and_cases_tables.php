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
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->string('deadline_notification_email')->nullable()->after('email_address');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->timestamp('state_deadline')->nullable()->after('status');
            $table->index(['status', 'state_deadline']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex(['status', 'state_deadline']);
            $table->dropColumn('state_deadline');
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('deadline_notification_email');
        });
    }
};
