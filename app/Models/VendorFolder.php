<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorFolder extends Model
{
    public const MODE_FILENAME = 'filename';

    public const MODE_FIXED = 'fixed';

    public const MODE_SKIP = 'skip';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'mode', 'machine_id', 'scanned_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
