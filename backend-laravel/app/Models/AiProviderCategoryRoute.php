<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiProviderCategoryRoute extends Model { protected $guarded=[]; protected $casts=['is_primary'=>'boolean','is_active'=>'boolean']; public function category(){return $this->belongsTo(AiProviderCategory::class,'ai_provider_category_id');} public function configuration(){return $this->belongsTo(AiAgentProviderConfiguration::class,'ai_agent_provider_configuration_id')->with('definition');} }
