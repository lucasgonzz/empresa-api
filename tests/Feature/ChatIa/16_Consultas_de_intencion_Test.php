<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderStatus;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — bloque B1/B2: las consultas de intención del asistente.
 *
 * Son las preguntas que el dueño hace siempre y que hasta hoy el asistente contestaba mal (o
 * contestaba otra cosa muy parecida). Cada test fija el caso REAL que las justificó, no una
 * variante cómoda:
 *
 *  - la venta más vieja que le debe un cliente, en un cliente con veinte pagos posteriores;
 *  - qué CLIENTE compró un artículo (el ERP), no quién lo miró en la tienda;
 *  - cuándo fue la última COMPRA de un artículo y a quién, que ninguna tool tocaba;
 *  - qué le compré a un proveedor, sin sumar monedas distintas en un mismo total;
 *  - el stock repartido por sucursal;
 *  - y los movimientos de cuenta corriente con ventana, tipo, orden, página y SIN mezclar la
 *    cuenta en pesos con la de dólares.
 */
class Consultas_de_intencion_Test extends TestCase
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
            'name'     => 'Comercio mano derecha B',
            'email'    => 'mano-derecha-b-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio mano derecha B',
            'email'    => 'mano-derecha-b-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 EL CASO QUE ORIGINÓ LA MISIÓN. Un cliente con una venta vieja impaga y veintidós pagos
     * posteriores: por la ventana de 20 movimientos ordenados del más nuevo al más viejo, la venta
     * vieja quedaba afuera y el asistente contestaba que no había ninguna.
     *
     * El test fija las dos mitades: que la consulta vieja NO la ve (y por eso hacía falta esto), y
     * que la nueva sí.
     *
     * @group chat-ia
     * @test
     */
    public function la_venta_mas_vieja_impaga_aparece_aunque_haya_veintidos_pagos_posteriores()
    {
        $cliente = Client::create([
            'name'    => 'Tucumana B1',
            'user_id' => $this->comercio->id,
        ]);

        // La venta que el comerciante está buscando: vieja y sin cobrar.
        $vieja = $this->venta_impaga($cliente, 1000, 400, 'Venta B1 vieja');

        // Una segunda venta impaga, reciente.
        $reciente = $this->venta_impaga($cliente, 500, 3, 'Venta B1 reciente');

        // Una venta ya cobrada: no puede aparecer.
        $cobrada = Sale::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'total'      => 700,
            'created_at' => now()->subDays(200),
        ]);
        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'sale_id'    => $cobrada->id,
            'detalle'    => 'Venta B1 cobrada',
            'debe'       => 700,
            'saldo'      => 0,
            'status'     => 'pagado',
            'created_at' => now()->subDays(200),
        ]);

        // Veintidós pagos recientes: son los que llenaban la ventana de 20 de la consulta vieja.
        for ($i = 1; $i <= 22; $i++) {
            CurrentAcount::create([
                'user_id'    => $this->comercio->id,
                'client_id'  => $cliente->id,
                'detalle'    => 'Pago B1 numero ' . $i,
                'haber'      => 10,
                'saldo'      => 1500 - ($i * 10),
                'status'     => 'pago_from_client',
                'created_at' => now()->subDays(22 - $i),
            ]);
        }

        // Una venta impaga de OTRO dueño con el mismo client_id: el cruce que el filtro atrapa.
        $ajena = Sale::create([
            'user_id'    => $this->otro_comercio->id,
            'client_id'  => $cliente->id,
            'total'      => 9999,
            'created_at' => now()->subDays(500),
        ]);
        CurrentAcount::create([
            'user_id'    => $this->otro_comercio->id,
            'client_id'  => $cliente->id,
            'sale_id'    => $ajena->id,
            'detalle'    => 'Venta B1 ajena',
            'debe'       => 9999,
            'saldo'      => 9999,
            'status'     => 'sin_pagar',
            'created_at' => now()->subDays(500),
        ]);

        /*
         * LA MITAD QUE PRUEBA EL DEFECTO: la consulta vieja trae los 20 movimientos más nuevos, y
         * la venta vieja no está entre ellos. Sin esto, el test de abajo no prueba que algo cambió.
         */
        $viejos = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente($this->comercio->id, $cliente->id);

        $this->assertCount(20, $viejos, 'La consulta vieja siempre devolvió los 20 más nuevos.');

        $detalles_viejos = array_column($viejos, 'detalle');

        $this->assertNotContains(
            'Venta B1 vieja',
            $detalles_viejos,
            'Si la venta vieja entrara en la ventana de 20, este caso no sería el que originó la misión.'
        );

        // LA MITAD QUE PRUEBA EL ARREGLO.
        $resultado = ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->comercio->id, $cliente->id);

        $this->assertEquals('Tucumana B1', $resultado['cliente']);
        $this->assertEquals(2, $resultado['ventas_impagas_encontradas'], 'La cobrada y la del otro dueño no cuentan.');
        $this->assertEquals(2, $resultado['ventas_en_esta_lista']);

        // El orden por defecto es de la más vieja a la más nueva: la primera fila es la respuesta.
        $this->assertEquals('mas_viejas', $resultado['orden']);
        $this->assertEquals((int) $vieja->id, $resultado['ventas'][0]['venta_id']);
        $this->assertEquals((int) $reciente->id, $resultado['ventas'][1]['venta_id']);

        $primera = $resultado['ventas'][0];

        $this->assertEquals(1000.0, $primera['total']);
        $this->assertEquals(1000.0, $primera['debe']);
        $this->assertEquals(1000.0, $primera['pendiente']);
        $this->assertEquals('sin_pagar', $primera['estado']);
        $this->assertEquals(400, $primera['dias_sin_cobrar'], 'El "desde cuándo" viaja calculado.');
        $this->assertNotNull($primera['fecha']);

        // 🔴 Y viaja aparte, calculada contra la query entera: contesta "la más vieja" aunque el
        // modelo haya pedido el orden inverso o un límite chico.
        $this->assertEquals((int) $vieja->id, $resultado['venta_impaga_mas_vieja']['venta_id']);

        $al_reves = ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente(
            $this->comercio->id,
            $cliente->id,
            'mas_nuevas',
            1
        );

        $this->assertEquals((int) $reciente->id, $al_reves['ventas'][0]['venta_id'], 'Pidiendo mas_nuevas, la lista arranca por la nueva.');
        $this->assertEquals(1, $al_reves['ventas_en_esta_lista']);
        $this->assertEquals(2, $al_reves['ventas_impagas_encontradas'], 'El total no lo recorta el límite.');
        $this->assertEquals((int) $vieja->id, $al_reves['venta_impaga_mas_vieja']['venta_id'], 'La más vieja sigue estando.');
    }

    /**
     * 🔴 UN CLIENTE QUE NO ES DEL DUEÑO NO PUEDE CONTESTAR COMO "NO DEBE NADA".
     *
     * Vacío se lee como "no hay nada que informar", y "Fulano no te debe nada" es una afirmación
     * sobre la plata del comerciante. Dicha porque no encontramos a Fulano, es una respuesta falsa
     * dicha con total seguridad — que es el peor modo de falla que tiene un asistente.
     *
     * @group chat-ia
     * @test
     */
    public function un_cliente_ajeno_contesta_que_no_lo_encontro_y_no_que_no_debe_nada()
    {
        $ajeno = Client::create([
            'name'    => 'Cliente B1 ajeno',
            'user_id' => $this->otro_comercio->id,
        ]);

        foreach ([
            ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->comercio->id, $ajeno->id),
            ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $ajeno->id),
        ] as $resultado) {
            $this->assertArrayHasKey('error', $resultado);
            $this->assertArrayHasKey('como_sigo', $resultado);

            // Y NO trae las claves de una respuesta normal: es lo que impide leerlo como un cero.
            $this->assertArrayNotHasKey('ventas', $resultado);
            $this->assertArrayNotHasKey('movimientos', $resultado);
            $this->assertArrayNotHasKey('saldo_en_cuenta_corriente_en_pesos', $resultado);
        }
    }

    /**
     * 🔴 LAS VENTAS IMPAGAS DICEN EN QUÉ MONEDA ESTÁ CADA UNA, Y EL TOTAL NO LAS MEZCLA.
     *
     * En un comercio con la extensión `ventas_en_dolares`, una venta impaga en USD llegaba como un
     * número pelado — y el prompt le dice al asistente que los importes son en pesos salvo aviso,
     * así que la informaba en pesos. Peor: `saldo_en_cuenta_corriente_en_pesos` SÍ excluye esa
     * cuenta, con lo cual los dos números del mismo JSON no cerraban y no había con qué explicarlo.
     *
     * Es el mismo criterio que esta clase ya aplicaba en compras_a_un_proveedor y en
     * compras_de_un_articulo: esta consulta había quedado afuera de su propia regla.
     *
     * @group chat-ia
     * @test
     */
    public function las_ventas_impagas_declaran_su_moneda_y_el_total_no_suma_dolares_con_pesos()
    {
        $cliente = Client::create(['name' => 'Cliente B1 bimoneda', 'user_id' => $this->comercio->id]);

        // La vieja y en DÓLARES: es la que el asistente informaba como pesos.
        $en_dolares = $this->venta_impaga($cliente, 1200, 300, 'Venta B1 en dolares', 2);

        // Y una en pesos, para que el total tenga algo legítimo que sumar.
        $en_pesos = $this->venta_impaga($cliente, 500, 10, 'Venta B1 en pesos', 1);

        // Una tercera sin moneda cargada: se lee como pesos, igual que en todo el resto del sistema.
        $sin_moneda = $this->venta_impaga($cliente, 300, 5, 'Venta B1 sin moneda', null);

        $resultado = ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->comercio->id, $cliente->id);

        $this->assertEquals(3, $resultado['ventas_impagas_encontradas']);

        $por_venta = [];
        foreach ($resultado['ventas'] as $fila) {
            $por_venta[$fila['venta_id']] = $fila;
        }

        $this->assertFalse($por_venta[$en_dolares->id]['en_pesos'], 'La venta en dólares tiene que declararlo.');
        $this->assertEquals(2, $por_venta[$en_dolares->id]['moneda_id']);

        $this->assertTrue($por_venta[$en_pesos->id]['en_pesos']);
        $this->assertTrue($por_venta[$sin_moneda->id]['en_pesos'], 'Sin moneda cargada se lee como pesos, igual que en el resto.');

        // 🔴 El total NO suma los 1.200 dólares con los 800 pesos.
        $this->assertEquals(
            800.0,
            $resultado['total_pendiente_en_pesos_en_esta_lista'],
            'Solo las dos en pesos: 500 + 300. Un total que mezcla monedas es un número falso que nadie puede detectar mirándolo.'
        );

        $this->assertEquals(1, $resultado['ventas_en_otra_moneda_en_esta_lista']);

        // La más vieja de todas es la de dólares, y también lo declara.
        $this->assertEquals((int) $en_dolares->id, $resultado['venta_impaga_mas_vieja']['venta_id']);
        $this->assertFalse($resultado['venta_impaga_mas_vieja']['en_pesos']);
    }

    /**
     * Sin movimientos, la respuesta dice "página 1 de 1" y no "página 1 de 0", que no se puede leer.
     *
     * @group chat-ia
     * @test
     */
    public function sin_movimientos_la_paginacion_informa_una_sola_pagina()
    {
        $cliente = Client::create(['name' => 'Cliente B2 sin movimientos', 'user_id' => $this->comercio->id]);

        $resultado = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id);

        $this->assertEquals(0, $resultado['movimientos_encontrados']);
        $this->assertEquals(1, $resultado['pagina']);
        $this->assertEquals(1, $resultado['paginas']);
    }

    /**
     * Un cliente del dueño SIN ventas impagas contesta con la respuesta completa en cero, no con
     * vacío: "no te debe nada" y "ese cliente no es tuyo" no se pueden leer igual.
     *
     * @group chat-ia
     * @test
     */
    public function un_cliente_al_dia_contesta_en_cero_y_no_vacio()
    {
        $cliente = Client::create(['name' => 'Cliente B1 al dia', 'user_id' => $this->comercio->id]);

        $resultado = ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->comercio->id, $cliente->id);

        $this->assertEquals('Cliente B1 al dia', $resultado['cliente']);
        $this->assertEquals(0, $resultado['ventas_impagas_encontradas']);
        $this->assertEquals(0, $resultado['ventas_en_esta_lista']);
        $this->assertEquals([], $resultado['ventas']);
        $this->assertNull($resultado['venta_impaga_mas_vieja'], 'Sin ventas impagas no hay una más vieja, y eso es null y no una fila inventada.');
        $this->assertEquals(0, $resultado['total_pendiente_en_pesos_en_esta_lista']);
    }

    /**
     * 🔴 Caso 2 de las capturas: "¿qué cliente me compró más la lámpara?". Corre sobre el ERP
     * (article_purchases), no sobre el tracking de la tienda.
     *
     * @group chat-ia
     * @test
     */
    public function quien_compro_un_articulo_agrupa_por_cliente_y_no_cuenta_dos_veces_la_consolidacion()
    {
        $articulo = Article::create([
            'name'    => 'Lampara B1',
            'user_id' => $this->comercio->id,
        ]);

        $grande = Client::create(['name' => 'Cliente B1 grande', 'user_id' => $this->comercio->id]);
        $chico  = Client::create(['name' => 'Cliente B1 chico', 'user_id' => $this->comercio->id]);

        // El grande: dos ventas, 6 + 4 = 10 unidades.
        $this->venta_con_articulo($articulo, $grande, 6, 100, 5);
        $this->venta_con_articulo($articulo, $grande, 4, 100, 1);

        // El chico: una venta de 2 unidades.
        $this->venta_con_articulo($articulo, $chico, 2, 100, 10);

        // Mostrador: venta sin cliente. No es una fila, pero se cuenta.
        $this->venta_con_articulo($articulo, null, 3, 100, 2);

        /*
         * 🔴 LA VENTA CONTENEDORA DE CONSOLIDACIÓN AFIP. Agrupa ventas que ya están contadas: sin
         * Sale::scopeSoloVentasReales() sus unidades se suman de nuevo y el artículo aparece
         * vendido el doble.
         */
        $this->venta_con_articulo($articulo, $grande, 10, 100, 1, ['is_consolidacion_facturacion' => 1]);

        // Venta borrada: sus unidades no suman.
        $borrada = $this->venta_con_articulo($articulo, $chico, 50, 100, 3);
        $borrada->delete();

        // Venta de otro dueño sobre el mismo artículo: jamás.
        $this->venta_con_articulo($articulo, $grande, 77, 100, 2, ['user_id' => $this->otro_comercio->id]);

        $resultado = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Lampara B1');

        $this->assertEquals('Lampara B1', $resultado['articulo']);
        $this->assertEquals(2, $resultado['clientes_encontrados']);
        $this->assertEquals(2, $resultado['clientes_en_esta_lista']);

        $this->assertEquals('Cliente B1 grande', $resultado['clientes'][0]['cliente'], 'Primero el que más unidades compró.');
        $this->assertEquals(10.0, $resultado['clientes'][0]['unidades'], 'La consolidacion NO puede sumar otras 10.');
        $this->assertEquals(2, $resultado['clientes'][0]['ventas']);
        $this->assertEquals(1000.0, $resultado['clientes'][0]['monto_en_pesos']);
        $this->assertNotNull($resultado['clientes'][0]['ultima_compra']);

        $this->assertEquals('Cliente B1 chico', $resultado['clientes'][1]['cliente']);
        $this->assertEquals(2.0, $resultado['clientes'][1]['unidades'], 'La venta borrada no suma.');

        // 15 = 10 del grande + 2 del chico + 3 de mostrador. La consolidacion y la borrada afuera.
        $this->assertEquals(15.0, $resultado['unidades_vendidas_en_total']);
        $this->assertEquals(3.0, $resultado['unidades_sin_cliente'], 'El mostrador se cuenta aunque no sea una fila.');
    }

    /**
     * La ventana de días recorta, y el total dice cuántos hay adentro de esa ventana.
     *
     * @group chat-ia
     * @test
     */
    public function quien_compro_un_articulo_respeta_la_ventana_de_dias()
    {
        $articulo = Article::create(['name' => 'Lampara B1 ventana', 'user_id' => $this->comercio->id]);
        $cliente  = Client::create(['name' => 'Cliente B1 ventana', 'user_id' => $this->comercio->id]);

        $this->venta_con_articulo($articulo, $cliente, 5, 10, 3);
        $this->venta_con_articulo($articulo, $cliente, 9, 10, 40);

        $todo = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Lampara B1 ventana');

        $this->assertEquals(14.0, $todo['unidades_vendidas_en_total']);
        $this->assertNull($todo['ventana_dias'], 'Sin ventana pedida, la respuesta lo dice con null.');

        $mes = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Lampara B1 ventana', 30);

        $this->assertEquals(30, $mes['ventana_dias']);
        $this->assertEquals(5.0, $mes['unidades_vendidas_en_total'], 'La venta de hace 40 días queda afuera.');
    }

    /**
     * 🔴 LAS UNIDADES SIN PRECIO CARGADO NO SE SUMAN COMO CERO PESOS, Y SE DICEN.
     *
     * `ArticlePurchaseHelper::set_costo_y_price()` llena `price` SOLO con `moneda_id == 1`, y
     * `price_dolar` SOLO con `== 2`: con null o 0 no llena NINGUNO de los dos. Y `sales.moneda_id`
     * es nullable sin default desde la migración del 29/8/2025, que no hizo backfill — o sea que
     * TODA venta anterior a esa fecha tiene `price` null.
     *
     * Con el `COALESCE(price, 0)` que tenía la consulta, esas unidades entraban al total como CERO
     * pesos: el comerciante leía "Fulano te compró 15 unidades por $1.000" cuando fueron muchas
     * más. Plausible, bajo e indetectable — y la pregunta apunta justo ahí, porque la ventana por
     * defecto es toda la historia.
     *
     * 🔴 El fixture de este test pasa por ArticlePurchaseHelper a propósito: es el único que
     * produce la combinación real (venta sin moneda → `price` null). Armando la fila a mano el
     * defecto no aparece, que es exactamente lo que pasaba antes.
     *
     * @group chat-ia
     * @test
     */
    public function las_unidades_sin_precio_cargado_no_se_suman_como_cero_pesos()
    {
        $articulo = Article::create(['name' => 'Lampara B1 sin precio', 'user_id' => $this->comercio->id]);
        $cliente  = Client::create(['name' => 'Cliente B1 sin precio', 'user_id' => $this->comercio->id]);

        // Venta vieja, de antes de que existiera la columna: moneda_id null.
        $vieja = $this->venta_con_articulo($articulo, $cliente, 10, 1000, 400, ['moneda_id' => null]);

        // Venta en dólares: llena price_dolar y deja price en null.
        $dolares = $this->venta_con_articulo($articulo, $cliente, 5, 80, 200, ['moneda_id' => 2]);

        // La única cuyo monto en pesos se conoce de verdad.
        $pesos = $this->venta_con_articulo($articulo, $cliente, 2, 500, 10);

        /*
         * 🔴 PRIMERO SE VERIFICA EL FIXTURE. Si estas tres aserciones no se cumplen, el test de
         * abajo no está probando lo que dice: la combinación tiene que venir del sistema.
         */
        $this->assertNull(
            ArticlePurchase::where('sale_id', $vieja->id)->first()->price,
            'Una venta sin moneda deja price en null: es lo que produce el defecto.'
        );
        $this->assertNull(
            ArticlePurchase::where('sale_id', $dolares->id)->first()->price,
            'Una venta en dólares llena price_dolar, no price.'
        );
        $this->assertNotNull(
            ArticlePurchase::where('sale_id', $pesos->id)->first()->price,
            'Una venta en pesos SÍ llena price.'
        );

        $resultado = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Lampara B1 sin precio');

        // Las unidades son exactas: eso no se toca.
        $this->assertEquals(17.0, $resultado['unidades_vendidas_en_total']);

        $fila = $resultado['clientes'][0];

        $this->assertEquals(17.0, $fila['unidades']);
        $this->assertEquals(
            1000.0,
            $fila['monto_en_pesos'],
            'Solo la venta en pesos: 2 x 500. Las otras 15 unidades no tienen precio y no se inventan.'
        );

        /*
         * Y ESTO es lo que convierte un total incompleto en un total que se puede leer: 15 unidades
         * quedaron afuera del monto. Sin este número, $1.000 sobre 17 unidades se lee como si el
         * artículo se vendiera a $59.
         */
        $this->assertEquals(15.0, $fila['unidades_sin_precio_en_pesos']);
        $this->assertEquals(15.0, $resultado['unidades_sin_precio_en_pesos']);

        /*
         * 🔴 La bandera vieja NO puede seguir existiendo. Se calculaba con `moneda_id = 2`, así que
         * contaba 5 y dejaba las 10 de la venta sin moneda afuera: prometía explicar el hueco del
         * monto y explicaba un tercio. Una bandera que miente es peor que ninguna.
         */
        $this->assertArrayNotHasKey('unidades_de_ventas_en_dolares', $resultado);
        $this->assertArrayNotHasKey('monto', $fila);
    }

    /**
     * 🔴 Caso 3 de las capturas: "¿cuándo fue la última compra que cargué de la lámpara y a quién
     * se la compré?". Ninguna tool tocaba provider_orders.
     *
     * @group chat-ia
     * @test
     */
    public function compras_de_un_articulo_trae_la_ultima_primero_con_proveedor_costo_y_comprobante()
    {
        $articulo = Article::create(['name' => 'Lampara B1 compras', 'user_id' => $this->comercio->id]);

        $acme  = Provider::create(['name' => 'Proveedor B1 Acme', 'user_id' => $this->comercio->id]);
        $otro  = Provider::create(['name' => 'Proveedor B1 Zeta', 'user_id' => $this->comercio->id]);

        $recibido = ProviderOrderStatus::create(['name' => 'Recibido']);

        $vieja = $this->compra($acme, 60, ['numero_comprobante' => 'A-0001', 'provider_order_status_id' => $recibido->id]);
        $this->renglon($vieja, $articulo, 10, 25.5, 10);

        $ultima = $this->compra($otro, 2, ['numero_comprobante' => 'B-0002', 'provider_order_status_id' => $recibido->id]);
        $this->renglon($ultima, $articulo, 4, 31.0, 4);

        // Compra de otro dueño del mismo artículo: jamás.
        $ajena = ProviderOrder::create([
            'user_id'     => $this->otro_comercio->id,
            'provider_id' => $acme->id,
            'created_at'  => now()->subDays(1),
        ]);
        $this->renglon($ajena, $articulo, 99, 1, 99);

        $resultado = ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, 'Lampara B1 compras');

        $this->assertEquals('Lampara B1 compras', $resultado['articulo']);
        $this->assertEquals(2, $resultado['compras_encontradas'], 'La compra del otro dueño no cuenta.');
        $this->assertEquals(2, $resultado['compras_en_esta_lista']);
        $this->assertEquals(2, $resultado['proveedores_distintos']);
        $this->assertEquals(14.0, $resultado['unidades_pedidas_en_total']);
        $this->assertEquals('mas_nuevas_primero', $resultado['orden']);

        // La primera fila es la última compra: es la pregunta.
        $primera = $resultado['compras'][0];

        $this->assertEquals((int) $ultima->id, $primera['compra_id']);
        $this->assertEquals('Proveedor B1 Zeta', $primera['proveedor']);
        $this->assertEquals('B-0002', $primera['comprobante']);
        $this->assertEquals('Recibido', $primera['estado']);
        $this->assertEquals(4.0, $primera['cantidad_pedida']);
        $this->assertEquals(31.0, $primera['costo_unitario']);
        $this->assertFalse($primera['costo_en_dolares'], 'La bandera viaja siempre: un costo en dólares leído como pesos es una respuesta falsa.');

        $this->assertEquals((int) $vieja->id, $resultado['compras'][1]['compra_id']);
        $this->assertEquals('Proveedor B1 Acme', $resultado['compras'][1]['proveedor']);
    }

    /**
     * Qué le compré a un proveedor: totales, estado y renglones. Y el total en pesos NO suma las
     * compras en otra moneda — las cuenta aparte.
     *
     * @group chat-ia
     * @test
     */
    public function compras_a_un_proveedor_suma_solo_los_pesos_y_cuenta_aparte_la_otra_moneda()
    {
        $proveedor = Provider::create(['name' => 'Proveedor B1 Mayorista', 'user_id' => $this->comercio->id]);

        $en_proceso = ProviderOrderStatus::create(['name' => 'En proceso']);

        $tornillo = Article::create(['name' => 'Tornillo B1', 'user_id' => $this->comercio->id]);
        $tuerca   = Article::create(['name' => 'Tuerca B1', 'user_id' => $this->comercio->id]);

        // moneda_id 1 y 0 son las DOS pesos (RecolectorBase::MONEDAS_PESOS).
        $uno = $this->compra($proveedor, 5, ['total' => 1000, 'moneda_id' => 1, 'provider_order_status_id' => $en_proceso->id]);
        $this->renglon($uno, $tornillo, 10, 50, 10);
        $this->renglon($uno, $tuerca, 5, 100, 5);

        $dos = $this->compra($proveedor, 2, ['total' => 500, 'moneda_id' => 0]);
        $this->renglon($dos, $tornillo, 3, 50, 3);

        // En dólares: no puede sumarse al total en pesos.
        $tres = $this->compra($proveedor, 1, ['total' => 200, 'moneda_id' => 2]);
        $this->renglon($tres, $tornillo, 1, 10, 1);

        // De otro proveedor: no entra.
        $ajeno = Provider::create(['name' => 'Proveedor B1 ajeno', 'user_id' => $this->comercio->id]);
        $this->compra($ajeno, 1, ['total' => 7777]);

        $resultado = ConsultasSistemaIaHelper::compras_a_un_proveedor($this->comercio->id, 'Proveedor B1 Mayorista');

        $this->assertEquals('Proveedor B1 Mayorista', $resultado['proveedor']);
        $this->assertEquals(3, $resultado['compras_encontradas']);
        $this->assertEquals(3, $resultado['compras_en_esta_lista']);
        $this->assertEquals(
            1500.0,
            $resultado['total_comprado_en_pesos'],
            'moneda_id 0 es pesos igual que el 1; los 200 dólares no suman.'
        );
        $this->assertEquals(1, $resultado['compras_en_otra_moneda']);

        // Más nuevas primero: la de hace 1 día.
        $this->assertEquals((int) $tres->id, $resultado['compras'][0]['compra_id']);
        $this->assertFalse($resultado['compras'][0]['en_pesos']);

        $la_grande = $resultado['compras'][2];

        $this->assertEquals((int) $uno->id, $la_grande['compra_id']);
        $this->assertEquals('En proceso', $la_grande['estado']);
        $this->assertEquals(1000.0, $la_grande['total']);
        $this->assertTrue($la_grande['en_pesos']);
        $this->assertEquals(2, $la_grande['articulos_distintos']);
        $this->assertEquals(15.0, $la_grande['unidades']);
    }

    /**
     * Stock repartido por sucursal, con los depósitos en cero incluidos y los dos totales a la
     * vista.
     *
     * @group chat-ia
     * @test
     */
    public function stock_por_deposito_trae_todas_las_sucursales_incluidas_las_que_estan_en_cero()
    {
        $articulo = Article::create([
            'name'    => 'Lampara B1 stock',
            'user_id' => $this->comercio->id,
            'stock'   => 30,
        ]);

        $central = Address::create(['street' => 'Deposito central B1', 'user_id' => $this->comercio->id, 'es_deposito_origen' => 1]);
        $sucursal = Address::create(['street' => 'Sucursal norte B1', 'user_id' => $this->comercio->id]);
        $vacia   = Address::create(['street' => 'Sucursal sur B1', 'user_id' => $this->comercio->id]);

        // Una sucursal de OTRO dueño: no puede aparecer.
        $ajena = Address::create(['street' => 'Deposito ajeno B1', 'user_id' => $this->otro_comercio->id]);

        $articulo->addresses()->attach($central->id, ['amount' => 18, 'stock_min' => 5]);
        $articulo->addresses()->attach($sucursal->id, ['amount' => 7]);
        $articulo->addresses()->attach($ajena->id, ['amount' => 999]);

        $resultado = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, 'Lampara B1 stock');

        $this->assertEquals('Lampara B1 stock', $resultado['articulo']);
        $this->assertTrue($resultado['trabaja_con_depositos'], 'Con dos o más sucursales hay reparto de stock.');
        $this->assertEquals(3, $resultado['depositos_encontrados'], 'La sucursal del otro dueño no cuenta.');
        $this->assertEquals(3, $resultado['depositos_en_esta_lista']);

        $this->assertEquals(30.0, $resultado['stock_total_del_articulo']);
        $this->assertEquals(25.0, $resultado['stock_sumado_por_deposito'], 'Los dos números viajan aunque no coincidan.');

        $nombres = array_column($resultado['depositos'], 'deposito');

        $this->assertEquals(['Deposito central B1', 'Sucursal norte B1', 'Sucursal sur B1'], $nombres);
        $this->assertNotContains('Deposito ajeno B1', $nombres);

        $this->assertEquals(18.0, $resultado['depositos'][0]['stock']);
        $this->assertEquals(5.0, $resultado['depositos'][0]['stock_min']);
        $this->assertTrue($resultado['depositos'][0]['es_deposito_origen']);

        $this->assertEquals(7.0, $resultado['depositos'][1]['stock']);

        // 🔴 El depósito sin stock es una fila, no un hueco: es justo lo que el comerciante busca.
        $this->assertEquals(0.0, $resultado['depositos'][2]['stock']);
        $this->assertFalse($resultado['depositos'][2]['es_deposito_origen']);
    }

    /**
     * Con una sola sucursal la respuesta viaja igual y lo declara: "no hay reparto" no es "no hay
     * stock".
     *
     * @group chat-ia
     * @test
     */
    public function stock_por_deposito_con_una_sola_sucursal_lo_declara_en_vez_de_callarse()
    {
        $articulo = Article::create([
            'name'    => 'Lampara B1 una sucursal',
            'user_id' => $this->comercio->id,
            'stock'   => 12,
        ]);

        $unica = Address::create(['street' => 'Local unico B1', 'user_id' => $this->comercio->id]);
        $articulo->addresses()->attach($unica->id, ['amount' => 12]);

        $resultado = ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, 'Lampara B1 una sucursal');

        $this->assertFalse($resultado['trabaja_con_depositos']);
        $this->assertEquals(1, $resultado['depositos_encontrados']);
        $this->assertEquals(12.0, $resultado['stock_total_del_articulo']);
        $this->assertEquals(12.0, $resultado['stock_sumado_por_deposito']);
    }

    /**
     * Una busqueda que no matchea ningun articulo del dueño no contesta vacio: contesta el motivo.
     * Vacio se leeria como "no lo compro nadie" o "no tiene compras", que son afirmaciones sobre el
     * negocio; y contestar sobre "el primer articulo del catalogo" seria peor todavia.
     *
     * @group chat-ia
     * @test
     */
    public function las_consultas_de_articulo_dicen_que_no_lo_encontraron_en_vez_de_contestar_vacio()
    {
        Article::create(['name' => 'Articulo B1 del otro', 'user_id' => $this->otro_comercio->id]);

        foreach ([
            ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Articulo B1 del otro'),
            ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, 'Articulo B1 del otro'),
            ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, 'Articulo B1 del otro'),
            ConsultasSistemaIaHelper::compras_a_un_proveedor($this->comercio->id, 'Proveedor que no existe B1'),
        ] as $resultado) {
            $this->assertArrayHasKey('error', $resultado);
            $this->assertArrayHasKey('como_sigo', $resultado);
            $this->assertArrayNotHasKey('clientes', $resultado);
            $this->assertArrayNotHasKey('compras', $resultado);
            $this->assertArrayNotHasKey('depositos', $resultado);
        }

        // Y con la busqueda vacia tampoco se elige uno al azar: se dice que falta el dato.
        Article::create(['name' => 'Articulo B1 propio', 'user_id' => $this->comercio->id]);

        $sin_busqueda = ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, '   ');

        $this->assertArrayHasKey('error', $sin_busqueda);
        $this->assertArrayNotHasKey('clientes', $sin_busqueda);
    }

    /**
     * 🔴 B2: los movimientos de cuenta corriente NO mezclan la cuenta en pesos con la de dólares.
     * Sin el filtro, las filas se intercalan por fecha y la columna `saldo` —que es el acumulado
     * de SU cuenta— salta entre dos cuentas distintas.
     *
     * ⚠️ moneda_id = 0 es PESOS igual que el 1: el criterio sale de RecolectorBase::MONEDAS_PESOS.
     *
     * @group chat-ia
     * @test
     */
    public function movimientos_detalle_no_mezcla_la_cuenta_en_pesos_con_la_de_dolares()
    {
        $cliente = Client::create(['name' => 'Cliente B2 bimoneda', 'user_id' => $this->comercio->id]);

        $cuenta_pesos = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'moneda_id'  => 1,
            'saldo'      => 1500,
            'user_id'    => $this->comercio->id,
        ]);

        $cuenta_dolares = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $cliente->id,
            'moneda_id'  => 2,
            'saldo'      => 80,
            'user_id'    => $this->comercio->id,
        ]);

        $this->movimiento($cliente, 'Venta B2 en pesos', ['debe' => 1000, 'saldo' => 1000, 'moneda_id' => 1, 'credit_account_id' => $cuenta_pesos->id, 'dias' => 10]);
        // moneda_id 0: también es pesos, y comparar contra 1 la dejaría afuera.
        $this->movimiento($cliente, 'Venta B2 en pesos moneda cero', ['debe' => 500, 'saldo' => 1500, 'moneda_id' => 0, 'credit_account_id' => $cuenta_pesos->id, 'dias' => 5]);
        $this->movimiento($cliente, 'Venta B2 en dolares', ['debe' => 80, 'saldo' => 80, 'moneda_id' => 2, 'credit_account_id' => $cuenta_dolares->id, 'dias' => 7]);

        $pesos = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id);

        $this->assertTrue($pesos['filtro']['solo_pesos']);
        $this->assertEquals(2, $pesos['movimientos_encontrados'], 'La fila en dólares no entra en la respuesta de pesos.');

        $detalles = array_column($pesos['movimientos'], 'detalle');

        $this->assertContains('Venta B2 en pesos', $detalles);
        $this->assertContains('Venta B2 en pesos moneda cero', $detalles, 'moneda_id 0 es pesos.');
        $this->assertNotContains('Venta B2 en dolares', $detalles);

        // Las dos cuentas del cliente viajan igual: es cómo el asistente sabe que hay una en
        // dólares sin tener que mezclarla para enterarse.
        $this->assertCount(2, $pesos['cuentas']);
        $this->assertTrue($pesos['cuentas'][0]['es_en_pesos']);
        $this->assertFalse($pesos['cuentas'][1]['es_en_pesos']);
        $this->assertEquals(80.0, $pesos['cuentas'][1]['saldo']);

        // Y apuntando a la cuenta en dólares se contesta sólo esa.
        $dolares = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle(
            $this->comercio->id,
            $cliente->id,
            'todos',
            null,
            null,
            'mas_nuevos',
            1,
            0,
            $cuenta_dolares->id
        );

        $this->assertFalse($dolares['filtro']['solo_pesos']);
        $this->assertEquals(1, $dolares['movimientos_encontrados']);
        $this->assertEquals('Venta B2 en dolares', $dolares['movimientos'][0]['detalle']);
    }

    /**
     * B2: tipo (debe / haber), ventana de fechas, orden y página.
     *
     * @group chat-ia
     * @test
     */
    public function movimientos_detalle_filtra_por_tipo_por_ventana_ordena_y_pagina()
    {
        $cliente = Client::create(['name' => 'Cliente B2 filtros', 'user_id' => $this->comercio->id]);

        // 5 ventas (debe) y 5 pagos (haber), intercalados en el tiempo.
        for ($i = 1; $i <= 5; $i++) {
            $this->movimiento($cliente, 'Venta B2 numero ' . $i, ['debe' => 100 * $i, 'saldo' => 100 * $i, 'dias' => 50 - $i]);
            $this->movimiento($cliente, 'Pago B2 numero ' . $i, ['haber' => 10 * $i, 'saldo' => 100 * $i, 'status' => 'pago_from_client', 'dias' => 20 - $i]);
        }

        $todos = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id);

        $this->assertEquals(10, $todos['movimientos_encontrados']);
        $this->assertEquals('todos', $todos['filtro']['tipo']);

        $debe = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id, 'debe');

        $this->assertEquals(5, $debe['movimientos_encontrados']);
        foreach ($debe['movimientos'] as $movimiento) {
            $this->assertGreaterThan(0, $movimiento['debe']);
        }

        $haber = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id, 'haber');

        $this->assertEquals(5, $haber['movimientos_encontrados']);
        foreach ($haber['movimientos'] as $movimiento) {
            $this->assertGreaterThan(0, $movimiento['haber']);
        }

        // Ventana: sólo los últimos 25 días -> los 5 pagos.
        $ventana = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle(
            $this->comercio->id,
            $cliente->id,
            'todos',
            now()->subDays(25)->format('Y-m-d')
        );

        $this->assertEquals(5, $ventana['movimientos_encontrados']);
        $this->assertEquals(now()->subDays(25)->format('Y-m-d'), $ventana['filtro']['desde']);

        // Orden ascendente: primero el más viejo de todos (la venta 1, de hace 49 días).
        $viejos = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle(
            $this->comercio->id,
            $cliente->id,
            'todos',
            null,
            null,
            'mas_viejos'
        );

        $this->assertEquals('mas_viejos', $viejos['filtro']['orden']);
        $this->assertEquals('Venta B2 numero 1', $viejos['movimientos'][0]['detalle']);

        // Paginado: 4 por página, 3 páginas, y la segunda no repite la primera.
        $pagina_1 = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id, 'todos', null, null, 'mas_viejos', 1, 4);
        $pagina_2 = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id, 'todos', null, null, 'mas_viejos', 2, 4);

        $this->assertEquals(3, $pagina_1['paginas']);
        $this->assertEquals(4, $pagina_1['movimientos_en_esta_lista']);
        $this->assertEquals(10, $pagina_1['movimientos_encontrados'], 'El total no lo recorta la página.');
        $this->assertEquals(2, $pagina_2['pagina']);

        $ids_1 = array_column($pagina_1['movimientos'], 'movimiento_id');
        $ids_2 = array_column($pagina_2['movimientos'], 'movimiento_id');

        $this->assertEquals([], array_intersect($ids_1, $ids_2), 'Dos páginas no pueden traer la misma fila.');
    }

    /**
     * El techo duro no depende de lo que pida el modelo.
     *
     * @group chat-ia
     * @test
     */
    public function el_limite_pedido_nunca_pasa_el_techo_duro()
    {
        $cliente = Client::create(['name' => 'Cliente B2 techo', 'user_id' => $this->comercio->id]);

        for ($i = 1; $i <= 25; $i++) {
            $this->movimiento($cliente, 'Movimiento B2 techo ' . $i, ['debe' => 1, 'saldo' => $i, 'dias' => $i]);
        }

        // Por defecto, MAX_RESULTS.
        $default = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle($this->comercio->id, $cliente->id);

        $this->assertEquals(ConsultasSistemaIaHelper::MAX_RESULTS, $default['movimientos_en_esta_lista']);
        $this->assertEquals(25, $default['movimientos_encontrados']);

        // Un límite explícito más alto se acepta hasta el techo, no más.
        $pedido = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle(
            $this->comercio->id,
            $cliente->id,
            'todos',
            null,
            null,
            'mas_nuevos',
            1,
            9999
        );

        $this->assertEquals(25, $pedido['movimientos_en_esta_lista'], 'Hay 25 y el techo duro es 100: entran todos.');
        $this->assertEquals(1, $pedido['paginas'], 'Con el techo duro de 100 los 25 entran en una página.');
        $this->assertGreaterThanOrEqual(
            ConsultasSistemaIaHelper::MAX_RESULTS,
            ConsultasSistemaIaHelper::TOPE_DURO_DE_RESULTADOS
        );
    }

    /**
     * Una venta impaga con su fila de cuenta corriente, tal como la deja el sistema.
     *
     * @param  Client  $cliente
     * @param  float   $total
     * @param  int     $dias
     * @param  string  $detalle
     * @return Sale
     */
    protected function venta_impaga($cliente, $total, $dias, $detalle, $moneda_id = 1)
    {
        $venta = Sale::create([
            'user_id'    => $cliente->user_id,
            'client_id'  => $cliente->id,
            'total'      => $total,
            'moneda_id'  => $moneda_id,
            'created_at' => now()->subDays($dias),
        ]);

        CurrentAcount::create([
            'user_id'    => $cliente->user_id,
            'client_id'  => $cliente->id,
            'sale_id'    => $venta->id,
            'detalle'    => $detalle,
            'debe'       => $total,
            'saldo'      => $total,
            'status'     => 'sin_pagar',
            'moneda_id'  => $moneda_id,
            'created_at' => now()->subDays($dias),
        ]);

        return $venta;
    }

    /**
     * Una venta con un renglón de un artículo.
     *
     * 🔴 LA FILA DE `article_purchases` LA ESCRIBE `ArticlePurchaseHelper`, NO EL TEST. Hasta el
     * 16/9/2026 este método la creaba a mano con `price` seteado y la venta SIN `moneda_id` — una
     * combinación que en producción no existe, porque es justamente `set_costo_y_price()` el que
     * decide si `price` se llena. El fixture inventado tapaba el defecto que el test creía cubrir:
     * con `moneda_id` null o 0 ese método no llena NI `price` NI `price_dolar`, y toda venta
     * anterior al 29/8/2025 tiene `moneda_id` null porque la migración que agregó la columna no
     * hizo backfill.
     *
     * Regla que dejó este caso: cuando lo que se está probando depende de CÓMO el sistema escribe
     * una fila, la fila se siembra por el camino del sistema. Un fixture a mano prueba el fixture.
     *
     * @param  Article      $articulo
     * @param  Client|null  $cliente   null = mostrador.
     * @param  float        $cantidad
     * @param  float        $precio
     * @param  int          $dias
     * @param  array        $extra     Atributos de la venta; `moneda_id` arranca en 1 (pesos).
     * @return Sale
     */
    protected function venta_con_articulo($articulo, $cliente, $cantidad, $precio, $dias, array $extra = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $articulo->user_id,
            'client_id'  => is_null($cliente) ? null : $cliente->id,
            'moneda_id'  => 1,
            'created_at' => now()->subDays($dias),
        ], $extra));

        $venta->articles()->attach($articulo->id, [
            'amount' => $cantidad,
            'price'  => $precio,
            'cost'   => 0,
        ]);

        $helper = new ArticlePurchaseHelper();
        $helper->set_article_purcase($venta->fresh());

        return $venta;
    }

    /**
     * Una compra a un proveedor.
     *
     * @param  Provider  $proveedor
     * @param  int       $dias
     * @param  array     $extra
     * @return ProviderOrder
     */
    protected function compra($proveedor, $dias, array $extra = [])
    {
        $data = [
            'user_id'     => $proveedor->user_id,
            'provider_id' => $proveedor->id,
            'created_at'  => now()->subDays($dias),
        ];

        return ProviderOrder::create(array_merge($data, $extra));
    }

    /**
     * Un renglón de una compra (pivot article_provider_order).
     *
     * @param  ProviderOrder  $compra
     * @param  Article        $articulo
     * @param  float          $cantidad
     * @param  float          $costo
     * @param  float          $recibida
     * @return void
     */
    protected function renglon($compra, $articulo, $cantidad, $costo, $recibida)
    {
        DB::table('article_provider_order')->insert([
            'provider_order_id' => $compra->id,
            'article_id'        => $articulo->id,
            'amount'            => $cantidad,
            'received'          => $recibida,
            'cost'              => $costo,
            'cost_in_dollars'   => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Un movimiento de cuenta corriente.
     *
     * @param  Client  $cliente
     * @param  string  $detalle
     * @param  array   $extra  Acepta `dias` para ubicarlo en el tiempo.
     * @return CurrentAcount
     */
    protected function movimiento($cliente, $detalle, array $extra = [])
    {
        $dias = isset($extra['dias']) ? $extra['dias'] : 1;
        unset($extra['dias']);

        $data = [
            'user_id'    => $cliente->user_id,
            'client_id'  => $cliente->id,
            'detalle'    => $detalle,
            'status'     => 'sin_pagar',
            'created_at' => now()->subDays($dias),
        ];

        return CurrentAcount::create(array_merge($data, $extra));
    }
}
