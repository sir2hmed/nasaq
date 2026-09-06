<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('node_key', 64);
            $table->string('status', 16)->default('pending');
            $table->foreignId('preview_output_id')->nullable()->constrained('agent_outputs')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz();

            $table->unique(['workflow_run_id', 'node_key']);
            $table->index(['workflow_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
