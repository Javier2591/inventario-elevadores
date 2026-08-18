<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega un código de barras independiente del SKU interno.
     */
    public function up(): void
    {
        Schema::table('spare_parts', function (Blueprint $table): void {
            $table
                ->string('barcode', 100)
                ->nullable()
                ->unique()
                ->after('sku');
        });
    }

    /**
     * Elimina el índice y la columna al revertir.
     */
    public function down(): void
    {
        Schema::table('spare_parts', function (Blueprint $table): void {
            $table->dropUnique(['barcode']);
            $table->dropColumn('barcode');
        });
    }
};
