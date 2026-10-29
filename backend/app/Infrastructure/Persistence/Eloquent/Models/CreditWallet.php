<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CreditWallet extends Model
{
    use HasUlids;

    protected $table = 'credit_wallets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'available_units' => 'integer', 'reserved_units' => 'integer'];
    }
}
