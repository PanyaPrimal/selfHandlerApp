<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_revisions', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
        });
        Schema::create('workspace_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('response');
            $table->unsignedBigInteger('revision');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_receipts');
        Schema::dropIfExists('workspace_revisions');
    }
};
