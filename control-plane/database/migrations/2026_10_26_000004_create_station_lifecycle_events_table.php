<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('station_lifecycle_events',function(Blueprint $t): void {
  $t->id(); $t->foreignId('station_id')->constrained()->restrictOnDelete(); $t->uuid('operation_id')->index();
  $t->string('event_type',40); $t->string('from_state',24)->nullable(); $t->string('to_state',24)->nullable();
  $t->json('result')->nullable(); $t->text('message')->nullable(); $t->timestamp('created_at')->useCurrent(); $t->index(['station_id','created_at']);
 }); }
 public function down(): void { Schema::dropIfExists('station_lifecycle_events'); }
};
