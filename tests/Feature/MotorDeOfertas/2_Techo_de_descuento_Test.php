<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\User;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 2: EL TECHO DE DESCUENTO. Es la parte de plata de
 * la misión: un techo mal calculado se le cobra mal a un cliente real.
 *
 * 🔴 ACÁ NO SE HARDCODEA NINGÚN PRECIO. empresa_testing_s2 tiene DOS sale_taxes activos con
 * apply_to_all ("Ingresos Brutos" 3% e "IIBB" 3,5%) — la misma causa de los 4 rojos preexistentes
 * de tests/Feature/Costeo. Todo lo esperado se calcula con los MISMOS helpers que usa el servicio
 * (resolver_precio_de_venta / quitar_iva_y_sale_taxes) o con la fórmula del plan.
 *
 * El test que importa es el del invariante: aplicar el techo deja el precio neto EN el costo, y un
 * punto más lo deja por debajo. Es la prueba directa de que la fórmula es m/(100+m) y no m.
 */
class Techo_de_descuento_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** Tolerancia en pesos para comparar dos caminos del mismo cálculo. */
    const DELTA = 0.01;

    /** @var User */
    protected $user;

    /** @var array Config fiscal del usuario 500 antes de que esta suite la toque */
    protected $config_original = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave vive en el .env.testing.
        config(['services.anthropic.api_key' => null]);

        $this->user = User::find(500);
        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->config_original = [
            'condicion_iva_precios'           => $this->user->condicion_iva_precios,
            'usar_condicion_fiscal_en_costeo' => $this->user->usar_condicion_fiscal_en_costeo,
            'aplicar_iva_al_costo'            => $this->user->aplicar_iva_al_costo,
            'listas_de_precio'                => $this->user->listas_de_precio,
        ];
    }

    protected function tearDown(): void
    {
        // DatabaseTransactions revierte las filas, pero la config fiscal se restaura a mano igual
        // (criterio de CascadaDePreciosTest): el objeto en memoria y las caches estáticas del
        // helper no participan de la transacción.
        if (!is_null($this->user)) {
            foreach ($this->config_original as $columna => $valor) {
                $this->user->{$columna} = $valor;
            }

            $this->user->save();
            ArticlePricesHelper::$sale_taxes_cache = [];
        }

        parent::tearDown();
    }

    /**
     * Artículo del comercio 500 pasado por el camino REAL del sistema (setFinalPrice con
     * $guardar_cambios = true) para que costo_real y final_price queden persistidos como en
     * producción. El servicio después los lee de ahí, nunca los recalcula.
     *
     * @param  array $atributos
     * @return Article
     */
    protected function articulo(array $atributos = [])
    {
        $article = Article::create(array_merge([
            'name'            => 'zz-motor-ofertas-' . uniqid(),
            'user_id'         => 500,
            'cost'            => 1000,
            'percentage_gain' => 30,
            'aplicar_iva'     => 1,
            'iva_id'          => self::IVA_21,
        ], $atributos));

        ArticleHelper::setFinalPrice($article, null, $this->user, null, true);
        return Article::find($article->id);
    }

    /** El precio neto que produce la cadena real, para calcular el esperado sin tipear números. */
    protected function neto_de($article)
    {
        $precio = ArticlePricesHelper::resolver_precio_de_venta($article, $this->user, null);
        return (float) ArticlePricesHelper::quitar_iva_y_sale_taxes($article, $precio['final_price'], $this->user);
    }

    /**
     * 🔴 La tabla de la verdad: el techo es m/(100+m)*100, NO m. El esperado sale de la fórmula.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_tabla_de_la_verdad_del_techo_sale_de_la_formula_y_no_del_margen()
    {
        $tabla = [30 => 23, 50 => 33, 100 => 50];

        foreach ($tabla as $margen => $techo_documentado) {
            $crudo = TechoDeDescuentoService::techo_crudo($margen);

            $this->assertEqualsWithDelta($margen / (100 + $margen) * 100, $crudo, 0.0001);
            $this->assertSame($techo_documentado, (int) floor($crudo), "margen {$margen}%");

            // El punto de todo: si alguien "simplifica" a techo = margen, esto se rompe.
            $this->assertLessThan($margen, $crudo);
        }
    }

    /**
     * 🔴 floor, nunca round: con margen 30,89005% el techo real es 23,6, floor da 23 (correcto)
     * y round daría 24, que ya está por encima del techo y vende bajo costo.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function usa_floor_y_no_round()
    {
        $crudo = TechoDeDescuentoService::techo_crudo(30.890052356020942);

        $this->assertEqualsWithDelta(23.6, $crudo, 0.0001);
        $this->assertSame(23, (int) floor($crudo));
        $this->assertSame(24, (int) round($crudo), 'si esto cambia, el test dejó de contrastar floor contra round');
    }

    /**
     * 🔴 EL TEST CENTRAL DE LA MISIÓN, sobre un artículo real y con los dos sale_taxes puestos:
     * el techo crudo deja el neto exactamente en el costo, el techo entero lo deja en el costo o
     * apenas arriba, un punto más lo deja abajo, y tomar el margen como descuento vende bajo costo.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_techo_deja_el_precio_neto_en_el_costo_y_un_punto_mas_lo_deja_abajo()
    {
        $article   = $this->articulo();
        $resultado = TechoDeDescuentoService::calcular($article, null, $this->user);

        $this->assertNotNull($resultado, 'un artículo con costo y margen tiene que dar techo');

        $neto  = $this->neto_de($article);
        $costo = (float) $article->costo_real;
        $crudo = TechoDeDescuentoService::techo_crudo($resultado['margen']);

        $this->assertEqualsWithDelta($neto, $resultado['precio_neto'], self::DELTA);
        $this->assertEqualsWithDelta(($neto - $costo) / $costo * 100, $resultado['margen'], 0.0001);
        $this->assertEqualsWithDelta($costo, $neto * (1 - $crudo / 100), self::DELTA,
            'el techo crudo tiene que dejar el precio neto EXACTAMENTE en el costo');

        $this->assertGreaterThanOrEqual($costo, $neto * (1 - $resultado['techo'] / 100),
            'el techo entero nunca puede dejar el precio por debajo del costo');

        $this->assertLessThan($costo, $neto * (1 - ($resultado['techo'] + 1) / 100),
            'un punto por encima del techo ya vende bajo costo: si esto no pasa, el techo está flojo');

        $this->assertLessThan($costo, $neto * (1 - $resultado['margen'] / 100),
            'tomar el margen como descuento directo vende bajo costo: por eso el techo es m/(100+m)');
    }

    /**
     * Borde 1 de A.4.1 — precio manual: NO excluye, el margen se deriva del precio contra el costo
     * igual que en ArticlePricesHelper.php:423.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_articulo_con_precio_manual_no_se_excluye_y_el_margen_se_deriva()
    {
        $article   = $this->articulo(['percentage_gain' => null, 'price' => 1800]);
        $resultado = TechoDeDescuentoService::calcular($article, null, $this->user);

        $this->assertNotNull($resultado, 'el precio manual no excluye: el margen se deriva del precio');
        $this->assertGreaterThan(0, $resultado['margen']);
        $this->assertEqualsWithDelta($this->neto_de($article), $resultado['precio_neto'], self::DELTA);
    }

    /**
     * Borde 2 de A.4.1 — sin costo: EXCLUYE. Un descuento sin techo es lo que esta misión impide.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_articulo_sin_costo_se_excluye()
    {
        $article = $this->articulo(['cost' => null, 'percentage_gain' => null, 'price' => 5000]);

        $this->assertNull(TechoDeDescuentoService::calcular($article, null, $this->user));

        $diagnostico = TechoDeDescuentoService::evaluar($article, null, $this->user);
        $this->assertSame(TechoDeDescuentoService::EXCLUIDO_SIN_COSTO, $diagnostico['excluido_por']);
    }

    /**
     * Borde 3 de A.4.1 — Monotributista: NO excluye, se marca. El riesgo del IVA contado dos veces
     * va declarado en el informe y lo amortiguan el floor y el tope absoluto.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_cuenta_monotributista_no_excluye_pero_queda_marcada()
    {
        $article = $this->articulo();

        $this->user->usar_condicion_fiscal_en_costeo = 1;
        $this->user->condicion_iva_precios = User::CONDICION_MT;
        $this->user->save();

        $resultado = TechoDeDescuentoService::evaluar($article, null, $this->user);
        $this->assertNull($resultado['excluido_por'], 'Monotributista NO excluye');
        $this->assertTrue($resultado['marca_monotributista'], 'pero tiene que quedar marcado');
    }

    /**
     * Borde 4 de A.4.1 — dólares con listas: EXCLUYE. cotizar() no corre ahí, el costo queda en
     * dólares y el precio en pesos, y compararlos es una resta sin sentido.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function un_articulo_en_dolares_con_listas_de_precio_se_excluye()
    {
        $article = $this->articulo(['cost_in_dollars' => 1]);

        // Sin listas, el mismo artículo en dólares SÍ entra: la exclusión es de la combinación.
        $this->user->listas_de_precio = 0;
        $this->user->save();
        $this->assertNotNull(TechoDeDescuentoService::calcular($article, null, $this->user));

        $this->user->listas_de_precio = 1;
        $this->user->save();

        $this->assertNull(TechoDeDescuentoService::calcular($article, null, $this->user));
        $this->assertSame(TechoDeDescuentoService::EXCLUIDO_DOLARES_CON_LISTAS,
            TechoDeDescuentoService::evaluar($article, null, $this->user)['excluido_por']);
    }

    /**
     * 🔴 El invariante que blinda el techo: ninguna señal puede subirlo. Un factor > 1 se recorta.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_factor_de_credito_nunca_puede_subir_el_techo()
    {
        $article = $this->articulo();
        $base    = TechoDeDescuentoService::calcular($article, null, $this->user);

        foreach ([1.0, 1.5, 99.0] as $factor) {
            $con_factor = TechoDeDescuentoService::calcular($article, null, $this->user, $factor);

            $this->assertSame($base['techo'], $con_factor['techo'], "el factor {$factor} no puede subir el techo");
            $this->assertLessThanOrEqual(1, $con_factor['factor_credito']);
        }

        $la_mitad = TechoDeDescuentoService::evaluar($article, null, $this->user, 0.5);
        $this->assertLessThan($base['techo'], $la_mitad['techo'], 'un factor menor a 1 sí lo baja');
    }

    /**
     * 🔴 Corrección del 15/8/2026: al mal pagador se lo EXCLUYE entero en CriteriosDeOfertaService,
     * no se le baja el techo. Este test se pone rojo si alguien trae FACTOR_MAL_PAGADOR de vuelta.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function no_existe_ninguna_constante_de_mal_pagador()
    {
        $constantes = (new ReflectionClass(TechoDeDescuentoService::class))->getConstants();
        $this->assertArrayNotHasKey('FACTOR_MAL_PAGADOR', $constantes);

        foreach (array_keys($constantes) as $nombre) {
            $this->assertFalse(strpos($nombre, 'MAL_PAGADOR') !== false,
                "la constante {$nombre} reintroduce el mal pagador como factor del techo");
        }
    }

    /**
     * Más de 60% en un ecommerce es un error de carga, no una oferta; y un margen chico no llega al
     * mínimo, así que la línea no se sugiere.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_techo_respeta_el_maximo_absoluto_y_el_minimo()
    {
        $caro = $this->articulo(['cost' => 10, 'percentage_gain' => 5000]);

        $this->assertSame(TechoDeDescuentoService::MAX_DESCUENTO_ABSOLUTO,
            TechoDeDescuentoService::calcular($caro, null, $this->user)['techo']);

        $flaco       = $this->articulo(['percentage_gain' => 2]);
        $diagnostico = TechoDeDescuentoService::evaluar($flaco, null, $this->user);

        $this->assertLessThan(TechoDeDescuentoService::MIN_DESCUENTO, $diagnostico['techo']);
        $this->assertSame(TechoDeDescuentoService::EXCLUIDO_TECHO_MENOR_AL_MINIMO, $diagnostico['excluido_por']);
        $this->assertNull(TechoDeDescuentoService::calcular($flaco, null, $this->user));
    }
}
