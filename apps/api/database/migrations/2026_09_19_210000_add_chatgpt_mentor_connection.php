<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentor_preferences', function (Blueprint $table): void {
            $table->string('auth_mode', 12)->default('api');
            $table->string('chatgpt_model', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mentor_preferences', fn (Blueprint $table) => $table->dropColumn(['auth_mode', 'chatgpt_model']));
    }
};
