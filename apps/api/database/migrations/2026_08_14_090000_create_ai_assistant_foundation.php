<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->enum('provider', ['anthropic', 'openai']);
            $table->string('model', 160);
            $table->text('api_key');
            $table->char('key_hint', 4);
            $table->json('parameters');
            $table->enum('status', ['untested', 'ready', 'invalid'])->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'llm_connections_owner_name_uq');
            $table->index(['user_id', 'status'], 'llm_connections_owner_status_idx');
        });

        Schema::create('llm_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->foreignId('active_connection_id')->nullable()
                ->constrained('llm_connections')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('llm_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['storage_inbox']);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'scope'], 'llm_consents_owner_scope_uq');
        });

        Schema::create('llm_tool_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('llm_connection_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('proposal_hash', 64);
            $table->string('tool_name', 80);
            $table->enum('source_type', ['item']);
            $table->unsignedBigInteger('source_id');
            $table->char('source_fingerprint', 64);
            $table->enum('status', ['pending', 'applied', 'rejected'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at'], 'llm_confirmations_owner_pending_idx');
            $table->index(['user_id', 'source_type', 'source_id'], 'llm_confirmations_owner_source_idx');
        });

        Schema::create('llm_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('llm_connection_id')->nullable()
                ->constrained('llm_connections')->nullOnDelete();
            $table->string('event', 48);
            $table->enum('scope', ['storage_inbox'])->nullable();
            $table->enum('outcome', ['succeeded', 'rejected']);
            $table->string('error_code', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at'], 'llm_audit_events_owner_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_audit_events');
        Schema::dropIfExists('llm_tool_confirmations');
        Schema::dropIfExists('llm_consents');
        Schema::dropIfExists('llm_settings');
        Schema::dropIfExists('llm_connections');
    }
};
