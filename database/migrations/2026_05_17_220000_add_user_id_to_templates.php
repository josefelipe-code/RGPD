<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->nullable()->change();
        });

        if (Schema::hasColumn('templates', 'mail_account_id')) {
            DB::statement('
                UPDATE templates
                SET user_id = (
                    SELECT user_id FROM mail_accounts
                    WHERE mail_accounts.id = templates.mail_account_id
                )
                WHERE templates.mail_account_id IS NOT NULL
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
