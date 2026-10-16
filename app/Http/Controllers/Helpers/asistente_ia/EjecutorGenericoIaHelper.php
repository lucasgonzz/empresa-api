<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiMessageAction;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ejecuta una tarjeta genérica (alta, edición o baja) al confirmarla: arma un request igual al que
 * manda la SPA y llama al MISMO método del MISMO controller que atiende la pantalla (misión
 * asistente-omnisciente, 21/9/2026, bloque B). Corre adentro de la transacción de
 * EjecutorAccionesIaHelper, autenticado como la persona que confirma.
 *
 * Por qué el controller y no un helper propio: los controllers de recurso (`ProviderController`,
 * `CategoryController`, `ArticleController`...) son el único lugar donde está TODA la lógica de
 * cada alta —el correlativo con num(), las cuentas corrientes del proveedor, el precio final del
 * artículo, la sincronización de listas de precio—, y la pantalla pasa por ahí. Duplicarla en un
 * helper del asistente es la clase "el mismo invariante con dos criterios" de APRENDER_NO_PARCHEAR.md.
 *
 * Lo que este ejecutor pone alrededor, que el controller no hace:
 *
 *   - 🔴 Tenencia. `update()` y `destroy()` resuelven por `Model::find($id)` SIN filtrar por dueño
 *     (hallazgo del 16/9/2026 sobre ComboController, vale para casi todos). Acá el registro se
 *     vuelve a buscar por id Y user_id antes de llamar, y si no es del dueño no se llama.
 *   - 🔴 El payload de la edición es EL MODELO ENTERO más los cambios, no solo los cambios:
 *     `update()` reasigna todos los campos del request (`$model->phone = $request->phone`), así que
 *     mandar solo lo que cambia pisaría el resto con null. Es lo que manda la pantalla
 *     (getModelToSend() de Index.vue: `{...this.model}`, que viene de withAll()), y con withAll()
 *     viajan también las relaciones que algunos update() sincronizan desde el request
 *     (`price_types` de una categoría, `categories` de una lista de precios).
 *   - 🔴 Las fechas del modelo se mandan como están en la base. toArray() las serializa como ISO
 *     con "Z" (2026-09-21T13:26:19.000000Z para un 2026-09-21 10:26:19 guardado), y un controller
 *     que reasigna `created_at` desde el request (ExpenseController::update) la correría tres
 *     horas. La SPA no lo sufre porque su date-picker reescribe la fecha al montar el formulario.
 *   - La autenticación se verifica y no se supone: `Auth::id()` tiene que ser la persona del
 *     contexto, porque `$this->userId()` de los controllers sale de ahí. Los dos caminos que llegan
 *     acá (el clic por sanctum y la confirmación por texto con Auth::setUser) ya la garantizan.
 *   - La respuesta del controller se interpreta: un status >= 400 es un rechazo de negocio (422
 *     con su `message`), no una falla técnica.
 *   - El resultado relee la fila y compara lo pedido con lo guardado: `campos_que_no_quedaron`
 *     dice qué campo el controller normalizó o ignoró (un margen 0 que se guarda como null).
 */
class EjecutorGenericoIaHelper
{
    const MENSAJE_SIN_AUTENTICAR = 'No se pudo autenticar a la persona para ejecutar la carga.';

    const MENSAJE_NO_DISPONIBLE = 'Esta carga ya no está disponible desde el asistente. Pedímela de nuevo.';

    const MENSAJE_RECHAZO = 'La pantalla rechazó la carga.';

    const MENSAJE_CAMBIADO = 'Ese registro cambió después de armar la tarjeta. Si todavía querés el cambio, pedímelo de nuevo.';

    /**
     * Ejecuta la carga de la tarjeta por el controller de la pantalla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, entidad, id, nombre, ruta, params, campos_que_no_quedaron, campos_guardados[, cambios_aplicados]
     *                 [, precio_de_venta, precio_de_venta_texto, precios_por_lista] (los tres últimos, solo artículos)}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $entidad = isset($datos['entidad']) ? (string) $datos['entidad'] : '';
        $operacion = isset($datos['operacion']) ? (string) $datos['operacion'] : '';

        if (is_null($contexto->persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $contexto->persona->id) {

            throw new AccionIaException(500, self::MENSAJE_SIN_AUTENTICAR);
        }

        $declaracion = Catalogo::declaracion($entidad);

        if (is_null($declaracion) || !Catalogo::permite($entidad, $operacion)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_extencion($declaracion['etiqueta']));
        }

        if (!PropuestaGenericaIaHelper::puede($contexto->persona, $declaracion['entidad'], $operacion)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_permiso($declaracion['etiqueta']));
        }

        $id = isset($datos['id']) ? (int) $datos['id'] : null;

        $fila = null;

        if ($operacion !== Catalogo::OP_ALTA) {

            $fila = PropuestaGenericaIaHelper::fila_del_dueno($contexto->owner_id, $declaracion, (int) $id);

            if (is_null($fila)) {

                throw new AccionIaException(422, Str::ucfirst($declaracion['singular']).' de la tarjeta ya no existe entre '.($declaracion['genero'] === 'f' ? 'las tuyas' : 'los tuyos').'. Pedímelo de nuevo.');
            }
        }

        if ($operacion === Catalogo::OP_EDICION) {

            self::verificar_que_no_cambio($accion, $fila);
        }

        $pedidos = isset($datos['pedidos']) && is_array($datos['pedidos']) ? $datos['pedidos'] : [];

        self::verificar_relaciones($contexto, $declaracion, $pedidos);

        $payload = self::payload($declaracion, $operacion, $datos, $id);

        /*
         * 🔴 Misión alta-por-agente-margen-y-stock (29/9/2026, demo3 artículo 17320): el margen por
         * lista entra al controller como el `price_types` que manda la ficha, NO como un campo
         * propio ni escribiendo el pivote a mano. Así `ArticleController::store()` / `update()`
         * hacen lo de siempre —attach_price_types y recién después setFinalPrice— y el precio de
         * cada lista sale de la MISMA cuenta que cuando la persona tipea el margen en la ficha. Se
         * arma acá, antes de llamar, para que una lista que ya no existe corte con 422 sin dejar
         * nada escrito (en el alta y la edición no hay transacción que lo revierta: son de dos
         * etapas, ver EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS).
         */
        $margenes = self::margenes_de_la_tarjeta($declaracion, $operacion, $datos);

        if (count($margenes)) {

            if (!MargenesPorListaIaHelper::aplica($contexto->owner)) {

                throw new AccionIaException(422, MargenesPorListaIaHelper::NO_SE_CARGO.'la tarjeta trae márgenes por lista de precio y el negocio ya no trabaja con listas por artículo. Pedímelo de nuevo.');
            }

            $payload['price_types'] = MargenesPorListaIaHelper::price_types_para_el_payload(
                $contexto,
                $margenes,
                isset($payload['price_types']) && is_array($payload['price_types']) ? $payload['price_types'] : []
            );
        }

        $controller = Catalogo::controller_y_metodo($declaracion['entidad'], $operacion);

        $respuesta = self::llamar_al_controller($controller, $operacion, $payload, $id);

        /*
         * 🔴 El status >= 400 corta en las TRES operaciones (misión venta-facturada-no-se-borra,
         * 5/10/2026), como ya prometía el docblock de la clase. Hasta acá solo lo miraba el alta (por
         * id_creado()), y una baja rechazada salía como éxito: la venta facturada que destroy() se
         * negó a borrar quedaba en la tarjeta como "Venta N° X anulada". Los 4xx de los update() y
         * destroy() del catálogo cortan antes de escribir nada (revisado el 5/10/2026).
         */
        self::cuerpo_de($respuesta);

        if ($operacion === Catalogo::OP_ALTA) {

            $id = self::id_creado($contexto, $declaracion, $respuesta);
        }

        $resultado = self::resultado($contexto, $declaracion, $operacion, $id, $pedidos, $fila);

        /*
         * Misión asistente-fotos-barras-y-compras (24/9/2026): el alta de un artículo puede traer su
         * foto y su descripción en la misma tarjeta. Se hacen DESPUÉS del alta, con el artículo ya
         * creado, y si fallan el artículo no se deshace: el texto del resultado lo dice. Ver
         * AltaDeArticuloConFotoIaHelper.
         */
        if ($operacion === Catalogo::OP_ALTA && !empty($datos['extras']) && is_array($datos['extras'])) {

            $resultado = AltaDeArticuloConFotoIaHelper::completar($contexto, $resultado, $datos['extras']);
        }

        /*
         * La edición con márgenes por lista relee el pivote y dice el precio que quedó en cada lista
         * (en el alta lo hace completar(), junto con la foto y el stock). Ver el 🔴 de
         * MargenesPorListaIaHelper::verificar().
         */
        if ($operacion === Catalogo::OP_EDICION && count($margenes)) {

            $resultado = MargenesPorListaIaHelper::completar_resultado($resultado, (int) $id, $margenes);
        }

        /*
         * 🔴 Y con listas por artículo, el resultado dice el precio de TODAS las listas, no solo de
         * las nombradas (ronda de correcciones del 29/9/2026): en "directo" el `aviso` de la tarjeta
         * no le llega al modelo, y un alta sin margen quedaba con 0 % en todas y un "creado" a secas,
         * que es demo3. Va en toda alta de artículo (tenga o no extras) y en la edición con márgenes.
         * Protegido: nunca lanza.
         */
        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD
            && ($operacion === Catalogo::OP_ALTA || ($operacion === Catalogo::OP_EDICION && count($margenes)))) {

            $resultado = MargenesPorListaIaHelper::sumar_las_demas_listas($contexto, $resultado, (int) $id, $margenes, $operacion === Catalogo::OP_ALTA);
        }

        /*
         * 🔴 Y el precio de venta que quedó, releído, en toda alta y edición de un artículo (misión
         * asistente-altas-y-conversacion, 16/10/2026, D5, demo3 S11): el martillo quedó con
         * `final_price` 9.278,35 (9000 / 0,97 por el IIBB de la demo) y el asistente le dijo al
         * dueño "el precio de venta todavía no se calculó". Va en claves aparte y `texto` no cambia:
         * ConfirmacionPorTextoIaHelper::traducir() se las pasa al modelo en `detalle`.
         */
        if ($declaracion['entidad'] === AltaDeArticuloConFotoIaHelper::ENTIDAD
            && ($operacion === Catalogo::OP_ALTA || $operacion === Catalogo::OP_EDICION)) {

            $ya_dijo_las_listas = $operacion === Catalogo::OP_ALTA || count($margenes) > 0;

            $resultado = self::con_el_precio_de_venta($contexto, $resultado, (int) $id, $ya_dijo_las_listas);
        }

        return $resultado;
    }

    /**
     * Suma al resultado de un alta o edición de artículo el precio de venta que quedó guardado:
     *
     *   - `precio_de_venta`: `articles.final_price` releído (número), o null si quedó sin precio
     *     (null o 0) o si el negocio trabaja con listas de precio por artículo (ahí el precio va
     *     por lista y `articles.final_price` no es el que ve la persona).
     *   - `precio_de_venta_texto`: la frase para el modelo. Sin listas: "precio de venta $ X" (con
     *     el costo, si tiene) o "quedó SIN precio de venta (no tiene costo ni precio)". Con listas:
     *     los precios por lista, salvo que el texto ya los diga (el alta y la edición con márgenes
     *     pasan por MargenesPorListaIaHelper, que los escribe en `texto`): ahí no se duplica y la
     *     clave queda en null.
     *   - `precios_por_lista` (solo con listas y cuando el texto no los dijo): [{lista, precio}].
     *
     * Protegido: una lectura que falla deja el resultado como estaba (el artículo ya se guardó).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $resultado
     * @param  int  $article_id
     * @param  bool  $ya_dijo_las_listas
     * @return array
     */
    protected static function con_el_precio_de_venta(ContextoDeCargaIa $contexto, array $resultado, int $article_id, bool $ya_dijo_las_listas): array
    {
        try {

            $articulo = DB::table('articles')->where('id', $article_id)->first(['final_price', 'cost', 'cost_in_dollars', 'price']);

            if (is_null($articulo)) {

                return $resultado;
            }

            if (MargenesPorListaIaHelper::aplica($contexto->owner)) {

                $resultado['precio_de_venta'] = null;
                $resultado['precio_de_venta_texto'] = null;

                $precios = [];
                $partes = [];

                // Ninguna lista con margen (ni con precio fijo): se vende al costo más impuestos.
                $todas_en_cero = true;
                $hay_listas = false;

                $pivotes = [];

                foreach (DB::table('article_price_type')
                            ->where('article_id', $article_id)
                            ->get(['price_type_id', 'final_price', 'percentage', 'setear_precio_final']) as $pivote) {

                    $pivotes[(int) $pivote->price_type_id] = $pivote;
                }

                foreach (MargenesPorListaIaHelper::listas_del_dueno($contexto->owner_id) as $lista) {

                    $hay_listas = true;

                    $pivote = isset($pivotes[(int) $lista->id]) ? $pivotes[(int) $lista->id] : null;

                    $precio = !is_null($pivote) && !is_null($pivote->final_price) && (float) $pivote->final_price > 0
                        ? (float) $pivote->final_price
                        : null;

                    $margen = !is_null($pivote) && !is_null($pivote->percentage)
                        ? (float) $pivote->percentage
                        : (is_null($lista->percentage) ? 0.0 : (float) $lista->percentage);

                    if ((!is_null($pivote) && !empty($pivote->setear_precio_final)) || $margen > 0) {

                        $todas_en_cero = false;
                    }

                    $precios[] = ['lista' => (string) $lista->name, 'precio' => $precio];
                    $partes[] = $lista->name.' '.(is_null($precio) ? 'sin precio' : FormatoIaHelper::monto($precio));
                }

                $sin_margen = $hay_listas && $todas_en_cero && !is_null($articulo->cost) && (float) $articulo->cost > 0;

                $aviso_sin_margen = 'SIN margen de ganancia en ninguna lista: se vende al costo más impuestos';

                if ($ya_dijo_las_listas) {

                    // El texto ya trae los precios por lista: solo se suma el aviso del margen cero.
                    $resultado['precio_de_venta_texto'] = $sin_margen ? $aviso_sin_margen : null;

                    return $resultado;
                }

                $resultado['precios_por_lista'] = $precios;
                $resultado['precio_de_venta_texto'] = count($partes)
                    ? 'precios de venta por lista: '.implode(', ', $partes).($sin_margen ? ' ('.$aviso_sin_margen.')' : '')
                    : null;

                return $resultado;
            }

            $final = is_null($articulo->final_price) ? 0.0 : (float) $articulo->final_price;

            if ($final <= 0) {

                $sin_costo_ni_precio = (is_null($articulo->cost) || (float) $articulo->cost <= 0)
                    && (is_null($articulo->price) || (float) $articulo->price <= 0);

                $resultado['precio_de_venta'] = null;
                $resultado['precio_de_venta_texto'] = 'quedó SIN precio de venta'.($sin_costo_ni_precio ? ' (no tiene costo ni precio)' : '');

                return $resultado;
            }

            $texto = 'precio de venta '.FormatoIaHelper::monto($final);

            if (!is_null($articulo->cost) && (float) $articulo->cost > 0) {

                $costo = filter_var($articulo->cost_in_dollars, FILTER_VALIDATE_BOOLEAN)
                    ? 'US$ '.number_format((float) $articulo->cost, 2, ',', '.')
                    : FormatoIaHelper::monto($articulo->cost);

                /*
                 * 🔴 Con costo y SIN margen (chequeo independiente, 16/10/2026, el martillo de demo3:
                 * costo 9.000, precio 9.278,35 = el costo más el IIBB). Un "precio de venta $ X" a
                 * secas suena a precio armado, y el dueño está vendiendo sin ganar nada.
                 */
                $texto .= self::sin_margen($article_id, $articulo)
                    ? ' (costo '.$costo.', SIN margen de ganancia: se vende al costo más impuestos)'
                    : ' (costo '.$costo.')';
            }

            $resultado['precio_de_venta'] = round($final, 2);
            $resultado['precio_de_venta_texto'] = $texto;

        } catch (\Throwable $e) {

            Log::warning('EjecutorGenericoIaHelper: no se pudo releer el precio de venta del artículo', [
                'article_id' => $article_id,
                'error'      => $e->getMessage(),
            ]);
        }

        return $resultado;
    }

    /**
     * true si el precio de un artículo con costo (sin listas) no lleva ningún margen: ni el suyo,
     * ni el del proveedor (si lo aplica), ni el de la lista del proveedor, ni el de su categoría, y
     * sin precio manual. Mismas fuentes que suma ArticleHelper::setFinalPrice().
     *
     * @param  int  $article_id
     * @param  object  $articulo  La fila con `price`.
     * @return bool
     */
    protected static function sin_margen(int $article_id, $articulo): bool
    {
        if (!is_null($articulo->price) && (float) $articulo->price > 0) {

            return false;
        }

        $fila = DB::table('articles')
                    ->where('id', $article_id)
                    ->first(['percentage_gain', 'apply_provider_percentage_gain', 'provider_id', 'provider_price_list_id', 'category_id']);

        if (is_null($fila) || (!is_null($fila->percentage_gain) && (float) $fila->percentage_gain > 0)) {

            return false;
        }

        if (!empty($fila->apply_provider_percentage_gain)) {

            if (!empty($fila->provider_price_list_id)) {

                return false;
            }

            if (!empty($fila->provider_id) && (float) DB::table('providers')->where('id', (int) $fila->provider_id)->value('percentage_gain') > 0) {

                return false;
            }
        }

        if (!empty($fila->category_id) && (float) DB::table('categories')->where('id', (int) $fila->category_id)->value('percentage_gain') > 0) {

            return false;
        }

        return true;
    }

    /**
     * Los márgenes por lista que guardó la tarjeta, o []: en el alta viajan en `datos.extras` (con
     * la foto y el stock), en la edición en `datos.margenes_por_lista`. Solo un artículo los tiene;
     * una tarjeta vieja, de antes de la misión alta-por-agente-margen-y-stock, no trae la clave y
     * sigue exactamente el camino de siempre.
     *
     * @param  array  $declaracion
     * @param  string  $operacion
     * @param  array  $datos
     * @return array
     */
    protected static function margenes_de_la_tarjeta(array $declaracion, string $operacion, array $datos): array
    {
        if ($declaracion['entidad'] !== AltaDeArticuloConFotoIaHelper::ENTIDAD) {

            return [];
        }

        $clave = MargenesPorListaIaHelper::CLAVE;

        if ($operacion === Catalogo::OP_ALTA) {

            return isset($datos['extras'][$clave]) && is_array($datos['extras'][$clave]) ? $datos['extras'][$clave] : [];
        }

        if ($operacion === Catalogo::OP_EDICION) {

            return isset($datos[$clave]) && is_array($datos[$clave]) ? $datos[$clave] : [];
        }

        return [];
    }

    // -------------------------------------------------------------------------------------------
    // Guardas
    // -------------------------------------------------------------------------------------------

    /**
     * 🔴 Los cambios de la tarjeta se armaron sobre el registro como estaba en ese momento. Si
     * alguien lo editó después (desde la pantalla o con otra tarjeta), confirmar pisaría ese
     * cambio con valores viejos sin que nadie lo vea: se corta con 409 y la tarjeta queda vencida.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  object  $fila
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_que_no_cambio(AiMessageAction $accion, $fila)
    {
        $referencia = $accion->referencia_updated_at;
        $actual = isset($fila->updated_at) ? $fila->updated_at : null;

        $referencia = is_null($referencia) ? null : Carbon::parse($referencia)->format('Y-m-d H:i:s');
        $actual = is_null($actual) ? null : Carbon::parse($actual)->format('Y-m-d H:i:s');

        if ($referencia !== $actual) {

            throw new AccionIaException(409, self::MENSAJE_CAMBIADO, AiMessageAction::ESTADO_VENCIDA);
        }
    }

    /**
     * Cada relación pedida tiene que seguir existiendo (una categoría borrada entre la tarjeta y el
     * clic dejaría un id colgado en la fila).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  array  $pedidos
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_relaciones(ContextoDeCargaIa $contexto, array $declaracion, array $pedidos)
    {
        foreach ($pedidos as $columna => $valor) {

            if (!isset($declaracion['campos'][$columna]) || is_null($declaracion['campos'][$columna]['relacion']) || is_null($valor)) {

                continue;
            }

            $campo = $declaracion['campos'][$columna];

            if ($campo['relacion']['tabla'] === 'monedas') {

                continue;
            }

            $nombre = Catalogo::nombre_relacionado($campo, $valor);

            if (is_null($nombre)) {

                throw new AccionIaException(422, Str::ucfirst($campo['etiqueta']).' de la tarjeta ya no existe. Pedímelo de nuevo.');
            }
        }
    }

    // -------------------------------------------------------------------------------------------
    // Payload
    // -------------------------------------------------------------------------------------------

    /**
     * El payload que va al controller: el guardado (alta), el modelo entero más los cambios
     * (edición) o nada (baja).
     *
     * @param  array  $declaracion
     * @param  string  $operacion
     * @param  array  $datos
     * @param  int|null  $id
     * @return array
     */
    protected static function payload(array $declaracion, string $operacion, array $datos, $id): array
    {
        if ($operacion === Catalogo::OP_ALTA) {

            return isset($datos['payload']) && is_array($datos['payload']) ? $datos['payload'] : [];
        }

        if ($operacion === Catalogo::OP_BAJA) {

            return [];
        }

        $cambios = isset($datos['cambios']) && is_array($datos['cambios']) ? $datos['cambios'] : [];

        $base = self::modelo_entero($declaracion, (int) $id);

        foreach ($cambios as $columna => $valor) {

            $base[$columna] = $valor;
        }

        // Las claves que la pantalla manda siempre, por si el modelo no las trae cargadas.
        foreach ($declaracion['claves_de_pantalla'] as $clave => $valor) {

            if (!array_key_exists($clave, $base)) {

                $base[$clave] = $valor;
            }
        }

        // ArticleController::update(Request) no recibe el id por la ruta: lo lee del request.
        $base['id'] = (int) $id;

        return $base;
    }

    /**
     * El modelo con sus relaciones (withAll(), como lo carga el listado de la pantalla) como array,
     * con las fechas tal cual están en la base. Sin modelo Eloquent, la fila pelada.
     *
     * @param  array  $declaracion
     * @param  int  $id
     * @return array
     */
    protected static function modelo_entero(array $declaracion, int $id): array
    {
        $clase = GeneralHelper::getModelName($declaracion['entidad']);

        if (!class_exists($clase)) {

            $fila = DB::table($declaracion['tabla'])->where('id', $id)->first();

            return is_null($fila) ? ['id' => $id] : (array) $fila;
        }

        $modelo = method_exists($clase, 'scopeWithAll')
            ? $clase::withAll()->find($id)
            : $clase::find($id);

        if (is_null($modelo)) {

            return ['id' => $id];
        }

        $base = $modelo->toArray();

        foreach ($modelo->getAttributes() as $columna => $crudo) {

            if (!array_key_exists($columna, $base) || !is_string($crudo) || !is_string($base[$columna])) {

                continue;
            }

            // Una fecha que toArray() serializó como ISO vuelve a su valor guardado.
            if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $crudo) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $base[$columna])) {

                $base[$columna] = $crudo;
            }
        }

        return $base;
    }

    // -------------------------------------------------------------------------------------------
    // La llamada
    // -------------------------------------------------------------------------------------------

    /**
     * Arma el request como el de la SPA y llama al método del controller, pasándole solo los
     * parámetros que su firma tiene (el request por tipo, el id por nombre o posición).
     *
     * @param  array{clase: string, metodo: string, slug: string}  $controller
     * @param  string  $operacion
     * @param  array  $payload
     * @param  int|null  $id
     * @return mixed  Lo que devolvió el controller.
     *
     * @throws AccionIaException
     */
    protected static function llamar_al_controller(array $controller, string $operacion, array $payload, $id)
    {
        $metodos_http = [Catalogo::OP_ALTA => 'POST', Catalogo::OP_EDICION => 'PUT', Catalogo::OP_BAJA => 'DELETE'];

        $uri = '/api/'.$controller['slug'].(is_null($id) || $operacion === Catalogo::OP_ALTA ? '' : '/'.$id);

        $request = Request::create($uri, $metodos_http[$operacion], $payload);

        $request->headers->set('Accept', 'application/json');

        $request->setUserResolver(function () {
            return Auth::user();
        });

        $instancia = app($controller['clase']);

        $argumentos = self::argumentos_para($instancia, $controller['metodo'], $request, $id);

        // request() y la fachada Request tienen que ver ESTE request mientras corre el controller;
        // después vuelve el original, que es el del clic.
        $request_anterior = app()->bound('request') ? app('request') : null;

        app()->instance('request', $request);

        try {

            return $instancia->{$controller['metodo']}(...$argumentos);

        } catch (ValidationException $e) {

            throw new AccionIaException(422, self::primer_mensaje($e->errors()));

        } catch (HttpResponseException $e) {

            $respuesta = $e->getResponse();

            throw new AccionIaException(422, self::mensaje_de_rechazo($respuesta));

        } catch (AccionIaException $e) {

            throw $e;

        } catch (\Throwable $e) {

            Log::error('EjecutorGenericoIaHelper: el controller lanzó al ejecutar una tarjeta genérica', [
                'controller' => $controller['clase'].'@'.$controller['metodo'],
                'operacion'  => $operacion,
                'error'      => $e->getMessage(),
            ]);

            throw $e;

        } finally {

            if (!is_null($request_anterior)) {

                app()->instance('request', $request_anterior);
            }
        }
    }

    /**
     * Los argumentos posicionales para el método, según su firma: el request donde el parámetro sea
     * de ese tipo, el id donde se llame `id` (o donde no haya default y quede sin resolver), y el
     * default en el resto.
     *
     * @param  object  $instancia
     * @param  string  $metodo
     * @param  \Illuminate\Http\Request  $request
     * @param  int|null  $id
     * @return array
     */
    protected static function argumentos_para($instancia, string $metodo, Request $request, $id): array
    {
        $reflexion = new \ReflectionMethod($instancia, $metodo);

        $argumentos = [];

        foreach ($reflexion->getParameters() as $parametro) {

            $tipo = $parametro->getType();

            if ($tipo instanceof \ReflectionNamedType && !$tipo->isBuiltin() && is_a($request, $tipo->getName())) {

                $argumentos[] = $request;

                continue;
            }

            if (!is_null($id) && $parametro->getName() === 'id') {

                $argumentos[] = $id;

                continue;
            }

            if ($parametro->isDefaultValueAvailable()) {

                $argumentos[] = $parametro->getDefaultValue();

                continue;
            }

            // Un parámetro obligatorio que no es el request ni se llama id: el del recurso de la
            // ruta (`destroy($sale)`), que también es el id.
            $argumentos[] = $id;
        }

        return $argumentos;
    }

    /**
     * El id de la fila creada: el `model.id` de la respuesta del controller (todos devuelven
     * `{model}`), o la última fila del dueño en la tabla si no vino.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  mixed  $respuesta
     * @return int
     *
     * @throws AccionIaException
     */
    protected static function id_creado(ContextoDeCargaIa $contexto, array $declaracion, $respuesta): int
    {
        $cuerpo = self::cuerpo_de($respuesta);

        if (isset($cuerpo['model']['id']) && (int) $cuerpo['model']['id'] > 0) {

            return (int) $cuerpo['model']['id'];
        }

        $ultimo = DB::table($declaracion['tabla'])->where('user_id', $contexto->owner_id)->max('id');

        if (is_null($ultimo)) {

            throw new AccionIaException(422, self::MENSAJE_RECHAZO);
        }

        return (int) $ultimo;
    }

    // -------------------------------------------------------------------------------------------
    // La respuesta del controller
    // -------------------------------------------------------------------------------------------

    /**
     * El cuerpo decodificado de una respuesta del controller (o [] si no es una respuesta con
     * JSON), y el corte por status >= 400.
     *
     * @param  mixed  $respuesta
     * @return array
     *
     * @throws AccionIaException
     */
    protected static function cuerpo_de($respuesta): array
    {
        if (!($respuesta instanceof Response)) {

            return is_array($respuesta) ? $respuesta : [];
        }

        if ($respuesta->getStatusCode() >= 400) {

            throw new AccionIaException(422, self::mensaje_de_rechazo($respuesta));
        }

        if ($respuesta instanceof JsonResponse) {

            $datos = $respuesta->getData(true);

            return is_array($datos) ? $datos : [];
        }

        $contenido = $respuesta->getContent();

        if (!is_string($contenido) || trim($contenido) === '') {

            return [];
        }

        $decodificado = json_decode($contenido, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * El mensaje para la persona de una respuesta de rechazo: `message`, el primer error de
     * validación, o el genérico.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @return string
     */
    protected static function mensaje_de_rechazo(Response $respuesta): string
    {
        $datos = null;

        if ($respuesta instanceof JsonResponse) {

            $datos = $respuesta->getData(true);

        } else {

            $datos = json_decode((string) $respuesta->getContent(), true);
        }

        if (is_array($datos)) {

            if (isset($datos['message']) && is_string($datos['message']) && trim($datos['message']) !== '') {

                return trim($datos['message']);
            }

            if (isset($datos['errors']) && is_array($datos['errors'])) {

                $primero = self::primer_mensaje($datos['errors']);

                if ($primero !== '') {

                    return $primero;
                }
            }

            if (isset($datos['error']) && is_string($datos['error']) && trim($datos['error']) !== '') {

                return trim($datos['error']);
            }
        }

        return self::MENSAJE_RECHAZO;
    }

    /**
     * El primer mensaje de un array de errores de validación.
     *
     * @param  array  $errores
     * @return string
     */
    protected static function primer_mensaje(array $errores): string
    {
        foreach ($errores as $mensajes) {

            if (is_array($mensajes)) {

                foreach ($mensajes as $mensaje) {

                    if (is_string($mensaje) && trim($mensaje) !== '') {

                        return trim($mensaje);
                    }
                }

            } elseif (is_string($mensajes) && trim($mensajes) !== '') {

                return trim($mensajes);
            }
        }

        return self::MENSAJE_RECHAZO;
    }

    // -------------------------------------------------------------------------------------------
    // Resultado
    // -------------------------------------------------------------------------------------------

    /**
     * Las pantallas de la SPA que listan por día (`from-date`): un registro que no es de hoy no está
     * en el día que abren por defecto.
     */
    const RUTAS_POR_DIA = ['expense', 'sale', 'budget'];

    /**
     * La ruta de la pantalla de la entidad y, si esa pantalla lista por día, el día del registro en
     * `fecha` (Y-m-d de su `created_at`, releído después de guardar). Sin esto, editar por el chat un
     * gasto de otro día y tocar "Ver en Gastos" abría un día donde el gasto no estaba (misión
     * ver-en-del-asistente-refresca-destino, 10/10/2026). Es la misma clave opcional que manda
     * PropuestaGastoIaHelper.
     *
     * @param  string  $entidad
     * @param  object|null  $fila  La fila tal como quedó.
     * @return array|null
     */
    protected static function ruta_con_dia(string $entidad, $fila)
    {
        $ruta = Catalogo::ruta_de_pantalla($entidad);

        if (is_null($ruta) || !in_array($ruta['name'], self::RUTAS_POR_DIA, true) || is_null($fila) || empty($fila->created_at)) {

            return $ruta;
        }

        $ruta['fecha'] = substr((string) $fila->created_at, 0, 10);

        return $ruta;
    }

    /**
     * El resultado que queda en la tarjeta, releyendo la fila y comparando cada campo pedido con
     * lo guardado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion
     * @param  string  $operacion
     * @param  int  $id
     * @param  array  $pedidos
     * @param  object|null  $fila_anterior  La fila antes de la operación (edición y baja).
     * @return array
     */
    protected static function resultado(ContextoDeCargaIa $contexto, array $declaracion, string $operacion, int $id, array $pedidos, $fila_anterior): array
    {
        $femenino = $declaracion['genero'] === 'f';

        if ($operacion === Catalogo::OP_BAJA) {

            $nombre = Catalogo::nombre_de_fila($declaracion['entidad'], $fila_anterior);

            $verbo = $declaracion['entidad'] === 'sale'
                ? 'anulada'
                : ($femenino ? 'borrada' : 'borrado');

            $texto = self::nombre_con_tipo($declaracion, $nombre).' '.$verbo;

            return [
                'texto'   => $texto,
                'entidad' => $declaracion['entidad'],
                'id'      => $id,
                'nombre'  => $nombre,
                'ruta'    => null,
                'params'  => new \stdClass(),
            ];
        }

        $fila = DB::table($declaracion['tabla'])->where('id', $id)->first();

        $nombre = is_null($fila) ? '#'.$id : Catalogo::nombre_de_fila($declaracion['entidad'], $fila);

        $no_quedaron = [];
        $aplicados = [];
        $guardados = [];

        foreach ($pedidos as $columna => $valor) {

            if (!isset($declaracion['campos'][$columna])) {

                continue;
            }

            $campo = $declaracion['campos'][$columna];

            $guardado = !is_null($fila) && isset($fila->{$columna}) ? $fila->{$columna} : null;

            /*
             * Protegido (chequeo independiente, 16/10/2026): el alta o la edición ya se hizo, y una
             * relación rara que lanza al leer su nombre no puede devolverla como error. Si falla, el
             * resultado va sin `campos_guardados`.
             */
            if (!is_null($guardados)) {

                try {

                    $guardados[] = [
                        'etiqueta' => Str::ucfirst($campo['etiqueta']),
                        'valor'    => self::guardado_legible($campo, $guardado, $fila),
                    ];

                } catch (\Throwable $e) {

                    Log::warning('EjecutorGenericoIaHelper: no se pudo leer lo guardado de un campo', [
                        'entidad' => $declaracion['entidad'],
                        'campo'   => $columna,
                        'error'   => $e->getMessage(),
                    ]);

                    $guardados = null;
                }
            }

            if (self::quedo($campo, $valor, $guardado)) {

                $aplicados[] = $campo['etiqueta'];

                continue;
            }

            $no_quedaron[] = [
                'campo'    => $columna,
                'etiqueta' => $campo['etiqueta'],
                'pedido'   => Catalogo::valor_legible($campo, $valor),
                'quedo'    => Catalogo::valor_legible($campo, $guardado),
            ];
        }

        $verbo = $operacion === Catalogo::OP_ALTA
            ? ($femenino ? 'creada' : 'creado')
            : ($femenino ? 'actualizada' : 'actualizado');

        $resultado = [
            'texto'                  => self::nombre_con_tipo($declaracion, $nombre).' '.$verbo,
            'entidad'                => $declaracion['entidad'],
            'id'                     => $id,
            'nombre'                 => $nombre,
            'ruta'                   => self::ruta_con_dia($declaracion['entidad'], $fila),
            'params'                 => new \stdClass(),
            'campos_que_no_quedaron' => $no_quedaron,
            /*
             * Misión asistente-altas-y-conversacion (16/10/2026, D3): lo que QUEDÓ guardado en cada
             * campo que mandó la tarjeta, legible ("Razón social: Ferretería El Clavo"). En "directo"
             * el modelo solo veía `texto` y redactó "con domicilio en Ferretería El Clavo" para una
             * razón social: con esto cuenta lo guardado, no lo que se imagina del pedido.
             */
            'campos_guardados'       => $guardados,
        ];

        if (is_null($guardados)) {

            unset($resultado['campos_guardados']);
        }

        if ($operacion === Catalogo::OP_EDICION) {

            $resultado['cambios_aplicados'] = $aplicados;
        }

        return $resultado;
    }

    /**
     * El valor guardado de un campo como se lee en un renglón (Catalogo::valor_legible(); para una
     * relación, su nombre). El costo de un artículo con "costo en dólares" se escribe en dólares, como
     * en la tarjeta (ver PropuestaGenericaIaHelper::costo_en_dolares_en_los_renglones()).
     *
     * @param  array  $campo
     * @param  mixed  $guardado
     * @param  object|null  $fila
     * @return string
     */
    protected static function guardado_legible(array $campo, $guardado, $fila): string
    {
        if ($campo['columna'] === 'cost'
            && !is_null($guardado)
            && !is_null($fila)
            && isset($fila->cost_in_dollars)
            && filter_var($fila->cost_in_dollars, FILTER_VALIDATE_BOOLEAN)) {

            return 'US$ '.number_format((float) $guardado, 2, ',', '.');
        }

        return Catalogo::valor_legible($campo, $guardado);
    }

    /**
     * "Proveedor Acme", o "Venta N° 12" cuando el nombre ya trae el tipo (entidades sin columna de
     * nombre, que se identifican por número).
     *
     * @param  array  $declaracion
     * @param  string  $nombre
     * @return string
     */
    protected static function nombre_con_tipo(array $declaracion, string $nombre): string
    {
        if (is_null($declaracion['columna_nombre'])) {

            return $nombre;
        }

        return Str::ucfirst($declaracion['singular']).' '.$nombre;
    }

    /**
     * true si lo guardado es lo pedido. Para texto no distingue mayúsculas: los controllers hacen
     * ucfirst() del nombre y eso no es "no quedó".
     *
     * @param  array  $campo
     * @param  mixed  $pedido
     * @param  mixed  $guardado
     * @return bool
     */
    protected static function quedo(array $campo, $pedido, $guardado): bool
    {
        if (PropuestaGenericaIaHelper::iguales($campo, $guardado, $pedido)) {

            return true;
        }

        if (is_null($campo['relacion']) && in_array($campo['tipo'], ['text', 'textarea'], true) && !is_null($pedido) && !is_null($guardado)) {

            return mb_strtolower(trim((string) $pedido)) === mb_strtolower(trim((string) $guardado));
        }

        return false;
    }
}
