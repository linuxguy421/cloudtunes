<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_lifecycle_events', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')
                ->nullable()
                ->after('station_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('station_lifecycle_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_user_id');
        });
    }
};
