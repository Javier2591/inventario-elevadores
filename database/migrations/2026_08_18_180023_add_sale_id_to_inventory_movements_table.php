<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relaciona los movimientos de inventario con una venta.
     */
    public function up(): void
    {
        /*
         * La comprobación permite ejecutar la migración de manera
         * segura tanto en MySQL como en SQLite durante las pruebas.
         */
        if (! Schema::hasColumn(
            'inventory_movements',
            'sale_id'
        )) {
            Schema::table(
                'inventory_movements',
                function (Blueprint $table): void {
                    $table
                        ->foreignId('sale_id')
                        ->nullable()
                        ->after('service_order_id')
                        ->constrained('sales')
                        ->restrictOnDelete();
                }
            );
        }
    }

    /**
     * Elimina la relación al revertir la migración.
     */
    public function down(): void
    {
        if (Schema::hasColumn(
            'inventory_movements',
            'sale_id'
        )) {
            Schema::table(
                'inventory_movements',
                function (Blueprint $table): void {
                    $table->dropConstrainedForeignId(
                        'sale_id'
                    );
                }
            );
        }
    }
};
