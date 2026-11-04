<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints de sincronización de mensualidad hacia admin-api.
 *
 * Estos endpoints son la capa opcional de sincronización: empresa-api sigue
 * siendo la fuente de verdad del cálculo (mismo cálculo que
 * UserPaymentExpiredAtController, usado por la vista Blade). Admin solo
 * consume/actualiza estos datos a través del canal admin-sync existente.
 */
class MensualidadController extends Controller
{
    /**
     * GET admin-sync/mensualidad-info/{user_id?}
     *
     * Devuelve el estado actual de la mensualidad de un cliente (precios
     * guardados + fecha de vencimiento), los conteos vivos (empleados,
     * ecommerce, mercado libre, tienda nube) y los datos fiscales cargados
     * en AfipInformation, para que admin arme el mismo desglose que la Blade.
     *
     * @param  int|null $user_id  Id del cliente (dueño). Si es null, se usa config('app.USER_ID').
     * @return JsonResponse
     */
    public function show($user_id = null): JsonResponse
    {
        // Si no viene user_id, se asume el dueño configurado en la instancia (igual que la Blade)
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // Cargamos empleados, extensiones y datos fiscales (con su condición de IVA) para armar el desglose completo
        $user = User::with(['employees', 'extencions', 'afip_information.iva_condition'])->findOrFail($user_id);

        // Slugs de las extensiones asignadas al usuario, usados para detectar mercado_libre / usa_tienda_nube
        $slugs_extenciones = $user->extencions->pluck('slug')->toArray();

        // Conteos vivos: misma lógica que UserPaymentExpiredAtController y la vista Blade
        $conteos = [
            // Empleados: cantidad de usuarios hijos (el dueño se cobra aparte en precio_plan)
            'empleados'     => $user->employees->count(),
            // Ecommerce: +1 si tiene la propiedad 'online' seteada con una URL
            'ecommerce'     => !empty($user->online) ? 1 : 0,
            // Mercado Libre: +1 si tiene la extensión 'mercado_libre'
            'mercado_libre' => in_array('mercado_libre', $slugs_extenciones) ? 1 : 0,
            // Tienda Nube: +1 si tiene la extensión 'usa_tienda_nube'
            'tienda_nube'   => in_array('usa_tienda_nube', $slugs_extenciones) ? 1 : 0,
        ];

        // Datos fiscales (AfipInformation): puede no existir si el cliente no lo cargó todavía
        $afip_information = null;
        if (!is_null($user->afip_information)) {
            $afip_information = [
                'cuit'                => $user->afip_information->cuit,
                'razon_social'        => $user->afip_information->razon_social,
                // Nombre de la condición de IVA (relación iva_condition), null si no está cargada
                'condicion_iva'       => !is_null($user->afip_information->iva_condition) ? $user->afip_information->iva_condition->name : null,
                'domicilio_comercial' => $user->afip_information->domicilio_comercial,
                'ingresos_brutos'     => $user->afip_information->ingresos_brutos,
            ];
        }

        return response()->json([
            'user_id'              => (int) $user->id,
            'company_name'         => $user->company_name,
            'payment_expired_at'   => $user->payment_expired_at ? $user->payment_expired_at->format('Y-m-d') : null,
            'precio_plan'          => $user->precio_plan,
            'precio_por_cuenta'    => $user->precio_por_cuenta,
            'precio_ecommerce'     => $user->precio_ecommerce,
            'precio_mercado_libre' => $user->precio_mercado_libre,
            'precio_tienda_nube'   => $user->precio_tienda_nube,
            'total_mensualidad'    => $user->total_mensualidad,
            'online'               => $user->online,
            'conteos'              => $conteos,
            'afip_information'     => $afip_information,
            // C5 (misión prueba-experiencia-empresa, 3/11/2026): clave nueva y opcional, un admin viejo la ignora.
            'operaciones_mes'      => $this->operaciones_del_mes((int) $user->id),
        ], 200);
    }

    /**
     * Las operaciones del mes calendario en curso (C5, programa «Prueba gratuita de 7 días»): ventas, compras
     * y gastos REGISTRADOS, se facturen o no, en la zona horaria de la app. El admin arma con esto la cuota
     * con excedentes de los planes por operaciones.
     *
     * Lo sembrado por el demo setup (`semilla_registros`) no cuenta: una prueba que todavía no importó sus
     * datos tiene ~150 ventas de ejemplo en el mes, y no son operaciones del negocio.
     *
     * @param int $user_id
     * @return array{mes: string, ventas: int, compras: int, gastos: int, total: int}
     */
    private function operaciones_del_mes($user_id): array
    {
        $desde = Carbon::now()->startOfMonth();
        $hasta = Carbon::now()->endOfMonth();
        $schema = DB::connection()->getSchemaBuilder();
        $hay_marcas = $schema->hasTable('semilla_registros');

        $contar = function ($tabla) use ($user_id, $desde, $hasta, $schema, $hay_marcas) {
            $query = DB::table($tabla)
                ->where('user_id', $user_id)
                ->whereBetween('created_at', [$desde, $hasta]);

            if ($schema->hasColumn($tabla, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($hay_marcas) {
                $query->whereNotExists(function ($sub) use ($tabla) {
                    $sub->select(DB::raw(1))
                        ->from('semilla_registros')
                        ->where('semilla_registros.tabla', $tabla)
                        ->whereColumn('semilla_registros.registro_id', $tabla . '.id');
                });
            }

            return (int) $query->count();
        };

        $ventas  = $contar('sales');
        $compras = $contar('provider_orders');
        $gastos  = $contar('expenses');

        return [
            'mes'     => $desde->format('Y-m'),
            'ventas'  => $ventas,
            'compras' => $compras,
            'gastos'  => $gastos,
            'total'   => $ventas + $compras + $gastos,
        ];
    }

    /**
     * PUT admin-sync/mensualidad-update/{user_id?}
     *
     * Recalcula y persiste la mensualidad de un cliente. Reutiliza EXACTAMENTE
     * el mismo cálculo que UserPaymentExpiredAtController::update (misma
     * validación, mismo total_mensualidad, mismos campos guardados); la única
     * diferencia es que responde JSON en lugar de redirigir.
     *
     * @param  Request  $request
     * @param  int|null $user_id  Id del cliente (dueño). Si es null, se usa config('app.USER_ID').
     * @return JsonResponse
     */
    public function update(Request $request, $user_id = null): JsonResponse
    {
        // Si no viene user_id, se asume el dueño configurado en la instancia (igual que la Blade)
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // Cargamos empleados y extensiones para recalcular el total_mensualidad
        $user = User::with(['employees', 'extencions'])->findOrFail($user_id);

        // Misma validación que UserPaymentExpiredAtController::update
        $request->validate([
            'payment_expired_at'    => ['required', 'date'],
            'precio_plan'           => ['required', 'numeric', 'min:0'],
            'precio_por_cuenta'     => ['required', 'numeric', 'min:0'],
            'precio_ecommerce'      => ['nullable', 'numeric', 'min:0'],
            'precio_mercado_libre'  => ['nullable', 'numeric', 'min:0'],
            'precio_tienda_nube'    => ['nullable', 'numeric', 'min:0'],
        ]);

        // Monto fijo base del plan (sistema), antes de cuentas y módulos
        $precio_plan = $request->precio_plan;

        // Precio por cada usuario empleado
        $precio_por_cuenta = $request->precio_por_cuenta;

        // Precios individuales por servicio; si vienen vacíos se guardan como null
        // y el cálculo usará precio_por_cuenta como fallback
        $precio_ecommerce       = $request->filled('precio_ecommerce')      ? $request->precio_ecommerce      : null;
        $precio_mercado_libre   = $request->filled('precio_mercado_libre')  ? $request->precio_mercado_libre  : null;
        $precio_tienda_nube     = $request->filled('precio_tienda_nube')    ? $request->precio_tienda_nube    : null;

        // Cuentas base: solo cantidad de empleados (el dueño se cobra en precio_plan)
        $cuentas_base = $user->employees->count();

        // Ecommerce: +1 si tiene la propiedad 'online' seteada con una URL
        $cuentas_ecommerce = !empty($user->online) ? 1 : 0;

        // Slugs de las extensiones asignadas al usuario
        $slugs_extenciones = $user->extencions->pluck('slug')->toArray();

        // Mercado Libre: +1 si tiene la extensión 'mercado_libre'
        $cuentas_mercado_libre = in_array('mercado_libre', $slugs_extenciones) ? 1 : 0;

        // Tienda Nube: +1 si tiene la extensión 'usa_tienda_nube'
        $cuentas_tienda_nube = in_array('usa_tienda_nube', $slugs_extenciones) ? 1 : 0;

        // Para cada servicio: si tiene precio individual lo usa, sino usa precio_por_cuenta
        $precio_ecommerce_efectivo      = $precio_ecommerce      ?? $precio_por_cuenta;
        $precio_mercado_libre_efectivo  = $precio_mercado_libre  ?? $precio_por_cuenta;
        $precio_tienda_nube_efectivo    = $precio_tienda_nube    ?? $precio_por_cuenta;

        // Total mensualidad: plan base (dueño) + empleados + módulos ecommerce/ML/TN
        $total_mensualidad = $precio_plan
                           + ($precio_por_cuenta            * $cuentas_base)
                           + ($precio_ecommerce_efectivo    * $cuentas_ecommerce)
                           + ($precio_mercado_libre_efectivo * $cuentas_mercado_libre)
                           + ($precio_tienda_nube_efectivo  * $cuentas_tienda_nube);

        $user->payment_expired_at   = $request->payment_expired_at;
        $user->precio_plan          = $precio_plan;
        $user->precio_por_cuenta    = $precio_por_cuenta;
        $user->precio_ecommerce     = $precio_ecommerce;
        $user->precio_mercado_libre = $precio_mercado_libre;
        $user->precio_tienda_nube   = $precio_tienda_nube;
        $user->total_mensualidad    = $total_mensualidad;
        $user->save();

        return response()->json([
            'ok'                 => true,
            'payment_expired_at' => $user->payment_expired_at,
            'total_mensualidad'  => $user->total_mensualidad,
        ], 200);
    }
}
