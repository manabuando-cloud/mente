<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * ローカル確認用のデモデータ。本番データは `php artisan navi:import` で旧スプレッドシートから移行する。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'dev@example.com'], ['name' => 'dev', 'is_admin' => true]);

        $machines = collect([
            ['id' => 'B1508I0077', 'model' => 'TruBend5230(B23)', 'maker' => 'TRUMPF', 'site' => '本社', 'category' => 'ベンダー'],
            ['id' => 'A0111A0024', 'model' => 'TruLaser3030', 'maker' => 'TRUMPF', 'site' => '本社', 'category' => 'レーザー'],
            ['id' => 'K2201L0003', 'model' => 'ENSIS-3015AJ', 'maker' => 'AMADA', 'site' => '九州事業所', 'category' => 'レーザー'],
            ['id' => 'T1903B0011', 'model' => 'HG1303', 'maker' => 'AMADA', 'site' => '東北工場', 'category' => 'ベンダー'],
        ])->map(fn ($m) => Machine::updateOrCreate(['id' => $m['id']], [...$m, 'source' => 'master']));

        if (TroubleCase::count() === 0) {
            foreach ($machines as $m) {
                TroubleCase::factory()->count(12)->create(['machine_id' => $m->id]);
            }
            TroubleCase::factory()->pending()->count(3)->create(['machine_id' => $machines[0]->id]);
        }
    }
}
