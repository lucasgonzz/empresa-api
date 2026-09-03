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
 * 1. La copia de credenciales COPIA y no mueve. Si vaciara el origen, todos los comercios
 *    dejarían de cobrar en la ventana entre el deploy de `empresa` y el de `tienda`, que lee
 *    `payment_methods.access_token`.
 * 2. La migración de cifrado es idempotente. Correrla dos veces sobre el mismo valor lo dejaría
 *    doblemente cifrado y el token, irrecuperable.
 *
 * @group integraciones
 */
class MigracionCredencialesMercadoPagoTest extends EmpresaTestCase
{
    /**
     * Instancia la migración que copia las credenciales a los conectores.
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
     * La copia desde `online_configuration` deja el conector cargado y el ORIGEN INTACTO.
     *
     * @return void
     */
    public function test_copia_desde_online_configuration_sin_vaciar_el_origen()
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

        $conector = $this->conector_mp();

        $this->assertNotNull($conector, 'La migración no creó el conector de Mercado Pago del comercio.');
        $this->assertSame('TOKEN-VIEJO-DE-ONLINE-CONFIG', $conector->access_token);
        $this->assertSame('REFRESH-VIEJO-DE-ONLINE-CONFIG', $conector->refresh_token);
        $this->assertSame('PUBLIC-KEY-VIEJA', $conector->public_key);
        $this->assertSame('55554444', $conector->platform_user_id);
        $this->assertSame(PlatformConnector::STATUS_CONECTADO, $conector->status);
        $this->assertTrue($conector->is_connected());

        $configuracion->refresh();

        $this->assertSame(
            'TOKEN-VIEJO-DE-ONLINE-CONFIG',
            $configuracion->mp_access_token,
            'La migración VACIÓ el origen. Tiene que copiar: mientras tienda-api no se despliegue, '.
            'borrar el origen deja comercios sin poder cobrar.'
        );
    }

    /**
     * Sin `online_configuration` cargado, la copia cae a `payment_methods` — que es lo único con
     * lo que `tienda-api` cobra hoy — y tampoco lo vacía.
     *
     * @return void
     */
    public function test_copia_desde_payment_methods_sin_vaciar_el_origen()
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

        $conector = $this->conector_mp();

        $this->assertNotNull($conector, 'La migración no cayó a payment_methods para el comercio sin OAuth.');
        $this->assertSame('TOKEN-CARGADO-A-MANO', $conector->access_token);
        $this->assertSame('PUBLIC-KEY-A-MANO', $conector->public_key);
        $this->assertNull(
            $conector->expires_at,
            'Una credencial cargada a mano no vence: si la migración le inventa un vencimiento, el '.
            'command de refresh la va a intentar renovar y la va a dejar desconectada.'
        );

        $payment_method->refresh();

        $this->assertSame(
            'TOKEN-CARGADO-A-MANO',
            $payment_method->access_token,
            'La migración VACIÓ payment_methods, que es con lo que la tienda cobra hoy.'
        );
    }

    /**
     * Correr la copia dos veces no pisa lo que el comercio ya tiene conectado.
     *
     * @return void
     */
    public function test_la_copia_es_idempotente_y_no_pisa_un_conector_ya_conectado()
    {
        $this->sin_conector_mp();

        $configuracion = OnlineConfiguration::where('user_id', $this->user_id())->firstOrFail();
        $configuracion->mp_access_token = 'TOKEN-VIEJO';
        $configuracion->save();

        $this->migracion_de_copia()->up();

        $conector = $this->conector_mp();
        $conector->access_token = 'TOKEN-NUEVO-POR-OAUTH';
        $conector->save();

        $this->migracion_de_copia()->up();

        $conector->refresh();

        $this->assertSame(
            'TOKEN-NUEVO-POR-OAUTH',
            $conector->access_token,
            'Una segunda corrida de la migración pisó el token que el comercio consiguió por OAuth '.
            'después del deploy.'
        );
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
