<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const TYPE_ENTRY = 'entry';
    public const TYPE_EXIT = 'exit';

    protected $fillable = [
        'spare_part_id',
        'user_id',
        'purchase_id',
        'service_order_id',
        'type',
        'quantity',
        'previous_stock',
        'new_stock',
        'reason',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'spare_part_id' => 'integer',
            'user_id' => 'integer',
            'purchase_id' => 'integer',
            'service_order_id' => 'integer',
            'quantity' => 'integer',
            'previous_stock' => 'integer',
            'new_stock' => 'integer',
        ];
    }

    /**
     * Refacción afectada.
     */
    public function sparePart(): BelongsTo
    {
        return $this->belongsTo(SparePart::class);
    }

    /**
     * Usuario responsable.
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
     * Orden que originó una salida.
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * Consulta solamente entradas.
     */
    public function scopeEntries(Builder $query): Builder
    {
        return $query->where(
            'type',
            self::TYPE_ENTRY
        );
    }

    /**
     * Consulta solamente salidas.
     */
    public function scopeExits(Builder $query): Builder
    {
        return $query->where(
            'type',
            self::TYPE_EXIT
        );
    }

    public function isEntry(): bool
    {
        return $this->type === self::TYPE_ENTRY;
    }

    public function isExit(): bool
    {
        return $this->type === self::TYPE_EXIT;
    }
}