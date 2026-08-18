<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SparePart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaleService
{
    /**
     * Registra una venta, descuenta las existencias y genera
     * automáticamente las salidas correspondientes en el Kárdex.
     *
     * @param array<string, mixed> $data
     */
    public function register(User $user, array $data): Sale
    {
        $this->validateUser($user);

        $validated = Validator::make(
            $data,
            [
                'customer_id' => [
                    'required',
                    'integer',
                    'exists:customers,id',
                ],
                'sale_date' => [
                    'nullable',
                    'date',
                ],
                'payment_method' => [
                    'required',
                    Rule::in([
                        Sale::PAYMENT_CASH,
                        Sale::PAYMENT_CARD,
                        Sale::PAYMENT_TRANSFER,
                        Sale::PAYMENT_CREDIT,
                        Sale::PAYMENT_OTHER,
                    ]),
                ],
                'payment_reference' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'tax_rate' => [
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:100',
                ],
                'notes' => [
                    'nullable',
                    'string',
                    'max:5000',
                ],
                'items' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'items.*.spare_part_id' => [
                    'required',
                    'integer',
                    'distinct',
                    'exists:spare_parts,id',
                ],
                'items.*.quantity' => [
                    'required',
                    'integer',
                    'min:1',
                ],
                'items.*.unit_price' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
            ],
            [
                'customer_id.required' =>
                    'Debes seleccionar un cliente.',
                'customer_id.exists' =>
                    'El cliente seleccionado no existe.',
                'payment_method.required' =>
                    'Debes seleccionar un método de pago.',
                'payment_method.in' =>
                    'El método de pago seleccionado no es válido.',
                'items.required' =>
                    'Debes agregar al menos una refacción.',
                'items.min' =>
                    'Debes agregar al menos una refacción.',
                'items.*.spare_part_id.required' =>
                    'Selecciona una refacción.',
                'items.*.spare_part_id.distinct' =>
                    'Una refacción no puede aparecer dos veces.',
                'items.*.spare_part_id.exists' =>
                    'Una de las refacciones seleccionadas no existe.',
                'items.*.quantity.required' =>
                    'Indica la cantidad de la refacción.',
                'items.*.quantity.min' =>
                    'La cantidad debe ser mayor que cero.',
                'items.*.unit_price.required' =>
                    'Indica el precio de venta.',
                'items.*.unit_price.min' =>
                    'El precio no puede ser negativo.',
            ]
        )->validate();

        return DB::transaction(
            function () use ($user, $validated): Sale {
                $customer = Customer::query()
                    ->findOrFail($validated['customer_id']);

                if (! $customer->is_active) {
                    throw ValidationException::withMessages([
                        'customer_id' =>
                            'El cliente seleccionado está desactivado.',
                    ]);
                }

                $taxRate = round(
                    (float) ($validated['tax_rate'] ?? 16),
                    2
                );

                /*
                 * La venta se crea inicialmente con importes en cero.
                 * Los importes definitivos se calculan usando los datos
                 * comprobados directamente en el servidor.
                 */
                $sale = Sale::query()->create([
                    'folio' => $this->generateFolio(),
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'sale_date' => $validated['sale_date'] ?? now(),
                    'subtotal' => 0,
                    'tax_rate' => $taxRate,
                    'tax' => 0,
                    'total' => 0,
                    'payment_method' =>
                        $validated['payment_method'],
                    'payment_reference' =>
                        $validated['payment_reference'] ?? null,
                    'status' => Sale::STATUS_COMPLETED,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $subtotal = 0.0;

                foreach ($validated['items'] as $index => $itemData) {
                    /*
                     * lockForUpdate evita que dos dispositivos vendan
                     * simultáneamente la misma última existencia.
                     */
                    $sparePart = SparePart::query()
                        ->lockForUpdate()
                        ->findOrFail($itemData['spare_part_id']);

                    if (! $sparePart->is_active) {
                        throw ValidationException::withMessages([
                            "items.{$index}.spare_part_id" =>
                                "La refacción {$sparePart->name} está desactivada.",
                        ]);
                    }

                    $quantity = (int) $itemData['quantity'];

                    $unitPrice = round(
                        (float) $itemData['unit_price'],
                        2
                    );

                    $unitCost = round(
                        (float) $sparePart->cost,
                        2
                    );

                    $previousStock = (int) $sparePart->stock;

                    if (! $sparePart->hasEnoughStock($quantity)) {
                        throw ValidationException::withMessages([
                            'items' =>
                                "Existencia insuficiente de {$sparePart->name}. "
                                . "Disponibles: {$previousStock}; "
                                . "solicitadas: {$quantity}.",
                        ]);
                    }

                    $newStock = $previousStock - $quantity;

                    $itemSubtotal = round(
                        $unitPrice * $quantity,
                        2
                    );

                    /*
                     * Se guarda el costo histórico y el precio de venta.
                     * Cambios futuros en la refacción no alterarán la venta.
                     */
                    $sale->items()->create([
                        'spare_part_id' => $sparePart->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'unit_price' => $unitPrice,
                        'subtotal' => $itemSubtotal,
                    ]);

                    $sparePart->forceFill([
                        'stock' => $newStock,
                    ])->save();

                    /*
                     * Cada partida genera su propia salida en el Kárdex.
                     */
                    InventoryMovement::query()->create([
                        'spare_part_id' => $sparePart->id,
                        'user_id' => $user->id,
                        'purchase_id' => null,
                        'service_order_id' => null,
                        'sale_id' => $sale->id,
                        'type' => InventoryMovement::TYPE_EXIT,
                        'quantity' => $quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'reason' => 'Venta de refacciones',
                        'reference' => $sale->folio,
                        'notes' =>
                            "Salida generada automáticamente por la venta {$sale->folio}.",
                    ]);

                    $subtotal += $itemSubtotal;
                }

                $subtotal = round($subtotal, 2);

                $tax = round(
                    $subtotal * ($taxRate / 100),
                    2
                );

                $total = round(
                    $subtotal + $tax,
                    2
                );

                /*
                 * Los totales se actualizan únicamente después de procesar
                 * correctamente todas las partidas.
                 */
                $sale->forceFill([
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ])->save();

                return $sale
                    ->fresh()
                    ->load([
                        'customer',
                        'user',
                        'items.sparePart',
                        'inventoryMovements',
                    ]);
            },
            3
        );
    }

    /**
     * Cancela una venta completada y devuelve sus existencias.
     *
     * La venta solamente puede cancelarse una vez.
     */
    public function cancel(Sale $sale, User $user): Sale
    {
        $this->validateUser($user);

        return DB::transaction(
            function () use ($sale, $user): Sale {
                /*
                 * Se vuelve a consultar y bloquear la venta.
                 * Esto impide dos cancelaciones simultáneas.
                 */
                $lockedSale = Sale::query()
                    ->lockForUpdate()
                    ->findOrFail($sale->getKey());

                if (! $lockedSale->canBeCancelled()) {
                    throw ValidationException::withMessages([
                        'sale' =>
                            'La venta ya fue cancelada o no puede cancelarse.',
                    ]);
                }

                $items = $lockedSale
                    ->items()
                    ->get();

                if ($items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'sale' =>
                            'La venta no contiene refacciones para devolver.',
                    ]);
                }

                foreach ($items as $item) {
                    /*
                     * Cada refacción se bloquea antes de devolver
                     * las existencias al inventario.
                     */
                    $sparePart = SparePart::query()
                        ->lockForUpdate()
                        ->findOrFail($item->spare_part_id);

                    $quantity = (int) $item->quantity;
                    $previousStock = (int) $sparePart->stock;
                    $newStock = $previousStock + $quantity;

                    $sparePart->forceFill([
                        'stock' => $newStock,
                    ])->save();

                    /*
                     * La devolución se registra como una entrada
                     * relacionada con la misma venta.
                     */
                    InventoryMovement::query()->create([
                        'spare_part_id' => $sparePart->id,
                        'user_id' => $user->id,
                        'purchase_id' => null,
                        'service_order_id' => null,
                        'sale_id' => $lockedSale->id,
                        'type' => InventoryMovement::TYPE_ENTRY,
                        'quantity' => $quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'reason' => 'Cancelación de venta',
                        'reference' => $lockedSale->folio,
                        'notes' =>
                            "Entrada generada por la cancelación de la venta {$lockedSale->folio}.",
                    ]);
                }

                $lockedSale->forceFill([
                    'status' => Sale::STATUS_CANCELLED,
                    'cancelled_by' => $user->id,
                    'cancelled_at' => now(),
                ])->save();

                return $lockedSale
                    ->fresh()
                    ->load([
                        'customer',
                        'user',
                        'cancelledBy',
                        'items.sparePart',
                        'inventoryMovements',
                    ]);
            },
            3
        );
    }

    /**
     * Verifica que el usuario pueda operar en el sistema.
     */
    private function validateUser(User $user): void
    {
        if (! $user->canAccessSystem()) {
            throw ValidationException::withMessages([
                'user' =>
                    'El usuario no está autorizado para registrar operaciones.',
            ]);
        }
    }

    /**
     * Genera un folio único para el comprobante.
     *
     * Ejemplo:
     * VTA-20260818-AB12CD
     */
    private function generateFolio(): string
    {
        do {
            $folio = 'VTA-'
                . now()->format('Ymd')
                . '-'
                . Str::upper(Str::random(6));
        } while (
            Sale::query()
                ->where('folio', $folio)
                ->exists()
        );

        return $folio;
    }
}