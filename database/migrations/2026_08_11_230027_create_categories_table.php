<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla para clasificar las refacciones.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            // Identificador único de la categoría.
            $table->id();

            // Nombre visible, por ejemplo: Tarjetas electrónicas.
            $table->string('name', 100)->unique();

            // Nombre adaptado para URLs, por ejemplo: tarjetas-electronicas.
            $table->string('slug', 120)->unique();

            // Información adicional opcional.
            $table->text('description')->nullable();

            // Permite desactivar una categoría sin eliminarla.
            $table->boolean('is_active')->default(true)->index();

            // Guarda automáticamente fecha de creación y actualización.
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla si se revierte la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};