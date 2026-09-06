<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_provider_definitions')->where('provider_key', 'gemini')->update([
            'capabilities' => json_encode(['text', 'research', 'writing', 'email', 'planning', 'general_chat']),
            'models' => json_encode(['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-2.5-flash']),
            'connection_test_supported' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('ai_provider_definitions')->where('provider_key', 'gemini')->update([
            'capabilities' => json_encode(['text', 'research', 'writing', 'image', 'video', 'email', 'planning', 'general_chat']),
            'models' => json_encode(['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro']),
            'updated_at' => now(),
        ]);
    }
};
