<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Models\VenderLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ABM de los "Diseños de Vender" (misión diseno-vender-configurable, 28/9/2026).
 *
 *   GET    api/vender-layout        -> los diseños del dueño (crea el predeterminado si no tiene ninguno)
 *   POST   api/vender-layout        -> crea uno
 *   GET    api/vender-layout/{id}   -> uno solo
 *   PUT    api/vender-layout/{id}   -> lo edita (solo las claves que vienen)
 *   DELETE api/vender-layout/{id}   -> lo borra (nunca el que está en uso)
 *
 * El contrato de las respuestas es el del resto de los ABM: `{'models': [...]}` en el index y
 * `{'model': {...}}` en el resto, porque el SPA lo consume con `__base_store`
 * (`src/store/vender_layout.js`) y `routeString('vender_layout')` arma la URL `vender-layout`.
 * Cada modelo viaja con `id, user_id, name, layout (objeto|null), en_uso (bool), created_at,
 * updated_at`.
 *
 * Los diseños son del DUEÑO (`$this->userId()`): un empleado ve, crea y edita los de su negocio,
 * porque el diseño en uso es uno solo para todos (decisión de Lucas, 28/9/2026).
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL INVARIANTE "EXACTAMENTE UN DISEÑO EN USO POR DUEÑO" VIVE ACÁ, NO EN LA BASE
 * -------------------------------------------------------------------------------------------
 *
 * No hay unique que lo sostenga (ver la migración): un unique sobre (user_id, en_uso) prohibiría
 * también tener dos diseños apagados, que es el caso normal. Lo sostienen tres reglas, y cada una
 * corre en la MISMA transacción que la escritura y con el candado de la fila del dueño tomado
 * (`VenderLayoutHelper::bloquear_dueno()`), así dos pedidos cruzados del mismo negocio no lo pueden
 * romper:
 *
 *   - Al poner uno en uso se apagan todos los demás (`VenderLayoutHelper::poner_en_uso()`).
 *   - El primero que se crea queda en uso aunque no lo pidan (si no hay ninguno en uso).
 *   - El que está en uso no se apaga ni se borra: se cambia poniendo OTRO en uso. Un
 *     `en_uso = false` sobre él se ignora y un DELETE responde 422.
 *
 * Si igual el SPA llegara a ver dos en uso (datos tocados a mano), `diseno_en_uso()` desempata por
 * el `updated_at` más nuevo; y si no ve ninguno, usa el diseño predeterminado del sistema.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class VenderLayoutController extends Controller
{
    /** Mensaje del 404 cuando el id pedido no es de este negocio. */
    const MENSAJE_NO_ENCONTRADO = 'No se encontró el diseño de Vender.';

    /** Mensaje del 422 cuando el layout no tiene la forma del §3 del plan (lo lee el SPA tal cual). */
    const MENSAJE_LAYOUT_INVALIDO = 'El diseño no tiene un formato válido.';

    /** Mensaje del 422 al querer borrar el diseño en uso (lo muestra el ABM tal cual). */
    const MENSAJE_BORRAR_EN_USO = 'No se puede eliminar el diseño en uso. Poné otro diseño en uso y después eliminá este.';

    /** Mensaje del 422 cuando el nombre falta o queda vacío después del trim. */
    const MENSAJE_SIN_NOMBRE = 'El diseño tiene que tener un nombre.';

    /** Mensaje del 422 cuando el nombre no entra en la columna. */
    const MENSAJE_NOMBRE_LARGO = 'El nombre del diseño no puede tener más de 120 caracteres.';

    /** Largo máximo del nombre: el de la columna `vender_layouts.name` (string 120). */
    const LARGO_MAXIMO_DEL_NOMBRE = 120;

    /*
     * Resultados posibles del borrado, que se decide adentro de la transacción (ver destroy()).
     */
    const BORRADO_OK = 'borrado';
    const BORRADO_NO_ENCONTRADO = 'no_encontrado';
    const BORRADO_EN_USO = 'en_uso';

    /**
     * Los diseños del dueño, del más viejo al más nuevo.
     *
     * Si el dueño no tiene NINGUNO (la primera vez que el negocio entra después del deploy, o un
     * negocio que no pasó por el seeder), antes de listar le crea el "Diseño predeterminado" (layout
     * null, en uso). El `exists()` de afuera es para no abrir una transacción con candado en cada
     * inicio de sesión: el helper vuelve a preguntar adentro, ya con el candado tomado, y ahí se
     * decide de verdad.
     *
     * 🔴 SIN PARÁMETROS Y SIN LEER `request()`: son dos de las cuatro condiciones para estar en la
     * whitelist de `RecursosInicialesController`, que lo llama directo, sin pasar por el router, al
     * iniciar sesión. Las otras dos (no pagina, devuelve la clave `models`) también se cumplen.
     *
     * El predeterminado recién creado NO se notifica a las otras sesiones, a propósito: una sesión
     * que todavía no lo tiene en su store usa el diseño predeterminado del sistema, que es
     * exactamente lo que ese diseño (layout null) significa. No hay nada que avisarle.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $owner_id = $this->userId();

        if (!VenderLayout::where('user_id', $owner_id)->exists()) {
            VenderLayoutHelper::crear_predeterminado_si_no_tiene($owner_id);
        }

        $models = VenderLayout::where('user_id', $owner_id)
                                ->orderBy('id')
                                ->withAll()
                                ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un diseño.
     *
     * Body: `{name, layout, en_uso}`.
     *   - `name`: obligatorio (se recorta, hasta 120 caracteres) -> 422 si falta.
     *   - `layout`: se normaliza (`VenderLayoutHelper::normalizar_layout()`) -> 422 si no tiene un
     *     formato válido. `null`, o no mandarlo, deja el diseño predeterminado del sistema.
     *   - `en_uso`: si viene verdadero, o si el dueño no tiene NINGÚN diseño en uso, el nuevo queda
     *     en uso y se apagan los demás, en la misma transacción que el alta.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $nombre = $this->nombre_del_request($request);

        $error = $this->error_del_nombre($nombre);

        if (!is_null($error)) {
            return response()->json($error, 422);
        }

        list($layout_valido, $layout) = VenderLayoutHelper::normalizar_layout($request->input('layout'));

        if (!$layout_valido) {
            return response()->json(['message' => self::MENSAJE_LAYOUT_INVALIDO], 422);
        }

        $pidio_en_uso = $this->booleano($request->input('en_uso'));

        $owner_id = $this->userId();

        $apagados = array();

        /*
         * El candado va antes del "¿hay alguno en uso?": sin él, dos altas simultáneas del mismo
         * negocio sin ninguno en uso verían las dos "no hay" y quedarían las dos en uso.
         */
        $model = DB::transaction(function () use ($owner_id, $nombre, $layout, $pidio_en_uso, &$apagados) {

            VenderLayoutHelper::bloquear_dueno($owner_id);

            $hay_uno_en_uso = VenderLayout::where('user_id', $owner_id)
                                            ->where('en_uso', 1)
                                            ->exists();

            $queda_en_uso = $pidio_en_uso || !$hay_uno_en_uso;

            $model = VenderLayout::create([
                'user_id' => $owner_id,
                'name'    => $nombre,
                'layout'  => $layout,
                'en_uso'  => $queda_en_uso,
            ]);

            if ($queda_en_uso) {
                $apagados = VenderLayoutHelper::poner_en_uso($model);
            }

            return $model;
        });

        $this->notificar_guardado($model->id, $apagados);

        return response()->json(['model' => $this->fullModel('VenderLayout', $model->id)], 201);
    }

    /**
     * Un diseño, solo si es de este negocio.
     *
     * @param  mixed  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $model = VenderLayoutHelper::diseno_del_dueno($id, $this->userId());

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        return response()->json(['model' => $this->fullModel('VenderLayout', $model->id)], 200);
    }

    /**
     * Edita un diseño. Solo toca las claves que vienen en el request (`$request->has()`), así el
     * editor puede mandar un cambio parcial —por ejemplo `{en_uso: true}` desde "Usar este diseño"—
     * sin pisar el resto:
     *
     *   - `name`: si viene, no puede quedar vacío (422) ni pasar de 120 caracteres.
     *   - `layout`: si viene, se normaliza (422 si es inválido). `null` explícito es válido y vuelve
     *     el diseño al predeterminado del sistema.
     *   - `en_uso: true`: queda en uso y se apagan los demás (misma transacción).
     *   - `en_uso: false`: SE IGNORA siempre. Sobre el que está en uso, porque tiene que haber uno
     *     en uso y se cambia poniendo otro; sobre uno apagado, porque ya está apagado.
     *
     * `has()` y no `input()`: `has()` pregunta si la CLAVE vino, y es lo único que distingue "no
     * me mandaste el layout" (no se toca) de "me mandaste layout null" (volver al predeterminado).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed                     $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $owner_id = $this->userId();

        // El 404 va antes que las validaciones: sobre un diseño ajeno no se le dice nada del body.
        if (is_null(VenderLayoutHelper::diseno_del_dueno($id, $owner_id))) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        $cambios = array();

        if ($request->has('name')) {
            $nombre = $this->nombre_del_request($request);

            $error = $this->error_del_nombre($nombre);

            if (!is_null($error)) {
                return response()->json($error, 422);
            }

            $cambios['name'] = $nombre;
        }

        if ($request->has('layout')) {
            list($layout_valido, $layout) = VenderLayoutHelper::normalizar_layout($request->input('layout'));

            if (!$layout_valido) {
                return response()->json(['message' => self::MENSAJE_LAYOUT_INVALIDO], 422);
            }

            $cambios['layout'] = $layout;
        }

        $pidio_en_uso = $request->has('en_uso') && $this->booleano($request->input('en_uso'));

        $apagados = array();

        $model = DB::transaction(function () use ($id, $owner_id, $cambios, $pidio_en_uso, &$apagados) {

            VenderLayoutHelper::bloquear_dueno($owner_id);

            /*
             * Se vuelve a leer con el candado tomado: entre el chequeo de arriba y ahora otro pedido
             * del mismo negocio pudo borrarlo. Guardar sobre el modelo leído antes no daría error
             * (el UPDATE no tocaría ninguna fila) pero `poner_en_uso()` apagaría todos los demás y
             * el negocio quedaría sin ningún diseño en uso.
             */
            $model = VenderLayoutHelper::diseno_del_dueno($id, $owner_id);

            if (is_null($model)) {
                return null;
            }

            foreach ($cambios as $columna => $valor) {
                $model->{$columna} = $valor;
            }

            $model->save();

            if ($pidio_en_uso) {
                $apagados = VenderLayoutHelper::poner_en_uso($model);
            }

            return $model;
        });

        if (is_null($model)) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        $this->notificar_guardado($model->id, $apagados);

        return response()->json(['model' => $this->fullModel('VenderLayout', $model->id)], 200);
    }

    /**
     * Borra un diseño. El que está en uso no se borra (422): tiene que haber siempre uno en uso, y
     * se cambia poniendo otro en uso primero.
     *
     * Todo se decide adentro de la transacción y con el candado del dueño tomado: si el "¿está en
     * uso?" se mirara afuera, otro pedido podría ponerlo en uso justo antes del DELETE y el negocio
     * quedaría sin ninguno.
     *
     * @param  mixed  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $owner_id = $this->userId();

        $id_borrado = null;

        $resultado = DB::transaction(function () use ($id, $owner_id, &$id_borrado) {

            VenderLayoutHelper::bloquear_dueno($owner_id);

            $model = VenderLayoutHelper::diseno_del_dueno($id, $owner_id);

            if (is_null($model)) {
                return self::BORRADO_NO_ENCONTRADO;
            }

            if ($model->en_uso) {
                return self::BORRADO_EN_USO;
            }

            $id_borrado = $model->id;

            $model->delete();

            return self::BORRADO_OK;
        });

        if ($resultado === self::BORRADO_NO_ENCONTRADO) {
            return response()->json(['message' => self::MENSAJE_NO_ENCONTRADO], 404);
        }

        if ($resultado === self::BORRADO_EN_USO) {
            return response()->json(['message' => self::MENSAJE_BORRAR_EN_USO], 422);
        }

        $this->sendDeleteModelNotification('VenderLayout', $id_borrado);

        return response(null);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Privados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El `name` del request, recortado. Null si no vino o no es un texto (o un número, que se toma
     * como texto: "2026" es un nombre posible).
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
     * El cuerpo del 422 si el nombre no sirve, o null si está bien.
     *
     * El largo se cuenta en caracteres (`mb_strlen`), no en bytes: la columna es utf8mb4 y cuenta
     * caracteres, y "Diseño" tiene 6 aunque ocupe 7 bytes. Sin este chequeo, un nombre largo no
     * llegaría como 422 sino como un 500 del MySQL estricto ("Data too long").
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

    /**
     * Un valor del request pasado a booleano. El SPA manda `true`/`false`, pero un checkbox puede
     * llegar como `1`, `'1'`, `'true'` o no llegar: `FILTER_VALIDATE_BOOLEAN` es el mismo criterio
     * que usan los otros controllers del repo para los checkbox del ABM.
     *
     * @param  mixed  $valor
     * @return bool
     */
    private function booleano($valor)
    {
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Avisa a las otras sesiones del negocio que cambió el diseño guardado Y cada diseño que se
     * apagó: el "en uso" cambia en varias filas a la vez, y una sesión que se enterara solo del
     * guardado quedaría viendo dos diseños en uso.
     *
     * @param  int    $id        El diseño que se guardó.
     * @param  array  $apagados  Los ids que `VenderLayoutHelper::poner_en_uso()` apagó.
     * @return void
     */
    private function notificar_guardado($id, array $apagados)
    {
        $this->sendAddModelNotification('VenderLayout', $id);

        foreach ($apagados as $id_apagado) {
            $this->sendAddModelNotification('VenderLayout', $id_apagado);
        }
    }
}
