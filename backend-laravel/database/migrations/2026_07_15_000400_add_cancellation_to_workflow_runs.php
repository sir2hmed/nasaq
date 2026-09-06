<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->timestampTz('cancellation_requested_at')->nullable()->after('task_id');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('cancellation_requested_at');
        });
    }
};
