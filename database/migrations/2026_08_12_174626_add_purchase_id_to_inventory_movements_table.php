<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relaciona una entrada de inventario con su compra.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            /*
             * Es opcional porque algunos movimientos pueden
             * registrarse manualmente sin pertenecer a una compra.
             */
            $table->foreignId('purchase_id')
                ->nullable()
                ->after('user_id')
                ->constrained('purchases')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index([
                'purchase_id',
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
                'purchase_id',
                'created_at',
            ]);

            $table->dropConstrainedForeignId('purchase_id');
        });
    }
};