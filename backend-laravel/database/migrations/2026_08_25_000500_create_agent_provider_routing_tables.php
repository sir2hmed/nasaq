<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key')->unique();
            $table->string('provider_name');
            $table->json('capabilities');
            $table->json('models');
            $table->boolean('connection_test_supported')->default(false);
            $table->timestamps();
        });
        Schema::create('ai_provider_categories', function (Blueprint $table) {
            $table->id(); $table->string('category_key')->unique(); $table->string('display_name');
            $table->string('display_name_ar'); $table->text('purpose'); $table->timestamps();
        });
        Schema::create('ai_agent_provider_configurations', function (Blueprint $table) {
            $table->id(); $table->foreignId('ai_provider_definition_id')->constrained()->cascadeOnDelete();
            $table->string('model_key'); $table->text('encrypted_credentials')->nullable(); $table->json('configuration_json')->nullable();
            $table->string('status')->default('draft'); $table->timestamp('verified_at')->nullable(); $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable(); $table->text('last_error_message_sanitized')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
            $table->index(['status', 'last_test_status']);
        });
        Schema::create('ai_provider_category_routes', function (Blueprint $table) {
            $table->id(); $table->foreignId('ai_provider_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_provider_configuration_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(0); $table->boolean('is_primary')->default(false); $table->boolean('is_active')->default(false);
            $table->timestamps(); $table->unique(['ai_provider_category_id', 'ai_agent_provider_configuration_id'], 'ai_provider_route_unique');
            $table->index(['ai_provider_category_id', 'is_active', 'priority']);
        });
        Schema::create('ai_provider_connection_tests', function (Blueprint $table) {
            $table->id(); $table->foreignId('ai_agent_provider_configuration_id')->constrained()->cascadeOnDelete();
            $table->string('status'); $table->string('capability')->nullable(); $table->text('message_sanitized')->nullable(); $table->timestamp('tested_at'); $table->timestamps();
        });
        Schema::create('ai_provider_routing_audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ai_provider_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_agent_provider_configuration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event'); $table->json('metadata')->nullable(); $table->timestamps(); $table->index(['event', 'created_at']);
        });
        $now = now();
        $categories = [
            ['researcher','Researcher Agent','وكيل البحث','Research, source analysis, and structured research output.'], ['writer','Writer Agent','وكيل الكتابة','Long-form writing, scripts, and bilingual content.'],
            ['image','Image Generation Agent','وكيل توليد الصور','Generate and edit images and social visuals.'], ['video','Video Generation Agent','وكيل الفيديو','Generate video content and video-related outputs.'],
            ['publisher','Publisher Agent','وكيل النشر','Social captions and publishing preparation.'], ['email','Email Agent','وكيل البريد الإلكتروني','Professional email and campaign content.'],
            ['planner','Workflow & Planning Agent','وكيل التخطيط','Workflow planning and orchestration.'], ['general_chat','General Assistant','المساعد العام','General chat and fallback AI tasks.'],
        ];
        foreach ($categories as [$key, $name, $ar, $purpose]) DB::table('ai_provider_categories')->insert(['category_key'=>$key,'display_name'=>$name,'display_name_ar'=>$ar,'purpose'=>$purpose,'created_at'=>$now,'updated_at'=>$now]);
        $definitions = [
            ['openai','OpenAI',['text','research','writing','image','video','email','planning','general_chat'],['gpt-4o','gpt-4o-mini','gpt-image-1'],true], ['gemini','Google Gemini',['text','research','writing','image','video','email','planning','general_chat'],['gemini-2.0-flash','gemini-1.5-flash','gemini-1.5-pro'],true],
            ['anthropic','Anthropic Claude',['text','research','writing','email','planning','general_chat'],['claude-3-5-sonnet-latest'],false], ['groq','Groq',['text','research','writing','email','planning','general_chat'],['llama-3.3-70b-versatile'],false], ['openrouter','OpenRouter',['text','research','writing','image','email','planning','general_chat'],['openai/gpt-4o-mini'],false],
            ['mistral','Mistral AI',['text','research','writing','email','planning','general_chat'],['mistral-large-latest'],false], ['together','Together AI',['text','research','writing','image','email','planning','general_chat'],['meta-llama/Llama-3.3-70B-Instruct-Turbo'],false], ['huggingface','Hugging Face',['text','research','writing','image','video'],['meta-llama/Llama-3.3-70B-Instruct'],false],
            ['cohere','Cohere',['text','research','writing','email','planning','general_chat'],['command-r-plus'],false], ['deepseek','DeepSeek',['text','research','writing','email','planning','general_chat'],['deepseek-chat'],false], ['xai','xAI Grok',['text','research','writing','email','planning','general_chat'],['grok-2-latest'],false],
            ['stability','Stability AI',['image'],['stable-image-core'],false], ['replicate','Replicate',['image','video'],['black-forest-labs/flux-1.1-pro'],false], ['runway','Runway',['video'],['gen-3-alpha-turbo'],false],
        ];
        foreach ($definitions as [$key,$name,$capabilities,$models,$testable]) DB::table('ai_provider_definitions')->insert(['provider_key'=>$key,'provider_name'=>$name,'capabilities'=>json_encode($capabilities),'models'=>json_encode($models),'connection_test_supported'=>$testable,'created_at'=>$now,'updated_at'=>$now]);
    }
    public function down(): void { Schema::dropIfExists('ai_provider_routing_audit_logs'); Schema::dropIfExists('ai_provider_connection_tests'); Schema::dropIfExists('ai_provider_category_routes'); Schema::dropIfExists('ai_agent_provider_configurations'); Schema::dropIfExists('ai_provider_categories'); Schema::dropIfExists('ai_provider_definitions'); }
};
