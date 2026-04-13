<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserPaymentExpiredAtController extends Controller
{
    public function edit($user_id = null)
    {
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // Cargamos empleados y extensiones para calcular el total mensualidad en la vista
        $user = User::with(['employees', 'extencions'])->findOrFail($user_id);

        return view('user.payment_expired_at.edit', compact('user'));
    }

    public function update(Request $request, $user_id)
    {
        // Cargamos empleados y extensiones para recalcular el total_mensualidad
        $user = User::with(['employees', 'extencions'])->findOrFail($user_id);

        $request->validate([
            'payment_expired_at'    => ['required', 'date'],
            'precio_por_cuenta'     => ['required', 'numeric', 'min:0'],
            'precio_ecommerce'      => ['nullable', 'numeric', 'min:0'],
            'precio_mercado_libre'  => ['nullable', 'numeric', 'min:0'],
            'precio_tienda_nube'    => ['nullable', 'numeric', 'min:0'],
        ]);

        // Precio base por cuenta
        $precio_por_cuenta = $request->precio_por_cuenta;

        // Precios individuales por servicio; si vienen vacíos se guardan como null
        // y el cálculo usará precio_por_cuenta como fallback
        $precio_ecommerce       = $request->filled('precio_ecommerce')      ? $request->precio_ecommerce      : null;
        $precio_mercado_libre   = $request->filled('precio_mercado_libre')  ? $request->precio_mercado_libre  : null;
        $precio_tienda_nube     = $request->filled('precio_tienda_nube')    ? $request->precio_tienda_nube    : null;

        // Cuentas base: dueño (1) + cantidad de empleados
        $cuentas_base = $user->employees->count() + 1;

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

        // Total mensualidad sumando cada concepto con su precio correspondiente
        $total_mensualidad = ($precio_por_cuenta            * $cuentas_base)
                           + ($precio_ecommerce_efectivo    * $cuentas_ecommerce)
                           + ($precio_mercado_libre_efectivo * $cuentas_mercado_libre)
                           + ($precio_tienda_nube_efectivo  * $cuentas_tienda_nube);

        $user->payment_expired_at   = $request->payment_expired_at;
        $user->precio_por_cuenta    = $precio_por_cuenta;
        $user->precio_ecommerce     = $precio_ecommerce;
        $user->precio_mercado_libre = $precio_mercado_libre;
        $user->precio_tienda_nube   = $precio_tienda_nube;
        $user->total_mensualidad    = $total_mensualidad;
        $user->save();

        return redirect()
            ->route('users.payment_expired_at.edit', $user->id)
            ->with('success', 'Fecha de pago y total a pagar actualizados correctamente.');
    }
}
