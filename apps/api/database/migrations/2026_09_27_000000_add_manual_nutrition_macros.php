<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_settings', function (Blueprint $table): void {
            $table->json('macro_targets_grams')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nutrition_settings', fn (Blueprint $table) => $table->dropColumn('macro_targets_grams'));
    }
};
