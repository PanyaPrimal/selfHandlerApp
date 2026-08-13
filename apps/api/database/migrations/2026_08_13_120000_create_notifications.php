<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('quiet_hours_enabled')->default(true);
            $table->time('quiet_starts_at')->default('23:00');
            $table->time('quiet_ends_at')->default('08:00');
            $table->boolean('digest_enabled')->default(true);
            $table->time('digest_time')->default('08:00');
            $table->json('categories');
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 48);
            // Required even for a synthetic digest. Its YYYYMMDD value avoids
            // nullable-unique behavior that differs between MySQL and SQLite.
            $table->unsignedBigInteger('source_id');
            $table->string('type', 48);
            $table->string('category', 24);
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('action_url', 500)->nullable();
            $table->json('content');
            $table->timestamp('scheduled_at');
            $table->string('status', 16)->default('scheduled');
            $table->json('channels');
            $table->unsignedSmallInteger('escalation_count')->default(0);
            $table->timestamp('next_escalation_at')->nullable();
            $table->unsignedSmallInteger('max_escalations')->default(0);
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'source_type', 'source_id', 'escalation_count'],
                'notifications_source_escalation_unique',
            );
            $table->index(['status', 'scheduled_at'], 'notifications_due_index');
            $table->index(['user_id', 'status', 'sent_at'], 'notifications_inbox_index');
            $table->index(['user_id', 'source_type', 'source_id'], 'notifications_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_settings');
    }
};
