<?php

namespace Database\Factories;

use App\Models\Expedient;
use App\Models\SharedIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedIncident>
 */
class SharedIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'imap_reconciliation',
            'fingerprint' => fake()->unique()->sha256(),
            'title' => 'La sincronización del expediente requiere atención.',
            'case_id' => Expedient::factory(),
            'status' => SharedIncident::StatusOpen,
            'claimed_by_user_id' => null,
            'claimed_at' => null,
        ];
    }
}
