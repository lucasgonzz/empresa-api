<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\PlatformConnector;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\MercadoLibreLimiteException;
use App\Services\MercadoLibre\MercadoLibreService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;

/**
 * Tokens y llamadas de `MercadoLibreService` (misión mercado-libre-integracion, 27/10/2026):
 * refresh antes de vencer, el candado (si otro proceso ya refrescó no se vuelve a refrescar: el
 * refresh_token de ML es de un solo uso), `invalid_grant` → conector en error con aviso una sola
 * vez, y los reintentos ante 429.
 *
 * @group mercado-libre
 */
class TokensTest extends MercadoLibreTestCase
{
    public function test_si_vence_en_menos_de_5_minutos_se_refresca_y_se_guarda_el_token_nuevo()
    {
        $connector = $this->conector_ml(['expires_at' => Carbon::now()->addMinutes(2)]);

        $this->fake_ml([
            'POST oauth/token' => [200, ['access_token' => 'APP_USR-REFRESCADO', 'refresh_token' => 'TG-ROTADO', 'expires_in' => 21600]],
        ]);

        new MercadoLibreService($this->owner->id);

        $refreshes = $this->pedidos_ml('POST', 'oauth/token');
        $this->assertCount(1, $refreshes);
        $this->assertSame('refresh_token', $refreshes[0]['grant_type']);
        $this->assertSame(self::REFRESH, $refreshes[0]['refresh_token']);

        $connector = $connector->fresh();
        $this->assertSame('APP_USR-REFRESCADO', $connector->access_token);
        $this->assertSame('TG-ROTADO', $connector->refresh_token);
        $this->assertTrue($connector->expires_at->gt(Carbon::now()->addHours(5)));
    }

    public function test_con_el_token_vigente_no_se_refresca()
    {
        $this->conector_ml(['expires_at' => Carbon::now()->addHours(3)]);
        $this->fake_ml([]);

        new MercadoLibreService($this->owner->id);

        $this->assertCount(0, $this->pedidos_ml('POST', 'oauth/token'));
    }

    /**
     * Otro proceso refrescó mientras éste esperaba el candado: adentro se relee la fila, se ve el
     * token nuevo y NO se pide otro refresh (usar el refresh_token viejo daría invalid_grant).
     */
    public function test_si_otro_proceso_ya_refresco_adentro_del_candado_no_se_vuelve_a_refrescar()
    {
        $connector = $this->conector_ml(['expires_at' => Carbon::now()->addHours(3)]);
        $this->fake_ml([
            'POST oauth/token' => [200, ['access_token' => 'NO-DEBERIA', 'refresh_token' => 'NO-DEBERIA', 'expires_in' => 21600]],
        ]);

        $service = new MercadoLibreService($this->owner->id);

        // "Otro proceso" refresca en la base.
        $connector->access_token = 'APP_USR-DEL-OTRO-PROCESO';
        $connector->refresh_token = 'TG-DEL-OTRO-PROCESO';
        $connector->expires_at = Carbon::now()->addHours(6);
        $connector->save();

        // Este servicio todavía cree que su token venció.
        $service->conector()->expires_at = Carbon::now()->subMinute();

        $metodo = new ReflectionMethod(MercadoLibreService::class, 'refresh_token');
        $metodo->setAccessible(true);
        $metodo->invoke($service);

        $this->assertCount(0, $this->pedidos_ml('POST', 'oauth/token'), 'Refrescó de nuevo con un refresh_token que otro proceso ya había usado.');
        $this->assertSame('APP_USR-DEL-OTRO-PROCESO', $service->conector()->access_token);
    }

    public function test_el_refresh_toma_el_candado_del_conector_y_lo_suelta()
    {
        $connector = $this->conector_ml(['expires_at' => Carbon::now()->subMinute()]);
        $this->fake_ml([
            'POST oauth/token' => [200, ['access_token' => 'APP_USR-OK', 'refresh_token' => 'TG-OK', 'expires_in' => 21600]],
        ]);

        new MercadoLibreService($this->owner->id);

        // Si el candado hubiera quedado tomado, este get() devolvería false.
        $lock = Cache::lock('meli_refresh_'.$connector->id, 5);
        $this->assertTrue((bool) $lock->get(), 'El candado del refresh quedó tomado.');
        $lock->release();
    }

    public function test_invalid_grant_deja_el_conector_en_error_y_avisa_una_sola_vez()
    {
        Notification::fake();

        $connector = $this->conector_ml(['expires_at' => Carbon::now()->subMinute()]);
        $this->fake_ml([
            'POST oauth/token' => [400, ['error' => 'invalid_grant', 'message' => 'Error validating grant']],
        ]);

        try {
            new MercadoLibreService($this->owner->id);
            $this->fail('Con invalid_grant el servicio tenía que tirar.');
        } catch (\RuntimeException $e) {
            $this->assertSame(MercadoLibreService::MENSAJE_CONEXION_CORTADA, $e->getMessage());
        }

        $connector = $connector->fresh();
        $this->assertSame(PlatformConnector::STATUS_ERROR, $connector->status);
        $this->assertSame(MercadoLibreService::MENSAJE_CONEXION_CORTADA, $connector->error_message);

        Notification::assertSentTo($this->owner, GlobalNotification::class, 1);

        // Un segundo intento (el conector ya no figura conectado) no vuelve a avisar lo mismo.
        $this->assertNull(PlatformConnector::find_connected_mercado_libre_for_user($this->owner->id));
    }

    /**
     * Dos procesos con el mismo conector: el primero ya lo marcó en error y avisó; el segundo, al
     * recibir también invalid_grant, no vuelve a notificar.
     */
    public function test_un_segundo_invalid_grant_sobre_un_conector_ya_cortado_no_vuelve_a_avisar()
    {
        $connector = $this->conector_ml();
        $this->fake_ml([
            'POST oauth/token' => [400, ['error' => 'invalid_grant']],
        ]);

        $service = new MercadoLibreService($this->owner->id);

        // El "otro proceso" ya cortó la conexión y avisó.
        $connector->status = PlatformConnector::STATUS_ERROR;
        $connector->error_message = MercadoLibreService::MENSAJE_CONEXION_CORTADA;
        $connector->save();

        Notification::fake();

        $metodo = new ReflectionMethod(MercadoLibreService::class, 'refresh_token');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($service, self::TOKEN);
            $this->fail('Con invalid_grant tenía que tirar.');
        } catch (\RuntimeException $e) {
            $this->assertSame(MercadoLibreService::MENSAJE_CONEXION_CORTADA, $e->getMessage());
        }

        Notification::assertNothingSent();
    }

    /**
     * Arreglo 11 (revisión, 27/10/2026): el token de APP (client_credentials, de la cuenta de
     * ComercioCity) se guarda cifrado en el cache, nunca en texto plano.
     */
    public function test_el_token_de_app_se_guarda_cifrado_en_el_cache()
    {
        $this->fake_ml([
            'POST oauth/token' => [200, ['access_token' => 'APP-TOKEN-SECRETO', 'expires_in' => 21600]],
        ]);

        $this->assertSame('APP-TOKEN-SECRETO', \App\Services\MercadoLibre\MercadoLibreAppCredentials::token_de_app());

        $guardado = Cache::get(\App\Services\MercadoLibre\MercadoLibreAppCredentials::CACHE_TOKEN_DE_APP);
        $this->assertNotEmpty($guardado);
        $this->assertStringNotContainsString('APP-TOKEN-SECRETO', (string) $guardado, 'El token de app quedó en texto plano en el cache.');
        $this->assertSame('APP-TOKEN-SECRETO', \Illuminate\Support\Facades\Crypt::decryptString($guardado));

        // La segunda vez sale del cache (descifrado) sin pedir otro.
        $this->assertSame('APP-TOKEN-SECRETO', \App\Services\MercadoLibre\MercadoLibreAppCredentials::token_de_app());
        $this->assertCount(1, $this->pedidos_ml('POST', 'oauth/token'));
    }

    public function test_429_reintenta_tres_veces_y_despues_tira_la_excepcion_de_limite()
    {
        $this->conector_ml();
        $this->fake_ml([
            'GET users/me' => [429, ['message' => 'too many requests']],
        ]);

        $service = new MercadoLibreService($this->owner->id);

        try {
            $service->datos_de_la_cuenta(true);
            $this->fail('Con 429 seguidos tenía que tirar MercadoLibreLimiteException.');
        } catch (MercadoLibreLimiteException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }

        $this->assertCount(4, $this->pedidos_ml('GET', 'users/me'), 'Tienen que ser 1 intento + 3 reintentos.');
    }

    public function test_un_429_seguido_de_200_se_recupera_solo()
    {
        $this->conector_ml();
        $this->fake_ml([
            'GET users/me' => ['secuencia' => [
                [429, ['message' => 'too many requests'], ['Retry-After' => '0']],
                [200, $this->usuario_ml()],
            ]],
        ]);

        $service = new MercadoLibreService($this->owner->id);
        $usuario = $service->datos_de_la_cuenta(true);

        $this->assertSame('VENDEDOR_PRUEBA', $usuario['nickname']);
        $this->assertCount(2, $this->pedidos_ml('GET', 'users/me'));
    }

    public function test_409_reintenta_una_sola_vez()
    {
        $this->conector_ml();
        $this->fake_ml([
            'GET users/me' => [409, ['message' => 'conflict']],
        ]);

        $service = new MercadoLibreService($this->owner->id);

        try {
            $service->datos_de_la_cuenta(true);
            $this->fail('Con 409 dos veces tenía que tirar MercadoLibreLimiteException.');
        } catch (MercadoLibreLimiteException $e) {
            $this->assertStringContainsString('409', $e->getMessage());
        }

        $this->assertCount(2, $this->pedidos_ml('GET', 'users/me'));
    }

    public function test_un_401_refresca_y_reintenta_con_el_token_nuevo()
    {
        $connector = $this->conector_ml();
        $this->fake_ml([
            'GET users/me' => function ($request) {
                $auth = $request->header('Authorization');
                $auth = is_array($auth) ? $auth[0] : $auth;

                return $auth === 'Bearer APP_USR-DESPUES-DEL-401'
                    ? \Illuminate\Support\Facades\Http::response($this->usuario_ml(), 200)
                    : \Illuminate\Support\Facades\Http::response(['message' => 'invalid_token'], 401);
            },
            'POST oauth/token' => [200, ['access_token' => 'APP_USR-DESPUES-DEL-401', 'refresh_token' => 'TG-NUEVO', 'expires_in' => 21600]],
        ]);

        $service = new MercadoLibreService($this->owner->id);
        $usuario = $service->datos_de_la_cuenta(true);

        $this->assertSame('VENDEDOR_PRUEBA', $usuario['nickname']);
        $this->assertCount(1, $this->pedidos_ml('POST', 'oauth/token'));
        $this->assertSame('APP_USR-DESPUES-DEL-401', $connector->fresh()->access_token);
    }
}
