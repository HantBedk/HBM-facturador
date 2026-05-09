<?php

return [

    /*
    | URL base del frontend donde vive /consulta-factura (enlaces en correos transaccionales).
    | Producción: https://facturacion.su-dominio.com
    */
    'frontend_public_url' => rtrim((string) env('FRONTEND_PUBLIC_URL', 'http://localhost:5173'), '/'),

];
