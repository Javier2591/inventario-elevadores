<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SparePart extends Model
{
    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'description',
        'brand',
        'stock',
        'minimum_stock',
        'location',
        'cost',
        'is_active',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'stock' => 'integer',
            'minimum_stock' => 'integer',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Categoría a la que pertenece la refacción.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Entradas y salidas registradas en el Kárdex.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(
            InventoryMovement::class
        );
    }

    /**
     * Compras donde aparece la refacción.
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(
            PurchaseItem::class
        );
    }

    /**
     * Órdenes de servicio donde se utilizó la refacción.
     */
    public function serviceOrderItems(): HasMany
    {
        return $this->hasMany(
            ServiceOrderItem::class
        );
    }

    /**
     * Consulta solamente las refacciones activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'is_active',
            true
        );
    }

    /**
     * Consulta refacciones con existencia baja.
     *
     * Una existencia se considera baja cuando es menor
     * o igual al mínimo configurado.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('minimum_stock', '>', 0)
            ->whereColumn(
                'stock',
                '<=',
                'minimum_stock'
            );
    }

    /**
     * Busca una refacción por SKU, nombre, marca,
     * descripción o ubicación.
     */
    public function scopeSearch(
        Builder $query,
        string $search
    ): Builder {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $term = '%' . $search . '%';

        return $query->where(
            function (Builder $query) use ($term) {
                $query
                    ->where('sku', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('location', 'like', $term);
            }
        );
    }

    /**
     * Indica si la refacción tiene existencia baja.
     */
    public function hasLowStock(): bool
    {
        return $this->minimum_stock > 0
            && $this->stock <= $this->minimum_stock;
    }

    /**
     * Indica si existe inventario suficiente
     * para retirar una cantidad determinada.
     */
    public function hasEnoughStock(int $quantity): bool
    {
        return $quantity > 0
            && $this->stock >= $quantity;
    }
}