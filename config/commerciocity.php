<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marca ComercioCity (correos transaccionales)
    |--------------------------------------------------------------------------
    */

    'brand_name' => env('COMMERCIOCITY_BRAND_NAME', 'ComercioCity'),

    /** URL absoluta del logo para <img> en el correo (recomendado HTTPS). */
    'logo_url' => env('COMMERCIOCITY_LOGO_URL', ''),

    /** Color principal del header (franja superior), formato CSS. */
    'header_background' => env('COMMERCIOCITY_HEADER_BG', '#0068D4'),

    /** Enlaces del pie (URLs completas). */
    'website_url' => env('COMMERCIOCITY_WEBSITE_URL', 'https://commerciocity.com'),
    'instagram_url' => env('COMMERCIOCITY_INSTAGRAM_URL', 'https://instagram.com/commerciocity'),

    /** Texto del enlace a la web en el footer. */
    'website_label' => env('COMMERCIOCITY_WEBSITE_LABEL', 'Sitio web'),

    /** Texto del enlace a Instagram en el footer. */
    'instagram_label' => env('COMMERCIOCITY_INSTAGRAM_LABEL', 'Instagram'),

    /**
     * Texto legal opcional bajo los enlaces (p. ej. baja de suscripción).
     * Vacío = no se muestra bloque.
     */
    'footer_legal_html' => env('COMMERCIOCITY_FOOTER_LEGAL_HTML', ''),
];
