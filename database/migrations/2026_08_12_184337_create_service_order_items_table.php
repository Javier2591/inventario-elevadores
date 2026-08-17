<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las partidas de refacciones utilizadas.
     */
    public function up(): void
    {
        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();

            /*
             * Orden a la que pertenece la refacción.
             */
            $table->foreignId('service_order_id')
                ->constrained('service_orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            /*
             * Refacción utilizada.
             * No se permite eliminar una pieza con historial.
             */
            $table->foreignId('spare_part_id')
                ->constrained('spare_parts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Cantidad utilizada durante el servicio.
            $table->unsignedInteger('quantity');

            /*
             * El costo se guarda como evidencia histórica,
             * aunque posteriormente cambie en el inventario.
             */
            $table->decimal('unit_cost', 12, 2);

            // Cantidad multiplicada por el costo unitario.
            $table->decimal('subtotal', 12, 2);

            $table->timestamps();

            /*
             * Una refacción solamente puede aparecer una vez
             * dentro de la misma orden.
             */
            $table->unique([
                'service_order_id',
                'spare_part_id',
            ]);

            $table->index([
                'spare_part_id',
                'created_at',
            ]);
        });
    }

    /**
     * Elimina las partidas al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
    }
};
