<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las órdenes de mantenimiento de elevadores.
     */
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();

            /*
             * Folio identificador de la orden.
             * Se generará automáticamente desde el sistema.
             */
            $table->string('folio', 50)
                ->nullable()
                ->unique();

            /*
             * Elevador atendido.
             * No se podrá eliminar si tiene órdenes registradas.
             */
            $table->foreignId('elevator_id')
                ->constrained('elevators')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Usuario que creó la orden.
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            /*
             * Técnico asignado.
             * Puede asignarse después de crear la orden.
             */
            $table->foreignId('technician_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            /*
             * Tipo de servicio solicitado.
             */
            $table->enum('service_type', [
                'preventive',
                'corrective',
                'inspection',
                'installation',
            ]);

            /*
             * Nivel de atención requerido.
             */
            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'urgent',
            ])->default('medium');

            /*
             * scheduled   = programada.
             * in_progress = en proceso.
             * completed   = terminada.
             * cancelled   = cancelada.
             */
            $table->enum('status', [
                'scheduled',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('scheduled');

            // Fecha y hora programadas para la atención.
            $table->dateTime('scheduled_at')
                ->nullable();

            // Fecha y hora reales de inicio y término.
            $table->dateTime('started_at')
                ->nullable();

            $table->dateTime('completed_at')
                ->nullable();

            // Problema o servicio solicitado.
            $table->text('description');

            // Diagnóstico realizado por el técnico.
            $table->text('diagnosis')
                ->nullable();

            // Trabajo efectuado durante el servicio.
            $table->text('work_performed')
                ->nullable();

            // Información complementaria.
            $table->text('notes')
                ->nullable();

            // Costos registrados en la orden.
            $table->decimal('labor_cost', 12, 2)
                ->default(0);

            $table->decimal('parts_cost', 12, 2)
                ->default(0);

            $table->decimal('total_cost', 12, 2)
                ->default(0);

            $table->timestamps();

            // Índices para consultas operativas.
            $table->index([
                'elevator_id',
                'status',
            ]);

            $table->index([
                'technician_id',
                'status',
            ]);

            $table->index([
                'status',
                'scheduled_at',
            ]);

            $table->index([
                'service_type',
                'priority',
            ]);

            $table->index([
                'created_by',
                'created_at',
            ]);
        });
    }

    /**
     * Elimina la tabla al revertir la migración.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};