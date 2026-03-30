<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $data['invoice']['code'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 8px; }
        h2 { font-size: 12px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .muted { color: #555; font-size: 10px; }
        .right { text-align: right; }
        .totals td { border: none; padding: 4px 0; }
        .totals .label { text-align: right; padding-right: 12px; width: 70%; }
    </style>
</head>
<body>
    <h1>Factura {{ $data['invoice']['number'] ?? '' }}</h1>
    <p class="muted">Consulta pública — documento informativo</p>
    <p><strong>Cliente:</strong> {{ $data['company']['nombre'] ?? '' }}<br>
        <strong>NIT:</strong> {{ $data['company']['nit'] ?? '' }}<br>
        <strong>Periodo:</strong> {{ $data['invoice']['period_label'] ?? '' }}<br>
        <strong>Estado:</strong> {{ $data['invoice']['status_label'] ?? '' }}</p>

    <h2>Servicios</h2>
    <table>
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Código</th>
            <th>Descripción</th>
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
                <td>{{ $row['technician_name'] ?? '—' }}</td>
                <td class="right">{{ number_format((float)($row['amount'] ?? 0), 2, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Resumen</h2>
    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="right">{{ number_format((float)($data['financial']['subtotal'] ?? 0), 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Total</td>
            <td class="right"><strong>{{ number_format((float)($data['financial']['total'] ?? 0), 2, ',', '.') }}</strong></td>
        </tr>
        <tr>
            <td class="label">Total pagado</td>
            <td class="right">{{ number_format((float)($data['financial']['total_paid'] ?? 0), 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Saldo pendiente</td>
            <td class="right"><strong>{{ number_format((float)($data['financial']['balance'] ?? 0), 2, ',', '.') }}</strong></td>
        </tr>
    </table>

    <h2>Pagos</h2>
    <table>
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Monto</th>
            <th>Método</th>
        </tr>
        </thead>
        <tbody>
        @forelse($data['payments'] ?? [] as $p)
            <tr>
                <td>{{ $p['payment_date'] ?? '' }}</td>
                <td class="right">{{ number_format((float)($p['amount'] ?? 0), 2, ',', '.') }}</td>
                <td>{{ $p['method'] ?? '' }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Sin pagos registrados.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
