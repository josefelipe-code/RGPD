<?php

namespace Database\Factories;

use App\Models\CaseDeadlineReminder;
use App\Models\Expedient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseDeadlineReminder>
 */
class CaseDeadlineReminderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_id' => Expedient::factory(),
            'case_status' => 'pending_client',
            'deadline' => now()->addDay(),
            'alert_type' => 'twenty_four_hours',
            'reminder_date' => now()->toDateString(),
        ];
    }
}
