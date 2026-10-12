<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\CommonLaravel\EmployeeController;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\PermissionEmpresa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Darle o sacarle un PERMISO a un empleado (misión asistente-capacidades-y-hilos, 22/9/2026): el
 * mensaje #44 del 22/9 en demo3, *"los permisos de los usuarios se administran desde la parte de
 * configuración del sistema, y no puedo cambiarlos desde acá"*. El caso real de Lucas era sacarle a
 * la empleada Brisa el permiso de ver ventas (`sale.index`, que la pantalla muestra como "Listar
 * ventas").
 *
 * 🔴 SE HACE POR `PUT api/employee/{id}` (`CommonLaravel\EmployeeController@update`), el mismo
 * endpoint que la pantalla de Empleados.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * 🔴 TRES TRAMPAS DE ESE ENDPOINT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **`permissions` ES UN REEMPLAZO TOTAL.** `update()` hace `sync()` con la lista que le llega:
 *    mandar SOLO el permiso nuevo le saca al empleado TODOS los demás. Por eso acá se leen los
 *    actuales, se suma o se resta el que pidió el dueño, y se manda la LISTA COMPLETA. (Si
 *    `permissions` no viene como lista, `update()` no toca los permisos —desde el 1/10/2026; antes
 *    reventaba con un 500—, pero acá se manda siempre: es justo lo que se quiere cambiar.)
 *
 * 2. **REESCRIBE LA CONTRASEÑA EN CADA GUARDADO** (`password = Hash::make(visible_password)`), y
 *    desde el 9/10/2026 (misión empleados-alta-y-edicion) EXIGE nombre, documento y contraseña:
 *    con cualquiera de los tres vacío contesta 422 y NO ESCRIBE NADA (antes, con la contraseña
 *    vacía, se la cambiaba y el empleado no entraba más). Acá se manda la `visible_password` que el
 *    empleado ya tiene y, 🔴 si le falta la contraseña visible, el nombre o el documento, la carga se
 *    RECHAZA de entrada con el motivo: así no se puede guardar su ficha, y eso se completa desde
 *    ABM > Empleados, no desde el chat.
 *
 * 3. **USA `$request->id`, NO EL `{id}` DE LA URL.** Se mandan los dos y tienen que coincidir.
 *    Desde el 9/10/2026 un `id` ausente, o el de alguien que no es empleado del dueño, da 404
 *    (antes era un 500 sobre `null`).
 *
 * ⚠️ Y una cuarta, que no es del endpoint sino del catálogo: la tabla de permisos de empresa es
 * `permission_empresas` (modelo `PermissionEmpresa`, pivot `permission_empresa_user`), NO
 * `permission_betas`, que es otra cosa (feature flags por plan y por extensión) y no tiene ninguna
 * relación con `User`.
 *
 * 🔴 ESTA CARGA NO SE AUTO-EJECUTA EN NINGÚN MODO, ni siquiera con el dueño en "directo": su tipo
 * está en `HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES` y su `case` no pasa por
 * `quizas_auto_confirmar()`. Por lo de arriba, y porque el daño no se nota hasta que la persona
 * llega a trabajar. La tarjeta muestra CON QUÉ PERMISOS QUEDA el empleado, no solo cuál se toca.
 *
 * PHP 7.4: sin enum, sin match, sin operador nullsafe.
 */
class PropuestaPermisoEmpleadoIaHelper
{
    /**
     * La extensión que enciende el módulo de Empleados: la ruta `/empleados` de la SPA pide
     * `if_has_extencion: 'comerciocity_interno'` (src/router/routes.js).
     */
    const EXTENSION = 'comerciocity_interno';

    /** Las dos cosas que se pueden hacer con un permiso. */
    const ACCION_DAR = 'dar';

    const ACCION_SACAR = 'sacar';

    /** Tope de candidatos que se ofrecen cuando un nombre es ambiguo. */
    const TOPE_CANDIDATOS = 10;

    /** Ruta de la SPA donde se ven los empleados (`src/router/routes.js`: `name: 'employee'`). */
    const RUTA_EMPLEADOS = 'employee';

    /**
     * Herramienta proponer_permiso_de_empleado.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessage  $mensaje  El assistant que propone.
     * @param  array  $input  empleado*, permiso*, accion*, reemplaza_a
     * @return array
     */
    public static function proponer(ContextoDeCargaIa $contexto, AiMessage $mensaje, array $input): array
    {
        /*
         * 🔴 Espejo de `check_is_owner` de la ruta `/empleados`, que la SPA resuelve como
         * `this.is_owner || this.user.admin_access` en sus dos consumidores (mixins/nav.js:41-43 y
         * mixins/permissions.js:81-83). O sea: el dueño y los administradores, que es exactamente
         * `PermisosIaHelper::es_admin()`. No hay ningún `can()` de permiso suelto que lo cubra: los
         * permisos de empleados no están detrás de un slug, están detrás de ser dueño.
         */
        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            return RespuestaDeCargaIa::error('Los permisos de los empleados los cambia el dueño del negocio o un administrador.');
        }

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            return RespuestaDeCargaIa::error(PermisosIaHelper::mensaje_sin_extencion('Empleados'));
        }

        $accion = EntradaDeCargaIa::texto($input, 'accion');

        if (!in_array($accion, [self::ACCION_DAR, self::ACCION_SACAR], true)) {

            return RespuestaDeCargaIa::faltan(['si le doy o le saco el permiso']);
        }

        $empleado = self::resolver_empleado($contexto, EntradaDeCargaIa::texto($input, 'empleado'));

        if (RespuestaDeCargaIa::es_negativa($empleado)) {

            return $empleado;
        }

        $permiso = self::resolver_permiso(EntradaDeCargaIa::texto($input, 'permiso'));

        if (RespuestaDeCargaIa::es_negativa($permiso)) {

            return $permiso;
        }

        /*
         * 🔴 LA GUARDA DE LA FICHA (trampa 2). `update()` exige nombre, documento y contraseña y, si
         * falta alguno, contesta 422 sin escribir nada. Este helper manda la ficha tal como está,
         * así que una tarjeta armada para un empleado con la ficha incompleta no se podría
         * confirmar nunca. Se corta acá, antes de armarla, diciendo qué falta y dónde se carga.
         */
        $falta = self::falta_en_la_ficha($empleado);

        if (!is_null($falta)) {

            return RespuestaDeCargaIa::error($falta);
        }

        $actuales = self::permisos_actuales($empleado);

        $tenia = array_key_exists((int) $permiso->id, $actuales);

        if ($accion === self::ACCION_DAR && $tenia) {

            return RespuestaDeCargaIa::error($empleado->name . ' ya tiene el permiso "' . $permiso->name . '": no hay nada que cambiar.');
        }

        if ($accion === self::ACCION_SACAR && !$tenia) {

            return RespuestaDeCargaIa::error($empleado->name . ' no tiene el permiso "' . $permiso->name . '": no hay nada que sacarle.');
        }

        $finales = $actuales;

        if ($accion === self::ACCION_DAR) {

            $finales[(int) $permiso->id] = $permiso;

        } else {

            unset($finales[(int) $permiso->id]);
        }

        $nombres = self::nombres_de($finales);

        $renglones = [
            ['etiqueta' => 'Empleado', 'valor' => (string) $empleado->name],
            [
                'etiqueta' => $accion === self::ACCION_DAR ? 'Le doy' : 'Le saco',
                'valor'    => $permiso->name . ' (' . $permiso->slug . ')',
            ],
            /*
             * 🔴 LA TARJETA DICE CON QUÉ QUEDA, no solo qué se toca. `update()` reemplaza la lista
             * entera, así que lo que la persona tiene que poder revisar antes de confirmar es el
             * resultado. Si quedara vacía, eso también se lee.
             */
            [
                'etiqueta' => 'Le quedan',
                'valor'    => count($nombres) ? implode(', ', $nombres) : 'ningún permiso',
            ],
        ];

        if ($empleado->admin_access) {

            $renglones[] = [
                'etiqueta' => 'Ojo',
                'valor'    => 'Tiene acceso de administrador, así que puede hacer todo igual: los permisos de la lista no lo limitan.',
            ];
        }

        $creada = AccionesIaHelper::crear(
            $contexto,
            $mensaje,
            AiMessageAction::TIPO_PERMISO_EMPLEADO,
            self::clave($empleado->id, $permiso->id),
            /*
             * 🔴 ACÁ NO SE GUARDA EL PAYLOAD, Y ES A PROPÓSITO: LLEVA LA CONTRASEÑA EN CLARO.
             *
             * `payload()` arma el modelo entero del empleado, y ahí adentro va su
             * `visible_password` tal cual —el endpoint la exige, ver la trampa 2—. Guardarlo en
             * `ai_message_actions.datos` dejaría una copia en claro de la contraseña de un empleado
             * en una tabla de chat, viva mientras viva la fila. `datos` está en `$hidden` y no
             * llega a la SPA, pero eso es que no se muestra, no que no esté.
             *
             * Y no hace falta para nada: al confirmar el payload se REARMA con la ficha de ese
             * momento (ver el 🔴 de ejecutar()), así que lo guardado no se leía nunca. Lo que sí se
             * guarda es el PEDIDO —qué empleado, qué permiso, si se da o se saca— y `permiso_ids`,
             * que es la lista que la tarjeta PROMETIÓ, para poder leer después qué se le dijo a la
             * persona.
             */
            [
                'esperado' => [
                    'employee_id'  => (int) $empleado->id,
                    'permiso_id'   => (int) $permiso->id,
                    'accion'       => $accion,
                    'permiso_ids'  => array_map('intval', array_keys($finales)),
                ],
            ],
            [
                'titulo'    => 'Permisos de un empleado',
                'renglones' => $renglones,
                'aviso'     => count($nombres)
                    ? null
                    : 'Con este cambio ' . $empleado->name . ' se queda sin ningún permiso: va a poder entrar, pero no ver ni hacer casi nada.',
            ],
            EntradaDeCargaIa::valor($input, 'reemplaza_a'),
            // El `updated_at` de la ficha: si la editan en el medio, confirmar da 409 (ver ejecutar()).
            is_null($empleado->updated_at) ? null : $empleado->updated_at->format('Y-m-d H:i:s')
        );

        $resumen = ($accion === self::ACCION_DAR ? 'Darle ' : 'Sacarle ') . '"' . $permiso->name . '" a ' . $empleado->name;

        return AccionesIaHelper::respuesta_de_propuesta($creada, $resumen);
    }

    /**
     * Identidad del cambio para el reemplazo: el mismo permiso sobre el mismo empleado. Una
     * corrección ("no, dáselo") pisa la tarjeta; otro permiso u otro empleado, no.
     *
     * @param  int  $employee_id
     * @param  int  $permiso_id
     * @return string
     */
    public static function clave($employee_id, $permiso_id): string
    {
        return 'permiso_empleado:' . (int) $employee_id . ':' . (int) $permiso_id;
    }

    /**
     * Aplica el cambio por `EmployeeController::update()` y CONFIRMA leyendo los permisos después.
     *
     * 🔴 SE CONFIRMA EL RESULTADO, Y NO POR PROLIJIDAD: `update()` REEMPLAZA la lista entera con un
     * `sync()` (trampa 1), y lo que le importa a la persona es con qué permisos quedó el empleado, no
     * que el endpoint haya contestado 200. Desde el 9/10/2026 `update()` valida antes de escribir y
     * escribe en una transacción, pero esta verificación no depende de eso: se lee la lista final y
     * se compara contra la esperada; si no coinciden, se lanza y el ejecutor revierte.
     *
     * Y si `update()` contesta un error (422 por datos de la ficha que no sirven, 404), se lanza
     * con SU motivo antes de comparar nada (ver el 🔴 de la llamada).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  \App\Models\AiMessageAction  $accion
     * @return array  resultado {texto, ruta}
     *
     * @throws AccionIaException
     */
    public static function ejecutar(ContextoDeCargaIa $contexto, AiMessageAction $accion): array
    {
        if (!PermisosIaHelper::es_admin($contexto->persona)) {

            throw new AccionIaException(422, 'Los permisos de los empleados los cambia el dueño del negocio o un administrador.');
        }

        if (!PermisosIaHelper::tiene_extencion($contexto->owner, self::EXTENSION)) {

            throw new AccionIaException(422, PermisosIaHelper::mensaje_sin_extencion('Empleados'));
        }

        $persona = $contexto->persona;

        if (is_null($persona) || is_null(Auth::id()) || (int) Auth::id() !== (int) $persona->id) {

            throw new AccionIaException(500, 'El cambio de permisos no se puede hacer sin la persona autenticada.');
        }

        $datos = is_array($accion->datos) ? $accion->datos : [];

        $esperado = isset($datos['esperado']) && is_array($datos['esperado']) ? $datos['esperado'] : [];

        $employee_id = isset($esperado['employee_id']) ? (int) $esperado['employee_id'] : 0;

        $empleado = User::where('owner_id', $contexto->owner_id)->where('id', $employee_id)->first();

        if (is_null($empleado)) {

            throw new AccionIaException(422, 'Ese empleado ya no está entre los de este negocio. Pedímelo de nuevo.');
        }

        // La guarda de la ficha, revisada de nuevo: la ficha pudo cambiar entre la propuesta y el clic.
        $falta = self::falta_en_la_ficha($empleado);

        if (!is_null($falta)) {

            throw new AccionIaException(422, $falta);
        }

        /*
         * 🔴 PRIMERA CAPA: SI LA FICHA CAMBIÓ, LA TARJETA ESTÁ VIEJA Y SE DICE.
         *
         * La tarjeta muestra "le quedan: A, B" y esa lista se armó con la ficha de ese momento. Si
         * alguien la editó desde ABM > Empleados en el medio, ese renglón dejó de ser verdad, y una
         * tarjeta que afirma algo falso no se confirma en silencio: se vence y se pide de nuevo.
         * Mismo criterio y mismo `referencia_updated_at` que EjecutorGenericoIaHelper y
         * PropuestaTareaIaHelper. La ventana es de hasta 24 h (AiMessageAction::HORAS_VENCIMIENTO).
         */
        self::verificar_que_no_cambio($accion, $empleado);

        /*
         * 🔴 SEGUNDA CAPA, Y NO ES REDUNDANTE: EL PAYLOAD SE REARMA CON EL EMPLEADO DE AHORA.
         *
         * `payload` lleva el MODELO ENTERO (nombre, teléfono, documento, sucursal, admin_access,
         * vendedor) y `EmployeeController::update()` pisa cada columna con lo que le
         * llega: confirmar con el payload congelado revierte, sin que nadie lo vea, todo lo que se
         * haya editado de esa ficha entre la propuesta y el clic. Es exactamente el problema que
         * PropuestaStockIaHelper::ejecutar_stock_en_deposito() resuelve rearmando, y acá duele más,
         * porque un empleado al que le devolvieron un permiso que después se le vuelve a ir no se
         * entera hasta que llega a trabajar.
         *
         * 🔴 Y LA GUARDA DE ARRIBA NO ALCANZA SOLA: un cambio SOLO de permisos (un `sync()` sobre
         * la pivot `permission_empresa_user`) NO toca `users.updated_at`, así que no dispara el 409.
         * Por eso el alta o la baja que pidió el dueño se aplica sobre la lista que el empleado
         * tiene AHORA, y no sobre la congelada: un permiso que le dieron en el medio se conserva.
         */
        $permiso_id = isset($esperado['permiso_id']) ? (int) $esperado['permiso_id'] : 0;

        $que_hago = isset($esperado['accion']) ? (string) $esperado['accion'] : '';

        if ($permiso_id <= 0 || !in_array($que_hago, [self::ACCION_DAR, self::ACCION_SACAR], true)) {

            throw new AccionIaException(500, 'La tarjeta de permisos está incompleta. Pedímela de nuevo.');
        }

        $permiso = PermissionEmpresa::find($permiso_id);

        if (is_null($permiso)) {

            throw new AccionIaException(422, 'Ese permiso ya no existe en el sistema. Pedímelo de nuevo.');
        }

        $finales = self::permisos_actuales($empleado);

        if ($que_hago === self::ACCION_DAR) {

            $finales[$permiso_id] = $permiso;

        } else {

            unset($finales[$permiso_id]);
        }

        $payload = self::payload($empleado, $finales);

        $esperados = array_map('intval', array_keys($finales));

        $request = Request::create('/api/employee/' . $employee_id, 'PUT', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(function () use ($persona) {
            return $persona;
        });

        $respuesta = app(EmployeeController::class)->update($request, $employee_id);

        /*
         * 🔴 SI `update()` NO GUARDÓ, SE DICE POR QUÉ, CON SU PROPIO MOTIVO.
         *
         * Desde la misión empleados-alta-y-edicion (9/10/2026) `update()` valida antes de escribir y
         * puede contestar 422 o 404. La ficha incompleta ya la frena `falta_en_la_ficha()` antes de
         * llegar acá, pero quedan casos que solo ve `update()`: p. ej. un documento viejo guardado
         * con espacios, que al recortarlo choca con el de otro usuario ("Ya hay un empleado con ese
         * número de documento"). Antes esa respuesta se ignoraba y lo que llegaba a la persona era
         * la comparación de abajo: "el sistema no dejó los permisos como corresponde", que es
         * engañoso — no es que los dejó mal, es que no guardó nada, y hay algo de la ficha para
         * corregir. Como `update()` valida todo antes de tocar la base, un error garantiza que no
         * hubo escritura a medias.
         *
         * Siempre 422, con el motivo de `update()`: es el único status que el ejecutor convierte en
         * "no se pudo, por esto" (`respuesta_de_negocio()` manda todo lo que no es 404 ni 422 a un
         * 409 de "tarjeta resuelta", que acá sería falso). Un 500 de verdad no llega como respuesta:
         * `update()` tira la excepción y la atrapa el ejecutor.
         */
        if ($respuesta instanceof JsonResponse && $respuesta->getStatusCode() >= 400) {

            $cuerpo = $respuesta->getData(true);

            $motivo = is_array($cuerpo) && isset($cuerpo['message']) && is_string($cuerpo['message'])
                ? trim($cuerpo['message'])
                : '';

            if ($motivo === '') {
                $motivo = 'El sistema no dejó guardar la ficha de ' . $empleado->name;
            }

            throw new AccionIaException(422, rtrim($motivo, '.') . '. Corregilo en ABM > Empleados.');
        }

        $quedaron = [];

        foreach (User::find($employee_id)->permissions as $permiso) {
            $quedaron[] = (int) $permiso->id;
        }

        sort($quedaron);
        sort($esperados);

        if ($quedaron !== array_values(array_unique($esperados))) {

            throw new AccionIaException(
                422,
                'El sistema no dejó los permisos como corresponde: ' . $empleado->name . ' quedó con ' . count($quedaron)
                . ' permisos y tenía que quedar con ' . count(array_unique($esperados))
                . '. No lo doy por hecho: revisalo en ABM > Empleados.'
            );
        }

        $nombres = [];

        foreach (User::find($employee_id)->permissions as $permiso) {
            $nombres[] = (string) $permiso->name;
        }

        sort($nombres);

        return [
            'texto' => 'Permisos de ' . $empleado->name . ' actualizados. Le quedan: '
                . (count($nombres) ? implode(', ', $nombres) : 'ninguno'),
            'ruta'  => [
                'name'   => self::RUTA_EMPLEADOS,
                'params' => new \stdClass(),
                'texto'  => 'Ver en Empleados',
            ],
        ];
    }

    /**
     * El payload de `PUT api/employee/{id}`, con el MODELO ENTERO como lo manda `getModelToSend()`
     * de la SPA (que es un spread del modelo, no una selección de campos).
     *
     * 🔴 CADA CLAVE DE ACÁ ES UNA COLUMNA QUE `update()` PISA CON LO QUE LE LLEGUE. No es una lista
     * de "lo que queremos cambiar": es la lista de lo que se borraría si no viajara. Por eso todas
     * salen del empleado tal como está hoy, y la única que cambia es `permissions`.
     *
     * La excepción son `default_version` / `estable_version`: desde la misión
     * empleados-alta-y-edicion (9/10/2026) `update()` las IGNORA. `default_version` la escribe el
     * admin en cada rotación de frente; `estable_version` es interna (la URL de un frente), el dueño
     * no tiene qué poner ahí y se dejó de escribir desde la pantalla de Empleados para que nadie la
     * cambie sin saber. Siguen viajando porque no molestan (igual que en la SPA, que manda el modelo
     * entero), pero ya no son "una columna que se pisaría".
     *
     * `permissions` va como array de OBJETOS (el endpoint lee `$permission['id']`), que es lo que
     * manda la SPA: `employee.js` no declara `send_belongs_to_many_ids_as`, así que la relación
     * viaja entera.
     *
     * @param  \App\Models\User  $empleado
     * @param  array<int, \App\Models\PermissionEmpresa>  $permisos  Indexados por id.
     * @return array<string, mixed>
     */
    public static function payload(User $empleado, array $permisos): array
    {
        $lista = [];

        foreach ($permisos as $permiso) {

            $lista[] = [
                'id'         => (int) $permiso->id,
                'name'       => (string) $permiso->name,
                'model_name' => $permiso->model_name,
                'slug'       => (string) $permiso->slug,
            ];
        }

        return [
            // 🔴 update() lee `$request->id`, NO el {id} de la URL: los dos van y tienen que coincidir.
            'id'                                        => (int) $empleado->id,
            'name'                                      => $empleado->name,
            'phone'                                     => $empleado->phone,
            'doc_number'                                => $empleado->doc_number,
            'address_id'                                => $empleado->address_id,
            // 🔴 La de hoy: update() la vuelve a hashear en cada guardado, y vacía da 422 (trampa 2).
            'visible_password'                          => (string) $empleado->visible_password,
            'admin_access'                              => $empleado->admin_access,
            'dias_alertar_empleados_ventas_no_cobradas' => $empleado->dias_alertar_empleados_ventas_no_cobradas,
            'ver_alertas_de_todos_los_empleados'        => $empleado->ver_alertas_de_todos_los_empleados,
            'puede_guardar_ventas_sin_cliente'          => $empleado->puede_guardar_ventas_sin_cliente,
            // update() las ignora (ver el docblock); viajan igual, como en la SPA.
            'default_version'                           => $empleado->default_version,
            'estable_version'                           => $empleado->estable_version,
            'seller_id'                                 => $empleado->seller_id,
            'permissions'                               => $lista,
        ];
    }

    /**
     * La ficha del empleado no cambió desde que se armó la tarjeta.
     *
     * Mismo mecanismo que `EjecutorGenericoIaHelper::verificar_que_no_cambio()`: 409 con la tarjeta
     * vencida, para que la persona pida una nueva en vez de confirmar un renglón que ya no dice la
     * verdad. Se compara al segundo, que es la precisión de la columna.
     *
     * @param  \App\Models\AiMessageAction  $accion
     * @param  \App\Models\User  $empleado
     * @return void
     *
     * @throws AccionIaException
     */
    protected static function verificar_que_no_cambio(AiMessageAction $accion, User $empleado)
    {
        $referencia = $accion->referencia_updated_at;

        $referencia = is_null($referencia) ? null : Carbon::parse($referencia)->format('Y-m-d H:i:s');

        $actual = is_null($empleado->updated_at) ? null : Carbon::parse($empleado->updated_at)->format('Y-m-d H:i:s');

        if ($referencia !== $actual) {

            throw new AccionIaException(
                409,
                'La ficha de ' . $empleado->name . ' cambió después de que armé la tarjeta, así que lo que decía sobre sus permisos '
                . 'puede haber quedado viejo. Pedímelo de nuevo y te la armo con lo que tiene ahora.',
                AiMessageAction::ESTADO_VENCIDA
            );
        }
    }

    /**
     * Lo que le falta a la ficha del empleado para que `EmployeeController::update()` la pueda
     * guardar, como texto para la persona; null si está completa.
     *
     * Espejo de `EmployeeController::validar_datos_del_empleado()` (misión empleados-alta-y-edicion,
     * 9/10/2026): nombre, documento y contraseña son obligatorios también al editar, y con
     * cualquiera vacío `update()` contesta 422 sin escribir nada. Este helper manda la ficha TAL
     * COMO ESTÁ (no puede inventar un documento ni una contraseña), así que si le falta algo, el
     * cambio de permisos no se puede hacer desde el chat: se carga en ABM > Empleados.
     *
     * Se usa dos veces y no es redundante: al PROPONER, para no armar una tarjeta que no se va a
     * poder confirmar nunca; y al CONFIRMAR, porque la ficha pudo cambiar en el medio.
     *
     * La contraseña se mira recortada (más estricto que `update()`, que solo rechaza la vacía): una
     * "contraseña" de puros espacios tampoco es algo que el chat deba reescribir.
     *
     * @param  \App\Models\User  $empleado
     * @return string|null
     */
    public static function falta_en_la_ficha(User $empleado)
    {
        $nombre = trim((string) $empleado->name);

        if ($nombre === '') {

            return 'Ese empleado no tiene el nombre cargado en su ficha, y sin eso no se pueden guardar sus permisos desde acá. '
                . 'Cargáselo en ABM > Empleados.';
        }

        if (trim((string) $empleado->doc_number) === '') {

            return $nombre . ' no tiene cargado el número de documento, y sin eso no se pueden guardar sus permisos desde acá. '
                . 'Cargáselo en ABM > Empleados.';
        }

        if (trim((string) $empleado->visible_password) === '') {

            return $nombre . ' no tiene la contraseña visible cargada en su ficha, y sin eso no se pueden guardar sus permisos desde acá. '
                . 'Cargásela en ABM > Empleados.';
        }

        return null;
    }

    /**
     * Los permisos que el empleado tiene HOY, indexados por id.
     *
     * @param  \App\Models\User  $empleado
     * @return array<int, \App\Models\PermissionEmpresa>
     */
    public static function permisos_actuales(User $empleado): array
    {
        $actuales = [];

        foreach ($empleado->permissions as $permiso) {

            $actuales[(int) $permiso->id] = $permiso;
        }

        return $actuales;
    }

    /**
     * El empleado que nombró la persona, o la respuesta de negocio con los candidatos.
     *
     * Solo entre los del dueño (`users.owner_id`), que es el universo de `EmployeeController::index()`.
     * 🔴 El DUEÑO no es un empleado y no entra: sus permisos no se administran desde ahí, y
     * "sacarle" uno no tendría ningún efecto (`can()` le devuelve true a todo por ser dueño).
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  string  $texto
     * @return \App\Models\User|array
     */
    protected static function resolver_empleado(ContextoDeCargaIa $contexto, $texto)
    {
        $texto = trim((string) $texto);

        $empleados = User::where('owner_id', $contexto->owner_id)->orderBy('name')->get();

        $opciones = [];

        foreach ($empleados as $empleado) {
            $opciones[] = ['nombre' => (string) $empleado->name];
        }

        if (!count($empleados)) {

            return RespuestaDeCargaIa::error('Este negocio no tiene ningún empleado cargado.');
        }

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['a qué empleado'], ['empleados' => $opciones]);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        $exactos = [];
        $parciales = [];

        foreach ($empleados as $empleado) {

            $nombre = ConsultasSistemaIaHelper::normalize_text((string) $empleado->name);

            if ($nombre === $normalizado) {

                $exactos[] = $empleado;

            } elseif ($nombre !== '' && strpos($nombre, $normalizado) !== false) {

                $parciales[] = $empleado;
            }
        }

        $encontrados = count($exactos) ? $exactos : $parciales;

        if (!count($encontrados)) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún empleado que se llame "' . $texto . '" en este negocio.',
                ['empleados' => $opciones]
            );
        }

        if (count($encontrados) > 1) {

            $candidatos = [];

            foreach (array_slice($encontrados, 0, self::TOPE_CANDIDATOS) as $empleado) {
                $candidatos[] = ['nombre' => (string) $empleado->name];
            }

            return RespuestaDeCargaIa::faltan(['cuál de estos empleados'], ['empleados' => $candidatos]);
        }

        return $encontrados[0];
    }

    /**
     * El permiso que nombró la persona, por SLUG o por NOMBRE, o la respuesta de negocio.
     *
     * 🔴 El catálogo es `PermissionEmpresa::all()` (`GET api/permission`), tabla
     * `permission_empresas`. NO `PermissionBeta`, que es otra tabla con otro pivot y sirve para los
     * feature flags por plan y por extensión: el modelo `User` ni siquiera tiene relación con ella.
     *
     * Se busca primero por slug exacto (que es lo que compara `can()`) y después por nombre, que es
     * lo que la persona ve en la pantalla ("Ver el listado de ventas" es `sale.index`).
     *
     * @param  string  $texto
     * @return \App\Models\PermissionEmpresa|array
     */
    protected static function resolver_permiso($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {

            return RespuestaDeCargaIa::faltan(['qué permiso']);
        }

        $normalizado = ConsultasSistemaIaHelper::normalize_text($texto);

        $todos = PermissionEmpresa::orderBy('name')->get();

        $por_slug = [];
        $por_nombre = [];
        $parciales = [];

        foreach ($todos as $permiso) {

            if (ConsultasSistemaIaHelper::normalize_text((string) $permiso->slug) === $normalizado) {

                $por_slug[] = $permiso;

            } elseif (ConsultasSistemaIaHelper::normalize_text((string) $permiso->name) === $normalizado) {

                $por_nombre[] = $permiso;

            } elseif ($normalizado !== '' && strpos(ConsultasSistemaIaHelper::normalize_text((string) $permiso->name), $normalizado) !== false) {

                $parciales[] = $permiso;
            }
        }

        if (count($por_slug)) {

            return $por_slug[0];
        }

        if (count($por_nombre) === 1) {

            return $por_nombre[0];
        }

        $encontrados = count($por_nombre) ? $por_nombre : $parciales;

        if (!count($encontrados)) {

            return RespuestaDeCargaIa::error(
                'No encontré ningún permiso que se llame "' . $texto . '". Los permisos se llaman como en la pantalla de Empleados '
                . '("Ver el listado de ventas", "Ver clientes"), o por su código ("sale.index").'
            );
        }

        if (count($encontrados) > 1) {

            $candidatos = [];

            foreach (array_slice($encontrados, 0, self::TOPE_CANDIDATOS) as $permiso) {
                $candidatos[] = ['nombre' => (string) $permiso->name, 'codigo' => (string) $permiso->slug];
            }

            return RespuestaDeCargaIa::faltan(['cuál de estos permisos'], ['permisos' => $candidatos]);
        }

        return $encontrados[0];
    }

    /**
     * Los nombres de una lista de permisos, ordenados, para la tarjeta.
     *
     * @param  array<int, \App\Models\PermissionEmpresa>  $permisos
     * @return array<int, string>
     */
    protected static function nombres_de(array $permisos): array
    {
        $nombres = [];

        foreach ($permisos as $permiso) {
            $nombres[] = (string) $permiso->name;
        }

        sort($nombres);

        return $nombres;
    }
}
