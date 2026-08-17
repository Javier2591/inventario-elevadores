<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el historial de entradas y salidas del inventario.
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            // Identificador único del movimiento.
            $table->id();

            /*
             * Refacción afectada.
             * No se permite eliminar una refacción que tenga movimientos,
             * porque se perdería parte del historial del inventario.
             */
            $table->foreignId('spare_part_id')
                ->constrained('spare_parts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            /*
             * Usuario que realizó la operación.
             * Conserva la responsabilidad y trazabilidad del movimiento.
             */
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            /*
             * Tipo de movimiento permitido:
             * entry = entrada de mercancía.
             * exit  = salida de mercancía.
             */
            $table->enum('type', ['entry', 'exit']);

            // Número de unidades agregadas o retiradas.
            $table->unsignedInteger('quantity');

            /*
             * Existencias antes y después de la operación.
             * Estos campos permiten reconstruir y auditar el inventario.
             */
            $table->unsignedInteger('previous_stock');
            $table->unsignedInteger('new_stock');

            // Razón por la que se realizó el movimiento.
            $table->string('reason', 150);

            /*
             * Folio, factura, orden de compra o documento relacionado.
             * Es opcional porque no todos los movimientos tendrán referencia.
             */
            $table->string('reference', 100)->nullable();

            // Información complementaria del movimiento.
            $table->text('notes')->nullable();

            // Fecha y hora de creación del movimiento.
            $table->timestamps();

            /*
             * Índices para acelerar consultas por refacción,
             * tipo de movimiento, usuario y fecha.
             */
            $table->index(['spare_part_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Elimina la tabla al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};