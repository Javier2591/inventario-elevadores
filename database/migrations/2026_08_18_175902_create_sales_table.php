<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el encabezado de las ventas.
     */
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();

            $table->string('folio', 50)->unique();

            /*
             * Toda venta debe conservar al cliente y al usuario
             * responsable de registrarla.
             */
            $table
                ->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table
                ->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('sale_date')->index();

            /*
             * Importes históricos de la venta.
             */
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(16);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->enum('payment_method', [
                'cash',
                'card',
                'transfer',
                'credit',
                'other',
            ])->default('cash');

            $table->string('payment_reference', 100)->nullable();

            $table->enum('status', [
                'pending',
                'completed',
                'cancelled',
            ])->default('completed')->index();

            /*
             * Auditoría de cancelación.
             */
            $table
                ->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'sale_date']);
            $table->index(['user_id', 'sale_date']);
        });
    }

    /**
     * Elimina las ventas al revertir.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};