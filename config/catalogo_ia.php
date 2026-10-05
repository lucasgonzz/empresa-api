<?php

/**
 * Categorización con IA en tres modelos (misión categorizacion-tres-modelos, 5/10/2026).
 *
 * Todo se lee con `config('catalogo_ia.*')`. Nunca con `env()` fuera de este archivo: con
 * `php artisan config:cache` un `env()` suelto devuelve null.
 */
return [

    /*
     * Tope de artículos vivos del dueño sobre los que se puede armar una corrida de categorías. Las
     * corridas son de implementación (un catálogo recién cargado) y las clasifica Claude por lotes; un
     * catálogo de cientos de miles de artículos no es el caso. Por encima del tope, la ingesta
     * responde 422 `catalogo_muy_grande` y la corrida no se crea.
     * Variable de entorno: CATALOGO_IA_TOPE_ARTICULOS.
     */
    'tope_articulos' => (int) env('CATALOGO_IA_TOPE_ARTICULOS', 10000),

    /*
     * Topes del contrato con la skill (admin-sync/catalogo/*). Son de forma, no de negocio: acotan
     * cuánto entra en un pedido y cuánto puede crecer una propuesta.
     */
    'articulos_por_pagina_maximo' => 1000,
    'asignaciones_por_pedido_maximo' => 500,
    'propuestas_por_corrida_maximo' => 4,
    'nodos_por_propuesta_maximo' => 400,
    'largo_maximo_de_nombre' => 128,

    /*
     * Cuántos ids de ítems acepta un pedido de aprobar o rechazar en lote, y de a cuántos artículos
     * se escribe `articles` al aplicar o deshacer una elección.
     */
    'ids_por_lote_de_revision_maximo' => 500,
    'articulos_por_lote_de_escritura' => 1000,
];
