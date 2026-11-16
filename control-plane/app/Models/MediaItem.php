<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'station_id',
        'original_filename',
        'storage_key',
        'mime_type',
        'file_size_bytes',
        'sha256',
        'status',
        'metadata',
        'approved_by',
        'approved_at',
        'published_at',
        'publication_error',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'metadata' => 'array',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
