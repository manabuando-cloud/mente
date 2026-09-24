<?php

namespace App\Http\Presenters;

use App\Models\Machine;
use App\Models\TroubleCase;
use App\Models\User;
use Illuminate\Support\Collection;

/** 対応履歴カード（検索結果・ダッシュボード・詳細で共通）に渡す形 */
class CasePresenter
{
    public static function one(TroubleCase $c, ?User $viewer = null): array
    {
        $ratings = $c->relationLoaded('ratings') ? $c->ratings : $c->ratings()->get();

        return [
            'id' => $c->id,
            'machine_id' => $c->machine_id,
            'machine' => $c->machine ? self::machine($c->machine) : null,
            'date' => $c->date?->format('Y-m-d'),
            'engineer' => $c->engineer,
            'symptom' => $c->symptom,
            'report_no' => $c->report_no,
            'quote_no' => $c->quote_no,
            'cause' => $c->cause,
            'action' => $c->action,
            'codes' => TroubleCase::splitList($c->codes),
            'parts' => TroubleCase::splitList($c->parts),
            'codes_raw' => $c->codes,
            'parts_raw' => $c->parts,
            'cost' => $c->cost,
            'status' => $c->status,
            'note' => $c->note,
            'days' => $c->days,
            'submitted_by' => $c->submitted_by,
            'report_url' => $c->report_url,
            'quote_url' => $c->quote_url,
            'review_status' => $c->review_status,
            'source' => $c->source,
            'source_url' => $c->source_url,
            'review_note' => $c->review_note,
            'photos' => $c->relationLoaded('photos') ? $c->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->url])->values() : [],
            'rating' => [
                'up' => $ratings->where('value', 1)->count(),
                'down' => $ratings->where('value', -1)->count(),
                'mine' => $viewer ? (int) ($ratings->firstWhere('user_id', $viewer->id)?->value ?? 0) : 0,
            ],
            'similarity' => $c->getAttribute('similarity'),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function many(iterable $cases, ?User $viewer = null): array
    {
        return collect($cases)->map(fn ($c) => self::one($c, $viewer))->values()->all();
    }

    public static function machine(Machine $m): array
    {
        return [
            'id' => $m->id,
            'model' => $m->model,
            'maker' => $m->maker,
            'label' => $m->label,
            'site' => $m->site,
            'category' => $m->category,
            'manuals' => $m->manuals ?? [],
            'name' => $m->displayName(),
        ];
    }

    /** 機種セレクト用（「機種名 + 機械番号」で並べる） */
    public static function machineOptions(): Collection
    {
        return Machine::orderBy('model')->orderBy('id')->get()->map(fn ($m) => self::machine($m));
    }
}
