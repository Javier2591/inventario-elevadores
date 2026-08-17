<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    // Tipos de servicio.
    public const TYPE_PREVENTIVE = 'preventive';
    public const TYPE_CORRECTIVE = 'corrective';
    public const TYPE_INSPECTION = 'inspection';
    public const TYPE_INSTALLATION = 'installation';

    // Prioridades.
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    // Estados.
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Campos permitidos para asignación masiva.
     */
    protected $fillable = [
        'folio',
        'elevator_id',
        'created_by',
        'technician_id',
        'service_type',
        'priority',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'description',
        'diagnosis',
        'work_performed',
        'notes',
        'labor_cost',
        'parts_cost',
        'total_cost',
    ];

    /**
     * Conversión automática de tipos.
     */
    protected function casts(): array
    {
        return [
            'elevator_id' => 'integer',
            'created_by' => 'integer',
            'technician_id' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'labor_cost' => 'decimal:2',
            'parts_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    /**
     * Elevador atendido.
     */
    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    /**
     * Usuario que creó la orden.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    /**
     * Técnico asignado.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'technician_id'
        );
    }

    /**
     * Refacciones incluidas en la orden.
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            ServiceOrderItem::class
        );
    }

    /**
     * Salidas de inventario generadas por la orden.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(
            InventoryMovement::class
        );
    }

    /**
     * Consulta órdenes que aún requieren atención.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SCHEDULED,
            self::STATUS_IN_PROGRESS,
        ]);
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Nombre del tipo de servicio en español.
     */
    public function serviceTypeLabel(): string
    {
        return match ($this->service_type) {
            self::TYPE_PREVENTIVE => 'Preventivo',
            self::TYPE_CORRECTIVE => 'Correctivo',
            self::TYPE_INSPECTION => 'Inspección',
            self::TYPE_INSTALLATION => 'Instalación',
            default => 'Desconocido',
        };
    }

    /**
     * Nombre de la prioridad en español.
     */
    public function priorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_LOW => 'Baja',
            self::PRIORITY_MEDIUM => 'Media',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_URGENT => 'Urgente',
            default => 'Desconocida',
        };
    }

    /**
     * Nombre del estado en español.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SCHEDULED => 'Programada',
            self::STATUS_IN_PROGRESS => 'En proceso',
            self::STATUS_COMPLETED => 'Completada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => 'Desconocido',
        };
    }
}