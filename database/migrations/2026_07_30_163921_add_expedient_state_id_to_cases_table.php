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
        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('expedient_state_id')->nullable()->after('mail_account_id')->constrained('expedient_states')->nullOnDelete();
            $table->index(['mail_account_id', 'expedient_state_id']);
        });

        DB::table('mail_accounts')->orderBy('id')->each(function (object $account): void {
            $states = collect([
                ['name' => 'Pending client', 'key' => 'pending_client', 'is_final' => false],
                ['name' => 'Pending provider', 'key' => 'pending_provider', 'is_final' => false],
                ['name' => 'Concluded', 'key' => 'concluded', 'is_final' => true],
            ])->mapWithKeys(fn (array $state): array => [$state['key'] => DB::table('expedient_states')->insertGetId([
                'mail_account_id' => $account->id,
                ...$state,
                'created_at' => now(),
                'updated_at' => now(),
            ])]);

            DB::table('cases')->where('mail_account_id', $account->id)->orderBy('id')->each(function (object $case) use ($states): void {
                DB::table('cases')->where('id', $case->id)->update([
                    'expedient_state_id' => $states[$case->status] ?? $states['pending_client'],
                ]);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expedient_state_id');
        });
    }
};
