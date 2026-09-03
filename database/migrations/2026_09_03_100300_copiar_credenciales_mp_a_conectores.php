<?php

use App\Models\OnlineConfiguration;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * COPIA a `platform_connectors` las credenciales de Mercado Pago que cada comercio ya tiene.
 *
 * 🔴 COPIA, NO MUEVE, Y ESO NO ES UN DETALLE. `empresa` y `tienda` nunca llegan a produccion el
 * mismo dia, asi que durante toda la ventana entre un deploy y el otro va a haber empresa nueva
 * conviviendo con tienda vieja. La tienda vieja cobra leyendo `payment_methods.access_token`: si
 * esta migracion vaciara ese origen, todos los comercios dejarian de cobrar hasta que se
 * despliegue el otro repo. Por eso el origen queda intacto — ni `online_configurations.mp_*` ni
 * `payment_methods` se tocan. La limpieza es una mision posterior, con las dos puntas arriba.
 *
 * Orden de las fuentes (el mismo de `MercadoPagoCredentialsHelper`):
 * 1. `online_configurations.mp_*` — lo que dejo el OAuth del prompt 598.
 * 2. `payment_methods` del tipo "MercadoPago" — la carga a mano, que es lo unico con lo que la
 *    tienda cobra hoy.
 *
 * IDEMPOTENTE: si el comercio ya tiene un conector de Mercado Pago con access_token, no se
 * pisa. Correrla dos veces no cambia nada la segunda vez.
 */
class CopiarCredencialesMpAConectores extends Migration
{
    /**
     * Copia las credenciales de cada comercio a su conector de Mercado Pago.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('platform_connectors') || !Schema::hasTable('platforms')) {
            return;
        }

        $platform = $this->asegurar_plataforma_mercado_pago();

        // Comercios ya resueltos por la fuente 1, para no volver a mirarlos en la fuente 2.
        $ya_copiados = [];

        if (Schema::hasTable('online_configurations') && Schema::hasColumn('online_configurations', 'mp_access_token')) {
            $ya_copiados = $this->copiar_desde_online_configurations($platform);
        }

        if (Schema::hasTable('payment_methods') && Schema::hasTable('payment_method_types')) {
            $this->copiar_desde_payment_methods($platform, $ya_copiados);
        }
    }

    /**
     * No revierte nada a proposito.
     *
     * Esta migracion no movio ni borro nada: solo escribio copias en `platform_connectors`. El
     * origen sigue completo, asi que no hay dato que recuperar. Y borrar conectores en el
     * `down()` seria peor que no hacer nada: no hay forma confiable de distinguir el que creo
     * esta migracion del que el comercio conecto por OAuth despues, y borrar el segundo lo
     * dejaria sin cobrar.
     *
     * @return void
     */
    public function down()
    {
        //
    }

    /**
     * Se asegura de que exista la fila `mercado_pago` en `platforms` (es la que ancla el
     * `platform_id` de los conectores). Si ya existe con credenciales cargadas, NO las pisa con
     * los null de un `.env` sin configurar.
     *
     * @return Platform
     */
    protected function asegurar_plataforma_mercado_pago()
    {
        $platform = Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->first();

        if ($platform) {
            return $platform;
        }

        $mp_app_id     = env('MP_APP_ID');
        $mp_app_secret = env('MP_APP_SECRET');

        return Platform::create([
            'slug'          => Platform::SLUG_MERCADO_PAGO,
            'name'          => 'Mercado Pago',
            'client_id'     => empty($mp_app_id) ? null : $mp_app_id,
            'client_secret' => empty($mp_app_secret) ? null : $mp_app_secret,
            'extra_config'  => null,
        ]);
    }

    /**
     * Fuente 1: las columnas `mp_*` que dejo el OAuth del prompt 598.
     *
     * Se lee con el modelo (no con `DB::table`) a proposito: `mp_access_token` tiene cast
     * `encrypted`, asi que el modelo es el unico que devuelve el token en claro para poder
     * volver a guardarlo cifrado del otro lado.
     *
     * @param Platform $platform Fila `mercado_pago` de `platforms`.
     * @return array<int, bool> user_id => true de los comercios ya resueltos.
     */
    protected function copiar_desde_online_configurations(Platform $platform)
    {
        $ya_copiados = [];

        $configurations = OnlineConfiguration::whereNotNull('mp_access_token')
            ->where('mp_access_token', '!=', '')
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($configurations as $configuration) {
            $user_id = (int) $configuration->user_id;

            if (empty($user_id)) {
                continue;
            }

            try {
                // Este acceso es el que dispara el desencriptado del cast.
                $access_token = $configuration->mp_access_token;
                $refresh_token = $configuration->mp_refresh_token;
            } catch (\Throwable $e) {
                // Un token que no se puede desencriptar (APP_KEY rotada, valor cargado a mano en
                // plano) no se copia: se avisa y se sigue con el resto de los comercios.
                Log::warning(
                    'copiar_credenciales_mp_a_conectores: no se pudo leer mp_access_token de '.
                    "online_configuration {$configuration->id}: " . $e->getMessage()
                );
                continue;
            }

            if (empty($access_token)) {
                continue;
            }

            $copiado = $this->escribir_conector($platform, $user_id, [
                'access_token'     => $access_token,
                'refresh_token'    => $refresh_token,
                'public_key'       => $configuration->mp_public_key,
                'platform_user_id' => $configuration->mp_user_id,
                'expires_at'       => $configuration->mp_token_expires_at,
            ]);

            if ($copiado) {
                $ya_copiados[$user_id] = true;
            }
        }

        return $ya_copiados;
    }

    /**
     * Fuente 2: la fila de `payment_methods` del tipo "MercadoPago", cargada a mano. Es lo unico
     * con lo que `tienda-api` cobra hoy. Solo se mira para los comercios que la fuente 1 no
     * resolvio.
     *
     * No hay `expires_at`: una credencial cargada a mano no vence, y `is_connected()` trata el
     * null como vigente (mismo criterio que `getMpConnectedAttribute()`).
     *
     * @param Platform $platform Fila `mercado_pago` de `platforms`.
     * @param array<int, bool> $ya_copiados Comercios resueltos por la fuente 1.
     * @return void
     */
    protected function copiar_desde_payment_methods(Platform $platform, array $ya_copiados)
    {
        $type = PaymentMethodType::where('name', 'MercadoPago')->first();

        if (!$type) {
            return;
        }

        $payment_methods = PaymentMethod::where('payment_method_type_id', $type->id)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->whereNotNull('user_id')
            ->orderBy('id', 'ASC')
            ->get();

        foreach ($payment_methods as $payment_method) {
            $user_id = (int) $payment_method->user_id;

            if (empty($user_id) || isset($ya_copiados[$user_id])) {
                continue;
            }

            $this->escribir_conector($platform, $user_id, [
                'access_token'     => $payment_method->access_token,
                'refresh_token'    => null,
                'public_key'       => $payment_method->public_key,
                'platform_user_id' => null,
                'expires_at'       => null,
            ]);

            $ya_copiados[$user_id] = true;
        }
    }

    /**
     * Crea o completa el conector de Mercado Pago de un comercio.
     *
     * Se escribe con el modelo para que el cast `encrypted` cifre el token al guardar.
     *
     * @param Platform $platform Fila `mercado_pago` de `platforms`.
     * @param int $user_id Comercio (owner).
     * @param array<string, mixed> $datos Credenciales a copiar.
     * @return bool true si el conector quedo con credenciales (recien copiadas o ya las tenia).
     */
    protected function escribir_conector(Platform $platform, $user_id, array $datos)
    {
        $connector = PlatformConnector::where('user_id', $user_id)
            ->where('platform_id', $platform->id)
            ->orderBy('id', 'DESC')
            ->first();

        if ($connector && !empty($connector->getAttributes()['access_token'])) {
            // Ya tiene credencial: es una segunda corrida de esta migracion, o el comercio ya
            // conecto por OAuth despues del deploy. No se pisa.
            return true;
        }

        if (!$connector) {
            $connector = new PlatformConnector();
            $connector->user_id = $user_id;
            $connector->platform_id = $platform->id;
        }

        $connector->access_token     = $datos['access_token'];
        $connector->refresh_token    = $datos['refresh_token'];
        $connector->public_key       = $datos['public_key'];
        $connector->platform_user_id = $datos['platform_user_id'];
        $connector->expires_at       = $datos['expires_at'];
        $connector->status           = PlatformConnector::STATUS_CONECTADO;
        $connector->error_message    = null;
        $connector->save();

        return true;
    }
}
