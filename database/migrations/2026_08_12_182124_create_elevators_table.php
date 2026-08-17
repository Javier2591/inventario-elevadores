<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea el catálogo de elevadores atendidos.
     */
    public function up(): void
    {
        Schema::create('elevators', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            /*
             * Código único asignado por la empresa.
             * Ejemplo: ELEV-001.
             */
            $table->string('code', 50)
                ->unique();

            // Propietario, empresa o cliente responsable.
            $table->string('client_name', 150);

            // Nombre del edificio, condominio o inmueble.
            $table->string('building_name', 150);

            // Dirección donde se encuentra instalado.
            $table->text('address');

            // Información técnica del elevador.
            $table->string('brand', 100)
                ->nullable();

            $table->string('model', 100)
                ->nullable();

            /*
             * Número de serie del fabricante.
             * Es opcional, pero no puede repetirse.
             */
            $table->string('serial_number', 100)
                ->nullable()
                ->unique();

            // Cantidad de niveles o paradas atendidas.
            $table->unsignedSmallInteger('stops')
                ->nullable();

            // Capacidad máxima expresada en kilogramos.
            $table->unsignedSmallInteger('capacity_kg')
                ->nullable();

            // Fechas relevantes para mantenimiento.
            $table->date('installation_date')
                ->nullable();

            $table->date('last_service_date')
                ->nullable();

            /*
             * active         = equipo en funcionamiento.
             * out_of_service = temporalmente fuera de servicio.
             * inactive       = equipo que ya no se atiende.
             */
            $table->enum('status', [
                'active',
                'out_of_service',
                'inactive',
            ])->default('active');

            // Información técnica o administrativa adicional.
            $table->text('notes')
                ->nullable();

            $table->timestamps();

            // Índices para búsquedas y filtros frecuentes.
            $table->index([
                'status',
                'building_name',
            ]);

            $table->index([
                'client_name',
                'building_name',
            ]);

            $table->index([
                'brand',
                'model',
            ]);
        });
    }

    /**
     * Elimina el catálogo al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('elevators');
    }
};