<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Comprobante {{ $sale->folio }}
    </title>

    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #e5e7eb;
            color: #111827;
        }

        .toolbar {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 20px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 18px;
            border: 1px solid #9ca3af;
            border-radius: 8px;
            background: #ffffff;
            color: #111827;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .button-primary {
            border-color: #2563eb;
            background: #2563eb;
            color: #ffffff;
        }

        .receipt {
            width: 100%;
            max-width: 850px;
            margin: 0 auto;
            padding: 38px;
            border: 1px solid #d1d5db;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.10);
        }

        .header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 30px;
            padding-bottom: 24px;
            border-bottom: 2px solid #111827;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand img {
            width: 72px;
            height: 72px;
            border-radius: 10px;
            object-fit: contain;
        }

        .brand h1 {
            margin: 0;
            font-size: 24px;
        }

        .brand p {
            margin: 6px 0 0;
            color: #6b7280;
        }

        .folio {
            text-align: right;
        }

        .folio h2 {
            margin: 0;
            font-size: 21px;
        }

        .folio p {
            margin: 7px 0 0;
            color: #4b5563;
        }

        .status {
            display: inline-block;
            margin-top: 10px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-size: 13px;
            font-weight: 700;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .information-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 26px;
        }

        .information-card {
            padding: 18px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
        }

        .information-card h3 {
            margin: 0 0 14px;
            font-size: 16px;
        }

        .information-card p {
            margin: 6px 0;
            color: #374151;
            line-height: 1.45;
        }

        .label {
            color: #6b7280;
            font-size: 13px;
        }

        .table-container {
            margin-top: 26px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            padding: 12px 10px;
            border-bottom: 2px solid #111827;
            background: #f3f4f6;
            font-size: 13px;
            text-align: left;
        }

        td {
            padding: 13px 10px;
            border-bottom: 1px solid #d1d5db;
            vertical-align: top;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .item-name {
            font-weight: 700;
        }

        .item-sku {
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
        }

        .summary {
            width: 330px;
            max-width: 100%;
            margin: 24px 0 0 auto;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 7px 0;
        }

        .summary-total {
            margin-top: 8px;
            padding-top: 14px;
            border-top: 2px solid #111827;
            font-size: 21px;
            font-weight: 700;
        }

        .notes {
            margin-top: 28px;
            padding: 18px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
        }

        .notes h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }

        .notes p {
            margin: 0;
            color: #374151;
            line-height: 1.5;
            white-space: pre-line;
        }

        .footer {
            margin-top: 32px;
            padding-top: 18px;
            border-top: 1px solid #d1d5db;
            color: #6b7280;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 650px) {
            body {
                padding: 0;
                background: #ffffff;
            }

            .toolbar {
                position: sticky;
                top: 0;
                z-index: 10;
                margin: 0;
                padding: 12px;
                background: #111827;
            }

            .receipt {
                padding: 22px 16px;
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            .header {
                flex-direction: column;
            }

            .folio {
                text-align: left;
            }

            .information-grid {
                grid-template-columns: 1fr;
            }

            .brand img {
                width: 58px;
                height: 58px;
            }

            .brand h1 {
                font-size: 20px;
            }
        }

        @media print {
            @page {
                size: auto;
                margin: 12mm;
            }

            body {
                padding: 0;
                background: #ffffff;
            }

            .no-print {
                display: none !important;
            }

            .receipt {
                max-width: none;
                padding: 0;
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            .information-card,
            .notes {
                break-inside: avoid;
            }

            table {
                break-inside: auto;
            }

            tr {
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    @php
        $saleDate = \Illuminate\Support\Carbon::parse(
            $sale->sale_date ?? $sale->created_at
        );

        $statusLabel = method_exists($sale, 'statusLabel')
            ? $sale->statusLabel()
            : ucfirst((string) $sale->status);

        $paymentLabel = method_exists($sale, 'paymentMethodLabel')
            ? $sale->paymentMethodLabel()
            : ucfirst((string) $sale->payment_method);

        $taxRate = (float) ($sale->tax_rate ?? 0);

        if ($taxRate > 0 && $taxRate <= 1) {
            $taxRate *= 100;
        }

        $isCancelled = $sale->status === 'cancelled';
    @endphp

    <div class="toolbar no-print">
        <a
            class="button"
            href="{{ route('sales.index') }}"
        >
            Volver a ventas
        </a>

        <button
            type="button"
            class="button button-primary"
            onclick="window.print()"
        >
            Imprimir o guardar PDF
        </button>
    </div>

    <main class="receipt">
        <header class="header">
            <div class="brand">
                <img
                    src="{{ asset('images/logo-empresa.jpeg') }}"
                    alt="Logo de la empresa"
                >

                <div>
                    <h1>Inventario de Elevadores</h1>
                    <p>Comprobante de venta de refacciones</p>
                </div>
            </div>

            <div class="folio">
                <h2>{{ $sale->folio }}</h2>

                <p>
                    {{ $saleDate->format('d/m/Y H:i') }}
                </p>

                <span
                    class="status {{ $isCancelled ? 'status-cancelled' : '' }}"
                >
                    {{ $statusLabel }}
                </span>
            </div>
        </header>

        <section class="information-grid">
            <article class="information-card">
                <h3>Datos del cliente</h3>

                <p>
                    <span class="label">Nombre</span><br>
                    <strong>
                        {{ $sale->customer?->name ?? 'Público general' }}
                    </strong>
                </p>

                @if (filled($sale->customer?->tax_id))
                    <p>
                        <span class="label">RFC</span><br>
                        {{ $sale->customer->tax_id }}
                    </p>
                @endif

                @if (filled($sale->customer?->phone))
                    <p>
                        <span class="label">Teléfono</span><br>
                        {{ $sale->customer->phone }}
                    </p>
                @endif

                @if (filled($sale->customer?->email))
                    <p>
                        <span class="label">Correo electrónico</span><br>
                        {{ $sale->customer->email }}
                    </p>
                @endif

                @if (filled($sale->customer?->address))
                    <p>
                        <span class="label">Dirección</span><br>
                        {{ $sale->customer->address }}
                    </p>
                @endif
            </article>

            <article class="information-card">
                <h3>Información de pago</h3>

                <p>
                    <span class="label">Forma de pago</span><br>
                    <strong>{{ $paymentLabel }}</strong>
                </p>

                @if (filled($sale->payment_reference))
                    <p>
                        <span class="label">Referencia</span><br>
                        {{ $sale->payment_reference }}
                    </p>
                @endif

                <p>
                    <span class="label">Registró</span><br>
                    {{ $sale->user?->name ?? 'Usuario no disponible' }}
                </p>
            </article>
        </section>

        <section class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Refacción</th>
                        <th class="number">Cantidad</th>
                        <th class="number">Precio unitario</th>
                        <th class="number">Importe</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($sale->items as $item)
                        @php
                            $itemName =
                                $item->name ??
                                $item->spare_part_name ??
                                $item->name_snapshot ??
                                $item->sparePart?->name ??
                                'Refacción';

                            $itemSku =
                                $item->sku ??
                                $item->spare_part_sku ??
                                $item->sku_snapshot ??
                                $item->sparePart?->sku ??
                                'Sin SKU';

                            $itemSubtotal = (float) (
                                $item->subtotal ??
                                (
                                    (int) $item->quantity *
                                    (float) $item->unit_price
                                )
                            );
                        @endphp

                        <tr>
                            <td>
                                <div class="item-name">
                                    {{ $itemName }}
                                </div>

                                <div class="item-sku">
                                    SKU: {{ $itemSku }}
                                </div>
                            </td>

                            <td class="number">
                                {{ number_format((int) $item->quantity) }}
                            </td>

                            <td class="number">
                                ${{ number_format((float) $item->unit_price, 2) }}
                            </td>

                            <td class="number">
                                ${{ number_format($itemSubtotal, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                No se encontraron partidas para esta venta.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="summary">
            <div class="summary-row">
                <span>Subtotal</span>

                <strong>
                    ${{ number_format((float) $sale->subtotal, 2) }}
                </strong>
            </div>

            <div class="summary-row">
                <span>
                    IVA
                    @if ($taxRate > 0)
                        ({{ number_format($taxRate, 0) }}%)
                    @endif
                </span>

                <strong>
                    ${{ number_format((float) $sale->tax, 2) }}
                </strong>
            </div>

            <div class="summary-row summary-total">
                <span>Total</span>

                <span>
                    ${{ number_format((float) $sale->total, 2) }}
                </span>
            </div>
        </section>

        @if (filled($sale->notes))
            <section class="notes">
                <h3>Notas</h3>
                <p>{{ $sale->notes }}</p>
            </section>
        @endif

        <footer class="footer">
            <p>
                Este documento es un comprobante interno de venta.
            </p>

            <p>
                Generado el
                {{ now()->format('d/m/Y H:i') }}.
            </p>
        </footer>
    </main>
</body>
</html>