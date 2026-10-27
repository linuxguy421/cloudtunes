<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('approval_status', 16)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('station_limit')->default(3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at']);
            $table->unsignedInteger('station_limit')->default(2)->change();
        });
    }
};
