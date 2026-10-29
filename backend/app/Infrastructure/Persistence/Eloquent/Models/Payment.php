<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

    protected $table = 'payments';

    protected $guarded = [];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    protected function casts(): array
    {
        return ['credit_units' => 'integer', 'amount_minor' => 'integer', 'fx_ngn_per_usd_micros' => 'integer', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'invoice_notified_at' => 'immutable_datetime', 'provider_fee_minor' => 'integer'];
    }
}
