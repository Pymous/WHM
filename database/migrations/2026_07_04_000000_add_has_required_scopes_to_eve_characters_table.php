<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eve_characters', function (Blueprint $table) {
            $table->boolean('has_required_scopes')->default(false)->after('is_valid');
        });

        $normalize = static function (?string $scopes): array {
            if (! $scopes) {
                return [];
            }

            return array_values(array_unique(array_filter(
                preg_split('/[\s,]+/', trim($scopes)) ?: []
            )));
        };

        $expectedScopes = $normalize(config('services.eveonline.scopes'));

        DB::table('eve_characters')
            ->select(['id', 'esi_scopes'])
            ->orderBy('id')
            ->chunkById(100, function ($characters) use ($expectedScopes, $normalize): void {
                foreach ($characters as $character) {
                    DB::table('eve_characters')
                        ->where('id', $character->id)
                        ->update([
                            'has_required_scopes' => array_diff(
                                $expectedScopes,
                                $normalize($character->esi_scopes)
                            ) === [],
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('eve_characters', function (Blueprint $table) {
            $table->dropColumn('has_required_scopes');
        });
    }
};
