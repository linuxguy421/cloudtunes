<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
 use HasFactory, Notifiable;
 protected $fillable=['name','email','password','role','station_limit','storage_quota_bytes'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','station_limit'=>'integer','storage_quota_bytes'=>'integer']; }
 public function stations(): HasMany { return $this->hasMany(Station::class); }
}
