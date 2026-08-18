<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_CASH = 'cash';
    public const PAYMENT_CARD = 'card';
    public const PAYMENT_TRANSFER = 'transfer';
    public const PAYMENT_CREDIT = 'credit';
    public const PAYMENT_OTHER = 'other';

    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'folio',
        'customer_id',
        'user_id',
        'sale_date',
        'subtotal',
        'tax_rate',
        'tax',
        'total',
        'payment_method',
        'payment_reference',
        'status',
        'cancelled_by',
        'cancelled_at',
        'notes',
    ];

    /**
     * Conversiones automáticas.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Cliente relacionado con la venta.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Usuario que registró la venta.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuario que canceló la venta.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Partidas incluidas en la venta.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Movimientos de inventario generados por la venta.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Filtra ventas pendientes.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Filtra ventas completadas.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Filtra ventas canceladas.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Busca por folio, cliente o usuario.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $term = trim((string) $search);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query
                ->where('folio', 'like', "%{$term}%")
                ->orWhereHas(
                    'customer',
                    fn (Builder $customerQuery): Builder =>
                        $customerQuery->where('name', 'like', "%{$term}%")
                )
                ->orWhereHas(
                    'user',
                    fn (Builder $userQuery): Builder =>
                        $userQuery->where('name', 'like', "%{$term}%")
                );
        });
    }

    /**
     * Determina si la venta está completada.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Determina si la venta puede cancelarse.
     */
    public function canBeCancelled(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Etiqueta legible del estado.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_COMPLETED => 'Completada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => 'Desconocido',
        };
    }

    /**
     * Etiqueta legible del método de pago.
     */
    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::PAYMENT_CASH => 'Efectivo',
            self::PAYMENT_CARD => 'Tarjeta',
            self::PAYMENT_TRANSFER => 'Transferencia',
            self::PAYMENT_CREDIT => 'Crédito',
            self::PAYMENT_OTHER => 'Otro',
            default => 'No especificado',
        };
    }
}
