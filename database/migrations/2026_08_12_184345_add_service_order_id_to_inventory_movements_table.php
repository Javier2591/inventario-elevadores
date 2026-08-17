<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relaciona un movimiento con su orden de servicio.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            /*
             * Es opcional porque existen movimientos manuales
             * y entradas generadas mediante compras.
             */
            $table->foreignId('service_order_id')
                ->nullable()
                ->after('purchase_id')
                ->constrained('service_orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index([
                'service_order_id',
                'created_at',
            ]);
        });
    }

    /**
     * Elimina la relación al revertir la migración.
     */
    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex([
                'service_order_id',
                'created_at',
            ]);

            $table->dropConstrainedForeignId(
                'service_order_id'
            );
        });
    }
};
