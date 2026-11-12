<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\Address;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deja un negocio de la PRUEBA GRATUITA listo para su primera venta (misión prueba-primera-venta,
 * 12/11/2026).
 *
 * En el ensayo del 11/11 el dueño quiso hacer su primera venta y «Guardar venta» le frenó tres veces:
 * todas las cajas cerradas, ninguna caja elegida y ninguna sucursal elegida. La primera venta es el hito
 * central de la prueba, así que la instancia tiene que nacer (y seguir, después del primer import) con:
 *
 *  1. La sucursal del dueño fijada (`users.address_id` = la principal), igual que lo hace el user setup de
 *     un cliente real (`UserSetupHelper`, misión sucursal-siempre). Con eso Vender arranca con la sucursal
 *     puesta: no la adivina el SPA —la decisión D6 de sucursal-siempre sigue en pie: con dos o más
 *     sucursales el SPA no elige—, es la configuración del usuario.
 *  2. La caja de EFECTIVO por defecto de esa sucursal abierta. Vender la elige sola por la configuración de
 *     cajas por defecto (`default_payment_method_cajas`) en cuanto la sucursal y el método están puestos.
 *
 * Por qué hace falta también DESPUÉS del primer import: `SemillaRegistroHelper::borrar_caja_de_ejemplo()`
 * deja en cero y CERRADA toda caja cuyos movimientos eran todos de ejemplo — o sea todas, en una prueba que
 * todavía no vendió. Ese es el «todas las cajas estaban cerradas» del ensayo.
 *
 * 🔴 Solo para la prueba. Lo llaman el demo setup cuando arma una prueba y el borrado de lo sembrado, que solo
 * existe en una prueba activa. Un cliente real no pasa nunca por acá. Nunca rompe a quien lo llama: un fallo
 * se loguea y la instancia queda como estaba.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class PrimeraVentaDeLaPruebaHelper
{
    /** Método de pago «Efectivo», el que Vender pone por defecto (METODO_DE_PAGO_EFECTIVO del SPA). */
    const METODO_EFECTIVO = 3;

    /**
     * Fija la sucursal del dueño y abre su caja de efectivo. Idempotente: lo que ya está bien no se toca.
     *
     * @param int $owner_id
     * @return array{address_id: int|null, caja_abierta_id: int|null} lo que quedó (para el log y los tests).
     */
    public static function dejar_lista_para_vender($owner_id)
    {
        $resultado = ['address_id' => null, 'caja_abierta_id' => null];

        try {
            $owner = User::find((int) $owner_id);

            if (is_null($owner)) {
                return $resultado;
            }

            $principal = self::fijar_sucursal_del_dueno($owner);
            $resultado['address_id'] = is_null($principal) ? null : (int) $principal->id;

            $caja = self::abrir_caja_de_efectivo($owner, $principal);
            $resultado['caja_abierta_id'] = is_null($caja) ? null : (int) $caja->id;

            Log::info('PrimeraVentaDeLaPruebaHelper: el dueño ' . $owner->id . ' quedó listo para vender.', $resultado);
        } catch (Throwable $e) {
            Log::error('PrimeraVentaDeLaPruebaHelper: no se pudo dejar lista la primera venta del dueño ' . $owner_id . ': ' . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Si el dueño no tiene sucursal (o tiene una que ya no existe), le pone la principal.
     *
     * @param \App\Models\User $owner
     * @return \App\Models\Address|null la sucursal con la que vende el dueño, o null si el negocio no tiene.
     */
    private static function fijar_sucursal_del_dueno(User $owner)
    {
        if ($owner->address_id) {
            $actual = Address::sucursales_de($owner->id)->where('id', $owner->address_id)->first();

            if (!is_null($actual)) {
                return $actual;
            }
        }

        $principal = Address::principal_de($owner->id);

        if (is_null($principal)) {
            return null;
        }

        $owner->address_id = $principal->id;
        $owner->save();

        return $principal;
    }

    /**
     * Abre la caja de efectivo por defecto de la sucursal del dueño, si está cerrada.
     *
     * Orden de búsqueda:
     *  1. La caja por defecto de Efectivo para esa sucursal (la que Vender va a elegir sola).
     *  2. Si no hay configuración por defecto y NINGUNA caja está abierta: la primera caja de la sucursal o
     *     compartida (sin sucursal). Con alguna ya abierta no se abre otra: el dueño ya tiene dónde cobrar.
     *
     * @param \App\Models\User $owner
     * @param \App\Models\Address|null $sucursal
     * @return \App\Models\Caja|null la caja abierta para vender, o null si el negocio no tiene cajas.
     */
    private static function abrir_caja_de_efectivo(User $owner, $sucursal)
    {
        $caja = self::caja_por_defecto_de_efectivo($owner, $sucursal);

        if (is_null($caja)) {
            $abierta = Caja::where('user_id', $owner->id)->where('abierta', 1)->orderBy('id')->first();

            if (!is_null($abierta)) {
                return $abierta;
            }

            $caja = Caja::where('user_id', $owner->id)
                ->where(function ($q) use ($sucursal) {
                    $q->whereNull('address_id');
                    if (!is_null($sucursal)) {
                        $q->orWhere('address_id', $sucursal->id);
                    }
                })
                ->orderByRaw('address_id IS NULL')
                ->orderBy('id')
                ->first();
        }

        if (is_null($caja)) {
            return null;
        }

        if ((int) $caja->abierta === 1 && !is_null($caja->current_apertura_caja_id)) {
            return $caja;
        }

        $helper = new CajaAperturaHelper($caja->id);
        $helper->abrir_caja();

        // Fuera de un request no hay usuario logueado: la apertura queda a nombre del dueño.
        if (!is_null($helper->apertura_caja)) {
            AperturaCaja::where('id', $helper->apertura_caja->id)->update(['apertura_employee_id' => $owner->id]);
        }

        return $helper->caja->fresh();
    }

    /**
     * La caja por defecto de Efectivo para la sucursal (o sin sucursal, si el negocio no tiene ninguna).
     *
     * @param \App\Models\User $owner
     * @param \App\Models\Address|null $sucursal
     * @return \App\Models\Caja|null
     */
    private static function caja_por_defecto_de_efectivo(User $owner, $sucursal)
    {
        $config = DefaultPaymentMethodCaja::where('user_id', $owner->id)
            ->where('current_acount_payment_method_id', self::METODO_EFECTIVO)
            ->where(function ($q) use ($sucursal) {
                if (is_null($sucursal)) {
                    $q->whereNull('address_id');
                } else {
                    $q->where('address_id', $sucursal->id);
                }
            })
            ->orderBy('id')
            ->first();

        if (is_null($config)) {
            return null;
        }

        return Caja::where('user_id', $owner->id)->where('id', $config->caja_id)->first();
    }
}
