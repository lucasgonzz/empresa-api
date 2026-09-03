<?php

namespace Tests\Feature\Integraciones;

use App\Models\OnlineConfiguration;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión "ABM -> Integraciones" (3/9/2026): las dos migraciones que tocan credenciales.
 *
 * Se corren las clases de migración a mano, dentro de la transacción del test, en vez de
 * afirmar sobre el estado que dejó `artisan migrate` en la base del slot: así el test dice algo
 * sobre lo que la migración HACE, no sobre lo que quedó en una base concreta, y sirve igual en
 * un slot recién armado.
 *
 * Lo que se fija:
 *
 * 1. 🔴 LA MIGRACIÓN DE CREDENCIALES NO MIGRA A NADIE. Es el guardián de la decisión de Lucas
 *    del 3/9/2026 ("que no migre a nadie"): un comercio con credenciales cargadas —en
 *    `online_configurations.mp_*`, en `payment_methods`, o en las dos— tiene que quedar SIN
 *    conector después de correrla, y con las dos fuentes intactas.
 *
 *    El motivo, que es lo que estos tests protegen: el OAuth del 21/7/2026 nunca cobró nada, así
 *    que un comercio pudo apretar "Conectar" y olvidarse. Tomar ese click como declaración de
 *    "esta es mi cuenta de cobro", retroactivamente y en silencio, es cambiarle a alguien la
 *    cuenta donde le entra la plata. Cada comercio sigue cobrando por donde cobraba hasta que
 *    conecte a mano.
 *
 *    Estos tests están escritos para fallar si alguien "completa" la migración vacía.
 *
 * 2. La migración de cifrado es idempotente. Correrla dos veces sobre el mismo valor lo dejaría
 *    doblemente cifrado y el token, irrecuperable.
 *
 * @group integraciones
 */
class MigracionCredencialesMercadoPagoTest extends EmpresaTestCase
{
    /**
     * Instancia la migración que NO copia las credenciales a los conectores (ver el docblock de
     * la clase: es un no-op deliberado, no un olvido).
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function migracion_de_copia()
    {
        require_once database_path('migrations/2026_09_03_100300_copiar_credenciales_mp_a_conectores.php');

        return new \CopiarCredencialesMpAConectores();
    }

    /**
     * Instancia la migración que cifra los tokens de los conectores.
     *
     * @return \Illuminate\Database\Migrations\Migration
     */
    protected function migracion_de_cifrado()
    {
        require_once database_path('migrations/2026_09_03_100000_encrypt_platform_connector_tokens.php');

        return new \EncryptPlatformConnectorTokens();
    }

    /**
     * Id del comercio del fixture.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Fila `mercado_pago` de `platforms`, creándola si no está.
     *
     * @return Platform
     */
    protected function plataforma_mp()
    {
        $platform = Platform::where('slug', Platform::SLUG_MERCADO_PAGO)->first();

        if ($platform) {
            return $platform;
        }

        return Platform::create([
            'slug'          => Platform::SLUG_MERCADO_PAGO,
            'name'          => 'Mercado Pago',
            'client_id'     => null,
            'client_secret' => null,
            'extra_config'  => null,
        ]);
    }

    /**
     * Deja el comercio del fixture sin conector de Mercado Pago, para que cada test parta del
     * mismo lugar sin importar qué haya dejado la corrida de `artisan migrate` del slot.
     *
     * @return void
     */
    protected function sin_conector_mp()
    {
        PlatformConnector::where('user_id', $this->user_id())
            ->where('platform_id', $this->plataforma_mp()->id)
            ->delete();
    }

    /**
     * Conector de Mercado Pago del comercio del fixture, si tiene.
     *
     * @return PlatformConnector|null
     */
    protected function conector_mp()
    {
        return PlatformConnector::where('user_id', $this->user_id())
            ->where('platform_id', $this->plataforma_mp()->id)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * 🔴 Un comercio con credenciales en `online_configuration` NO recibe conector.
     *
     * Es el caso del que apretó "Conectar" en algún momento desde el 21/7/2026. Ese OAuth nunca
     * cobró nada, así que el click no significó nada: no se puede tomar retroactivamente como su
     * declaración de cuenta de cobro.
     *
     * @return void
     */
    public function test_no_crea_conector_para_un_comercio_con_credenciales_en_online_configuration()
    {
        $this->sin_conector_mp();

        $vence = Carbon::now()->addDays(120);

        $configuracion = OnlineConfiguration::where('user_id', $this->user_id())->firstOrFail();
        $configuracion->mp_access_token = 'TOKEN-VIEJO-DE-ONLINE-CONFIG';
        $configuracion->mp_refresh_token = 'REFRESH-VIEJO-DE-ONLINE-CONFIG';
        $configuracion->mp_public_key = 'PUBLIC-KEY-VIEJA';
        $configuracion->mp_user_id = '55554444';
        $configuracion->mp_token_expires_at = $vence;
        $configuracion->mp_enabled = true;
        $configuracion->save();

        $this->migracion_de_copia()->up();

        $this->assertNull(
            $this->conector_mp(),
            'La migración creó un conector de Mercado Pago. La decisión del 3/9/2026 es que NO MIGRE '.
            'A NADIE: el OAuth del 21/7 nunca cobró, así que ese click no puede decidir '.
            'retroactivamente en qué cuenta le entra la plata al comercio.'
        );

        $configuracion->refresh();

        $this->assertSame(
            'TOKEN-VIEJO-DE-ONLINE-CONFIG',
            $configuracion->mp_access_token,
            'La migración tocó online_configuration. No tiene que leer ni escribir nada.'
        );
    }

    /**
     * 🔴 Un comercio con la credencial cargada A MANO en `payment_methods` tampoco recibe
     * conector, y su credencial queda intacta: sigue cobrando exactamente por donde cobraba.
     *
     * @return void
     */
    public function test_no_crea_conector_para_un_comercio_con_credenciales_en_payment_methods()
    {
        $this->sin_conector_mp();

        $configuracion = OnlineConfiguration::where('user_id', $this->user_id())->firstOrFail();
        $configuracion->mp_access_token = null;
        $configuracion->mp_refresh_token = null;
        $configuracion->save();

        $type = PaymentMethodType::where('name', 'MercadoPago')->first();
        if (!$type) {
            $type = PaymentMethodType::create(['name' => 'MercadoPago']);
        }

        $payment_method = PaymentMethod::create([
            'name'                   => 'MercadoPago',
            'payment_method_type_id' => $type->id,
            'public_key'             => 'PUBLIC-KEY-A-MANO',
            'access_token'           => 'TOKEN-CARGADO-A-MANO',
            'user_id'                => $this->user_id(),
        ]);

        $this->migracion_de_copia()->up();

        $this->assertNull(
            $this->conector_mp(),
            'La migración creó un conector desde payment_methods. No migra a nadie.'
        );

        $payment_method->refresh();

        $this->assertSame(
            'TOKEN-CARGADO-A-MANO',
            $payment_method->access_token,
            'La migración tocó payment_methods, que es con lo que la tienda cobra hoy.'
        );
        $this->assertSame('PUBLIC-KEY-A-MANO', $payment_method->public_key);
    }

    /**
     * 🔴 Con las DOS fuentes cargadas tampoco pasa nada: ninguna precedencia, ningún conector.
     *
     * Este es el test que fallaría primero si alguien "completa" la migración vacía eligiendo un
     * orden entre las dos fuentes, que es exactamente la decisión que Lucas descartó.
     *
     * @return void
     */
    public function test_con_las_dos_fuentes_cargadas_sigue_sin_crear_nada()
    {
        $this->sin_conector_mp();

        $configuracion = OnlineConfiguration::where('user_id', $this->user_id())->firstOrFail();
        $configuracion->mp_access_token = 'TOKEN-DE-ONLINE-CONFIG';
        $configuracion->save();

        $type = PaymentMethodType::where('name', 'MercadoPago')->first();
        if (!$type) {
            $type = PaymentMethodType::create(['name' => 'MercadoPago']);
        }

        $payment_method = PaymentMethod::create([
            'name'                   => 'MercadoPago',
            'payment_method_type_id' => $type->id,
            'public_key'             => 'PUBLIC-KEY-A-MANO',
            'access_token'           => 'TOKEN-CARGADO-A-MANO',
            'user_id'                => $this->user_id(),
        ]);

        $this->migracion_de_copia()->up();

        $this->assertNull($this->conector_mp(), 'La migración eligió una precedencia entre las dos fuentes y creó un conector.');

        $configuracion->refresh();
        $payment_method->refresh();

        $this->assertSame('TOKEN-DE-ONLINE-CONFIG', $configuracion->mp_access_token);
        $this->assertSame('TOKEN-CARGADO-A-MANO', $payment_method->access_token);
    }

    /**
     * Y no toca el conector del comercio que SÍ conectó a mano después del despliegue: ni se lo
     * borra, ni se lo pisa.
     *
     * @return void
     */
    public function test_no_toca_el_conector_de_un_comercio_que_conecto_a_mano()
    {
        $this->sin_conector_mp();

        $conector = PlatformConnector::create([
            'user_id'      => $this->user_id(),
            'platform_id'  => $this->plataforma_mp()->id,
            'status'       => PlatformConnector::STATUS_CONECTADO,
            'access_token' => 'TOKEN-CONECTADO-A-MANO',
        ]);

        $this->migracion_de_copia()->up();

        $conector->refresh();

        $this->assertSame(
            'TOKEN-CONECTADO-A-MANO',
            $conector->access_token,
            'La migración pisó el token de un comercio que conectó por OAuth de verdad.'
        );
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
    }

    /**
     * La migración de cifrado cifra lo que está en plano y NO vuelve a cifrar lo que ya está
     * cifrado. Doble cifrado = token irrecuperable.
     *
     * @return void
     */
    public function test_el_cifrado_es_idempotente()
    {
        $this->sin_conector_mp();

        // Se escribe con DB::table a propósito: por el modelo, el cast `encrypted` ya lo
        // cifraría, y lo que hay que reproducir acá es la fila vieja en TEXTO PLANO.
        $id = DB::table('platform_connectors')->insertGetId([
            'user_id'      => $this->user_id(),
            'platform_id'  => $this->plataforma_mp()->id,
            'status'       => PlatformConnector::STATUS_CONECTADO,
            'access_token' => 'TOKEN-EN-PLANO',
            'refresh_token' => 'REFRESH-EN-PLANO',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $migracion = $this->migracion_de_cifrado();

        $migracion->up();

        $crudo_primera = DB::table('platform_connectors')->where('id', $id)->value('access_token');

        $this->assertNotSame('TOKEN-EN-PLANO', $crudo_primera, 'La migración no cifró el token en plano.');
        $this->assertSame('TOKEN-EN-PLANO', Crypt::decryptString($crudo_primera));

        // Segunda corrida: no tiene que tocar nada.
        $migracion->up();

        $crudo_segunda = DB::table('platform_connectors')->where('id', $id)->value('access_token');

        $this->assertSame(
            'TOKEN-EN-PLANO',
            Crypt::decryptString($crudo_segunda),
            'La segunda corrida volvió a cifrar un valor ya cifrado: el token quedó irrecuperable.'
        );

        $conector = PlatformConnector::find($id);

        $this->assertSame('TOKEN-EN-PLANO', $conector->access_token);
        $this->assertSame('REFRESH-EN-PLANO', $conector->refresh_token);
    }
}
