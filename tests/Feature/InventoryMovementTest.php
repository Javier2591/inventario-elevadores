<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\SparePart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    private SparePart $sparePart;

    /**
     * Prepara los registros necesarios antes de cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Fijamos la fecha para que las pruebas sean deterministas.
         */
        Carbon::setTestNow('2026-08-17 14:00:00');

        $this->admin = User::factory()->create([
            'name' => 'Administrador de inventario',
            'email' => 'admin.inventario@example.com',
            'phone' => '5511111111',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        /*
         * El slug es obligatorio en la tabla categories.
         * Las propiedades se asignan directamente para no depender
         * de la configuración $fillable del modelo.
         */
        $this->category = new Category();
        $this->category->name = 'Movimientos de prueba';
        $this->category->slug = 'movimientos-de-prueba';
        $this->category->description =
            'Categoría utilizada por InventoryMovementTest.';
        $this->category->is_active = true;
        $this->category->save();

        /*
         * Refacción inicial:
         * 10 unidades con costo de $100.00.
         */
        $this->sparePart = $this->createSparePart(
            sku: 'MOV-TEST-001',
            name: 'Botón de llamada',
            stock: 10,
            cost: 100.00,
        );

        /*
         * El módulo de movimientos requiere un administrador.
         */
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Comprueba que una entrada manual aumente las existencias.
     */
    public function test_manual_entry_increases_stock_and_creates_kardex_record(): void
    {
        $this->registerMovement(
            sparePart: $this->sparePart,
            type: InventoryMovement::TYPE_ENTRY,
            quantity: 5,
            reason: '  Entrada   manual de almacén  ',
            reference: '  AJUSTE-001  ',
            notes: '  Recepción manual de refacciones.  ',
        );

        $updatedPart = $this->sparePart->fresh();

        $movement = InventoryMovement::query()
            ->firstOrFail();

        /*
         * Existencia:
         * 10 unidades iniciales + 5 de entrada = 15.
         */
        $this->assertSame(
            15,
            $updatedPart->stock
        );

        /*
         * Un movimiento manual no modifica el costo de la refacción.
         */
        $this->assertEquals(
            100.00,
            (float) $updatedPart->cost
        );

        /*
         * Validamos la información registrada en el Kárdex.
         */
        $this->assertSame(
            $this->sparePart->id,
            $movement->spare_part_id
        );

        $this->assertSame(
            $this->admin->id,
            $movement->user_id
        );

        $this->assertNull(
            $movement->purchase_id
        );

        $this->assertNull(
            $movement->service_order_id
        );

        $this->assertSame(
            InventoryMovement::TYPE_ENTRY,
            $movement->type
        );

        $this->assertSame(
            5,
            $movement->quantity
        );

        $this->assertSame(
            10,
            $movement->previous_stock
        );

        $this->assertSame(
            15,
            $movement->new_stock
        );

        /*
         * Str::squish elimina espacios repetidos del motivo
         * y de la referencia.
         */
        $this->assertSame(
            'Entrada manual de almacén',
            $movement->reason
        );

        $this->assertSame(
            'AJUSTE-001',
            $movement->reference
        );

        $this->assertSame(
            'Recepción manual de refacciones.',
            $movement->notes
        );

        $this->assertTrue(
            $movement->isEntry()
        );

        $this->assertFalse(
            $movement->isExit()
        );

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->entries()
                ->count()
        );
    }

    /**
     * Comprueba que una salida manual descuente existencias.
     */
    public function test_manual_exit_decreases_stock_and_creates_kardex_record(): void
    {
        $this->registerMovement(
            sparePart: $this->sparePart,
            type: InventoryMovement::TYPE_EXIT,
            quantity: 4,
            reason: 'Uso interno',
            reference: '',
            notes: '',
        );

        $updatedPart = $this->sparePart->fresh();

        $movement = InventoryMovement::query()
            ->firstOrFail();

        /*
         * Existencia:
         * 10 unidades iniciales - 4 de salida = 6.
         */
        $this->assertSame(
            6,
            $updatedPart->stock
        );

        $this->assertSame(
            InventoryMovement::TYPE_EXIT,
            $movement->type
        );

        $this->assertSame(
            4,
            $movement->quantity
        );

        $this->assertSame(
            10,
            $movement->previous_stock
        );

        $this->assertSame(
            6,
            $movement->new_stock
        );

        $this->assertSame(
            'Uso interno',
            $movement->reason
        );

        /*
         * Los textos vacíos se guardan como null.
         */
        $this->assertNull(
            $movement->reference
        );

        $this->assertNull(
            $movement->notes
        );

        $this->assertTrue(
            $movement->isExit()
        );

        $this->assertFalse(
            $movement->isEntry()
        );

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->exits()
                ->count()
        );
    }

    /**
     * Comprueba que no se permita retirar más unidades
     * de las que existen actualmente.
     */
    public function test_exit_with_insufficient_stock_is_rejected(): void
    {
        try {
            $this->registerMovement(
                sparePart: $this->sparePart,
                type: InventoryMovement::TYPE_EXIT,
                quantity: 11,
                reason: 'Salida superior a existencia',
                reference: 'SALIDA-001',
                notes: '',
            );

            $this->fail(
                'La salida no debía registrarse porque solamente existen 10 unidades.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'quantity',
                $exception->errors()
            );
        }

        /*
         * La existencia debe permanecer intacta.
         */
        $this->assertSame(
            10,
            $this->sparePart->fresh()->stock
        );

        /*
         * No debe generarse ningún movimiento.
         */
        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );
    }

    /**
     * Comprueba que una entrada no pueda superar
     * la existencia máxima establecida por el sistema.
     */
    public function test_entry_that_exceeds_stock_limit_is_rejected(): void
    {
        $this->sparePart->update([
            'stock' => 999999999,
        ]);

        try {
            $this->registerMovement(
                sparePart: $this->sparePart,
                type: InventoryMovement::TYPE_ENTRY,
                quantity: 1,
                reason: 'Entrada sobre límite máximo',
                reference: 'ENTRADA-LIMITE',
                notes: '',
            );

            $this->fail(
                'La entrada no debía superar el límite máximo de existencias.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'quantity',
                $exception->errors()
            );
        }

        /*
         * La transacción debe mantener la existencia original.
         */
        $this->assertSame(
            999999999,
            $this->sparePart->fresh()->stock
        );

        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );
    }

    /**
     * Comprueba que una refacción inactiva no pueda
     * recibir entradas ni salidas manuales.
     */
    public function test_inactive_spare_part_cannot_receive_movements(): void
    {
        $this->sparePart->update([
            'is_active' => false,
        ]);

        try {
            $this->registerMovement(
                sparePart: $this->sparePart,
                type: InventoryMovement::TYPE_ENTRY,
                quantity: 5,
                reason: 'Movimiento no permitido',
                reference: 'INACTIVA-001',
                notes: '',
            );

            $this->fail(
                'Una refacción inactiva no debe aceptar movimientos.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'spare_part_id',
                $exception->errors()
            );
        }

        $this->assertSame(
            10,
            $this->sparePart->fresh()->stock
        );

        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );
    }

    /**
     * Crea una refacción para las pruebas.
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
            'description' => 'Refacción utilizada en pruebas.',
            'brand' => 'Marca de prueba',
            'stock' => $stock,
            'minimum_stock' => 2,
            'location' => 'Almacén de pruebas',
            'cost' => $cost,
            'is_active' => true,
        ]);
    }

    /**
     * Ejecuta el método real utilizado por el formulario
     * de movimientos de inventario.
     */
    private function registerMovement(
        SparePart $sparePart,
        string $type,
        int $quantity,
        string $reason,
        string $reference,
        string $notes
    ): void {
        $livewireTest = Livewire::test(
            'inventory-movements.index'
        );

        $component = $livewireTest->instance();

        /*
         * Se asignan los datos esperados por el componente.
         */
        $component->spare_part_id =
            (string) $sparePart->id;

        $component->type = $type;

        $component->quantity =
            (string) $quantity;

        $component->reason = $reason;

        $component->reference = $reference;

        $component->notes = $notes;

        /*
         * Ejecuta validación, transacción, actualización
         * de existencias y creación del movimiento.
         */
        $component->save();
    }
}