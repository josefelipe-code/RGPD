<?php

namespace Database\Factories;

use App\Enums\MilestoneAction;
use App\Models\CaseMilestone;
use App\Models\Expedient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaseMilestone>
 */
class CaseMilestoneFactory extends Factory
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
            'user_id' => User::factory(),
            'action' => MilestoneAction::Opened,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * Indicate that the milestone is a reply to client.
     */
    public function repliedClient(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => MilestoneAction::RepliedClient,
        ]);
    }

    /**
     * Indicate that the milestone is a reply to provider.
     */
    public function repliedProvider(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => MilestoneAction::RepliedProvider,
        ]);
    }

    /**
     * Indicate that the milestone closes the case.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => MilestoneAction::Closed,
        ]);
    }
}
