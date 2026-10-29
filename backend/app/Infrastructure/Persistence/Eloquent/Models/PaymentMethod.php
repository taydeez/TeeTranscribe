<?php

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(PaymentMethodFactory::class)]
class PaymentMethod extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'code', 'description', 'is_active', 'sort_order', 'public_config'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'public_config' => 'array'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
