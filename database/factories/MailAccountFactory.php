<?php

namespace Database\Factories;

use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->words(2, true),
            'email_address' => fake()->unique()->safeEmail(),
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => fake()->safeEmail(),
            'imap_password' => fake()->password(),
            'imap_options' => null,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => fake()->safeEmail(),
            'smtp_password' => fake()->password(),
            'smtp_options' => null,
            'is_active' => true,
        ];
    }
}
