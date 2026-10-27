<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StationLifecycleEvent extends Model {
 public $timestamps=false;
 protected $fillable=['station_id','operation_id','event_type','from_state','to_state','result','message','created_at'];
 protected function casts(): array { return ['result'=>'array','created_at'=>'datetime']; }
 public function station(): BelongsTo { return $this->belongsTo(Station::class); }
}
