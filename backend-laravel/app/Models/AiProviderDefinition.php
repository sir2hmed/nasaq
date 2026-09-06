<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiProviderDefinition extends Model { protected $guarded=[]; protected $casts=['capabilities'=>'array','models'=>'array','connection_test_supported'=>'boolean']; }
