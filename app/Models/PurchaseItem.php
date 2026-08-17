<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'purchase_id',
        'spare_part_id',
        'quantity',
        'unit_cost',
        'subtotal',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'spare_part_id' => 'integer',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * Compra a la que pertenece la partida.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Refacción incluida en la partida.
     */
    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(SparePart::class);
    }
}