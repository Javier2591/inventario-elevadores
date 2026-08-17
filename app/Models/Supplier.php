<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'tax_id',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Compras realizadas al proveedor.
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Consulta solamente proveedores activos.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Busca proveedores por sus datos principales.
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

        return $query->where(function (Builder $query) use ($term) {
            $query
                ->where('name', 'like', $term)
                ->orWhere('tax_id', 'like', $term)
                ->orWhere('contact_name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term);
        });
    }
}