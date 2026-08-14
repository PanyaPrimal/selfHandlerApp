<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('period_rating')->nullable();
            $table->text('worked_well')->nullable();
            $table->text('did_not_work')->nullable();
            $table->text('learned')->nullable();
            $table->text('next_focus')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'period_type', 'period_start'], 'periodic_reviews_owner_type_start_unique');
            $table->index(['user_id', 'period_start', 'period_end'], 'periodic_reviews_owner_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodic_reviews');
    }
};
