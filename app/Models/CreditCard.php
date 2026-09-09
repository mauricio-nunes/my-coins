<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_limit' => 'integer', 'closing_day' => 'integer', 'due_day' => 'integer', 'archived' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function defaultPaymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_payment_account_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(CardPurchase::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(CardStatement::class);
    }
}
