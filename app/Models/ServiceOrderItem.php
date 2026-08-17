<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderItem extends Model
{
    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'service_order_id',
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
            'service_order_id' => 'integer',
            'spare_part_id' => 'integer',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    /**
     * Orden de servicio a la que pertenece la partida.
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(
            ServiceOrder::class
        );
    }

    /**
     * Refacción incluida en la orden.
     */
    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(
            SparePart::class
        );
    }
}