<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->jsonb('graph_json');
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('agent_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('node_key', 64);
            $table->string('agent_type', 64);
            $table->jsonb('configuration_json');
            $table->double('position_x');
            $table->double('position_y');
            $table->timestampsTz();

            $table->unique(['workflow_id', 'node_key']);
            $table->index(['workflow_id', 'agent_type']);
        });

        Schema::create('workflow_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('edge_key', 64);
            $table->string('source_node_key', 64);
            $table->string('target_node_key', 64);
            $table->timestampsTz();

            $table->unique(['workflow_id', 'edge_key']);
            $table->index(['workflow_id', 'source_node_key']);
            $table->index(['workflow_id', 'target_node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_edges');
        Schema::dropIfExists('agent_nodes');
        Schema::dropIfExists('workflows');
    }
};
