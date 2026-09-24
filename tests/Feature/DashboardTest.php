<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Models\TroubleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_and_filters(): void
    {
        $a = Machine::factory()->create(['site' => '本社', 'category' => 'レーザー']);
        $b = Machine::factory()->create(['site' => '九州事業所', 'category' => 'ベンダー']);
        TroubleCase::factory()->create(['machine_id' => $a->id, 'date' => '2024-05-01', 'cost' => 10000, 'days' => 1, 'codes' => 'E1, E2', 'parts' => 'センサー']);
        TroubleCase::factory()->create(['machine_id' => $a->id, 'date' => '2025-05-01', 'cost' => 5000, 'days' => 2, 'codes' => 'E1', 'parts' => 'センサー、ヒューズ']);
        TroubleCase::factory()->create(['machine_id' => $b->id, 'date' => '2025-06-01', 'cost' => 1, 'codes' => 'X9']);
        TroubleCase::factory()->pending()->create(['machine_id' => $a->id, 'cost' => 999999]);

        $this->actingAs($this->user())->get('/?site=本社')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Dashboard')
            ->where('stats.summary.count', 2)
            ->where('stats.summary.total_cost', 15000)
            ->where('stats.summary.total_days', 3)
            ->where('stats.codes.0', ['label' => 'E1', 'count' => 2])
            ->where('stats.parts.0', ['label' => 'センサー', 'count' => 2])
            ->where('stats.by_year', [['year' => '2024', 'count' => 1, 'cost' => 10000], ['year' => '2025', 'count' => 1, 'cost' => 5000]])
            ->has('recent', 2));

        $this->actingAs($this->user())->get('/?category=ベンダー')->assertInertia(fn ($p) => $p->where('stats.summary.count', 1));
        $this->actingAs($this->user())->get("/?machine={$b->id}")->assertInertia(fn ($p) => $p->where('stats.summary.count', 1));
    }
}
