<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual order for checklist-style lists.
 *
 * Purely additive. `position` orders an item among its siblings: the children
 * of one parent, or the top-level items of one project (or of no project).
 * Existing rows keep 0 and fall back to creation order, so nothing is moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->unsignedInteger('position')->default(0)->after('parent_id');
            $table->index(['user_id', 'project_id', 'parent_id', 'position'], 'items_owner_sibling_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropIndex('items_owner_sibling_order_idx');
            $table->dropColumn('position');
        });
    }
};
