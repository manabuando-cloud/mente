<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedReportFile extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'file_id';

    protected $keyType = 'string';

    protected $fillable = ['file_id', 'machine_id', 'file_name', 'result', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
