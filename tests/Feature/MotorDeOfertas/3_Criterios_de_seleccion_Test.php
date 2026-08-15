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

    /** @return Client */
    protected function cliente($nombre)
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

    /** Un evento crudo de la tienda. occurred_at, nunca created_at. */
    protected function sembrar_evento($buyer, $article, $dias_atras, $tipo = 'cart_add')
    {
        DB::table('buyer_tracking_events')->insert([
            'user_id' => $this->comercio->id, 'buyer_id' => $buyer->id, 'event_type' => $tipo,
            'visitor_id' => '11111111-1111-4111-8111-111111111111',
            'session_id' => '22222222-2222-4222-8222-222222222222', 'article_id' => $article->id,
            'occurred_at' => now()->subDays($dias_atras), 'created_at' => now(), 'updated_at' => now(),
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
     * 🔴 El filtro de ventas reales de A.5.0: una venta borrada NO cuenta, y una consolidación de
     * facturación tampoco (duplicaría las ventas que agrupa).
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
     * 🔴 El estado real de hoy: las dos tablas de tracking están en CERO FILAS. El criterio devuelve
     * [] y NO lanza, y la corrida sigue viva con los otros dos criterios.
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
     * A.5.2 — con un cart_add sembrado aparece; y desaparece si después lo compró de verdad. El
     * descarte se hace contra article_purchases, no contra los eventos.
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
    }

    /**
     * 🔴 El vínculo buyers.comercio_city_client_id es opcional y manual: un buyer sin cliente
     * asociado no genera ninguna línea, porque no hay a quién ofrecerle nada.
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
     * A.5.6 — un cliente con diez candidatos deja tres. Un cliente con cuarenta ofertas no es una
     * campaña, es spam.
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

        $this->assertCount((int) $this->suggestion->max_ofertas_por_cliente, $candidatos);
        $this->assertSame(3, count($candidatos), 'el tope por defecto de la corrida es 3');
    }

    /**
     * 🔴 LA CORRECCIÓN DEL 15/8/2026: al que debe hace mucho NO se le ofrece nada. Se excluye entero
     * —no se le baja el techo— y el conteo queda disponible para
     * offer_suggestions.total_clientes_excluidos_por_deuda.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_mal_pagador_se_excluye_entero_y_se_cuenta()
    {
        $deudor  = $this->cliente('Debe hace 60 dias');
        $al_dia  = $this->cliente('Paga al dia');
        $article = $this->articulo('Articulo de los dos clientes');

        $this->comprar($deudor, $article, 10);
        $this->comprar($al_dia, $article, 10);

        // La venta vencida sin cobrar, con el mismo criterio de SaleController::ventas_sin_cobrar().
        $vencida = $this->comprar($deudor, $article, 60);

        CurrentAcount::create(['sale_id' => $vencida->id, 'client_id' => $deudor->id,
            'user_id' => $this->comercio->id, 'debe' => 15000, 'status' => 'sin_pagar']);

        $servicio = $this->servicio();
        $clientes = array_unique(array_column($servicio->candidatos(), 'client_id'));

        $this->assertNotContains($deudor->id, $clientes, 'al mal pagador no se le ofrece nada');
        $this->assertContains($al_dia->id, $clientes);
        $this->assertSame(1, $servicio->clientes_excluidos_por_deuda());
        $this->assertFalse($servicio->evaluacion_crediticia()[$deudor->id]);
    }
}
