<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TroubleCase extends Model
{
    use HasFactory;

    public const REVIEW_PUBLISHED = 'published';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'machine_id', 'date', 'engineer', 'symptom', 'report_no', 'quote_no',
        'cause', 'action', 'codes', 'parts', 'cost', 'status', 'note', 'days',
        'submitted_by', 'slack_notified_at', 'report_url', 'quote_url',
        'review_status', 'source', 'source_file_id', 'source_url', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'cost' => 'integer',
            'days' => 'integer',
            'slack_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TroubleCase $case) {
            $case->id ??= 'c_'.Str::lower((string) Str::ulid());
        });
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(CasePhoto::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(CaseRating::class);
    }

    /** ダッシュボード・検索に出す正式な対応履歴だけ */
    public function scopePublished(Builder $query): void
    {
        $query->where('review_status', self::REVIEW_PUBLISHED);
    }

    /** キーワード検索（空白区切りAND、各語はいずれかの項目に部分一致） */
    public function scopeKeyword(Builder $query, ?string $keyword): void
    {
        $terms = preg_split('/[\s　]+/u', trim((string) $keyword), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms as $term) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $q) use ($like) {
                foreach (['symptom', 'cause', 'action', 'codes', 'parts', 'note', 'machine_id', 'engineer', 'report_no', 'quote_no'] as $col) {
                    // エスケープ文字はDBごとに既定が違う（SQLiteは無し、MySQLは\）ので、どこでも同じ意味の ! を明示する
                    $q->orWhereRaw("{$col} LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }
    }

    /**
     * 交換部品 [{n: 部品名, id: 品番, q: 数量}]。代入時は配列・JSON文字列・"部品A, 部品B" のどれでも受け付けて正規化する。
     * 日本語をエスケープせずに保存するので LIKE 検索できる。
     */
    protected function parts(): Attribute
    {
        return Attribute::make(
            get: function (?string $v) {
                if ($v === null || $v === '') {
                    return null;
                }
                $decoded = json_decode($v, true);

                return is_array($decoded) ? $decoded : self::normalizeParts($v);
            },
            set: fn (mixed $v) => ($p = self::normalizeParts($v ?? [])) ? json_encode($p, JSON_UNESCAPED_UNICODE) : null,
        );
    }

    /** 交換部品の部品名だけの配列 */
    public function partNames(): array
    {
        return array_values(array_filter(array_map(fn ($p) => trim((string) ($p['n'] ?? '')), $this->parts ?? [])));
    }

    /**
     * 交換部品の入力を正規化する。[{n,id,q}] の配列、または "部品A, 部品B" の文字列を受け付ける。
     *
     * @return list<array{n: string, id?: string, q?: int|float}>|null
     */
    public static function normalizeParts(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_map(fn ($n) => ['n' => $n], self::splitList($value));
        }
        $parts = [];
        foreach ((array) $value as $p) {
            $p = is_array($p) ? $p : ['n' => (string) $p];
            $name = trim((string) ($p['n'] ?? $p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $part = ['n' => $name];
            if (($id = trim((string) ($p['id'] ?? ''))) !== '') {
                $part['id'] = $id;
            }
            if (isset($p['q']) && is_numeric($p['q'])) {
                $part['q'] = $p['q'] + 0;
            }
            $parts[] = $part;
        }

        return $parts ?: null;
    }

    /** "E101, E102、E103" のような区切り文字列を配列へ */
    public static function splitList(?string $value): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => trim($v),
            preg_split('/[,、，;；\/\n]+/u', (string) $value)
        ), fn ($v) => $v !== ''));
    }
}
