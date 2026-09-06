<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Facades\Crypt;
class AiAgentProviderConfiguration extends Model { protected $guarded=[]; protected $casts=['configuration_json'=>'array','verified_at'=>'datetime','last_tested_at'=>'datetime'];
 public function definition(){ return $this->belongsTo(AiProviderDefinition::class,'ai_provider_definition_id'); }
 public function category(){ return $this->belongsTo(AiProviderCategory::class,'ai_provider_category_id'); }
 public function setCredentials(string $key): void { $this->encrypted_credentials=Crypt::encryptString(json_encode(['api_key'=>$key])); }
 public function credential(): ?string { try { $value=json_decode(Crypt::decryptString((string)$this->encrypted_credentials),true); return is_array($value)&&is_string($value['api_key']??null)?$value['api_key']:null; } catch (\Throwable) { return null; } }
 public function maskedCredential(): string { $key=$this->credential(); return $key && strlen($key)>8 ? substr($key,0,4).'****'.substr($key,-4) : ''; }
 }
