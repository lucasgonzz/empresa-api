<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    
    function index() {
        $models = User::where('owner_id', $this->userId())
                    ->with('permissions')
                    ->orderBy('name', 'ASC')
                    ->get();
        return response()->json(['models' => $models], 200);
    }      

    /**
     * Edita un empleado del dueño de la sesión (pantalla ABM > Empleados y el asistente, que entra
     * por `PropuestaPermisoEmpleadoIaHelper::ejecutar()`).
     *
     * Misión empleados-alta-y-edicion (9/10/2026). Antes de esa misión este método:
     *  - resolvía el empleado con `User::where('id', $request->id)` a secas (cualquier usuario de la base);
     *  - con un documento que ya usaba otro usuario, lo IGNORABA en silencio y contestaba 200 con el
     *    documento viejo, después de haber sincronizado los permisos (escritura a medias);
     *  - pisaba `default_version` / `estable_version` con lo que trajera el pedido.
     *
     * @param  Request  $request  El modelo entero, como lo manda `getModelToSend()` de la SPA: id,
     *                            name, doc_number y visible_password (obligatorios), phone,
     *                            address_id, seller_id, admin_access,
     *                            dias_alertar_empleados_ventas_no_cobradas,
     *                            ver_alertas_de_todos_los_empleados, puede_guardar_ventas_sin_cliente
     *                            y permissions (opcional: si no viene como lista, no se tocan).
     * @param  int  $id  El de la URL. Ver abajo por qué se sigue leyendo `$request->id`.
     * @return \Illuminate\Http\JsonResponse  200 con el empleado; 404 si no es del dueño; 422 con
     *                                        `{message}` si los datos no sirven.
     */
    function update(Request $request, $id) {

        /*
            🔴 ACOTADO AL DUEÑO DE LA SESIÓN. Antes era `User::where('id', $request->id)` sin más, y
            eso alcanzaba a CUALQUIER usuario de la base: al propio dueño (cambiarle la contraseña
            desde la pantalla de empleados) y, en una base compartida entre comercios, a los
            empleados de otro. Ningún llamador legítimo apunta afuera de los empleados del dueño: el
            listado (`index()`) filtra por `owner_id` y el asistente también.

            No se agrega el 403 de "solo el dueño o un administrador" que tiene `duplicate()`: hoy
            puede haber empleados que gestionan empleados por permisos, y eso no se corta acá.

            Se sigue leyendo `$request->id` (y no el `{id}` de la URL) porque es el contrato que ya
            documenta el asistente, que manda los dos (trampa 3 de PropuestaPermisoEmpleadoIaHelper).
        */
        $model = User::where('id', $request->id)
                        ->where('owner_id', $this->userId())
                        ->first();

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontró el empleado'], 404);
        }

        /*
            🔴 TODA LA VALIDACIÓN VA ANTES DE CUALQUIER ESCRITURA. Antes, con un documento repetido,
            los permisos ya estaban sincronizados cuando se descubría que el documento no se podía
            guardar, y la respuesta era un 200: una escritura a medias que nadie veía. El asistente
            llama a este método y después compara los permisos; con una escritura a medias daba por
            bueno un guardado que la persona no pidió así.
        */
        list($error, $datos) = $this->validar_datos_del_empleado($request, $model);

        if (!is_null($error)) {
            return $error;
        }

        DB::transaction(function () use ($request, $model, $datos) {

            /*
                Si el request no trae 'permissions' (array) no se tocan los permisos: antes el
                sync([]) los borraba y el foreach reventaba con un 500, dejando al empleado sin
                ninguno. Una lista vacía, en cambio, sí los saca todos (es lo que manda la pantalla
                cuando se destildan).
            */
            if (is_array($request->permissions)) {
                $model->permissions()->sync($this->ids_de_permisos($request->permissions));
            }

            $model->name                = $datos['name'];
            $model->phone               = $datos['phone'];
            // Ya validado: no vacío y, si cambió, sin chocar con el de otro usuario.
            $model->doc_number          = $datos['doc_number'];
            $model->visible_password    = $datos['visible_password'];
            // Ya validada no vacía: con la contraseña vacía el empleado no entraría más.
            $model->password            = Hash::make($datos['visible_password']);

            /*
                🔴 `default_version` y `estable_version` NO SE ESCRIBEN ACÁ, aunque vengan en el
                pedido (la SPA manda el modelo entero). Decisión de Lucas, 9/10/2026. Son dos casos
                distintos:

                - `default_version` la escribe el admin, en el dueño y en todos sus empleados, en
                  cada rotación de frente (`AdminSync\UpdateDefaultVersionController`). Si este
                  método la pisara, un listado cargado ANTES de la rotación devolvería al empleado al
                  frente viejo con solo guardarlo.
                - `estable_version` NO la escribe nadie más para un empleado (el admin no la toca;
                  `UserController@update` la escribe solo para el usuario logueado, y Duplicar la
                  copia del original). Es un dato interno —la URL de un frente— y el dueño no tiene
                  qué poner ahí: se deja de escribir desde este formulario para que nadie la cambie
                  sin saber. Si un empleado quedara con una vieja, se corrige en la base.
            */
            foreach ($this->perfil_del_empleado($request) as $columna => $valor) {
                $model->{$columna} = $valor;
            }

            $model->save();
        });

        $model = User::where('id', $model->id)
                        ->with('permissions')
                        ->first();
        return response()->json(['model' => $model], 200);
    }

    /**
     * Duplica un empleado: crea uno nuevo con el mismo perfil de acceso.
     *
     * Copia TODO lo que define que puede hacer y ver el empleado, no solo los permisos:
     *  - permisos (`permission_empresa_user`)
     *  - cajas que puede usar en Vender (`caja_user`) y cajas que ve en Tesoreria (`caja_treasury_user`)
     *  - sucursal, perfil de vendedor, acceso de administrador, alertas de ventas no cobradas,
     *    guardar ventas sin cliente y las versiones por defecto / estable.
     *
     * Lo que NO se copia, a proposito: nombre, documento, telefono y contrasena (son de la
     * persona nueva y los pide el formulario), la sesion (`session_id`, `login_at`...), y la caja
     * PROPIA (`cajas.employee_id`), que pertenece a un solo empleado.
     *
     * Se hizo en el API y no armando un "empleado nuevo" en la pantalla porque, cuando nació
     * Duplicar (29/9/2026), `store()` solo guardaba un subconjunto de columnas: por ese camino se
     * perdían el perfil de vendedor y las alertas. Desde el 9/10/2026 (misión
     * empleados-alta-y-edicion) el alta guarda el perfil completo, pero Duplicar sigue en el API
     * porque además copia las cajas y los permisos del original.
     *
     * @param  Request  $request  name, doc_number y visible_password (obligatorios); phone opcional.
     * @param  int  $id  Empleado a copiar. Tiene que ser del mismo dueno que el usuario logueado.
     * @return \Illuminate\Http\JsonResponse
     */
    function duplicate(Request $request, $id) {

        $user = auth()->user();

        // Mismo criterio que la pantalla de empleados: solo el dueno o quien tiene acceso de administrador.
        if (!is_null($user->owner_id) && !$user->admin_access) {
            return response()->json(['message' => 'No tenes permiso para duplicar empleados'], 403);
        }

        $origen = User::where('id', $id)
                        ->where('owner_id', $this->userId())
                        ->first();

        if (is_null($origen)) {
            return response()->json(['message' => 'No se encontro el empleado a duplicar'], 404);
        }

        // Los campos del formulario son texto: si llega otra cosa (un array, por ejemplo) se descarta
        // en vez de romper con un 500.
        $name = is_scalar($request->name) ? trim((string) $request->name) : '';
        $doc_number = is_scalar($request->doc_number) ? trim((string) $request->doc_number) : '';
        $password = is_scalar($request->visible_password) ? (string) $request->visible_password : '';
        $phone = is_scalar($request->phone) ? trim((string) $request->phone) : null;

        if ($name == '' || $doc_number == '' || $password == '') {
            return response()->json(['message' => 'Completa el nombre, el numero de documento y la contrasena del nuevo empleado'], 422);
        }

        // Las columnas son varchar(128): mas largo que eso en modo estricto de MySQL da un error 500.
        if (mb_strlen($name) > 128 || mb_strlen($doc_number) > 128 || mb_strlen($password) > 128 || mb_strlen((string) $phone) > 128) {
            return response()->json(['message' => 'El nombre, el documento, la contrasena y el telefono no pueden tener mas de 128 caracteres'], 422);
        }

        if ($this->docNumerRegister($doc_number)) {
            return response()->json(['message' => 'Ya hay un empleado con ese numero de documento'], 422);
        }

        $copia = DB::transaction(function () use ($origen, $name, $doc_number, $password, $phone) {

            $copia = User::create([
                'name'                                          => ucfirst($name),
                'phone'                                         => $phone,
                'doc_number'                                    => $doc_number,
                'visible_password'                              => $password,
                'password'                                      => Hash::make($password),
                'owner_id'                                      => $origen->owner_id,
                'created_at'                                    => Carbon::now(),

                'address_id'                                    => $origen->address_id,
                'seller_id'                                     => $origen->seller_id,
                'admin_access'                                  => $origen->admin_access,
                'dias_alertar_empleados_ventas_no_cobradas'     => $origen->dias_alertar_empleados_ventas_no_cobradas,
                'ver_alertas_de_todos_los_empleados'            => $origen->ver_alertas_de_todos_los_empleados,
                'puede_guardar_ventas_sin_cliente'              => $origen->puede_guardar_ventas_sin_cliente,
                'default_version'                               => $origen->default_version,
                'estable_version'                               => $origen->estable_version,
            ]);

            $copia->permissions()->sync($origen->permissions()->pluck('permission_empresas.id')->all());

            $this->copiar_cajas_del_empleado($origen->id, $copia->id);

            return $copia;
        });

        $model = User::where('id', $copia->id)
                        ->with('permissions')
                        ->first();

        return response()->json(['model' => $model], 201);
    }

    /**
     * Le da al empleado nuevo las mismas cajas que tiene el original, en las dos pivot.
     *
     * Solo copia las filas que existen: `caja_treasury_user` vacia significa "fallback a las cajas
     * de Vender" (ver Caja::treasury_users), y copiar el vacio conserva esa misma regla.
     *
     * @param  int  $origen_id
     * @param  int  $copia_id
     * @return void
     */
    function copiar_cajas_del_empleado($origen_id, $copia_id) {

        foreach (['caja_user', 'caja_treasury_user'] as $tabla) {

            $caja_ids = DB::table($tabla)
                            ->where('user_id', $origen_id)
                            ->pluck('caja_id')
                            ->unique();

            foreach ($caja_ids as $caja_id) {
                DB::table($tabla)->insert([
                    'user_id'       => $copia_id,
                    'caja_id'       => $caja_id,
                    'created_at'    => Carbon::now(),
                    'updated_at'    => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Borra un empleado del dueño de la sesión.
     *
     * 🔴 Acotado al dueño (misión empleados-alta-y-edicion, 9/10/2026): antes era `User::find($id)`
     * a secas, que alcanzaba a cualquier usuario de la base, el dueño incluido, y en una base
     * compartida a los de otro comercio. Mismo criterio que `update()`.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse|void  404 con `{message}` si no es un empleado del dueño.
     */
    function destroy($id) {
        $user = User::where('id', $id)
                        ->where('owner_id', $this->userId())
                        ->first();

        if (is_null($user)) {
            return response()->json(['message' => 'No se encontró el empleado'], 404);
        }

        $user->delete();
    }

    /**
     * Da de alta un empleado del dueño de la sesión.
     *
     * Misión empleados-alta-y-edicion (9/10/2026). Antes de esa misión:
     *  - guardaba solo nombre, teléfono, documento, acceso de administrador, contraseña y sucursal:
     *    el perfil de vendedor, los días para alertar ventas no cobradas, "ver las de TODOS" y
     *    "guardar ventas sin cliente" SE PERDÍAN EN SILENCIO al crear. Ahora guarda lo mismo que
     *    `update()`;
     *  - con un documento repetido contestaba `{model: false}` con 200: la SPA avisaba con un toast
     *    pero el formulario se cerraba y se perdía lo escrito. Ahora es un 422 con el motivo, como
     *    `duplicate()`, y el modal queda abierto (el `catch` del guardado de la SPA lo muestra);
     *  - un alta sin la clave `permissions` daba 500 (`foreach` sobre null).
     *
     * @param  Request  $request  name, doc_number y visible_password (obligatorios), phone,
     *                            address_id, seller_id, admin_access,
     *                            dias_alertar_empleados_ventas_no_cobradas,
     *                            ver_alertas_de_todos_los_empleados, puede_guardar_ventas_sin_cliente
     *                            y permissions (opcional).
     * @return \Illuminate\Http\JsonResponse  201 con el empleado y sus permisos; 422 con `{message}`.
     */
    function store(Request $request) {

        // Validación completa ANTES de escribir nada (ver el mismo comentario en update()).
        list($error, $datos) = $this->validar_datos_del_empleado($request);

        if (!is_null($error)) {
            return $error;
        }

        $model = DB::transaction(function () use ($request, $datos) {

            /*
                🔴 Sin `default_version` / `estable_version`, aunque vengan: quedan en null y el
                empleado usa las del dueño (`check_version.js` y `BtnVersionEstable.vue` de la SPA
                caen al `owner`). `default_version` la escribe el admin en cada rotación de frente;
                `estable_version` es interna y no se carga desde este formulario. Ver update().
            */
            $model = User::create(array_merge([
                'name'              => ucfirst($datos['name']),
                'phone'             => $datos['phone'],
                'doc_number'        => $datos['doc_number'],
                'visible_password'  => $datos['visible_password'],
                'password'          => Hash::make($datos['visible_password']),
                'owner_id'          => $this->userId(),
                'created_at'        => Carbon::now(),
            ], $this->perfil_del_empleado($request)));

            // Solo si viene como lista: un alta sin permisos es válida (antes daba 500).
            if (is_array($request->permissions)) {
                $model->permissions()->sync($this->ids_de_permisos($request->permissions));
            }

            return $model;
        });

        $model = User::where('id', $model->id)
                            ->with('permissions')
                            ->first();
        return response()->json(['model' => $model], 201);
    }

    /**
     * Valida los datos de la PERSONA (nombre, documento, contraseña y teléfono) para el alta y la
     * edición, y los devuelve normalizados.
     *
     * Mismas reglas que `duplicate()`, que NO usa este método a propósito: tiene sus propios tests
     * y su propio texto ("del nuevo empleado"). Nombre, documento y contraseña son obligatorios en
     * el alta Y en la edición (decisión de Lucas, 9/10/2026): sin documento o sin contraseña el
     * empleado no puede iniciar sesión (`AuthController::login` es `Auth::attempt(['doc_number'
     * => ...])`), y sin nombre no se lo distingue en ninguna pantalla.
     *
     * - Los campos son texto: si llega otra cosa (un array, por ejemplo) se toma como vacío en vez
     *   de romper con un 500.
     * - Nombre, documento y teléfono se recortan acá. La contraseña no se recorta acá, como en
     *   `duplicate()`; ojo que en el camino HTTP igual llega recortada: el middleware global
     *   `TrimStrings` (app/Http/Kernel.php) recorta todo el pedido salvo `password`,
     *   `current_password` y `password_confirmation`, y `visible_password` no está en esa lista
     *   (preexistente, no es de esta misión). El recorte de acá es el que vale para un pedido
     *   armado por dentro, como el del asistente (`Request::create()`, sin middleware).
     * - Largos: nombre, documento y contraseña son `varchar(128)`; el teléfono es `string()` sin
     *   largo en la migración, o sea `varchar(191)` por `Schema::defaultStringLength(191)` de
     *   AppServiceProvider. Pasarse en modo estricto de MySQL es un 500.
     * - 🔴 La unicidad del documento es GLOBAL, no por dueño: el login busca por `doc_number` sin
     *   mirar el dueño, así que dos usuarios con el mismo documento chocarían al entrar.
     * - 🔴 En la edición, la unicidad se chequea SOLO SI EL DOCUMENTO CAMBIÓ, y excluyendo al
     *   propio empleado. No es un descuido: hay empleados viejos cuyo documento ya choca con el de
     *   otro usuario (de antes de esta validación), y tienen que poder seguir guardándose sin
     *   tocarlo. Chequearlo siempre los dejaría imposibles de editar.
     * - 🔴 "Cambió" se compara contra el valor CRUDO de la base, sin recortarlo: un documento viejo
     *   guardado con espacios que ahora llega recortado SÍ cuenta como cambio y se chequea. Si se
     *   comparara recortado, se escribiría el recortado sin chequear y podrían quedar dos usuarios
     *   con el mismo documento, y uno de los dos dejaría de poder entrar.
     *
     * @param  Request  $request
     * @param  \App\Models\User|null  $empleado  El que se edita; null en el alta.
     * @return array  `[$error, $datos]`: si algo no sirve, `$error` es el 422 listo para devolver
     *                (forma `{message}`, sin `errors`, igual que `duplicate()`) y `$datos` es null;
     *                si no, `$error` es null y `$datos` trae name, doc_number, visible_password y
     *                phone normalizados.
     */
    private function validar_datos_del_empleado(Request $request, $empleado = null) {

        $name = is_scalar($request->name) ? trim((string) $request->name) : '';
        $doc_number = is_scalar($request->doc_number) ? trim((string) $request->doc_number) : '';
        $password = is_scalar($request->visible_password) ? (string) $request->visible_password : '';
        $phone = is_scalar($request->phone) ? trim((string) $request->phone) : null;

        if ($name == '' || $doc_number == '' || $password == '') {
            return [
                response()->json(['message' => 'Completá el nombre, el número de documento y la contraseña del empleado'], 422),
                null,
            ];
        }

        /*
            Nombre, documento y contraseña son varchar(128); el teléfono, varchar(191) (ver el
            docblock). Más largo que eso en modo estricto de MySQL da un error 500. El texto nombra
            los dos topes para que sea verdad cualquiera sea el campo que se pasó.
        */
        if (mb_strlen($name) > 128 || mb_strlen($doc_number) > 128 || mb_strlen($password) > 128 || mb_strlen((string) $phone) > 191) {
            return [
                response()->json(['message' => 'El nombre, el documento y la contraseña no pueden tener más de 128 caracteres, ni el teléfono más de 191'], 422),
                null,
            ];
        }

        if (is_null($empleado)) {

            $repetido = $this->docNumerRegister($doc_number);

        } else {

            // Contra el valor CRUDO, sin trim: ver el último punto del docblock.
            $cambio = $doc_number !== (string) $empleado->doc_number;

            $repetido = $cambio && User::where('doc_number', $doc_number)
                                        ->where('id', '!=', $empleado->id)
                                        ->exists();
        }

        if ($repetido) {
            return [
                response()->json(['message' => 'Ya hay un empleado con ese número de documento'], 422),
                null,
            ];
        }

        return [
            null,
            [
                'name'              => $name,
                'doc_number'        => $doc_number,
                'visible_password'  => $password,
                'phone'             => $phone,
            ],
        ];
    }

    /**
     * Las columnas de PERFIL del empleado (qué ve y qué puede hacer), normalizadas para la base.
     * Las usan `store()` y `update()`, para que el alta guarde exactamente lo mismo que la edición:
     * que el alta guardara un subconjunto es justamente lo que perdía el perfil de vendedor y las
     * alertas al crear.
     *
     * - Las tildes van a 0/1. 🔴 `puede_guardar_ventas_sin_cliente` es `NOT NULL`: un `null`
     *   explícito (la tilde ausente en el pedido, o una extensión apagada que no la muestra) da
     *   error de SQL. Por eso se normaliza en vez de pasar lo que llegue.
     * - Los días: un número va como entero; vacío o no numérico, null (= el valor general de la
     *   configuración, como dice la ayuda del formulario).
     * - Sucursal y perfil de vendedor: vacío o 0 (el "sin elegir" de los select) va como null.
     *
     * NO incluye `default_version` / `estable_version`: ver update().
     *
     * @param  Request  $request
     * @return array<string, int|null>
     */
    private function perfil_del_empleado(Request $request) {

        $dias = $request->dias_alertar_empleados_ventas_no_cobradas;

        return [
            'address_id'                                    => $this->id_o_null($request->address_id),
            'seller_id'                                     => $this->id_o_null($request->seller_id),
            'admin_access'                                  => $this->tilde($request->admin_access),
            'dias_alertar_empleados_ventas_no_cobradas'     => is_numeric($dias) ? (int) $dias : null,
            'ver_alertas_de_todos_los_empleados'            => $this->tilde($request->ver_alertas_de_todos_los_empleados),
            'puede_guardar_ventas_sin_cliente'              => $this->tilde($request->puede_guardar_ventas_sin_cliente),
        ];
    }

    /**
     * Una tilde del formulario, como 0 o 1.
     *
     * La SPA manda 0/1 o true/false; ausente o null es 0. Los textos "0", "false" y "null" también
     * son 0 (un pedido armado como formulario los manda así, y `(bool) "false"` sería true).
     *
     * @param  mixed  $valor
     * @return int
     */
    private function tilde($valor) {

        if (is_null($valor) || is_array($valor)) {
            return 0;
        }

        if (is_string($valor)) {
            return in_array(strtolower(trim($valor)), ['', '0', 'false', 'null'], true) ? 0 : 1;
        }

        return $valor ? 1 : 0;
    }

    /**
     * El id de un select del formulario, o null si no se eligió nada (vacío, 0 o algo que no es un
     * número).
     *
     * @param  mixed  $valor
     * @return int|null
     */
    private function id_o_null($valor) {

        if (is_numeric($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        return null;
    }

    /**
     * Los ids de la lista `permissions` del pedido. La SPA manda la relación entera (objetos con
     * `id`): `employee.js` no declara `send_belongs_to_many_ids_as`.
     *
     * Se llama solo cuando `permissions` es un array; el "no vino" lo decide el que llama, porque
     * no es lo mismo que una lista vacía (esa saca todos los permisos).
     *
     * @param  array  $permissions
     * @return array<int, mixed>
     */
    private function ids_de_permisos(array $permissions) {

        $ids = [];

        foreach ($permissions as $permission) {
            $ids[] = $permission['id'];
        }

        return $ids;
    }

    function docNumerRegister($doc_number) {
        $model = User::where('doc_number', $doc_number)
                        ->first();
        return !is_null($model);
    }
    
}
