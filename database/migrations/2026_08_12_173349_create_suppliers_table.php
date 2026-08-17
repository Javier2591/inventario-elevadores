<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el catálogo de proveedores.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            // Identificador único del proveedor.
            $table->id();

            // Nombre comercial o razón social.
            $table->string('name', 150);

            /*
             * RFC del proveedor.
             * Es opcional, pero no puede repetirse cuando se registra.
             * Admite RFC de personas físicas y morales.
             */
            $table->string('tax_id', 13)
                ->nullable()
                ->unique();

            // Persona responsable o contacto de ventas.
            $table->string('contact_name', 150)
                ->nullable();

            // Medios de contacto.
            $table->string('phone', 25)
                ->nullable();

            $table->string('email', 150)
                ->nullable();

            // Dirección fiscal o comercial.
            $table->text('address')
                ->nullable();

            // Información adicional del proveedor.
            $table->text('notes')
                ->nullable();

            /*
             * Permite desactivar proveedores sin eliminarlos,
             * conservando el historial de compras.
             */
            $table->boolean('is_active')
                ->default(true);

            // Fechas de creación y última modificación.
            $table->timestamps();

            /*
             * Acelera la búsqueda y los filtros del catálogo.
             */
            $table->index(['is_active', 'name']);
            $table->index('email');
        });
    }

    /**
     * Elimina la tabla al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};

