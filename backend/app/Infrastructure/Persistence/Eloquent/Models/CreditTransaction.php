<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    use HasUlids;

    protected $table = 'credit_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_units' => 'integer', 'available_after' => 'integer', 'reserved_after' => 'integer', 'metadata' => 'array'];
    }
}
