<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el catálogo de clientes.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 150);
            $table->string('tax_id', 20)->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable()->index();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true)->index();

            /*
             * Conservamos quién registró al cliente.
             * Si el usuario es eliminado, el cliente permanece.
             */
            $table
                ->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['name', 'is_active']);
        });
    }

    /**
     * Elimina el catálogo al revertir.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};