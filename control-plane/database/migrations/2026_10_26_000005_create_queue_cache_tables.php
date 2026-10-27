<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('jobs',function(Blueprint $t): void { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedSmallInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
  Schema::create('failed_jobs',function(Blueprint $t): void { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
  Schema::create('cache',function(Blueprint $t): void { $t->string('key')->primary(); $t->mediumText('value'); $t->integer('expiration'); });
  Schema::create('cache_locks',function(Blueprint $t): void { $t->string('key')->primary(); $t->string('owner'); $t->integer('expiration'); });
 }
 public function down(): void { Schema::dropIfExists('cache_locks'); Schema::dropIfExists('cache'); Schema::dropIfExists('failed_jobs'); Schema::dropIfExists('jobs'); }
};
