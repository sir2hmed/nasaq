<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('display_name');
            $table->text('encrypted_credentials')->nullable();
            $table->json('configuration_json')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('connection_status')->default('unconfigured');
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_test_message')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('integration_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_integration_id')->nullable()->constrained('platform_integrations')->nullOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->json('changed_fields')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_audit_logs');
        Schema::dropIfExists('platform_integrations');
    }
};
