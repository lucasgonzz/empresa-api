<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\import\article\motor\StockEnLote;
use App\Http\Controllers\Stock\StockMovementController;
use App\Jobs\ProcessSendAdviseMail;
use App\Models\Address;
use App\Models\Advise;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * StockEnLote deja en la base EXACTAMENTE lo mismo que el camino viejo.
 *
 * Misión `importacion-excel-motor-rapido` (24/9/2026), constructor B1. El camino viejo es el que
 * tenía ActualizarBBDD: `StockMovementController::crear()` por movimiento
 * (guardar_stock_movement_global / guardar_stock_movement_addresses) más `exists()` +
 * `updateExistingPivot()` / `attach()` por depósito para stock_min/stock_max. Acá se reproduce
 * tal cual (ver camino_viejo_*) y se compara contra StockEnLote sobre GEMELOS: cada escenario se
 * arma dos veces con el mismo estado inicial, uno va por cada camino, y las fotos tienen que ser
 * idénticas campo por campo (stock_movements salvo id/article_id/timestamps, articles.stock,
 * stock_updated_at igual al created_at del movimiento, address_article salvo id/article_id).
 *
 * Además: el número de consultas de volcar() no crece con la cantidad de artículos.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class StockEnLoteTest extends ImportTestCase
{
    /** updated_at con el que nacen los artículos del escenario, para detectar si se tocó. */
    const UPDATED_AT_INICIAL = '2020-01-01 00:00:00';

    /** @var array clave => Address */
    protected $depositos = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['A', 'B'] as $clave) {
            $this->depositos[$clave] = Address::create([
                'street'  => 'Deposito '.$clave.' stock en lote',
                'user_id' => $this->tenant->id,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Escenarios
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Los escenarios que cubren cada rama del docblock de StockEnLote. Cada uno: estado inicial
     * del artículo (stock, depósitos, variante, aviso) y la lista de pedidos, en la misma forma
     * en que ActualizarBBDD los hace (`global` = guardar_stock_movement_global($amount, $target),
     * `depositos` = guardar_stock_movement_addresses([...])).
     *
     * @return array
     */
    protected function escenarios()
    {
        return [
            'global creado, stock 10 + 15' => [
                'stock' => 10, 'depositos' => [], 'pedidos' => [['global', 15, null]],
            ],
            'global actualizado, 20 -> objetivo 5' => [
                'stock' => 20, 'depositos' => [], 'pedidos' => [['global', -15, 5]],
            ],
            'global actualizado sin cambio (objetivo = stock)' => [
                'stock' => 7, 'depositos' => [], 'pedidos' => [['global', 3, 7]],
            ],
            'global creado con stock null' => [
                'stock' => null, 'depositos' => [], 'pedidos' => [['global', 7, null]],
            ],
            'global creado con delta cero' => [
                'stock' => 4, 'depositos' => [], 'pedidos' => [['global', 0, null]],
            ],
            'dos movimientos globales al mismo articulo' => [
                'stock' => 2, 'depositos' => [], 'pedidos' => [['global', 5, null], ['global', 3, null]],
            ],
            'dos depositos seguidos sobre uno existente (resultante acumulado)' => [
                'stock' => 3, 'depositos' => ['A' => [3, null, null]],
                'pedidos' => [['depositos', [['A', 4, null, null], ['B', 5, null, null]]]],
            ],
            'depositos abren en articulo sin stock (dos direcciones, min/max)' => [
                'stock' => 0, 'depositos' => [],
                'pedidos' => [['depositos', [['A', 10, 2, 20], ['B', 5, null, null]]]],
            ],
            'deposito abre sobre stock global 20 (el concepto reparte)' => [
                'stock' => 20, 'depositos' => [],
                'pedidos' => [['depositos', [['A', 4, null, null]]]],
            ],
            'deposito existente suma y recibe min/max; otro solo con min/max' => [
                'stock' => 3, 'depositos' => ['A' => [3, null, null]],
                'pedidos' => [['depositos', [['A', 4, 1, 9], ['B', null, 5, 50]]]],
            ],
            'global sobre articulo con depositos (no suma al stock)' => [
                'stock' => 3, 'depositos' => ['A' => [3, null, null]],
                'pedidos' => [['global', 5, null]],
            ],
            'deposito sobre articulo con stock null' => [
                'stock' => null, 'depositos' => [],
                'pedidos' => [['depositos', [['A', 6, null, null]]]],
            ],
            'deposito con monto cero (sin guarda de cero, como hoy)' => [
                'stock' => 0, 'depositos' => [],
                'pedidos' => [['depositos', [['A', 0, null, null]]]],
            ],
            'deposito con decimales' => [
                'stock' => 0, 'depositos' => ['A' => [1.5, null, null]],
                'pedidos' => [['depositos', [['A', 2.25, null, null]]]],
            ],
            'articulo con variante va por el camino viejo' => [
                'stock' => 2, 'depositos' => [], 'variante' => true, 'pedidos' => [['global', 5, null]],
            ],
            'aviso de "volvio el stock"' => [
                'stock' => 0, 'depositos' => [], 'aviso' => true, 'pedidos' => [['global', 3, null]],
            ],
        ];
    }

    /**
     * Crea un artículo del tenant con el estado inicial del escenario.
     *
     * @param  string $nombre
     * @param  array  $escenario
     * @return \App\Models\Article
     */
    protected function armar($nombre, array $escenario)
    {
        $article = new Article();
        $article->user_id = $this->tenant->id;
        $article->name    = $nombre;
        $article->stock   = $escenario['stock'];
        $article->status  = 'active';
        $article->save();

        // Fecha fija para poder detectar si el camino tocó updated_at; stock_updated_at en null.
        DB::table('articles')->where('id', $article->id)->update([
            'updated_at'       => self::UPDATED_AT_INICIAL,
            'stock_updated_at' => null,
        ]);

        foreach ($escenario['depositos'] as $clave => $pivot) {
            DB::table('address_article')->insert([
                'article_id' => $article->id,
                'address_id' => $this->depositos[$clave]->id,
                'amount'     => $pivot[0],
                'stock_min'  => $pivot[1],
                'stock_max'  => $pivot[2],
            ]);
        }

        if (!empty($escenario['variante'])) {
            ArticleVariant::create([
                'article_id'          => $article->id,
                'variant_description' => 'Rojo',
                'stock'               => 2,
            ]);
        }

        if (!empty($escenario['aviso'])) {
            Advise::create([
                'article_id' => $article->id,
                'email'      => 'aviso-'.$article->id.'@test.local',
            ]);
        }

        return Article::find($article->id);
    }

    /**
     * Traduce la lista de pedidos del escenario a la forma que reciben los dos caminos.
     *
     * @param  array $pedidos
     * @return array
     */
    protected function pedidos_de(array $pedidos)
    {
        $salida = [];

        foreach ($pedidos as $pedido) {

            if ($pedido[0] === 'global') {
                $salida[] = ['global', $pedido[1], $pedido[2]];
                continue;
            }

            $addresses = [];

            foreach ($pedido[1] as $address) {
                $addresses[] = [
                    'address_id' => $this->depositos[$address[0]]->id,
                    'amount'     => $address[1],
                    'stock_min'  => $address[2],
                    'stock_max'  => $address[3],
                ];
            }

            $salida[] = ['depositos', $addresses];
        }

        return $salida;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // El camino viejo, tal cual estaba en ActualizarBBDD antes de StockEnLote
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  \App\Http\Controllers\Stock\StockMovementController $ct
     * @param  \App\Models\Article $article
     * @param  float               $amount
     * @param  float|null          $target_stock
     * @return void
     */
    protected function camino_viejo_global($ct, $article, $amount, $target_stock = null)
    {
        if (!is_null($target_stock)) {

            $stock_actual = (float) $article->fresh()->stock;
            $amount = $target_stock - $stock_actual;

            if ($amount == 0.0) {
                return;
            }
        }

        if ((float) $amount == 0.0) {
            return;
        }

        $data = [];
        $data['concepto_stock_movement_name'] = 'Importacion de excel';
        $data['model_id'] = $article->id;
        $data['amount'] = $amount;

        $ct->crear($data, true, $this->tenant, $this->tenant->id);
    }

    /**
     * @param  \App\Http\Controllers\Stock\StockMovementController $ct
     * @param  \App\Models\Article $article
     * @param  array               $addresses
     * @return void
     */
    protected function camino_viejo_depositos($ct, $article, array $addresses)
    {
        $data = [];
        $data['concepto_stock_movement_name'] = 'Importacion de excel';
        $data['model_id'] = $article->id;

        foreach ($addresses as $address) {

            if (!is_null($address['amount'])) {
                $data['to_address_id'] = $address['address_id'];
                $data['amount'] = $address['amount'];
                $ct->crear($data, true, $this->tenant, $this->tenant->id);
            }

            if (!is_null($address['stock_min']) || !is_null($address['stock_max'])) {

                if ($article->addresses()->where('address_id', $address['address_id'])->exists()) {
                    $article->addresses()->updateExistingPivot($address['address_id'], [
                        'stock_min' => $address['stock_min'],
                        'stock_max' => $address['stock_max'],
                    ]);
                } else {
                    $article->addresses()->attach($address['address_id'], [
                        'stock_min' => $address['stock_min'],
                        'stock_max' => $address['stock_max'],
                    ]);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // La foto que se compara
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Todo lo que un movimiento de stock deja en la base para un artículo, sin ids ni horas.
     *
     * @param  \App\Models\Article $article
     * @return array
     */
    protected function foto($article)
    {
        $fila = DB::table('articles')->where('id', $article->id)->first();

        $movimientos = DB::table('stock_movements')
                            ->where('article_id', $article->id)
                            ->orderBy('id')
                            ->get();

        $filas_movimientos = [];
        $stock_updated_at_coincide = true;

        foreach ($movimientos as $movimiento) {

            $arr = (array) $movimiento;

            // stock_updated_at tiene que ser el created_at del movimiento (si el concepto existe).
            if (!is_null($movimiento->concepto_stock_movement_id) && (string) $fila->stock_updated_at !== (string) $movimiento->created_at) {
                $stock_updated_at_coincide = false;
            }

            unset($arr['id'], $arr['article_id'], $arr['created_at'], $arr['updated_at']);

            // Traducción de los ids de depósito a la clave del escenario (los gemelos comparten depósitos).
            $filas_movimientos[] = $arr;
        }

        $depositos = DB::table('address_article')
                        ->where('article_id', $article->id)
                        ->orderBy('address_id')
                        ->orderBy('id')
                        ->get();

        $filas_depositos = [];

        foreach ($depositos as $deposito) {
            $filas_depositos[] = [
                'address_id'      => (int) $deposito->address_id,
                'amount'          => $deposito->amount,
                'stock_min'       => $deposito->stock_min,
                'stock_max'       => $deposito->stock_max,
                'created_at_null' => is_null($deposito->created_at),
                'updated_at_null' => is_null($deposito->updated_at),
            ];
        }

        return [
            'stock'                     => $fila->stock,
            'updated_at_tocado'         => (string) $fila->updated_at !== self::UPDATED_AT_INICIAL,
            'stock_updated_at_null'     => is_null($fila->stock_updated_at),
            'stock_updated_at_coincide' => $stock_updated_at_coincide,
            'movimientos'               => $filas_movimientos,
            'depositos'                 => $filas_depositos,
        ];
    }

    /**
     * Corre todos los escenarios por los dos caminos y devuelve [nombre => [foto_vieja, foto_nueva]].
     *
     * @param  array $escenarios
     * @return array
     */
    protected function correr_gemelos(array $escenarios)
    {
        $ct   = new StockMovementController(false);
        $lote = new StockEnLote($this->tenant, $this->tenant->id, $ct);

        $viejos = [];
        $nuevos = [];

        foreach ($escenarios as $nombre => $escenario) {

            $viejos[$nombre] = $this->armar('VIEJO '.$nombre, $escenario);
            $nuevos[$nombre] = $this->armar('NUEVO '.$nombre, $escenario);

            foreach ($this->pedidos_de($escenario['pedidos']) as $pedido) {

                if ($pedido[0] === 'global') {
                    $this->camino_viejo_global($ct, $viejos[$nombre], $pedido[1], $pedido[2]);
                    $lote->agregar_global($nuevos[$nombre], $pedido[1], $pedido[2]);
                } else {
                    $this->camino_viejo_depositos($ct, $viejos[$nombre], $pedido[1]);
                    $lote->agregar_por_depositos($nuevos[$nombre], $pedido[1]);
                }
            }
        }

        $lote->volcar();

        $fotos = [];

        foreach ($escenarios as $nombre => $escenario) {
            $fotos[$nombre] = [$this->foto($viejos[$nombre]), $this->foto($nuevos[$nombre])];
        }

        return $fotos;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Cada escenario deja lo mismo por los dos caminos.
     *
     * @return void
     */
    public function test_el_lote_deja_exactamente_lo_mismo_que_el_camino_viejo()
    {
        Queue::fake();

        // Con la config online presente, checkAdvises() llega a despachar el job del aviso.
        OnlineConfiguration::create(['user_id' => $this->tenant->id, 'avisar_ingreso_stock_por_mail' => 1]);

        $fotos = $this->correr_gemelos($this->escenarios());

        foreach ($fotos as $nombre => $par) {
            $this->assertEquals(
                $par[0],
                $par[1],
                'El escenario "'.$nombre.'" deja algo distinto por el camino nuevo.'
            );
        }

        // Guardas para que el test no pase por vacío: hubo movimientos, depósitos y avisos.
        $this->assertNotEmpty($fotos['global creado, stock 10 + 15'][1]['movimientos']);
        $this->assertSame('25.00', $fotos['global creado, stock 10 + 15'][1]['stock']);
        $this->assertCount(2, $fotos['depositos abren en articulo sin stock (dos direcciones, min/max)'][1]['movimientos']);
        $this->assertCount(2, $fotos['depositos abren en articulo sin stock (dos direcciones, min/max)'][1]['depositos']);
        $this->assertEmpty($fotos['global actualizado sin cambio (objetivo = stock)'][1]['movimientos']);
        $this->assertEmpty($fotos['global creado con delta cero'][1]['movimientos']);

        // El aviso se despachó una vez por cada gemelo (el viejo y el nuevo).
        Queue::assertPushed(ProcessSendAdviseMail::class, 2);
    }

    /**
     * Detalle fino de los valores (no sólo igualdad entre caminos): resultante acumulado por
     * depósito, observaciones con el stock, el global sobre depósitos no suma, el depósito abre
     * sobre stock global.
     *
     * @return void
     */
    public function test_los_valores_de_cada_movimiento_son_los_esperados()
    {
        $fotos = $this->correr_gemelos($this->escenarios());

        $dos = $fotos['depositos abren en articulo sin stock (dos direcciones, min/max)'][1];
        $this->assertSame('10.00', $dos['movimientos'][0]['stock_resultante']);
        $this->assertSame('10', $dos['movimientos'][0]['observations']);
        $this->assertSame((int) $this->depositos['A']->id, (int) $dos['movimientos'][0]['to_address_id']);
        $this->assertSame('15.00', $dos['movimientos'][1]['stock_resultante']);
        $this->assertSame('15', $dos['movimientos'][1]['observations']);
        $this->assertSame('15.00', $dos['stock']);
        $this->assertSame('2', (string) $dos['depositos'][0]['stock_min']);
        $this->assertSame('20', (string) $dos['depositos'][0]['stock_max']);
        $this->assertFalse($dos['updated_at_tocado'], 'Abrir depósitos no toca updated_at del artículo');
        $this->assertTrue($dos['stock_updated_at_coincide']);

        $sobre_global = $fotos['deposito abre sobre stock global 20 (el concepto reparte)'][1];
        $this->assertSame('4.00', $sobre_global['stock'], 'El stock pasa a ser la suma de los depósitos');
        $this->assertSame('4.00', $sobre_global['movimientos'][0]['stock_resultante']);

        $con_depositos = $fotos['global sobre articulo con depositos (no suma al stock)'][1];
        $this->assertSame('3.00', $con_depositos['stock']);
        $this->assertSame('5.00', $con_depositos['movimientos'][0]['amount']);
        $this->assertSame('3.00', $con_depositos['movimientos'][0]['stock_resultante']);
        $this->assertNull($con_depositos['movimientos'][0]['to_address_id']);

        $existente = $fotos['deposito existente suma y recibe min/max; otro solo con min/max'][1];
        $this->assertSame('7.00', $existente['stock']);
        $this->assertSame('7.00', $existente['depositos'][0]['amount']);
        $this->assertSame('1', (string) $existente['depositos'][0]['stock_min']);
        $this->assertFalse($existente['depositos'][0]['updated_at_null'], 'sumar_al_deposito toca updated_at del pivot');
        $this->assertNull($existente['depositos'][1]['amount']);
        $this->assertSame('50', (string) $existente['depositos'][1]['stock_max']);
        $this->assertTrue($existente['depositos'][1]['updated_at_null'], 'attach() no escribe timestamps');
        $this->assertCount(1, $existente['movimientos']);

        $nulo = $fotos['global creado con stock null'][1];
        $this->assertSame('7.00', $nulo['stock']);
        $this->assertTrue($nulo['updated_at_tocado']);

        $decimales = $fotos['deposito con decimales'][1];
        $this->assertSame('3.75', $decimales['stock']);
        $this->assertSame('3.75', $decimales['movimientos'][0]['observations']);

        // El resultante intermedio (el del primer movimiento) es el stock corrido, no el monto.
        $dos_globales = $fotos['dos movimientos globales al mismo articulo'][1];
        $this->assertSame('5.00', $dos_globales['movimientos'][0]['amount']);
        $this->assertSame('7.00', $dos_globales['movimientos'][0]['stock_resultante']);
        $this->assertSame('7', $dos_globales['movimientos'][0]['observations']);
        $this->assertSame('10.00', $dos_globales['movimientos'][1]['stock_resultante']);
        $this->assertSame('10.00', $dos_globales['stock']);

        $dos_depositos = $fotos['dos depositos seguidos sobre uno existente (resultante acumulado)'][1];
        $this->assertSame('4.00', $dos_depositos['movimientos'][0]['amount']);
        $this->assertSame('7.00', $dos_depositos['movimientos'][0]['stock_resultante']);
        $this->assertSame('12.00', $dos_depositos['movimientos'][1]['stock_resultante']);
        $this->assertSame('12.00', $dos_depositos['stock']);
        $this->assertCount(2, $dos_depositos['depositos']);

        $objetivo = $fotos['global actualizado, 20 -> objetivo 5'][1];
        $this->assertSame('-15.00', $objetivo['movimientos'][0]['amount']);
        $this->assertSame('5.00', $objetivo['stock']);
    }

    /**
     * Sin el concepto 'Importacion de excel' en la base: el movimiento queda con concepto null,
     * stock_updated_at no se toca y un depósito no puede abrirse sobre stock global (el
     * movimiento va al global, sin to_address_id). Igual por los dos caminos.
     *
     * @return void
     */
    public function test_sin_concepto_deja_lo_mismo_que_el_camino_viejo()
    {
        $respaldo = DB::table('concepto_stock_movements')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();

        try {
            DB::table('concepto_stock_movements')->delete();

            $escenarios = [
                'sin concepto: global' => [
                    'stock' => 4, 'depositos' => [], 'pedidos' => [['global', 5, null]],
                ],
                'sin concepto: deposito sobre stock global no abre' => [
                    'stock' => 20, 'depositos' => [], 'pedidos' => [['depositos', [['A', 5, null, null]]]],
                ],
                'sin concepto: deposito sobre stock cero abre' => [
                    'stock' => 0, 'depositos' => [], 'pedidos' => [['depositos', [['A', 5, null, null]]]],
                ],
                'sin concepto: deposito existente suma' => [
                    'stock' => 2, 'depositos' => ['A' => [2, null, null]], 'pedidos' => [['depositos', [['A', 5, null, null]]]],
                ],
            ];

            $fotos = $this->correr_gemelos($escenarios);

            foreach ($fotos as $nombre => $par) {
                $this->assertEquals($par[0], $par[1], 'El escenario "'.$nombre.'" deja algo distinto por el camino nuevo.');
            }

            $global = $fotos['sin concepto: global'][1];
            $this->assertNull($global['movimientos'][0]['concepto_stock_movement_id']);
            $this->assertTrue($global['stock_updated_at_null'], 'Sin concepto no se toca stock_updated_at');
            $this->assertSame('9.00', $global['stock']);

            $no_abre = $fotos['sin concepto: deposito sobre stock global no abre'][1];
            $this->assertNull($no_abre['movimientos'][0]['to_address_id']);
            $this->assertSame('25.00', $no_abre['stock']);
            $this->assertEmpty($no_abre['depositos']);

            $abre = $fotos['sin concepto: deposito sobre stock cero abre'][1];
            $this->assertCount(1, $abre['depositos']);
            $this->assertSame('5.00', $abre['stock']);

        } finally {
            DB::table('concepto_stock_movements')->delete();
            if (count($respaldo) > 0) {
                DB::table('concepto_stock_movements')->insert($respaldo);
            }
        }
    }

    /**
     * El número de consultas de volcar() no depende de cuántos artículos trae el lote.
     *
     * @return void
     */
    public function test_las_consultas_por_lote_no_crecen_con_la_cantidad_de_articulos()
    {
        $consultas_con = function ($cantidad) {

            $lote = new StockEnLote($this->tenant, $this->tenant->id);

            for ($i = 0; $i < $cantidad; $i++) {

                $global = $this->armar('Consultas global '.$cantidad.'-'.$i, ['stock' => 3, 'depositos' => []]);
                $lote->agregar_global($global, 2, 5);

                $con_deposito = $this->armar('Consultas deposito '.$cantidad.'-'.$i, ['stock' => 0, 'depositos' => ['A' => [1, null, null]]]);
                $lote->agregar_por_depositos($con_deposito, $this->pedidos_de([['depositos', [['A', 4, 1, 9], ['B', 2, null, null]]]])[0][1]);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            $lote->volcar();

            $consultas = count(DB::getQueryLog());

            DB::disableQueryLog();

            return $consultas;
        };

        $con_dos    = $consultas_con(2);
        $con_veinte = $consultas_con(20);

        $this->assertGreaterThan(0, $con_dos);
        $this->assertSame(
            $con_dos,
            $con_veinte,
            'volcar() hizo '.$con_dos.' consultas con 2 artículos y '.$con_veinte.' con 20: la escritura no es en lote.'
        );
    }

    /**
     * Si falla el INSERT de los movimientos (la última escritura del lote), no queda stock movido
     * sin asiento: el stock, los depósitos, updated_at y stock_updated_at vuelven a como estaban,
     * igual que con el camino viejo, que empieza justamente por ese INSERT. Chequeo 3 de la misión
     * (24/9/2026): sin la transacción quedaban stock = 15 y stock_updated_at puesto con cero
     * movimientos para todo el lote.
     *
     * El fallo se provoca con un employee_id no numérico, que MySQL en modo estricto rechaza; en
     * producción lo mismo lo produce un deadlock o un lock wait timeout.
     *
     * @return void
     */
    public function test_si_falla_la_escritura_de_los_movimientos_no_queda_stock_sin_asiento()
    {
        $global = $this->armar('TRANSACCION GLOBAL', ['stock' => 10, 'depositos' => []]);
        $con_depositos = $this->armar('TRANSACCION DEPOSITOS', [
            'stock' => 9, 'depositos' => ['A' => [4, null, null], 'B' => [5, null, null]],
        ]);

        $antes = [$this->foto($global), $this->foto($con_depositos)];

        $lote = new StockEnLote($this->tenant, 'no-es-un-id');

        foreach ($this->pedidos_de([['global', 5, null], ['depositos', [['A', 10, 2, 20], ['B', 1, null, null]]]]) as $i => $pedido) {
            if ($pedido[0] === 'global') {
                $lote->agregar_global($global, $pedido[1], $pedido[2]);
            } else {
                $lote->agregar_por_depositos($con_depositos, $pedido[1]);
            }
        }

        $fallo = null;

        try {
            $lote->volcar();
        } catch (\Throwable $e) {
            $fallo = $e;
        }

        $this->assertNotNull($fallo, 'El INSERT de los movimientos tenía que fallar: el test no prueba nada.');

        $this->assertSame(
            $antes,
            [$this->foto($global), $this->foto($con_depositos)],
            'Un fallo al escribir los movimientos no puede dejar el stock escrito sin su asiento.'
        );
    }

    /**
     * Sin pedidos, volcar() no consulta nada y devuelve el resumen vacío.
     *
     * @return void
     */
    public function test_sin_pedidos_no_hace_nada()
    {
        $lote = new StockEnLote($this->tenant, $this->tenant->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $resumen = $lote->volcar();

        $this->assertSame(0, count(DB::getQueryLog()));
        $this->assertSame(['movimientos' => 0, 'por_camino_viejo' => 0, 'articulos' => 0], $resumen);

        DB::disableQueryLog();
    }
}
