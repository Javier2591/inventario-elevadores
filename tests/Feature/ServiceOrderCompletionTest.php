<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Elevator;
use App\Models\InventoryMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\SparePart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceOrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    private Category $category;

    private Elevator $elevator;

    private SparePart $sparePart;

    /**
     * Prepara los datos necesarios antes de cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Fijamos la fecha para que los resultados sean deterministas
         * y no dependan del día en que se ejecuten las pruebas.
         */
        Carbon::setTestNow('2026-08-17 10:00:00');

        $this->admin = User::factory()->create([
            'name' => 'Administrador de prueba',
            'email' => 'admin.prueba@example.com',
            'phone' => '5511111111',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->technician = User::factory()->create([
            'name' => 'Técnico de prueba',
            'email' => 'tecnico.prueba@example.com',
            'phone' => '5522222222',
            'role' => User::ROLE_TECHNICIAN,
            'is_active' => true,
        ]);

        /*
         * Se asignan directamente las propiedades para no depender
         * de la configuración $fillable del modelo Category.
         *
         * El campo slug es obligatorio en la base de datos.
         */
        $this->category = new Category();
        $this->category->name = 'Botoneras de prueba';
        $this->category->slug = 'botoneras-de-prueba';
        $this->category->description =
            'Categoría utilizada por las pruebas.';
        $this->category->is_active = true;
        $this->category->save();

        $this->elevator = Elevator::query()->create([
            'code' => 'ELEV-TEST-001',
            'client_name' => 'Cliente de prueba',
            'building_name' => 'Edificio de prueba',
            'address' => 'Dirección de prueba',
            'brand' => 'Marca de prueba',
            'model' => 'Modelo de prueba',
            'serial_number' => 'SERIE-TEST-001',
            'stops' => 10,
            'capacity_kg' => 800,
            'installation_date' => '2026-01-10',
            'last_service_date' => null,
            'status' => 'active',
            'notes' => null,
        ]);

        $this->sparePart = $this->createSparePart(
            sku: 'REF-TEST-001',
            name: 'Botón de llamada',
            stock: 10,
            cost: 125.50,
        );

        /*
         * El componente de órdenes de servicio pertenece al área
         * administrativa, por lo que autenticamos al administrador.
         */
        $this->actingAs($this->admin);
    }

    /**
     * Limpia la fecha simulada después de cada prueba.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verifica el flujo exitoso de finalización de una orden.
     */
    public function test_completing_an_order_deducts_stock_and_creates_a_movement(): void
    {
        $order = $this->createServiceOrder();

        $item = $this->addPartToOrder(
            order: $order,
            sparePart: $this->sparePart,
            quantity: 3,
        );

        $this->completeOrder(
            order: $order,
            laborCost: '500.00',
        );

        $completedOrder = $order->fresh();
        $updatedPart = $this->sparePart->fresh();
        $updatedItem = $item->fresh();
        $updatedElevator = $this->elevator->fresh();

        /*
         * Existencia inicial: 10.
         * Cantidad utilizada: 3.
         * Existencia esperada: 7.
         */
        $this->assertSame(
            7,
            $updatedPart->stock
        );

        /*
         * El costo histórico debe guardarse en la partida:
         * 3 unidades × $125.50 = $376.50.
         */
        $this->assertEquals(
            125.50,
            (float) $updatedItem->unit_cost
        );

        $this->assertEquals(
            376.50,
            (float) $updatedItem->subtotal
        );

        /*
         * Validamos el nuevo estado y la información técnica.
         */
        $this->assertSame(
            'completed',
            $completedOrder->status
        );

        $this->assertNotNull(
            $completedOrder->completed_at
        );

        $this->assertSame(
            '2026-08-17',
            $completedOrder->completed_at->toDateString()
        );

        $this->assertSame(
            'Desgaste en los botones de llamada.',
            $completedOrder->diagnosis
        );

        $this->assertSame(
            'Se sustituyeron los botones dañados.',
            $completedOrder->work_performed
        );

        /*
         * Mano de obra: $500.00.
         * Refacciones: $376.50.
         * Total: $876.50.
         */
        $this->assertEquals(
            500.00,
            (float) $completedOrder->labor_cost
        );

        $this->assertEquals(
            376.50,
            (float) $completedOrder->parts_cost
        );

        $this->assertEquals(
            876.50,
            (float) $completedOrder->total_cost
        );

        /*
         * Debe generarse una salida de inventario relacionada
         * con la orden y con el administrador que la completó.
         */
        $this->assertDatabaseHas('inventory_movements', [
            'spare_part_id' => $this->sparePart->id,
            'user_id' => $this->admin->id,
            'purchase_id' => null,
            'service_order_id' => $order->id,
            'type' => 'exit',
            'quantity' => 3,
            'previous_stock' => 10,
            'new_stock' => 7,
            'reason' => 'Orden de servicio: '.$order->folio,
            'reference' => $order->folio,
        ]);

        /*
         * La fecha de último servicio del elevador debe actualizarse.
         */
        $this->assertNotNull(
            $updatedElevator->last_service_date
        );

        $this->assertSame(
            '2026-08-17',
            $updatedElevator->last_service_date->toDateString()
        );

        $this->assertSame(
            'active',
            $updatedElevator->status
        );
    }

    /**
     * Verifica que una existencia insuficiente revierta
     * absolutamente todos los cambios de la transacción.
     */
    public function test_insufficient_stock_rolls_back_the_complete_operation(): void
    {
        $order = $this->createServiceOrder(
            folio: 'OS-TEST-000002'
        );

        /*
         * Esta partida tiene existencias suficientes y se procesa primero.
         */
        $firstItem = $this->addPartToOrder(
            order: $order,
            sparePart: $this->sparePart,
            quantity: 3,
        );

        /*
         * Esta segunda refacción únicamente tiene una unidad,
         * pero la orden requiere dos.
         */
        $insufficientPart = $this->createSparePart(
            sku: 'REF-TEST-002',
            name: 'Tarjeta electrónica',
            stock: 1,
            cost: 800.00,
        );

        $secondItem = $this->addPartToOrder(
            order: $order,
            sparePart: $insufficientPart,
            quantity: 2,
        );

        try {
            $this->completeOrder($order);

            $this->fail(
                'La orden no debía completarse porque el inventario es insuficiente.'
            );
        } catch (ValidationException $exception) {
            /*
             * El método debe devolver el error dentro de la clave completion.
             */
            $this->assertArrayHasKey(
                'completion',
                $exception->errors()
            );
        }

        /*
         * La transacción debe restaurar las existencias originales.
         */
        $this->assertSame(
            10,
            $this->sparePart->fresh()->stock
        );

        $this->assertSame(
            1,
            $insufficientPart->fresh()->stock
        );

        /*
         * Las partidas tampoco deben conservar costos modificados.
         */
        $this->assertEquals(
            0.00,
            (float) $firstItem->fresh()->unit_cost
        );

        $this->assertEquals(
            0.00,
            (float) $firstItem->fresh()->subtotal
        );

        $this->assertEquals(
            0.00,
            (float) $secondItem->fresh()->unit_cost
        );

        $this->assertEquals(
            0.00,
            (float) $secondItem->fresh()->subtotal
        );

        $unchangedOrder = $order->fresh();

        /*
         * La orden debe continuar en proceso.
         */
        $this->assertSame(
            'in_progress',
            $unchangedOrder->status
        );

        $this->assertNull(
            $unchangedOrder->completed_at
        );

        $this->assertEquals(
            0.00,
            (float) $unchangedOrder->parts_cost
        );

        $this->assertEquals(
            0.00,
            (float) $unchangedOrder->total_cost
        );

        /*
         * No se debe actualizar la fecha del elevador.
         */
        $this->assertNull(
            $this->elevator->fresh()->last_service_date
        );

        /*
         * Tampoco debe existir ningún movimiento de inventario.
         */
        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );
    }

    /**
     * Verifica que una orden completada no pueda descontar
     * las mismas refacciones una segunda vez.
     */
    public function test_a_completed_order_cannot_deduct_inventory_twice(): void
    {
        $order = $this->createServiceOrder(
            folio: 'OS-TEST-000003'
        );

        $this->addPartToOrder(
            order: $order,
            sparePart: $this->sparePart,
            quantity: 2,
        );

        /*
         * Primera finalización válida:
         * 10 unidades iniciales menos 2 utilizadas = 8.
         */
        $this->completeOrder($order);

        $this->assertSame(
            8,
            $this->sparePart->fresh()->stock
        );

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('service_order_id', $order->id)
                ->count()
        );

        /*
         * El segundo intento debe ser rechazado porque
         * la orden ya se encuentra completada.
         */
        try {
            $this->completeOrder($order);

            $this->fail(
                'Una orden completada no debe poder completarse nuevamente.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'completion',
                $exception->errors()
            );
        }

        /*
         * El segundo intento no debe modificar nuevamente el inventario.
         */
        $this->assertSame(
            8,
            $this->sparePart->fresh()->stock
        );

        /*
         * Debe existir únicamente el movimiento producido
         * durante la primera finalización.
         */
        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('service_order_id', $order->id)
                ->count()
        );

        $this->assertSame(
            'completed',
            $order->fresh()->status
        );
    }

    /**
     * Crea una orden en proceso lista para completarse.
     */
    private function createServiceOrder(
        string $folio = 'OS-TEST-000001'
    ): ServiceOrder {
        return ServiceOrder::query()->create([
            'folio' => $folio,
            'elevator_id' => $this->elevator->id,
            'created_by' => $this->admin->id,
            'technician_id' => $this->technician->id,
            'service_type' => 'preventive',
            'priority' => 'medium',
            'status' => 'in_progress',
            'scheduled_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
            'completed_at' => null,
            'description' => 'Mantenimiento preventivo de prueba.',
            'diagnosis' => null,
            'work_performed' => null,
            'notes' => null,
            'labor_cost' => 0,
            'parts_cost' => 0,
            'total_cost' => 0,
        ]);
    }

    /**
     * Crea una refacción dentro de la categoría de prueba.
     */
    private function createSparePart(
        string $sku,
        string $name,
        int $stock,
        float $cost
    ): SparePart {
        return SparePart::query()->create([
            'category_id' => $this->category->id,
            'sku' => $sku,
            'name' => $name,
            'description' => 'Refacción utilizada por las pruebas.',
            'brand' => 'Marca de prueba',
            'stock' => $stock,
            'minimum_stock' => 2,
            'location' => 'Almacén de pruebas',
            'cost' => $cost,
            'is_active' => true,
        ]);
    }

    /**
     * Agrega una refacción planeada a la orden.
     */
    private function addPartToOrder(
        ServiceOrder $order,
        SparePart $sparePart,
        int $quantity
    ): ServiceOrderItem {
        return ServiceOrderItem::query()->create([
            'service_order_id' => $order->id,
            'spare_part_id' => $sparePart->id,
            'quantity' => $quantity,
            'unit_cost' => 0,
            'subtotal' => 0,
        ]);
    }

    /**
     * Ejecuta el método utilizado por el botón "Completar".
     */
    private function completeOrder(
        ServiceOrder $order,
        string $laborCost = '300.00'
    ): void {
        /*
         * Cargamos una instancia real del componente Livewire.
         */
        $livewireTest = Livewire::test(
            'service-orders.index'
        );

        $component = $livewireTest->instance();

        /*
         * Simulamos los datos enviados por el formulario.
         */
        $component->completionOrderId = $order->id;

        $component->diagnosis =
            'Desgaste en los botones de llamada.';

        $component->work_performed =
            'Se sustituyeron los botones dañados.';

        $component->labor_cost = $laborCost;

        /*
         * Ejecutamos el método real que contiene la transacción,
         * el descuento de inventario y la creación del Kárdex.
         */
        $component->completeOrder();
    }
}