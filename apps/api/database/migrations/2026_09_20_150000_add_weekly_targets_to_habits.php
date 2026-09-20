<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habits', function (Blueprint $table): void {
            $table->unsignedTinyInteger('weekly_target')->nullable();
            $table->json('weekly_target_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table): void {
            $table->dropColumn(['weekly_target', 'weekly_target_history']);
        });
    }
};
