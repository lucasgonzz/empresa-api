<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\contabilidad\PosicionFiscalHelper;
use App\Http\Controllers\Helpers\contabilidad\ContabilidadRepository;
use App\Models\CAPaymentMethodType;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MovimientoCaja;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\RetencionSufrida;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Las retenciones sufridas se cargan en el COBRO de la cuenta corriente de un cliente, como un
 * medio de pago más (misión compras-factura-manual-alicuotas, 17/9/2026, parte C).
 *
 * 🔴 LA REGLA QUE SOSTIENE TODA LA SUITE. Si el cliente te debe $100.000 y te retiene $2.000, te
 * paga $98.000 y la deuda se cancela por $100.000. La retención es un medio de pago, no un
 * descuento ni una quita: la plata que no entró a la caja igual canceló deuda, porque el cliente la
 * depositó a tu nombre en ARCA. Si alguna vez el cobro pasara a registrar $98.000 de haber, al
 * cliente le quedarían $2.000 de deuda que ya pagó — es el error clásico de este circuito y es el
 * que mide el primer test de acá.
 *
 * El mecanismo es el mismo del cheque: un tipo de medio de pago (`c_a_payment_method_types.slug`)
 * que suma al haber y no manda plata a ninguna caja. Por eso ni `get_haber()` ni
 * `CurrentAcountPagoHelper` saben que la retención existe, y no tuvieron que tocarse.
 *
 * @group current-acount
 * @group retenciones
 */
class Retenciones_sufridas_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta para comparar montos. */
    const DELTA = 0.01;

    /** La deuda que se le siembra al cliente en cada escenario. */
    const DEUDA = 100000;

    /** Lo que el cliente paga en efectivo. */
    const EFECTIVO = 98000;

    /** Lo que el cliente retiene. */
    const RETENCION = 2000;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Caja */
    protected $caja;

    /** @var array<int,int> Ids de retenciones creadas a mano por los tests, para el cleanup. */
    protected $retenciones_creadas = [];

    /** @var array<int,int> Ids de facturas de compra creadas a mano, para el cleanup. */
    protected $tickets_creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->caja = Caja::where('name', TestingFerreteriaSeeder::CAJA_EFECTIVO)->first();

        $this->assertNotNull($this->caja, 'No existe la caja "'.TestingFerreteriaSeeder::CAJA_EFECTIVO.'" en el fixture.');

        $this->asegurar_caja_abierta($this->caja);
    }

    protected function tearDown(): void
    {
        RetencionSufrida::whereIn('id', $this->retenciones_creadas)->delete();
        ProviderOrderAfipTicket::whereIn('id', $this->tickets_creados)->delete();

        $this->limpiar_escenarios();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * El medio de pago cuyo tipo tiene el slug `retencion`.
     *
     * Se busca por SLUG y no por nombre a propósito: el nombre lo puede cambiar el comercio desde
     * la configuración de métodos de pago, el slug del tipo no (es lo que mira el backend).
     *
     * @return \App\Models\CurrentAcountPaymentMethod
     */
    protected function metodo_de_retencion()
    {
        $type = CAPaymentMethodType::where('slug', 'retencion')->first();

        if (is_null($type)) {
            $this->fail('No existe el tipo de medio de pago con slug "retencion". Falta correr CAPaymentMethodTypeSeeder o el seeder suelto MetodoPagoRetencionSeeder.');
        }

        $metodo = CurrentAcountPaymentMethod::where('c_a_payment_method_type_id', $type->id)->first();

        if (is_null($metodo)) {
            $this->fail('No existe ningún método de pago del tipo "retencion". Falta correr el seeder suelto MetodoPagoRetencionSeeder.');
        }

        return $metodo;
    }

    /**
     * Un cliente nuevo del dueño, con su cuenta corriente en pesos y una deuda sembrada por el
     * endpoint real de nota de débito.
     *
     * Cliente nuevo y no el `CLIENTE_CC` del fixture: la cuenta del fixture la usan otras suites y
     * lo que se mide acá es un saldo que tiene que quedar en CERO exacto.
     *
     * @param  string $nombre
     * @return array{0: \App\Models\Client, 1: \App\Models\CreditAccount, 2: \App\Models\CurrentAcount}
     */
    protected function cliente_con_deuda($nombre)
    {
        $cliente = Client::create([
            'num'     => (int) Client::where('user_id', $this->dueno->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->dueno->id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);

        $cuenta = CreditAccount::where('model_name', 'client')
                                ->where('model_id', $cliente->id)
                                ->where('moneda_id', 1)
                                ->first();

        $this->assertNotNull($cuenta, 'El cliente quedó sin cuenta corriente en pesos.');

        $response = $this->postJson('api/current-acount/nota-debito', [
            'credit_account_id' => $cuenta->id,
            'model_name'        => 'client',
            'model_id'          => $cliente->id,
            'debe'              => self::DEUDA,
            'description'       => 'Deuda del test de retenciones',
        ]);

        $response->assertStatus(201);

        $debito = CurrentAcount::find($response->json('current_acount.id'));

        $this->cobros_cc_creados_por_escenarios[] = $debito->id;

        return [$cliente, $cuenta, $debito];
    }

    /**
     * Payload de `POST api/current-acount/pago`: efectivo a la caja + una fila de retención con su
     * certificado. Copiado de lo que manda el modal de cobro de la SPA.
     *
     * @param  \App\Models\Client $cliente
     * @param  \App\Models\CreditAccount $cuenta
     * @param  array $datos_certificado Claves `retencion_*` de la fila de retención.
     * @return array
     */
    protected function payload_con_retencion($cliente, $cuenta, $datos_certificado = [])
    {
        $metodo_efectivo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        $metodo_retencion = $this->metodo_de_retencion();

        $fila_retencion = array_merge([
            'current_acount_payment_method_id' => $metodo_retencion->id,
            'amount'                           => self::RETENCION,
            // Sin caja: es justamente lo que hace que la plata no entre a ninguna.
            'caja_id'                          => 0,
            'moneda_id'                        => 1,
        ], $datos_certificado);

        return [
            'description'                    => 'Cobro con retención',
            'credit_account_id'              => $cuenta->id,
            'is_provisorio'                  => 0,
            'model_name'                     => 'client',
            'model_id'                       => $cliente->id,
            'current_date'                   => 1,
            // El modal manda el total de las filas, retención incluida.
            'haber'                          => self::EFECTIVO + self::RETENCION,
            'current_acount_payment_methods' => [
                [
                    'current_acount_payment_method_id' => $metodo_efectivo->id,
                    'amount'                           => self::EFECTIVO,
                    'caja_id'                          => $this->caja->id,
                    'moneda_id'                        => 1,
                ],
                $fila_retencion,
            ],
        ];
    }

    /**
     * Postea el cobro y devuelve el `CurrentAcount` creado.
     *
     * @param  array $payload
     * @return \App\Models\CurrentAcount
     */
    protected function cobrar($payload)
    {
        $response = $this->postJson('api/current-acount/pago', $payload);

        $response->assertStatus(201);

        $pago_id = $this->extraer_id_de_respuesta(
            $response,
            'api/current-acount/pago',
            null,
            ['current_acount', 'model']
        );

        $this->cobros_cc_creados_por_escenarios[] = $pago_id;

        return CurrentAcount::find($pago_id);
    }

    /**
     * Saldo fiscal CON SIGNO: PosicionFiscalHelper devuelve el monto en valor absoluto más el tipo,
     * así que para comparar dos mediciones hay que volver a armarlo (si no, un saldo que cruza el
     * cero se lee como si hubiera subido).
     *
     * @param  array $posicion
     * @return float
     */
    protected function saldo_con_signo($posicion)
    {
        if ($posicion['tipo'] == 'a_favor') {

            return -1 * (float) $posicion['saldo'];
        }

        return (float) $posicion['saldo'];
    }

    // ------------------------------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------------------------------

    /**
     * 🔴 EL TEST QUE SOSTIENE TODO. $98.000 en efectivo + $2.000 de retención cancelan una deuda de
     * $100.000 ENTERA: el débito queda en `pagado` y la cuenta corriente en cero. Si la retención
     * se restara del cobro en vez de sumar al haber, acá quedarían $2.000 de deuda que el cliente
     * ya pagó.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function un_cobro_con_efectivo_y_retencion_cancela_la_deuda_entera()
    {
        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Deuda Entera');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta));

        $this->assertEqualsWithDelta(
            self::DEUDA,
            (float) $pago->haber,
            self::DELTA,
            'El haber del cobro no es la suma de los medios de pago: la retención no sumó.'
        );

        $debito->refresh();

        $this->assertEquals(
            'pagado',
            $debito->status,
            'La deuda de $'.self::DEUDA.' no quedó cancelada: el cobro fue de $'.self::EFECTIVO.' en efectivo MÁS $'.self::RETENCION.' de retención.'
        );

        $cuenta->refresh();

        $this->assertEqualsWithDelta(
            0,
            (float) $cuenta->saldo,
            self::DELTA,
            'Al cliente le quedó saldo en la cuenta corriente después de pagar la deuda entera.'
        );
    }

    /**
     * 🔴 La retención NO genera movimiento de caja; el efectivo sí, y por su propio monto.
     *
     * La plata de la retención no la tiene el comercio: la depositó el cliente a su nombre en ARCA.
     * Si apareciera en la caja, el arqueo del día cerraría con $2.000 de más que no están.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function la_retencion_no_genera_movimiento_de_caja_y_el_efectivo_si()
    {
        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Sin Caja');

        $antes = $this->max_id_movimiento_caja();

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta));

        $this->registrar_movimientos_caja_nuevos($antes);

        $movimientos = MovimientoCaja::where('id', '>', $antes)->get();

        $this->assertCount(
            1,
            $movimientos,
            'El cobro generó '.count($movimientos).' movimientos de caja. Tiene que generar UNO solo: el del efectivo. La retención no entra a ninguna caja.'
        );

        $this->assertEquals(
            $this->caja->id,
            $movimientos[0]->caja_id,
            'El movimiento no cayó en la caja del efectivo.'
        );

        $this->assertEqualsWithDelta(
            self::EFECTIVO,
            (float) $movimientos[0]->ingreso,
            self::DELTA,
            'A la caja entró un monto distinto de los $'.self::EFECTIVO.' de efectivo: la retención se le sumó.'
        );

        // Y las dos filas de medio de pago quedaron igual adjuntas al cobro.
        $this->assertCount(
            2,
            $pago->current_acount_payment_methods,
            'El cobro no guardó los dos medios de pago.'
        );
    }

    /**
     * El certificado queda guardado con todos sus campos, atado al cobro y al cliente que retuvo.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function el_certificado_de_retencion_queda_guardado_con_sus_campos()
    {
        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Certificado');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta, [
            'retencion_impuesto'           => 'iva',
            'retencion_numero_certificado' => 'A-000123',
            'retencion_fecha'              => '2026-09-10',
            'retencion_regimen'            => 'RG 2854',
            'retencion_base_imponible'     => 100000,
            'retencion_alicuota'           => 2,
        ]));

        $certificados = RetencionSufrida::where('current_acount_id', $pago->id)->get();

        $this->assertCount(1, $certificados, 'No se guardó el certificado de la retención.');

        $certificado = $certificados[0];

        $this->retenciones_creadas[] = $certificado->id;

        $this->assertEquals('iva', $certificado->impuesto);
        $this->assertEquals('A-000123', $certificado->numero_certificado);
        $this->assertEquals('2026-09-10', Carbon::parse($certificado->fecha)->format('Y-m-d'));
        $this->assertEquals('RG 2854', $certificado->regimen);
        $this->assertEqualsWithDelta(100000, (float) $certificado->base_imponible, self::DELTA);
        $this->assertEqualsWithDelta(2, (float) $certificado->alicuota, self::DELTA);
        $this->assertEqualsWithDelta(self::RETENCION, (float) $certificado->importe, self::DELTA, 'El importe del certificado no es el monto de la fila de medio de pago.');
        $this->assertEquals($cliente->id, $certificado->client_id, 'El certificado no quedó atado al cliente que retuvo.');
        $this->assertEquals($this->dueno->id, $certificado->user_id);
        $this->assertEquals(RetencionSufrida::ORIGEN_COBRO, $certificado->origen);
    }

    /**
     * El papel incompleto no bloquea el cobro: sin número de certificado, sin régimen y sin base
     * imponible, el certificado igual se guarda. La fecha, que es obligatoria, cae en la del cobro.
     *
     * Es la decisión explícita de la misión: exigirle a un comercio chico el régimen de una
     * retención le impediría registrar la plata que entró, y eso es peor que guardar el dato flojo.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function un_certificado_sin_los_datos_administrativos_igual_se_guarda()
    {
        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Papel Incompleto');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta, [
            'retencion_impuesto'           => 'iibb',
            'retencion_numero_certificado' => '',
            'retencion_fecha'              => '',
            'retencion_regimen'            => '',
            'retencion_base_imponible'     => '',
            'retencion_alicuota'           => '',
        ]));

        $certificado = RetencionSufrida::where('current_acount_id', $pago->id)->first();

        $this->assertNotNull($certificado, 'Un certificado con el papel incompleto no se guardó: el cobro no se puede bloquear por un campo administrativo.');

        $this->retenciones_creadas[] = $certificado->id;

        $this->assertEquals('iibb', $certificado->impuesto);
        $this->assertNull($certificado->numero_certificado);
        $this->assertNull($certificado->regimen);
        $this->assertNull($certificado->base_imponible, 'La base imponible vacía quedó en 0 en vez de NULL: un 0 se lee como "la base fue cero".');
        $this->assertNull($certificado->alicuota);

        $this->assertEquals(
            Carbon::parse($pago->created_at)->format('Y-m-d'),
            Carbon::parse($certificado->fecha)->format('Y-m-d'),
            'Sin fecha en el papel, el certificado tiene que quedar fechado con la del cobro.'
        );
    }

    /**
     * Si se borra el cobro, los certificados se van con él. Un certificado huérfano no se ve en
     * ninguna pantalla pero sí lo sigue sumando la Posición Fiscal.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function borrar_el_cobro_se_lleva_los_certificados()
    {
        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Baja');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta, [
            'retencion_impuesto' => 'ganancias',
        ]));

        $this->assertEquals(1, RetencionSufrida::where('current_acount_id', $pago->id)->count());

        $this->deleteJson('api/current-acount/client/'.$pago->id)->assertStatus(200);

        $this->assertEquals(
            0,
            RetencionSufrida::where('current_acount_id', $pago->id)->count(),
            'El certificado quedó huérfano después de borrar el cobro.'
        );
    }

    /**
     * La Posición Fiscal lee la retención de la tabla nueva y la resta donde corresponde: el
     * renglón de retención de IVA sube por el importe y el saldo de IVA baja por el mismo monto.
     *
     * Se mide por DELTA contra la medición previa y no contra un número absoluto: la base de
     * testing la comparten otras suites y cualquier dato sembrado por otro test rompería una
     * aserción absoluta.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function la_posicion_fiscal_resta_la_retencion_nueva()
    {
        $desde = Carbon::now()->startOfMonth()->format('Y-m-d');
        $hasta = Carbon::now()->endOfMonth()->format('Y-m-d');

        $antes = PosicionFiscalHelper::posicion_iva($this->dueno->id, $desde, $hasta);

        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Posicion');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta, [
            'retencion_impuesto' => 'iva',
            'retencion_fecha'    => Carbon::now()->format('Y-m-d'),
        ]));

        $certificado = RetencionSufrida::where('current_acount_id', $pago->id)->first();
        $this->assertNotNull($certificado);
        $this->retenciones_creadas[] = $certificado->id;

        $despues = PosicionFiscalHelper::posicion_iva($this->dueno->id, $desde, $hasta);

        $this->assertEqualsWithDelta(
            self::RETENCION,
            (float) $despues['retencion_iva_sufrida'] - (float) $antes['retencion_iva_sufrida'],
            self::DELTA,
            'La Posición Fiscal no ve la retención nueva: sigue leyendo la fuente vieja.'
        );

        $this->assertEqualsWithDelta(
            -1 * self::RETENCION,
            $this->saldo_con_signo($despues) - $this->saldo_con_signo($antes),
            self::DELTA,
            'El saldo de IVA no bajó por la retención: la fórmula la tiene que restar.'
        );
    }

    /**
     * El drill-down de una retención cargada en un cobro lleva al cobro, no a una compra.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function el_detalle_de_la_retencion_lleva_al_cobro()
    {
        $desde = Carbon::now()->startOfMonth()->format('Y-m-d');
        $hasta = Carbon::now()->endOfMonth()->format('Y-m-d');

        list($cliente, $cuenta, $debito) = $this->cliente_con_deuda('Cliente Retencion Drilldown');

        $pago = $this->cobrar($this->payload_con_retencion($cliente, $cuenta, [
            'retencion_impuesto'           => 'ganancias',
            'retencion_numero_certificado' => 'G-9001',
            'retencion_fecha'              => Carbon::now()->format('Y-m-d'),
        ]));

        $certificado = RetencionSufrida::where('current_acount_id', $pago->id)->first();
        $this->assertNotNull($certificado);
        $this->retenciones_creadas[] = $certificado->id;

        $detalle = ContabilidadRepository::retenciones_sufridas_detalle($this->dueno->id, $desde, $hasta, 1, 200);

        $fila = null;

        foreach ($detalle['registros'] as $registro) {

            if ($registro['id'] == $certificado->id) {
                $fila = $registro;
            }
        }

        $this->assertNotNull($fila, 'La retención no aparece en el detalle del reporte.');

        $this->assertEquals('current_acount', $fila['link_tipo'], 'El drill-down sigue llevando a la compra: el origen de una retención ahora es el cobro.');
        $this->assertEquals($pago->id, $fila['link_id']);
        $this->assertEqualsWithDelta(self::RETENCION, (float) $fila['monto'], self::DELTA);
        $this->assertStringContainsString('Ganancias', $fila['descripcion']);
        $this->assertStringContainsString('G-9001', $fila['descripcion']);
    }

    /**
     * 🔴 La migración de datos trae las retenciones que ya estaban cargadas en las facturas de
     * compra, sin perder ninguna.
     *
     * Sin esto, el día del despliegue todo cliente que las tenía cargadas ve el renglón de
     * retenciones sufridas en cero y le cambia el saldo de IVA y de IIBB del período.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function la_migracion_de_datos_trae_las_retenciones_viejas()
    {
        $provider = Provider::where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();
        $this->assertNotNull($provider, 'No existe el proveedor del fixture.');

        $compra = ProviderOrder::create([
            'user_id'     => $this->dueno->id,
            'provider_id' => $provider->id,
        ]);

        $ticket = ProviderOrderAfipTicket::create([
            'user_id'             => $this->dueno->id,
            'provider_order_id'   => $compra->id,
            'code'                => 'A-0001-00001234',
            'issued_at'           => '2026-05-14 10:00:00',
            'total'               => 121000,
            'total_iva'           => 21000,
            'retencion_iva'       => 500,
            'retencion_iibb'      => 300,
            'retencion_ganancias' => 0,
        ]);

        $this->tickets_creados[] = $ticket->id;

        $migracion = $this->instanciar_migracion_de_datos();

        $movidas = $migracion->migrar();

        $this->assertGreaterThanOrEqual(2, $movidas, 'La migración no movió las retenciones de la factura.');

        $traidas = RetencionSufrida::where('provider_order_afip_ticket_id', $ticket->id)->get();

        foreach ($traidas as $traida) {
            $this->retenciones_creadas[] = $traida->id;
        }

        $this->assertCount(
            2,
            $traidas,
            'La factura tenía retención de IVA y de IIBB (y Ganancias en 0): tienen que quedar DOS filas, no una ni tres.'
        );

        $por_impuesto = [];

        foreach ($traidas as $traida) {
            $por_impuesto[$traida->impuesto] = $traida;
        }

        $this->assertArrayHasKey('iva', $por_impuesto);
        $this->assertArrayHasKey('iibb', $por_impuesto);
        $this->assertArrayNotHasKey('ganancias', $por_impuesto, 'Se migró una retención de Ganancias que valía 0.');

        $this->assertEqualsWithDelta(500, (float) $por_impuesto['iva']->importe, self::DELTA);
        $this->assertEqualsWithDelta(300, (float) $por_impuesto['iibb']->importe, self::DELTA);

        foreach ($traidas as $traida) {

            $this->assertEquals(
                '2026-05-14',
                Carbon::parse($traida->fecha)->format('Y-m-d'),
                'La retención migrada no quedó fechada por el issued_at del comprobante.'
            );

            $this->assertNull($traida->client_id, 'De una factura de compra no hay de dónde sacar qué cliente retuvo: client_id tiene que quedar NULL.');
            $this->assertNull($traida->current_acount_id, 'Una retención migrada no está atada a ningún cobro.');
            $this->assertEquals(RetencionSufrida::ORIGEN_MIGRACION_COMPRA, $traida->origen, 'Falta la marca de que el origen es la migración.');
        }

        // Las columnas viejas siguen intactas: se pueden volver a leer si hace falta.
        $ticket->refresh();
        $this->assertEqualsWithDelta(500, (float) $ticket->retencion_iva, self::DELTA);
        $this->assertEqualsWithDelta(300, (float) $ticket->retencion_iibb, self::DELTA);

        // Y correrla de nuevo no duplica nada (clientes con huecos en la tabla `migrations`).
        $segunda = $migracion->migrar();

        $this->assertEquals(0, $segunda, 'La migración volvió a traer lo que ya había traído: duplicaría las retenciones.');

        $this->assertEquals(
            2,
            RetencionSufrida::where('provider_order_afip_ticket_id', $ticket->id)->count(),
            'La segunda corrida duplicó las filas.'
        );
    }

    /**
     * Una retención migrada sigue pudiendo auditarse: su drill-down lleva a la compra de la que
     * salió, porque cobro no tiene.
     *
     * @group current-acount
     * @group retenciones
     * @test
     */
    public function el_detalle_de_una_retencion_migrada_lleva_a_la_compra()
    {
        $provider = Provider::where('name', TestingFerreteriaSeeder::PROVIDER_BSAS)->first();

        $compra = ProviderOrder::create([
            'user_id'     => $this->dueno->id,
            'provider_id' => $provider->id,
        ]);

        $ticket = ProviderOrderAfipTicket::create([
            'user_id'           => $this->dueno->id,
            'provider_order_id' => $compra->id,
            'code'              => 'A-0001-00009999',
            'issued_at'         => Carbon::now()->format('Y-m-d').' 10:00:00',
            'retencion_iva'     => 750,
        ]);

        $this->tickets_creados[] = $ticket->id;

        $movidas = $this->instanciar_migracion_de_datos()->migrar();

        $this->assertGreaterThanOrEqual(1, $movidas);

        $traida = RetencionSufrida::where('provider_order_afip_ticket_id', $ticket->id)->first();
        $this->assertNotNull($traida);
        $this->retenciones_creadas[] = $traida->id;

        $desde = Carbon::now()->startOfMonth()->format('Y-m-d');
        $hasta = Carbon::now()->endOfMonth()->format('Y-m-d');

        $detalle = ContabilidadRepository::retenciones_sufridas_detalle($this->dueno->id, $desde, $hasta, 1, 200);

        $fila = null;

        foreach ($detalle['registros'] as $registro) {

            if ($registro['id'] == $traida->id) {
                $fila = $registro;
            }
        }

        $this->assertNotNull($fila, 'La retención migrada no aparece en el detalle.');
        $this->assertEquals('provider_order', $fila['link_tipo']);
        $this->assertEquals($compra->id, $fila['link_id']);
    }

    /**
     * Carga la clase de la migración de datos. No es autoloadable (las migraciones no están en el
     * PSR-4 del composer.json), así que se incluye el archivo por ruta.
     *
     * @return \MigrarRetencionesDeFacturasDeCompra
     */
    protected function instanciar_migracion_de_datos()
    {
        if (!class_exists('MigrarRetencionesDeFacturasDeCompra')) {

            require_once database_path('migrations/2026_09_17_100100_migrar_retenciones_de_facturas_de_compra.php');
        }

        return new \MigrarRetencionesDeFacturasDeCompra();
    }
}
