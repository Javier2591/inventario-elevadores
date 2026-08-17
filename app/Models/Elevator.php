<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Elevator extends Model
{
    /*
     * Estados permitidos para un elevador.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'code',
        'client_name',
        'building_name',
        'address',
        'brand',
        'model',
        'serial_number',
        'stops',
        'capacity_kg',
        'installation_date',
        'last_service_date',
        'status',
        'notes',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected function casts(): array
    {
        return [
            'stops' => 'integer',
            'capacity_kg' => 'integer',
            'installation_date' => 'date',
            'last_service_date' => 'date',
        ];
    }

    /**
     * Órdenes de servicio registradas para el elevador.
     */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /**
     * Busca elevadores por sus datos principales.
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
                    ->where('code', 'like', $term)
                    ->orWhere('client_name', 'like', $term)
                    ->orWhere('building_name', 'like', $term)
                    ->orWhere('address', 'like', $term)
                    ->orWhere('brand', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('serial_number', 'like', $term);
            }
        );
    }

    /**
     * Consulta solamente los elevadores activos.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'status',
            self::STATUS_ACTIVE
        );
    }

    /**
     * Consulta los elevadores que pueden recibir una orden.
     *
     * Incluye equipos activos y temporalmente fuera de servicio,
     * pero excluye los equipos inactivos.
     */
    public function scopeAvailableForService(
        Builder $query
    ): Builder {
        return $query->whereIn('status', [
            self::STATUS_ACTIVE,
            self::STATUS_OUT_OF_SERVICE,
        ]);
    }

    /**
     * Indica si el elevador está activo.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Indica si está temporalmente fuera de servicio.
     */
    public function isOutOfService(): bool
    {
        return $this->status === self::STATUS_OUT_OF_SERVICE;
    }

    /**
     * Indica si el elevador está inactivo.
     */
    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }

    /**
     * Obtiene el nombre del estado en español.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Activo',
            self::STATUS_OUT_OF_SERVICE => 'Fuera de servicio',
            self::STATUS_INACTIVE => 'Inactivo',
            default => 'Desconocido',
        };
    }
}