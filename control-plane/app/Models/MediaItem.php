<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MediaItem extends Model {
 public const STATUS_PENDING='pending', STATUS_READY='ready', STATUS_FAILED='failed';
 protected $fillable=['station_id','original_filename','storage_key','mime_type','file_size_bytes','sha256','status','metadata'];
 protected function casts(): array { return ['file_size_bytes'=>'integer','metadata'=>'array']; }
 public function station(): BelongsTo { return $this->belongsTo(Station::class); }
}
