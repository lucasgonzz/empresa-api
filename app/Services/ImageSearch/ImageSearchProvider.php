<?php

namespace App\Services\ImageSearch;

/**
 * Un proveedor de búsqueda de imágenes para las asignaciones inteligentes (misión
 * imagenes-catalogo-completo, 27/9/2026). Hoy hay dos: Serper (Google Imágenes por API, el
 * principal) y Google Custom Search (el de siempre, de respaldo).
 *
 * El motor (ArticleImageAssignmentEngine) no sabe de cuál se trata: pide una búsqueda y recibe
 * candidatas normalizadas, siempre con la misma forma, para poder ordenarlas y descartarlas igual.
 *
 * 🔴 Regla del conteo (la misma, contraintuitiva, de BusquedaDeImagenesEnGoogle::consumir_cuota()):
 * una búsqueda CUENTA si el proveedor respondió bien, aunque viniera vacía — el proveedor la cobró
 * igual. Una que falló (error de la API, de red, sin clave) NO cuenta. Por eso `ok` es lo que mira
 * el motor para sumar búsquedas y descontar el cupo diario, y no si trajo resultados.
 */
interface ImageSearchProvider
{
    /**
     * Nombre corto del proveedor, el que se guarda en image_assignment_runs.proveedor.
     *
     * @return string  'serper' | 'google'
     */
    public function nombre();

    /**
     * Busca imágenes para la consulta dada.
     *
     * Nunca lanza excepciones: un error de red o de la API vuelve como `ok => false` con el
     * mensaje en `error` (en castellano, listo para mostrar en el diagnóstico). Nunca incluye la
     * clave del proveedor en el mensaje.
     *
     * @param  string $consulta  El código de barras o el nombre del artículo.
     * @return array {
     *     ok:         bool,         true si el proveedor respondió bien (cuenta como búsqueda).
     *     error:      string|null,  por qué falló, si falló. Legible: un error de conexión dice "El
     *                               buscador no respondió a tiempo." / "No se pudo conectar con el
     *                               buscador.", nunca el mensaje crudo de cURL (plan §13, S2).
     *     detalle:    string|null,  opcional: el detalle técnico (sin claves) cuando `error` es un
     *                               texto genérico. Va al registro de consultas del admin y al log,
     *                               nunca a lo que ve el comercio.
     *     resultados: array,        candidatas normalizadas, en el orden del proveedor:
     *                               [url, miniatura, ancho, alto, pagina, dominio, titulo, posicion].
     *                               `ancho`/`alto` son los que informa el proveedor (null si no los informa).
     *     total:      int|null,     cantidad de resultados (o el total que informa el proveedor).
     *     http_status: int|null,    el estado HTTP que respondió el proveedor (null si no llegó a
     *                               responder: error de red, sin clave). Para el registro de consultas.
     *     duracion_ms: int|null,    cuánto tardó el proveedor (null si ni siquiera se lo llamó).
     * }
     */
    public function buscar($consulta);
}
