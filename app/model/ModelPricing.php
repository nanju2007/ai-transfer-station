<?php

namespace app\model;

use support\Model;

class ModelPricing extends Model
{
    protected $table = 'model_pricing';

    protected $fillable = [
        'model_id', 'billing_type', 'input_price', 'output_price',
        'per_request_price', 'min_charge', 'cache_input_ratio',
        'cache_enabled', 'cache_creation_price', 'cache_read_price',
        'currency', 'status',
    ];

    protected $casts = [
        'model_id' => 'integer',
        'billing_type' => 'integer',
        'input_price' => 'decimal:6',
        'output_price' => 'decimal:6',
        'per_request_price' => 'decimal:6',
        'min_charge' => 'decimal:6',
        'cache_input_ratio' => 'decimal:4',
        'cache_enabled' => 'integer',
        'cache_creation_price' => 'decimal:4',
        'cache_read_price' => 'decimal:4',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function model()
    {
        return $this->belongsTo(Model_::class, 'model_id');
    }
}
