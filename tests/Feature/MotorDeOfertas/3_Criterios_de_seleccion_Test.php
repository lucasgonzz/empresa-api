<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\OfferSuggestion;
use App\Models\Sale;
use App\Models\User;
use App\Services\OfertasClientes\CriteriosDeOfertaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 3: a quién ofrecerle qué (§A.5).
 *
 * 🔴 Lo que tiene que dejar demostrado es que el motor SIRVE HOY con las dos tablas de tracking
 * en cero filas (afinidad y reactivación salen de article_purchases; carrito abandonado devuelve []
 * y no rompe nada), y que el filtro de A.5.0 realmente filtra: una venta borrada o una consolidación
 * de facturación no puede inventar afinidad donde no la hubo.
 */
class Criterios_de_seleccion_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var OfferSuggestion */
    protected $suggestion;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave vive en el .env.testing.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name' => 'Comercio ofertas P3', 'password' => Hash::make('secret'),
            'email' => 'motor-ofertas-p3-' . uniqid() . '@test.local',
            'dias_alertar_administradores_ventas_no_cobradas' => 30,
        ]);

        // Los defaults del plan, explícitos para que cada test se lea sin ir a la migración.
        $this->suggestion = OfferSuggestion::create([
            'user_id' => $this->comercio->id, 'status' => 'pendiente', 'origen_generacion' => 'manual',
            'dias_historial_afinidad' => 180, 'dias_inactividad_reactivacion' => 60,
            'dias_carrito_abandonado' => 7, 'max_ofertas_por_cliente' => 3, 'dias_vigencia_sugerida' => 15,
        ]);
    }

    /** @return CriteriosDeOfertaService */
    protected function servicio()
    {
        return new CriteriosDeOfertaService($this->suggestion, $this->comercio);
    }

    /**
     * 🔴 Con buyer vinculado: es el escenario REAL de un cliente que puede recibir una oferta.
     * Desde el punto A, afinidad() (y por herencia reactivacion()) exige buyer, y la query que
     * corre la tienda ya lo exigía de antes para poder mostrar la oferta a alguien. Un test que
     * arma un cliente SIN buyer y espera que el motor le genere algo está probando un escenario
     * que en producción no puede ver nadie.
     *
     * @return Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
        $this->comprador($cliente);

        return $cliente;
    }

    /** Un cliente SIN buyer vinculado, para los tests que prueban justamente ese filtro. @return Client */
    protected function cliente_sin_buyer($nombre)
    {
        return Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /** @return Article */
    protected function articulo($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /**
     * Una compra real: la venta y su línea de article_purchases, con la misma fecha.
     * $datos_venta existe para probar los bordes del filtro de A.5.0.
     *
     * @return Sale
     */
    protected function comprar($client, $article, $dias_atras, array $datos_venta = [])
    {
        $fecha = now()->subDays($dias_atras);

        $sale = Sale::create(array_merge([
            'user_id' => $this->comercio->id, 'client_id' => $client->id, 'created_at' => $fecha,
        ], $datos_venta));

        ArticlePurchase::create([
            'sale_id' => $sale->id, 'client_id' => $client->id, 'article_id' => $article->id,
            'amount' => 1, 'created_at' => $fecha,
        ]);

        return $sale;
    }

    /** Un evento crudo de la tienda. La ventana se mide por occurred_at, nunca por created_at. */
    protected function sembrar_evento($buyer, $article, $dias_atras, $tipo = 'cart_add', array $extra = [])
    {
        DB::table('buyer_tracking_events')->insert(array_merge([
            'user_id' => $this->comercio->id, 'buyer_id' => $buyer->id, 'event_type' => $tipo,
            'visitor_id' => '11111111-1111-4111-8111-111111111111',
            'session_id' => '22222222-2222-4222-8222-222222222222',
            'article_id' => is_null($article) ? null : $article->id,
            'occurred_at' => now()->subDays($dias_atras), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** Un buyer del ecommerce atado (o no) a un cliente del ERP. @return Buyer */
    protected function comprador($cliente = null)
    {
        return Buyer::create([
            'name' => 'Comprador ' . uniqid(), 'email' => 'buyer-' . uniqid() . '@test.local',
            'user_id' => $this->comercio->id,
            'comercio_city_client_id' => is_null($cliente) ? null : $cliente->id,
        ]);
    }

    /** Los pares (cliente, artículo) de una lista de candidatos, para comparar sin depender del orden. */
    protected function pares(array $candidatos)
    {
        return array_map(function ($c) { return $c['client_id'] . '-' . $c['article_id']; }, $candidatos);
    }

    /**
     * A.5.1 — el que compró aparece con su cuenta de compras; el que nunca compró, no.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_afinidad_trae_al_que_compro_y_no_al_que_nunca_compro()
    {
        $compro  = $this->cliente('Compró tres veces');
        $nunca   = $this->cliente('Nunca compró');
        $article = $this->articulo('Articulo con afinidad');

        foreach ([5, 20, 40] as $dias) {
            $this->comprar($compro, $article, $dias);
        }

        $candidatos = $this->servicio()->afinidad();
        $this->assertContains($compro->id . '-' . $article->id, $this->pares($candidatos));
        $this->assertNotContains($nunca->id . '-' . $article->id, $this->pares($candidatos));
        $this->assertSame(3.0, $candidatos[0]['score'], 'el score de afinidad es cuántas veces lo compró');
        $this->assertSame(CriteriosDeOfertaService::CRITERIO_AFINIDAD, $candidatos[0]['criterio']);
    }

    /**
     * 🔴 A.5.0: una venta borrada NO cuenta, y una consolidación de facturación tampoco.
     *
     * 🔴 EL assertSame([], $pares) DE ABAJO PRUEBA UNA SOLA COSA, NO DOS. `cliente()` ahora crea el
     * Buyer vinculado, así que el [] ya no puede explicarse por "el cliente no tiene buyer": prueba
     * únicamente que el filtro de A.5.0 (venta borrada / consolidación de facturación) descarta las
     * dos compras. Es una aserción MÁS estricta que antes, no más floja.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_venta_borrada_o_una_consolidacion_de_facturacion_no_generan_afinidad()
    {
        $cliente = $this->cliente('Cliente de ventas que no cuentan');
        $borrada = $this->articulo('Articulo de venta borrada');
        $consoli = $this->articulo('Articulo de consolidacion');

        $this->comprar($cliente, $borrada, 10)->delete();
        $this->comprar($cliente, $consoli, 10, ['is_consolidacion_facturacion' => 1]);

        $pares = $this->pares($this->servicio()->afinidad());
        $this->assertNotContains($cliente->id . '-' . $borrada->id, $pares, 'una venta borrada no es afinidad');
        $this->assertNotContains($cliente->id . '-' . $consoli->id, $pares, 'una consolidación tampoco');
        $this->assertSame([], $pares);
    }

    /**
     * A.5.3 — el dormido aparece con el artículo de su propio historial; el que compró ayer, no.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_reactivacion_trae_al_dormido_y_no_al_que_compro_ayer()
    {
        $dormido  = $this->cliente('Dormido hace 90 dias');
        $reciente = $this->cliente('Compro ayer');
        $article  = $this->articulo('Articulo de los dos');

        $this->comprar($dormido, $article, 90);
        $this->comprar($reciente, $article, 1);

        $candidatos = $this->servicio()->reactivacion();
        $pares      = $this->pares($candidatos);
        $this->assertContains($dormido->id . '-' . $article->id, $pares);
        $this->assertNotContains($reciente->id . '-' . $article->id, $pares);
        $this->assertGreaterThan(CriteriosDeOfertaService::SCORE_BASE_REACTIVACION, $candidatos[0]['score'],
            'el score de reactivación crece con los días dormido');
    }

    /**
     * 🔴 El estado real de hoy: tracking en CERO FILAS. Devuelve [], no lanza, y la corrida sigue.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_carrito_abandonado_con_las_tablas_de_tracking_vacias_devuelve_vacio()
    {
        $cliente = $this->cliente('Cliente sin tracking');
        $article = $this->articulo('Articulo sin tracking');
        $this->comprar($cliente, $article, 5);

        $this->assertSame([], $this->servicio()->carrito_abandonado());
        $this->assertNotEmpty($this->servicio()->candidatos(), 'sin tracking la corrida igual da resultados');
    }

    /**
     * A.5.2 — aparece con un cart_add y desaparece si lo compró: el descarte va contra article_purchases.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_carrito_abandonado_aparece_y_desaparece_si_despues_lo_compro()
    {
        $cliente = $this->cliente('Cliente con carrito');
        $article = $this->articulo('Articulo abandonado');
        $buyer = Buyer::create(['name' => 'Comprador', 'email' => 'buyer-' . uniqid() . '@test.local',
            'user_id' => $this->comercio->id, 'comercio_city_client_id' => $cliente->id]);
        $this->sembrar_evento($buyer, $article, 3);
        $this->assertContains($cliente->id . '-' . $article->id, $this->pares($this->servicio()->carrito_abandonado()));

        // Compra posterior al carrito: ya no hay nada que ofrecerle.
        $this->comprar($cliente, $article, 1);
        $this->assertSame([], $this->servicio()->carrito_abandonado());

        // 🔴 Y la ventana se mide por occurred_at: este evento pasó hace 30 días (fuera de los 7 de
        // la corrida) aunque la tienda lo haya mandado recién hoy. Si alguien cambia occurred_at por
        // created_at, este par vuelve a aparecer y el test se pone rojo.
        $viejo = $this->articulo('Articulo de carrito viejo');
        $this->sembrar_evento($buyer, $viejo, 30);
        $this->assertSame([], $this->servicio()->carrito_abandonado());
    }

    /**
     * 🔴 buyers.comercio_city_client_id es opcional: sin cliente asociado no hay a quién ofrecerle.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_buyer_sin_cliente_asociado_no_genera_ninguna_linea()
    {
        $article = $this->articulo('Articulo de buyer suelto');
        $buyer = Buyer::create(['name' => 'Comprador suelto', 'email' => 'buyer-s-' . uniqid() . '@test.local',
            'user_id' => $this->comercio->id, 'comercio_city_client_id' => null]);
        $this->sembrar_evento($buyer, $article, 2);
        $this->assertSame([], $this->servicio()->carrito_abandonado());
    }

    /**
     * 🔴 LA MITAD DEL PEDIDO QUE FALTABA: "qué productos vio y cuánto tiempo, qué buscó".
     *
     * El motor consumía dos de las cuatro señales que pidió Lucas (cart_add y las compras) y nadie
     * leía product_view, dwell_ms, search ni search_term. Este test siembra las dos señales nuevas y
     * comprueba que las dos llegan a ser candidatos.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_interes_en_la_tienda_trae_lo_que_miro_con_tiempo_y_lo_que_busco()
    {
        $cliente = $this->cliente('Cliente que mira la tienda');
        $mirado  = $this->articulo('Articulo mirado tres veces');
        $buscado = $this->articulo('Termostato zzterminobuscado digital');
        $buyer   = $this->comprador($cliente);

        // Tres visitas a la misma ficha, un minuto cada una: volvió y se quedó.
        foreach ([1, 2, 3] as $dias) {
            $this->sembrar_evento($buyer, $mirado, $dias, 'product_view', ['dwell_ms' => 60000]);
        }

        // 🔴 Una búsqueda NO trae article_id (el esquema no lo ata): el puente es el texto.
        $this->sembrar_evento($buyer, null, 2, 'search', ['search_term' => 'zzterminobuscado']);

        $candidatos = $this->servicio()->interes_en_el_ecommerce();
        $pares      = $this->pares($candidatos);

        $this->assertContains($cliente->id . '-' . $mirado->id, $pares,
            'lo que miró varias veces y con tiempo tiene que ser candidato');
        $this->assertContains($cliente->id . '-' . $buscado->id, $pares,
            'lo que buscó por nombre tiene que ser candidato: el match va por texto contra articles');

        foreach ($candidatos as $candidato) {
            $this->assertSame(CriteriosDeOfertaService::CRITERIO_INTERES_ECOMMERCE, $candidato['criterio']);
            // 🔴 La escala: mirar y buscar valen MENOS que una compra previa (afinidad = cantidad de
            // compras, o sea >= 1.0) y mucho menos que un carrito abandonado (3.0). Si alguien sube
            // estos scores por encima de 1, el dedupe empieza a tapar afinidad con vistas.
            $this->assertLessThan(1.0, $candidato['score'], 'una vista vale menos que una compra previa');
            $this->assertLessThan(CriteriosDeOfertaService::SCORE_CARRITO_ABANDONADO, $candidato['score']);
            $this->assertGreaterThan(0, $candidato['score']);
        }

        // El detalle lo escribe el código y tiene que contar las dos cosas que se midieron.
        $del_mirado = array_values(array_filter($candidatos, function ($c) use ($mirado) {
            return $c['article_id'] == $mirado->id;
        }));
        $this->assertStringContainsString('3 veces', $del_mirado[0]['detalle']);
        $this->assertStringContainsString('3 minutos', $del_mirado[0]['detalle']);

        // 🔴 Volver varias veces y quedarse un rato tienen que MOVER el score: si no, las dos señales
        // que Lucas pidió ("cuánto tiempo", "varias veces") se leen y se tiran.
        $tibio = $this->cliente('Cliente que paso de largo');
        $this->sembrar_evento($this->comprador($tibio), $mirado, 1, 'product_view', ['dwell_ms' => 1000]);

        $scores = [];
        foreach ($this->servicio()->interes_en_el_ecommerce() as $candidato) {
            $scores[$candidato['client_id'] . '-' . $candidato['article_id']] = $candidato['score'];
        }
        $this->assertGreaterThan(
            $scores[$tibio->id . '-' . $mirado->id],
            $scores[$cliente->id . '-' . $mirado->id],
            'tres visitas de un minuto valen más que una de un segundo'
        );

        // 🔴 Y el criterio tiene que estar ENCHUFADO al pipeline, no solo existir: sin la llamada en
        // candidatos() todo lo de arriba pasa igual y la funcionalidad no existe.
        $this->assertContains($cliente->id . '-' . $mirado->id, $this->pares($this->servicio()->candidatos()));
    }

    /**
     * 🔴 El estado real de hoy, igual que el carrito abandonado: tracking en CERO FILAS. El criterio
     * devuelve [], no lanza, y la corrida sigue dando resultados por afinidad y reactivación.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_interes_en_la_tienda_con_las_tablas_de_tracking_vacias_devuelve_vacio()
    {
        $cliente = $this->cliente('Cliente sin tracking de interes');
        $article = $this->articulo('Articulo sin tracking de interes');
        $this->comprar($cliente, $article, 5);

        $this->assertSame([], $this->servicio()->interes_en_el_ecommerce());
        $this->assertNotEmpty(
            $this->servicio()->candidatos(),
            'sin una sola fila de tracking la corrida igual tiene que dar resultados'
        );
    }

    /**
     * El mismo descarte que el carrito abandonado: si ya lo compró después de mirarlo, no hay nada
     * que ofrecerle. Y la ventana se mide por occurred_at, nunca por created_at.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_interes_desaparece_si_despues_lo_compro_y_si_la_vista_quedo_fuera_de_ventana()
    {
        $cliente = $this->cliente('Cliente que mira y compra');
        $article = $this->articulo('Articulo mirado y comprado');
        $buyer   = $this->comprador($cliente);

        $this->sembrar_evento($buyer, $article, 3, 'product_view', ['dwell_ms' => 90000]);
        $this->assertContains(
            $cliente->id . '-' . $article->id,
            $this->pares($this->servicio()->interes_en_el_ecommerce())
        );

        $this->comprar($cliente, $article, 1);
        $this->assertSame([], $this->servicio()->interes_en_el_ecommerce(),
            'lo que ya compró después de mirarlo no se ofrece de nuevo');

        // 🔴 occurred_at y no created_at: este evento pasó hace 60 días (la ventana del interés es el
        // doble de dias_carrito_abandonado, o sea 14) aunque la tienda lo haya mandado recién hoy.
        $viejo = $this->articulo('Articulo mirado hace mucho');
        $this->sembrar_evento($buyer, $viejo, 60, 'product_view', ['dwell_ms' => 90000]);
        $this->assertSame([], $this->servicio()->interes_en_el_ecommerce());
    }

    /**
     * 🔴 El término buscado entra CRUDO en un LIKE: sus comodines se escapan. Sin el addcslashes, un
     * "50%" matchea todo lo que empieza con 50 y el cliente recibe ofertas de cualquier cosa.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function los_comodines_de_un_termino_buscado_no_matchean_todo_el_catalogo()
    {
        $cliente = $this->cliente('Cliente que busca con comodines');
        $otro    = $this->articulo('zzcatalogo articulo cualquiera');
        $buyer   = $this->comprador($cliente);

        // "zz%" con el comodín sin escapar matchea 'zzcatalogo articulo cualquiera'.
        $this->sembrar_evento($buyer, null, 1, 'search', ['search_term' => 'zz%']);

        $this->assertNotContains(
            $cliente->id . '-' . $otro->id,
            $this->pares($this->servicio()->interes_en_el_ecommerce()),
            'el % del término tiene que ser un porcentaje literal, no un comodín de LIKE'
        );
    }

    /**
     * A.5.6 — diez candidatos dejan tres: cuarenta ofertas no son una campaña, son spam.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_tope_de_ofertas_por_cliente_se_respeta()
    {
        $cliente = $this->cliente('Cliente con diez articulos');

        for ($i = 0; $i < 10; $i++) {
            $this->comprar($cliente, $this->articulo('Articulo tope ' . $i), 5 + $i);
        }

        $candidatos = $this->servicio()->candidatos();
        $this->assertCount((int) $this->suggestion->max_ofertas_por_cliente, $candidatos, 'el tope de la corrida es 3');
    }

    /**
     * 🔴 LA CORRECCIÓN DEL 15/8/2026: al que debe hace mucho NO se le ofrece nada. Se excluye
     * entero —no se le baja el techo— y el conteo queda para total_clientes_excluidos_por_deuda.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mal_pagador_se_excluye_entero_y_se_cuenta()
    {
        $deudor    = $this->cliente('Debe hace 60 dias');
        $al_dia    = $this->cliente('Paga al dia');
        $pagandose = $this->cliente('Pagandose sin deuda');
        $article   = $this->articulo('Articulo de los tres clientes');

        foreach ([$deudor, $al_dia, $pagandose] as $c) {
            $this->comprar($c, $article, 10);
        }

        // La venta vencida sin cobrar, con el mismo criterio de SaleController::ventas_sin_cobrar().
        CurrentAcount::create(['sale_id' => $this->comprar($deudor, $article, 60)->id,
            'client_id' => $deudor->id, 'user_id' => $this->comercio->id,
            'debe' => 15000, 'status' => 'sin_pagar']);

        // 🔴 Y la divergencia intencional con SaleController.php:789-796, donde el orWhere sin
        // agrupar deja entrar una venta 'pagandose' SIN exigir debe > 0. Con debe 0 no hay deuda y
        // este cliente NO se excluye; con la agrupación del original el conteo daría 2 y esto se
        // pondría rojo. Es la única punta que denuncia si alguien "alinea" el criterio al original.
        CurrentAcount::create(['sale_id' => $this->comprar($pagandose, $article, 60)->id,
            'client_id' => $pagandose->id, 'user_id' => $this->comercio->id,
            'debe' => 0, 'status' => 'pagandose']);

        $servicio = $this->servicio();
        $clientes = array_unique(array_column($servicio->candidatos(), 'client_id'));

        $this->assertNotContains($deudor->id, $clientes, 'al mal pagador no se le ofrece nada');
        $this->assertContains($al_dia->id, $clientes);
        $this->assertContains($pagandose->id, $clientes, 'con debe 0 no hay deuda aunque este pagandose');
        $this->assertSame(1, $servicio->clientes_excluidos_por_deuda());
        $this->assertFalse($servicio->evaluacion_crediticia()[$deudor->id]);
    }
}
