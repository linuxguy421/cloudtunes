<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('stations',function(Blueprint $t): void {
  $t->id(); $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->string('name',120); $t->string('slug',80)->unique();
  $t->string('status',24)->default('draft')->index(); $t->string('desired_state',24)->default('stopped'); $t->string('playback_mode',24)->default('random');
  $t->string('mount_path',160)->unique(); $t->json('configuration')->nullable(); $t->timestamp('last_health_check_at')->nullable();
  $t->text('last_error')->nullable(); $t->timestamps(); $t->index(['user_id','status']);
 }); }
 public function down(): void { Schema::dropIfExists('stations'); }
};
