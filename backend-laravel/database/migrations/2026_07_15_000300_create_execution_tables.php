<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('workflow_snapshot');
            $table->string('status', 32)->default('queued');
            $table->string('current_node_key', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->text('error_summary')->nullable();
            $table->boolean('demo_mode')->default(true);
            $table->uuid('correlation_id');
            $table->uuid('task_id')->nullable();
            $table->uuid('idempotency_key');
            $table->timestampsTz();

            $table->unique(['user_id', 'workflow_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index(['workflow_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('execution_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->string('node_key', 64)->nullable();
            $table->string('level', 16);
            $table->string('event_type', 40);
            $table->text('message');
            $table->jsonb('context_json')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['workflow_run_id', 'occurred_at']);
            $table->index(['workflow_run_id', 'node_key']);
        });

        Schema::create('agent_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('node_key', 64);
            $table->string('agent_type', 64);
            $table->string('output_type', 40);
            $table->jsonb('content_json')->nullable();
            $table->longText('text_content')->nullable();
            $table->string('file_path', 1000)->nullable();
            $table->string('public_url', 2000)->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestampsTz();

            $table->index(['workflow_run_id', 'created_at']);
            $table->index(['workflow_run_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_outputs');
        Schema::dropIfExists('execution_logs');
        Schema::dropIfExists('workflow_runs');
    }
};
