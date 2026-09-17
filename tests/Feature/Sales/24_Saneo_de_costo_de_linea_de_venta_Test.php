<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `sale:sanear-costo-de-linea` repara el historico de `article_sale.cost` (mision
 * saneo-ganancia-ventas, 17/9/2026).
 *
 * Lo que este test protege, que es lo unico que hace seguro correr el saneo sobre la base de un
 * negocio real:
 *
 *   1. corrige la linea que `set_costo_ventas` dejo con el costo TOTAL en la columna unitaria;
 *   2. no toca la linea sana que esta al lado, en la misma venta;
 *   3. 🔴 no toca una venta legitima a perdida — la detecta por la FIRMA de la ganancia, no
 *      porque el costo "parezca" alto, asi que un costo mayor al precio por si solo nunca
 *      alcanza para escribir;
 *   4. corrige el costo del bulto entero de un articulo con `unidades_individuales`;
 *   5. sin `--aplicar` no escribe una sola fila;
 *   6. el `.sql` de reversion devuelve los valores exactos que habia antes.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes
 * y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Saneo_de_costo_de_linea_de_venta_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    /**
     * Carpeta donde el comando deja el respaldo y el SQL de reversion de cada corrida.
     *
     * @var string
     */
    protected $carpeta_de_salida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->carpeta_de_salida = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saneo-costo-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->carpeta_de_salida)) {
            foreach ((array) glob($this->carpeta_de_salida . DIRECTORY_SEPARATOR . '*') as $archivo) {
                unlink($archivo);
            }

            rmdir($this->carpeta_de_salida);
        }

        parent::tearDown();
    }

    /**
     * @param  array  $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props = [])
    {
        $article = new Article();

        $article->user_id = $this->user->id;
        $article->name = 'ZZ Test saneo costo ' . uniqid();
        $article->status = 'active';
        $article->iva_id = 2;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        return $article;
    }

    /**
     * @param  array  $props
     * @return \App\Models\Sale
     */
    protected function crear_venta(array $props = [])
    {
        $sale = new Sale();

        $sale->user_id = $this->user->id;
        $sale->total = 20000;
        $sale->terminada = 1;

        foreach ($props as $campo => $valor) {
            $sale->$campo = $valor;
        }

        $sale->save();

        return $sale;
    }

    /**
     * Adjunta una linea con los valores crudos que se quieren sembrar (no pasa por
     * `attachArticle`: el test necesita poder sembrar a proposito el estado roto).
     *
     * @param  \App\Models\Sale  $sale
     * @param  \App\Models\Article  $article
     * @param  float  $amount
     * @param  float  $price
     * @param  float  $cost
     * @param  float  $ganancia
     * @return void
     */
    protected function adjuntar_linea($sale, $article, $amount, $price, $cost, $ganancia)
    {
        $sale->articles()->attach($article->id, [
            'amount' => $amount,
            'price' => $price,
            'cost' => $cost,
            'ganancia' => $ganancia,
        ]);
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  \App\Models\Article  $article
     * @return object
     */
    protected function linea($sale, $article)
    {
        return DB::table('article_sale')
            ->where('sale_id', $sale->id)
            ->where('article_id', $article->id)
            ->first();
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  bool  $aplicar
     * @return void
     */
    protected function correr_saneo($sale, $aplicar)
    {
        $parametros = [
            '--user_id' => $this->user->id,
            '--sale_id' => $sale->id,
            '--salida' => $this->carpeta_de_salida,
        ];

        if ($aplicar) {
            $parametros['--aplicar'] = true;
        }

        Artisan::call('sale:sanear-costo-de-linea', $parametros);
    }

    /**
     * @return array
     */
    protected function respaldo_json()
    {
        $archivos = (array) glob($this->carpeta_de_salida . DIRECTORY_SEPARATOR . '*.json');

        $this->assertNotEmpty($archivos, 'El comando no dejo el respaldo JSON');

        return json_decode(file_get_contents($archivos[0]), true);
    }

    /**
     * @return string
     */
    protected function sql_de_reversion()
    {
        $archivos = (array) glob($this->carpeta_de_salida . DIRECTORY_SEPARATOR . '*-reversion.sql');

        $this->assertNotEmpty($archivos, 'El comando no dejo el SQL de reversion');

        return file_get_contents($archivos[0]);
    }

    /**
     * Venta con las tres situaciones a la vez: una linea rota por `set_costo_ventas`, una sana y
     * una venta legitima a perdida.
     *
     * Linea rota: costo unitario real 850, cantidad 15, precio 955. El comando viejo guardaba
     * cost = 850 x 15 = 12750 y ganancia = 955 x 15 − 12750 = 1575, que es la firma que delata
     * quien escribio la fila.
     *
     * @return array
     */
    protected function sembrar_venta_con_las_tres_situaciones()
    {
        $sale = $this->crear_venta(['total_cost' => 999999, 'ganancia' => -979999]);

        $rota = $this->crear_articulo(['costo_real' => 850]);
        $sana = $this->crear_articulo(['costo_real' => 60]);
        $a_perdida = $this->crear_articulo(['costo_real' => 500]);

        $this->adjuntar_linea($sale, $rota, 15, 955, 12750, 1575);
        $this->adjuntar_linea($sale, $sana, 3, 100, 60, 120);
        $this->adjuntar_linea($sale, $a_perdida, 2, 100, 500, -800);

        return [
            'sale' => $sale,
            'rota' => $rota,
            'sana' => $sana,
            'a_perdida' => $a_perdida,
        ];
    }

    /**
     * @group sales
     * @test
     */
    public function corrige_el_costo_inflado_por_la_cantidad_y_deja_intacta_la_linea_sana()
    {
        $escenario = $this->sembrar_venta_con_las_tres_situaciones();

        $this->correr_saneo($escenario['sale'], true);

        $rota = $this->linea($escenario['sale'], $escenario['rota']);

        $this->assertEquals(
            850,
            (float) $rota->cost,
            'La linea rota tenia el costo TOTAL (850 x 15) en la columna unitaria y el saneo no lo dividio'
        );

        $this->assertEquals(
            (955 - 850) * 15,
            (float) $rota->ganancia,
            'La ganancia de la linea corregida tiene que ser (precio − costo unitario) x cantidad'
        );

        $sana = $this->linea($escenario['sale'], $escenario['sana']);

        $this->assertEquals(60, (float) $sana->cost, 'El saneo toco una linea que estaba sana');
        $this->assertEquals(120, (float) $sana->ganancia, 'El saneo toco la ganancia de una linea sana');

        $escenario['sale']->refresh();

        $this->assertEquals(
            850 * 15 + 60 * 3 + 500 * 2,
            (float) $escenario['sale']->total_cost,
            'sales.total_cost tiene que quedar en la suma de costo_unitario x cantidad de todas las lineas'
        );

        $this->assertNotNull(
            $escenario['sale']->ganancia,
            'sales.ganancia tiene que quedar recalculada despues del saneo'
        );
    }

    /**
     * 🔴 Una venta a perdida es un dato real de un negocio, no un defecto. El costo mayor al
     * precio no alcanza para tocar nada: hace falta la firma de la causa B o las unidades
     * individuales de la causa A.
     *
     * @group sales
     * @test
     */
    public function no_toca_una_venta_legitima_a_perdida()
    {
        $escenario = $this->sembrar_venta_con_las_tres_situaciones();

        $this->correr_saneo($escenario['sale'], true);

        $a_perdida = $this->linea($escenario['sale'], $escenario['a_perdida']);

        $this->assertEquals(
            500,
            (float) $a_perdida->cost,
            'El saneo corrigio una venta legitima a perdida: el costo mayor al precio no es prueba de nada'
        );

        $this->assertEquals(
            -800,
            (float) $a_perdida->ganancia,
            'El saneo reescribio la ganancia de una venta legitima a perdida'
        );

        $json = $this->respaldo_json();

        $this->assertArrayHasKey(
            'costo_incoherente_sin_causa_identificada',
            $json['descartes_por_motivo'],
            'La linea que se dejo afuera tiene que quedar listada con su motivo'
        );
    }

    /**
     * Causa A: el costo del bulto entero guardado como costo unitario (articulo con
     * `unidades_individuales`, defecto de `vender_presupuestos.js` diagnosticado el 1/9/2026).
     *
     * @group sales
     * @test
     */
    public function corrige_el_costo_sin_dividir_por_unidades_individuales()
    {
        $article = $this->crear_articulo(['costo_real' => 1000, 'unidades_individuales' => 10]);

        $sale = $this->crear_venta();

        /** Costo del bulto (1000) guardado como unitario, con la ganancia sana de ese costo. */
        $this->adjuntar_linea($sale, $article, 2, 150, 1000, (150 - 1000) * 2);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(
            100,
            (float) $linea->cost,
            'El costo del bulto tenia que quedar dividido por unidades_individuales'
        );

        $this->assertEquals(
            (150 - 100) * 2,
            (float) $linea->ganancia,
            'La ganancia de la linea corregida tiene que ser (precio − costo unitario) x cantidad'
        );

        $sale->refresh();

        $this->assertEquals(100 * 2, (float) $sale->total_cost);
    }

    /**
     * Las dos causas apiladas en la misma linea: un articulo de bulto cuyo costo quedo sin
     * dividir (causa A) y que despues paso por `set_costo_ventas` (causa B).
     *
     * Costo unitario real 100, bulto de 10 (costo 1000), cantidad 2, precio 150. El comando
     * viejo dejo cost = 1000 x 2 = 2000 y ganancia = 150 x 2 − 2000 = −1700.
     *
     * Es el caso que delata si el buscador de k se come la division de las unidades
     * individuales: dividiendo solo por la cantidad hasta que "cierre" con el precio, la linea
     * terminaria en 250 en vez de 100.
     *
     * @group sales
     * @test
     */
    public function corrige_la_linea_que_tiene_las_dos_causas_encima()
    {
        $article = $this->crear_articulo(['costo_real' => 1000, 'unidades_individuales' => 10]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea($sale, $article, 2, 150, 2000, 150 * 2 - 2000);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(
            100,
            (float) $linea->cost,
            'La linea con las dos causas encima tiene que terminar en el costo unitario real'
        );

        $this->assertEquals((150 - 100) * 2, (float) $linea->ganancia);

        $json = $this->respaldo_json();

        $this->assertEquals(
            ['B', 'A'],
            $json['correcciones'][0]['causas'],
            'El respaldo tiene que decir que se aplicaron las dos causas'
        );

        $this->assertEquals(1, $json['correcciones'][0]['k']);
    }

    /**
     * @group sales
     * @test
     */
    public function sin_aplicar_no_escribe_ninguna_fila()
    {
        $escenario = $this->sembrar_venta_con_las_tres_situaciones();

        $this->correr_saneo($escenario['sale'], false);

        $rota = $this->linea($escenario['sale'], $escenario['rota']);

        $this->assertEquals(
            12750,
            (float) $rota->cost,
            'El dry-run escribio el costo de la linea'
        );

        $this->assertEquals(1575, (float) $rota->ganancia, 'El dry-run escribio la ganancia de la linea');

        $escenario['sale']->refresh();

        $this->assertEquals(
            999999,
            (float) $escenario['sale']->total_cost,
            'El dry-run escribio el total_cost de la venta'
        );

        $json = $this->respaldo_json();

        $this->assertEquals('dry-run', $json['modo']);
        $this->assertCount(1, $json['correcciones'], 'El dry-run tiene que reportar la unica linea que corregiria');
    }

    /**
     * El respaldo no sirve si no se puede volver: se corre el SQL de reversion generado y la
     * base tiene que quedar como estaba, con los valores exactos.
     *
     * Se saltean las lineas de control de transaccion del archivo porque el test ya corre dentro
     * de una (DatabaseTransactions): un COMMIT de adentro dejaria los datos sembrados en la base.
     *
     * @group sales
     * @test
     */
    public function el_sql_de_reversion_devuelve_los_valores_exactos()
    {
        $escenario = $this->sembrar_venta_con_las_tres_situaciones();

        $this->correr_saneo($escenario['sale'], true);

        $sql = $this->sql_de_reversion();

        $this->assertStringContainsString(
            'UPDATE article_sale SET cost = 12750.00, ganancia = 1575.00',
            $sql,
            'El SQL de reversion tiene que traer los valores exactos que habia antes, con todos los decimales'
        );

        foreach (explode("\n", $sql) as $sentencia) {
            $sentencia = trim($sentencia);

            if (strpos($sentencia, 'UPDATE ') !== 0) {
                continue;
            }

            DB::statement(rtrim($sentencia, ';'));
        }

        $rota = $this->linea($escenario['sale'], $escenario['rota']);

        $this->assertEquals(12750, (float) $rota->cost, 'La reversion no devolvio el costo anterior');
        $this->assertEquals(1575, (float) $rota->ganancia, 'La reversion no devolvio la ganancia anterior');

        $escenario['sale']->refresh();

        $this->assertEquals(
            999999,
            (float) $escenario['sale']->total_cost,
            'La reversion no devolvio el total_cost anterior de la venta'
        );
    }
}
