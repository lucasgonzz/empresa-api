<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Models\ArticleTicketDesign;
use Illuminate\Http\Request;

/**
 * ABM de los "Diseños de etiquetas de góndola" (misión disenos-etiquetas-gondola, 29/9/2026).
 *
 *   GET    api/article-ticket-design        -> los diseños del dueño, por `position, id`
 *   POST   api/article-ticket-design        -> crea uno (sin `diseno`, arranca con el de siempre)
 *   GET    api/article-ticket-design/{id}   -> uno solo (404 si no es del dueño)
 *   PUT    api/article-ticket-design/{id}   -> lo edita (solo las claves que vienen)
 *   DELETE api/article-ticket-design/{id}   -> lo borra
 *
 * El contrato de las respuestas es el del resto de los ABM: `{'models': [...]}` en el index y
 * `{'model': {...}}` en el resto, porque el SPA lo consume con `__base_store`
 * (`src/store/article_ticket_design.js`). Cada modelo viaja con `id, user_id, name, price_type_id,
 * position, diseno (objeto), created_at, updated_at`.
 *
 * Los diseños son del DUEÑO (`$this->userId()`): un empleado ve, crea y edita los de su negocio.
 * A diferencia de Vender no hay "diseño en uso": cada diseño es una opción más del menú de
 * impresión del listado, así que no hay invariante que sostener entre filas.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class ArticleTicketDesignController extends Controller
{
    /** Mensaje del 404 cuando el id pedido no es de este negocio. */
    const MENSAJE_NO_ENCONTRADO = 'No se encontró el diseño de etiquetas.';

    /** Mensaje del 422 cuando el diseño no es un objeto. */
    const MENSAJE_DISENO_INVALIDO = 'El diseño no tiene un formato válido.';

    /** Mensaje del 422 cuando el nombre falta o queda vacío después del trim. */
    const MENSAJE_SIN_NOMBRE = 'El diseño tiene que tener un nombre.';

    /** Mensaje del 422 cuando el nombre no entra en la columna. */
    const MENSAJE_NOMBRE_LARGO = 'El nombre del diseño no puede tener más de 120 caracteres.';

    /** Largo máximo del nombre: el de la columna `article_ticket_designs.name` (string 120). */
    const LARGO_MAXIMO_DEL_NOMBRE = 120;

    /**
     * Los diseños del dueño, por `position` y después por `id`.
     *
     * 🔴 SIN PARÁMETROS Y SIN LEER `request()`: son dos de las cuatro condiciones para estar en la
     * whitelist de `RecursosInicialesController`, que lo llama directo al iniciar sesión (así el
     * menú de etiquetas del listado los tiene sin pedir nada). Las otras dos (no pagina, devuelve
     * la clave `models`) también se cumplen.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $models = ArticleTicketDesign::where('user_id', $this->userId())
                                        ->orderBy('position')
                                        ->orderBy('id')
                                        ->withAll()
                                        ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un diseño.
     *
     * Body: `{name, diseno, position}`.
     *   - `name`: obligatorio (se recorta, hasta 120 caracteres) -> 422 si falta.
     *   - `diseno`: se normaliza. Si no viene, o viene null, arranca con el diseño de siempre con
     *     `precio_final` (§3.7). Si viene y no es un objeto -> 422.
     *   - `position`: opcional; si no viene, va al final.
     *
     * `price_type_id` queda siempre en null: solo el sistema lo completa (seeder / alta de lista).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $owner_id = $this->userId();

        $nombre = $this->nombre_del_request($request);

        $error = $this->error_del_nombre($nombre);

        if (!is_null($error)) {
            return response()->json($error, 422);
        }

        $diseno_crudo = $request->input('diseno');

        if (is_null($diseno_crudo)) {
            $diseno = ArticleTicketDesignHelper::diseno_actual(null);
        } else {
            list($valido, $diseno) = ArticleTicketDesignHelper::normalizar_diseno($diseno_crudo, $owner_id);

            if (!$valido) {
                return response()->json(['message' => self::MENSAJE_DISENO_INVALIDO], 422);
            }
        }

        $position = $request->input('position');

        if (!is_numeric($position)) {
            $position = ((int) ArticleTicketDesign::where('user_id', $owner_id)->max('position')) + 1;
        }

        $model = ArticleTicketDesign::create([
            'user_id'       => $owner_id,
            'name'          => $nombre,
            'price_type_id' => null,
            'position'      => (int) $position,
            'diseno'        => $diseno,
        ]);

        $this->sendAddModelNotification('article_ticket_design', $model->id);

        return response()->json(['model' => $this->fullModel('ArticleTicketDesign', $model->id)], 201);
    }

    /**
     * Un diseño, solo si es de este negocio.
     *
     * @param  mixed  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $model = ArticleTicketDesignHelper::diseno_del_dueno($id, $this->userId());

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        return response()->json(['model' => $this->fullModel('ArticleTicketDesign', $model->id)], 200);
    }

    /**
     * Edita un diseño. Solo toca las claves que vienen en el request (`$request->has()`):
     *
     *   - `name`: si viene, no puede quedar vacío (422) ni pasar de 120 caracteres.
     *   - `diseno`: si viene, se normaliza (422 si no es un objeto). `null` explícito lo vuelve al
     *     diseño de siempre (con la lista del diseño, si el sistema lo generó para una).
     *   - `position`: si viene y es un número.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed                     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $owner_id = $this->userId();

        // El 404 va antes que las validaciones: sobre un diseño ajeno no se le dice nada del body.
        $model = ArticleTicketDesignHelper::diseno_del_dueno($id, $owner_id);

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        if ($request->has('name')) {
            $nombre = $this->nombre_del_request($request);

            $error = $this->error_del_nombre($nombre);

            if (!is_null($error)) {
                return response()->json($error, 422);
            }

            $model->name = $nombre;
        }

        if ($request->has('diseno')) {
            $diseno_crudo = $request->input('diseno');

            if (is_null($diseno_crudo)) {
                $model->diseno = ArticleTicketDesignHelper::diseno_actual($model->price_type_id);
            } else {
                list($valido, $diseno) = ArticleTicketDesignHelper::normalizar_diseno($diseno_crudo, $owner_id);

                if (!$valido) {
                    return response()->json(['message' => self::MENSAJE_DISENO_INVALIDO], 422);
                }

                $model->diseno = $diseno;
            }
        }

        if ($request->has('position') && is_numeric($request->input('position'))) {
            $model->position = (int) $request->input('position');
        }

        $model->save();

        $this->sendAddModelNotification('article_ticket_design', $model->id);

        return response()->json(['model' => $this->fullModel('ArticleTicketDesign', $model->id)], 200);
    }

    /**
     * Borra un diseño. Cualquiera se puede borrar: no hay "en uso".
     *
     * @param  mixed  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $model = ArticleTicketDesignHelper::diseno_del_dueno($id, $this->userId());

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        $id_borrado = $model->id;

        $model->delete();

        $this->sendDeleteModelNotification('article_ticket_design', $id_borrado);

        return response(null);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Privados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El `name` del request, recortado. Null si no vino o no es un texto (o un número).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    private function nombre_del_request(Request $request)
    {
        $valor = $request->input('name');

        if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
            return null;
        }

        return trim((string) $valor);
    }

    /**
     * El cuerpo del 422 si el nombre no sirve, o null si está bien. El largo se cuenta en
     * caracteres: la columna es utf8mb4.
     *
     * @param  string|null  $nombre
     * @return array|null
     */
    private function error_del_nombre($nombre)
    {
        if (is_null($nombre) || $nombre === '') {
            return ['message' => self::MENSAJE_SIN_NOMBRE];
        }

        if (mb_strlen($nombre, 'UTF-8') > self::LARGO_MAXIMO_DEL_NOMBRE) {
            return ['message' => self::MENSAJE_NOMBRE_LARGO];
        }

        return null;
    }
}
