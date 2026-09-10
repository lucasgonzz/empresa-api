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
 * Mision `precio-manual-no-suma-iva` (10/9/2026), pedido de Lucas: si un usuario carga el precio
 * manual de un articulo, ese precio se usa TAL CUAL para vender, sin sumarle impuestos sobre
 * ventas ni IVA por encima -- aunque la cuenta tenga activo "usar_condicion_fiscal_en_costeo" y
 * sea Responsable Inscripto (donde el IVA se suma al vender, no al costo).
 *
 * Antes de esta mision, ArticleHelper::setFinalPrice() dejaba pasar el precio manual
 * ($article->price, sin percentage_gain y sin listas de precio) por el bloque comun de
 * aplicar_sale_taxes() / aplicar_iva() al final del pipeline, que no distinguia la rama de precio
 * manual de la rama calculada. El mismo invariante ya estaba resuelto para las listas de precio
 * (ver CascadaDePreciosTest::precio_manual_no_se_toca_prompt_02) pero nunca se replico aca.
 *
 * @group costeo-precios
 */
class PrecioManualNoSumaImpuestosNiIvaTest extends TestCase
{
    use DatabaseTransactions;

    const DELTA = 0.01;
    const COSTO = 1000;
    const PRECIO_MANUAL = 3000;

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
        ];

        /**
         * Se limpian los sale_taxes del usuario para partir de un escenario conocido y que cada
         * test agregue el que necesita (mismo criterio que MargenDeUnPrecioFijadoAManoTest).
         * DatabaseTransactions revierte el borrado al terminar el test.
         */
        SaleTax::where('user_id', 500)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && count($this->condicion_original) > 0) {
            $this->user->usar_condicion_fiscal_en_costeo = $this->condicion_original['usar_condicion_fiscal_en_costeo'];
            $this->user->condicion_iva_precios           = $this->condicion_original['condicion_iva_precios'];
            $this->user->listas_de_precio                = $this->condicion_original['listas_de_precio'];
            $this->user->save();
        }

        parent::tearDown();
    }

    /**
     * @return \App\Models\User
     */
    protected function cuenta_responsable_inscripto_sin_listas()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 1;
        $this->user->condicion_iva_precios = 'RRII';
        $this->user->listas_de_precio = 0;
        $this->user->save();

        return User::find(500);
    }

    /**
     * Articulo con IVA 21%, costo cargado y un precio manual fijado (percentage_gain vacio, para
     * que setFinalPrice() entre por la rama de precio manual y no por la de margen/costo).
     *
     * @param float $precio_manual
     * @return \App\Models\Article
     */
    protected function articulo_con_precio_manual($precio_manual)
    {
        $iva_21 = Iva::where('percentage', 21)->first();

        if (is_null($iva_21)) {
            $this->markTestSkipped('La base de testing no tiene el IVA de 21% sembrado.');
        }

        $article = Article::create([
            'name'            => 'zz Articulo precio manual RRII',
            'user_id'         => 500,
            'cost'            => self::COSTO,
            'iva_id'          => $iva_21->id,
            'aplicar_iva'     => 1,
            'price'           => $precio_manual,
            'percentage_gain' => null,
            'status'          => 'active',
        ]);

        return Article::with('iva')->find($article->id);
    }

    /**
     * Criterio 1: cuenta RRII con "usar_condicion_fiscal_en_costeo" activo (el IVA se suma al
     * vender, no al costo) -- el precio manual tiene que quedar EXACTO, sin el 21% de IVA encima.
     *
     * @group costeo-precios
     * @test
     */
    public function el_precio_manual_no_suma_iva_en_una_cuenta_rrii()
    {
        $user = $this->cuenta_responsable_inscripto_sin_listas();
        $article = $this->articulo_con_precio_manual(self::PRECIO_MANUAL);

        $resultado = ArticleHelper::setFinalPrice($article, null, $user, null, false);

        $this->assertEqualsWithDelta(
            self::PRECIO_MANUAL,
            (float) $resultado['final_price'],
            self::DELTA,
            'el precio manual no se tiene que tocar: nada de IVA por encima'
        );
    }

    /**
     * Criterio 2: lo mismo con un impuesto sobre ventas (SaleTax, ej. IIBB) activo y con
     * apply_to_all -- tampoco se le suma al precio manual.
     *
     * @group costeo-precios
     * @test
     */
    public function el_precio_manual_no_suma_impuestos_sobre_ventas()
    {
        $user = $this->cuenta_responsable_inscripto_sin_listas();

        SaleTax::create([
            'name'         => 'zz Ingresos Brutos precio manual',
            'percentage'   => 3,
            'user_id'      => 500,
            'apply_to_all' => 1,
            'activo'       => 1,
        ]);
        ArticlePricesHelper::$sale_taxes_cache = [];

        $article = $this->articulo_con_precio_manual(self::PRECIO_MANUAL);

        $resultado = ArticleHelper::setFinalPrice($article, null, $user, null, false);

        $this->assertEqualsWithDelta(
            self::PRECIO_MANUAL,
            (float) $resultado['final_price'],
            self::DELTA,
            'el precio manual no se tiene que tocar: nada de impuesto sobre ventas por encima'
        );
    }

    /**
     * Criterio 3 (control): una cuenta legacy, sin migrar (usar_condicion_fiscal_en_costeo = 0)
     * con la tilde vieja "aplicar_iva_al_costo" apagada reproduce el mismo escenario -- el bug no
     * era exclusivo de la migracion RRII/MT, sino de cualquier cuenta donde el IVA se sume al
     * vender en vez de al costo.
     *
     * @group costeo-precios
     * @test
     */
    public function el_precio_manual_no_suma_iva_en_una_cuenta_legacy_sin_iva_al_costo()
    {
        $this->user->usar_condicion_fiscal_en_costeo = 0;
        $this->user->aplicar_iva_al_costo = 0;
        $this->user->listas_de_precio = 0;
        $this->user->save();

        $user = User::find(500);
        $article = $this->articulo_con_precio_manual(self::PRECIO_MANUAL);

        $resultado = ArticleHelper::setFinalPrice($article, null, $user, null, false);

        $this->assertEqualsWithDelta(
            self::PRECIO_MANUAL,
            (float) $resultado['final_price'],
            self::DELTA,
            'el precio manual no se tiene que tocar tampoco en una cuenta legacy con el IVA fuera del costo'
        );
    }
}
