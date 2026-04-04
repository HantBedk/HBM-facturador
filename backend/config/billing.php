<?php

/**
 * Datos del emisor en facturas PDF (su negocio / facturador).
 * El cliente facturado sigue siendo el registro Company vinculado a la factura.
 */
return [
    'issuer' => [
        'nombre' => env('BILLING_ISSUER_NAME', env('APP_NAME', 'Facturador')),
        'nit' => env('BILLING_ISSUER_NIT', ''),
        'direccion' => env('BILLING_ISSUER_ADDRESS', ''),
        'telefono' => env('BILLING_ISSUER_PHONE', ''),
        'correo' => env('BILLING_ISSUER_EMAIL', ''),
        /** Texto bajo el NIT en el PDF (ej. Régimen Simplificado). */
        'regimen' => env('BILLING_ISSUER_REGIMEN', ''),
    ],

    /**
     * Líneas de “Medios de pago” en el PDF (orden de aparición).
     * Puede sobreescribirse con BILLING_PDF_PAYMENT_METHODS separado por | (pipe).
     */
    'pdf_payment_methods' => array_values(array_filter(array_map(
        'trim',
        explode('|', (string) env(
            'BILLING_PDF_PAYMENT_METHODS',
            'Nequi|Cuenta bancaria|Transferencia bancaria'
        ))
    ))),

    /** Ruta relativa a `public/` (ej. `img/logo.png`) o ruta absoluta legible por PHP. */
    'logo_path' => env('BILLING_LOGO_PATH', ''),

    'footer_message' => env('BILLING_FOOTER_MESSAGE', 'Gracias por su confianza.'),

    'payment_terms' => env('BILLING_PAYMENT_TERMS', 'Pago según condiciones acordadas con el cliente.'),
];
