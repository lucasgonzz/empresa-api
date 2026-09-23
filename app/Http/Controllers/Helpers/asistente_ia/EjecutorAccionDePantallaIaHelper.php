<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Escritura;
use App\Models\AiMessageAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Ejecuta una acción de pantalla: llama a la misma ruta y al mismo controller que llama la pantalla,
 * autenticado como la persona (misión asistente-mcp, 22/9/2026, constructor B).
 *
 * Es el mismo movimiento que EjecutorGenericoIaHelper hizo para el ABM —armar el request de la SPA
 * y llamar al controller— llevado a cualquier ruta del catálogo (CatalogoDeAccionesDePantallaIaHelper).
 * La diferencia es que acá no hay una entidad ni un payload curado: la ruta se resuelve con el
 * matcher del router, los {param} se reemplazan por sus valores, el cuerpo va tal cual lo armó el
 * modelo, y la respuesta del controller es lo que la pantalla habría recibido.
 *
 * Lo que este ejecutor pone alrededor, que el controller no hace:
 *
 *   - 🔴 La ruta se RESUELVE con el router antes de decidir nada (resolver()), y todo lo que decide
 *     —la fila del catálogo, la extensión, la tenencia— sale de ESA ruta y de los valores que el
 *     router liga, no de lo que escribió el modelo. Ver "LA RUTA QUE MANDA", más abajo.
 *   - 🔴 El catálogo se vuelve a consultar al ejecutar, no solo al proponer. Una tarjeta guarda la
 *     ruta con sus {param}; si esa ruta salió del catálogo (una exclusión nueva, un cambio de
 *     rutas) entre la propuesta y el clic, se corta con 422 y no se llama a nada.
 *   - 🔴 La extensión se verifica sobre el DUEÑO (PermisosIaHelper::tiene_extencion), que es lo que
 *     haría `check_extencion_empresa` en la ruta. Los middlewares de la ruta NO corren: la sesión
 *     la garantiza Auth (ver abajo) y `SubstituteBindings` lo reemplaza ImplicitRouteBinding.
 *   - La autenticación se verifica y no se supone: `Auth::id()` tiene que ser la persona del
 *     contexto, porque `$this->userId()` de los controllers sale de ahí. Los tres caminos que llegan
 *     acá (el clic por sanctum, la confirmación por texto y la consulta GET en el acto) la ponen
 *     con Auth::setUser() antes.
 *   - La respuesta del controller se interpreta: un status >= 400 es un rechazo de negocio (422 con
 *     su `message`), no una falla técnica; 🔴 se le sacan las claves sensibles (ver "NADA
 *     SENSIBLE", más abajo); y el cuerpo viaja recortado a LARGO_MAXIMO caracteres, porque la
 *     respuesta más pesada del sistema (el índice de artículos) mide megabytes.
 *
 * 🔴 LA RUTA QUE MANDA ES LA QUE EL ROUTER RESUELVE (verificador de la misión, segunda vuelta,
 * 23/9/2026). La primera versión recorría los {param} por el nombre que había escrito el modelo y
 * salteaba el que no encontraba, y consultar_por_pantalla le pasaba al router la ruta tal como
 * vino. Resultado demostrado: con `ruta: "api/sale/4898"` (el id ya metido en la ruta), con
 * `api/sale/{id}` (otro nombre de parámetro), con `api/{x}` y `x = "sale/4898"` (una barra
 * codificada que el router decodifica), o con el id ajeno en la ruta y un parámetro PROPIO en
 * `parametros`, la guarda no miraba nada y `GET api/sale/{sale}` devolvía la venta de OTRO dueño
 * entera (`fullModel()` no filtra por dueño). Ahora lo que mandó el modelo en `ruta` y `parametros`
 * solo sirve para ARMAR la URI concreta: resolver() la matchea con el mismo matcher que después corre
 * la acción, y la tenencia se verifica sobre `$route->parameters()` —los valores que el router
 * efectivamente ligó, por los nombres de la ruta resuelta, que son los que el controller recibe—.
 * La propuesta hace lo mismo y guarda en la tarjeta esa ruta y esos valores, así que una tarjeta
 * con el id metido en la ruta queda ejecutable.
 *
 * 🔴 TENENCIA DE LOS IDS DE LA RUTA (verificar_tenencia()). Los controllers de pantalla resuelven
 * casi todos por `Model::find($id)` sin filtrar por dueño (hallazgo del 16/9/2026 sobre
 * ComboController; acá mismo `CajaController::destroy()` y `UserController::set_eliminar_articulos_offline()`).
 * Una persona nunca manda un id ajeno desde su pantalla; un modelo sí puede inventarlo, y en las
 * bases compartidas (51 comercios en `u767360347_empresa`) eso es tocar el negocio de otro. Por eso,
 * antes de llamar al controller —y también al proponer, para avisar en el acto—, cada {param}
 * ligado se resuelve a su tabla como lo hace CatalogoDeEscrituraIaHelper
 * (`Str::plural(str_replace('-', '_', <primer segmento después de api/>))`) y, si esa tabla existe
 * y tiene `user_id`, la fila tiene que ser del dueño; si no existe o es ajena, 422 MENSAJE_AJENO.
 * Lo que el modelo mandó con el nombre de un {param} de la ruta resuelta también se mira: un
 * `true` se escribe "1" en la URI, y el router liga la fila 1 aunque `true` no sea un id.
 *
 * Cómo se elige la tabla de cada {param}, y por qué así (medido sobre las 520 acciones con
 * parámetros de este router): `{id}` → el recurso del primer segmento (`api/budget/{id}/anular` →
 * budgets), y si ese slug no es una tabla, la del MODELO del controller
 * (`cc-payment-method-discount` → CurrentAcountPaymentMethodDiscountController →
 * current_acount_payment_method_discounts); `{x_id}` → `xs` (`{article_id}` → articles, aunque la
 * ruta sea `api/price-change/...`); el parámetro del propio recurso (`{caja}` en `api/caja/{caja}`,
 * `{cuotum}` en `api/cuota/...`) → ese recurso; cualquier otro nombre → su propio plural, y nada
 * más. Ese "nada más" es a propósito: caer al primer segmento para `{ultimos_movimientos}` o
 * `{value}` chequearía un contador contra `stock_movements.id` y rechazaría lecturas válidas.
 *
 * 🔴 Y LA FILA SE VERIFICA AUNQUE SU TABLA NO TENGA `user_id` (verificador de la misión, 23/9/2026:
 * `PUT api/article-variant/{id}` cambió el precio de una variante ajena y
 * `POST api/apertura-caja/reabrir/{id}` reabrió la caja de otro). Sin `user_id` se lee la fila y se
 * sube por sus columnas `*_id` hasta una tabla que sí lo tenga, dos saltos como máximo
 * (`apertura_caja_id` → apertura_cajas → `caja_id` → cajas): esa fila padre tiene que ser del
 * dueño. Si ninguna columna llega a un dueño —un catálogo global como current_acount_payment_methods
 * o unidad_medidas, compartido por todos los comercios de la base— una ESCRITURA se rechaza con
 * MENSAJE_NO_VERIFICABLE y una lectura pasa. `users` tampoco tiene `user_id`, y ahí la regla es
 * otra: la fila es del negocio si es el dueño o uno de sus empleados (verificar_usuario()).
 *
 * 🔴 LOS IDS DEL CUERPO, TODOS (verificar_cuerpo(); verificador, segunda vuelta). La guarda miraba
 * solo `id` y `*_id` escalares de primer nivel, y así, con el dueño en "directo":
 * `POST api/article-discount` con el `model_id` de un artículo ajeno le recalculó el precio,
 * `POST api/provider-price-list` le creó una lista a un proveedor ajeno, `POST api/sale-tax` ató
 * artículos ajenos con `article_ids`, y `PUT api/cheque/rechazar` con `cheque_id: true` rechazó el
 * cheque 1 de otro comercio (`Cheque::find(true)` es el id 1). Ahora el cuerpo (o la query, si es
 * GET) se recorre hasta PROFUNDIDAD_DEL_CUERPO niveles:
 *   - `x_id` → `Str::plural(x)`; `x_ids`, o un `x_id` con una lista adentro → cada elemento contra
 *     lo mismo; `user_id`, `employee_id`, `owner_id` y sus compuestos → `users`
 *     (PATRON_ID_DE_USUARIO: `employee_id` derivaría `employees`, que no existe);
 *   - `id` adentro de un objeto que cuelga de la clave `P` —suelto o como elemento de una lista—
 *     → la tabla `P` si existe, si no `Str::plural(P)`; el `id` de primer nivel → la tabla de la
 *     ruta resuelta;
 *   - `model_id` (la convención de la SPA para "hijo de") → la tabla que le da el CÓDIGO del
 *     controller (`'article_id' => $request->model_id`), y si el código no lo dice, la del
 *     `model_name` hermano o, en último caso, la del prefijo de la ruta (tabla_del_model_id());
 *   - null, '', 0 y '0' son "sin valor"; un booleano, un decimal o un texto que no es un entero,
 *     en una clave con tabla derivable, es MENSAJE_AJENO; un entero se verifica como un {param}.
 *     Un catálogo global sin padre (`moneda_id`, `iva_id`) se deja pasar, SALVO el `model_id` de
 *     una escritura: ese dice sobre qué registro se escribe, y si no se lo puede atribuir a un
 *     dueño se rechaza con MENSAJE_NO_VERIFICABLE.
 * Lo que la guarda sigue sin decidir: ids más hondos que PROFUNDIDAD_DEL_CUERPO, y claves cuyo nombre
 * no dice su tabla (`returned_items[].id`, sin tabla `returned_items`).
 *
 * 🔴 NADA SENSIBLE CRUZA AL MODELO NI QUEDA EN LA TARJETA (sin_claves_sensibles(); verificador,
 * segunda vuelta). `GET api/client/{client}` traía la `visible_password` y el `verification_code`
 * del comprador (la relación `buyer`: Buyer no tiene $hidden) y `GET api/sale/{sale}` además la
 * `visible_password` del empleado (User::$hidden no la incluye). La pantalla recibe esos datos
 * porque los modelos no los esconden, y los modelos NO se tocan: el SPA depende de lo que
 * devuelven. El asistente, en cambio, no los puede ver ni guardar. Por eso el borde es acá, adentro
 * de llamar(): antes del recorte, se saca de la respuesta toda clave que matchee
 * EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES —el mismo regex que protege el catálogo de lectura—, a
 * cualquier profundidad. Como consultar, ejecutar y el `resultado.respuesta` de la tarjeta salen
 * todos de llamar(), no hay un camino que se lo saltee.
 *
 * Sus dos tipos van en EjecutorAccionesIaHelper::TIPOS_DE_DOS_ETAPAS: un controller de pantalla
 * puede abrir su propia transacción, soltar un candado, disparar un job o un broadcast, y nada de
 * eso se revierte con el rollback de la transacción del ejecutor.
 */
class EjecutorAccionDePantallaIaHelper
{
    const MENSAJE_SIN_AUTENTICAR = 'No se pudo autenticar a la persona para ejecutar la acción.';

    const MENSAJE_NO_DISPONIBLE = 'Esa acción no está disponible desde el asistente.';

    const MENSAJE_RECHAZO = 'La pantalla rechazó la acción.';

    const MENSAJE_SIN_REGISTRO = 'No existe el registro que pide la ruta.';

    const MENSAJE_AJENO = 'Ese registro no es de este negocio o no existe.';

    const MENSAJE_NO_VERIFICABLE = 'No se puede verificar que ese registro sea de este negocio.';

    /**
     * Largo máximo (en caracteres) del JSON de la respuesta que viaja al modelo y queda en la
     * tarjeta. Pasado eso se corta el texto y se marca `recortado: true`.
     */
    const LARGO_MAXIMO = 30000;

    /**
     * Hasta cuántos niveles de anidamiento se buscan ids en el cuerpo (cada array cuenta, también
     * una lista): `cheque_id` es nivel 1, `sale.client_id` nivel 2, `articles[0].id` nivel 3. Es
     * lo que arma la SPA para un renglón de una venta o de un pedido; más hondo no lee ningún
     * controller del catálogo, y recorrer sin tope es una consulta por hoja.
     */
    const PROFUNDIDAD_DEL_CUERPO = 3;

    /**
     * Las claves de id que apuntan a `users` aunque no se llamen como la tabla: el dueño y sus
     * empleados son filas de users. Sin esto `employee_id` derivaría `employees`, que no existe, y
     * el empleado de otro comercio pasaría sin mirar (CajaController, RoadMapController y
     * SaleController::update() lo guardan tal cual viene).
     */
    const PATRON_ID_DE_USUARIO = '/(^|_)(user|employee|owner)_id$/';

    /**
     * Cómo dice el CÓDIGO de un controller a qué tabla va el `model_id`:
     * `'article_id' => $request->model_id` (o con input('model_id') / $request['model_id']). El
     * lado derecho no cruza una coma ni un punto y coma: el tokenizer de cuerpo_del_metodo() junta
     * dos renglones cuando saca un comentario del final de uno, y sin ese tope la clave de un
     * renglón se pegaría al `model_id` del siguiente.
     */
    const PATRON_MODEL_ID_EN_EL_CODIGO = '/\'([a-z0-9_]+)_id\'\s*=>[^,;\n]*?\$request(?:->model_id\b|->input\(\s*\'model_id\'\s*\)|\[\s*\'model_id\'\s*\])/';

    /** @var array<string, string>  [tabla => 'ok' | 'sin_user_id' | 'no_existe'], por proceso. */
    protected static $tablas = [];

    /** @var array<string, array<int, string>>  [Clase@metodo => tablas del model_id según su código], por proceso. */
    protected static $tablas_del_model_id = [];

    /**
     * Ejecuta la acción de una tarjeta (accion_pantalla o borrado_pantalla) al confirmarla.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta: null, params, metodo, uri, status, respuesta, recortado}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        $datos = is_array($accion->datos) ? $accion->datos : [];

        $metodo = Catalogo::normalizar_metodo(isset($datos['metodo']) ? $datos['metodo'] : '');
        $ruta = isset($datos['ruta']) ? (string) $datos['ruta'] : '';
        $parametros = isset($datos['parametros']) && is_array($datos['parametros']) ? $datos['parametros'] : [];
        $cuerpo = isset($datos['cuerpo']) && is_array($datos['cuerpo']) ? $datos['cuerpo'] : [];

        /*
         * El tipo de la tarjeta y el método tienen que coincidir: una tarjeta `accion_pantalla`
         * (que en "directo" se ejecuta sola) no puede terminar corriendo un DELETE, ni una de
         * borrado un POST. Es la defensa contra una tarjeta forjada a mano en la base.
         */
        $metodos_del_tipo = [
            AiMessageAction::TIPO_ACCION_PANTALLA  => ['POST', 'PUT'],
            AiMessageAction::TIPO_BORRADO_PANTALLA => ['DELETE'],
        ];

        $tipo = (string) $accion->tipo;

        if (!isset($metodos_del_tipo[$tipo]) || !in_array($metodo, $metodos_del_tipo[$tipo], true)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $llamada = self::llamar($contexto, $metodo, $ruta, $parametros, $cuerpo);

        return [
            'texto'     => 'Hecho: '.$metodo.' '.$llamada['uri'],
            'ruta'      => null,
            'params'    => new \stdClass(),
            'metodo'    => $metodo,
            'uri'       => $llamada['uri'],
            'status'    => $llamada['status'],
            'respuesta' => $llamada['cuerpo'],
            'recortado' => $llamada['recortado'],
        ];
    }

    /**
     * Llama a la ruta como la llamaría la pantalla y devuelve lo que respondió, sin claves sensibles.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $metodo  GET | POST | PUT | DELETE (PATCH se pliega en PUT).
     * @param  string  $uri  La ruta, con sus {param} (o ya con valores): solo sirve para armar la URI.
     * @param  array  $parametros  Los valores de los {param}, por nombre.
     * @param  array  $cuerpo  El cuerpo del request (o la query string, si es GET).
     * @return array{status: int, cuerpo: array, recortado: bool, uri: string}
     *
     * @throws AccionIaException
     */
    public static function llamar(ContextoDeCargaIa $contexto, string $metodo, string $uri, array $parametros, array $cuerpo): array
    {
        $metodo = Catalogo::normalizar_metodo($metodo);

        if (!in_array($metodo, Catalogo::METODOS, true)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $faltan = Catalogo::parametros_que_faltan(Catalogo::normalizar_ruta($uri), $parametros);

        if (count($faltan)) {

            throw new AccionIaException(422, 'Falta el parámetro '.$faltan[0].' de la ruta.');
        }

        /*
         * 🔴 La declaración que manda es la de la ruta que el router RESUELVE, y la tenencia se mira
         * sobre los valores que ESA ruta liga (ver resolver() y el docblock de la clase). Si la
         * ruta que atiende la URI no está en el catálogo —o no hay ninguna—, no se corre nada.
         */
        $resuelta = self::resolver($metodo, $uri, $parametros, $cuerpo);

        if (is_null($resuelta)) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        $declaracion = $resuelta['declaracion'];

        if (!is_null($declaracion['extension']) && !PermisosIaHelper::tiene_extencion($contexto->owner, $declaracion['extension'])) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_extencion(str_replace('_', ' ', $declaracion['extension'])));
        }

        // Se vuelve a verificar acá y no solo al proponer: el registro pudo cambiar de dueño entre
        // la tarjeta y el clic, y una tarjeta se puede forjar.
        self::verificar_tenencia_de_la_llamada($contexto, $resuelta, $parametros, $cuerpo);

        if (is_null($contexto->persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $contexto->persona->id) {

            throw new AccionIaException(500, self::MENSAJE_SIN_AUTENTICAR);
        }

        $request = $resuelta['request'];
        $ruta = $resuelta['ruta'];
        $uri_concreta = $resuelta['uri'];

        $request->setUserResolver(function () {
            return Auth::user();
        });

        /*
         * La instancia de Route es compartida por todo el proceso: se la vuelve a ligar a ESTE
         * request justo antes de correrla, y si lo que queda ligado no es exactamente lo que se
         * verificó arriba, no se corre nada. Es defensa en profundidad: hoy no hay camino que la
         * religue en el medio, y el día que lo haya esto lo corta en vez de correr otra cosa.
         */
        $ruta->bind($request);

        if ($ruta->parameters() !== $resuelta['parametros']) {

            throw new AccionIaException(422, self::MENSAJE_NO_DISPONIBLE);
        }

        // request() y la fachada Request tienen que ver ESTE request mientras corre el controller;
        // después vuelve el original, que es el del clic (o ninguno, en el job).
        $request_anterior = app()->bound('request') ? app('request') : null;

        app()->instance('request', $request);

        try {

            // Lo que haría SubstituteBindings si el método tipara un modelo (en la práctica los
            // controllers reciben ids, pero se resuelve igual por si acaso).
            ImplicitRouteBinding::resolveForRoute(app(), $ruta);

            $respuesta = $ruta->run();

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            throw new AccionIaException(422, self::MENSAJE_SIN_REGISTRO);

        } catch (ValidationException $e) {

            throw new AccionIaException(422, self::primer_mensaje($e->errors()));

        } catch (AccionIaException $e) {

            throw $e;

        } catch (HttpExceptionInterface $e) {

            // Un abort(403) / abort(404) del controller es un rechazo de negocio, no una falla técnica.
            $mensaje = trim((string) $e->getMessage());

            throw new AccionIaException(422, $mensaje === '' ? self::MENSAJE_RECHAZO : $mensaje);

        } catch (\Throwable $e) {

            Log::error('EjecutorAccionDePantallaIaHelper: el controller lanzó al ejecutar una acción de pantalla', [
                'accion' => $declaracion['accion'],
                'metodo' => $metodo,
                'uri'    => $uri_concreta,
                'error'  => $e->getMessage(),
            ]);

            throw $e;

        } finally {

            if (!is_null($request_anterior)) {

                app()->instance('request', $request_anterior);

            } else {

                // En el job no había request: no se deja el sintético bindeado, o los helpers que
                // corran después en el mismo proceso verían un GET falso.
                app()->forgetInstance('request');
            }
        }

        list($status, $cuerpo_respuesta) = self::interpretar($respuesta);

        /*
         * 🔴 Lo sensible sale ACÁ, antes del recorte y antes de volver: la pantalla recibe la
         * contraseña visible de un comprador o de un empleado porque los modelos no la esconden
         * (y no se tocan, el SPA depende de lo que devuelven), pero al modelo no le llega y en la
         * tarjeta no queda. Ver el docblock de la clase.
         */
        $cuerpo_respuesta = self::sin_claves_sensibles($cuerpo_respuesta);

        list($cuerpo_respuesta, $recortado) = self::recortar($cuerpo_respuesta);

        return [
            'status'    => $status,
            'cuerpo'    => $cuerpo_respuesta,
            'recortado' => $recortado,
            'uri'       => $uri_concreta,
        ];
    }

    // -------------------------------------------------------------------------------------------
    // La ruta resuelta
    // -------------------------------------------------------------------------------------------

    /**
     * Resuelve una llamada como la resolvería el router para la pantalla: arma la URI concreta con
     * la ruta y los parámetros que vinieron, la matchea con el MISMO matcher que después corre la
     * acción y liga los parámetros de la ruta que la atiende. Lo usan llamar() y la propuesta
     * (PropuestaAccionDePantallaIaHelper), así la tarjeta guarda exactamente la ruta y los valores
     * que después se ejecutan.
     *
     * 🔴 Lo que vino en `$ruta` / `$parametros` solo sirve para armar la URI: la fila del catálogo,
     * la extensión y la tenencia salen de lo que devuelve esto (ver el docblock de la clase).
     *
     * @param  string  $metodo
     * @param  string  $ruta  Como la mandó el modelo o como la guardó la tarjeta.
     * @param  array  $parametros
     * @param  array  $cuerpo  El cuerpo (o la query, si es GET): viaja en el request.
     * @return array|null  {ruta: \Illuminate\Routing\Route, request: Request, declaracion: array,
     *                     parametros: array (los ligados, por nombre), uri: string (la concreta)};
     *                     null si ninguna ruta atiende esa URI con ese método o si la que la atiende
     *                     no está en el catálogo.
     */
    public static function resolver($metodo, $ruta, array $parametros, array $cuerpo = [])
    {
        $metodo = Catalogo::normalizar_metodo($metodo);

        if (!in_array($metodo, Catalogo::METODOS, true)) {

            return null;
        }

        $uri_concreta = Catalogo::uri_concreta((string) $ruta, $parametros);

        if ($uri_concreta === '') {

            return null;
        }

        $request = Request::create('/'.$uri_concreta, $metodo, $cuerpo);

        $request->headers->set('Accept', 'application/json');

        try {

            $encontrada = Route::getRoutes()->match($request);

        } catch (HttpExceptionInterface $e) {

            // No hay ruta, o no con ese método: para el asistente es "no disponible".
            return null;

        } catch (\Throwable $e) {

            Log::warning('EjecutorAccionDePantallaIaHelper: no se pudo resolver una ruta', [
                'metodo' => $metodo,
                'uri'    => $uri_concreta,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }

        /*
         * La fila del catálogo de ESTA ruta, exacta. declaracion() tolera otro nombre de {param}, y
         * para una ruta excluida podría devolver la fila de otra ruta con la misma forma: si la
         * fila no es la de la ruta que el router eligió, no se corre nada.
         */
        $declaracion = Catalogo::declaracion($metodo, $encontrada->uri());

        if (is_null($declaracion) || $declaracion['ruta'] !== $encontrada->uri()) {

            return null;
        }

        // match() ya la ligó, pero declaracion() pudo matchear otra URI contra la misma instancia
        // de Route (es compartida): se la liga de nuevo a ESTE request antes de leer los valores.
        $encontrada->bind($request);

        return [
            'ruta'        => $encontrada,
            'request'     => $request,
            'declaracion' => $declaracion,
            'parametros'  => $encontrada->parameters(),
            'uri'         => $uri_concreta,
        ];
    }

    /**
     * Los parámetros ligados como se guardan en la tarjeta: un id escrito con dígitos vuelve a ser
     * entero (el router liga todo como texto, y la tarjeta dice `caja_id: 12` como lo habría
     * mandado la pantalla); lo demás, tal cual. Un número que no entra en un entero queda como
     * texto: castearlo lo cambiaría.
     *
     * @param  array  $ligados  Los de resolver().
     * @return array
     */
    public static function parametros_para_guardar(array $ligados): array
    {
        $guardar = [];

        foreach ($ligados as $nombre => $valor) {

            if (is_string($valor) && preg_match('/^[1-9][0-9]*$/D', $valor) === 1 && (string) (int) $valor === $valor) {

                $valor = (int) $valor;
            }

            $guardar[$nombre] = $valor;
        }

        return $guardar;
    }

    // -------------------------------------------------------------------------------------------
    // Tenencia
    // -------------------------------------------------------------------------------------------

    /**
     * La tenencia de una llamada ya resuelta (ver resolver()): los valores que el router LIGÓ, por
     * los nombres de la ruta resuelta —los que el controller va a recibir—, el cuerpo, y además lo
     * que el modelo mandó con el nombre de un {param} de esa ruta.
     *
     * Lo último no sobra: Catalogo::uri_concreta() escribe un booleano como "1", así que
     * `caja_id: true` llega ligado como la caja 1 —que puede ser del dueño— aunque `true` no sea un
     * id. null y '' se saltean: son "no vino", la URI ni los escribe.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $resuelta  Lo que devolvió resolver().
     * @param  array  $parametros_del_modelo  Los `parametros` tal como vinieron.
     * @param  array  $cuerpo  El cuerpo, o la query si es GET.
     * @return void
     *
     * @throws AccionIaException
     */
    public static function verificar_tenencia_de_la_llamada(ContextoDeCargaIa $contexto, array $resuelta, array $parametros_del_modelo, array $cuerpo)
    {
        self::verificar_tenencia($contexto, $resuelta['declaracion'], $resuelta['parametros'], $cuerpo);

        $mandados = [];

        foreach ($parametros_del_modelo as $nombre => $valor) {

            if (is_null($valor) || (is_string($valor) && trim($valor) === '')) {

                continue;
            }

            $mandados[$nombre] = $valor;
        }

        self::verificar_tenencia($contexto, $resuelta['declaracion'], $mandados);
    }

    /**
     * Exige que cada id de la ruta, y cada id del cuerpo (o de la query, si es GET), sea una fila
     * del dueño (ver el docblock de la clase). `$parametros` son los valores POR LOS NOMBRES DE LA
     * RUTA DE `$declaracion`: llamar() y la propuesta le pasan los que ligó el router
     * (verificar_tenencia_de_la_llamada()).
     *
     * Los ids de la ruta son el REGISTRO SOBRE EL QUE SE ACTÚA: si su tabla no tiene `user_id` y
     * tampoco se llega al dueño por sus padres, una escritura se rechaza como no verificable. Los
     * ids del cuerpo son referencias (a qué venta, a qué proveedor): un catálogo global sin padre
     * (`moneda_id`, `iva_id`, `afip_tipo_comprobante_id`) no se puede rechazar sin romper cargas
     * legítimas, así que ahí lo no verificable se deja pasar — salvo el `model_id` de una escritura
     * (ver verificar_cuerpo()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $declaracion  La fila del catálogo (ruta, metodo, accion).
     * @param  array  $parametros  Los valores de los {param}, por nombre.
     * @param  array  $cuerpo  El cuerpo del request, o la query string si es GET.
     * @return void
     *
     * @throws AccionIaException  422 MENSAJE_AJENO si una fila no existe o es de otro dueño;
     *                            422 MENSAJE_NO_VERIFICABLE si el registro sobre el que se escribe
     *                            no se puede atribuir a ningún dueño.
     */
    public static function verificar_tenencia(ContextoDeCargaIa $contexto, array $declaracion, array $parametros, array $cuerpo = [])
    {
        $ruta = Catalogo::normalizar_ruta(isset($declaracion['ruta']) ? $declaracion['ruta'] : '');
        $escritura = Catalogo::normalizar_metodo(isset($declaracion['metodo']) ? $declaracion['metodo'] : 'GET') !== 'GET';
        $tabla_del_modelo = self::tabla_del_modelo_del_controller(isset($declaracion['accion']) ? (string) $declaracion['accion'] : '');

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\??\}/', $ruta, $m);

        foreach ($m[1] as $nombre) {

            // Un {param} opcional que no se ligó no le llega al controller: no hay qué decidir.
            if (!array_key_exists($nombre, $parametros)) {

                continue;
            }

            // Sin tabla derivable (fechas, códigos, contadores) no hay qué decidir.
            $tabla = self::tabla_del_parametro($ruta, $nombre, $tabla_del_modelo);

            if (is_null($tabla)) {

                continue;
            }

            /*
             * 🔴 Con tabla derivable, el valor TIENE que ser un entero. Antes un valor que no era
             * solo dígitos se salteaba "porque no es un id", y el verificador de la misión abrió la
             * caja de otro dueño con `caja_id = <ajeno>x`: el router matchea igual y MySQL castea
             * '161x' a 161 en el find() del controller. Lo que no es un id, con tabla, se rechaza.
             */
            self::verificar_fila($contexto, $tabla, $parametros[$nombre], $escritura);
        }

        if (count($cuerpo)) {

            $vistos = [];

            self::verificar_cuerpo($contexto, $cuerpo, 1, null, $declaracion, $ruta, $escritura, $tabla_del_modelo, $vistos);
        }
    }

    /**
     * Recorre el cuerpo (o la query) hasta PROFUNDIDAD_DEL_CUERPO niveles y verifica cada id que
     * encuentra contra la tabla de su clave (las reglas, en el docblock de la clase).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $nodo  El nivel que se recorre.
     * @param  int  $nivel  1 para las claves de primer nivel.
     * @param  string|null  $padre  La clave con nombre más cercana de la que cuelga este nivel.
     * @param  array  $declaracion
     * @param  string  $ruta  Ya normalizada.
     * @param  bool  $escritura
     * @param  string|null  $tabla_del_modelo
     * @param  array  $vistos  [tabla|valor|exigir => true]: lo ya verificado no se vuelve a consultar.
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_cuerpo(ContextoDeCargaIa $contexto, array $nodo, int $nivel, $padre, array $declaracion, string $ruta, bool $escritura, $tabla_del_modelo, array &$vistos)
    {
        foreach ($nodo as $clave => $valor) {

            if (is_string($clave)) {

                $destino = self::destino_de_la_clave($clave, $nodo, $padre, $declaracion, $ruta, $escritura, $tabla_del_modelo);

                if (!is_null($destino)) {

                    foreach (self::valores_de($valor) as $uno) {

                        self::verificar_valor_del_cuerpo($contexto, $destino, $uno, $vistos);
                    }
                }
            }

            if (is_array($valor) && $nivel < self::PROFUNDIDAD_DEL_CUERPO) {

                self::verificar_cuerpo($contexto, $valor, $nivel + 1, is_string($clave) ? $clave : $padre, $declaracion, $ruta, $escritura, $tabla_del_modelo, $vistos);
            }
        }
    }

    /**
     * Qué verificar para una clave del cuerpo: null si la clave no es de un id; si no,
     * `{tabla: string|null, exigir: bool}`, donde `tabla` null quiere decir que no se pudo
     * derivar y `exigir` que, sin dueño, se rechaza (solo el `model_id` de una escritura).
     *
     * @param  string  $clave
     * @param  array  $hermanos  El objeto que contiene la clave (para el `model_name` del `model_id`).
     * @param  string|null  $padre
     * @param  array  $declaracion
     * @param  string  $ruta
     * @param  bool  $escritura
     * @param  string|null  $tabla_del_modelo
     * @return array|null
     */
    protected static function destino_de_la_clave(string $clave, array $hermanos, $padre, array $declaracion, string $ruta, bool $escritura, $tabla_del_modelo)
    {
        if ($clave === 'model_id') {

            // 🔴 El model_id de una escritura dice SOBRE QUÉ registro se escribe: sin dueño, no pasa.
            return ['tabla' => self::tabla_del_model_id($hermanos, $declaracion, $ruta), 'exigir' => $escritura];
        }

        if ($clave === 'id') {

            // El `id` de primer nivel es el registro de la ruta; uno anidado, el de la clave de la
            // que cuelga (`articles[0].id` → articles).
            $tabla = is_null($padre)
                ? self::tabla_del_parametro($ruta, 'id', $tabla_del_modelo)
                : self::tabla_de_la_lista($padre);

            return ['tabla' => $tabla, 'exigir' => false];
        }

        if (Str::endsWith($clave, '_ids')) {

            return ['tabla' => self::tabla_de_la_clave(substr($clave, 0, -1)), 'exigir' => false];
        }

        if (Str::endsWith($clave, '_id')) {

            return ['tabla' => self::tabla_de_la_clave($clave), 'exigir' => false];
        }

        return null;
    }

    /**
     * Los valores a verificar de una clave de id: el valor mismo si es escalar (o null), y si es un
     * array, cada elemento que no sea a su vez un array (`article_ids: [4, 5]`, o un `cheque_id`
     * con una lista, que `find()` también acepta). Lo anidado más adentro lo recorre verificar_cuerpo().
     *
     * @param  mixed  $valor
     * @return array
     */
    protected static function valores_de($valor): array
    {
        if (!is_array($valor)) {

            return [$valor];
        }

        $valores = [];

        foreach ($valor as $elemento) {

            if (!is_array($elemento)) {

                $valores[] = $elemento;
            }
        }

        return $valores;
    }

    /**
     * Verifica un valor de una clave de id del cuerpo contra su destino.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $destino  {tabla, exigir} de destino_de_la_clave().
     * @param  mixed  $valor
     * @param  array  $vistos
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_valor_del_cuerpo(ContextoDeCargaIa $contexto, array $destino, $valor, array &$vistos)
    {
        // null, '', 0 y '0' son "sin valor" (la SPA los manda así para una relación vacía), no un id.
        if (self::sin_valor($valor)) {

            return;
        }

        if (is_null($destino['tabla'])) {

            /*
             * Sin tabla derivable no hay qué verificar (un código externo, un contador)... salvo el
             * model_id de una escritura: ese es el registro sobre el que se escribe, y si no se sabe
             * de qué tabla es, tampoco se sabe de quién es.
             */
            if ($destino['exigir']) {

                throw new AccionIaException(422, self::MENSAJE_NO_VERIFICABLE);
            }

            return;
        }

        $visto = $destino['tabla'].'|'.gettype($valor).':'.(is_scalar($valor) ? (string) $valor : '').'|'.($destino['exigir'] ? '1' : '0');

        if (isset($vistos[$visto])) {

            return;
        }

        self::verificar_fila($contexto, $destino['tabla'], $valor, (bool) $destino['exigir']);

        $vistos[$visto] = true;
    }

    /**
     * true si el valor es "sin valor" en un cuerpo: null, '', 0 o '0' (los textos, sin espacios
     * alrededor). Un booleano NO: `false` no es una relación vacía que mande la pantalla, y
     * `true` es el id 1 para un find().
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function sin_valor($valor): bool
    {
        if (is_null($valor)) {

            return true;
        }

        if (is_bool($valor) || !is_scalar($valor)) {

            return false;
        }

        $texto = trim((string) $valor);

        return $texto === '' || $texto === '0';
    }

    /**
     * Una fila tiene que ser del dueño: por su `user_id` si la tabla lo tiene, y si no, por el
     * `user_id` de sus padres (`*_id` → tabla padre), con dos saltos como máximo
     * (`apertura_caja_id` → apertura_cajas → `caja_id` → cajas). `users` tiene su propia regla
     * (verificar_usuario()).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $tabla  Una tabla que existe.
     * @param  mixed  $valor  El id.
     * @param  bool  $exigir_verificable  true para el registro sobre el que se ESCRIBE: si ningún
     *                                    padre resuelve la tenencia (catálogo global), se rechaza.
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_fila(ContextoDeCargaIa $contexto, string $tabla, $valor, bool $exigir_verificable)
    {
        if (!self::es_id($valor)) {

            throw new AccionIaException(422, self::MENSAJE_AJENO);
        }

        if ($tabla === 'users') {

            self::verificar_usuario($contexto, $valor);

            return;
        }

        if (self::estado_de_la_tabla($tabla) === 'ok') {

            $user_id = DB::table($tabla)->where('id', (int) $valor)->value('user_id');

            if (is_null($user_id) || (int) $user_id !== (int) $contexto->owner_id) {

                throw new AccionIaException(422, self::MENSAJE_AJENO);
            }

            return;
        }

        $fila = DB::table($tabla)->where('id', (int) $valor)->first();

        if (is_null($fila)) {

            throw new AccionIaException(422, self::MENSAJE_AJENO);
        }

        if (!self::tenencia_por_padres($contexto, (array) $fila, 2) && $exigir_verificable) {

            throw new AccionIaException(422, self::MENSAJE_NO_VERIFICABLE);
        }
    }

    /**
     * Un id de `users` es del negocio si es el dueño o uno de sus empleados (`owner_id`). users no
     * tiene `user_id`, y subir por sus padres (`plan_id`, `address_id`...) no dice nada de para
     * quién trabaja la persona.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $valor  Un id ya validado con es_id().
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_usuario(ContextoDeCargaIa $contexto, $valor)
    {
        $fila = DB::table('users')->where('id', (int) $valor)->first(['id', 'owner_id']);

        $dueno = (int) $contexto->owner_id;

        if (is_null($fila) || ((int) $fila->id !== $dueno && (int) $fila->owner_id !== $dueno)) {

            throw new AccionIaException(422, self::MENSAJE_AJENO);
        }
    }

    /**
     * true si al menos un padre de la fila resolvió la tenencia (y todos los que resolvieron son
     * del dueño); false si ninguna columna `*_id` llegó a una tabla con `user_id`. Un padre de otro
     * dueño corta con MENSAJE_AJENO.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  array  $fila
     * @param  int  $saltos  Cuántos niveles hacia arriba quedan por mirar.
     * @return bool
     *
     * @throws AccionIaException
     */
    protected static function tenencia_por_padres(ContextoDeCargaIa $contexto, array $fila, int $saltos): bool
    {
        $alguna = false;

        foreach ($fila as $columna => $valor) {

            if ($columna === 'user_id' || !Str::endsWith((string) $columna, '_id') || is_null($valor) || !self::es_id($valor)) {

                continue;
            }

            $padre = Str::plural(substr($columna, 0, -3));

            $estado = self::estado_de_la_tabla($padre);

            if ($estado === 'no_existe') {

                continue;
            }

            if ($estado === 'ok') {

                $user_id = DB::table($padre)->where('id', (int) $valor)->value('user_id');

                if (is_null($user_id) || (int) $user_id !== (int) $contexto->owner_id) {

                    throw new AccionIaException(422, self::MENSAJE_AJENO);
                }

                $alguna = true;

                continue;
            }

            if ($saltos > 1) {

                $fila_padre = DB::table($padre)->where('id', (int) $valor)->first();

                if (!is_null($fila_padre) && self::tenencia_por_padres($contexto, (array) $fila_padre, $saltos - 1)) {

                    $alguna = true;
                }
            }
        }

        return $alguna;
    }

    /**
     * La tabla del modelo del controller (`CajaController` → App\Models\Caja → cajas), o null si no
     * hay un modelo con ese nombre. Es el fallback para las rutas cuyo slug no coincide con la
     * tabla (`cc-payment-method-discount` → current_acount_payment_method_discounts).
     *
     * @param  string  $accion  Clase@metodo sin namespace.
     * @return string|null
     */
    protected static function tabla_del_modelo_del_controller(string $accion)
    {
        $clase = strtok($accion, '@');

        if (!is_string($clase) || $clase === '') {

            return null;
        }

        $modelo = 'App\Models\\'.preg_replace('/Controller$/', '', class_basename($clase));

        if (!class_exists($modelo) || !is_subclass_of($modelo, 'Illuminate\Database\Eloquent\Model')) {

            return null;
        }

        try {

            $tabla = (new $modelo())->getTable();

        } catch (\Throwable $e) {

            return null;
        }

        return self::tabla_si_existe($tabla);
    }

    /**
     * La tabla si existe (con o sin `user_id`), o null. Solo se consultan nombres en minúsculas
     * con guiones bajos: una clave del cuerpo como `Articles` no la lee ningún controller, y en
     * Windows MySQL la encontraría igual (tablas sin distinguir mayúsculas) y en Linux no.
     *
     * @param  string|null  $tabla
     * @return string|null
     */
    protected static function tabla_si_existe($tabla)
    {
        if (!is_string($tabla) || preg_match('/^[a-z][a-z0-9_]*$/', $tabla) !== 1) {

            return null;
        }

        return self::estado_de_la_tabla($tabla) === 'no_existe' ? null : $tabla;
    }

    /**
     * La tabla de una clave `x_id` (de la ruta o del cuerpo): `users` para las de PATRON_ID_DE_USUARIO,
     * y si no `Str::plural(x)`, si existe.
     *
     * @param  string  $clave  Termina en `_id`.
     * @return string|null
     */
    protected static function tabla_de_la_clave(string $clave)
    {
        if (preg_match(self::PATRON_ID_DE_USUARIO, $clave) === 1) {

            return self::tabla_si_existe('users');
        }

        return self::tabla_si_existe(Str::plural(substr($clave, 0, -3)));
    }

    /**
     * La tabla del `id` de un objeto que cuelga de la clave `$padre`: si el padre es una clave de
     * ids (`article_ids: [{id}]`), la de esa clave; si no, la tabla `$padre` si existe, y si no,
     * su plural (`articles` → articles, `client` → clients). null si nada de eso es una tabla
     * (`returned_items`, `form`).
     *
     * @param  string  $padre
     * @return string|null
     */
    protected static function tabla_de_la_lista(string $padre)
    {
        if (Str::endsWith($padre, '_ids')) {

            return self::tabla_de_la_clave(substr($padre, 0, -1));
        }

        if (Str::endsWith($padre, '_id')) {

            return self::tabla_de_la_clave($padre);
        }

        $directa = self::tabla_si_existe($padre);

        return is_null($directa) ? self::tabla_si_existe(Str::plural($padre)) : $directa;
    }

    /**
     * La tabla del `model_id` de un cuerpo, en este orden:
     *   1. la que le da el CÓDIGO del controller (`'article_id' => $request->model_id`): si es una
     *      sola, manda, aunque venga un `model_name` que diga otra cosa, porque es la que el
     *      controller va a escribir;
     *   2. si el código la reparte según el model_name (`'client_id' => $request->model_name ==
     *      'client' ? $request->model_id : null`), la del `model_name` hermano, siempre que sea una
     *      de esas;
     *   3. si el código no dice nada (se lo pasa a un helper junto con el model_name, como
     *      `limite-credito`), la del `model_name` hermano;
     *   4. y si tampoco hay `model_name`, la del prefijo del primer segmento de la ruta antes del
     *      primer guion (`article-discount` → articles).
     * null si nada de eso da una tabla que exista.
     *
     * 🔴 Por qué el código primero y no el prefijo de la ruta: en `api/provider-order-discount`,
     * `provider-order-extra-cost` y `provider-order-afip-ticket` el prefijo dice `providers` y el
     * controller escribe `provider_order_id`; en `api/description` el prefijo dice `descriptions` y
     * el controller escribe `article_id`. Verificar contra la tabla equivocada rechaza lo legítimo
     * o, peor, deja pasar el pedido de otro dueño cuyo número coincide con un proveedor propio. Y
     * por qué el código antes que el `model_name`: si no, `model_name: "client"` con el id de un
     * cliente propio pasaría la guarda en `article-discount`, que lo usa como `article_id`.
     *
     * @param  array  $hermanos  El objeto que contiene el `model_id`.
     * @param  array  $declaracion
     * @param  string  $ruta  Ya normalizada.
     * @return string|null
     */
    protected static function tabla_del_model_id(array $hermanos, array $declaracion, string $ruta)
    {
        $del_codigo = self::tablas_del_model_id_en_el_codigo(isset($declaracion['accion']) ? (string) $declaracion['accion'] : '');

        $del_nombre = null;

        if (isset($hermanos['model_name']) && is_string($hermanos['model_name']) && trim($hermanos['model_name']) !== '') {

            $del_nombre = self::tabla_si_existe(Str::plural(Str::snake(class_basename(trim($hermanos['model_name'])))));
        }

        if (count($del_codigo) === 1) {

            return $del_codigo[0];
        }

        if (count($del_codigo) > 1) {

            return !is_null($del_nombre) && in_array($del_nombre, $del_codigo, true) ? $del_nombre : null;
        }

        if (!is_null($del_nombre)) {

            return $del_nombre;
        }

        $segmentos = explode('/', $ruta);

        $prefijo = isset($segmentos[1]) ? explode('-', $segmentos[1])[0] : '';

        return $prefijo === '' ? null : self::tabla_si_existe(Str::plural($prefijo));
    }

    /**
     * Las tablas a las que el código del método del controller manda el `model_id`
     * (PATRON_MODEL_ID_EN_EL_CODIGO), sin repetir; [] si no lo dice o no se pudo leer el código.
     * Cacheado por proceso.
     *
     * @param  string  $accion  Clase@metodo, sin el namespace App\Http\Controllers.
     * @return array<int, string>
     */
    protected static function tablas_del_model_id_en_el_codigo(string $accion): array
    {
        if (isset(self::$tablas_del_model_id[$accion])) {

            return self::$tablas_del_model_id[$accion];
        }

        $tablas = [];

        if (strpos($accion, '@') !== false) {

            list($clase, $metodo) = explode('@', $accion, 2);

            $completa = class_exists('App\Http\Controllers\\'.$clase) ? 'App\Http\Controllers\\'.$clase : $clase;

            $codigo = null;

            try {

                $codigo = Escritura::cuerpo_del_metodo($completa, $metodo);

            } catch (\Throwable $e) {

                $codigo = null;
            }

            if (is_string($codigo) && preg_match_all(self::PATRON_MODEL_ID_EN_EL_CODIGO, $codigo, $m)) {

                foreach ($m[1] as $columna) {

                    $tabla = self::tabla_de_la_clave($columna.'_id');

                    if (!is_null($tabla) && !in_array($tabla, $tablas, true)) {

                        $tablas[] = $tabla;
                    }
                }
            }
        }

        self::$tablas_del_model_id[$accion] = $tablas;

        return $tablas;
    }

    /**
     * true si el valor es un id: un entero positivo, o un texto escrito solo con dígitos, sin signo,
     * sin decimales y sin nada pegado ('161x' no es un id aunque MySQL lo castee a 161; "161\n"
     * tampoco, por eso el `D`). Un decimal no es un id aunque sea redondo: 12.0 no es lo que manda
     * la pantalla, y un booleano menos (`find(true)` es el id 1).
     *
     * @param  mixed  $valor
     * @return bool
     */
    protected static function es_id($valor): bool
    {
        if (is_bool($valor) || is_float($valor) || !is_scalar($valor)) {

            return false;
        }

        return preg_match('/^[1-9][0-9]*$/D', (string) $valor) === 1;
    }

    /**
     * La tabla a la que apunta un {param} de la ruta (exista o no su `user_id`: eso lo resuelve
     * verificar_fila()), o null si no se puede decidir porque no hay tabla con ese nombre. Las
     * reglas están en el docblock de la clase.
     *
     * Para el registro del propio recurso (`{id}`, `{caja}`, `{cuotum}`) se prueba PRIMERO la
     * tabla del slug de la URI y recién después la del modelo del controller: en
     * `PUT api/article/{id}/variants-disponibilidad` el controller es ArticleVariantController pero
     * el {id} es el del ARTÍCULO, y al revés (`cc-payment-method-discount`) el slug no es tabla y el
     * modelo sí.
     *
     * @param  string  $ruta  Ya normalizada.
     * @param  string  $nombre  El nombre del {param}.
     * @param  string|null  $tabla_del_modelo  La tabla del modelo del controller, si existe.
     * @return string|null
     */
    protected static function tabla_del_parametro(string $ruta, string $nombre, $tabla_del_modelo)
    {
        $segmentos = explode('/', $ruta);

        $segmento = isset($segmentos[1]) ? str_replace('-', '_', $segmentos[1]) : '';

        $recurso = $segmento === '' ? null : Str::plural($segmento);

        if (Str::endsWith($nombre, '_id')) {

            return self::tabla_de_la_clave($nombre);
        }

        // El propio recurso: `{id}`, o el parámetro con el nombre (o el singular del inflector,
        // `{cuotum}` para `api/cuota`) del primer segmento.
        $es_el_recurso = $nombre === 'id'
            || (!is_null($recurso) && (Str::plural($nombre) === $recurso || Str::singular($segmento) === $nombre));

        if ($es_el_recurso) {

            $tabla = self::tabla_si_existe($recurso);

            return is_null($tabla) ? self::tabla_si_existe($tabla_del_modelo) : $tabla;
        }

        return self::tabla_si_existe(Str::plural($nombre));
    }

    /**
     * 'ok' si la tabla existe y tiene `user_id`, 'sin_user_id' si existe sin la columna,
     * 'no_existe' si no. Cacheado por proceso: information_schema no se consulta dos veces por la
     * misma tabla.
     *
     * @param  string  $tabla
     * @return string
     */
    protected static function estado_de_la_tabla(string $tabla): string
    {
        if (!isset(self::$tablas[$tabla])) {

            if (!Schema::hasTable($tabla)) {

                self::$tablas[$tabla] = 'no_existe';

            } else {

                self::$tablas[$tabla] = Schema::hasColumn($tabla, 'user_id') ? 'ok' : 'sin_user_id';
            }
        }

        return self::$tablas[$tabla];
    }

    // -------------------------------------------------------------------------------------------
    // La respuesta del controller
    // -------------------------------------------------------------------------------------------

    /**
     * El status y el cuerpo decodificado de lo que devolvió el controller. Una respuesta con status
     * >= 400 es un rechazo de negocio y corta con 422 y su mensaje. Lo que no es JSON viaja como
     * `{texto}`; `Route::run()` ya convirtió una HttpResponseException en su respuesta.
     *
     * @param  mixed  $respuesta
     * @return array{0: int, 1: array}
     *
     * @throws AccionIaException
     */
    protected static function interpretar($respuesta): array
    {
        if ($respuesta instanceof Response) {

            $status = $respuesta->getStatusCode();

            if ($status >= 400) {

                throw new AccionIaException(422, self::mensaje_de_rechazo($respuesta));
            }

            if ($respuesta instanceof JsonResponse || $respuesta instanceof \Symfony\Component\HttpFoundation\JsonResponse) {

                $datos = json_decode((string) $respuesta->getContent(), true);

                return [$status, is_array($datos) ? $datos : ['texto' => (string) $respuesta->getContent()]];
            }

            if ($respuesta instanceof BinaryFileResponse || $respuesta instanceof StreamedResponse) {

                return [$status, ['texto' => '(la pantalla devolvió un archivo)']];
            }

            $contenido = (string) $respuesta->getContent();

            if (trim($contenido) === '') {

                return [$status, ['texto' => '']];
            }

            $decodificado = json_decode($contenido, true);

            return [$status, is_array($decodificado) ? $decodificado : ['texto' => $contenido]];
        }

        if (is_null($respuesta)) {

            return [200, ['texto' => '']];
        }

        if (is_scalar($respuesta)) {

            return [200, ['texto' => (string) $respuesta]];
        }

        // Un modelo, una colección, un array: lo que Laravel habría convertido en JSON.
        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $decodificado = $json === false ? null : json_decode($json, true);

        return [200, is_array($decodificado) ? $decodificado : ['texto' => $json === false ? '' : $json]];
    }

    /**
     * La respuesta sin ninguna clave sensible, a cualquier profundidad (ver el docblock de la
     * clase): toda clave de un objeto cuyo nombre matchee EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES
     * se va con su valor. Las posiciones de una lista no son nombres y no se miran.
     *
     * @param  mixed  $valor
     * @return mixed
     */
    protected static function sin_claves_sensibles($valor)
    {
        if ($valor instanceof \stdClass) {

            $limpio = new \stdClass();

            foreach (get_object_vars($valor) as $clave => $hijo) {

                if (!self::es_clave_sensible($clave)) {

                    $limpio->{$clave} = self::sin_claves_sensibles($hijo);
                }
            }

            return $limpio;
        }

        if (!is_array($valor)) {

            return $valor;
        }

        $limpio = [];

        foreach ($valor as $clave => $hijo) {

            if (is_string($clave) && self::es_clave_sensible($clave)) {

                continue;
            }

            $limpio[$clave] = self::sin_claves_sensibles($hijo);
        }

        return $limpio;
    }

    /**
     * true si el nombre de la clave es de un dato sensible (contraseña, token, clave, código de
     * verificación...), con el mismo regex que protege el catálogo de lectura.
     *
     * @param  mixed  $clave
     * @return bool
     */
    protected static function es_clave_sensible($clave): bool
    {
        return preg_match(EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES, (string) $clave) === 1;
    }

    /**
     * El cuerpo tal cual si su JSON entra en LARGO_MAXIMO; si no, el JSON cortado como texto y la
     * marca `recortado`.
     *
     * @param  array  $cuerpo
     * @return array{0: array, 1: bool}
     */
    protected static function recortar(array $cuerpo): array
    {
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {

            return [['texto' => 'La pantalla respondió con un contenido que no se pudo leer.'], false];
        }

        if (mb_strlen($json) <= self::LARGO_MAXIMO) {

            return [$cuerpo, false];
        }

        return [['texto' => mb_substr($json, 0, self::LARGO_MAXIMO), 'recortado' => true], true];
    }

    /**
     * El mensaje para la persona de una respuesta de rechazo: `message`, el primer error de
     * validación, `error`, o el genérico. Copiado de EjecutorGenericoIaHelper.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $respuesta
     * @return string
     */
    protected static function mensaje_de_rechazo(Response $respuesta): string
    {
        $datos = json_decode((string) $respuesta->getContent(), true);

        if (is_array($datos)) {

            if (isset($datos['message']) && is_string($datos['message']) && trim($datos['message']) !== '') {

                return trim($datos['message']);
            }

            if (isset($datos['errors']) && is_array($datos['errors'])) {

                $primero = self::primer_mensaje($datos['errors']);

                if ($primero !== self::MENSAJE_RECHAZO) {

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
}
