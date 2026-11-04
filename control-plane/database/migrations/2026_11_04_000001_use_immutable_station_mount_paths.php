<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stations')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(500, function ($stations): void {
                foreach ($stations as $station) {
                    DB::table('stations')
                        ->where('id', $station->id)
                        ->update(['mount_path' => '/stations/'.$station->id.'.ogg']);
                }
            });
    }

    public function down(): void
    {
        DB::table('stations')
            ->select(['id', 'slug'])
            ->orderBy('id')
            ->chunkById(500, function ($stations): void {
                foreach ($stations as $station) {
                    DB::table('stations')
                        ->where('id', $station->id)
                        ->update(['mount_path' => '/'.$station->slug.'.ogg']);
                }
            });
    }
};
