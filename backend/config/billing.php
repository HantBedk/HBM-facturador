<?php

/**
 * Respaldo del emisor en facturas PDF si faltan datos en Configuración → Empresa del sistema.
 * El origen principal es AppSetting (SystemOrganizationProfileService). El cliente facturado es Company.
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

    /** Ruta relativa a `public/` (por defecto `logo-HBM.png` en la raíz de `public/`). */
    'logo_path' => env('BILLING_LOGO_PATH', 'logo-HBM.png'),

    'footer_message' => env('BILLING_FOOTER_MESSAGE', 'Gracias por su confianza.'),

    'payment_terms' => env('BILLING_PAYMENT_TERMS', 'Pago según condiciones acordadas con el cliente.'),

    /**
     * Usuario propietario de los {@see Service} generados desde plantillas mensuales (servicios fijos).
     * Si es null, se usa el primer super_admin o admin activo.
     */
    'recurring_services_user_id' => env('BILLING_RECURRING_SERVICES_USER_ID') !== null && env('BILLING_RECURRING_SERVICES_USER_ID') !== ''
        ? (int) env('BILLING_RECURRING_SERVICES_USER_ID')
        : null,
];
