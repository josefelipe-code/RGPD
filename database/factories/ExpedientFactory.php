<?php

namespace Database\Factories;

use App\Enums\CaseStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expedient>
 */
class ExpedientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_number' => 'EXP-'.fake()->unique()->numberBetween(10000, 99999),
            'sender_email' => fake()->safeEmail(),
            'sender_phone' => fake()->phoneNumber(),
            'mail_account_id' => MailAccount::factory(),
            'assigned_user_id' => User::factory(),
            'status' => CaseStatus::PendingClient,
            'request_type' => fake()->randomElement(['consulta', 'reclamo', 'solicitud', null]),
            'opened_at' => fake()->dateTimeBetween('-60 days', 'now'),
            'closed_at' => null,
        ];
    }

    /**
     * Indicate that the expedient is concluded.
     */
    public function concluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CaseStatus::Concluded,
            'closed_at' => fake()->dateTimeBetween('-10 days', 'now'),
        ]);
    }

    /**
     * Indicate that the expedient is pending provider response.
     */
    public function pendingProvider(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CaseStatus::PendingProvider,
        ]);
    }
}
