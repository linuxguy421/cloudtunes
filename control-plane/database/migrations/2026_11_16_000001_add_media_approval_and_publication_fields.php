<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('published_at')->nullable()->after('approved_at');
            $table->text('publication_error')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'published_at', 'publication_error']);
        });
    }
};
