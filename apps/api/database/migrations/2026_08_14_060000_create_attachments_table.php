<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 32);
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 32);
            $table->unsignedBigInteger('size_bytes');
            $table->string('kind', 24);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64);
            $table->string('upload_key', 100);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'upload_key'], 'attachments_owner_upload_uq');
            $table->unique(['disk', 'path'], 'attachments_disk_path_uq');
            $table->index(
                ['attachable_type', 'attachable_id', 'created_at', 'id'],
                'attachments_parent_created_idx',
            );
            $table->index(['user_id', 'size_bytes'], 'attachments_owner_size_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
