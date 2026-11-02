<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Station extends Model {
 use HasFactory, SoftDeletes;
 public const STATUS_DRAFT='draft', STATUS_PROVISIONING='provisioning', STATUS_STOPPED='stopped', STATUS_RUNNING='running', STATUS_ERROR='error';
 public const DESIRED_RUNNING='running', DESIRED_STOPPED='stopped';
 protected $fillable=['user_id','name','slug','status','desired_state','playback_mode','mount_path','configuration','last_health_check_at','last_error'];
 protected function casts(): array { return ['configuration'=>'array','last_health_check_at'=>'datetime','deleted_at'=>'datetime']; }
 public function owner(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }
 public function mediaItems(): HasMany { return $this->hasMany(MediaItem::class); }
 public function lifecycleEvents(): HasMany { return $this->hasMany(StationLifecycleEvent::class); }
}
