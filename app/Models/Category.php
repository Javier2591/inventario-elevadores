<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * Campos permitidos para guardarse mediante asignación masiva.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    /**
     * Convierte is_active automáticamente a verdadero o falso.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Refacciones que pertenecen a esta categoría.
     */
    public function spareParts(): HasMany
    {
        return $this->hasMany(SparePart::class);
    }

    /**
     * Consulta solamente las categorías activas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}