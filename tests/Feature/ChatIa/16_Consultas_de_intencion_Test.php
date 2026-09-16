<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
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
     * Un cliente de otro dueño no devuelve "no debe nada": devuelve vacío, que es otra cosa.
     *
     * @group chat-ia
     * @test
     */
    public function ventas_impagas_de_un_cliente_ajeno_devuelve_vacio()
    {
        $ajeno = Client::create([
            'name'    => 'Cliente B1 ajeno',
            'user_id' => $this->otro_comercio->id,
        ]);

        $resultado = ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente($this->comercio->id, $ajeno->id);

        $this->assertEquals([], $resultado);
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
        $consolidacion = Sale::create([
            'user_id'                     => $this->comercio->id,
            'client_id'                   => $grande->id,
            'is_consolidacion_facturacion' => 1,
            'created_at'                  => now()->subDays(1),
        ]);
        ArticlePurchase::create([
            'sale_id'    => $consolidacion->id,
            'client_id'  => $grande->id,
            'article_id' => $articulo->id,
            'amount'     => 10,
            'price'      => 100,
            'created_at' => now()->subDays(1),
        ]);

        // Venta borrada: sus unidades no suman.
        $borrada = $this->venta_con_articulo($articulo, $chico, 50, 100, 3);
        $borrada->delete();

        // Venta de otro dueño sobre el mismo artículo: jamás.
        $venta_ajena = Sale::create(['user_id' => $this->otro_comercio->id, 'created_at' => now()->subDays(2)]);
        ArticlePurchase::create([
            'sale_id'    => $venta_ajena->id,
            'client_id'  => $grande->id,
            'article_id' => $articulo->id,
            'amount'     => 77,
            'created_at' => now()->subDays(2),
        ]);

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
     * Una búsqueda que no matchea ningún artículo del dueño devuelve vacío en las tres consultas
     * de artículo: contestar sobre "el primer artículo del catálogo" sería la peor forma de estar
     * equivocado.
     *
     * @group chat-ia
     * @test
     */
    public function las_consultas_de_articulo_devuelven_vacio_cuando_no_resuelven_el_articulo()
    {
        Article::create(['name' => 'Articulo B1 del otro', 'user_id' => $this->otro_comercio->id]);

        $this->assertEquals([], ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, 'Articulo B1 del otro'));
        $this->assertEquals([], ConsultasSistemaIaHelper::compras_de_un_articulo($this->comercio->id, 'Articulo B1 del otro'));
        $this->assertEquals([], ConsultasSistemaIaHelper::stock_por_deposito($this->comercio->id, 'Articulo B1 del otro'));
        $this->assertEquals([], ConsultasSistemaIaHelper::compras_a_un_proveedor($this->comercio->id, 'Proveedor que no existe B1'));

        // Y con la búsqueda vacía tampoco se elige uno al azar.
        Article::create(['name' => 'Articulo B1 propio', 'user_id' => $this->comercio->id]);

        $this->assertEquals([], ConsultasSistemaIaHelper::quien_compro_un_articulo($this->comercio->id, '   '));
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
    protected function venta_impaga($cliente, $total, $dias, $detalle)
    {
        $venta = Sale::create([
            'user_id'    => $cliente->user_id,
            'client_id'  => $cliente->id,
            'total'      => $total,
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
            'created_at' => now()->subDays($dias),
        ]);

        return $venta;
    }

    /**
     * Una venta con un renglón de un artículo, como la deja ArticlePurchaseHelper.
     *
     * @param  Article      $articulo
     * @param  Client|null  $cliente   null = mostrador.
     * @param  float        $cantidad
     * @param  float        $precio
     * @param  int          $dias
     * @return Sale
     */
    protected function venta_con_articulo($articulo, $cliente, $cantidad, $precio, $dias)
    {
        $venta = Sale::create([
            'user_id'    => $articulo->user_id,
            'client_id'  => is_null($cliente) ? null : $cliente->id,
            'created_at' => now()->subDays($dias),
        ]);

        ArticlePurchase::create([
            'sale_id'    => $venta->id,
            'client_id'  => is_null($cliente) ? null : $cliente->id,
            'article_id' => $articulo->id,
            'amount'     => $cantidad,
            'price'      => $precio,
            'created_at' => now()->subDays($dias),
        ]);

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
