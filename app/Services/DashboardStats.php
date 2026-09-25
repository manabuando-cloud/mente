<?php

namespace App\Services;

use App\Models\TroubleCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** 履歴ダッシュボードの集計（件数・停止日数・費用・エラーコード/部品の頻度・年別費用） */
class DashboardStats
{
    /** @param  array{machine?: ?string, site?: ?string, category?: ?string}  $filters */
    public function build(array $filters): array
    {
        /** @var Collection<int, TroubleCase> $cases */
        $cases = $this->query($filters)->with('machine')->get();

        $byYear = $cases->filter(fn ($c) => $c->date)
            ->groupBy(fn ($c) => $c->date->format('Y'))
            ->map(fn ($g, $year) => ['year' => (string) $year, 'count' => $g->count(), 'cost' => (int) $g->sum('cost')])
            ->sortKeys()
            ->values();

        return [
            'summary' => [
                'count' => $cases->count(),
                'machines' => $cases->pluck('machine_id')->unique()->count(),
                'total_cost' => (int) $cases->sum('cost'),
                'total_days' => (int) $cases->sum('days'),
                'last_date' => $cases->max(fn ($c) => $c->date?->format('Y-m-d')),
            ],
            'codes' => $this->frequency($cases, 'codes'),
            'parts' => $this->frequency($cases, 'parts'),
            'status' => $cases->countBy(fn ($c) => $c->status ?: 'unknown')->sortDesc()
                ->map(fn ($n, $s) => ['status' => (string) $s, 'count' => $n])->values(),
            'by_year' => $byYear,
            'by_machine' => $cases->groupBy('machine_id')
                ->map(fn ($g, $id) => [
                    'machine_id' => $id,
                    'name' => $g->first()->machine?->displayName() ?? $id,
                    'count' => $g->count(),
                    'cost' => (int) $g->sum('cost'),
                ])
                ->sortByDesc('count')->take(10)->values(),
        ];
    }

    public function query(array $filters): Builder
    {
        return TroubleCase::published()
            ->when($filters['machine'] ?? null, fn ($q, $v) => $q->where('machine_id', $v))
            ->when($filters['site'] ?? null, fn ($q, $v) => $q->whereHas('machine', fn ($m) => $m->where('site', $v)))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->whereHas('machine', fn ($m) => $m->where('category', $v)));
    }

    private function frequency(Collection $cases, string $field, int $limit = 10): Collection
    {
        return $cases->flatMap(fn ($c) => array_unique($field === 'parts' ? $c->partNames() : TroubleCase::splitList($c->{$field})))
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])
            ->values();
    }
}
