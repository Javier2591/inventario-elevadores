<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SparePart;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    private Supplier $supplier;

    private SparePart $sparePart;

    /**
     * Prepara los registros necesarios antes de cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * La fecha queda fija para obtener siempre
         * los mismos resultados en las pruebas.
         */
        Carbon::setTestNow('2026-08-17 12:00:00');

        $this->admin = User::factory()->create([
            'name' => 'Administrador de compras',
            'email' => 'admin.compras@example.com',
            'phone' => '5511111111',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        /*
         * El slug es obligatorio. Asignamos directamente
         * las propiedades para no depender de $fillable.
         */
        $this->category = new Category();
        $this->category->name = 'Refacciones de prueba';
        $this->category->slug = 'refacciones-de-prueba';
        $this->category->description =
            'Categoría utilizada por PurchaseInventoryTest.';
        $this->category->is_active = true;
        $this->category->save();

        $this->supplier = Supplier::query()->create([
            'name' => 'Proveedor Industrial de Prueba',
            'tax_id' => 'PIP260817ABC',
            'contact_name' => 'Contacto de prueba',
            'phone' => '5522222222',
            'email' => 'proveedor@example.com',
            'address' => 'Dirección del proveedor de prueba',
            'notes' => null,
            'is_active' => true,
        ]);

        /*
         * Existencia inicial:
         * 10 unidades con costo promedio de $100.00.
         */
        $this->sparePart = $this->createSparePart(
            sku: 'REF-COMPRA-001',
            name: 'Botón de llamada',
            stock: 10,
            cost: 100.00,
        );

        /*
         * El módulo de compras requiere un administrador autenticado.
         */
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verifica el registro completo de una compra con IVA.
     */
    public function test_purchase_increases_stock_and_creates_inventory_entry(): void
    {
        /*
         * Se compran 5 unidades con costo de $160.00:
         *
         * Subtotal: 5 × $160.00 = $800.00
         * IVA: $800.00 × 16% = $128.00
         * Total: $928.00
         */
        $this->registerPurchase(
            items: [
                $this->purchaseItem(
                    sparePart: $this->sparePart,
                    quantity: 5,
                    unitCost: '160.00',
                    key: 'item-1',
                ),
            ],
            folio: 'FACT-TEST-001',
            includeTax: true,
        );

        $purchase = Purchase::query()
            ->where('folio', 'FACT-TEST-001')
            ->firstOrFail();

        $purchaseItem = PurchaseItem::query()
            ->where('purchase_id', $purchase->id)
            ->where('spare_part_id', $this->sparePart->id)
            ->firstOrFail();

        $updatedPart = $this->sparePart->fresh();

        /*
         * Existencia:
         * 10 anteriores + 5 compradas = 15.
         */
        $this->assertSame(
            15,
            $updatedPart->stock
        );

        /*
         * Costo promedio ponderado:
         *
         * (10 × $100.00) + (5 × $160.00)
         * -------------------------------- = $120.00
         *              15
         */
        $this->assertEquals(
            120.00,
            (float) $updatedPart->cost
        );

        /*
         * Validamos el encabezado de la compra.
         */
        $this->assertSame(
            $this->supplier->id,
            $purchase->supplier_id
        );

        $this->assertSame(
            $this->admin->id,
            $purchase->user_id
        );

        $this->assertSame(
            'received',
            $purchase->status
        );

        $this->assertSame(
            '2026-08-17',
            $purchase->purchase_date->toDateString()
        );

        $this->assertEquals(
            800.00,
            (float) $purchase->subtotal
        );

        $this->assertEquals(
            128.00,
            (float) $purchase->tax
        );

        $this->assertEquals(
            928.00,
            (float) $purchase->total
        );

        /*
         * Validamos la partida de la compra.
         */
        $this->assertSame(
            5,
            $purchaseItem->quantity
        );

        $this->assertEquals(
            160.00,
            (float) $purchaseItem->unit_cost
        );

        $this->assertEquals(
            800.00,
            (float) $purchaseItem->subtotal
        );

        /*
         * Debe generarse automáticamente una entrada en el Kárdex.
         */
        $this->assertDatabaseHas('inventory_movements', [
            'spare_part_id' => $this->sparePart->id,
            'user_id' => $this->admin->id,
            'purchase_id' => $purchase->id,
            'service_order_id' => null,
            'type' => 'entry',
            'quantity' => 5,
            'previous_stock' => 10,
            'new_stock' => 15,
            'reason' => 'Compra a proveedor: '.$this->supplier->name,
            'reference' => 'FACT-TEST-001',
        ]);
    }

    /**
     * Verifica que una compra sin IVA mantenga el impuesto en cero.
     */
    public function test_purchase_without_tax_calculates_total_correctly(): void
    {
        /*
         * Compra:
         * 2 unidades × $50.00 = $100.00.
         *
         * Como include_tax es falso:
         * IVA = $0.00
         * Total = $100.00
         */
        $this->registerPurchase(
            items: [
                $this->purchaseItem(
                    sparePart: $this->sparePart,
                    quantity: 2,
                    unitCost: '50.00',
                    key: 'item-1',
                ),
            ],
            folio: 'FACT-TEST-002',
            includeTax: false,
        );

        $purchase = Purchase::query()
            ->where('folio', 'FACT-TEST-002')
            ->firstOrFail();

        $updatedPart = $this->sparePart->fresh();

        $this->assertEquals(
            100.00,
            (float) $purchase->subtotal
        );

        $this->assertEquals(
            0.00,
            (float) $purchase->tax
        );

        $this->assertEquals(
            100.00,
            (float) $purchase->total
        );

        $this->assertSame(
            12,
            $updatedPart->stock
        );

        /*
         * Nuevo costo promedio:
         *
         * (10 × $100.00) + (2 × $50.00)
         * -------------------------------- = $91.67
         *              12
         */
        $this->assertEquals(
            91.67,
            (float) $updatedPart->cost
        );

        $this->assertDatabaseHas('inventory_movements', [
            'spare_part_id' => $this->sparePart->id,
            'purchase_id' => $purchase->id,
            'type' => 'entry',
            'quantity' => 2,
            'previous_stock' => 10,
            'new_stock' => 12,
        ]);
    }

    /**
     * Verifica que una refacción repetida no permita registrar la compra.
     */
    public function test_purchase_rejects_duplicate_spare_parts(): void
    {
        try {
            $this->registerPurchase(
                items: [
                    $this->purchaseItem(
                        sparePart: $this->sparePart,
                        quantity: 2,
                        unitCost: '100.00',
                        key: 'item-1',
                    ),
                    $this->purchaseItem(
                        sparePart: $this->sparePart,
                        quantity: 3,
                        unitCost: '110.00',
                        key: 'item-2',
                    ),
                ],
                folio: 'FACT-TEST-003',
                includeTax: true,
            );

            $this->fail(
                'La compra no debía aceptar la misma refacción dos veces.'
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            /*
             * Laravel puede colocar el error distinct en cualquiera
             * de las dos posiciones repetidas.
             */
            $hasDuplicateError =
                array_key_exists(
                    'items.0.spare_part_id',
                    $errors
                )
                || array_key_exists(
                    'items.1.spare_part_id',
                    $errors
                );

            $this->assertTrue(
                $hasDuplicateError,
                'No se encontró el error de refacción repetida.'
            );
        }

        /*
         * La validación debe detener todo antes de modificar la BD.
         */
        $this->assertDatabaseCount(
            'purchases',
            0
        );

        $this->assertDatabaseCount(
            'purchase_items',
            0
        );

        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );

        $this->assertSame(
            10,
            $this->sparePart->fresh()->stock
        );

        $this->assertEquals(
            100.00,
            (float) $this->sparePart->fresh()->cost
        );
    }

    /**
     * Verifica que cualquier error interno revierta toda la compra.
     */
    public function test_purchase_rolls_back_when_resulting_stock_exceeds_limit(): void
    {
        /*
         * Esta refacción ya se encuentra en la existencia máxima
         * permitida por la lógica del componente.
         */
        $fullStockPart = $this->createSparePart(
            sku: 'REF-COMPRA-002',
            name: 'Tarjeta electrónica',
            stock: 999999999,
            cost: 50.00,
        );

        try {
            /*
             * La primera partida es válida y se procesa primero.
             * La segunda provoca el error dentro de la transacción.
             */
            $this->registerPurchase(
                items: [
                    $this->purchaseItem(
                        sparePart: $this->sparePart,
                        quantity: 2,
                        unitCost: '150.00',
                        key: 'item-1',
                    ),
                    $this->purchaseItem(
                        sparePart: $fullStockPart,
                        quantity: 1,
                        unitCost: '10.00',
                        key: 'item-2',
                    ),
                ],
                folio: 'FACT-TEST-004',
                includeTax: true,
            );

            $this->fail(
                'La compra debía fallar porque la existencia supera el límite.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'items.1.quantity',
                $exception->errors()
            );
        }

        /*
         * La compra se crea antes de procesar las partidas,
         * pero la transacción debe eliminarla al fallar.
         */
        $this->assertDatabaseCount(
            'purchases',
            0
        );

        $this->assertDatabaseCount(
            'purchase_items',
            0
        );

        $this->assertDatabaseCount(
            'inventory_movements',
            0
        );

        /*
         * La primera partida alcanzó a procesarse antes del error,
         * pero debe volver a sus valores originales.
         */
        $this->assertSame(
            10,
            $this->sparePart->fresh()->stock
        );

        $this->assertEquals(
            100.00,
            (float) $this->sparePart->fresh()->cost
        );

        /*
         * La segunda refacción tampoco debe modificarse.
         */
        $this->assertSame(
            999999999,
            $fullStockPart->fresh()->stock
        );

        $this->assertEquals(
            50.00,
            (float) $fullStockPart->fresh()->cost
        );
    }

    /**
     * Crea una refacción utilizada por las pruebas.
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
            'description' => 'Refacción utilizada en pruebas de compras.',
            'brand' => 'Marca de prueba',
            'stock' => $stock,
            'minimum_stock' => 2,
            'location' => 'Almacén de pruebas',
            'cost' => $cost,
            'is_active' => true,
        ]);
    }

    /**
     * Construye una partida con el formato esperado por Livewire.
     */
    private function purchaseItem(
        SparePart $sparePart,
        int $quantity,
        string $unitCost,
        string $key
    ): array {
        return [
            'key' => $key,
            'spare_part_id' => (string) $sparePart->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ];
    }

    /**
     * Ejecuta el método real utilizado por el formulario de compras.
     */
    private function registerPurchase(
        array $items,
        string $folio,
        bool $includeTax
    ): void {
        $livewireTest = Livewire::test(
            'purchases.index'
        );

        $component = $livewireTest->instance();

        /*
         * Encabezado de la compra.
         */
        $component->supplier_id =
            (string) $this->supplier->id;

        $component->folio = $folio;

        $component->purchase_date =
            now()->toDateString();

        $component->notes =
            'Compra registrada mediante una prueba automatizada.';

        $component->include_tax = $includeTax;

        /*
         * Partidas que serán validadas y procesadas.
         */
        $component->items = $items;

        /*
         * Ejecuta validación, cálculos, transacción,
         * actualización de existencias y creación del Kárdex.
         */
        $component->save();
    }
}