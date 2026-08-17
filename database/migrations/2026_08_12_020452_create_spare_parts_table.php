<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla principal de refacciones.
     */
    public function up(): void
    {
        Schema::create('spare_parts', function (Blueprint $table) {
            // Identificador único de la refacción.
            $table->id();

            /*
             * Relaciona cada refacción con una categoría.
             * restrictOnDelete evita borrar una categoría que tenga
             * refacciones asociadas.
             */
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Código interno único para identificar la refacción.
            $table->string('sku', 50)->unique();

            // Información general de la refacción.
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('brand', 100)->nullable();

            // Control básico de existencias.
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);

            // Lugar físico donde se encuentra almacenada.
            $table->string('location', 100)->nullable();

            /*
             * Costo unitario.
             * Se usa decimal en lugar de float para evitar errores
             * de redondeo en cantidades monetarias.
             */
            $table->decimal('cost', 12, 2)->default(0);

            // Permite desactivar una refacción sin eliminar su historial.
            $table->boolean('is_active')->default(true);

            // Fechas de creación y última modificación.
            $table->timestamps();

            // Índices para agilizar búsquedas frecuentes.
            $table->index('name');
            $table->index('brand');
            $table->index('is_active');
            $table->index(['category_id', 'is_active']);
        });
    }

    /**
     * Elimina la tabla al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('spare_parts');
    }
};