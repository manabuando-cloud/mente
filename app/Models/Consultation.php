<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    protected $fillable = [
        'trouble_case_id', 'machine_id', 'site', 'symptom', 'answer',
        'similar_case_ids', 'source', 'user_id',
    ];

    protected function casts(): array
    {
        return ['similar_case_ids' => 'array'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
