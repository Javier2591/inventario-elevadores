<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /*
    |--------------------------------------------------------------------------
    | Roles disponibles
    |--------------------------------------------------------------------------
    */

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TECHNICIAN = 'technician';

    /*
    |--------------------------------------------------------------------------
    | Asignación masiva
    |--------------------------------------------------------------------------
    */

    /**
     * Atributos que pueden asignarse mediante create() y update().
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'is_active',
        'password',
    ];

    /*
    |--------------------------------------------------------------------------
    | Atributos ocultos
    |--------------------------------------------------------------------------
    */

    /**
     * Información que no debe mostrarse al convertir
     * el usuario en arreglo o JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /*
    |--------------------------------------------------------------------------
    | Conversión de atributos
    |--------------------------------------------------------------------------
    */

    /**
     * Define las conversiones automáticas del modelo.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    /**
     * Compras registradas por el usuario.
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(
            Purchase::class,
            'user_id'
        );
    }

    /**
     * Movimientos de inventario registrados por el usuario.
     */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(
            InventoryMovement::class,
            'user_id'
        );
    }

    /**
     * Órdenes asignadas al usuario cuando trabaja como técnico.
     *
     * Esta relación puede utilizarse mediante:
     *
     * $user->serviceOrders
     */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(
            ServiceOrder::class,
            'technician_id'
        );
    }

    /**
     * Alias descriptivo para las órdenes asignadas.
     *
     * El componente de administración de usuarios utiliza:
     *
     * withCount('serviceOrdersAssigned')
     *
     * Laravel generará el atributo:
     *
     * service_orders_assigned_count
     */
    public function serviceOrdersAssigned(): HasMany
    {
        return $this->hasMany(
            ServiceOrder::class,
            'technician_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Comprobaciones de roles y acceso
    |--------------------------------------------------------------------------
    */

    /**
     * Determina si el usuario es administrador.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Determina si el usuario es técnico.
     */
    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    /**
     * Devuelve el nombre legible del rol.
     */
    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => 'Administrador',
            self::ROLE_TECHNICIAN => 'Técnico',
            default => 'Sin función',
        };
    }

    /**
     * Determina si la cuenta está activa.
     *
     * La conversión explícita garantiza que siempre
     * se devuelva un valor booleano.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Determina si el usuario puede ingresar al sistema.
     */
    public function canAccessSystem(): bool
    {
        return (bool) $this->is_active;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Obtiene solamente usuarios activos.
     *
     * Ejemplo:
     *
     * User::active()->get();
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(
            'is_active',
            true
        );
    }

    /**
     * Obtiene solamente usuarios administradores.
     *
     * Ejemplo:
     *
     * User::admins()->get();
     */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where(
            'role',
            self::ROLE_ADMIN
        );
    }

    /**
     * Obtiene solamente usuarios técnicos.
     *
     * Ejemplo:
     *
     * User::technicians()->get();
     */
    public function scopeTechnicians(Builder $query): Builder
    {
        return $query->where(
            'role',
            self::ROLE_TECHNICIAN
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Presentación
    |--------------------------------------------------------------------------
    */

    /**
     * Genera las iniciales del usuario para mostrarlas
     * en el avatar de la aplicación.
     *
     * Ejemplos:
     *
     * "Mario Rodríguez" se convierte en "MR".
     * "Javier" se convierte en "J".
     */
    public function initials(): string
    {
        $words = preg_split(
            '/\s+/u',
            trim((string) $this->name)
        );

        if (! is_array($words)) {
            return 'U';
        }

        $words = array_values(
            array_filter(
                $words,
                fn (string $word): bool =>
                    $word !== ''
            )
        );

        if ($words === []) {
            return 'U';
        }

        $initials = collect($words)
            ->take(2)
            ->map(
                fn (string $word): string =>
                    mb_strtoupper(
                        mb_substr(
                            $word,
                            0,
                            1
                        )
                    )
            )
            ->implode('');

        return $initials !== ''
            ? $initials
            : 'U';
    }
}