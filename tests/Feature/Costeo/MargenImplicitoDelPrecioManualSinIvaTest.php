<?php

namespace Tests\Feature\Costeo;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\SaleTax;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Mision `margen-implicito-sin-iva` (14/10/2026), T1.8 del centro de recursos: el aviso «Ese precio
 * implica un margen del X %» de un precio fijado a mano comparaba el precio (lo que paga el
 * cliente, con el IVA adentro) contra `base_margen` (neto). Con una base de 2.263,64 y IVA 21 %,
 * un precio de 3.600 decia ~59 % y el margen real es 31,45 %.
 *
 * El back (renglon «Margen implicito» del desglose) y el front (que divide con
 * `base_margen_final`) tienen que decir lo mismo, y los dos tienen que medir en la misma magnitud.
 *
 * @group costeo-precios
 */
class MargenImplicitoDelPrecioManualSinIvaTest extends TestCase
{
    use DatabaseTransactions;

    const DELTA = 0.01;
    const COSTO = 1000;

    /** @var \App\Models\User */
    protected $user;

    /** @var array<string,mixed> */
    protected $condicion_original = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->condicion_original = [
            'usar_condicion_fiscal_en_costeo' => $this->user->usar_condicion_fiscal_en_costeo,
            'condicion_iva_precios'           => $this->user->condicion_iva_precios,
            'listas_de_precio'                => $this->user->listas_de_precio,
            'aplicar_iva_al_costo'            => $this->user->aplicar_iva_al_costo,
        ];

        SaleTax::where('user_id', 500)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && count($this->condicion_original) > 0) {
            foreach ($this->condicion_original as $campo => $valor) {
                $this->user->{$campo} = $valor;
            }
            $this->user->save();
        }

        parent::tearDown();
    }

    protected function cuenta_rrii()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 1;
        $this->user->condicion_iva_precios = 'RRII';
        $this->user->listas_de_precio = 0;
        $this->user->save();

        return User::find(500);
    }

    protected function articulo_con_precio_manual($precio, $porcentaje_iva = 21, $aplicar_iva = 1)
    {
        $iva = Iva::where('percentage', $porcentaje_iva)->first();

        if (is_null($iva)) {
            $this->markTestSkipped('La base de testing no tiene el IVA de '.$porcentaje_iva.'% sembrado.');
        }

        $article = Article::create([
            'name'            => 'zz Articulo margen implicito',
            'user_id'         => 500,
            'cost'            => self::COSTO,
            'iva_id'          => $iva->id,
            'aplicar_iva'     => $aplicar_iva,
            'price'           => $precio,
            'percentage_gain' => null,
            'status'          => 'active',
        ]);

        return Article::with('iva')->find($article->id);
    }

    /**
     * El margen que dice el renglon «Margen implicito» del desglose, como numero.
     */
    protected function margen_del_desglose($article, $user)
    {
        // El desglose solo se devuelve en el camino que guarda (guardar_cambios = true).
        $des = ArticleHelper::setFinalPrice($article, null, $user, null, true, null, true);

        foreach ($des as $linea) {
            if (isset($linea['etiqueta']) && $linea['etiqueta'] === 'Margen implícito') {
                return (float) str_replace(',', '.', str_replace('%', '', $linea['valor']));
            }
        }

        return null;
    }

    /**
     * Lo que calcula el front: (precio - base_margen_final) / base_margen_final * 100.
     */
    protected function margen_del_front($article, $precio)
    {
        $base = (float) $article->base_margen_final;

        return ($precio - $base) / $base * 100;
    }

    /**
     * @test
     */
    public function con_iva_21_el_margen_se_mide_sin_el_iva_del_precio()
    {
        $user = $this->cuenta_rrii();
        $article = $this->articulo_con_precio_manual(3600, 21);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);

        $base = (float) $res['base_margen'];
        $this->assertGreaterThan(0, $base, 'hace falta una base para medir el margen');

        // La base «en precio de venta» es la neta con el IVA encima.
        $this->assertEqualsWithDelta($base * 1.21, (float) $res['base_margen_final'], self::DELTA);

        $esperado = (3600 / 1.21 - $base) / $base * 100;
        $inflado  = (3600 - $base) / $base * 100;

        $article = Article::with('iva')->find($article->id);
        $this->assertEqualsWithDelta($esperado, $this->margen_del_desglose($article, $user), self::DELTA, 'el desglose del back mide sin IVA');

        $article->base_margen_final = $res['base_margen_final'];
        $this->assertEqualsWithDelta($esperado, $this->margen_del_front($article, 3600), self::DELTA, 'el front y el back dicen lo mismo');

        $this->assertGreaterThan(1, abs($inflado - $esperado), 'el viejo calculo daba otro numero: este test tiene que distinguirlos');
    }

    /**
     * @test
     */
    public function con_iva_10_5_se_saca_esa_alicuota_y_no_la_del_21()
    {
        $user = $this->cuenta_rrii();
        $article = $this->articulo_con_precio_manual(3000, 10.5);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);
        $base = (float) $res['base_margen'];

        $this->assertEqualsWithDelta($base * 1.105, (float) $res['base_margen_final'], self::DELTA);

        $article = Article::with('iva')->find($article->id);
        $this->assertEqualsWithDelta((3000 / 1.105 - $base) / $base * 100, $this->margen_del_desglose($article, $user), self::DELTA);
    }

    /**
     * @test
     */
    public function un_articulo_sin_iva_aplicable_mide_el_precio_tal_cual()
    {
        $user = $this->cuenta_rrii();
        $article = $this->articulo_con_precio_manual(3000, 21, 0);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);
        $base = (float) $res['base_margen'];

        // aplicar_iva = 0: el sistema no le suma IVA a este articulo, asi que tampoco hay que sacarselo.
        $this->assertEqualsWithDelta($base, (float) $res['base_margen_final'], self::DELTA);

        $article = Article::with('iva')->find($article->id);
        $this->assertEqualsWithDelta((3000 - $base) / $base * 100, $this->margen_del_desglose($article, $user), self::DELTA);
    }

    /**
     * @test
     */
    public function un_impuesto_sobre_ventas_tambien_sale_del_precio()
    {
        $user = $this->cuenta_rrii();

        SaleTax::create([
            'name'         => 'zz Ingresos Brutos margen implicito',
            'percentage'   => 3,
            'user_id'      => 500,
            'apply_to_all' => 1,
            'activo'       => 1,
        ]);
        ArticlePricesHelper::$sale_taxes_cache = [];

        $article = $this->articulo_con_precio_manual(3600, 21);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);
        $base = (float) $res['base_margen'];

        $neto = 3600 / 1.21 * (1 - 0.03);

        $this->assertEqualsWithDelta($base / (1 / 1.21 * (1 - 0.03)), (float) $res['base_margen_final'], self::DELTA);

        $article = Article::with('iva')->find($article->id);
        $this->assertEqualsWithDelta(($neto - $base) / $base * 100, $this->margen_del_desglose($article, $user), self::DELTA);
    }

    /**
     * @test
     */
    public function en_una_cuenta_legacy_con_el_iva_al_costo_no_se_saca_nada()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 0;
        $this->user->aplicar_iva_al_costo = 1;
        $this->user->listas_de_precio = 0;
        $this->user->save();

        $user = User::find(500);
        $article = $this->articulo_con_precio_manual(3600, 21);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);
        $base = (float) $res['base_margen'];

        // El IVA ya esta en el costo (y por lo tanto en la base): precio y base estan en la misma magnitud.
        $this->assertEqualsWithDelta($base, (float) $res['base_margen_final'], self::DELTA);

        $article = Article::with('iva')->find($article->id);
        $this->assertEqualsWithDelta((3600 - $base) / $base * 100, $this->margen_del_desglose($article, $user), self::DELTA);
    }

    /**
     * @test
     */
    public function un_articulo_sin_costo_no_tiene_base_ni_margen_que_mostrar()
    {
        $user = $this->cuenta_rrii();

        $article = Article::create([
            'name'            => 'zz Articulo sin costo margen implicito',
            'user_id'         => 500,
            'cost'            => null,
            'price'           => 3000,
            'percentage_gain' => null,
            'status'          => 'active',
        ]);
        $article = Article::with('iva')->find($article->id);

        $res = ArticleHelper::setFinalPrice($article, null, $user, null, false);

        $this->assertNull($res['base_margen']);
        $this->assertNull($res['base_margen_final']);
    }
}
