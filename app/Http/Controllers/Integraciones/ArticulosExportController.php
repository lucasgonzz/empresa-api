<?php

namespace App\Http\Controllers\Integraciones;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint publico (sin auth) de export de articulos para flujos automatizados externos
 * (n8n). El comercio se identifica por `articles_export_key` en el path, que ademas
 * resuelve el `user_id` a usar en todas las queries (nunca se acepta un user_id por
 * parametro). Soporta paginacion por cursor (keyset) sobre `(updated_at, id)` y filtro
 * `updated_since` para exports incrementales, apoyandose en el indice
 * `articles_user_updated_id_index` creado en el prompt 01 de este grupo.
 *
 * Grupo 211, prompt 02.
 */
class ArticulosExportController extends Controller
{
    /**
     * Limite de articulos por pagina por default, cuando no se manda `limit`.
     *
     * @var int
     */
    const DEFAULT_LIMIT = 1000;

    /**
     * Limite maximo permitido por pagina, para evitar payloads gigantes en una sola
     * respuesta aunque el consumidor pida mas.
     *
     * @var int
     */
    const MAX_LIMIT = 5000;

    /**
     * Devuelve una pagina de articulos del comercio dueño de `$export_key`, ordenados de
     * forma ascendente por `updated_at, id` (paginacion por cursor / keyset).
     *
     * Parametros de query aceptados (todos opcionales): `updated_since`, `cursor`,
     * `limit`, `incluir_costos`, `incluir_eliminados`, `solo_activos`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $export_key  Key publica del comercio, tomada del path.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $export_key)
    {
        // Validacion minima de formato antes de tocar la base: una key ausente o
        // demasiado corta no puede ser valida (la real mide 48 caracteres).
        if (empty($export_key) || strlen($export_key) < 20) {
            return response()->json(['error' => 'no encontrado'], 404);
        }

        // Resolucion del comercio a partir de la key. El user_id de esta fila es el unico
        // que se usa para filtrar articulos: nunca se acepta un user_id por parametro.
        $user = User::where('articles_export_key', $export_key)->first();

        if (is_null($user)) {
            // 404 generico a proposito: con el mismo cuerpo que una key mal formada, quien
            // prueba keys al azar no puede distinguir una key valida de una invalida.
            return response()->json(['error' => 'no encontrado'], 404);
        }

        // Parseo de `updated_since`. Acepta 'Y-m-d', 'Y-m-d H:i:s' e ISO 8601 con offset,
        // todo lo que Carbon::parse() entiende. Si no se puede parsear, 400 sin ejecutar
        // ninguna query.
        $updated_since = null;
        if ($request->filled('updated_since')) {
            try {
                $updated_since = Carbon::parse($request->query('updated_since'));
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'updated_since invalido',
                    'formato_esperado' => 'YYYY-MM-DD HH:MM:SS o ISO 8601',
                ], 400);
            }
        }

        // Decodificacion del cursor opaco (si vino). Formato: base64("Y-m-d H:i:s|id").
        // Cualquier cursor manipulado o con formato invalido responde 400 sin consultar.
        $cursor_updated_at = null;
        $cursor_id = null;
        if ($request->filled('cursor')) {
            $decoded = base64_decode($request->query('cursor'), true);
            $partes = $decoded === false ? [] : explode('|', $decoded);

            // Exactamente 2 partes y la segunda (id) tiene que ser numerica.
            if (count($partes) !== 2 || !is_numeric($partes[1])) {
                return response()->json(['error' => 'cursor invalido'], 400);
            }

            // La primera parte tiene que ser una fecha valida.
            try {
                $cursor_updated_at = Carbon::parse($partes[0]);
            } catch (\Exception $e) {
                return response()->json(['error' => 'cursor invalido'], 400);
            }

            $cursor_id = (int) $partes[1];
        }

        // Limite de pagina: se recorta silenciosamente al rango permitido, nunca error.
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        // Flags booleanos: se aceptan como '1' en query string.
        $incluir_costos = $request->query('incluir_costos', '0') == '1';
        $incluir_eliminados = $request->query('incluir_eliminados', '1') == '1';
        $solo_activos = $request->query('solo_activos', '0') == '1';

        // Armado de la query base, siempre con withTrashed() para poder incluir
        // eliminados segun el flag (se filtran despues con whereNull('deleted_at')).
        $query = Article::withTrashed()
                        ->where('user_id', $user->id);

        if (!$incluir_eliminados) {
            $query->whereNull('deleted_at');
        }

        if ($solo_activos) {
            $query->where('status', 'active');
        }

        if (!is_null($updated_since)) {
            // Inclusivo a proposito: el checkpoint de la corrida anterior es el
            // updated_at exacto del ultimo articulo entregado, y se quiere volver a
            // traerlo (upsert idempotente del lado del consumidor).
            $query->where('updated_at', '>=', $updated_since);
        }

        // Keyset: (updated_at, id) estrictamente mayor al cursor. Va como grupo propio
        // para no mezclarse con los AND de arriba.
        if (!is_null($cursor_updated_at)) {
            $query->where(function ($q) use ($cursor_updated_at, $cursor_id) {
                $q->where('updated_at', '>', $cursor_updated_at)
                    ->orWhere(function ($q2) use ($cursor_updated_at, $cursor_id) {
                        $q2->where('updated_at', '=', $cursor_updated_at)
                            ->where('id', '>', $cursor_id);
                    });
            });
        }

        $query->orderBy('updated_at', 'ASC')
                ->orderBy('id', 'ASC');

        // Relaciones minimas e indispensables para el payload, con seleccion de columnas
        // para no traer la fila entera de cada tabla relacionada. No usar withAll(): esta
        // pensado para la ficha del ERP (25 relaciones), no para un export masivo.
        $query->with([
            'brand:id,name',
            'category:id,name',
            'sub_category:id,name',
            'iva:id,percentage',
            'provider:id,name',
        ]);

        // Se trae una fila de mas para saber si hay proxima pagina sin hacer un COUNT
        // aparte. Esa fila sobrante se descarta y no se serializa.
        $articles = $query->limit($limit + 1)->get();

        $hay_mas = $articles->count() > $limit;
        if ($hay_mas) {
            $articles = $articles->slice(0, $limit);
        }

        // Armado del payload plano, articulo por articulo, sin usar toArray() del modelo
        // (que arrastraria columnas sensibles y las relaciones cargadas con su forma
        // original).
        $articulos = [];
        foreach ($articles as $articulo) {
            $item = [
                'id' => (int) $articulo->id,
                'codigo_barras' => $articulo->bar_code,
                'codigo_proveedor' => $articulo->provider_code,
                'sku' => $articulo->sku,
                'plu' => $articulo->plu,
                'nombre' => $articulo->name,
                'precio_final' => is_null($articulo->final_price) ? null : (float) $articulo->final_price,
                'precio_final_blanco' => is_null($articulo->final_price_blanco) ? null : (float) $articulo->final_price_blanco,
                'precio' => is_null($articulo->price) ? null : (float) $articulo->price,
                'stock' => is_null($articulo->stock) ? null : (float) $articulo->stock,
                'stock_minimo' => is_null($articulo->stock_min) ? null : (int) $articulo->stock_min,
                'unidades_por_bulto' => $articulo->unidades_por_bulto,
                'contenido' => $articulo->contenido,
                'medida' => is_null($articulo->medida) ? null : (float) $articulo->medida,
                'marca' => !is_null($articulo->brand) ? $articulo->brand->name : null,
                'rubro' => !is_null($articulo->category) ? $articulo->category->name : null,
                'subrubro' => !is_null($articulo->sub_category) ? $articulo->sub_category->name : null,
                'proveedor' => !is_null($articulo->provider) ? $articulo->provider->name : null,
                'iva_porcentaje' => !is_null($articulo->iva) ? $articulo->iva->percentage : null,
                'status' => $articulo->status,
                'activo' => $articulo->status === 'active',
                'online' => (bool) $articulo->online,
                'eliminado' => !is_null($articulo->deleted_at),
                'actualizado_en' => $articulo->updated_at->toIso8601String(),
                'creado_en' => is_null($articulo->created_at) ? null : $articulo->created_at->toIso8601String(),
            ];

            // Costos: solo si se pidieron explicitamente. Son la estructura de costos y
            // el margen del comercio, y este endpoint no pide credenciales.
            if ($incluir_costos) {
                $item['costo'] = is_null($articulo->cost) ? null : (float) $articulo->cost;
                $item['costo_real'] = is_null($articulo->costo_real) ? null : (float) $articulo->costo_real;
                $item['porcentaje_ganancia'] = is_null($articulo->percentage_gain) ? null : (float) $articulo->percentage_gain;
            }

            $articulos[] = $item;
        }

        // next_cursor: solo si hay proxima pagina, calculado a partir del ultimo articulo
        // de esta pagina (antes de descartar la fila sobrante).
        $next_cursor = null;
        $checkpoint = null;

        if ($hay_mas) {
            $ultimo = $articles->last();
            $next_cursor = base64_encode($ultimo->updated_at->format('Y-m-d H:i:s') . '|' . $ultimo->id);
        } else {
            // Corrida completa: se completa el checkpoint con el updated_at del ultimo
            // articulo entregado, para que el consumidor lo guarde como proxima
            // updated_since. Caso borde: si no hubo ningun articulo, se usa la hora
            // actual para que el consumidor siempre pueda avanzar su marca.
            if (count($articulos) > 0) {
                $ultimo = $articles->last();
                $checkpoint = $ultimo->updated_at->toIso8601String();
            } else {
                $checkpoint = now()->toIso8601String();
            }
        }

        // Auditoria: nunca se loguea la key entera, solo los ultimos 6 caracteres.
        Log::info('ArticulosExportController@index', [
            'export_key_suffix' => substr($export_key, -6),
            'ip' => $request->ip(),
            'updated_since' => is_null($updated_since) ? null : $updated_since->toIso8601String(),
            'limit' => $limit,
            'con_cursor' => !is_null($cursor_updated_at),
            'cantidad_devuelta' => count($articulos),
        ]);

        return response()->json([
            'articulos' => $articulos,
            'paginacion' => [
                'limit' => $limit,
                'cantidad' => count($articulos),
                'hay_mas' => $hay_mas,
                'next_cursor' => $next_cursor,
            ],
            'checkpoint' => $checkpoint,
            'generado_en' => now()->toIso8601String(),
        ]);
    }
}
