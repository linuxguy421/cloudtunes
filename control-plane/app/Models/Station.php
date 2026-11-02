<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_STARTING = 'starting';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_LIVE = 'live';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_STOPPING = 'stopping';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    // Legacy values remain readable while existing records and UI are migrated.
    public const STATUS_RUNNING = 'running';
    public const STATUS_ERROR = 'error';

    public const DESIRED_RUNNING = 'running';
    public const DESIRED_STOPPED = 'stopped';

    public const START_PENDING_STATES = [
        self::STATUS_PROVISIONING,
        self::STATUS_STARTING,
        self::STATUS_CONNECTING,
        self::STATUS_LIVE,
        self::STATUS_DEGRADED,
    ];

    public const OBSERVED_STATES = [
        self::STATUS_DRAFT,
        self::STATUS_PROVISIONING,
        self::STATUS_STARTING,
        self::STATUS_CONNECTING,
        self::STATUS_LIVE,
        self::STATUS_DEGRADED,
        self::STATUS_STOPPING,
        self::STATUS_STOPPED,
        self::STATUS_FAILED,
        self::STATUS_RUNNING,
        self::STATUS_ERROR,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'status',
        'desired_state',
        'playback_mode',
        'mount_path',
        'configuration',
        'last_health_check_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'last_health_check_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(StationLifecycleEvent::class);
    }
}
