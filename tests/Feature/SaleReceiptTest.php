<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SparePart;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Customer $customer;

    private SparePart $sparePart;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create([
            'name' => 'Administrador de prueba',
            'email' => 'admin-recibos@example.com',
            'phone' => '5551112233',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'name' => 'Botoneras para pruebas',
            'slug' => 'botoneras-para-pruebas',
            'description' => 'Categoría utilizada en las pruebas de comprobantes.',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'name' => 'Cliente de comprobante',
            'tax_id' => 'XAXX010101000',
            'contact_name' => 'Persona de contacto',
            'phone' => '5552223344',
            'email' => 'cliente-recibo@example.com',
            'address' => 'Dirección del cliente de prueba',
            'notes' => 'Cliente creado durante la prueba.',
            'is_active' => true,
            'created_by' => $this->administrator->id,
        ]);

        $this->sparePart = SparePart::query()->create([
            'category_id' => $category->id,
            'sku' => 'REC-TEST-001',
            'barcode' => '7500000000001',
            'name' => 'Botonera de comprobante',
            'description' => 'Refacción utilizada en la prueba del comprobante.',
            'brand' => 'Marca de prueba',
            'stock' => 20,
            'minimum_stock' => 2,
            'location' => 'A-01',
            'cost' => 100,
            'is_active' => true,
        ]);

        $this->sale = app(SaleService::class)->register(
            user: $this->administrator,
            data: [
                'customer_id' => $this->customer->id,
                'sale_date' => now()->format('Y-m-d H:i:s'),
                'payment_method' => Sale::PAYMENT_TRANSFER,
                'payment_reference' => 'TRANSFERENCIA-TEST-001',
                'tax_rate' => 16,
                'notes' => 'Venta creada para probar el comprobante.',
                'items' => [
                    [
                        'spare_part_id' => $this->sparePart->id,
                        'quantity' => 2,
                        'unit_price' => 150,
                    ],
                ],
            ],
        );
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(
            route('sales.receipt', [
                'sale' => $this->sale->id,
            ])
        );

        $response->assertRedirect(route('login'));
    }

    public function test_active_administrator_can_open_sale_receipt(): void
    {
        $response = $this
            ->actingAs($this->administrator)
            ->get(
                route('sales.receipt', [
                    'sale' => $this->sale->id,
                ])
            );

        $response
            ->assertOk()
            ->assertViewIs('sales.receipt')
            ->assertViewHas(
                'sale',
                fn (Sale $sale): bool =>
                    $sale->is($this->sale)
            );
    }

    public function test_receipt_contains_sale_information(): void
    {
        $response = $this
            ->actingAs($this->administrator)
            ->get(
                route('sales.receipt', [
                    'sale' => $this->sale->id,
                ])
            );

        $response
            ->assertOk()
            ->assertSeeText($this->sale->folio)
            ->assertSeeText('Cliente de comprobante')
            ->assertSeeText('Botonera de comprobante')
            ->assertSeeText('REC-TEST-001')
            ->assertSeeText('Transferencia')
            ->assertSeeText('Administrador de prueba')
            ->assertSeeText('$348.00');
    }

    public function test_technician_cannot_open_sale_receipt(): void
    {
        $technician = User::factory()->create([
            'name' => 'Técnico de prueba',
            'email' => 'tecnico-recibos@example.com',
            'phone' => '5553334455',
            'role' => User::ROLE_TECHNICIAN,
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($technician)
            ->get(
                route('sales.receipt', [
                    'sale' => $this->sale->id,
                ])
            );

        $response->assertForbidden();
    }

    public function test_nonexistent_sale_returns_not_found(): void
    {
        $response = $this
            ->actingAs($this->administrator)
            ->get(
                route('sales.receipt', [
                    'sale' => 999999,
                ])
            );

        $response->assertNotFound();
    }
}