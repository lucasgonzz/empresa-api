<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\sale\CostoDeLineaDeVentaHelper;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Causa C del saneo de `article_sale.cost` (mision ganancia-negativa-ferretotal, 30/9/2026).
 *
 * Caso real: ferretotal, venta 8050 (ganancia −4.605.962.572). Una linea con el costo TOTAL
 * (unitario × cantidad × unidades del bulto) cuya ganancia ya cumple la firma SANA
 * `(precio − costo) × cantidad`: `set_costo_ventas` dejo el costo total y un recalculo posterior de
 * la ganancia la reescribio, asi que ninguna firma la delata y el comando la descartaba.
 *
 * Lo que este test protege:
 *
 *   1. corrige la linea cuyo costo reconstruido cierra con el precio Y con la ficha de hoy;
 *   2. la corrige tambien cuando ademas hay unidades individuales (el caso de la venta 8050);
 *   3. 🔴 NO toca una venta legitima a perdida: si el costo guardado tal cual encaja con la ficha, o
 *      si dividir lo deja lejos de la ficha, queda como esta;
 *   4. 🔴 NO decide si es ambiguo (cantidad 2 en que los dos costos encajan con la ficha);
 *   5. sin `costo_real` en la ficha no se adivina;
 *   6. `--causa=a` / `--causa=b` no la aplican (la C es opt-in por causa y entra en `todas`);
 *   7. el helper con los 4 argumentos de siempre (la guarda de `set_costo_ventas`) responde IGUAL que
 *      antes: la C esta apagada por defecto;
 *   8. sin `--aplicar` no escribe una sola fila, y el respaldo nombra la causa C.
 *
 * DatabaseTransactions: la base de testing del slot esta sembrada y un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class Saneo_causa_c_costo_total_con_firma_sana_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    /** @var string */
    protected $carpeta_de_salida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->carpeta_de_salida = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saneo-causa-c-test-' . uniqid();
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
     * @param  array $props
     * @return \App\Models\Article
     */
    protected function crear_articulo(array $props = [])
    {
        $article = new Article();

        $article->user_id = $this->user->id;
        $article->name = 'ZZ Test causa C ' . uniqid();
        $article->status = 'active';
        $article->iva_id = 2;

        foreach ($props as $campo => $valor) {
            $article->$campo = $valor;
        }

        $article->save();

        return $article;
    }

    /**
     * @return \App\Models\Sale
     */
    protected function crear_venta()
    {
        $sale = new Sale();

        $sale->user_id = $this->user->id;
        $sale->total = 20000;
        $sale->terminada = 1;
        $sale->save();

        return $sale;
    }

    /**
     * Siembra a proposito el estado roto: costo TOTAL con la ganancia sana de ese costo.
     *
     * @param  \App\Models\Sale $sale
     * @param  \App\Models\Article $article
     * @param  float $amount
     * @param  float $price
     * @param  float $cost
     * @return void
     */
    protected function adjuntar_linea_sana($sale, $article, $amount, $price, $cost)
    {
        $sale->articles()->attach($article->id, [
            'amount' => $amount,
            'price' => $price,
            'cost' => $cost,
            'ganancia' => ($price - $cost) * $amount,
        ]);
    }

    /**
     * @param  \App\Models\Sale $sale
     * @param  \App\Models\Article $article
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
     * @param  \App\Models\Sale $sale
     * @param  bool $aplicar
     * @param  string $causa
     * @return int
     */
    protected function correr_saneo($sale, $aplicar, $causa = 'todas')
    {
        $parametros = [
            '--user_id' => $this->user->id,
            '--sale_id' => $sale->id,
            '--causa' => $causa,
            '--salida' => $this->carpeta_de_salida,
        ];

        if ($aplicar) {
            $parametros['--aplicar'] = true;
        }

        return Artisan::call('sale:sanear-costo-de-linea', $parametros);
    }

    /**
     * Fila con la forma que devuelve `CostoDeLineaDeVentaHelper::query_de_lineas()`.
     *
     * @param  float $amount
     * @param  float $price
     * @param  float $cost
     * @param  float|null $unidades
     * @param  float|null $costo_real
     * @return object
     */
    protected function fila($amount, $price, $cost, $unidades, $costo_real)
    {
        return (object) [
            // Ids que no existen: la condicion 6 (otra linea con el mismo costo) no encuentra nada.
            'linea_id' => -1,
            'sale_id' => -1,
            'article_id' => -1,
            'amount' => $amount,
            'price' => $price,
            'cost' => $cost,
            'ganancia' => ($price - $cost) * $amount,
            'unidades_del_articulo' => $unidades,
            'unidades_de_la_linea' => null,
            'costo_real_del_articulo' => $costo_real,
            'venta_num' => 1,
            'venta_fecha' => '2026-01-01 10:00:00',
            'venta_to_check' => 0,
            'venta_checked' => 0,
        ];
    }

    /**
     * Costo unitario 1000 (ficha), cantidad 6, precio 1500 (margen 1,50). El costo guardado es el
     * total: 1000 × 6 = 6000, y la ganancia es la sana de ese costo: (1500 − 6000) × 6 = −27000.
     *
     * @group sales
     * @test
     */
    public function corrige_el_costo_total_cuando_cierra_con_el_precio_y_con_la_ficha()
    {
        $article = $this->crear_articulo(['costo_real' => 1000]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 6, 1500, 6000);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(1000, (float) $linea->cost, 'El costo tenia que quedar en el unitario (6000 / 6)');
        $this->assertEquals((1500 - 1000) * 6, (float) $linea->ganancia);

        $sale->refresh();

        $this->assertEquals(1000 * 6, (float) $sale->total_cost, 'total_cost = costo unitario × cantidad');
    }

    /**
     * El caso de la venta 8050: el articulo es un bulto de 10 unidades (costo_real 10000) y el costo
     * guardado es el del bulto × la cantidad: 10000 × 4 = 40000. Dividir solo por las unidades
     * (4000) no alcanza contra el precio (1500): la causa A no puede y la C reconstruye 1000.
     *
     * @group sales
     * @test
     */
    public function corrige_el_costo_total_de_un_articulo_con_unidades_individuales()
    {
        $article = $this->crear_articulo(['costo_real' => 10000, 'unidades_individuales' => 10]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 4, 1500, 40000);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(1000, (float) $linea->cost, '40000 / 4 cantidad / 10 unidades = 1000');
        $this->assertEquals((1500 - 1000) * 4, (float) $linea->ganancia);
    }

    /**
     * Venta legitima a perdida FUERTE: costo unitario real 3000 (la ficha sigue diciendo 3000), precio
     * 1000, cantidad 3. El costo crudo (ratio 1,0) encaja con la ficha; el candidato (1000, ratio 0,33)
     * queda fuera de la banda: no se toca.
     *
     * @group sales
     * @test
     */
    public function no_toca_una_venta_legitima_a_perdida_fuerte()
    {
        $article = $this->crear_articulo(['costo_real' => 3000]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 3, 1000, 3000);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(3000, (float) $linea->cost, 'Una perdida real no se corrige');
        $this->assertEquals((1000 - 3000) * 3, (float) $linea->ganancia);
    }

    /**
     * Ambiguo: cantidad 2, costo 3000, precio 1000, ficha 3000. Tanto el costo crudo (ratio 1,0) como
     * el dividido por 2 (1500, ratio 0,5) encajan con la ficha: no se adivina.
     *
     * @group sales
     * @test
     */
    public function no_adivina_cuando_el_costo_crudo_y_el_dividido_encajan_con_la_ficha()
    {
        $article = $this->crear_articulo(['costo_real' => 3000]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 2, 1000, 3000);

        $this->correr_saneo($sale, true);

        $linea = $this->linea($sale, $article);

        $this->assertEquals(3000, (float) $linea->cost, 'Ambiguo: queda como estaba');
    }

    /**
     * Sin `costo_real` en la ficha no hay con que contrastar: no se adivina.
     *
     * @group sales
     * @test
     */
    public function no_toca_si_la_ficha_no_tiene_costo_real()
    {
        $article = $this->crear_articulo(['costo_real' => 0]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 6, 1500, 6000);

        $this->correr_saneo($sale, true);

        $this->assertEquals(6000, (float) $this->linea($sale, $article)->cost);
    }

    /**
     * La C entra en `todas` pero no en `--causa=a` ni en `--causa=b`.
     *
     * @group sales
     * @test
     */
    public function las_causas_a_y_b_no_aplican_la_c()
    {
        $article = $this->crear_articulo(['costo_real' => 1000]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 6, 1500, 6000);

        $this->correr_saneo($sale, true, 'a');
        $this->assertEquals(6000, (float) $this->linea($sale, $article)->cost, '--causa=a no debe aplicar la C');

        $this->correr_saneo($sale, true, 'b');
        $this->assertEquals(6000, (float) $this->linea($sale, $article)->cost, '--causa=b no debe aplicar la C');

        $this->correr_saneo($sale, true, 'c');
        $this->assertEquals(1000, (float) $this->linea($sale, $article)->cost, '--causa=c si la aplica');
    }

    /**
     * La guarda de `set_costo_ventas` llama a `analizar()` con cuatro argumentos: la C esta apagada por
     * defecto y su respuesta no cambia respecto de antes de esta mision.
     *
     * @group sales
     * @test
     */
    public function el_helper_con_cuatro_argumentos_no_aplica_la_c()
    {
        $fila = $this->fila(6, 1500, 6000, null, 1000);

        $cuatro = CostoDeLineaDeVentaHelper::analizar($fila, true, true, 4);
        $cinco = CostoDeLineaDeVentaHelper::analizar($fila, true, true, 4, true);

        $this->assertSame('descartar', $cuatro['accion'], 'Sin la C, la linea se sigue descartando como antes');
        $this->assertSame('costo_incoherente_sin_causa_identificada', $cuatro['motivo']);
        $this->assertFalse(CostoDeLineaDeVentaHelper::corrige_por_causa_b($cuatro));

        $this->assertSame('corregir', $cinco['accion']);
        $this->assertSame(['C'], $cinco['causas']);
        $this->assertEquals(1000, $cinco['cost_final']);
    }

    /**
     * La C no pisa a la B: una linea con la firma del comando viejo sigue siendo de la causa B.
     *
     * @group sales
     * @test
     */
    public function una_linea_con_la_firma_de_la_causa_b_sigue_siendo_b()
    {
        $fila = $this->fila(15, 955, 12750, null, 850);
        $fila->ganancia = 955 * 15 - 12750; // firma del comando viejo, no la sana

        $analisis = CostoDeLineaDeVentaHelper::analizar($fila, true, true, 4, true);

        $this->assertSame('corregir', $analisis['accion']);
        $this->assertSame(['B'], $analisis['causas']);
        $this->assertEquals(850, $analisis['cost_final']);
    }

    /**
     * Bordes de la regla, a nivel helper: cantidad que no supera 1, cantidad fraccionada entre 1 y 2,
     * candidato por encima del doble del precio y venta en deposito (la C corre antes del descarte de
     * deposito, que tiene que atraparla despues).
     *
     * @group sales
     * @test
     */
    public function los_bordes_de_la_regla_no_corrigen_de_mas()
    {
        // amount = 1: con una sola unidad el costo total y el unitario son lo mismo, no hay que dividir.
        $analisis = CostoDeLineaDeVentaHelper::analizar($this->fila(1, 1500, 6000, null, 1000), true, true, 4, true);
        $this->assertNotContains('C', $analisis['causas'], 'Con cantidad 1 no hay nada que dividir');

        // amount = 1,5: es > 1, asi que se evalua; 6000 / 1,5 = 4000 supera el doble del precio (3000).
        $analisis = CostoDeLineaDeVentaHelper::analizar($this->fila(1.5, 1500, 6000, null, 4000), true, true, 4, true);
        $this->assertNotContains('C', $analisis['causas'], 'Un candidato por encima de 2 x precio no se acepta');

        // Venta en deposito: la regla la corrige en papel pero el descarte comun la deja afuera.
        $fila = $this->fila(6, 1500, 6000, null, 1000);
        $fila->venta_to_check = 1;
        $analisis = CostoDeLineaDeVentaHelper::analizar($fila, true, true, 4, true);
        $this->assertSame('descartar', $analisis['accion']);
        $this->assertSame('venta_en_deposito_el_recalculo_blanquearia_su_total_cost', $analisis['motivo']);

        // Pivot con unidades historicas distintas de las del articulo: tampoco se adivina.
        $fila = $this->fila(4, 1500, 40000, 10, 10000);
        $fila->unidades_de_la_linea = 5;
        $analisis = CostoDeLineaDeVentaHelper::analizar($fila, true, true, 4, true);
        $this->assertSame('descartar', $analisis['accion']);
        $this->assertSame('pivot_con_unidades_individuales_historicas_distintas', $analisis['motivo']);
    }

    /**
     * Guardas del verificador independiente (30/9/2026), medidas en ferretotal: una correccion que sigue
     * en perdida no se escribe (linea viva 176494) y un margen resultante fuera de lo creible tampoco.
     *
     * @group sales
     * @test
     */
    public function no_escribe_una_correccion_que_sigue_en_perdida_ni_con_margen_absurdo()
    {
        // Candidato 1000 >= precio 900: seguiria perdiendo plata.
        $analisis = CostoDeLineaDeVentaHelper::analizar($this->fila(6, 900, 6000, null, 1000), true, true, 4, true);
        $this->assertNotContains('C', $analisis['causas'], 'Una correccion que sigue en perdida no se escribe');

        // Candidato 1000 con precio 2500: margen 2,5 > 2,0, sin evidencia.
        $analisis = CostoDeLineaDeVentaHelper::analizar($this->fila(6, 2500, 6000, null, 1000), true, true, 4, true);
        $this->assertNotContains('C', $analisis['causas'], 'Un margen mayor a 2,0 no se acepta');

        // Y el caso sano de siempre (margen 1,5) sigue entrando.
        $analisis = CostoDeLineaDeVentaHelper::analizar($this->fila(6, 1500, 6000, null, 1000), true, true, 4, true);
        $this->assertSame(['C'], $analisis['causas']);
    }

    /**
     * Condicion 6: si otra venta del mismo articulo tiene el MISMO costo con OTRA cantidad, el costo no es
     * proporcional a la cantidad (art. 2147 de ferretotal: 71.002,80 en diez lineas de cantidades 1,5 a
     * 30) y no se toca. Con la misma cantidad no hay contradiccion y se corrige.
     *
     * @group sales
     * @test
     */
    public function no_corrige_si_otra_linea_del_articulo_repite_el_costo_con_otra_cantidad()
    {
        $article = $this->crear_articulo(['costo_real' => 1000]);

        $venta_a = $this->crear_venta();
        $venta_b = $this->crear_venta();

        $this->adjuntar_linea_sana($venta_a, $article, 6, 1500, 6000);
        // Mismo costo guardado, otra cantidad: el 6000 no puede ser "1000 x cantidad" en las dos.
        $this->adjuntar_linea_sana($venta_b, $article, 4, 1500, 6000);

        $this->correr_saneo($venta_a, true, 'c');

        $this->assertEquals(6000, (float) $this->linea($venta_a, $article)->cost, 'Costo repetido con otra cantidad: no se toca');
    }

    /**
     * @group sales
     * @test
     */
    public function corrige_si_la_otra_linea_del_articulo_tiene_la_misma_cantidad_y_el_mismo_costo()
    {
        $article = $this->crear_articulo(['costo_real' => 1000]);

        $venta_a = $this->crear_venta();
        $venta_b = $this->crear_venta();

        $this->adjuntar_linea_sana($venta_a, $article, 6, 1500, 6000);
        $this->adjuntar_linea_sana($venta_b, $article, 6, 1500, 6000);

        $this->correr_saneo($venta_a, true, 'c');

        $this->assertEquals(1000, (float) $this->linea($venta_a, $article)->cost, 'Mismo costo con la misma cantidad es coherente');
    }

    /**
     * Sin `--aplicar` no se escribe nada, y el respaldo dice que la causa fue la C.
     *
     * @group sales
     * @test
     */
    public function el_dry_run_no_escribe_y_el_respaldo_nombra_la_causa_c()
    {
        $article = $this->crear_articulo(['costo_real' => 1000]);

        $sale = $this->crear_venta();

        $this->adjuntar_linea_sana($sale, $article, 6, 1500, 6000);

        $antes = $this->linea($sale, $article);

        $this->correr_saneo($sale, false);

        $despues = $this->linea($sale, $article);

        $this->assertEquals((float) $antes->cost, (float) $despues->cost);
        $this->assertEquals((float) $antes->ganancia, (float) $despues->ganancia);

        $archivos = (array) glob($this->carpeta_de_salida . DIRECTORY_SEPARATOR . '*.json');
        $this->assertNotEmpty($archivos, 'El dry-run tiene que dejar el respaldo');

        $json = json_decode(file_get_contents($archivos[0]), true);
        $this->assertEquals('dry-run', $json['modo']);
        $this->assertCount(1, $json['correcciones']);
        $this->assertEquals(['C'], $json['correcciones'][0]['causas'], 'El respaldo tiene que nombrar la causa C');
    }
}
