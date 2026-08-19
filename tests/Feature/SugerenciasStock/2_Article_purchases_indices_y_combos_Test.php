<?php

namespace Tests\Feature\SugerenciasStock;

use App\Models\Address;
use App\Models\ArticlePurchase;
use App\Models\Combo;
use App\Models\SaleChannel;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión sugerencias inteligentes de stock — pieza P2.
 *
 * Protege las dos patas del cimiento del cálculo de prioridad por días hasta quiebre:
 *
 * 1. Los índices de `article_purchases` existen (hoy la tabla no tiene NINGUNO y cada
 *    agregación es un full scan sobre 10^5-10^6 filas). Sin el compuesto
 *    (address_id, article_id, created_at), la consulta de velocidad de venta por sucursal
 *    vuelve a ser un full scan en silencio.
 * 2. `ArticlePurchaseHelper::combos()` setea `address_id` y `sale_channel_id` igual que el
 *    bucle principal. Sin eso, las ventas por combo no suman a la velocidad de ninguna
 *    sucursal y la cobertura de esos artículos da infinito (quedan últimos aunque se estén
 *    quedando sin stock).
 *
 * Las ventas se arman contra el endpoint real (`POST api/sale`, vía EscenariosDePlata):
 * un insert a mano se saltearía justamente el camino SaleHelper -> ArticlePurchaseHelper
 * que está bajo prueba.
 */
class Article_purchases_indices_y_combos_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /**
     * Nombre del índice compuesto que apoya la agregación por sucursal + artículo + fecha.
     */
    const INDICE_COMPUESTO = 'article_purchases_address_article_created_index';

    /**
     * Nombre del índice por fecha pura (ventana interanual y reportes por período).
     */
    const INDICE_FECHA = 'article_purchases_created_at_index';

    /**
     * Combo creado por el test (si lo hubo), para el cleanup explícito.
     *
     * @var \App\Models\Combo|null
     */
    protected $combo_creado = null;

    /**
     * Fotos de los artículos tocados por las ventas del test (nombre => snapshot), para
     * restaurar stock y pivots en el tearDown (misma red explícita que EscenariosDePlata:
     * DatabaseTransactions debería alcanzar, pero el cleanup explícito es la red real).
     *
     * @var array<string,array>
     */
    protected $snapshots_articulos = [];

    /**
     * Libera lo creado por el trait (ventas, movimientos, aperturas) y lo propio de este
     * test (el combo con sus pivots, y el stock de los artículos vendidos).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        if (!is_null($this->combo_creado)) {
            DB::table('article_combo')->where('combo_id', $this->combo_creado->id)->delete();
            DB::table('combo_sale')->where('combo_id', $this->combo_creado->id)->delete();
            $this->combo_creado->forceDelete();
            $this->combo_creado = null;
        }

        foreach ($this->snapshots_articulos as $nombre => $snapshot) {
            $articulo = $this->articulo($nombre);
            if (!is_null($articulo)) {
                $this->restaurar_articulo($articulo, $snapshot);
            }
        }
        $this->snapshots_articulos = [];

        parent::tearDown();
    }

    /**
     * Usuario dueño del fixture, resuelto por email (nunca por id hardcodeado).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Depósito "Principal" del fixture, que hace de sucursal de la venta.
     *
     * @return \App\Models\Address
     */
    protected function deposito_principal()
    {
        $deposito = Address::where('user_id', $this->usuario_de_testing()->id)
            ->where('street', TestingFerreteriaSeeder::DEPOSITO)
            ->first();

        if (is_null($deposito)) {
            $this->fail('No existe el depósito "'.TestingFerreteriaSeeder::DEPOSITO.'" en el fixture de testing.');
        }

        return $deposito;
    }

    /**
     * Id del canal de venta "sistema", que es el que corresponde a una venta de mostrador
     * (sin meli_order_id, order_id ni tienda_nube_order_id).
     *
     * @return int
     */
    protected function canal_sistema_id()
    {
        $canal = SaleChannel::where('slug', 'sistema')->first();

        if (is_null($canal)) {
            $this->fail('No existe el sale_channel "sistema"; el fixture debería haberlo sembrado (SaleChannelSeeder).');
        }

        return (int) $canal->id;
    }

    /**
     * Guarda la foto de un artículo del fixture (una sola vez por nombre) antes de venderlo.
     *
     * @param string $nombre
     * @return \App\Models\Article
     */
    protected function articulo_con_snapshot($nombre)
    {
        $articulo = $this->articulo($nombre);

        if (is_null($articulo)) {
            $this->fail('No existe el artículo "'.$nombre.'" en el fixture de testing.');
        }

        if (!array_key_exists($nombre, $this->snapshots_articulos)) {
            $this->snapshots_articulos[$nombre] = $this->snapshot_articulo($articulo);
        }

        return $articulo;
    }

    /**
     * Test 1 — los dos índices existen, y el compuesto tiene exactamente esas columnas en
     * ese orden (el prefijo izquierdo es lo que hace servible al índice: con las columnas
     * desordenadas, la consulta por sucursal vuelve al full scan aunque el índice "exista").
     *
     * @test
     * @return void
     */
    public function los_indices_de_article_purchases_existen_con_sus_columnas()
    {
        $filas_compuesto = DB::select(
            "SHOW INDEX FROM article_purchases WHERE Key_name = '".self::INDICE_COMPUESTO."'"
        );

        $this->assertNotEmpty(
            $filas_compuesto,
            'Falta el índice '.self::INDICE_COMPUESTO.' en article_purchases: la agregación de velocidad de venta es un full scan.'
        );

        $columnas = collect($filas_compuesto)
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->values()
            ->all();

        $this->assertEquals(
            ['address_id', 'article_id', 'created_at'],
            $columnas,
            'El índice compuesto no tiene las columnas esperadas en el orden esperado.'
        );

        $filas_fecha = DB::select(
            "SHOW INDEX FROM article_purchases WHERE Key_name = '".self::INDICE_FECHA."'"
        );

        $this->assertNotEmpty(
            $filas_fecha,
            'Falta el índice '.self::INDICE_FECHA.' en article_purchases.'
        );
    }

    /**
     * Test 2 — una venta con combo deja en `article_purchases` una fila por artículo del
     * combo, con `address_id` (sucursal de la venta) y `sale_channel_id` (canal "sistema")
     * seteados, y el amount = cantidad de combos × cantidad del artículo dentro del combo.
     * Antes de esta pieza, esas filas quedaban con address_id y sale_channel_id en null.
     *
     * @test
     * @return void
     */
    public function una_venta_con_combo_anota_sucursal_y_canal_en_article_purchases()
    {
        $martillo = $this->articulo_con_snapshot(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $pinza = $this->articulo_con_snapshot('Pinza');

        $deposito = $this->deposito_principal();

        /** Combo propio del test: 1 martillo + 2 pinzas. */
        $this->combo_creado = Combo::create([
            'num'     => (int) (Combo::withTrashed()->max('num') ?? 0) + 1,
            'name'    => 'Combo herramientas (test P2)',
            'price'   => 1000,
            'user_id' => $this->usuario_de_testing()->id,
        ]);

        $this->combo_creado->articles()->attach($martillo->id, ['amount' => 1]);
        $this->combo_creado->articles()->attach($pinza->id, ['amount' => 2]);

        /** Se venden 2 combos: el amount esperado es 2×1 martillos y 2×2 pinzas. */
        $cantidad_combos = 2;
        $precio_combo = 1000;

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $precio_combo * $cantidad_combos,
            [
                'address_id' => $deposito->id,
                'items'      => [
                    [
                        'is_combo'     => true,
                        'id'           => $this->combo_creado->id,
                        'name'         => $this->combo_creado->name,
                        'amount'       => $cantidad_combos,
                        'price_vender' => $precio_combo,
                        // ComboHelper::discount_articles_stock() lee los artículos del payload
                        // (no de la base) para armar el movimiento de stock de la venta.
                        'articles'     => [
                            ['id' => $martillo->id, 'pivot' => ['amount' => 1]],
                            ['id' => $pinza->id,    'pivot' => ['amount' => 2]],
                        ],
                    ],
                ],
            ]
        );

        $filas = ArticlePurchase::where('sale_id', $venta->id)->get();

        $this->assertCount(
            2,
            $filas,
            'La venta con combo tiene que dejar exactamente una fila de article_purchases por artículo del combo.'
        );

        $fila_martillo = $filas->firstWhere('article_id', $martillo->id);
        $fila_pinza = $filas->firstWhere('article_id', $pinza->id);

        $this->assertNotNull($fila_martillo, 'Falta la fila de article_purchases del martillo (artículo del combo).');
        $this->assertNotNull($fila_pinza, 'Falta la fila de article_purchases de la pinza (artículo del combo).');

        // La aserción que da sentido a la pieza: sucursal y canal ya no quedan en null.
        $canal_sistema_id = $this->canal_sistema_id();

        foreach ([$fila_martillo, $fila_pinza] as $fila) {
            $this->assertEquals(
                $deposito->id,
                $fila->address_id,
                'La fila de combo quedó sin address_id: esa venta no suma a la velocidad de ninguna sucursal.'
            );
            $this->assertEquals(
                $canal_sistema_id,
                $fila->sale_channel_id,
                'La fila de combo quedó sin sale_channel_id.'
            );
        }

        $this->assertEquals($cantidad_combos * 1, (float) $fila_martillo->amount);
        $this->assertEquals($cantidad_combos * 2, (float) $fila_pinza->amount);
    }

    /**
     * Test 3 — testigo: una venta común (sin combo) sigue generando su fila de
     * article_purchases exactamente igual que antes, con sucursal y canal seteados por el
     * bucle principal. Si este test cae, el fix de combos() rompió el camino que ya andaba.
     *
     * @test
     * @return void
     */
    public function una_venta_sin_combo_sigue_anotando_sucursal_y_canal_como_siempre()
    {
        $martillo = $this->articulo_con_snapshot(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        $deposito = $this->deposito_principal();

        $cantidad = 3;
        $precio = 500;

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $precio * $cantidad,
            [
                'address_id' => $deposito->id,
                'items'      => [
                    [
                        'is_article'   => true,
                        'id'           => $martillo->id,
                        'price_vender' => $precio,
                        'amount'       => $cantidad,
                    ],
                ],
            ]
        );

        $filas = ArticlePurchase::where('sale_id', $venta->id)->get();

        $this->assertCount(1, $filas, 'La venta sin combo tiene que dejar exactamente una fila de article_purchases.');

        $fila = $filas->first();

        $this->assertEquals($martillo->id, $fila->article_id);
        $this->assertEquals($deposito->id, $fila->address_id);
        $this->assertEquals($this->canal_sistema_id(), $fila->sale_channel_id);
        $this->assertEquals($cantidad, (float) $fila->amount);
    }
}
