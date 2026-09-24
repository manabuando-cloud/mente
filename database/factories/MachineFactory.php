<?php

namespace Database\Factories;

use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Machine> */
class MachineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => strtoupper(fake()->unique()->bothify('?####?####')),
            'model' => fake()->randomElement(['TruBend5230(B23)', 'TruLaser3030', 'HG1303', 'ENSIS-3015AJ']),
            'maker' => fake()->randomElement(['TRUMPF', 'AMADA']),
            'site' => fake()->randomElement(['本社', '九州事業所', '東北工場', '中部事業所']),
            'category' => fake()->randomElement(['レーザー', 'ベンダー']),
            'manuals' => [],
            'source' => 'master',
        ];
    }
}
