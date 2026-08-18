<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const TYPE_ENTRY = 'entry';

    public const TYPE_EXIT = 'exit';

    /**
     * Campos permitidos para asignación masiva.
     *
     * Es indispensable incluir sale_id para que create()
     * conserve la relación con la venta.
     */
    protected $fillable = [
        'spare_part_id',
        'user_id',
        'purchase_id',
        'service_order_id',
        'sale_id',
        'type',
        'quantity',
        'previous_stock',
        'new_stock',
        'reason',
        'reference',
        'notes',
    ];

    /**
     * Conversión automática de atributos.
     */
    protected function casts(): array
    {
        return [
            'spare_part_id' => 'integer',
            'user_id' => 'integer',
            'purchase_id' => 'integer',
            'service_order_id' => 'integer',
            'sale_id' => 'integer',
            'quantity' => 'integer',
            'previous_stock' => 'integer',
            'new_stock' => 'integer',
        ];
    }

    /**
     * Refacción afectada por el movimiento.
     */
    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(SparePart::class);
    }

    /**
     * Usuario responsable del movimiento.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Compra que originó una entrada.
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * Orden de servicio que originó una salida.
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * Venta que originó el movimiento.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Filtra solamente entradas de inventario.
     */
    public function scopeEntries(Builder $query): Builder
    {
        return $query->where(
            'type',
            self::TYPE_ENTRY
        );
    }

    /**
     * Filtra solamente salidas de inventario.
     */
    public function scopeExits(Builder $query): Builder
    {
        return $query->where(
            'type',
            self::TYPE_EXIT
        );
    }

    /**
     * Determina si el movimiento es una entrada.
     */
    public function isEntry(): bool
    {
        return $this->type === self::TYPE_ENTRY;
    }

    /**
     * Determina si el movimiento es una salida.
     */
    public function isExit(): bool
    {
        return $this->type === self::TYPE_EXIT;
    }
}