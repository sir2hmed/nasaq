<?php

namespace App\Http\Controllers;

use App\Models\AiAgentProviderConfiguration;
use App\Models\AiProviderCategory;
use App\Models\AiProviderCategoryRoute;
use App\Models\AiProviderDefinition;
use App\Services\ProviderProbeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AiProviderRoutingController extends Controller
{
    public function index(): JsonResponse
    {
        $definitions = AiProviderDefinition::query()->orderBy('provider_name')->get();
        $routes = AiProviderCategoryRoute::query()->with('configuration.definition')->get()->groupBy('ai_provider_category_id');
        $categories = AiProviderCategory::query()->orderBy('id')->get()->map(function ($category) use ($routes) {
            $items = ($routes[$category->id] ?? collect())->sortBy('priority')->map(fn ($route) => $this->routeData($route))->values();
            return ['id'=>$category->id,'key'=>$category->category_key,'name'=>$category->display_name,'name_ar'=>$category->display_name_ar,'purpose'=>$category->purpose,'routes'=>$items,'primary'=>$items->firstWhere('is_primary', true),'fallback_count'=>$items->where('is_primary', false)->count()];
        });
        $configured = AiAgentProviderConfiguration::query()->with(['definition', 'category'])->latest()->get()->map(fn ($configuration) => $this->configurationData($configuration));
        $activity = DB::table('ai_provider_routing_audit_logs as log')->leftJoin('users as user', 'user.id', '=', 'log.admin_user_id')->leftJoin('ai_provider_categories as category', 'category.id', '=', 'log.ai_provider_category_id')->select(['log.event', 'log.metadata', 'log.created_at', 'user.name as admin_name', 'category.display_name as category_name'])->latest('log.id')->limit(50)->get()->map(fn ($event) => ['event'=>$event->event, 'admin_name'=>$event->admin_name, 'category_name'=>$event->category_name, 'metadata'=>json_decode($event->metadata ?? '[]', true), 'created_at'=>\Illuminate\Support\Carbon::parse($event->created_at)->toISOString()]);
        return response()->json(['data'=>['categories'=>$categories,'providers'=>$definitions,'configurations'=>$configured,'activity'=>$activity,'health_checked_at'=>now()->toISOString()],'message'=>'AI provider routing retrieved.','errors'=>null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['category_key'=>['required','string',Rule::exists('ai_provider_categories','category_key')], 'provider_key'=>['required','string',Rule::exists('ai_provider_definitions','provider_key')], 'model_key'=>['required','string','max:150'], 'api_key'=>['required','string','min:6','max:1000'], 'base_url'=>['nullable','url','max:255']]);
        $definition = AiProviderDefinition::where('provider_key',$data['provider_key'])->firstOrFail();
        abort_if($definition->provider_key === 'gemini' && $request->filled('base_url'), 422, 'Gemini uses the official API endpoint and accepts only a Gemini API key.');
        $category = AiProviderCategory::where('category_key',$data['category_key'])->firstOrFail();
        abort_unless(in_array($category->category_key, $definition->capabilities, true) || in_array('text', $definition->capabilities, true) && in_array($category->category_key,['writer','email','planner','general_chat','researcher','publisher'],true), 422, 'This provider does not support the selected agent category.');
        abort_unless(in_array($data['model_key'], $definition->models, true), 422, 'The selected model is not available for this provider.');
        $configuration = new AiAgentProviderConfiguration(['ai_provider_definition_id'=>$definition->id,'ai_provider_category_id'=>$category->id,'model_key'=>$data['model_key'],'configuration_json'=>$definition->provider_key === 'gemini' ? [] : array_filter(['base_url'=>$data['base_url'] ?? null]),'status'=>'draft','created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]);
        $configuration->setCredentials($data['api_key']); $configuration->save();
        $this->audit($request, 'configuration.created', $category, $configuration, ['provider'=>$definition->provider_key,'model'=>$configuration->model_key]);
        return response()->json(['data'=>['configuration'=>$this->configurationData($configuration->load('definition'))],'message'=>'Provider configuration saved as a draft. Test it before activation.','errors'=>null],201);
    }

    public function test(Request $request, AiAgentProviderConfiguration $configuration, ProviderProbeClient $probe): JsonResponse
    {
        $configuration->load(['definition', 'category']);
        $definition=$configuration->definition; $result=['success'=>false,'status'=>'unsupported','message'=>'This provider requires an official execution adapter before it can be verified.'];
        $configuration->update(['status' => 'testing', 'last_error_message_sanitized' => null, 'updated_by' => $request->user()->id]);
        if ($definition->connection_test_supported && in_array($definition->provider_key,['openai','gemini'],true)) $result=$probe->probe($definition->provider_key,$configuration->model_key,['api_key'=>$configuration->credential()],data_get($configuration->configuration_json,'base_url'));
        $status=$result['success']?'verified':'failed';
        $configuration->update(['status'=>$status,'verified_at'=>$result['success']?now():null,'last_tested_at'=>now(),'last_test_status'=>$result['status'],'last_error_message_sanitized'=>$result['success']?null:$result['message'],'updated_by'=>$request->user()->id]);
        DB::table('ai_provider_connection_tests')->insert(['ai_agent_provider_configuration_id'=>$configuration->id,'status'=>$result['status'],'capability'=>null,'message_sanitized'=>$result['message'],'tested_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $this->audit($request,$result['success']?'configuration.test_passed':'configuration.test_failed',null,$configuration,['status'=>$result['status']]);
        return response()->json(['data'=>['configuration'=>$this->configurationData($configuration->fresh(['definition', 'category'])),'success'=>$result['success']],'message'=>$result['message'],'errors'=>null]);
    }

    public function activate(Request $request, AiAgentProviderConfiguration $configuration): JsonResponse
    {
        $data=$request->validate(['category_key'=>['required','string',Rule::exists('ai_provider_categories','category_key')],'as_fallback'=>['sometimes','boolean']]);
        abort_unless($configuration->status==='verified',422,'Only a verified provider configuration can be activated.');
        $category=AiProviderCategory::where('category_key',$data['category_key'])->firstOrFail(); $asFallback=(bool)($data['as_fallback']??false);
        abort_unless($configuration->ai_provider_category_id === $category->id, 422, 'This configuration belongs to a different agent category.');
        $route=DB::transaction(function() use($category,$configuration,$asFallback){
            $existing=AiProviderCategoryRoute::query()->where('ai_provider_category_id',$category->id)->lockForUpdate()->get();
            if (!$asFallback) AiProviderCategoryRoute::query()->where('ai_provider_category_id',$category->id)->where('is_primary',true)->update(['is_primary'=>false,'is_active'=>false]);
            $priority=((int) $existing->max('priority'))+1;
            return AiProviderCategoryRoute::updateOrCreate(['ai_provider_category_id'=>$category->id,'ai_agent_provider_configuration_id'=>$configuration->id],['is_primary'=>!$asFallback,'is_active'=>true,'priority'=>$asFallback?$priority:0]);
        });
        $this->audit($request,$asFallback?'route.fallback_activated':'route.primary_activated',$category,$configuration,['priority'=>$route->priority]);
        return response()->json(['data'=>['route'=>$this->routeData($route->fresh('configuration.definition'))],'message'=>$asFallback?'Verified fallback activated.':'Verified primary provider activated.','errors'=>null]);
    }

    public function reorder(Request $request, AiProviderCategory $category): JsonResponse
    {
        $data=$request->validate(['route_ids'=>['required','array'],'route_ids.*'=>['integer']]);
        DB::transaction(function() use($data,$category){ foreach($data['route_ids'] as $priority=>$id) AiProviderCategoryRoute::query()->where('id',$id)->where('ai_provider_category_id',$category->id)->where('is_primary',false)->lockForUpdate()->update(['priority'=>$priority+1]); });
        $this->audit($request,'route.priority_changed',$category,null,[]); return $this->index();
    }

    public function destroy(Request $request, AiAgentProviderConfiguration $configuration): JsonResponse
    {
        $this->audit($request,'configuration.deleted',null,$configuration,[]); $configuration->delete();
        return response()->json(['data'=>null,'message'=>'Provider configuration deleted.','errors'=>null]);
    }

    private function configurationData(AiAgentProviderConfiguration $c): array { return ['id'=>$c->id,'provider_key'=>$c->definition->provider_key,'provider_name'=>$c->definition->provider_name,'category_key'=>$c->category?->category_key,'category_name'=>$c->category?->display_name,'model_key'=>$c->model_key,'status'=>$c->status,'masked_credentials'=>$c->maskedCredential(),'verified_at'=>$c->verified_at?->toISOString(),'last_tested_at'=>$c->last_tested_at?->toISOString(),'last_test_status'=>$c->last_test_status,'message'=>$c->last_error_message_sanitized,'capabilities'=>$c->definition->capabilities]; }
    private function routeData(AiProviderCategoryRoute $r): array { return ['id'=>$r->id,'priority'=>$r->priority,'is_primary'=>$r->is_primary,'is_active'=>$r->is_active,'configuration'=>$this->configurationData($r->configuration)]; }
    private function audit(Request $request,string $event,?AiProviderCategory $category,?AiAgentProviderConfiguration $configuration,array $metadata): void { DB::table('ai_provider_routing_audit_logs')->insert(['admin_user_id'=>$request->user()->id,'ai_provider_category_id'=>$category?->id,'ai_agent_provider_configuration_id'=>$configuration?->id,'event'=>$event,'metadata'=>json_encode($metadata),'created_at'=>now(),'updated_at'=>now()]); }
}
