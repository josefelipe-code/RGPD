<?php

namespace Database\Factories;

use App\Enums\MailDirection;
use App\Enums\MailMessageStatus;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailMessage>
 */
class MailMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_account_id' => MailAccount::factory(),
            'message_id' => fake()->uuid(),
            'subject' => fake()->sentence,
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'body_html' => fake()->paragraphs(3, true),
            'body_text' => fake()->paragraphs(3, true),
            'received_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'direction' => MailDirection::Incoming,
            'status' => MailMessageStatus::New,
        ];
    }

    /**
     * Indicate that the message is outgoing.
     */
    public function outgoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MailDirection::Outgoing,
        ]);
    }

    /**
     * Indicate that the message is already associated with a case.
     */
    public function associated(): static
    {
        return $this->state(fn (array $attributes) => [
            'case_id' => Expedient::factory(),
            'status' => MailMessageStatus::Associated,
        ]);
    }
}
