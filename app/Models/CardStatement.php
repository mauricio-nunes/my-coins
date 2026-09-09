<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardStatement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'closing_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CardInstallment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CardStatementPayment::class);
    }
}
