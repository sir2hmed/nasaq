<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_provider_configurations', function (Blueprint $table) {
            $table->foreignId('ai_provider_category_id')
                ->nullable()
                ->after('ai_provider_definition_id')
                ->constrained('ai_provider_categories')
                ->nullOnDelete();
            $table->index(['ai_provider_category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_provider_configurations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_provider_category_id');
        });
    }
};
