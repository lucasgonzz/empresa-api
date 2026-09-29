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

    function update(Request $request, $id) {
        $model = User::where('id', $request->id)
                        ->first();
        
        $model->permissions()->sync([]);
        foreach ($request->permissions as $permission) {
            $model->permissions()->attach($permission['id']);
        }
        
        $model->name                                            = $request->name;
        $model->phone                                           = $request->phone;
        $model->address_id                                      = $request->address_id;
        $model->visible_password                                = $request->visible_password;
        $model->admin_access                                    = $request->admin_access;
        $model->dias_alertar_empleados_ventas_no_cobradas       = $request->dias_alertar_empleados_ventas_no_cobradas;
        $model->ver_alertas_de_todos_los_empleados              = $request->ver_alertas_de_todos_los_empleados;
        
        $model->puede_guardar_ventas_sin_cliente                = $request->puede_guardar_ventas_sin_cliente;

        $model->default_version                                 = $request->default_version;
        $model->estable_version                                 = $request->estable_version;
        
        $model->seller_id                                 = $request->seller_id;

        $model->password                                        = bcrypt($request->visible_password);
        if ($model->doc_number == $request->doc_number || !$this->docNumerRegister($request->doc_number)) {
            $model->doc_number          = $request->doc_number;
        }
        $model->save();

        $model = User::where('id', $request->id)
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
     * Se hace en el API y no armando un "empleado nuevo" en la pantalla porque `store()` solo
     * guarda un subconjunto de columnas: por ese camino se perdian el perfil de vendedor y las
     * alertas.
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

    function destroy($id) {
        $user = User::find($id);
        $user->delete();
    }

    function store(Request $request) {
        $user = auth()->user();


        if (!$this->docNumerRegister($request->doc_number)) {
            $model = User::create([
                'name'              => ucfirst($request->name),
                'phone'             => $request->phone,
                'doc_number'        => $request->doc_number,
                'admin_access'      => $request->admin_access,
                'visible_password'  => $request->visible_password,
                'address_id'        => $request->address_id,
                'password'          => Hash::make($request->visible_password),
                'owner_id'          => $this->userId(),
                'created_at'        => Carbon::now(),
            ]);

            foreach ($request->permissions as $permission) {
                $model->permissions()->attach($permission['id']);
            }
            
            $model = User::where('id', $model->id)
                                ->with('permissions')
                                ->first();
            return response()->json(['model' => $model], 201);
        } else {
            return response()->json(['model' => false], 200);
        }
    }

    function docNumerRegister($doc_number) {
        $model = User::where('doc_number', $doc_number)
                        ->first();
        return !is_null($model);
    }
    
}
