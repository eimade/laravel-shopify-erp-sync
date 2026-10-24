<?php

return [
    'shopify' => [
        // "fake" : boutique simulée (démo hors-ligne, tests) ; "graphql" : Admin API réelle.
        'driver' => env('SHOPIFY_DRIVER', 'fake'),
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN', 'synchub-demo.myshopify.com'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-07'),
        'location_id' => env('SHOPIFY_LOCATION_ID', ''),
        // Secret de signature des webhooks (HMAC-SHA256, en-tête X-Shopify-Hmac-Sha256).
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
        // Marge de points de coût conservée dans le seau GraphQL.
        'throttle_buffer' => (int) env('SHOPIFY_THROTTLE_BUFFER', 100),
    ],

    'erp' => [
        // Jeton attendu dans l'en-tête X-Erp-Token pour l'API REST du mini-ERP.
        'api_token' => env('ERP_API_TOKEN', ''),
    ],

    // Destinataire des alertes de dead-letter.
    'alert_email' => env('SYNCHUB_ALERT_EMAIL', 'ops@example.com'),
];
