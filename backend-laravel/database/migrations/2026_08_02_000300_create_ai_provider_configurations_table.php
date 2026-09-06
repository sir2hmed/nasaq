<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->foreignId('platform_integration_id')->nullable()->constrained('platform_integrations')->nullOnDelete();
            $table->string('default_model');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('connection_status')->default('unconfigured');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configurations');
    }
};
