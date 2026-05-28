<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Hoja de vida — {{ $lot->name }}</title>
    <style>
        @page { margin: 22px 26px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            line-height: 1.35;
            margin: 0;
            padding: 0;
        }
        h1 { font-size: 15px; margin: 0 0 6px; color: #0f172a; }
        .meta { font-size: 8px; color: #64748b; margin-bottom: 12px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info th, table.info td {
            border: 1px solid #cbd5e1;
            padding: 5px 7px;
            text-align: left;
            vertical-align: top;
        }
        table.info th { width: 28%; background: #f1f5f9; font-weight: bold; color: #334155; }
        h2 { font-size: 11px; margin: 16px 0 6px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
        table.events { width: 100%; border-collapse: collapse; font-size: 8px; }
        table.events th, table.events td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            text-align: left;
            vertical-align: top;
        }
        table.events th { background: #eef2ff; color: #3730a3; }
        .muted { color: #64748b; }
    </style>
</head>
<body>
<h1>Hoja de vida del activo</h1>
<p class="meta">
    Generado: {{ $generatedAt->format('Y-m-d H:i') }} (UTC {{ $generatedAt->format('P') }})<br/>
    @if($lot->tenantCompany)
        Empresa (custodia): {{ $lot->tenantCompany->nombre }} — NIT {{ $lot->tenantCompany->nit }}
    @endif
</p>

<table class="info">
    <tr><th>Nombre</th><td>{{ $lot->name }}</td></tr>
    <tr><th>Código interno</th><td>{{ $internalCode ?: '—' }}</td></tr>
    @if($lot->asset_type || $lot->asset_subtype)
        <tr><th>Tipo / subtipo</th><td>{{ trim(($lot->asset_type ?: '—').' · '.($lot->asset_subtype ?: '—'), ' ·') }}</td></tr>
    @endif
    @if($lot->brand || $lot->model)
        <tr><th>Marca / modelo</th><td>{{ trim(($lot->brand ?: '—').' / '.($lot->model ?: '—'), ' /') }}</td></tr>
    @endif
    @if($lot->site_label || $lot->area_label)
        <tr><th>Sede / área</th><td>{{ trim(($lot->site_label ?: '—').' · '.($lot->area_label ?: '—'), ' ·') }}</td></tr>
    @endif
    @if($lot->physical_condition)
        <tr><th>Estado físico</th><td>{{ $lot->physical_condition }}</td></tr>
    @endif
    @if($lot->warranty_until)
        <tr><th>Garantía hasta</th><td>{{ $lot->warranty_until instanceof \Carbon\Carbon ? $lot->warranty_until->format('Y-m-d') : $lot->warranty_until }}</td></tr>
    @endif
    @if($lot->purchase_date)
        <tr><th>Fecha de compra</th><td>{{ $lot->purchase_date instanceof \Carbon\Carbon ? $lot->purchase_date->format('Y-m-d') : $lot->purchase_date }}</td></tr>
    @endif
    @if($lot->custody_received_at)
        <tr><th>Ingreso a custodia</th><td>{{ $lot->custody_received_at instanceof \Carbon\Carbon ? $lot->custody_received_at->format('Y-m-d') : $lot->custody_received_at }}</td></tr>
    @endif
    @if($lot->responsible_name)
        <tr><th>Responsable (empresa)</th><td>{{ $lot->responsible_name }}{{ $lot->responsible_role ? ' — '.$lot->responsible_role : '' }}</td></tr>
    @endif
    <tr><th>Serial</th><td>{{ $lot->serial_number ?: '—' }}</td></tr>
    <tr><th>MAC</th><td>{{ $lot->mac_address ?: '—' }}</td></tr>
    <tr><th>Estado ciclo de vida</th><td>{{ $lot->lifecycle_status ?: 'activo' }}</td></tr>
    <tr><th>Stock (disp. / alq. / total)</th><td>{{ (int) $lot->quantity_available }} / {{ (int) $unitsOnRent }} / {{ (int) $lot->quantity_available + (int) $unitsOnRent }}</td></tr>
    @if(!$hidePrices)
        <tr><th>Precio unitario (referencia interna)</th><td>{{ (string) $lot->unit_price }}</td></tr>
    @endif
    <tr><th>Titular inventario</th><td>{{ $lot->owner?->nombre ?: '—' }}</td></tr>
    @if($lot->description)
        <tr><th>Descripción</th><td>{{ \Illuminate\Support\Str::limit(strip_tags($lot->description), 2000) }}</td></tr>
    @endif
</table>

<h2>Movimientos de inventario</h2>
@if($movements->isEmpty())
    <p class="muted">Sin movimientos registrados.</p>
@else
    <table class="events">
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Delta</th>
            <th>Usuario</th>
            <th>Nota</th>
        </tr>
        </thead>
        <tbody>
        @foreach($movements as $m)
            <tr>
                <td>{{ $m->created_at?->format('Y-m-d H:i') ?: '—' }}</td>
                <td>{{ $m->type }}</td>
                <td>{{ $m->quantity_delta }}</td>
                <td>{{ $m->user?->nombre ?: '—' }}</td>
                <td>{{ \Illuminate\Support\Str::limit((string) ($m->note ?? ''), 200) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if($audits->isNotEmpty())
    <h2>Auditoría (administración)</h2>
    <table class="events">
        <thead>
        <tr>
            <th>Fecha</th>
            <th>Acción</th>
            <th>Actor</th>
            <th>Detalle</th>
        </tr>
        </thead>
        <tbody>
        @foreach($audits as $ev)
            <tr>
                <td>{{ $ev->occurred_at?->format('Y-m-d H:i') ?: '—' }}</td>
                <td>@php
                    $act = (string) ($ev->action ?? '');
                    $auditActionLabel = match ($act) {
                        'create' => 'Registro del activo',
                        'import_csv_row' => 'Registro del activo (importación CSV)',
                        'update' => 'Actualización de datos',
                        'delete' => 'Eliminación del activo',
                        'lifecycle_to_reparacion' => 'Cambio a reparación',
                        'lifecycle_to_activo' => 'Reactivación',
                        'lifecycle_baja_requested' => 'Solicitud de baja',
                        'lifecycle_baja_approval_registered' => 'Aprobación parcial de baja',
                        'lifecycle_baja_approved' => 'Baja aprobada',
                        'lifecycle_baja_rejected' => 'Baja rechazada',
                        default => $act !== '' ? $act : '—',
                    };
                @endphp{{ $auditActionLabel }}</td>
                <td>{{ $ev->actor?->nombre ?: '—' }}</td>
                <td>
                    @php
                        $parts = [];
                        if (is_array($ev->before) && $ev->before !== []) {
                            $parts[] = 'antes: '.\Illuminate\Support\Str::limit(json_encode($ev->before, JSON_UNESCAPED_UNICODE), 180);
                        }
                        if (is_array($ev->after) && $ev->after !== []) {
                            $parts[] = 'después: '.\Illuminate\Support\Str::limit(json_encode($ev->after, JSON_UNESCAPED_UNICODE), 180);
                        }
                        $summary = implode(' | ', $parts);
                    @endphp
                    {{ $summary !== '' ? $summary : '—' }}
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<p class="muted" style="margin-top: 14px;">
    Documento operativo. En inventario de custodia por empresa no se incluyen importes de referencia comercial.
</p>
</body>
</html>
