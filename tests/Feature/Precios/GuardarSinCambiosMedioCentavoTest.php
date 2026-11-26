<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Guardar un artículo sin cambios no mueve el «precio anterior» cuando el precio de una lista cae en
 * medio centavo (misión ft-guardado-margen, 26/11/2026; caso 17338 de Ferretotal).
 *
 * 🔴 QUE PROTEGE. Ferretotal corre PHP 8.4, donde `round()` ya no pre-redondea: 2278.43 + 2278.43*50/100
 * da 3417.6449999999995 y `round(…, 2)` devuelve 3417.64 (en 7.4/8.3 daba 3417.65). El guardado sin
 * cambios comparaba 3417.64 contra el 3417.65 guardado, creía que el precio había cambiado y escribía
 * el precio actual como «precio anterior» (3339.60 → 3417.65): el Excel de clientes y los carteles
 * pierden el «Aumento». El gemelo en el precio único bajaba el precio un centavo y registraba un
 * cambio de precio.
 *
 * Los causales (CA2 y CA3) sólo fallan corriendo con `cc-php 8.4`; en 7.4 pasan antes y después.
 * Los demás (CA4, CA6) son de contrato y pasan siempre. CA6 existe para que nadie «arregle» sin una
 * decisión que `base_margen` viejo (de una importación que no lo persiste) se ponga al día al guardar.
 *
 * Cada test crea sus datos. Los helpers de foto repiten los de GuardarSinCambiosDerivadosNullTest a
 * propósito: ese archivo no se modifica.
 *
 * IMPORTANTE: PHP 7.4, sin sintaxis de PHP 8.
 *
 * @group precios-por-lista
 */
class GuardarSinCambiosMedioCentavoTest extends ListasSiempreTestCase
{
    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Cuentas y artículos
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta «tipo Ferretotal»: IVA al costo, sin redondeos de listas, sin recargos, con listas.
     * Dos listas: MAYORISTA 0 % y LISTA GENERAL 50 %.
     *
     * @return array [User, PriceType (0 %), PriceType (50 %)]
     */
    protected function cuenta_ft()
    {
        $dueno = $this->dueno_nuevo('ft-medio-centavo', [
            'listas_de_precio'           => 1,
            'aplicar_iva_al_costo'       => 1,
            'cotizar_precios_en_dolares' => 0,
        ]);

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $mayorista     = $this->otra_lista($dueno, 'zz MAYORISTA', 0, 1);
        $lista_general = $this->otra_lista($dueno, 'zz LISTA GENERAL', 50, 2);

        $this->actingAs($dueno, 'web');

        return [User::find($dueno->id), $mayorista, $lista_general];
    }

    /**
     * Artículo «17338»: costo 1883, IVA 21 %, sin margen propio, atado a las dos listas. Se lo
     * recalcula, se normaliza por la ficha y se lo lleva al estado que midió el humo de Ferretotal.
     *
     * @return \App\Models\Article
     */
    protected function articulo_17338($dueno, $mayorista, $lista_general)
    {
        $articulo = $this->articulo($dueno, '17338', [
            'cost'        => '1883',
            'aplicar_iva' => 1,
        ]);

        foreach ([$mayorista, $lista_general] as $lista) {
            $articulo->price_types()->attach($lista->id, ['percentage' => $lista->percentage]);
        }

        $this->recalcular($articulo, $dueno);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        DB::table('articles')->where('id', $articulo->id)->update([
            'previus_final_price' => '2226.40',
            'base_margen'         => '2226.40',
            'base_margen_final'   => '2226.40',
        ]);

        DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $mayorista->id)->update([
            'final_price'         => '2278.43',
            'previus_final_price' => '2226.40',
        ]);

        DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista_general->id)->update([
            'final_price'         => '3417.65',
            'previus_final_price' => '3339.60',
        ]);

        return Article::find($articulo->id);
    }

    // ------------------------------------------------------------------------------------------
    // Fotos y caminos
    // ------------------------------------------------------------------------------------------

    /**
     * Foto de lo que el guardado podría mover: fila de `articles`, filas de `article_price_type` y
     * conteo de `price_changes`. Sólo se saca `updated_at`.
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
     * test (`$ajustar` recibe el modelo y devuelve el modelo ajustado).
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

    protected function precios_de_pivot($articulo, $lista)
    {
        $pivot = $this->pivote($articulo, $lista);

        return [$pivot->final_price, $pivot->previus_final_price];
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — el caso original
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. Hoy en PHP 8.4 falla: el PUT deja el previus de la lista del 50 % en 3417.65 (el precio
     * actual) en vez de conservar 3339.60. La lista del 0 % no tiene medio centavo y no se mueve.
     *
     * @return void
     */
    public function test_ferretotal_17338_put_sin_cambios_conserva_previus_de_lista()
    {
        list($dueno, $mayorista, $lista_general) = $this->cuenta_ft();
        $articulo = $this->articulo_17338($dueno, $mayorista, $lista_general);

        $this->assertEquals(2278.43, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Precondición: costo_real 2278.43.');
        $this->assertSame('2278.43', DB::table('articles')->where('id', $articulo->id)->value('final_price'), 'Precondición: precio único 2278.43.');

        $antes = $this->foto($articulo->id);
        $cambios_antes = DB::table('price_changes')->where('article_id', $articulo->id)->count();

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $this->assertSame(['3417.65', '3339.60'], $this->precios_de_pivot($articulo, $lista_general), 'La lista del 50 % perdió su «precio anterior».');
        $this->assertSame(['2278.43', '2226.40'], $this->precios_de_pivot($articulo, $mayorista), 'La lista del 0 % se movió.');
        $this->assertSame('2278.43', DB::table('articles')->where('id', $articulo->id)->value('final_price'));
        $this->assertSame($cambios_antes, DB::table('price_changes')->where('article_id', $articulo->id)->count(), 'Un guardado sin cambios registró un cambio de precio.');

        // base_margen* se pone al día a propósito (CA6): se excluye de la foto completa.
        $antes   = array_diff_key($antes, array_flip(['articles.base_margen', 'articles.base_margen_final']));
        $despues = array_diff_key($this->foto($articulo->id), array_flip(['articles.base_margen', 'articles.base_margen_final']));
        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals([], $diferencias, 'Guardar sin cambios movió la foto:' . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — el gemelo en el precio único
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. Cuenta sin listas: costo 1883 con IVA 21 % y margen 50 % da 3417.645 → 3417.65 en 7.4. Hoy
     * en 8.4 el PUT sin cambios lo baja a 3417.64, pisa el previus y registra un cambio de precio.
     *
     * @return void
     */
    public function test_precio_unico_en_medio_centavo_no_baja_un_centavo()
    {
        $dueno = $this->dueno_nuevo('ft-precio-unico', [
            'listas_de_precio'           => 0,
            'aplicar_iva_al_costo'       => 1,
            'cotizar_precios_en_dolares' => 0,
        ]);

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->actingAs($dueno, 'web');

        $articulo = $this->articulo($dueno, 'precio-unico', [
            'cost'            => '1883',
            'percentage_gain' => 50,
            'aplicar_iva'     => 1,
        ]);

        $this->recalcular($articulo, $dueno);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        DB::table('articles')->where('id', $articulo->id)->update([
            'final_price'         => '3417.65',
            'previus_final_price' => '3339.60',
        ]);

        $antes = $this->foto($articulo->id);
        $cambios_antes = DB::table('price_changes')->where('article_id', $articulo->id)->count();

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertSame('3417.65', $fila->final_price, 'El guardado sin cambios bajó el precio un centavo.');
        $this->assertSame('3339.60', $fila->previus_final_price, 'El guardado sin cambios pisó el precio anterior.');
        $this->assertSame($cambios_antes, DB::table('price_changes')->where('article_id', $articulo->id)->count(), 'Un guardado sin cambios registró un cambio de precio.');

        $diferencias = $this->diferencias($antes, $this->foto($articulo->id));

        $this->assertEquals([], $diferencias, 'Guardar sin cambios movió la foto:' . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — un cambio real se sigue registrando
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. Subir el costo a 1900 mueve las dos listas y deja el precio anterior de cada una.
     * 1900 × 1,21 = 2299.00 (lista 0 %); × 1,5 = 3448.50 (lista 50 %).
     *
     * @return void
     */
    public function test_cambio_real_de_costo_mueve_previus()
    {
        list($dueno, $mayorista, $lista_general) = $this->cuenta_ft();
        $articulo = $this->articulo_17338($dueno, $mayorista, $lista_general);

        $this->guardar_sin_cambios($articulo->id, function ($modelo) {
            $modelo['cost'] = 1900;

            return $modelo;
        })->assertOk();

        $this->assertSame(['3448.50', '3417.65'], $this->precios_de_pivot($articulo, $lista_general), 'La lista del 50 % no registró el cambio real.');
        $this->assertSame(['2299.00', '2278.43'], $this->precios_de_pivot($articulo, $mayorista), 'La lista del 0 % no registró el cambio real.');
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — contrato: base_margen viejo se pone al día
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. El `base_margen` guardado (2226.40, de un costo anterior) se recalcula al guardar la ficha:
     * es la caché derivada que el motor en lote no persiste. Es comportamiento PREVISTO, no un bug:
     * ver `ArticleHelper` (misión guardar-sin-cambios-derivados-null). Este test pasa hoy y mañana; si
     * alguien lo hace fallar, que sea con una decisión.
     *
     * @return void
     */
    public function test_base_margen_viejo_se_pone_al_dia_sin_tocar_precios()
    {
        list($dueno, $mayorista, $lista_general) = $this->cuenta_ft();
        $articulo = $this->articulo_17338($dueno, $mayorista, $lista_general);

        $fila = DB::table('articles')->where('id', $articulo->id)->first();
        $this->assertEquals(2226.40, (float) $fila->base_margen, 'Precondición: base_margen viejo.');

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertEquals(2278.43, (float) $fila->base_margen, 'base_margen no se puso al día.');
        $this->assertEquals(2278.43, (float) $fila->base_margen_final, 'base_margen_final no se puso al día.');
        $this->assertSame('2278.43', $fila->final_price);
        $this->assertSame(['2278.43', '2226.40'], $this->precios_de_pivot($articulo, $mayorista));
    }
}
