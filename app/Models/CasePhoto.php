<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CasePhoto extends Model
{
    protected $fillable = ['trouble_case_id', 'path', 'original_name'];

    protected $appends = ['url'];

    public function troubleCase(): BelongsTo
    {
        return $this->belongsTo(TroubleCase::class);
    }

    public function getUrlAttribute(): string
    {
        // 外部URL（旧GAS版のDrive写真リンクなど）はそのまま返す
        if (preg_match('#^https?://#', $this->path)) {
            return $this->path;
        }

        return Storage::disk(config('navi.photos_disk'))->url($this->path);
    }
}
