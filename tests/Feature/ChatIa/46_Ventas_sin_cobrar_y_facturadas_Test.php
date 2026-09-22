<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ResumenDeVentasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentasSinCobrarIaHelper;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-ventas-y-fotos — C: las cuatro preguntas de ventas que no se podían contestar.
 *
 * 🔴 TRES ASERCIONES QUE NO SON DE FORMA, SON DE PLATA:
 *
 *   1. El `debe > 0` de la query de ventas sin cobrar cubre las DOS ramas del OR. Antes, por la
 *      precedencia de AND sobre OR de MySQL, solo cubría la primera: una cuenta `pagandose` con
 *      `debe` en cero entraba al listado como venta impaga.
 *   2. "Facturada" es el ticket de ARCA con CAE, NUNCA `sales.total_facturado`, que es acumulativo:
 *      una venta facturada y después anulada queda en 0, igual que una que nunca se facturó. Y una
 *      venta consolidada está facturada aunque el comprobante lo tenga la venta contenedora.
 *   3. El filtro por método de pago devuelve VENTAS ENTERAS: no prorratea, y eso viaja dicho en la
 *      respuesta para que el modelo no sume dos filtros y le informe al dueño de más.
 */
class Ventas_sin_cobrar_y_facturadas_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio ventas C',
            'email'    => 'ventas-c-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio ventas C',
            'email'    => 'ventas-c-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    // ------------------------------------------------------------------ C1

    /**
     * El total del negocio, el ranking de deudores y la venta más vieja, en una sola vuelta.
     *
     * @group chat-ia
     * @test
     */
    public function las_ventas_sin_cobrar_de_todo_el_negocio_traen_total_ranking_y_la_mas_vieja()
    {
        $tucumana = Client::create(['name' => 'Tucumana C', 'user_id' => $this->comercio->id]);
        $galvan = Client::create(['name' => 'Galvan C', 'user_id' => $this->comercio->id]);

        // Tucumana: dos ventas impagas, una de ellas la más vieja de todas.
        $this->venta_impaga($tucumana, 1000, 1000, now()->subDays(40));
        $this->venta_impaga($tucumana, 500, 500, now()->subDays(10));

        // Galvan: una sola, pero con algo ya entregado a cuenta.
        $this->venta_impaga($galvan, 2000, 2000, now()->subDays(20), ['pagandose' => 400, 'status' => 'pagandose']);

        // Lo que NO entra: una cuenta saldada y una venta de otro comercio.
        $saldada = $this->venta($this->comercio, now()->subDays(5), 700, ['client_id' => $galvan->id]);
        CurrentAcount::create(['user_id' => $this->comercio->id, 'client_id' => $galvan->id, 'sale_id' => $saldada->id, 'detalle' => 'Saldada', 'debe' => 0, 'saldo' => 0, 'status' => 'pagado']);

        $ajeno = Client::create(['name' => 'Ajeno C', 'user_id' => $this->otro_comercio->id]);
        $venta_ajena = $this->venta($this->otro_comercio, now()->subDays(3), 9999, ['client_id' => $ajeno->id]);
        CurrentAcount::create(['user_id' => $this->otro_comercio->id, 'client_id' => $ajeno->id, 'sale_id' => $venta_ajena->id, 'detalle' => 'Ajena', 'debe' => 9999, 'saldo' => 9999, 'status' => 'sin_pagar']);

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(3, $resultado['ventas_sin_cobrar']);

        // 1000 + 500 + (2000 - 400): el pendiente es debe menos lo entregado a cuenta, no el total.
        $this->assertEquals(3100.0, $resultado['total_pendiente_en_pesos']);

        $this->assertEquals(2, $resultado['clientes_con_deuda']);
        $this->assertEquals(0, $resultado['ventas_sin_cliente']);

        // El ranking va por plata: Galvan (1600) antes que Tucumana (1500).
        $this->assertEquals('Galvan C', $resultado['clientes'][0]['cliente']);
        $this->assertEquals(1600.0, $resultado['clientes'][0]['pendiente_en_pesos']);
        $this->assertEquals('Tucumana C', $resultado['clientes'][1]['cliente']);
        $this->assertEquals(1500.0, $resultado['clientes'][1]['pendiente_en_pesos']);
        $this->assertEquals(2, $resultado['clientes'][1]['ventas_sin_cobrar']);

        /*
         * 🔴 La más vieja sale del conjunto entero y no del ranking: el cliente que la tiene puede
         * no estar entre los que más deben, y "¿cuál es la más vieja?" es media razón de ser de
         * esta consulta.
         */
        $this->assertEquals('Tucumana C', $resultado['venta_mas_vieja']['cliente']);
        $this->assertEquals(40, $resultado['venta_mas_vieja']['dias_sin_cobrar']);
        $this->assertTrue($resultado['venta_mas_vieja']['en_pesos']);

        // Y dice de qué universo habla, que es lo que el modelo iba a inventar.
        $this->assertStringContainsString('no genero deuda', $resultado['criterio']);

        // Sin persona (el llamador habla por el negocio) no hay recorte, y lo declara.
        $this->assertEquals('Todas las ventas del negocio.', $resultado['alcance']);
    }

    /**
     * 🔴 EL DEFECTO DE PRECEDENCIA. Una cuenta `pagandose` con `debe` en cero no es una venta sin
     * cobrar: antes entraba porque el `debe > 0` aplicaba solo a la primera rama del OR.
     *
     * @group chat-ia
     * @test
     */
    public function una_cuenta_pagandose_sin_deuda_no_cuenta_como_venta_sin_cobrar()
    {
        $cliente = Client::create(['name' => 'Cliente precedencia', 'user_id' => $this->comercio->id]);

        // Esta sí: pagandose de verdad, con deuda viva.
        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(5), ['status' => 'pagandose']);

        // Esta no: mismo estado, sin deuda. Es la que entraba por la precedencia.
        $this->venta_impaga($cliente, 800, 0, now()->subDays(5), ['status' => 'pagandose']);

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id);

        $this->assertEquals(1, $resultado['ventas_sin_cobrar']);
        $this->assertEquals(1000.0, $resultado['total_pendiente_en_pesos']);
    }

    /**
     * 🔴 EL OTRO DEFECTO: sin `soloVentasReales()`, una venta CONTENEDORA de consolidación AFIP
     * sumaría deuda que ya está contada en las ventas que agrupa.
     *
     * @group chat-ia
     * @test
     */
    public function una_contenedora_de_consolidacion_afip_no_entra_en_las_ventas_sin_cobrar()
    {
        $cliente = Client::create(['name' => 'Cliente consolidado', 'user_id' => $this->comercio->id]);

        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(5));
        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(5), [], ['is_consolidacion_facturacion' => 1]);

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id);

        $this->assertEquals(1, $resultado['ventas_sin_cobrar']);
        $this->assertEquals(1000.0, $resultado['total_pendiente_en_pesos']);
    }

    /**
     * El `dias` acota por antigüedad, que es "lo que me deben hace más de N días".
     *
     * @group chat-ia
     * @test
     */
    public function el_umbral_de_dias_acota_por_antiguedad()
    {
        $cliente = Client::create(['name' => 'Cliente antiguedad', 'user_id' => $this->comercio->id]);

        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(40));
        $this->venta_impaga($cliente, 500, 500, now()->subDays(2));

        $this->assertEquals(2, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0)['ventas_sin_cobrar']);
        $this->assertEquals(1, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 30)['ventas_sin_cobrar']);
    }

    /**
     * 🔴 LA MONEDA (chequeo adversarial del 21/9/2026). `current_acounts.moneda_id` viene NULL en toda
     * deuda nacida de una venta —`CurrentAcountFromSaleHelper::crear_current_acount()` nunca la
     * escribe— y la moneda real está en `credit_accounts.moneda_id`, que es adonde cae el accesor
     * del modelo. El agregado filtraba con `COALESCE(moneda_id, 1)` sobre la fila pelada, así que
     * TODA la deuda se contaba como pesos y `ventas_en_otra_moneda` daba 0 fijo: a un comercio con
     * ventas en dólares se le sumaban pesos y dólares como si fueran la misma unidad.
     *
     * La prueba de que era un defecto: en la misma respuesta, `venta_mas_vieja` SÍ resolvía la moneda
     * por el accesor, así que una venta en dólares venía con `en_pesos = false` y su deuda ya sumada
     * al total "en pesos". Por eso acá la de dólares es a propósito la más vieja: las dos puntas
     * tienen que decir lo mismo.
     *
     * Las filas se siembran EXACTAMENTE como quedan en producción: `moneda_id` NULL en la cuenta
     * corriente y la moneda solo en la `credit_account`. Sembrarla en la fila haría pasar el test con
     * el código roto.
     *
     * @group chat-ia
     * @test
     */
    public function una_venta_en_dolares_no_entra_en_el_total_en_pesos_y_se_cuenta_aparte()
    {
        $cliente = Client::create(['name' => 'Cliente bimonetario', 'user_id' => $this->comercio->id]);

        // Una cuenta por moneda, como las deja el sistema (model_name + model_id + moneda_id).
        $cuenta_pesos = CreditAccount::create(['model_name' => 'client', 'model_id' => $cliente->id, 'moneda_id' => 1, 'saldo' => 0, 'user_id' => $this->comercio->id]);
        $cuenta_dolares = CreditAccount::create(['model_name' => 'client', 'model_id' => $cliente->id, 'moneda_id' => 2, 'saldo' => 0, 'user_id' => $this->comercio->id]);

        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(10), ['credit_account_id' => $cuenta_pesos->id]);

        // La de dólares, y la más vieja de las dos.
        $this->venta_impaga($cliente, 300, 300, now()->subDays(50), ['credit_account_id' => $cuenta_dolares->id], ['moneda_id' => 2]);

        // Y una sin credit_account (dato viejo): sin moneda en ningún lado se lee como pesos.
        $this->venta_impaga($cliente, 200, 200, now()->subDays(5));

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(3, $resultado['ventas_sin_cobrar']);

        // 1000 + 200: los 300 dólares NO son pesos y no se suman.
        $this->assertEquals(1200.0, $resultado['total_pendiente_en_pesos']);
        $this->assertEquals(1, $resultado['ventas_en_otra_moneda']);

        $this->assertEquals(1200.0, $resultado['clientes'][0]['pendiente_en_pesos']);
        $this->assertEquals(3, $resultado['clientes'][0]['ventas_sin_cobrar']);
        $this->assertEquals(1, $resultado['clientes'][0]['ventas_en_otra_moneda']);

        // 🔴 Las dos puntas coinciden: la más vieja es la de dólares y viene dicha como tal.
        $this->assertEquals(300.0, $resultado['venta_mas_vieja']['pendiente']);
        $this->assertEquals(2, $resultado['venta_mas_vieja']['moneda_id']);
        $this->assertFalse($resultado['venta_mas_vieja']['en_pesos']);
    }

    // ------------------------------------------------------------------ C1, el alcance por persona

    /**
     * 🔴 EL AISLAMIENTO ADENTRO DEL COMERCIO (chequeo adversarial del 21/9/2026). La pantalla
     * "Ventas sin cobrar" arranca con `ver_solo_las_ventas_suyas = true` y solo lo apaga para el dueño
     * o para quien tenga `ver_alertas_de_todos_los_empleados`. La tool pasaba `employee_id = null`
     * siempre: un vendedor que en la pantalla ve únicamente lo suyo le preguntaba al asistente y
     * recibía el total del negocio, el ranking de deudores con nombre y monto, y la venta más vieja de
     * cualquier compañero.
     *
     * @group chat-ia
     * @test
     */
    public function un_vendedor_sin_permiso_de_ver_todo_recibe_solo_sus_ventas()
    {
        $vendedor = $this->empleado('Vendedor C');
        $companero = $this->empleado('Companero C');

        $suyo = Client::create(['name' => 'Cliente del vendedor', 'user_id' => $this->comercio->id]);
        $ajeno = Client::create(['name' => 'Cliente del companero', 'user_id' => $this->comercio->id]);

        // Lo suyo: dos ventas, la más vieja de las suyas tiene 20 días.
        $this->venta_impaga($suyo, 1000, 1000, now()->subDays(20), [], ['employee_id' => $vendedor->id]);
        $this->venta_impaga($suyo, 500, 500, now()->subDays(3), [], ['employee_id' => $vendedor->id]);

        // Lo del compañero: más plata y más vieja, y NO tiene que aparecer.
        $this->venta_impaga($ajeno, 9000, 9000, now()->subDays(90), [], ['employee_id' => $companero->id]);

        // Y una del dueño (sin employee_id): tampoco es del vendedor.
        $this->venta_impaga($ajeno, 700, 700, now()->subDays(30));

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0, $vendedor);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(2, $resultado['ventas_sin_cobrar']);
        $this->assertEquals(1500.0, $resultado['total_pendiente_en_pesos']);

        // El ranking no nombra al cliente del compañero.
        $this->assertEquals(1, $resultado['clientes_con_deuda']);
        $this->assertEquals('Cliente del vendedor', $resultado['clientes'][0]['cliente']);

        // La más vieja es la más vieja DE LAS SUYAS, no la de 90 días del compañero.
        $this->assertEquals('Cliente del vendedor', $resultado['venta_mas_vieja']['cliente']);
        $this->assertEquals(20, $resultado['venta_mas_vieja']['dias_sin_cobrar']);

        // Y la respuesta le dice al modelo que NO es el total del negocio.
        $this->assertStringContainsString('SOLO las ventas', $resultado['alcance']);
    }

    /**
     * El dueño ve todo, y también quien tiene `ver_alertas_de_todos_los_empleados`: los dos casos en
     * que la pantalla apaga el recorte. Un administrador SIN ese permiso, en cambio, ve solo lo suyo,
     * porque la pantalla tampoco le muestra más (se copia lo que hace, no lo que uno esperaría).
     *
     * @group chat-ia
     * @test
     */
    public function el_dueno_y_quien_ve_alertas_de_todos_reciben_todo_el_negocio()
    {
        $vendedor = $this->empleado('Vendedor todo');
        $supervisor = $this->empleado('Supervisor todo', ['ver_alertas_de_todos_los_empleados' => 1]);
        $admin_sin_permiso = $this->empleado('Admin sin permiso', ['admin_access' => 1]);

        $cliente = Client::create(['name' => 'Cliente alcance', 'user_id' => $this->comercio->id]);

        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(10), [], ['employee_id' => $vendedor->id]);
        $this->venta_impaga($cliente, 2000, 2000, now()->subDays(10), [], ['employee_id' => $supervisor->id]);
        $this->venta_impaga($cliente, 4000, 4000, now()->subDays(10));

        $dueno = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0, $this->comercio);
        $this->assertEquals(3, $dueno['ventas_sin_cobrar']);
        $this->assertEquals(7000.0, $dueno['total_pendiente_en_pesos']);
        $this->assertEquals('Todas las ventas del negocio.', $dueno['alcance']);

        $todos = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0, $supervisor);
        $this->assertEquals(3, $todos['ventas_sin_cobrar']);
        $this->assertEquals(7000.0, $todos['total_pendiente_en_pesos']);

        // admin_access no alcanza: la pantalla le recorta igual, y acá también.
        $admin = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0, $admin_sin_permiso);
        $this->assertEquals(0, $admin['ventas_sin_cobrar']);
        $this->assertEquals(0.0, $admin['total_pendiente_en_pesos']);
        $this->assertNull($admin['venta_mas_vieja']);
    }

    /**
     * Sin `dias` del modelo son TODAS las ventas sin cobrar, incluida la de hoy, aunque el comercio
     * tenga configurado un umbral de alertas. Un `dias` explícito sí acota.
     *
     * 🔴 ESTE TEST CAMBIÓ DE CONDUCTA A PROPÓSITO (decisión de Lucas, 22/9/2026). La versión anterior
     * afirmaba que sin `dias` valía la cascada por rol de la pantalla, sobre la premisa de que casi
     * ningún comercio tenía el umbral configurado. La premisa era falsa: `UserSeeder` siembra
     * `dias_alertar_*_ventas_no_cobradas = 1` para todo usuario, así que en la práctica "¿cuánto me
     * deben?" excluía la venta a cuenta corriente hecha esa misma mañana, mientras "¿qué me debe
     * Pérez?" (otra herramienta, con `dias = 0`) sí la incluía. Dos números que no cerraban. El umbral
     * de alertas es para "qué se está atrasando", no para "cuánto me deben".
     *
     * @group chat-ia
     * @test
     */
    public function sin_dias_del_modelo_son_todas_aunque_el_comercio_tenga_umbral_y_el_dias_explicito_acota()
    {
        $cliente = Client::create(['name' => 'Cliente umbral', 'user_id' => $this->comercio->id]);

        $this->venta_impaga($cliente, 1000, 1000, now()->subDays(40));
        $this->venta_impaga($cliente, 500, 500, now()->subDays(2));

        // Nada configurado: todas.
        $this->assertEquals(2, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, null, $this->comercio)['ventas_sin_cobrar']);

        // El comercio tiene el umbral configurado (como lo deja el seeder para todos): sin `dias`
        // del modelo SIGUEN siendo todas — el umbral de alertas no se le aplica a "cuánto me deben".
        $this->comercio->dias_alertar_administradores_ventas_no_cobradas = 30;
        $this->comercio->dias_alertar_empleados_ventas_no_cobradas = 1;
        $this->comercio->save();

        $this->assertEquals(2, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, null, $this->comercio)['ventas_sin_cobrar']);

        // Y un `dias` explícito del modelo sí acota: es la pregunta por lo atrasado.
        $this->assertEquals(1, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 30, $this->comercio)['ventas_sin_cobrar']);
        $this->assertEquals(2, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 0, $this->comercio)['ventas_sin_cobrar']);

        // Un empleado con umbral propio tampoco lo hereda sin `dias`: todas.
        $con_umbral = $this->empleado('Empleado con umbral', ['ver_alertas_de_todos_los_empleados' => 1, 'dias_alertar_empleados_ventas_no_cobradas' => 35]);

        $this->assertEquals(2, VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, null, $con_umbral)['ventas_sin_cobrar']);
    }

    // ------------------------------------------------------------------ C2

    /**
     * 🔴 EL CRITERIO ES EL CAE, NO `total_facturado`. Las cuatro ventas de este test tienen
     * `total_facturado` mintiendo a propósito: la facturada lo tiene en 0 (facturada y después
     * anulada, que es como lo deja AfipNotaCreditoHelper) y una sin facturar lo tiene en positivo.
     *
     * @group chat-ia
     * @test
     */
    public function agrupar_por_facturada_mira_el_cae_del_ticket_y_no_total_facturado()
    {
        $dia = now()->subDays(3)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $articulo = Article::create(['name' => 'Articulo facturada', 'user_id' => $this->comercio->id]);

        // Facturada de verdad: tiene CAE. Y total_facturado en 0, como una facturada y anulada.
        $con_cae = $this->venta($this->comercio, $dia->copy()->addHours(9), 1000, ['total_facturado' => 0], [$articulo->id => [1, 1000]]);
        AfipTicket::create(['sale_id' => $con_cae->id, 'cae' => '71234567890123', 'resultado' => 'A']);

        // No facturada: el ticket existe pero quedó sin CAE (el caso de problemas_al_facturar()).
        $sin_cae = $this->venta($this->comercio, $dia->copy()->addHours(10), 500, ['total_facturado' => 500], [$articulo->id => [1, 500]]);
        AfipTicket::create(['sale_id' => $sin_cae->id, 'cae' => '', 'resultado' => 'A']);

        // No facturada: nunca tuvo ticket.
        $this->venta($this->comercio, $dia->copy()->addHours(11), 300, [], [$articulo->id => [1, 300]]);

        // No facturada: el ticket con CAE está borrado (SoftDeletes).
        $borrado = $this->venta($this->comercio, $dia->copy()->addHours(12), 200, [], [$articulo->id => [1, 200]]);
        AfipTicket::create(['sale_id' => $borrado->id, 'cae' => '79999999999999'])->delete();

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'facturada');

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));

        $grupos = $this->grupos_por_etiqueta($resumen);

        $this->assertEquals(1, $grupos[ResumenDeVentasIaHelper::ETIQUETA_FACTURADA]['cantidad']);
        $this->assertEquals(1000.0, $grupos[ResumenDeVentasIaHelper::ETIQUETA_FACTURADA]['total']);
        $this->assertEquals(3, $grupos[ResumenDeVentasIaHelper::ETIQUETA_SIN_FACTURAR]['cantidad']);
        $this->assertEquals(1000.0, $grupos[ResumenDeVentasIaHelper::ETIQUETA_SIN_FACTURAR]['total']);

        // Y la respuesta avisa que facturada no es cobrada, que es el otro malentendido posible.
        $this->assertStringContainsString('no si se cobro', $resumen['nota']);
    }

    /**
     * 🔴 LA CONSOLIDACIÓN AFIP. La venta consolidada no tiene ticket propio —lo tiene la
     * contenedora— y contarla como "no facturada" sería falso: es una venta facturada de verdad.
     *
     * @group chat-ia
     * @test
     */
    public function una_venta_consolidada_cuenta_como_facturada_por_el_ticket_de_la_contenedora()
    {
        $dia = now()->subDays(4)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $articulo = Article::create(['name' => 'Articulo consolidado', 'user_id' => $this->comercio->id]);

        // La contenedora: tiene el comprobante y queda FUERA del conjunto (soloVentasReales).
        $contenedora = $this->venta($this->comercio, $dia->copy()->addHours(8), 1500, ['is_consolidacion_facturacion' => 1], []);
        AfipTicket::create(['sale_id' => $contenedora->id, 'cae' => '78888888888888']);

        // Las dos que agrupa: sin ticket propio, facturadas igual.
        $this->venta($this->comercio, $dia->copy()->addHours(9), 1000, ['consolidacion_facturacion_id' => $contenedora->id], [$articulo->id => [1, 1000]]);
        $this->venta($this->comercio, $dia->copy()->addHours(10), 500, ['consolidacion_facturacion_id' => $contenedora->id], [$articulo->id => [1, 500]]);

        // Y una suelta sin facturar, para que el grupo exista.
        $this->venta($this->comercio, $dia->copy()->addHours(11), 200, [], [$articulo->id => [1, 200]]);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'facturada');

        $grupos = $this->grupos_por_etiqueta($resumen);

        $this->assertEquals(3, $resumen['cantidad_de_ventas'], 'La contenedora no entra al conjunto.');
        $this->assertEquals(2, $grupos[ResumenDeVentasIaHelper::ETIQUETA_FACTURADA]['cantidad']);
        $this->assertEquals(1500.0, $grupos[ResumenDeVentasIaHelper::ETIQUETA_FACTURADA]['total']);
        $this->assertEquals(1, $grupos[ResumenDeVentasIaHelper::ETIQUETA_SIN_FACTURAR]['cantidad']);
    }

    // ------------------------------------------------------------------ C3

    /**
     * El filtro acota el conjunto y se combina con cualquier agrupación: "cuánto le vendí con
     * tarjeta a Fulano" en una sola llamada.
     *
     * @group chat-ia
     * @test
     */
    public function el_filtro_por_metodo_de_pago_acota_el_conjunto_y_se_combina_con_agrupar_por()
    {
        $dia = now()->subDays(6)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $tarjeta = CurrentAcountPaymentMethod::create(['name' => 'Tarjeta C3 ' . uniqid()]);
        $cliente = Client::create(['name' => 'Cliente C3', 'user_id' => $this->comercio->id]);

        // Con tarjeta por el pivot.
        $con_pivot = $this->venta($this->comercio, $dia->copy()->addHours(9), 1000, ['client_id' => $cliente->id, 'omitir_en_cuenta_corriente' => 1], []);
        DB::table('current_acount_payment_method_sale')->insert([
            'current_acount_payment_method_id' => $tarjeta->id,
            'sale_id'                          => $con_pivot->id,
            'amount'                           => 1000,
        ]);

        // Con tarjeta por la cabecera, sin pivot.
        $this->venta($this->comercio, $dia->copy()->addHours(10), 600, ['client_id' => $cliente->id, 'omitir_en_cuenta_corriente' => 1, 'current_acount_payment_method_id' => $tarjeta->id], []);

        // En efectivo (sin método cargado): queda afuera del filtro.
        $this->venta($this->comercio, $dia->copy()->addHours(11), 400, [], []);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'cliente', 'pesos', $tarjeta->name);

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertEquals(2, $resumen['cantidad_de_ventas']);
        $this->assertEquals(1600.0, $resumen['total']);
        $this->assertEquals($tarjeta->name, $resumen['filtrado_por_metodo_de_pago']);

        // 🔴 Y declara que no prorratea: el importe es el total de la venta, no la parte pagada.
        $this->assertStringContainsString('no la parte pagada con ese metodo', $resumen['aviso_del_filtro']);

        // Las devoluciones no se pueden acotar y van en null, dicho.
        $this->assertNull($resumen['devoluciones']);
        $this->assertArrayHasKey('aviso_de_devoluciones', $resumen);

        // La agrupación también quedó acotada.
        $grupos = $this->grupos_por_etiqueta($resumen);

        $this->assertCount(1, $grupos);
        $this->assertEquals(1600.0, $grupos['Cliente C3']['total']);
    }

    /**
     * "Cuenta corriente" no es una fila de la tabla de métodos: es el grupo único de lo vendido
     * fiado, y se acepta igual como filtro porque es lo que el dueño pregunta.
     *
     * @group chat-ia
     * @test
     */
    public function el_filtro_acepta_cuenta_corriente_y_deja_solo_lo_vendido_fiado()
    {
        $dia = now()->subDays(7)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $cliente = Client::create(['name' => 'Cliente fiado', 'user_id' => $this->comercio->id]);

        $fiada = $this->venta($this->comercio, $dia->copy()->addHours(9), 900, ['client_id' => $cliente->id], []);
        CurrentAcount::create(['user_id' => $this->comercio->id, 'client_id' => $cliente->id, 'sale_id' => $fiada->id, 'detalle' => 'Fiada', 'debe' => 900, 'saldo' => 900, 'status' => 'sin_pagar']);

        $this->venta($this->comercio, $dia->copy()->addHours(10), 300, [], []);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', 'Cuenta corriente');

        $this->assertEquals(1, $resumen['cantidad_de_ventas']);
        $this->assertEquals(900.0, $resumen['total']);
        $this->assertEquals(ResumenDeVentasIaHelper::METODO_CUENTA_CORRIENTE, $resumen['filtrado_por_metodo_de_pago']);
    }

    /**
     * Un método que no existe, y uno ambiguo, cortan con `error` y con las opciones: el modelo
     * pregunta en vez de contestar un número de otro método.
     *
     * @group chat-ia
     * @test
     */
    public function un_metodo_inexistente_o_ambiguo_devuelve_error_con_las_opciones()
    {
        $fecha = now()->subDays(8)->format('Y-m-d');

        $sufijo = uniqid();

        CurrentAcountPaymentMethod::create(['name' => 'Ambiguo ' . $sufijo . ' uno']);
        CurrentAcountPaymentMethod::create(['name' => 'Ambiguo ' . $sufijo . ' dos']);

        $inexistente = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', 'Criptomonedas ' . $sufijo);

        $this->assertArrayHasKey('error', $inexistente);
        $this->assertStringContainsString('Los que existen son', $inexistente['error']);

        $ambiguo = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', 'Ambiguo ' . $sufijo);

        $this->assertArrayHasKey('error', $ambiguo);
        $this->assertStringContainsString('mas de un metodo de pago', $ambiguo['error']);
        $this->assertStringContainsString('Ambiguo ' . $sufijo . ' uno', $ambiguo['error']);
    }

    /**
     * Un nombre exacto desempata contra los que lo contienen: "Efectivo" no tiene por qué chocar
     * con "Efectivo dólar".
     *
     * @group chat-ia
     * @test
     */
    public function un_nombre_exacto_desempata_contra_los_que_lo_contienen()
    {
        $dia = now()->subDays(9)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $sufijo = uniqid();

        $exacto = CurrentAcountPaymentMethod::create(['name' => 'Metodo ' . $sufijo]);
        CurrentAcountPaymentMethod::create(['name' => 'Metodo ' . $sufijo . ' extendido']);

        $this->venta($this->comercio, $dia->copy()->addHours(9), 700, ['current_acount_payment_method_id' => $exacto->id], []);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'pesos', 'Metodo ' . $sufijo);

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertEquals(1, $resumen['cantidad_de_ventas']);
        $this->assertEquals(700.0, $resumen['total']);
    }

    // ------------------------------------------------------------------ C4 y C5

    /**
     * Las tres etiquetas que mentían si se leían literales, ahora dichas en la respuesta.
     *
     * @group chat-ia
     * @test
     */
    public function vendedor_sucursal_y_metodo_de_pago_declaran_que_significan()
    {
        $dia = now()->subDays(11)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $this->venta($this->comercio, $dia->copy()->addHours(9), 500, [], []);

        $vendedor = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'vendedor');
        $this->assertStringContainsString('USUARIO QUE CARGO la venta', $vendedor['nota']);
        $this->assertStringContainsString('no el vendedor comisionista', $vendedor['nota']);

        $sucursal = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'sucursal');
        $this->assertStringContainsString('Sin sucursal', $sucursal['nota']);
        $this->assertStringContainsString('puede ser el 100%', $sucursal['nota']);
        $this->assertEquals('Sin sucursal', $sucursal['grupos'][0]['etiqueta']);

        $metodo = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'metodo_de_pago');
        $this->assertStringContainsString('no se desglosa por como se cobro despues', $metodo['nota']);
    }

    /**
     * `facturada` va AL FINAL del enum: ese array se interpola en la definición de la tool, que es
     * el prefijo que cachea el prompt de Anthropic.
     *
     * @group chat-ia
     * @test
     */
    public function facturada_se_agrego_al_final_de_las_agrupaciones()
    {
        $agrupaciones = ResumenDeVentasIaHelper::AGRUPACIONES;

        $this->assertEquals('facturada', $agrupaciones[count($agrupaciones) - 1]);

        $this->assertEquals(
            ['dia', 'semana', 'mes', 'sucursal', 'vendedor', 'metodo_de_pago', 'cliente', 'articulo', 'rubro', 'proveedor'],
            array_slice($agrupaciones, 0, -1),
            'Lo de antes no se reordena: el orden es el prefijo del caché.'
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Una venta del comercio, con sus renglones.
     *
     * @param  User    $dueno
     * @param  Carbon  $momento
     * @param  float   $total
     * @param  array   $extra
     * @param  array   $renglones
     * @return Sale
     */
    protected function venta(User $dueno, Carbon $momento, $total, array $extra = [], array $renglones = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $dueno->id,
            'total'      => $total,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));

        foreach ($renglones as $article_id => $datos) {
            $venta->articles()->attach($article_id, ['amount' => $datos[0], 'price' => $datos[1], 'cost' => 0]);
        }

        return $venta;
    }

    /**
     * Un empleado del comercio (`owner_id` = el dueño), con los flags que se le pasen.
     *
     * @param  string  $nombre
     * @param  array   $extra
     * @return User
     */
    protected function empleado($nombre, array $extra = [])
    {
        return User::create(array_merge([
            'name'     => $nombre,
            'email'    => 'ventas-c-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * Una venta con su movimiento de cuenta corriente impago.
     *
     * @param  Client  $cliente
     * @param  float   $total
     * @param  float   $debe
     * @param  Carbon  $momento
     * @param  array   $extra_cuenta
     * @param  array   $extra_venta
     * @return Sale
     */
    protected function venta_impaga($cliente, $total, $debe, Carbon $momento, array $extra_cuenta = [], array $extra_venta = [])
    {
        $venta = $this->venta($this->comercio, $momento, $total, array_merge(['client_id' => $cliente->id], $extra_venta));

        CurrentAcount::create(array_merge([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'sale_id'    => $venta->id,
            'detalle'    => 'Venta impaga',
            'debe'       => $debe,
            'saldo'      => $debe,
            'status'     => 'sin_pagar',
            'created_at' => $momento,
        ], $extra_cuenta));

        return $venta;
    }

    /**
     * Los grupos de un resumen, indexados por su etiqueta.
     *
     * @param  array  $resumen
     * @return array<string, array>
     */
    protected function grupos_por_etiqueta(array $resumen): array
    {
        $grupos = [];

        foreach ($resumen['grupos'] as $grupo) {
            $grupos[$grupo['etiqueta']] = $grupo;
        }

        return $grupos;
    }
}
