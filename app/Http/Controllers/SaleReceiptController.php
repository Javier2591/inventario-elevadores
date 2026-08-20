<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SaleReceiptController extends Controller
{
    /**
     * Muestra el comprobante imprimible de una venta.
     */
    public function __invoke(Request $request, Sale $sale): View
    {
        $user = $request->user();

        /*
         * La ruta tendrá middleware administrativo, pero también
         * validamos aquí para aplicar seguridad en profundidad.
         */
        abort_unless(
            $user !== null &&
            $user->isAdmin() &&
            $user->isActive(),
            403,
            'No tienes permiso para consultar comprobantes de ventas.'
        );

        /*
         * Se cargan todas las relaciones necesarias en una sola operación
         * para evitar consultas repetidas desde la vista.
         */
        $sale->load([
            'customer',
            'user',
            'items.sparePart',
        ]);

        return view('sales.receipt', [
            'sale' => $sale,
        ]);
    }
}