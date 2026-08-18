<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las partidas o productos de cada venta.
     */
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();

            $table
                ->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table
                ->foreignId('spare_part_id')
                ->constrained('spare_parts')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            /*
             * unit_cost conserva el costo del inventario en el momento
             * de la venta. unit_price conserva el precio cobrado.
             */
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);

            $table->timestamps();

            /*
             * Una refacción solo puede aparecer una vez por venta.
             * Las cantidades repetidas se acumularán en la misma partida.
             */
            $table->unique(['sale_id', 'spare_part_id']);
        });
    }

    /**
     * Elimina las partidas al revertir.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};