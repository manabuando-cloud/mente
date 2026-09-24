<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function (Builder $q) use ($like) {
                foreach (['symptom', 'cause', 'action', 'codes', 'parts', 'note', 'machine_id', 'engineer', 'report_no', 'quote_no'] as $col) {
                    $q->orWhere($col, 'like', $like);
                }
            });
        }
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
