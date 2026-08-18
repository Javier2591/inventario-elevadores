<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SparePart;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Una venta completada debe descontar inventario,
     * guardar su partida y generar una salida en el Kárdex.
     */
    public function test_completed_sale_decreases_stock_and_creates_kardex_record(): void
    {
        [$user, $customer, $sparePart] = $this->createSaleContext();

        $sale = app(SaleService::class)->register(
            user: $user,
            data: [
                'customer_id' => $customer->id,
                'payment_method' => Sale::PAYMENT_CASH,
                'tax_rate' => 16,
                'notes' => 'Venta generada durante la prueba.',
                'items' => [
                    [
                        'spare_part_id' => $sparePart->id,
                        'quantity' => 3,
                        'unit_price' => 100,
                    ],
                ],
            ]
        );

        $this->assertTrue($sale->isCompleted());
        $this->assertSame(7, $sparePart->fresh()->stock);

        // Los importes siempre deben calcularse en el servidor.
        $this->assertSame('300.00', $sale->subtotal);
        $this->assertSame('48.00', $sale->tax);
        $this->assertSame('348.00', $sale->total);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'spare_part_id' => $sparePart->id,
            'quantity' => 3,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'sale_id' => $sale->id,
            'spare_part_id' => $sparePart->id,
            'user_id' => $user->id,
            'type' => InventoryMovement::TYPE_EXIT,
            'quantity' => 3,
            'previous_stock' => 10,
            'new_stock' => 7,
        ]);
    }

    /**
     * No debe permitirse vender más unidades de las disponibles.
     * La transacción completa debe revertirse.
     */
    public function test_sale_with_insufficient_stock_is_rejected_and_rolled_back(): void
    {
        [$user, $customer, $sparePart] = $this->createSaleContext();

        try {
            app(SaleService::class)->register(
                user: $user,
                data: [
                    'customer_id' => $customer->id,
                    'payment_method' => Sale::PAYMENT_CASH,
                    'tax_rate' => 16,
                    'items' => [
                        [
                            'spare_part_id' => $sparePart->id,
                            'quantity' => 11,
                            'unit_price' => 100,
                        ],
                    ],
                ]
            );

            $this->fail(
                'La venta debió rechazarse por inventario insuficiente.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'items',
                $exception->errors()
            );
        }

        $this->assertSame(10, $sparePart->fresh()->stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    /**
     * Cancelar una venta debe devolver las piezas al inventario
     * y generar una entrada compensatoria en el Kárdex.
     */
    public function test_cancelling_sale_restores_stock_and_creates_entry_movement(): void
    {
        [$user, $customer, $sparePart] = $this->createSaleContext();

        $service = app(SaleService::class);

        $sale = $service->register(
            user: $user,
            data: [
                'customer_id' => $customer->id,
                'payment_method' => Sale::PAYMENT_CARD,
                'payment_reference' => 'PRUEBA-123',
                'tax_rate' => 16,
                'items' => [
                    [
                        'spare_part_id' => $sparePart->id,
                        'quantity' => 4,
                        'unit_price' => 125,
                    ],
                ],
            ]
        );

        $this->assertSame(6, $sparePart->fresh()->stock);

        $cancelledSale = $service->cancel(
            sale: $sale,
            user: $user
        );

        $this->assertSame(
            Sale::STATUS_CANCELLED,
            $cancelledSale->status
        );

        $this->assertSame(
            $user->id,
            $cancelledSale->cancelled_by
        );

        $this->assertNotNull($cancelledSale->cancelled_at);
        $this->assertSame(10, $sparePart->fresh()->stock);

        $this->assertDatabaseHas('inventory_movements', [
            'sale_id' => $sale->id,
            'spare_part_id' => $sparePart->id,
            'user_id' => $user->id,
            'type' => InventoryMovement::TYPE_ENTRY,
            'quantity' => 4,
            'previous_stock' => 6,
            'new_stock' => 10,
        ]);
    }

    /**
     * Una venta no puede cancelarse dos veces porque eso
     * duplicaría la devolución de inventario.
     */
    public function test_cancelled_sale_cannot_restore_inventory_twice(): void
    {
        [$user, $customer, $sparePart] = $this->createSaleContext();

        $service = app(SaleService::class);

        $sale = $service->register(
            user: $user,
            data: [
                'customer_id' => $customer->id,
                'payment_method' => Sale::PAYMENT_TRANSFER,
                'payment_reference' => 'TRANSFERENCIA-001',
                'tax_rate' => 16,
                'items' => [
                    [
                        'spare_part_id' => $sparePart->id,
                        'quantity' => 2,
                        'unit_price' => 150,
                    ],
                ],
            ]
        );

        $service->cancel(
            sale: $sale,
            user: $user
        );

        try {
            $service->cancel(
                sale: $sale->fresh(),
                user: $user
            );

            $this->fail(
                'La venta no debe poder cancelarse dos veces.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'sale',
                $exception->errors()
            );
        }

        // El inventario debe regresar a 10 solamente una vez.
        $this->assertSame(10, $sparePart->fresh()->stock);

        // Debe existir una salida y una entrada compensatoria.
        $this->assertSame(
            2,
            InventoryMovement::query()
                ->where('sale_id', $sale->id)
                ->count()
        );
    }

    /**
     * El escáner debe localizar una refacción tanto por
     * código de barras como por SKU.
     */
    public function test_spare_part_can_be_found_by_barcode_or_sku(): void
    {
        [, , $sparePart] = $this->createSaleContext();

        $byBarcode = SparePart::query()
            ->byIdentifier('7501234567890')
            ->firstOrFail();

        $bySku = SparePart::query()
            ->byIdentifier('BOT-TEST-001')
            ->firstOrFail();

        $this->assertTrue($sparePart->is($byBarcode));
        $this->assertTrue($sparePart->is($bySku));
    }

    /**
     * Crea los registros necesarios para cada prueba.
     *
     * @return array{0: User, 1: Customer, 2: SparePart}
     */
    private function createSaleContext(): array
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'Botoneras de prueba',
            'slug' => 'botoneras-prueba',
            'description' => 'Categoría utilizada para ventas.',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Cliente de prueba',
            'tax_id' => 'XAXX010101000',
            'phone' => '5555555555',
            'email' => 'cliente@example.com',
            'address' => 'Ciudad de México',
            'notes' => 'Cliente generado por las pruebas.',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $sparePart = SparePart::query()->create([
            'category_id' => $category->id,
            'sku' => 'BOT-TEST-001',
            'barcode' => '7501234567890',
            'name' => 'Botón de llamada de prueba',
            'description' => 'Refacción utilizada en pruebas.',
            'brand' => 'Marca de prueba',
            'stock' => 10,
            'minimum_stock' => 2,
            'location' => 'A-01',
            'cost' => 60,
            'is_active' => true,
        ]);

        return [$user, $customer, $sparePart];
    }
}
