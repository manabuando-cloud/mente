<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseRating extends Model
{
    protected $fillable = ['trouble_case_id', 'user_id', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
