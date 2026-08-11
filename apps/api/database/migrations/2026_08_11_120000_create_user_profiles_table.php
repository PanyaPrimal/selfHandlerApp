<?php

use App\Support\ProfileDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64);
            $table->string('locale', 16);
            $table->string('unit_system', 16);
            $table->char('base_currency', 3);
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 16)->nullable();
            $table->decimal('height_meters', 6, 3)->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->decimal('body_fat_percentage', 5, 2)->nullable();
            $table->string('baseline_activity', 24)->nullable();
            $table->string('recommendation_tone', 16);
            $table->string('bmr_formula', 32);
            $table->timestamps();
        });

        $defaults = ProfileDefaults::attributes();
        $now = now();

        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use ($defaults, $now): void {
            DB::table('user_profiles')->insertOrIgnore(
                $users->map(fn ($user): array => [
                    'user_id' => $user->id,
                    ...$defaults,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
