<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $data['invoice']['code'] ?? '' }}</title>
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10.5px;
            color: #1a1a1a;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        .wrap { position: relative; }
        .watermark {
            position: fixed;
            top: 38%;
            left: 8%;
            right: 8%;
            text-align: center;
            font-size: 42px;
            font-weight: bold;
            color: rgba(200, 200, 200, 0.35);
            transform: rotate(-28deg);
            z-index: 0;
            pointer-events: none;
        }
        .content { position: relative; z-index: 1; }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 14px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
        }
        .header-left, .header-right { display: table-cell; vertical-align: top; }
        .header-left { width: 32%; }
        .header-right { width: 68%; text-align: right; padding-left: 12px; }
        .logo { max-height: 56px; max-width: 180px; }
        .issuer-name { font-size: 15px; font-weight: bold; color: #0f172a; margin: 0 0 4px; }
        .issuer-line { margin: 0; color: #334155; font-size: 9.5px; }
        .doc-title {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 0.03em;
            color: #0f172a;
            margin: 12px 0 8px;
        }
        .meta-grid {
            width: 100%;
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px 10px;
            background: #f8fafc;
        }
        .meta-row { margin: 2px 0; font-size: 10px; }
        .meta-row strong { color: #475569; min-width: 110px; display: inline-block; }
        .boxes { width: 100%; margin-bottom: 12px; }
        .box {
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px 10px;
            background: #fff;
            margin-bottom: 8px;
        }
        .box h3 {
            margin: 0 0 6px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .box p { margin: 3px 0; }
        h2 {
            font-size: 11px;
            margin: 14px 0 6px;
            color: #0f172a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 3px;
        }
        table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data th, table.data td {
            border: 1px solid #e2e8f0;
            padding: 5px 6px;
            vertical-align: top;
        }
        table.data th {
            background: #f1f5f9;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            color: #475569;
        }
        .right { text-align: right; }
        .totals { width: 42%; margin-left: auto; margin-top: 8px; border-collapse: collapse; }
        .totals td { border: none; padding: 3px 0; font-size: 10px; }
        .totals .label { text-align: right; padding-right: 10px; color: #475569; }
        .totals .sum { font-weight: bold; font-size: 11px; }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 9px;
            color: #64748b;
        }
        .footer .thanks { font-weight: bold; color: #334155; margin-bottom: 4px; }
        .muted { color: #64748b; font-size: 9px; }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
            background: #e0e7ff;
            color: #3730a3;
        }
    </style>
</head>
<body>
<div class="wrap">
    @if(!empty($data['document']['is_preview']))
        <div class="watermark">VISTA PREVIA</div>
    @endif
    <div class="content">
        <div class="header">
            <div class="header-left">
                @if(!empty($data['issuer']['logo_data_uri']))
                    <img class="logo" src="{{ $data['issuer']['logo_data_uri'] }}" alt="Logo" />
                @endif
            </div>
            <div class="header-right">
                <p class="issuer-name">{{ $data['issuer']['nombre'] ?? '' }}</p>
                @if(!empty($data['issuer']['nit']))
                    <p class="issuer-line">NIT: {{ $data['issuer']['nit'] }}</p>
                @endif
                @if(!empty($data['issuer']['direccion']))
                    <p class="issuer-line">{{ $data['issuer']['direccion'] }}</p>
                @endif
                @if(!empty($data['issuer']['telefono']))
                    <p class="issuer-line">Tel: {{ $data['issuer']['telefono'] }}</p>
                @endif
                @if(!empty($data['issuer']['correo']))
                    <p class="issuer-line">{{ $data['issuer']['correo'] }}</p>
                @endif
            </div>
        </div>

        <p class="doc-title">FACTURA {{ $data['invoice']['number'] ?? '' }}</p>

        <div class="meta-grid">
            <div class="meta-row"><strong>Fecha de emisión:</strong> {{ $data['invoice']['issued_at_label'] ?? '—' }}</div>
            <div class="meta-row"><strong>Periodo facturado:</strong> {{ $data['invoice']['period_label'] ?? '' }}</div>
            @if(!empty($data['invoice']['sent_at']))
                <div class="meta-row"><strong>Fecha de envío:</strong> {{ \Carbon\Carbon::parse($data['invoice']['sent_at'])->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
            @endif
            <div class="meta-row"><strong>Estado:</strong> <span class="tag">{{ $data['invoice']['status_label'] ?? '' }}</span></div>
        </div>

        <div class="boxes">
            <div class="box">
                <h3>Cliente</h3>
                <p><strong>{{ $data['company']['nombre'] ?? '—' }}</strong></p>
                @if(!empty($data['company']['nit']))
                    <p>NIT: {{ $data['company']['nit'] }}</p>
                @endif
                @if(!empty($data['company']['contact']))
                    <p>Contacto / obra: {{ $data['company']['contact'] }}</p>
                @endif
                @if(!empty($data['company']['telefono']))
                    <p>Tel: {{ $data['company']['telefono'] }}</p>
                @endif
                @if(!empty($data['company']['correo']))
                    <p>Correo: {{ $data['company']['correo'] }}</p>
                @endif
            </div>
        </div>

        <h2>Servicios</h2>
        <table class="data">
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Código</th>
                <th>Descripción</th>
                <th>Tipo</th>
                <th>Técnico</th>
                <th class="right">Valor</th>
            </tr>
            </thead>
            <tbody>
            @foreach($data['services'] ?? [] as $row)
                <tr>
                    <td>{{ $row['service_date'] ?? '' }}</td>
                    <td>{{ $row['code'] ?? '' }}</td>
                    <td>{{ $row['description'] ?? '' }}</td>
                    <td>{{ $row['service_type'] ?? '—' }}</td>
                    <td>{{ $row['technician_name'] ?? '—' }}</td>
                    <td class="right">{{ number_format((float)($row['amount'] ?? 0), 2, ',', '.') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if(!empty($data['products']) && count($data['products']) > 0)
            <h2>Productos</h2>
            <table class="data">
                <thead>
                <tr>
                    <th>Nombre</th>
                    <th class="right">Cantidad</th>
                    <th class="right">Precio unit.</th>
                    <th class="right">Subtotal</th>
                </tr>
                </thead>
                <tbody>
                @foreach($data['products'] as $pr)
                    <tr>
                        <td>{{ $pr['name'] ?? '' }}</td>
                        <td class="right">{{ $pr['qty'] ?? '' }}</td>
                        <td class="right">{{ number_format((float)($pr['unit_price'] ?? 0), 2, ',', '.') }}</td>
                        <td class="right">{{ number_format((float)($pr['line_total'] ?? 0), 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <h2>Resumen financiero</h2>
        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="right">{{ number_format((float)($data['financial']['subtotal'] ?? 0), 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Total</td>
                <td class="right sum">{{ number_format((float)($data['financial']['total'] ?? 0), 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Total pagado</td>
                <td class="right">{{ number_format((float)($data['financial']['total_paid'] ?? 0), 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Saldo pendiente</td>
                <td class="right sum">{{ number_format((float)($data['financial']['balance'] ?? 0), 2, ',', '.') }}</td>
            </tr>
        </table>

        <h2>Pagos registrados</h2>
        <table class="data">
            <thead>
            <tr>
                <th>Fecha</th>
                <th class="right">Monto</th>
                <th>Método</th>
                <th>Notas</th>
            </tr>
            </thead>
            <tbody>
            @forelse($data['payments'] ?? [] as $p)
                <tr>
                    <td>{{ $p['payment_date'] ?? '' }}</td>
                    <td class="right">{{ number_format((float)($p['amount'] ?? 0), 2, ',', '.') }}</td>
                    <td>{{ $p['method'] ?? '' }}</td>
                    <td>{{ $p['notes'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Sin pagos registrados.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="footer">
            @if(!empty($data['footer']['message']))
                <p class="thanks">{{ $data['footer']['message'] }}</p>
            @endif
            @if(!empty($data['footer']['payment_terms']))
                <p><strong>Condiciones de pago:</strong> {{ $data['footer']['payment_terms'] }}</p>
            @endif
            <p class="muted">Documento generado electrónicamente. Los valores están expresados en moneda local salvo indicación contraria.</p>
        </div>
    </div>
</div>
</body>
</html>
