<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eve_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->nullable()->index()->after('character_id');
        });

        DB::table('eve_notifications')
            ->whereNull('corporation_id')
            ->chunkById(100, function ($notifications): void {
                $corporationIds = DB::table('eve_characters')
                    ->whereIn('character_id', $notifications->pluck('character_id'))
                    ->pluck('corporation_id', 'character_id');

                foreach ($notifications as $notification) {
                    $corporationId = $corporationIds[$notification->character_id] ?? null;

                    if ($corporationId) {
                        DB::table('eve_notifications')
                            ->where('notification_id', $notification->notification_id)
                            ->update(['corporation_id' => $corporationId]);
                    }
                }
            }, 'notification_id');
    }

    public function down(): void
    {
        Schema::table('eve_notifications', function (Blueprint $table) {
            $table->dropIndex(['corporation_id']);
            $table->dropColumn('corporation_id');
        });
    }
};
