<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\MercadoPagoQrCaja;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Ubicaciones_qr_Test extends MercadoPagoQrTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('mp_qr_ubicaciones_ar_provincias_v1');
        Cache::forget('mp_qr_ubicaciones_ar_AR-B_v1');
        Cache::forget('mp_qr_ubicaciones_ar_AR-S_v1');
    }

    protected function catalogo()
    {
        return [
            'https://api.mercadolibre.com/countries/AR' => Http::response(['id' => 'AR', 'states' => [
                ['id' => 'AR-S', 'name' => 'Santa Fe'], ['id' => 'AR-B', 'name' => 'Buenos Aires'],
            ]]),
            'https://api.mercadolibre.com/states/AR-B' => Http::response(['id' => 'AR-B', 'country' => ['id' => 'AR'], 'cities' => [
                ['id' => 'ESCOBAR', 'name' => 'Escobar'],
            ]]),
            'https://api.mercadolibre.com/states/AR-S' => Http::response(['id' => 'AR-S', 'country' => ['id' => 'AR'], 'cities' => [
                ['id' => 'ROSARIO', 'name' => 'Rosario'],
            ]]),
        ];
    }

    /** @test */
    public function provincias_y_localidades_salen_del_catalogo_y_se_cachean_sin_token_del_comercio()
    {
        Http::fake($this->catalogo());
        $this->getJson('/api/mercado-pago/qr/provincias')->assertOk()->assertJsonPath('provincias.0.id', 'AR-B');
        $this->getJson('/api/mercado-pago/qr/provincias/AR-B/localidades')->assertOk()
            ->assertJsonPath('provincia.name', 'Buenos Aires')->assertJsonPath('localidades.0.name', 'Escobar');
        $this->getJson('/api/mercado-pago/qr/provincias/AR-B/localidades')->assertOk();
        Http::assertSentCount(2);
        Http::assertNotSent(function ($request) { return $request->hasHeader('Authorization'); });
    }

    /** @test */
    public function una_provincia_ajena_al_catalogo_no_dispara_una_url_arbitraria()
    {
        Http::fake($this->catalogo());
        $this->getJson('/api/mercado-pago/qr/provincias/BR-SP/localidades')->assertStatus(422);
        Http::assertSentCount(1);
    }

    /** @test */
    public function catalogo_caido_o_incompleto_no_se_cachea_como_lista_vacia()
    {
        Http::fake(['https://api.mercadolibre.com/countries/AR' => Http::sequence()
            ->push(['message' => 'caído'], 500)
            ->push(['id' => 'AR', 'states' => []], 200)
            ->push(['id' => 'AR', 'states' => [['id' => 'AR-B', 'name' => 'Buenos Aires']]], 200)]);
        $this->getJson('/api/mercado-pago/qr/provincias')->assertStatus(503);
        $this->getJson('/api/mercado-pago/qr/provincias')->assertStatus(503);
        $this->getJson('/api/mercado-pago/qr/provincias')->assertOk()->assertJsonPath('provincias.0.id', 'AR-B');
        Http::assertSentCount(3);
    }

    /** @test */
    public function localidad_de_otra_provincia_o_ids_incompletos_no_crean_sucursal_ni_qr()
    {
        $this->conectar_mp();
        Http::fake($this->catalogo());
        foreach ([['state_id' => 'AR-B', 'city_id' => 'ROSARIO'], ['state_id' => 'AR-B'], ['state_id' => ['AR-B'], 'city_id' => 'ESCOBAR']] as $ids) {
            $this->postJson('/api/mercado-pago/qr/cajas', $ids)->assertStatus(422);
        }
        $this->assertSame(0, MercadoPagoQrCaja::where('user_id', self::USER_ID)->count());
        Http::assertNotSent(function ($request) { return $request->method() === 'POST'; });
    }

    /** @test */
    public function crear_qr_usa_nombres_canonicos_y_conserva_direccion_y_coordenadas()
    {
        $this->conectar_mp();
        Http::fake(array_merge($this->catalogo(), [
            'https://api.mercadopago.com/users/' . self::MP_USER_ID . '/stores' => Http::response(['id' => 555], 201),
            'https://api.mercadopago.com/v2/pos' => Http::response(['id' => 777, 'qr_response' => [
                'image' => 'https://mp.test/qr.png', 'template_document' => 'https://mp.test/qr.pdf', 'qr_code' => 'codigo',
            ]], 201),
        ]));
        $this->postJson('/api/mercado-pago/qr/cajas', [
            'name' => 'Mostrador', 'state_id' => 'AR-B', 'city_id' => 'ESCOBAR',
            'state_name' => 'texto manipulado', 'city_name' => 'Matheu',
            'street_name' => 'Nazarre', 'street_number' => '198', 'latitude' => -34.38326, 'longitude' => -58.82916,
        ])->assertStatus(201);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST' && strpos($request->url(), '/stores') !== false
                && $request['location']['city_name'] === 'Escobar'
                && $request['location']['state_name'] === 'Buenos Aires'
                && $request['location']['street_name'] === 'Nazarre'
                && $request['location']['street_number'] === '198'
                && $request['location']['latitude'] === -34.38326
                && $request['location']['longitude'] === -58.82916;
        });
    }

    /** @test */
    public function las_localidades_deben_corresponder_a_la_provincia_pedida()
    {
        Http::fake(array_merge($this->catalogo(), [
            'https://api.mercadolibre.com/states/AR-B' => Http::response(['id' => 'AR-S', 'country' => ['id' => 'AR'], 'cities' => [['id' => 'ROSARIO', 'name' => 'Rosario']]]),
        ]));
        $this->getJson('/api/mercado-pago/qr/provincias/AR-B/localidades')->assertStatus(503);
    }

    /** @test */
    public function el_catalogo_exige_autenticacion()
    {
        auth('web')->logout();
        $this->getJson('/api/mercado-pago/qr/provincias')->assertStatus(401);
        $this->getJson('/api/mercado-pago/qr/provincias/AR-B/localidades')->assertStatus(401);
        Http::assertNothingSent();
    }
}
