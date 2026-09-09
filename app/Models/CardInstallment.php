<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CardInstallment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['installment_number' => 'integer', 'amount' => 'integer'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CardPurchase::class, 'card_purchase_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CardStatement::class, 'card_statement_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }
}
