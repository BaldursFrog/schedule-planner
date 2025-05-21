<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;  
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanGenerationJob extends Model
{
    use HasFactory, HasUuids;  

 
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',  
        'user_id',
        'goal',
        'group_id',
        'status',
        'result',
    ];

   
    protected $casts = [
        'result' => 'array',  
    ];
}
