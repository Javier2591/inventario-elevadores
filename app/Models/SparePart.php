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
        'barcode',
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
     * Categoría de la refacción.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Movimientos de inventario de la refacción.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Partidas de compras donde aparece la refacción.
     */
    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * Partidas de ventas donde aparece la refacción.
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Órdenes de servicio donde se utilizó la refacción.
     */
    public function serviceOrderItems(): HasMany
    {
        return $this->hasMany(ServiceOrderItem::class);
    }

    /**
     * Filtra únicamente refacciones activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filtra refacciones con existencia baja.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->where('minimum_stock', '>', 0)
            ->whereColumn('stock', '<=', 'minimum_stock');
    }

    /**
     * Busca por código de barras, SKU, nombre, marca,
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
            function (Builder $query) use ($term): void {
                $query
                    ->where('barcode', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('location', 'like', $term);
            }
        );
    }

    /**
     * Localiza una refacción por código de barras o SKU.
     */
    public function scopeByIdentifier(
        Builder $query,
        string $identifier
    ): Builder {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return $query;
        }

        return $query->where(
            function (Builder $query) use ($identifier): void {
                $query
                    ->where('barcode', $identifier)
                    ->orWhere('sku', $identifier);
            }
        );
    }

    /**
     * Determina si la existencia está en el mínimo o debajo.
     */
    public function hasLowStock(): bool
    {
        return $this->minimum_stock > 0
            && $this->stock <= $this->minimum_stock;
    }

    /**
     * Comprueba si existe inventario suficiente.
     */
    public function hasEnoughStock(int $quantity): bool
    {
        return $quantity > 0
            && $this->stock >= $quantity;
    }
}