<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentor_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->text('memory')->nullable();
            $table->unsignedInteger('monthly_token_limit')->default(1000000);
            $table->timestamps();
        });
        Schema::create('mentor_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('status', 20)->default('pending');
            $table->string('provider', 30);
            $table->string('model', 160);
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->json('sources')->nullable();
            $table->json('actions')->nullable();
            $table->longText('context')->nullable();
            $table->unsignedTinyInteger('round')->default(0);
            $table->foreignId('connection_id')->nullable()->constrained('llm_connections')->nullOnDelete();
            $table->unsignedBigInteger('base_revision')->default(0);
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('estimated_usd', 12, 6)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_turns');
        Schema::dropIfExists('mentor_preferences');
    }
};
