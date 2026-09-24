<?php

namespace Database\Factories;

use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TroubleCase> */
class TroubleCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'date' => fake()->dateTimeBetween('-5 years')->format('Y-m-d'),
            'engineer' => fake('ja_JP')->lastName(),
            'symptom' => fake()->randomElement(['バックゲージが原点復帰しない', 'レーザー出力が低下する', '油圧ユニットから異音', 'ノズル衝突アラームが頻発']),
            'cause' => 'センサー不良',
            'action' => 'センサー交換',
            'codes' => 'E'.fake()->numberBetween(1000, 9999),
            'parts' => '近接センサー',
            'cost' => fake()->numberBetween(0, 500) * 1000,
            'days' => fake()->numberBetween(0, 5),
            'status' => '完了',
            'review_status' => TroubleCase::REVIEW_PUBLISHED,
            'source' => 'manual',
        ];
    }

    public function pending(): static
    {
        return $this->state(['review_status' => TroubleCase::REVIEW_PENDING, 'source' => 'ai_ingest']);
    }
}
