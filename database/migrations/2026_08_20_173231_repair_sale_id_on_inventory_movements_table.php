<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la relación entre un movimiento y la venta que lo generó.
     */
    public function up(): void
    {
        // Evita errores si la columna ya existe en otro entorno.
        if (! Schema::hasColumn('inventory_movements', 'sale_id')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->foreignId('sale_id')
                    ->nullable()
                    ->after('service_order_id')
                    ->constrained('sales')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Revierte únicamente la columna agregada por esta migración.
     */
    public function down(): void
    {
        if (Schema::hasColumn('inventory_movements', 'sale_id')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('sale_id');
            });
        }
    }
};