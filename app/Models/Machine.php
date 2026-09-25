<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'model', 'maker', 'label', 'site', 'category', 'manuals',
        'drive_folder_id', 'source', 'submitted_by', 'equipment_no', 'spec', 'installed_on',
    ];

    protected function casts(): array
    {
        return ['manuals' => 'array', 'installed_on' => 'date:Y-m-d'];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(TroubleCase::class);
    }

    /** 表示名は「機種名 + 機械番号」（ユーザー要望で統一済みの表示順） */
    public function displayName(): string
    {
        return trim($this->model.' '.$this->id);
    }
}
