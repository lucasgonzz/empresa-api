<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\Iva;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Guardar un artículo sin cambiarle nada NO mueve las listas ni el costo real (misión
 * guardar-sin-cambios-listas-y-costo-real).
 *
 * 🔴 QUE PROTEGE. Contrato de actualización de clientes: abrir un artículo existente en la ficha y
 * guardarlo sin cambios no puede cambiar ningún valor de precio ni de costo, ni del artículo ni de sus
 * listas. La 4.3.72 cerró los derivados (`base_margen*`) y el «precio anterior». Quedaron dos casos de
 * producción (humo de actualización, paso 1, con la 4.3.73):
 *
 *  - CASO A (Golden-Breeze, artículo 10): cuenta con ventas en dólares, listas con precio fijado a mano
 *    por moneda y las filas de `article_price_type` en NULL. El espejo de las monedas en pesos hacia la
 *    pivot las completaba (-100/0.00, 220.18/1108.00, ...) aunque nadie tocó las listas.
 *  - CASO B (MBMalizia, artículo 2689): `costo_real` guardado sin la bonificación del proveedor (15 %)
 *    que la fila de `article_discounts` ya tiene; el guardado lo recalculaba aplicándola.
 *
 * Un cambio REAL (costo, IVA, proveedor, precio de una lista) se sigue calculando como siempre.
 *
 * Cada test crea sus datos (dueño propio, sin residuos).
 *
 * IMPORTANTE: PHP 7.4, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 *
 * @group precios-por-lista
 */
class GuardarSinCambiosCostoRealNuloTest extends ListasSiempreTestCase
{
    const ARS = 1;
    const USD = 2;

    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Fotos y caminos (mismos criterios que GuardarSinCambiosDerivadosNullTest)
    // ------------------------------------------------------------------------------------------

    /**
     * Foto de todo lo que el guardado podría mover: fila de `articles`, filas de `article_price_type` y de
     * `article_price_type_monedas` del artículo, y el historial. Sólo se saca `updated_at`.
     *
     * @return array clave 'tabla[selector].columna' => valor (string o null)
     */
    protected function foto($article_id)
    {
        $foto = [];

        $aplanar = function ($prefijo, $fila) use (&$foto) {
            foreach ((array) $fila as $columna => $valor) {
                if ($columna === 'updated_at') {
                    continue;
                }
                $foto[$prefijo . '.' . $columna] = is_null($valor) ? null : (string) $valor;
            }
        };

        $aplanar('articles', DB::table('articles')->where('id', $article_id)->first());

        foreach (DB::table('article_price_type')->where('article_id', $article_id)->orderBy('price_type_id')->get() as $fila) {
            $aplanar('article_price_type[lista=' . $fila->price_type_id . ']', $fila);
        }

        foreach (DB::table('article_price_type_monedas')->where('article_id', $article_id)->orderBy('price_type_id')->orderBy('moneda_id')->get() as $fila) {
            $aplanar('article_price_type_monedas[lista=' . $fila->price_type_id . ',moneda=' . $fila->moneda_id . ']', $fila);
        }

        $foto['price_changes.count'] = (string) DB::table('price_changes')->where('article_id', $article_id)->count();

        return $foto;
    }

    protected function diferencias($antes, $despues)
    {
        $diferencias = [];

        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $clave) {

            $a = array_key_exists($clave, $antes) ? $antes[$clave] : '<<no existe>>';
            $d = array_key_exists($clave, $despues) ? $despues[$clave] : '<<no existe>>';

            if ($a !== $d) {
                $diferencias[$clave] = [$a, $d];
            }
        }

        ksort($diferencias);

        return $diferencias;
    }

    protected function describir($diferencias)
    {
        $lineas = [];

        foreach ($diferencias as $clave => $par) {
            $lineas[] = $clave . ': ' . var_export($par[0], true) . ' -> ' . var_export($par[1], true);
        }

        return "\n" . implode("\n", $lineas);
    }

    /**
     * Camino de la ficha: GET api/article/{id} y PUT del `model` devuelto, con los ajustes que pida el
     * test (`$ajustar` recibe el modelo y lo devuelve).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_sin_cambios($article_id, $ajustar = null)
    {
        $modelo = $this->getJson('api/article/' . $article_id)->assertOk()->json('model');

        if (!is_null($ajustar)) {
            $modelo = $ajustar($modelo);
        }

        return $this->putJson('api/article/' . $article_id, $modelo);
    }

    protected function assertSinDiferencias($antes, $despues, $mensaje)
    {
        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals([], $diferencias, $mensaje . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // costo_real NULL se queda NULL (El Kiosco Verde, artículo 1 «Banco De Trabajo»)
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta simple con IVA al costo y un margen del 20 % en el artículo.
     *
     * @return \App\Models\User
     */
    protected function cuenta_kiosco()
    {
        $dueno = $this->dueno_nuevo('kiosco-lc', [
            'listas_de_precio'           => 0,
            'dollar'                     => 1000,
            'cotizar_precios_en_dolares' => 0,
            'aplicar_iva_al_costo'       => 1,
        ]);

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $this->actingAs($dueno, 'web');

        return User::find($dueno->id);
    }

    /**
     * Artículo estable (pasó una vez por la ficha) y luego dejado como lo deja una importación o un alta
     * sin costo: `cost` como se pida y `costo_real` NULL.
     *
     * @return \App\Models\Article
     */
    protected function articulo_con_costo_real_null($dueno, $cost)
    {
        $articulo = $this->articulo($dueno, 'kiosco', ['cost' => '100', 'aplicar_iva' => 1, 'percentage_gain' => 20]);

        $this->recalcular($articulo, $dueno);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        // Con costo vacío el precio final guardado es 0 (coherente con el costo): lo único que se mide es el costo real.
        $atributos = ['cost' => $cost, 'costo_real' => null, 'base_margen' => null, 'base_margen_final' => null];

        if (!$cost) {
            $atributos['final_price'] = 0;
        }

        DB::table('articles')->where('id', $articulo->id)->update($atributos);

        return Article::find($articulo->id);
    }

    /**
     * Hoy falla: con costo vacío el guardado deja costo_real en 0.000000.
     *
     * @dataProvider costos_vacios
     * @return void
     */
    public function test_costo_real_null_con_costo_vacio_sigue_null($cost)
    {
        $dueno    = $this->cuenta_kiosco();
        $articulo = $this->articulo_con_costo_real_null($dueno, $cost);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertNull($fila->costo_real, 'costo_real NULL pasó a otro valor al guardar sin cambios.');
        $this->assertNull($fila->base_margen, 'base_margen NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->base_margen_final, 'base_margen_final NULL fue completado por un guardado sin cambios.');
        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'Guardar sin cambios movió la foto completa:');
    }

    public function costos_vacios()
    {
        return [
            'cost NULL' => [null],
            'cost 0'    => ['0'],
        ];
    }

    /**
     * Con costo > 0 y costo_real NULL, guardar sin cambios tampoco lo completa (misma regla de Lucas).
     *
     * @return void
     */
    public function test_costo_real_null_con_costo_positivo_sigue_null()
    {
        $dueno    = $this->cuenta_kiosco();
        $articulo = $this->articulo_con_costo_real_null($dueno, '100');

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertNull($fila->costo_real, 'costo_real NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->base_margen, 'base_margen NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->base_margen_final, 'base_margen_final NULL fue completado por un guardado sin cambios.');
        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'Guardar sin cambios movió la foto completa:');
    }

    /**
     * Un cambio real de costo sí lo calcula: 200 x 1,21.
     *
     * @return void
     */
    public function test_un_cambio_real_de_costo_calcula_el_costo_real_null()
    {
        $dueno    = $this->cuenta_kiosco();
        $articulo = $this->articulo_con_costo_real_null($dueno, null);

        $iva = Iva::find(DB::table('articles')->where('id', $articulo->id)->value('iva_id'));
        $this->assertNotNull($iva, 'Precondición: el artículo tiene IVA.');

        $this->guardar_sin_cambios($articulo->id, function ($modelo) {
            $modelo['cost'] = '200';

            return $modelo;
        })->assertOk();

        $esperado = 200 * (1 + (float) $iva->percentage / 100);

        $this->assertEqualsWithDelta($esperado, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 0.001, 'Un cambio real de costo no calculó el costo real.');
    }
}
