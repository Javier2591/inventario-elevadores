<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el encabezado de cada compra.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            /*
             * Proveedor al que se realizó la compra.
             * No se permite eliminarlo si tiene compras registradas.
             */
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Usuario responsable de registrar la compra.
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Factura, nota o folio proporcionado por el proveedor.
            $table->string('folio', 100)
                ->nullable();

            // Fecha indicada en el documento de compra.
            $table->date('purchase_date');

            // Importes calculados a partir de las refacciones.
            $table->decimal('subtotal', 12, 2)
                ->default(0);

            $table->decimal('tax', 12, 2)
                ->default(0);

            $table->decimal('total', 12, 2)
                ->default(0);

            /*
             * received  = compra recibida y aplicada al inventario.
             * cancelled = compra cancelada.
             */
            $table->enum('status', [
                'received',
                'cancelled',
            ])->default('received');

            // Información complementaria.
            $table->text('notes')
                ->nullable();

            $table->timestamps();

            /*
             * Un proveedor no debe tener dos compras
             * con el mismo folio.
             */
            $table->unique([
                'supplier_id',
                'folio',
            ]);

            // Índices para búsquedas y reportes.
            $table->index([
                'supplier_id',
                'purchase_date',
            ]);

            $table->index([
                'status',
                'purchase_date',
            ]);

            $table->index([
                'user_id',
                'purchase_date',
            ]);
        });
    }

    /**
     * Elimina la tabla al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
