<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Storage inbox: captured items, their containers and their labels.
 *
 * Purely additive. `items` is one table plus a `type` column, which
 * `docs/design/data-conventions.md` section 2 already assigns to Storage: the
 * types share their fields and differ in meaning, so a detail table would carry
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'is_archived']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('inbox');
            $table->string('priority', 8)->nullable();
            // A calendar day, not a schedule: nothing expands or reminds on it.
            $table->date('due_on')->nullable();

            // Deleting a container must not delete the work inside it.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('items')->nullOnDelete();

            $table->boolean('is_blocker')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'project_id']);
            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('item_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_tag');
        Schema::dropIfExists('items');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('projects');
    }
};
