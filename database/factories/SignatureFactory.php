<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\Signature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signature>
 */
class SignatureFactory extends Factory
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
            'name' => fake()->words(2, true),
            'body' => fake()->paragraphs(2, true),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the signature is the default.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
