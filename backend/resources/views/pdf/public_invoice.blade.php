<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $data['invoice']['code'] ?? '' }}</title>
    <style>
        @page { margin: 22px 26px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .wrap { position: relative; min-height: 100%; }
        /* absolute: DomPDF 3 puede fallar con position:fixed en algunos documentos */
        .watermark {
            position: absolute;
            top: 42%;
            left: 5%;
            right: 5%;
            text-align: center;
            font-size: 38px;
            font-weight: bold;
            color: rgba(200, 200, 200, 0.32);
            z-index: 0;
            pointer-events: none;
        }
        .content { position: relative; z-index: 1; }

        .head-main { width: 100%; border-collapse: collapse; margin-bottom: 14px; vertical-align: top; }
        .head-main td { vertical-align: top; padding: 0 6px 0 0; }
        .head-main td:last-child { padding-right: 0; }

        .issuer-row { width: 100%; border-collapse: collapse; }
        .issuer-row td { vertical-align: top; padding: 0; }

        .logo-ph {
            width: 52px;
            height: 52px;
            background: #cbd5e1;
            border-radius: 4px;
            display: inline-block;
        }
        .logo-img { max-height: 52px; max-width: 52px; border-radius: 4px; display: block; }

        .issuer-name { font-size: 14px; font-weight: bold; color: #0f172a; margin: 0 0 3px; }
        .issuer-line { margin: 0; font-size: 8.5px; color: #334155; }

        .billto-box {
            background: #e0f2fe;
            border: 1px solid #7dd3fc;
            border-radius: 8px;
            padding: 8px 10px;
            min-height: 118px;
        }
        .billto-title {
            font-size: 9px;
            font-weight: bold;
            color: #0369a1;
            letter-spacing: 0.04em;
            margin: 0 0 6px;
        }
        .billto-line { margin: 0 0 3px; font-size: 8.5px; color: #0c4a6e; }
        .billto-line strong { color: #075985; font-weight: bold; }

        .inv-side { text-align: right; }
        .inv-doc-title {
            font-size: 22px;
            font-weight: bold;
            color: #0c4a6e;
            letter-spacing: 0.02em;
            margin: 0 0 4px;
            line-height: 1.1;
        }
        .inv-number { font-size: 11px; font-weight: bold; color: #0f172a; margin: 0 0 8px; }
        .inv-meta { margin: 0 0 2px; font-size: 8px; color: #334155; }
        .inv-meta strong { color: #475569; }
        .inv-status {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 8px;
            font-weight: bold;
            background: #dbeafe;
            color: #1e40af;
        }

        .lines-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .lines-table th {
            background: #bae6fd;
            color: #0c4a6e;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 7px 6px;
            border: 1px solid #7dd3fc;
            text-align: left;
        }
        .lines-table th.num { text-align: right; }
        .lines-table th.cen { text-align: center; }
        .lines-table td {
            padding: 6px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
            font-size: 8.5px;
        }
        .lines-table td.num { text-align: right; white-space: nowrap; }
        .lines-table td.cen { text-align: center; }
        .lines-table tbody tr.row-alt { background: #f0f9ff; }
        .line-title { font-weight: bold; color: #0f172a; display: block; margin-bottom: 2px; }
        .line-detail { color: #475569; font-size: 8px; }

        .totals-wrap { width: 100%; margin-top: 12px; }
        .totals-wrap td { vertical-align: top; }
        .totals-left { width: 52%; padding-right: 12px; }
        .totals-right { width: 48%; }

        .pay-box {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            background: #f8fafc;
        }
        .pay-title { font-size: 9px; font-weight: bold; color: #0f172a; margin: 0 0 6px; }
        .pay-line { margin: 0 0 3px; font-size: 8px; color: #334155; padding-left: 2px; }

        .sum-table { width: 100%; border-collapse: collapse; }
        .sum-table td { padding: 4px 0; font-size: 9px; }
        .sum-table .lbl { text-align: right; color: #475569; padding-right: 10px; }
        .sum-table .val { text-align: right; font-weight: bold; color: #0c4a6e; }
        .sum-table .grand td { padding: 0; border: none; }
        .grand-bar {
            background: #0c4a6e;
            color: #fff;
            font-weight: bold;
            font-size: 10px;
            padding: 8px 10px;
            text-align: right;
            border-radius: 4px;
            margin-top: 6px;
        }

        .payhist { margin-top: 10px; }
        .payhist h3 { font-size: 9px; margin: 0 0 4px; color: #0f172a; }
        .payhist-table { width: 100%; border-collapse: collapse; font-size: 8px; }
        .payhist-table th, .payhist-table td { border: 1px solid #e2e8f0; padding: 4px 5px; }
        .payhist-table th { background: #f1f5f9; text-align: left; }

        .foot-note {
            margin-top: 12px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            font-size: 7.5px;
            color: #64748b;
        }
        .foot-note .thanks { font-weight: bold; color: #334155; margin-bottom: 3px; }
    </style>
</head>
<body>
@php
    $fmtCop = static function ($n): string {
        return number_format((float) $n, 0, ',', '.').' COP';
    };
@endphp
<div class="wrap">
    @if(!empty($data['document']['is_preview']))
        <div class="watermark">VISTA PREVIA</div>
    @endif
    <div class="content">
        <table class="head-main">
            <tr>
                <td style="width: 36%;">
                    <table class="issuer-row">
                        <tr>
                            <td style="width: 58px; padding-right: 8px;">
                                @if(!empty($data['issuer']['logo_data_uri']))
                                    <img class="logo-img" src="{{ $data['issuer']['logo_data_uri'] }}" alt="" />
                                @else
                                    <span class="logo-ph"></span>
                                @endif
                            </td>
                            <td>
                                <p class="issuer-name">{{ $data['issuer']['nombre'] ?? '' }}</p>
                                @if(!empty($data['issuer']['nit']))
                                    <p class="issuer-line">NIT: {{ $data['issuer']['nit'] }}</p>
                                @endif
                                @if(!empty($data['issuer']['regimen']))
                                    <p class="issuer-line">{{ $data['issuer']['regimen'] }}</p>
                                @endif
                                @if(!empty($data['issuer']['direccion']))
                                    <p class="issuer-line">{{ $data['issuer']['direccion'] }}</p>
                                @endif
                                @if(!empty($data['issuer']['telefono']))
                                    <p class="issuer-line">{{ $data['issuer']['telefono'] }}</p>
                                @endif
                                @if(!empty($data['issuer']['correo']))
                                    <p class="issuer-line">{{ $data['issuer']['correo'] }}</p>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="width: 34%;">
                    <div class="billto-box">
                        <p class="billto-title">FACTURAR A:</p>
                        <p class="billto-line"><strong>Razón social:</strong> {{ $data['company']['nombre'] ?? '—' }}</p>
                        @if(!empty($data['company']['nit']))
                            <p class="billto-line"><strong>NIT:</strong> {{ $data['company']['nit'] }}</p>
                        @endif
                        <p class="billto-line"><strong>Obra / contacto:</strong> {{ $data['company']['contact'] ?? '—' }}</p>
                        <p class="billto-line"><strong>Dirección:</strong> {{ $data['company']['direccion'] ?? '—' }}</p>
                        <p class="billto-line"><strong>Periodo facturado:</strong> {{ $data['invoice']['period_label_upper'] ?? '' }}</p>
                        <p class="billto-line"><strong>E-mail:</strong> {{ $data['company']['correo'] ?? '—' }}</p>
                    </div>
                </td>
                <td style="width: 30%;" class="inv-side">
                    <p class="inv-doc-title">FACTURA</p>
                    <p class="inv-number">N°: {{ $data['invoice']['code'] ?? '—' }}</p>
                    <p class="inv-meta"><strong>FECHA EMISIÓN:</strong> {{ $data['invoice']['issued_at_label'] ?: '—' }}</p>
                    @if(!empty($data['invoice']['issued_time_label']))
                        <p class="inv-meta"><strong>HORA:</strong> {{ $data['invoice']['issued_time_label'] }}</p>
                    @endif
                    <p class="inv-meta"><strong>PERIODO FACTURADO:</strong> {{ $data['invoice']['period_label_upper'] ?? '' }}</p>
                    @if(!empty($data['invoice']['sent_at']))
                        @php
                            try {
                                $sentLabel = \Carbon\Carbon::parse($data['invoice']['sent_at'])->timezone(config('app.timezone'))->format('d/m/y H:i');
                            } catch (\Throwable) {
                                $sentLabel = '—';
                            }
                        @endphp
                        <p class="inv-meta"><strong>ENVIADA:</strong> {{ $sentLabel }}</p>
                    @endif
                    <span class="inv-status">{{ $data['invoice']['status_label'] ?? '' }}</span>
                </td>
            </tr>
        </table>

        <table class="lines-table">
            <thead>
            <tr>
                <th style="width: 11%;">Fecha</th>
                <th>Descripción de servicio</th>
                <th class="cen" style="width: 7%;">Cant.</th>
                <th class="num" style="width: 16%;">Valor unitario</th>
                <th class="num" style="width: 16%;">Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach($data['services'] ?? [] as $row)
                <tr class="{{ $loop->iteration % 2 === 0 ? 'row-alt' : '' }}">
                    <td>{{ $row['pdf_date_label'] ?? ($row['service_date'] ?? '—') }}</td>
                    <td>
                        <span class="line-title">{{ $row['pdf_title'] ?? ($row['service_type'] ?? 'Servicio') }}</span>
                        @if(!empty($row['pdf_detail']))
                            <span class="line-detail">{{ $row['pdf_detail'] }}</span>
                        @elseif(!empty($row['description']) || !empty($row['code']))
                            <span class="line-detail">{{ trim(($row['description'] ?? '').(isset($row['code']) && $row['code'] !== '' ? ' · '.$row['code'] : '')) }}</span>
                        @endif
                    </td>
                    <td class="cen">{{ (int) ($row['pdf_qty'] ?? 1) }}</td>
                    <td class="num">{{ $fmtCop($row['pdf_unit'] ?? $row['amount'] ?? 0) }}</td>
                    <td class="num" style="color: #0c4a6e; font-weight: bold;">{{ $fmtCop($row['pdf_line_total'] ?? $row['amount'] ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if(!empty($data['products']) && count($data['products']) > 0)
            <p style="font-size:9px;font-weight:bold;margin:12px 0 4px;color:#0f172a;">Productos</p>
            <table class="lines-table">
                <thead>
                <tr>
                    <th>Producto</th>
                    <th class="cen" style="width: 10%;">Cant.</th>
                    <th class="num" style="width: 18%;">V. unitario</th>
                    <th class="num" style="width: 18%;">Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach($data['products'] as $pr)
                    <tr class="{{ $loop->iteration % 2 === 0 ? 'row-alt' : '' }}">
                        <td>{{ $pr['name'] ?? '' }}</td>
                        <td class="cen">{{ $pr['qty'] ?? '1' }}</td>
                        <td class="num">{{ $fmtCop($pr['unit_price'] ?? 0) }}</td>
                        <td class="num" style="color: #0c4a6e; font-weight: bold;">{{ $fmtCop($pr['line_total'] ?? 0) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <table class="totals-wrap">
            <tr>
                <td class="totals-left">
                    @if(!empty($data['footer']['payment_methods']) && count($data['footer']['payment_methods']) > 0)
                        <div class="pay-box">
                            <p class="pay-title">Medios de pago</p>
                            @foreach($data['footer']['payment_methods'] as $pm)
                                <p class="pay-line">• {{ $pm }}</p>
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="totals-right">
                    <table class="sum-table">
                        <tr>
                            <td class="lbl">SUBTOTAL</td>
                            <td class="val">{{ $fmtCop($data['financial']['subtotal'] ?? 0) }}</td>
                        </tr>
                        <tr>
                            <td class="lbl">{{ $data['financial']['tax_label'] ?? 'IVA (0%)' }}</td>
                            <td class="val">{{ $fmtCop($data['financial']['tax_amount'] ?? 0) }}</td>
                        </tr>
                        <tr class="grand">
                            <td colspan="2">
                                <div class="grand-bar">
                                    TOTAL A PAGAR: {{ $fmtCop($data['financial']['total'] ?? 0) }}
                                </div>
                            </td>
                        </tr>
                    </table>
                    @php
                        $paid = (float) ($data['financial']['total_paid'] ?? 0);
                        $bal = (float) ($data['financial']['balance'] ?? 0);
                    @endphp
                    @if($paid > 0 || $bal > 0)
                        <table class="sum-table" style="margin-top:8px;">
                            <tr>
                                <td class="lbl">Total pagado</td>
                                <td class="val" style="color:#334155;">{{ $fmtCop($paid) }}</td>
                            </tr>
                            <tr>
                                <td class="lbl">Saldo pendiente</td>
                                <td class="val" style="color:#b45309;">{{ $fmtCop($bal) }}</td>
                            </tr>
                        </table>
                    @endif
                </td>
            </tr>
        </table>

        <div class="payhist">
            <h3>Pagos registrados</h3>
            <table class="payhist-table">
                <thead>
                <tr>
                    <th>Fecha</th>
                    <th style="text-align:right;width:22%;">Monto</th>
                    <th>Método</th>
                    <th>Notas</th>
                </tr>
                </thead>
                <tbody>
                @forelse($data['payments'] ?? [] as $p)
                    <tr>
                        <td>{{ $p['payment_date'] ?? '' }}</td>
                        <td style="text-align:right;">{{ $fmtCop($p['amount'] ?? 0) }}</td>
                        <td>{{ $p['method'] ?? '' }}</td>
                        <td>{{ $p['notes'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="color:#64748b;">Sin pagos registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="foot-note">
            @if(!empty($data['footer']['message']))
                <p class="thanks">{{ $data['footer']['message'] }}</p>
            @endif
            @if(!empty($data['footer']['payment_terms']))
                <p><strong>Condiciones:</strong> {{ $data['footer']['payment_terms'] }}</p>
            @endif
            <p>Documento generado electrónicamente. Valores en COP.</p>
        </div>
    </div>
</div>
</body>
</html>
