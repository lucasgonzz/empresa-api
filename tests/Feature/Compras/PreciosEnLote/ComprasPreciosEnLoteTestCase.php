<?php

namespace Tests\Feature\Compras\PreciosEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Http\Controllers\Helpers\providerOrder\NewProviderOrderHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\ProviderOrderDiscount;
use App\Models\ProviderOrderExtraCost;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compras\ComprasTestCase;
use Tests\Feature\Precios\RecalculoEnLote\FotoDePrecios;

/**
 * Base de los tests de equivalencia de la misión compras-precios-en-lote (29/9/2026).
 *
 * QUÉ SE PRUEBA. Hasta esta misión, una compra con "actualizar precios" llamaba hasta cuatro veces
 * a ArticleHelper::setFinalPrice() por renglón (update_cost, update_price, la materialización de
 * los descuentos del proveedor y los costos extra). Ahora NewProviderOrderHelper anota los
 * artículos y los recalcula UNA vez, al final de procesar_pedido(), con el motor en lote
 * (RecalculoDePreciosEnLote). El interruptor NewProviderOrderHelper::recalcular_por_articulo(true)
 * vuelve al camino de antes tal cual, y es la REFERENCIA contra la que se mide el nuevo.
 *
 * CÓMO. dos_caminos() confirma la MISMA compra dos veces sobre la MISMA base, por el endpoint real
 * (POST o PUT api/provider-order), cada vez adentro de un savepoint (DB::beginTransaction()
 * anidado en la transacción de DatabaseTransactions) que se revierte al terminar: la primera con el
 * interruptor prendido ("hoy") y la segunda apagado ("motor"). Después de cada corrida saca una foto
 * de todo lo que la compra escribe. El reloj queda congelado en AHORA durante las dos corridas, así
 * que created_at, updated_at y final_price_updated_at se comparan por valor.
 *
 * QUÉ SE COMPARA. assert_mismo_resultado() exige que las dos fotos sean idénticas, columna por
 * columna, SALVO lo que cambian a propósito las decisiones de Lucas del plan (§3):
 *
 *  - D1, un solo cambio de precio por artículo y por compra (antes → después):
 *      · price_changes: el motor tiene a lo sumo UNO por artículo. Si el precio final cambió en la
 *        compra, es igual en TODO (costo, precio, precio final, employee_id, fechas y listas) al
 *        ÚLTIMO de hoy; si quedó igual al de antes, ninguno.
 *      · articles.previus_final_price: si el precio cambió, el de ANTES de la compra.
 *      · article_price_type.previus_final_price (cuentas con listas): el final_price que la lista
 *        tenía ANTES de la compra (ver previus_de_lista_esperado()).
 *  - D2, "Precio Final" del historial de proveedores: article_provider.price queda con el precio
 *    final del artículo en los pares que el movimiento de stock de la compra escribió.
 *
 * Todo lo demás —articles entero, pivots de listas, monedas, descuentos tagueados, recargos, stock
 * por depósito, historial de ofertas, renglones de la compra, movimientos de stock, cuenta
 * corriente, facturas y la fila de la compra— tiene que quedar IDÉNTICO. Si un escenario muestra
 * una diferencia que esas reglas no explican, el test queda rojo: 🔴 PROHIBIDO ajustar una
 * aserción o una regla de comparación para que pase.
 *
 * Una compra que no difiere nada (sin proveedor, o con update_prices apagado) tiene que quedar
 * idéntica en todo, sin reglas: ahí el camino nuevo es el de antes (ver usa_el_recalculo_diferido()).
 *
 * Las fotos NUNCA se indexan por ids generados: los autoincrementales no se reusan después de un
 * rollback, así que la segunda corrida crea la compra, sus movimientos y sus price_changes con ids
 * distintos. Se sacan (o se reemplazan por una marca) y las filas se ordenan por su contenido.
 *
 * Extiende ComprasTestCase: DatabaseTransactions, el dueño del fixture (TestingFerreteriaSeeder)
 * autenticado, y los helpers de la compra (payload_compra, item, set_condicion_iva,
 * quitar_bonificaciones_de_buenos_aires).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class ComprasPreciosEnLoteTestCase extends ComprasTestCase
{
    use FotoDePrecios;

    /** Instante congelado de las corridas: posterior a cualquier fila creada por el fixture o el test. */
    const AHORA = '2030-01-15 10:00:00';

    /** Marca que reemplaza el id de la compra en las fotos (cambia de una corrida a la otra). */
    const LA_COMPRA = '(la compra)';

    /** Marca que reemplaza un id de una fila creada adentro de la corrida (idem). */
    const CREADO_EN_LA_CORRIDA = '(creado en la corrida)';

    /**
     * Listas de consultas que DB::listen() está anotando, o null si no se está escuchando. Ver
     * consultas_de_la_compra().
     *
     * @var array|null
     */
    protected $consultas_anotadas = null;

    /**
     * Si ya se registró el listener de consultas en este test (DB::listen() no se puede sacar, y
     * registrarlo dos veces contaría todo doble).
     *
     * @var bool
     */
    protected $escucha_registrada = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limpiar_estado_del_proceso();
    }

    protected function tearDown(): void
    {
        $this->limpiar_estado_del_proceso();

        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Lo que vive en estáticas del proceso y no en la base: la transacción del test no lo revierte
     * y se lo llevaría puesto la corrida o el test siguiente. El interruptor de la misión vuelve
     * siempre a su default (false: el recálculo diferido con el motor).
     *
     * @return void
     */
    protected function limpiar_estado_del_proceso()
    {
        NewProviderOrderHelper::recalcular_por_articulo(false);

        PreciosEnLote::descartar();
        PreciosEnLote::deshabilitar(false);

        ArticlePricesHelper::$sale_taxes_cache = [];
        ArticlePricesHelper::$payment_method_layer3_cache = [];
    }

    /* ------------------------------------------------------------------------------------------
     * Armado de datos
     * ---------------------------------------------------------------------------------------- */

    /**
     * El dueño del fixture, releído de la base (los tests le cambian flags con UPDATE directo).
     *
     * @return \App\Models\User
     */
    protected function dueno()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Id de un IVA por su porcentaje, tal como está guardado en ivas.percentage ('21', '10.5',
     * 'Exento'...). Nunca por id hardcodeado.
     *
     * @param  string $porcentaje
     * @return int
     */
    protected function iva_id($porcentaje)
    {
        $id = Iva::where('percentage', $porcentaje)->value('id');

        $this->assertNotNull($id, 'La base de testing no tiene el IVA "' . $porcentaje . '".');

        return (int) $id;
    }

    /**
     * Un artículo NUEVO del dueño del fixture (para las ramas del cálculo que los diez del catálogo
     * no cubren), del proveedor Buenos Aires salvo que se pida otro, y con el precio ya calculado
     * por el camino de siempre: una compra real siempre encuentra el artículo con su precio, y el
     * "antes" de la comparación tiene que ser un precio de verdad, no un null.
     *
     * Se crea FUERA de las corridas (antes de dos_caminos()), así las dos compras lo encuentran
     * con el mismo id y el mismo estado.
     *
     * @param  array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $atributos = [])
    {
        $dueno = $this->dueno();

        $article = Article::create(array_merge([
            'name'                           => 'zz Compras precios en lote ' . uniqid('', true),
            'user_id'                        => $dueno->id,
            'status'                         => 'active',
            'iva_id'                         => $this->iva_id('21'),
            'provider_id'                    => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_BSAS)->id,
            'cost'                           => 1000,
            'apply_provider_percentage_gain' => 1,
            'stock'                          => 0,
        ], $atributos));

        return $this->calcular_precio($article);
    }

    /**
     * Calcula y guarda el precio de un artículo con el camino por artículo de siempre (fuera de
     * las corridas: es preparación, no parte de lo que se compara). Devuelve el artículo releído.
     *
     * @param  \App\Models\Article $article
     * @return \App\Models\Article
     */
    protected function calcular_precio($article)
    {
        $dueno = $this->dueno();

        ArticleHelper::setFinalPrice(Article::find($article->id), $dueno->id, $dueno);

        return Article::find($article->id);
    }

    /**
     * Un renglón del array `articles` de la compra para un artículo dado (el `item()` de
     * ComprasTestCase lo busca por nombre y solo sirve para los diez del catálogo). Mismas claves y
     * mismos defaults que item().
     *
     * @param  \App\Models\Article $article
     * @param  float               $cost    Costo cargado en el renglón.
     * @param  float               $amount  Cantidad pedida (y recibida).
     * @param  array               $extra   Claves del pivot a pisar (price, discount, cost_in_dollars...).
     * @return array
     */
    protected function renglon($article, $cost, $amount, array $extra = [])
    {
        $article = Article::find($article->id);

        return [
            'id'            => $article->id,
            'bar_code'      => $article->bar_code,
            'provider_code' => $article->provider_code,
            'pivot'         => array_merge([
                'cost'            => $cost,
                'amount'          => $amount,
                'received'        => $amount,
                'price'           => null,
                'discount'        => null,
                'iva_id'          => $article->iva_id,
                'cost_in_dollars' => 0,
                'update_provider' => 1,
            ], $extra),
        ];
    }

    /**
     * Un costo extra de la compra cargado como lo carga la SPA en el alta: se crea sin compra, con
     * un temporal_id, y el POST lo engancha por `childrens` (Controller::updateRelationsCreated).
     * Por defecto un flete tipado (transporte), sin facturar y dentro de la factura de la compra.
     *
     * Va ADENTRO del callable de la compra: la corrida lo crea y el rollback lo borra.
     *
     * @param  array $atributos
     * @return array La entrada de `childrens` del payload.
     */
    protected function costo_extra_del_alta(array $atributos = [])
    {
        $temporal_id = 'zz-pel-costo-extra-' . uniqid('', true);

        ProviderOrderExtraCost::create(array_merge([
            'description'       => 'Flete',
            'value'             => 1000,
            'tipo'              => ProviderOrderExtraCost::TIPO_TRANSPORTE,
            'facturado'         => 0,
            'en_factura_compra' => 1,
        ], $atributos, [
            'provider_order_id' => null,
            'temporal_id'       => $temporal_id,
        ]));

        return [
            'model_name'  => 'provider_order_extra_cost',
            'temporal_id' => $temporal_id,
        ];
    }

    /**
     * Un descuento propio de la compra cargado como en el alta de la SPA (mismo mecanismo que
     * costo_extra_del_alta()). Con al menos uno, el alta NO precarga las bonificaciones del
     * proveedor (precargar_bonificaciones_proveedor()).
     *
     * @param  array $atributos ['percentage' => x] o ['monto' => y]
     * @return array La entrada de `childrens` del payload.
     */
    protected function descuento_del_alta(array $atributos)
    {
        $temporal_id = 'zz-pel-descuento-' . uniqid('', true);

        ProviderOrderDiscount::create(array_merge([
            'description' => 'Descuento de la compra',
        ], $atributos, [
            'provider_order_id' => null,
            'temporal_id'       => $temporal_id,
        ]));

        return [
            'model_name'  => 'provider_order_discount',
            'temporal_id' => $temporal_id,
        ];
    }

    /**
     * Confirma una compra nueva por el endpoint real (POST api/provider-order) y devuelve su id.
     *
     * @param  array $payload
     * @return int
     */
    protected function alta(array $payload)
    {
        $respuesta = $this->postJson('api/provider-order', $payload);

        $respuesta->assertStatus(201);

        return (int) $respuesta->json('model.id');
    }

    /**
     * Reconfirma una compra existente por el endpoint real (PUT api/provider-order/{id}).
     *
     * @param  int   $compra_id
     * @param  array $payload
     * @return int El mismo id.
     */
    protected function edicion($compra_id, array $payload)
    {
        $respuesta = $this->putJson('api/provider-order/' . $compra_id, $payload);

        $respuesta->assertStatus(200);

        return (int) $compra_id;
    }

    /* ------------------------------------------------------------------------------------------
     * Los dos caminos
     * ---------------------------------------------------------------------------------------- */

    /**
     * Confirma la misma compra por el camino de hoy y por el nuevo, sobre la misma base, y
     * devuelve las fotos para comparar.
     *
     * 1. Congela el reloj y anota las marcas (id máximo de las tablas donde la compra crea filas).
     * 2. Camino de HOY: savepoint → interruptor prendido → $compra() → foto → rollback.
     * 3. Foto "antes": precio final de los artículos y de sus listas. Se saca ACÁ, después del
     *    rollback de la primera corrida, porque recién ahora se sabe qué artículos compra la compra
     *    (los devuelve $compra()), y el rollback del savepoint deja la base exactamente como
     *    estaba antes. Se vuelve a sacar al final y se exige que sea la misma: si no, alguna de las
     *    dos corridas dejó algo afuera del savepoint y la comparación no vale.
     * 4. Camino NUEVO: interruptor apagado → savepoint → $compra() → foto → rollback.
     *
     * $compra tiene que hacer la compra por el ENDPOINT real (alta() o edicion()) y devolver
     * ['articulos' => int[], 'compra_id' => int]. Todo lo que cree adentro (costos extra y
     * descuentos del alta, la compra) lo revierte el savepoint; lo que tiene que existir en las dos
     * corridas con el MISMO id (artículos, una compra a editar) se crea antes de llamar acá.
     *
     * @param  callable $compra
     * @return array ['antes', 'hoy', 'motor', 'articulos', 'compra_id', 'resumen']
     */
    protected function dos_caminos(callable $compra)
    {
        Carbon::setTestNow(self::AHORA);

        try {

            $marcas = $this->marcas();

            $hoy = $this->correr_camino(true, $compra, $marcas);

            $antes = $this->foto_antes($hoy['articulos']);

            $motor = $this->correr_camino(false, $compra, $marcas);

            $antes_al_final = $this->foto_antes($hoy['articulos']);

        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($hoy['articulos'], $motor['articulos'], 'Las dos corridas compraron artículos distintos: la compra no es la misma.');

        $this->assertSame($antes, $antes_al_final, 'La base no quedó igual después de las dos corridas: algo quedó afuera del savepoint y la comparación no vale.');

        return [
            'antes'     => $antes,
            'hoy'       => $hoy['foto'],
            'motor'     => $motor['foto'],
            'articulos' => $hoy['articulos'],
            'compra_id' => $motor['compra_id'],
            'resumen'   => $this->resumen_de_cambios($hoy['foto'], $motor['foto']),
        ];
    }

    /**
     * Una corrida: interruptor en $por_articulo, savepoint, la compra, la foto y el rollback. El
     * interruptor y el estado de PreciosEnLote vuelven a su default pase lo que pase.
     *
     * @param  bool     $por_articulo true = camino de hoy; false = recálculo diferido con el motor.
     * @param  callable $compra
     * @param  array    $marcas       Las de marcas(), tomadas ANTES de la primera corrida.
     * @return array ['articulos' => int[], 'compra_id' => int, 'foto' => array]
     */
    protected function correr_camino($por_articulo, callable $compra, array $marcas)
    {
        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            $resultado = $compra();

            $this->assertIsArray($resultado, 'La compra tiene que devolver [\'articulos\' => ids, \'compra_id\' => id].');
            $this->assertArrayHasKey('articulos', $resultado);
            $this->assertArrayHasKey('compra_id', $resultado);

            $articulos = $this->ids_ordenados($resultado['articulos']);
            $compra_id = (int) $resultado['compra_id'];

            /* Guarda: los artículos que dice el callable son los que quedaron en la compra. */
            $de_la_compra = $this->ids_ordenados(
                DB::table('article_provider_order')->where('provider_order_id', $compra_id)->pluck('article_id')->all()
            );

            $this->assertSame($de_la_compra, $articulos, 'Los artículos que devolvió la compra no son los que quedaron en article_provider_order.');

            $foto = $this->foto_de_la_compra($articulos, $compra_id, $marcas);

        } finally {

            /* Vuelve exactamente al nivel de antes, aunque la compra haya dejado algo abierto. */
            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            $this->limpiar_estado_del_proceso();
        }

        return [
            'articulos' => $articulos,
            'compra_id' => $compra_id,
            'foto'      => $foto,
        ];
    }

    /**
     * El id máximo de cada tabla en la que la compra crea filas que después se miran por id: los
     * price_changes (para separar los de la corrida) y las tablas cuyos ids aparecen como
     * referencia en otras filas (para reemplazarlos por una marca). Se toman antes de la primera
     * corrida: las filas de cualquiera de las dos quedan por encima.
     *
     * @return array
     */
    protected function marcas()
    {
        return [
            'price_changes'              => (int) DB::table('price_changes')->max('id'),
            'provider_order_extra_costs' => (int) DB::table('provider_order_extra_costs')->max('id'),
            'stock_movements'            => (int) DB::table('stock_movements')->max('id'),
        ];
    }

    /**
     * Lo que las reglas D1 necesitan saber de ANTES de la compra: el precio final de cada artículo
     * y, por cada par (artículo, lista), si la fila existía, su final_price y su
     * setear_precio_final. Normalizado igual que las fotos (texto o null).
     *
     * @param  int[] $ids
     * @return array ['articles' => [id => final_price], 'pivots' => ['article_id|price_type_id' => [...]]]
     */
    protected function foto_antes(array $ids)
    {
        $foto = [
            'articles' => [],
            'pivots'   => [],
        ];

        foreach (DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get(['id', 'final_price']) as $fila) {
            $foto['articles'][(int) $fila->id] = $this->normalizar_escalares($fila->final_price);
        }

        $pivots = DB::table('article_price_type')
                        ->whereIn('article_id', $ids)
                        ->orderBy('article_id')
                        ->orderBy('price_type_id')
                        ->orderBy('id')
                        ->get(['article_id', 'price_type_id', 'final_price', 'setear_precio_final']);

        foreach ($pivots as $fila) {
            $foto['pivots'][$fila->article_id . '|' . $fila->price_type_id] = [
                'final_price'         => $this->normalizar_escalares($fila->final_price),
                'setear_precio_final' => $this->normalizar_escalares($fila->setear_precio_final),
            ];
        }

        return $foto;
    }

    /**
     * Todo lo que una compra puede escribir, para sus artículos y para la compra misma.
     *
     * Parte de la foto de los precios (FotoDePrecios::foto(): articles entero menos el vector de
     * embeddings, pivots de listas, monedas, y los price_changes de la corrida con sus listas) y
     * le suma:
     *  - de los artículos: article_provider (historial de proveedores), article_discounts,
     *    article_surchages, address_article (stock por depósito) y provider_price_offers;
     *  - de la compra: article_provider_order (renglones), stock_movements, current_acounts,
     *    provider_order_afip_tickets y la fila de provider_orders;
     *  - movimientos_con_proveedor: los pares "artículo|proveedor" de los movimientos de stock que
     *    ESTA corrida creó con proveedor, que son los que SetProvider escribió en article_provider
     *    (la regla D2 se aplica a esos y solo a esos).
     *
     * Sin ids generados ni el id de la compra (ver sin_ids_generados()), todo como texto o null y
     * las listas ordenadas por contenido.
     *
     * @param  int[] $ids
     * @param  int   $compra_id
     * @param  array $marcas
     * @return array
     */
    protected function foto_de_la_compra(array $ids, $compra_id, array $marcas)
    {
        $foto = $this->foto($ids, $marcas['price_changes']);

        $foto['article_provider']      = $this->filas('article_provider', 'article_id', $ids, $compra_id, $marcas);
        $foto['article_discounts']     = $this->filas('article_discounts', 'article_id', $ids, $compra_id, $marcas);
        $foto['article_surchages']     = $this->filas('article_surchages', 'article_id', $ids, $compra_id, $marcas);
        $foto['address_article']       = $this->filas('address_article', 'article_id', $ids, $compra_id, $marcas);
        $foto['provider_price_offers'] = $this->filas('provider_price_offers', 'article_id', $ids, $compra_id, $marcas);

        $foto['article_provider_order']      = $this->filas('article_provider_order', 'provider_order_id', $compra_id, $compra_id, $marcas);
        $foto['stock_movements']             = $this->filas('stock_movements', 'provider_order_id', $compra_id, $compra_id, $marcas);
        $foto['current_acounts']             = $this->filas('current_acounts', 'provider_order_id', $compra_id, $compra_id, $marcas);
        $foto['provider_order_afip_tickets'] = $this->filas('provider_order_afip_tickets', 'provider_order_id', $compra_id, $compra_id, $marcas);

        $compra = DB::table('provider_orders')->where('id', $compra_id)->first();

        $this->assertNotNull($compra, 'La compra ' . $compra_id . ' no existe.');

        $foto['provider_order'] = $this->sin_ids_generados((array) $compra, $compra_id, $marcas);

        $pares = [];

        $movimientos = DB::table('stock_movements')
                            ->where('provider_order_id', $compra_id)
                            ->where('id', '>', $marcas['stock_movements'])
                            ->whereNotNull('provider_id')
                            ->get(['article_id', 'provider_id']);

        foreach ($movimientos as $movimiento) {
            $pares[$movimiento->article_id . '|' . $movimiento->provider_id] = true;
        }

        $pares = array_keys($pares);
        sort($pares);

        $foto['movimientos_con_proveedor'] = $pares;

        $foto = $this->normalizar_escalares($foto);

        /* Las listas se reordenan DESPUÉS de pasar todo a texto: así el orden no depende del tipo
           con que PDO devolvió cada columna, y el esperado (que se arma sobre texto) ordena igual. */
        $foto['pivots'] = $this->ordenar_filas($foto['pivots']);

        foreach ($foto['cambios'] as $article_id => $cambios) {
            foreach ($cambios as $i => $cambio) {
                $foto['cambios'][$article_id][$i]['listas'] = $this->ordenar_filas($cambio['listas']);
            }
        }

        foreach ($this->secciones_en_lista() as $seccion) {
            $foto[$seccion] = $this->ordenar_filas($foto[$seccion]);
        }

        return $foto;
    }

    /**
     * Las secciones de la foto que son listas de filas sin id (se comparan ordenadas por contenido).
     *
     * @return string[]
     */
    protected function secciones_en_lista()
    {
        return [
            'article_provider',
            'article_discounts',
            'article_surchages',
            'address_article',
            'provider_price_offers',
            'article_provider_order',
            'stock_movements',
            'current_acounts',
            'provider_order_afip_tickets',
        ];
    }

    /**
     * Las filas de una tabla donde $columna vale $valores (uno o varios), sin ids generados.
     *
     * @param  string    $tabla
     * @param  string    $columna
     * @param  int|int[] $valores
     * @param  int       $compra_id
     * @param  array     $marcas
     * @return array
     */
    protected function filas($tabla, $columna, $valores, $compra_id, array $marcas)
    {
        $consulta = DB::table($tabla);

        if (is_array($valores)) {
            $consulta->whereIn($columna, $valores);
        } else {
            $consulta->where($columna, $valores);
        }

        $filas = [];

        foreach ($consulta->get() as $fila) {
            $filas[] = $this->sin_ids_generados((array) $fila, $compra_id, $marcas);
        }

        return $filas;
    }

    /**
     * Saca de una fila lo que cambia de una corrida a la otra sin que nadie lo haya decidido: su
     * propio id, el id de la compra (en provider_order_id, y en referencia_id de las ofertas que
     * salen de una compra) y el de un costo extra creado adentro de la corrida (en las facturas
     * aparte de un costo extra). Los ids que ya existían antes de las corridas quedan tal cual:
     * son el mismo en las dos.
     *
     * @param  array $fila
     * @param  int   $compra_id
     * @param  array $marcas
     * @return array
     */
    protected function sin_ids_generados(array $fila, $compra_id, array $marcas)
    {
        unset($fila['id']);

        if (array_key_exists('provider_order_id', $fila) && !is_null($fila['provider_order_id']) && (int) $fila['provider_order_id'] === (int) $compra_id) {
            $fila['provider_order_id'] = self::LA_COMPRA;
        }

        if (
            array_key_exists('referencia_id', $fila)
            && isset($fila['origen'])
            && $fila['origen'] === 'compra'
            && !is_null($fila['referencia_id'])
            && (int) $fila['referencia_id'] === (int) $compra_id
        ) {
            $fila['referencia_id'] = self::LA_COMPRA;
        }

        if (
            array_key_exists('provider_order_extra_cost_id', $fila)
            && !is_null($fila['provider_order_extra_cost_id'])
            && (int) $fila['provider_order_extra_cost_id'] > $marcas['provider_order_extra_costs']
        ) {
            $fila['provider_order_extra_cost_id'] = self::CREADO_EN_LA_CORRIDA;
        }

        return $fila;
    }

    /**
     * Pasa todo escalar a texto (o null), recursivamente. Las dos fotos se comparan con assertSame:
     * así la comparación es estricta (null no es igual a 0 ni a '') pero no depende de si PDO
     * devolvió un entero como int o como texto. Los decimales ya vienen como texto con la escala de
     * su columna, igual en las dos corridas.
     *
     * @param  mixed $valor
     * @return mixed
     */
    protected function normalizar_escalares($valor)
    {
        if (is_object($valor)) {
            $valor = (array) $valor;
        }

        if (is_array($valor)) {

            $resultado = [];

            foreach ($valor as $clave => $item) {
                $resultado[$clave] = $this->normalizar_escalares($item);
            }

            return $resultado;
        }

        if (is_null($valor)) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return (string) $valor;
    }

    /**
     * Ordena una lista de filas por su contenido (su JSON): estable, sin ids, y un duplicado se ve.
     *
     * @param  array $filas
     * @return array
     */
    protected function ordenar_filas(array $filas)
    {
        $filas = array_values($filas);

        usort($filas, function ($a, $b) {
            return strcmp(json_encode($a), json_encode($b));
        });

        return $filas;
    }

    /**
     * @param  array $ids
     * @return int[] Enteros, sin repetidos, ordenados.
     */
    protected function ids_ordenados(array $ids)
    {
        $limpios = [];

        foreach ($ids as $id) {
            $limpios[(int) $id] = (int) $id;
        }

        $limpios = array_values($limpios);
        sort($limpios);

        return $limpios;
    }

    /* ------------------------------------------------------------------------------------------
     * La comparación
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 LA COMPARACIÓN. El camino nuevo deja lo mismo que el de hoy, salvo lo que cambian las
     * decisiones D1/D2 (ver el docblock de la clase y motor_esperado()). Si la compra no usa el
     * recálculo diferido, idéntico en todo.
     *
     * @param  array $antes Lo de foto_antes().
     * @param  array $hoy   La foto del camino de hoy.
     * @param  array $motor La foto del camino nuevo.
     * @return void
     */
    protected function assert_mismo_resultado(array $antes, array $hoy, array $motor)
    {
        if (!$this->usa_el_recalculo_diferido($motor)) {

            $this->assert_identico($hoy, $motor, 'La compra no difiere el recálculo (sin proveedor o sin actualizar precios): el camino nuevo tiene que ser el de hoy, idéntico en todo.');

            return;
        }

        $this->assert_secciones_iguales(
            $this->motor_esperado($antes, $hoy, $motor),
            $motor,
            'El camino nuevo dejó algo distinto de lo que explican las reglas D1/D2 aplicadas al camino de hoy.'
        );
    }

    /**
     * Idéntico en todo, sección por sección (para que el diff del fallo diga dónde).
     *
     * @param  array  $hoy
     * @param  array  $motor
     * @param  string $mensaje
     * @return void
     */
    protected function assert_identico(array $hoy, array $motor, $mensaje = 'Las dos corridas no dejaron lo mismo.')
    {
        $this->assert_secciones_iguales($hoy, $motor, $mensaje);
    }

    /**
     * @param  array  $esperado
     * @param  array  $real
     * @param  string $mensaje
     * @return void
     */
    protected function assert_secciones_iguales(array $esperado, array $real, $mensaje)
    {
        $this->assertSame(array_keys($esperado), array_keys($real), $mensaje . ' (las fotos no tienen las mismas secciones)');

        foreach ($esperado as $seccion => $valor) {
            $this->assertSame($valor, $real[$seccion], $mensaje . ' Sección: ' . $seccion . '.');
        }
    }

    /**
     * ¿Esta compra recalcula diferido, con el motor? Solo si actualiza precios y tiene proveedor
     * (NewProviderOrderHelper::se_difiere_el_recalculo(): con el interruptor apagado, el gate es el
     * mismo is_null() del proveedor). Con update_prices apagado no hay nada que recalcular en
     * ninguno de los dos caminos.
     *
     * @param  array $foto
     * @return bool
     */
    protected function usa_el_recalculo_diferido(array $foto)
    {
        return $foto['provider_order']['update_prices'] === '1'
            && !is_null($foto['provider_order']['provider_id']);
    }

    /**
     * Lo que el camino nuevo TIENE que dejar: la foto de hoy con las reglas D1/D2 aplicadas.
     *
     * - price_changes (D1): por artículo, si el precio final cambió en la compra (antes contra el
     *   final del motor, con la misma comparación suelta que setFinalPrice()), UNO, igual en todo
     *   al último de hoy; si no cambió, ninguno.
     * - articles.previus_final_price (D1): si el precio cambió, el precio de antes; si no, el de hoy.
     * - article_price_type.previus_final_price (D1, solo si la cuenta calcula listas): el de
     *   previus_de_lista_esperado().
     * - article_provider.price (D2): en los pares de movimientos_con_proveedor, el precio final del
     *   artículo llevado a la columna entera (precio_entero()).
     *
     * @param  array $antes
     * @param  array $hoy
     * @param  array $motor
     * @return array
     */
    protected function motor_esperado(array $antes, array $hoy, array $motor)
    {
        $esperado = $hoy;

        /* D1: un solo cambio de precio por artículo y por compra. */
        $esperado['cambios'] = [];

        foreach ($motor['articles'] as $article_id => $fila_del_motor) {

            $this->assertArrayHasKey($article_id, $antes['articles'], 'El artículo ' . $article_id . ' no estaba en la foto de antes.');

            if (!$this->cambio_el_precio($antes['articles'][$article_id], $fila_del_motor['final_price'])) {
                continue;
            }

            if (isset($esperado['articles'][$article_id])) {
                $esperado['articles'][$article_id]['previus_final_price'] = $antes['articles'][$article_id];
            }

            if (!empty($hoy['cambios'][$article_id])) {
                $cambios_de_hoy = $hoy['cambios'][$article_id];
                $esperado['cambios'][$article_id] = [$cambios_de_hoy[count($cambios_de_hoy) - 1]];
            }
        }

        ksort($esperado['cambios']);

        /* D1 en las listas: el "anterior" de cada lista es el de antes de la compra. */
        if ($this->la_cuenta_calcula_listas()) {

            foreach ($esperado['pivots'] as $i => $fila) {

                $clave = $fila['article_id'] . '|' . $fila['price_type_id'];

                $pivot_antes = isset($antes['pivots'][$clave]) ? $antes['pivots'][$clave] : null;

                $esperado['pivots'][$i]['previus_final_price'] = $this->previus_de_lista_esperado($pivot_antes);
            }

            $esperado['pivots'] = $this->ordenar_filas($esperado['pivots']);
        }

        /* D2: el "Precio Final" del historial de proveedores es el precio con el que quedó el artículo. */
        foreach ($motor['movimientos_con_proveedor'] as $par) {

            list($article_id, $provider_id) = explode('|', $par);

            $this->assertArrayHasKey((int) $article_id, $motor['articles'], 'El movimiento de stock es de un artículo que no está en la compra.');

            $precio = $this->precio_entero($motor['articles'][(int) $article_id]['final_price']);

            foreach ($esperado['article_provider'] as $i => $fila) {
                if ($fila['article_id'] === $article_id && $fila['provider_id'] === $provider_id) {
                    $esperado['article_provider'][$i]['price'] = $precio;
                }
            }
        }

        $esperado['article_provider'] = $this->ordenar_filas($esperado['article_provider']);

        return $esperado;
    }

    /**
     * ¿El precio cambió? La MISMA comparación suelta con la que setFinalPrice() decide si graba un
     * price_change (`$current_final_price != $article->final_price`): el de antes como lo devuelve
     * la base (texto o null) contra el nuevo como float (o null). Con esa comparación, null contra
     * 0.00 NO es un cambio, y no se graba.
     *
     * @param  string|null $antes
     * @param  string|null $despues
     * @return bool
     */
    protected function cambio_el_precio($antes, $despues)
    {
        $nuevo = is_null($despues) ? null : (float) $despues;

        return $antes != $nuevo;
    }

    /**
     * El previus_final_price que tiene que dejar el camino nuevo en una fila de article_price_type,
     * leído en ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() (29/9/2026):
     *
     *  - arranca en null (:541) y, si la fila existe y NO tiene setear_precio_final, toma el
     *    final_price que la fila tiene en ese momento (:588-589); después se escribe siempre (:835),
     *    cambie o no el precio. Con el motor el cálculo es uno solo y lee la fila de antes de la
     *    compra: el final_price de ANTES. (Hoy, la llamada #3 lee lo que dejó la #1.)
     *  - si la fila no existía (la ata el primer cálculo) o tiene setear_precio_final (precio de
     *    lista puesto a mano), queda en null, en los dos caminos.
     *
     * @param  array|null $pivot_antes ['final_price', 'setear_precio_final'] o null si no existía.
     * @return string|null
     */
    protected function previus_de_lista_esperado($pivot_antes)
    {
        if (is_null($pivot_antes) || !empty($pivot_antes['setear_precio_final'])) {
            return null;
        }

        return $pivot_antes['final_price'];
    }

    /**
     * ¿La cuenta calcula las listas de precio con aplicar_precios_segun_listas_de_precios() (la que
     * escribe previus_final_price en el pivot)? Es la rama de setFinalPrice() con listas_de_precio y
     * sin la extensión ventas_en_dolares (que calcula por moneda).
     *
     * @return bool
     */
    protected function la_cuenta_calcula_listas()
    {
        $dueno = $this->dueno();

        return UserHelper::uses_listas_de_precio($dueno) && !UserHelper::hasExtencion('ventas_en_dolares', $dueno);
    }

    /**
     * article_provider.price es INT: la base redondea el decimal al entero, mitad hacia afuera del
     * cero (lo mismo cuando SetProvider manda el texto que cuando el UPDATE ... JOIN copia la
     * columna; medido en MySQL 8.3 con sql_mode estricto el 29/9/2026). PHP round() redondea igual.
     *
     * @param  string|null $final_price
     * @return string|null
     */
    protected function precio_entero($final_price)
    {
        if (is_null($final_price)) {
            return null;
        }

        return (string) (int) round((float) $final_price, 0);
    }

    /* ------------------------------------------------------------------------------------------
     * Guardas de "el escenario prueba algo" y resumen
     * ---------------------------------------------------------------------------------------- */

    /**
     * Cuántos cambios de precio dejó cada camino, y qué artículos tuvieron más de uno hoy.
     *
     * @param  array $hoy
     * @param  array $motor
     * @return array ['hoy' => int, 'motor' => int, 'varios_hoy' => int[], 'caso_d1' => int[]]
     */
    protected function resumen_de_cambios(array $hoy, array $motor)
    {
        $resumen = [
            'hoy'        => 0,
            'motor'      => 0,
            'varios_hoy' => [],
            'caso_d1'    => [],
        ];

        foreach ($hoy['cambios'] as $article_id => $cambios) {

            $resumen['hoy'] += count($cambios);

            if (count($cambios) > 1) {

                $resumen['varios_hoy'][] = (int) $article_id;

                if (isset($motor['cambios'][$article_id]) && count($motor['cambios'][$article_id]) === 1) {
                    $resumen['caso_d1'][] = (int) $article_id;
                }
            }
        }

        foreach ($motor['cambios'] as $cambios) {
            $resumen['motor'] += count($cambios);
        }

        return $resumen;
    }

    /**
     * El escenario tiene que haber movido algún precio: si no, la comparación no prueba nada.
     *
     * @param  array $r Lo de dos_caminos().
     * @return void
     */
    protected function assert_hubo_cambios_de_precio(array $r)
    {
        $this->assertGreaterThan(0, $r['resumen']['hoy'], 'El escenario no cambió ningún precio: la comparación no prueba nada.');
    }

    /**
     * El escenario tiene que mostrar el caso D1: algún artículo con más de un cambio de precio hoy
     * y uno solo con el motor.
     *
     * @param  array $r       Lo de dos_caminos().
     * @param  int   $minimo  Cuántos artículos, como mínimo.
     * @return void
     */
    protected function assert_hubo_caso_d1(array $r, $minimo = 1)
    {
        $this->assertGreaterThanOrEqual(
            $minimo,
            count($r['resumen']['caso_d1']),
            'El escenario tenía que dejar hoy más de un cambio de precio en algún artículo y uno solo con el motor (caso D1). Resumen: ' . json_encode($r['resumen'])
        );
    }

    /**
     * Anota el resumen de cambios de un escenario en el archivo que diga la variable de entorno
     * PRECIOS_EN_LOTE_RESUMEN (una línea JSON por escenario), para leer los números del caso D1 sin
     * ensuciar la salida de phpunit. Sin la variable no hace nada.
     *
     * @param  string $escenario
     * @param  array  $r Lo de dos_caminos().
     * @return void
     */
    protected function anotar_resumen($escenario, array $r)
    {
        $archivo = getenv('PRECIOS_EN_LOTE_RESUMEN');

        if ($archivo === false || $archivo === '') {
            return;
        }

        file_put_contents($archivo, json_encode([
            'escenario' => $escenario,
            'articulos' => count($r['articulos']),
            'resumen'   => $r['resumen'],
        ]) . PHP_EOL, FILE_APPEND);
    }

    /* ------------------------------------------------------------------------------------------
     * Consultas
     * ---------------------------------------------------------------------------------------- */

    /**
     * Corre una compra en un savepoint, con el interruptor en $por_articulo, y devuelve el SQL de
     * cada consulta que mandó (sin bindings). El reloj queda congelado igual que en dos_caminos().
     *
     * @param  bool     $por_articulo
     * @param  callable $compra
     * @return string[]
     */
    protected function consultas_de_la_compra($por_articulo, callable $compra)
    {
        if (!$this->escucha_registrada) {

            DB::listen(function ($consulta) {
                if (is_array($this->consultas_anotadas)) {
                    $this->consultas_anotadas[] = $consulta->sql;
                }
            });

            $this->escucha_registrada = true;
        }

        Carbon::setTestNow(self::AHORA);

        $this->limpiar_estado_del_proceso();

        NewProviderOrderHelper::recalcular_por_articulo($por_articulo);

        $nivel = DB::transactionLevel();

        DB::beginTransaction();

        try {

            $this->consultas_anotadas = [];

            $compra();

            $consultas = $this->consultas_anotadas;

        } finally {

            $this->consultas_anotadas = null;

            while (DB::transactionLevel() > $nivel) {
                DB::rollBack();
            }

            $this->limpiar_estado_del_proceso();

            Carbon::setTestNow();
        }

        return $consultas;
    }

    /**
     * Sentencias INSERT a price_changes (una por cambio hoy; una por tanda con el motor).
     *
     * @param  string[] $consultas
     * @return int
     */
    protected function inserts_de_cambios_de_precio(array $consultas)
    {
        $total = 0;

        foreach ($consultas as $sql) {
            if (preg_match('/^\s*insert\s+into\s+`?price_changes`?\s/i', $sql)) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Sentencias UPDATE a `articles` DEL RECÁLCULO: las que escriben precio final o costo real
     * (hoy, los save() de setFinalPrice(); con el motor, el UPDATE ... CASE en bloque). Quedan
     * afuera los UPDATE de la compra que no son del cálculo (costo, proveedor, stock, estado, y el
     * `price = null` de un artículo con margen), que existen igual en los dos caminos.
     *
     * @param  string[] $consultas
     * @return int
     */
    protected function updates_de_precios_de_articulos(array $consultas)
    {
        $total = 0;

        foreach ($consultas as $sql) {

            if (!preg_match('/^\s*update\s+`?articles`?\s+set\s+(.*)\swhere\s/is', $sql, $partes)) {
                continue;
            }

            if (preg_match('/`(final_price|costo_real)`\s*=/i', $partes[1])) {
                $total++;
            }
        }

        return $total;
    }
}
