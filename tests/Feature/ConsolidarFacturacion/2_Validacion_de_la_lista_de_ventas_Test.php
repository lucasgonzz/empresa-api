<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Archivo 2 — LA VALIDACION DE LA LISTA DE VENTAS A CONSOLIDAR
 * (mision cc-reactivar-validar-consolidacion, 23/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL HUECO QUE ESTE ARCHIVO CIERRA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `ConsolidarFacturacionHelper::validar()` estuvo comentada en `consolidar()` desde el commit
 *  original (27/4/2026). Sin ella, `POST api/sales/consolidar-facturacion` no rechazaba:
 *    - una venta que ya esta dentro de otra consolidacion,
 *    - una venta con factura autorizada (CAE),
 *    - una venta de otro cliente o de otro comercio,
 *    - una venta inexistente (se consolidaban las que si estaban y se ignoraba el resto).
 *  Un doble envio o un POST armado a mano consolidaba dos veces la misma venta y emitia dos
 *  facturas. La unica barrera era la SPA, y el menu "Seleccion" de Ventas tenia las suyas
 *  comentadas.
 *
 *  Los rechazos se prueban por el ENDPOINT real (la validacion corre antes de cualquier llamada a
 *  ARCA, asi que un rechazo no emite nada). Los casos que SI consolidan se prueban llamando al
 *  helper con `emitir_afip = false`: el endpoint siempre emite y emitir pegaria contra ARCA.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group consolidar_facturacion
 */
class Validacion_de_la_lista_de_ventas_Test extends EmpresaTestCase
{
    /**
     * Test 1 — Una venta que ya esta consolidada se rechaza, y no se crea ninguna contenedora nueva.
     *
     * @test
     */
    public function una_venta_ya_consolidada_se_rechaza()
    {
        $contenedora = $this->contenedora();
        $ya_consolidada = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);
        $libre = $this->venta();

        $contenedoras_antes = Sale::where('is_consolidacion_facturacion', 1)->count();

        $respuesta = $this->consolidar_por_endpoint([$libre->id, $ya_consolidada->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('ya fue incluida en la consolidación', $respuesta->json('message'));
        $this->assertTextoContiene((string) $contenedora->id, $respuesta->json('message'));

        $this->assertEquals($contenedoras_antes, Sale::where('is_consolidacion_facturacion', 1)->count(), 'No se crea ninguna contenedora.');
        $this->assertNull(Sale::find($libre->id)->consolidacion_facturacion_id, 'La venta libre de la lista tampoco queda marcada.');
        $this->assertEquals($contenedora->id, Sale::find($ya_consolidada->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 2 — Una venta con factura autorizada (CAE) se rechaza.
     *
     * @test
     */
    public function una_venta_con_cae_se_rechaza()
    {
        $facturada = $this->venta();
        AfipTicket::create(['sale_id' => $facturada->id, 'cae' => '71234567890123', 'cbte_numero' => 12, 'resultado' => 'A']);

        $respuesta = $this->consolidar_por_endpoint([$facturada->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('ya tiene un comprobante AFIP autorizado', $respuesta->json('message'));
        $this->assertNull(Sale::find($facturada->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 3 — Una venta de otro cliente se rechaza.
     *
     * @test
     */
    public function una_venta_de_otro_cliente_se_rechaza()
    {
        $otro_cliente = Client::where('id', '!=', $this->cliente()->id)->where('user_id', $this->comercio()->id)->first();
        $this->assertNotNull($otro_cliente, 'El fixture tiene mas de un cliente.');

        $de_otro = $this->venta(['client_id' => $otro_cliente->id]);
        $del_cliente = $this->venta();

        $respuesta = $this->consolidar_por_endpoint([$del_cliente->id, $de_otro->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('no corresponde al cliente indicado', $respuesta->json('message'));
        $this->assertNull(Sale::find($del_cliente->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 4 — Una venta de otro comercio se rechaza (nunca se consolida lo ajeno).
     *
     * @test
     */
    public function una_venta_de_otro_comercio_se_rechaza()
    {
        $otro_comercio = User::create([
            'name'     => 'Otro comercio consolidar',
            'email'    => 'consolidar-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajena = $this->venta(['user_id' => $otro_comercio->id]);

        $respuesta = $this->consolidar_por_endpoint([$ajena->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('no pertenece al usuario actual', $respuesta->json('message'));
        $this->assertNull(Sale::find($ajena->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 5 — Un id que no existe (o una venta borrada) rechaza TODA la lista: antes se
     * consolidaban las que si estaban y se ignoraba la otra, y la factura salia incompleta.
     *
     * @test
     */
    public function un_id_inexistente_o_borrado_rechaza_toda_la_lista()
    {
        $existente = $this->venta();
        $borrada = $this->venta();
        $borrada->delete();

        $respuesta = $this->consolidar_por_endpoint([$existente->id, $borrada->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('no existen', $respuesta->json('message'));
        $this->assertNull(Sale::find($existente->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 6 — Una contenedora no se puede volver a consolidar, y una lista vacia se rechaza.
     *
     * @test
     */
    public function una_contenedora_o_una_lista_vacia_se_rechazan()
    {
        $contenedora = $this->contenedora();

        $this->consolidar_por_endpoint([$contenedora->id])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => true]);

        $respuesta = $this->consolidar_por_endpoint([]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('al menos una venta', $respuesta->json('message'));
    }

    /**
     * Test 7 — Lo que Ventas hace todos los dias sigue andando: ventas terminadas y sin factura del
     * mismo cliente se consolidan, y quedan marcadas con su contenedora. Una venta con un
     * comprobante SIN CAE (un intento rechazado por ARCA) tambien se puede consolidar.
     *
     * @test
     */
    public function las_ventas_normales_de_ventas_se_siguen_consolidando()
    {
        $limpia = $this->venta();
        $con_intento_sin_cae = $this->venta();
        AfipTicket::create(['sale_id' => $con_intento_sin_cae->id, 'cae' => null, 'resultado' => 'R']);

        $consolidada = $this->consolidar_sin_emitir([$limpia->id, $con_intento_sin_cae->id]);

        $this->assertEquals(1, (int) $consolidada->is_consolidacion_facturacion);
        $this->assertEquals($consolidada->id, Sale::find($limpia->id)->consolidacion_facturacion_id);
        $this->assertEquals($consolidada->id, Sale::find($con_intento_sin_cae->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 8 — La misma venta elegida dos veces no es una venta inexistente: se consolida una vez.
     *
     * @test
     */
    public function la_misma_venta_repetida_en_la_lista_se_consolida_una_vez()
    {
        $venta = $this->venta();

        $consolidada = $this->consolidar_sin_emitir([$venta->id, $venta->id]);

        $this->assertEquals($consolidada->id, Sale::find($venta->id)->consolidacion_facturacion_id);
        $this->assertEquals(1, Sale::where('consolidacion_facturacion_id', $consolidada->id)->count());
    }

    /**
     * Test 9 — Si la contenedora se borro, sus ventas originales vuelven a poder consolidarse (es lo
     * que Ventas permitia antes de la validacion). Con la contenedora viva, no.
     *
     * @test
     */
    public function las_ventas_de_una_contenedora_borrada_pueden_volver_a_consolidarse()
    {
        $contenedora = $this->contenedora();
        $venta = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);

        $this->consolidar_por_endpoint([$venta->id])->assertStatus(422);

        $contenedora->delete();

        $nueva = $this->consolidar_sin_emitir([$venta->id]);

        $this->assertNotEquals($contenedora->id, $nueva->id);
        $this->assertEquals($nueva->id, Sale::find($venta->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 10 — El segundo envio de la misma seleccion (doble clic) se rechaza: la primera
     * consolidacion ya marco las ventas, y la validacion corre adentro del candado del cliente.
     *
     * @test
     */
    public function el_segundo_envio_de_la_misma_seleccion_se_rechaza()
    {
        $a = $this->venta();
        $b = $this->venta();

        $primera = $this->consolidar_sin_emitir([$a->id, $b->id]);

        $contenedoras_antes = Sale::where('is_consolidacion_facturacion', 1)->count();

        $respuesta = $this->consolidar_por_endpoint([$a->id, $b->id]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('ya fue incluida en la consolidación N° '.$primera->num, $respuesta->json('message'));
        $this->assertEquals($contenedoras_antes, Sale::where('is_consolidacion_facturacion', 1)->count(), 'No se creo una segunda contenedora.');
        $this->assertEquals($primera->id, Sale::find($a->id)->consolidacion_facturacion_id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * La subcadena esta en el texto (assertContains con strings esta deprecado en PHPUnit 9).
     *
     * @param  string  $aguja
     * @param  string  $texto
     * @return void
     */
    protected function assertTextoContiene($aguja, $texto)
    {
        $this->assertStringContainsString($aguja, (string) $texto);
    }

    /**
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)->first();

        $this->assertNotNull($client, 'Falta el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'" del fixture.');

        return $client;
    }

    /**
     * Una venta terminada del cliente del fixture, sin articulos: alcanza para la validacion y para
     * consolidar (la copia de renglones de una venta vacia no agrega nada).
     *
     * @param  array  $campos  Para pisar columnas.
     * @return \App\Models\Sale
     */
    protected function venta($campos = [])
    {
        return Sale::create(array_merge([
            'user_id'                    => $this->comercio()->id,
            'client_id'                  => $this->cliente()->id,
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ], $campos));
    }

    /**
     * Una venta contenedora ya creada.
     *
     * @return \App\Models\Sale
     */
    protected function contenedora()
    {
        return $this->venta(['is_consolidacion_facturacion' => 1]);
    }

    /**
     * Le pega al endpoint real. Solo se usa en los casos que se RECHAZAN: un caso que consolida
     * emitiria contra ARCA.
     *
     * @param  array  $sale_ids
     * @return \Illuminate\Testing\TestResponse
     */
    protected function consolidar_por_endpoint($sale_ids)
    {
        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        return $this->postJson('api/sales/consolidar-facturacion', [
            'client_id'                => $this->cliente()->id,
            'sale_ids'                 => $sale_ids,
            'afip_information_id'      => $afip_information->id,
            'afip_tipo_comprobante_id' => 1,
            'agrupar_items'            => false,
        ]);
    }

    /**
     * Consolida llamando al helper SIN emitir (emitir pegaria contra ARCA de verdad).
     *
     * @param  array  $sale_ids
     * @return \App\Models\Sale La contenedora.
     */
    protected function consolidar_sin_emitir($sale_ids)
    {
        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        return ConsolidarFacturacionHelper::consolidar(
            $sale_ids,
            $this->cliente()->id,
            $this->comercio()->id,
            $afip_information->id,
            1,
            false,
            [],
            false
        );
    }
}
