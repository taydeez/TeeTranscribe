<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UsageCharge extends Model
{
    use HasUlids;

    protected $table = 'usage_charges';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'credit_units' => 'integer', 'rate' => 'array', 'provider_cost_micros' => 'integer', 'provider_quantity' => 'integer'];
    }
}
