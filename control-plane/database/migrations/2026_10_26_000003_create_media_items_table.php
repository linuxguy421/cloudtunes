<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('media_items',function(Blueprint $t): void {
  $t->id(); $t->foreignId('station_id')->constrained()->restrictOnDelete(); $t->string('original_filename',255);
  $t->string('storage_key',512)->unique(); $t->string('mime_type',127)->nullable(); $t->unsignedBigInteger('file_size_bytes');
  $t->char('sha256',64)->nullable()->index(); $t->string('status',24)->default('pending')->index(); $t->json('metadata')->nullable();
  $t->timestamps(); $t->index(['station_id','status']);
 }); }
 public function down(): void { Schema::dropIfExists('media_items'); }
};
