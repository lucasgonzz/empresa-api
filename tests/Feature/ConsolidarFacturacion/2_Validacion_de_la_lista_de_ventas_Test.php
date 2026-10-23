<?php

namespace Tests\Feature\ConsolidarFacturacion;

use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
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
 *    - una venta inexistente (se consolidaban las que si estaban y se ignoraba el resto),
 *    - una venta de mostrador, si el POST no traia `client_id`.
 *  Un doble envio o un POST armado a mano consolidaba dos veces la misma venta y emitia dos
 *  facturas. La unica barrera era la SPA, y el menu "Seleccion" de Ventas tenia las suyas
 *  comentadas.
 *
 *  🔴 TODO SE PRUEBA LLAMANDO AL HELPER CON `emitir_afip = false`. El endpoint siempre emite, y si la
 *  validacion se rompiera algun dia un test de rechazo por el endpoint saldria a ARCA de verdad.
 *  El endpoint solo se prueba con las dos entradas que no pueden llegar a emitir ni siquiera con la
 *  validacion rota (ver el test 12).
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

        $this->assertRechaza([$libre->id, $ya_consolidada->id], 'ya fue incluida en la consolidación N° '.$contenedora->id);

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

        $this->assertRechaza([$facturada->id], 'ya tiene un comprobante AFIP autorizado');

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

        $this->assertRechaza([$del_cliente->id, $de_otro->id], 'no corresponde al cliente indicado');

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

        $this->assertRechaza([$ajena->id], 'no pertenece al usuario actual');

        $this->assertNull(Sale::find($ajena->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 5 — Un id borrado rechaza TODA la lista: antes se consolidaban las que si estaban y se
     * ignoraba la otra, y la factura salia incompleta.
     *
     * @test
     */
    public function un_id_borrado_rechaza_toda_la_lista()
    {
        $existente = $this->venta();
        $borrada = $this->venta();
        $borrada->delete();

        $this->assertRechaza([$existente->id, $borrada->id], 'no existen');

        $this->assertNull(Sale::find($existente->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 6 — Una contenedora no se puede volver a consolidar.
     *
     * @test
     */
    public function una_contenedora_no_se_vuelve_a_consolidar()
    {
        $contenedora = $this->contenedora();

        $this->assertRechaza([$contenedora->id], 'es una consolidación y no puede volver a consolidarse');
    }

    /**
     * Test 7 — Sin cliente no hay consolidacion. Un POST sin `client_id` llega a la API como 0 (el
     * controlador castea a int) y una venta de mostrador tiene `client_id` NULL: con una
     * comparacion floja (`null != 0` es false en PHP) pasaba, se creaba una contenedora sin cliente
     * y ni siquiera se tomaba el candado de la cuenta.
     *
     * @test
     */
    public function sin_cliente_no_se_consolida_ni_una_venta_de_mostrador()
    {
        $de_mostrador = $this->venta(['client_id' => null]);

        $this->assertRechaza([$de_mostrador->id], 'Las ventas de mostrador (sin cliente) no se pueden consolidar', 0);

        // Y con un cliente indicado, la venta de mostrador tampoco es de ese cliente.
        $this->assertRechaza([$de_mostrador->id], 'no corresponde al cliente indicado');

        $this->assertNull(Sale::find($de_mostrador->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 8 — Lo que Ventas hace todos los dias sigue andando: ventas terminadas y sin factura del
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
     * Test 9 — La misma venta elegida dos veces no es una venta inexistente: se consolida una vez.
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
     * Test 10 — Si la contenedora se borro, sus ventas originales vuelven a poder consolidarse (es
     * lo que Ventas permitia antes de la validacion). Con la contenedora viva, no.
     *
     * @test
     */
    public function las_ventas_de_una_contenedora_borrada_pueden_volver_a_consolidarse()
    {
        $contenedora = $this->contenedora();
        $venta = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);

        $this->assertRechaza([$venta->id], 'ya fue incluida en la consolidación');

        $contenedora->delete();

        $nueva = $this->consolidar_sin_emitir([$venta->id]);

        $this->assertNotEquals($contenedora->id, $nueva->id);
        $this->assertEquals($nueva->id, Sale::find($venta->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 11 — El segundo envio de la misma seleccion (doble clic) se rechaza: la primera
     * consolidacion ya marco las ventas, y la validacion corre adentro del candado del cliente.
     *
     * @test
     */
    public function el_segundo_envio_de_la_misma_seleccion_se_rechaza()
    {
        $a = $this->venta();
        $b = $this->venta();

        $primera = $this->consolidar_sin_emitir([$a->id, $b->id]);

        $this->assertRechaza([$a->id, $b->id], 'ya fue incluida en la consolidación N° '.$primera->num);

        $this->assertEquals($primera->id, Sale::find($a->id)->consolidacion_facturacion_id);
    }

    /**
     * Test 12 — El endpoint traduce el rechazo a un 422 con el mensaje (lo que muestra el modal de
     * la SPA). Solo con entradas que NO pueden llegar a emitir ni siquiera si la validacion se
     * rompiera: una lista vacia o con un id ya borrado no tiene ventas que copiar, asi que
     * `consolidar()` falla antes de crear nada. Cualquier otra entrada, si algun dia pasara la
     * validacion, emitiria una factura contra ARCA de verdad.
     *
     * @test
     */
    public function el_endpoint_responde_422_con_el_mensaje_del_rechazo()
    {
        $borrada = $this->venta();
        $borrada->delete();

        $respuesta = $this->consolidar_por_endpoint([$borrada->id]);

        $respuesta->assertStatus(422)->assertJsonFragment(['error' => true]);
        $this->assertTextoContiene('no existen', $respuesta->json('message'));

        $respuesta = $this->consolidar_por_endpoint([]);

        $respuesta->assertStatus(422);
        $this->assertTextoContiene('al menos una venta', $respuesta->json('message'));
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
     * Consolida llamando al helper SIN emitir y exige que lo rechace con ese mensaje, sin crear
     * ninguna contenedora. Los rechazos se prueban por el helper y no por el endpoint a proposito:
     * el endpoint siempre emite, y si la validacion se rompiera un test de rechazo saldria a ARCA.
     *
     * @param  array     $sale_ids
     * @param  string    $texto      Parte del mensaje esperado.
     * @param  int|null  $client_id  null = el cliente del fixture.
     * @return void
     */
    protected function assertRechaza($sale_ids, $texto, $client_id = null)
    {
        $contenedoras_antes = Sale::where('is_consolidacion_facturacion', 1)->count();

        try {
            $this->consolidar_sin_emitir($sale_ids, $client_id);
        } catch (\Exception $e) {
            $this->assertTextoContiene($texto, $e->getMessage());
            $this->assertEquals($contenedoras_antes, Sale::where('is_consolidacion_facturacion', 1)->count(), 'Un rechazo no crea ninguna contenedora.');
            return;
        }

        $this->fail('Se esperaba que se rechazara con: '.$texto);
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
     * Le pega al endpoint real. Solo se usa con las entradas del test 12, que no pueden llegar a
     * emitir: cualquier otra emitiria contra ARCA.
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
     * @param  array     $sale_ids
     * @param  int|null  $client_id  null = el cliente del fixture.
     * @return \App\Models\Sale La contenedora.
     */
    protected function consolidar_sin_emitir($sale_ids, $client_id = null)
    {
        $afip_information = AfipInformation::where('user_id', $this->comercio()->id)->first();

        $this->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

        return ConsolidarFacturacionHelper::consolidar(
            $sale_ids,
            is_null($client_id) ? $this->cliente()->id : $client_id,
            $this->comercio()->id,
            $afip_information->id,
            1,
            false,
            [],
            false
        );
    }
}
