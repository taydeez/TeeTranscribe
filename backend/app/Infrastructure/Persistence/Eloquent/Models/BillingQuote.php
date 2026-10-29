<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BillingQuote extends Model
{
    use HasUlids;

    protected $table = 'billing_quotes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source' => 'array', 'request_source' => 'array', 'rate' => 'array', 'quantity' => 'integer', 'credit_units' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
