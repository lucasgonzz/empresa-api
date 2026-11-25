<?php

namespace Tests\Feature\Integraciones;

use App\Models\Platform;
use App\Models\PlatformConnector;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión envios-andreani, ronda 2 (hallazgo 1, espejo de tienda-api): la cotización de prueba de la tarjeta por
 * código postal tiene que mandarle a Andreani el CP numérico de 4 dígitos aunque el operador pegue un CPA
 * ("X5000ABC", como lo devuelve Google Places): Andreani responde 400 al CPA.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados.
 */
class IntegracionesAndreaniCpaTest extends EmpresaTestCase
{
    const BASE_URL = 'https://apis.andreani.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.andreani.base_url' => self::BASE_URL]);
    }

    /**
     * Conector de Andreani conectado, con contrato de domicilio y de sucursal.
     *
     * @return \App\Models\PlatformConnector
     */
    protected function conectar_andreani()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
        $plataforma = Platform::firstOrCreate(['slug' => 'andreani'], ['name' => 'Andreani', 'client_id' => null, 'client_secret' => null, 'extra_config' => null]);

        return PlatformConnector::create([
            'user_id'          => $user->id,
            'platform_id'      => $plataforma->id,
            'status'           => PlatformConnector::STATUS_CONECTADO,
            'access_token'     => json_encode(['usuario' => null, 'password' => null]),
            'refresh_token'    => null,
            'platform_user_id' => 'CL0003750',
            'expires_at'       => null,
            'extra_config'     => [
                'contrato_domicilio' => '400006709',
                'contrato_sucursal'  => 'SUC-TEST',
                'sucursal_origen'    => null,
                'bulto_default'      => ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10],
                'declarar_valor'     => true,
                'envio_gratis_desde' => null,
                'ofrecer_en_tienda'  => true,
                'conectado_en'       => '2026-11-25T15:00:00-03:00',
            ],
        ]);
    }

    /**
     * Andreani responde bien tarifa y sucursales, pero sólo si el CP es numérico (como la API real: 400 al CPA).
     *
     * @return void
     */
    protected function fakear_andreani_que_rechaza_el_cpa()
    {
        $tarifa = json_decode(file_get_contents(__DIR__ . '/fixtures/andreani_tarifa.json'), true);
        $sucursales = json_decode(file_get_contents(__DIR__ . '/fixtures/andreani_sucursales.json'), true);

        Http::fake(function ($request) use ($tarifa, $sucursales) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);

            if (strpos($url, self::BASE_URL . '/v1/tarifas') === 0) {
                if (!preg_match('/^\d{4}$/', (string) $consulta['cpDestino'])) {
                    return Http::response(['status' => 400], 400);
                }

                return Http::response($tarifa, 200);
            }

            if (strpos($url, self::BASE_URL . '/v2/sucursales') === 0) {
                if (!preg_match('/^\d{4}$/', (string) $consulta['codigoPostal'])) {
                    return Http::response(['detail' => 'El código postal es inexistente'], 400);
                }

                return Http::response($sucursales, 200);
            }

            return Http::response(['message' => 'ruta no fakeada en el test'], 599);
        });
    }

    public function test_cotizar_prueba_con_un_cpa_manda_a_andreani_el_cp_numerico()
    {
        $this->fakear_andreani_que_rechaza_el_cpa();
        $this->conectar_andreani();

        $respuesta = $this->postJson('/api/integraciones/andreani/cotizar-prueba', ['zipcode' => ' x5000-abc '])->assertStatus(200);

        $keys = [];
        foreach ($respuesta->json('cotizacion.opciones') as $opcion) {
            $keys[] = $opcion['key'];
        }
        $this->assertContains('andreani|domicilio', $keys, 'Con un CPA la opción de domicilio no puede desaparecer.');
        $this->assertContains('andreani|sucursal', $keys, 'Ni la de sucursal.');

        $cp_enviados = [];
        foreach (Http::recorded() as $par) {
            parse_str((string) parse_url($par[0]->url(), PHP_URL_QUERY), $consulta);
            $cp_enviados[] = isset($consulta['cpDestino']) ? $consulta['cpDestino'] : $consulta['codigoPostal'];
        }

        $this->assertNotEmpty($cp_enviados);
        $this->assertSame(['5000'], array_values(array_unique($cp_enviados)));
    }
}
