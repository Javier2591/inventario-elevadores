<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las partidas o renglones de cada compra.
     */
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            /*
             * Compra a la que pertenece la partida.
             * Si se revierte una compra durante desarrollo,
             * sus partidas también se eliminan.
             */
            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            /*
             * Refacción adquirida.
             * No se permite eliminar una refacción utilizada.
             */
            $table->foreignId('spare_part_id')
                ->constrained('spare_parts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Cantidad comprada.
            $table->unsignedInteger('quantity');

            // Costo unitario registrado en la compra.
            $table->decimal('unit_cost', 12, 2);

            // Resultado de cantidad multiplicada por costo.
            $table->decimal('subtotal', 12, 2);

            $table->timestamps();

            /*
             * Evita repetir la misma refacción dentro
             * de una sola compra.
             */
            $table->unique([
                'purchase_id',
                'spare_part_id',
            ]);

            $table->index([
                'spare_part_id',
                'created_at',
            ]);
        });
    }

    /**
     * Elimina el detalle al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};