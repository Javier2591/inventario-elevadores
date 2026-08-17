<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'supplier_id',
        'user_id',
        'folio',
        'purchase_date',
        'subtotal',
        'tax',
        'total',
        'status',
        'notes',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'user_id' => 'integer',
            'purchase_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Proveedor relacionado con la compra.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Usuario que registró la compra.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Refacciones incluidas en la compra.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Entradas de inventario generadas por la compra.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Consulta solamente las compras recibidas.
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_RECEIVED
        );
    }

    /**
     * Indica si la compra está recibida.
     */
    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /**
     * Indica si la compra está cancelada.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}