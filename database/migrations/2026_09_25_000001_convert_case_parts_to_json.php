<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 交換部品をカンマ区切りの文字列から [{n: 部品名, id: 品番, q: 数量}] の JSON に変える。
 * 旧GAS版の parts 列と同じ形。部品名自体にカンマを含むもの（例: "APC910 Standard 2, LS187, BIOS"）があり、
 * 区切り文字では表現できないため。列の型は text のまま（JSON文字列を入れる）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('trouble_cases')->whereNotNull('parts')->orderBy('id')->each(function ($row) {
            $value = trim((string) $row->parts);
            if ($value === '' || str_starts_with($value, '[')) {
                return;
            }
            $parts = array_values(array_map(
                fn ($n) => ['n' => trim($n)],
                array_filter(preg_split('/[,、，;；\n]+/u', $value), fn ($n) => trim($n) !== '')
            ));
            DB::table('trouble_cases')->where('id', $row->id)
                ->update(['parts' => $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null]);
        });
    }

    public function down(): void
    {
        DB::table('trouble_cases')->whereNotNull('parts')->orderBy('id')->each(function ($row) {
            $parts = json_decode((string) $row->parts, true);
            if (is_array($parts)) {
                DB::table('trouble_cases')->where('id', $row->id)
                    ->update(['parts' => implode(', ', array_column($parts, 'n')) ?: null]);
            }
        });
    }
};
