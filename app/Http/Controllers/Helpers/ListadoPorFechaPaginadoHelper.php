<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Http\Request;

/**
 * Modo paginado OPT-IN del listado "Por fecha" (`<modelo>/from-date/{desde}/{hasta?}`) de las pantallas
 * que lo piden por la query string con `per_page`.
 *
 * Nació el 22/10/2026 para Movimientos de depósito y Notas de crédito, siguiendo el contrato que Ventas
 * estrenó el 14/9/2026 (`ListadoVentasHelper::pide_paginado()` / `respuesta_paginada()`), pero sin los
 * totales ni los filtros de pantalla de Ventas: acá solo se pagina.
 *
 * 🔴 Sin `per_page` en la query string la respuesta de cada controller es BYTE A BYTE la de siempre
 * (`{ models: [...] }`): cualquier otro consumidor de ese endpoint, o una SPA que todavía no manda el
 * parámetro, no se entera de que este modo existe. Con `per_page`, `models` pasa a ser un paginador de
 * Laravel (`data`, `current_page`, `last_page`, `total`, `per_page`).
 */
class ListadoPorFechaPaginadoHelper
{
    /** Filas por página cuando `per_page` viene en cero o negativo. Mismo valor que usa la SPA por defecto. */
    const PER_PAGE_POR_DEFECTO = 50;

    /** Techo de `per_page`: lo mismo que acota el buscador general (`global-search`). */
    const PER_PAGE_MAXIMO = 200;

    /**
     * Indica si el pedido activa el modo paginado.
     *
     * Se mira la PRESENCIA del parámetro en la query string, no su valor (`per_page=0` activa el modo y
     * cae al default), igual que Ventas. Este endpoint es GET: un `per_page` en el cuerpo no cuenta.
     *
     * @param  Request $request
     * @return bool
     */
    static function pide_paginado(Request $request)
    {
        return $request->query->has('per_page');
    }

    /**
     * `per_page` saneado: menor que 1 cae al default y mayor que el techo se acota al techo.
     *
     * @param  Request $request
     * @return int
     */
    static function per_page(Request $request)
    {
        $per_page = (int) $request->query('per_page');

        if ($per_page < 1) {
            return self::PER_PAGE_POR_DEFECTO;
        }

        if ($per_page > self::PER_PAGE_MAXIMO) {
            return self::PER_PAGE_MAXIMO;
        }

        return $per_page;
    }

    /**
     * Página pedida. Se lee de la query string; menor que 1 vale 1.
     *
     * @param  Request $request
     * @return int
     */
    static function page(Request $request)
    {
        $page = (int) $request->query('page', 1);

        return $page < 1 ? 1 : $page;
    }

    /**
     * Arma la respuesta del modo paginado a partir de la query del controller ya acotada por usuario
     * y fecha, con sus `orderBy` y sus relaciones (`withAll` o el scope que corresponda).
     *
     * Agrega `id DESC` como desempate del orden por `created_at`: varias filas pueden compartir el
     * segundo exacto (se crean en lote) y sin un orden total una fila podía repetirse o saltearse al
     * pasar de página. Solo en este modo: el camino sin `per_page` no se toca.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query Query del controller (usuario + fechas + relaciones).
     * @param  Request $request
     * @return array   ['models' => LengthAwarePaginator]
     */
    static function respuesta($query, Request $request)
    {
        $paginador = $query->orderBy('id', 'DESC')
                           ->paginate(self::per_page($request), ['*'], 'page', self::page($request));

        return ['models' => $paginador];
    }
}
