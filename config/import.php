<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Importación de Excel de artículos
    |--------------------------------------------------------------------------
    |
    | Grupo 291, prompt 07: antes ProcessArticleImport::handle() leía
    | ARTICLE_EXCEL_CHUNK_SIZE con env() directamente en el job (no en config/).
    | Con `config:cache` activo -- lo normal en producción -- env() fuera de
    | config/ siempre devuelve el default, así que esa variable nunca tuvo
    | efecto real aunque estuviera seteada en el .env del cliente.
    |
    */

    /** Filas por chunk al importar artículos desde Excel. */
    'excel_chunk_size' => env('ARTICLE_EXCEL_CHUNK_SIZE', 3500),

    /** Filas por chunk en entorno local (archivos de prueba chicos). */
    'excel_chunk_size_local' => env('ARTICLE_EXCEL_CHUNK_SIZE_LOCAL', 100),

];
