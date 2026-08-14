<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['google_calendar', 'apple_calendar']);
            $table->enum('kind', ['calendar']);
            $table->enum('status', ['pending', 'active', 'expired', 'revoked']);
            $table->string('external_account_id', 512)->nullable();
            $table->text('external_account_label')->nullable();
            $table->text('external_calendar_id')->nullable();
            $table->text('external_calendar_name')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('secret')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->json('settings');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 48)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider'], 'integrations_owner_provider_uq');
            $table->index(['status', 'last_sync_at'], 'integrations_due_idx');
        });

        Schema::create('external_calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->char('external_id_hash', 64);
            $table->text('summary')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_all_day');
            $table->enum('status', ['confirmed', 'tentative']);
            $table->timestamps();

            $table->unique(['integration_id', 'external_id_hash'], 'calendar_events_integration_external_uq');
            $table->index(['user_id', 'starts_at', 'ends_at'], 'calendar_events_owner_instants_idx');
            $table->index(['user_id', 'start_date', 'end_date'], 'calendar_events_owner_dates_idx');
        });

        Schema::create('synced_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->enum('origin', ['selfhandler', 'provider']);
            $table->enum('local_type', ['time_block', 'planned_occurrence', 'external_event']);
            $table->unsignedBigInteger('local_id');
            $table->text('external_id');
            $table->char('external_id_hash', 64);
            $table->string('external_etag', 512)->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->char('local_fingerprint', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_id_hash'], 'synced_items_integration_external_uq');
            $table->unique(
                ['integration_id', 'local_type', 'local_id'],
                'synced_items_integration_local_uq',
            );
            $table->index(['user_id', 'local_type', 'local_id'], 'synced_items_owner_local_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_items');
        Schema::dropIfExists('external_calendar_events');
        Schema::dropIfExists('integrations');
    }
};
