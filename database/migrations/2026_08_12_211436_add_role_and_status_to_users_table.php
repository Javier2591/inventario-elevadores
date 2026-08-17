<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega los datos de administración y acceso
     * al catálogo de usuarios.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Teléfono de contacto del usuario o técnico.
            $table->string('phone', 25)
                ->nullable()
                ->after('email');

            /*
             * admin      = acceso administrativo al sistema.
             * technician = usuario asignable a órdenes de servicio.
             *
             * Los usuarios creados posteriormente serán técnicos
             * hasta que un administrador cambie su función.
             */
            $table->enum('role', [
                'admin',
                'technician',
            ])
                ->default('technician')
                ->after('phone');

            /*
             * Permite bloquear el acceso sin eliminar al usuario,
             * conservando sus compras, movimientos y órdenes.
             */
            $table->boolean('is_active')
                ->default(true)
                ->after('role');

            // Índices para búsquedas y filtros.
            $table->index([
                'role',
                'is_active',
            ]);

            $table->index([
                'is_active',
                'name',
            ]);
        });

        /*
         * Los usuarios que ya existían antes de esta migración
         * conservan acceso administrativo. Posteriormente será
         * posible cambiar su función desde el módulo de usuarios.
         */
        DB::table('users')->update([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /**
     * Elimina los campos al revertir la migración.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex([
                'role',
                'is_active',
            ]);

            $table->dropIndex([
                'is_active',
                'name',
            ]);

            $table->dropColumn([
                'phone',
                'role',
                'is_active',
            ]);
        });
    }
};
