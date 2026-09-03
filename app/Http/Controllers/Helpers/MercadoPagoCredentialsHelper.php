<?php

namespace App\Http\Controllers\Helpers;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve con qué credenciales de Mercado Pago cobra un comercio.
 *
 * EL UNICO LUGAR QUE CONOCE EL FALLBACK. Durante esta mision (y hasta que la tienda tambien
 * lea del conector) hay dos lugares posibles donde puede estar la credencial de cobro de un
 * comercio, y el orden importa:
 *
 * 1. `platform_connectors` — el conector de `mercado_pago` del comercio, si esta conectado.
 *    Es lo que deja el OAuth desde esta mision y lo que va a quedar cuando se limpie el resto.
 * 2. `payment_methods` — la fila del tipo "MercadoPago" del comercio, con `access_token` y
 *    `public_key` cargados a mano. Es lo UNICO con lo que `tienda-api` cobra hoy
 *    (`MercadoPagoController@preference`), asi que no se toca ni se vacia: mientras la tienda
 *    no este desplegada con el orden nuevo, es la credencial que de verdad esta cobrando.
 *
 * Un comercio que nunca conecto por OAuth sigue cobrando por (2) exactamente igual que antes.
 * Uno que conecta pasa a cobrar por (1) sin que nadie tenga que migrar nada a mano.
 *
 * `tienda-api` va a duplicar este mismo orden en su propio repo (la base es compartida, el
 * codigo no). Si el orden cambia acá, cambia allá.
 */
class MercadoPagoCredentialsHelper
{
    /**
     * Devuelve las credenciales vigentes de Mercado Pago del comercio.
     *
     * Siempre devuelve el array con las dos claves; vienen en null cuando el comercio no tiene
     * con qué cobrar por ningún lado. El llamador decide qué hacer con eso (no se lanza
     * excepción: no poder cobrar es un estado posible del sistema, no un error del programa).
     *
     * @param int $user_id Comercio (owner) del que se quieren las credenciales.
     * @return array{access_token: string|null, public_key: string|null, origen: string|null}
     */
    public static function credentials($user_id)
    {
        $user_id = (int) $user_id;

        $connector = PlatformConnector::find_for_user_and_slug($user_id, Platform::SLUG_MERCADO_PAGO);

        if ($connector && $connector->is_connected()) {
            // `is_connected()` mira el atributo crudo, sin desencriptar. El desencriptado real
            // pasa acá y puede fallar (APP_KEY rotada, o una fila escrita en plano antes de que
            // corriera la migración de cifrado). En ese caso no se propaga la excepción: se
            // avisa y se cae al fallback, que es lo que deja al comercio cobrando igual.
            try {
                $access_token = $connector->access_token;
            } catch (\Throwable $e) {
                Log::error(
                    "MercadoPagoCredentialsHelper: no se pudo leer el access_token del platform_connector ".
                    "{$connector->id}: " . $e->getMessage()
                );
                $access_token = null;
            }

            if (!empty($access_token)) {
                return [
                    'access_token' => $access_token,
                    // El conector puede no tener public_key si Mercado Pago no la devolvió en el
                    // canje: en ese caso se completa con la de `payment_methods`, que no es
                    // secreta y viaja al navegador igual.
                    'public_key'   => !empty($connector->public_key)
                        ? $connector->public_key
                        : self::public_key_de_payment_method($user_id),
                    'origen'       => 'platform_connector',
                ];
            }
        }

        $payment_method = self::payment_method_mercado_pago($user_id);

        if ($payment_method && !empty($payment_method->access_token)) {
            return [
                'access_token' => $payment_method->access_token,
                'public_key'   => $payment_method->public_key,
                'origen'       => 'payment_method',
            ];
        }

        return [
            'access_token' => null,
            'public_key'   => null,
            'origen'       => null,
        ];
    }

    /**
     * Solo el access_token, para los llamadores que no necesitan la public key.
     *
     * @param int $user_id Comercio (owner).
     * @return string|null
     */
    public static function access_token($user_id)
    {
        $credentials = self::credentials($user_id);

        return $credentials['access_token'];
    }

    /**
     * Fila de `payment_methods` del tipo "MercadoPago" del comercio, si tiene una.
     *
     * Devuelve null (no explota) si la tabla `payment_method_types` no tiene la fila
     * "MercadoPago": el `PaymentMethodTypeSeeder` la siembra, pero una base armada a mano o un
     * fixture parcial puede no tenerla.
     *
     * @param int $user_id Comercio (owner).
     * @return PaymentMethod|null
     */
    protected static function payment_method_mercado_pago($user_id)
    {
        $type = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$type) {
            return null;
        }

        return PaymentMethod::where('user_id', $user_id)
            ->where('payment_method_type_id', $type->id)
            ->first();
    }

    /**
     * Public key de `payment_methods`, usada solo para completar la del conector cuando falta.
     *
     * @param int $user_id Comercio (owner).
     * @return string|null
     */
    protected static function public_key_de_payment_method($user_id)
    {
        $payment_method = self::payment_method_mercado_pago($user_id);

        if (!$payment_method) {
            return null;
        }

        return $payment_method->public_key;
    }
}
