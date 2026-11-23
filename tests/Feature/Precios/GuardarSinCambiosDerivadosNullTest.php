<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\Excel\Article\ArticleClientsExportHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Guardar (o recalcular) un artículo sin cambiarle nada NO escribe sus derivados vacíos ni mueve el
 * «precio anterior» de sus listas (misión guardar-sin-cambios-derivados-null-20261121).
 *
 * 🔴 QUE PROTEGE. La importación calcula el precio pero no persiste `base_margen`/`base_margen_final`
 * ni el `previus_final_price` del pivot: en Plazoleta 44.460 de 44.462 artículos los tienen NULL. El
 * primer guardado por la ficha los completaba (NULL → 3.850000 / 4.658500 / 6.99) aunque nadie
 * hubiera tocado nada, y el comparador estricto del humo de actualización marcaba DIFERENCIAS. Además
 * el `previus_final_price` del pivot se igualaba al precio actual en cada guardado: borraba el
 * «Aumento» del Excel de clientes de un cambio real anterior.
 *
 * Regla: «sin cambio» se decide con el valor YA cuantizado/persistible (nunca con «se tomó la rama de
 * tolerancia»: 6.98775 calculado contra 6.99 guardado difiere más que la tolerancia y es «sin
 * cambio» igual), y la foto del pivot se toma antes de que la ficha lo pise.
 *
 * Cada test crea sus datos (dueño propio, sin residuos). Los helpers de foto repiten los de
 * GuardarSinCambiosPreservaPrecisionTest a propósito: ese archivo no se puede reordenar.
 *
 * IMPORTANTE: PHP 7.4, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 *
 * @group precios-por-lista
 */
class GuardarSinCambiosDerivadosNullTest extends ListasSiempreTestCase
{
    /** Fecha fija conocida de `final_price_updated_at`: nada del código bajo prueba debería moverla. */
    const FECHA_FIJA = '2026-10-01 10:00:00';

    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Cuentas y artículos
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta Responsable Inscripto «tipo Plazoleta»: el IVA se suma al vender, sin impuestos de venta.
     * Con `$con_precio_final` queda con la lista «Precio final» (única lista de la cuenta); con
     * `$con_mayorista` además con «Mayorista» 20 %.
     *
     * @return array [User, PriceType|null (Precio final), PriceType|null (Mayorista)]
     */
    protected function cuenta_ri($etiqueta, $con_precio_final = true, $con_mayorista = false)
    {
        $dueno = $this->dueno_nuevo($etiqueta, [
            'usar_condicion_fiscal_en_costeo' => 1,
            'condicion_iva_precios'           => 'RRII',
        ]);

        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();
        ArticlePricesHelper::$sale_taxes_cache = [];

        $precio_final = null;
        $mayorista    = null;

        if ($con_precio_final) {
            list($dueno, $precio_final) = $this->con_precio_final($dueno);
        }

        if ($con_mayorista) {
            $mayorista = $this->otra_lista($dueno, 'zz Mayorista', 20, 2);
        }

        $this->actingAs($dueno, 'web');

        return [User::find($dueno->id), $precio_final, $mayorista];
    }

    /**
     * Artículo «Plazoleta 44461»: costo 3.85, margen 50 %, IVA 21 %. Se crea por el camino normal, se
     * normaliza por la ficha (nombre, slug, marca de Tienda Nube) y recién ahí se lo lleva al estado
     * REAL que deja la importación con `DB::table`: derivados y `previus` en NULL, fechas fijas.
     *
     * @return \App\Models\Article
     */
    protected function articulo_plazoleta($dueno, array $listas, array $atributos = [])
    {
        $articulo = $this->articulo($dueno, 'plazoleta', array_merge([
            'cost'            => '3.85',
            'percentage_gain' => 50,
            'aplicar_iva'     => 1,
        ], $atributos));

        foreach ($listas as $lista) {
            if (is_null($this->pivote($articulo, $lista))) {
                $articulo->price_types()->attach($lista->id, ['percentage' => $lista->percentage]);
            }
        }

        $this->recalcular($articulo, $dueno);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        return Article::find($articulo->id);
    }

    /**
     * Estado REAL de la importación para el artículo con «Precio final» como única lista.
     *
     * @return void
     */
    protected function llevar_a_estado_de_importacion($articulo, $precio_final)
    {
        DB::table('articles')->where('id', $articulo->id)->update([
            'final_price'            => '6.99',
            'costo_real'             => '3.85',
            'base_margen'            => null,
            'base_margen_final'      => null,
            'previus_final_price'    => null,
            'final_price_updated_at' => self::FECHA_FIJA,
        ]);

        DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $precio_final->id)
            ->update([
                'percentage'               => '50.00',
                'final_price'              => '6.99',
                'previus_final_price'      => null,
                'setear_precio_final'      => 0,
                'precio_luego_de_recargos' => '6.99',
                'monto_ganancia'           => '1.93',
            ]);
    }

    // ------------------------------------------------------------------------------------------
    // Fotos y caminos (mismos criterios que GuardarSinCambiosPreservaPrecisionTest)
    // ------------------------------------------------------------------------------------------

    /**
     * Foto de todo lo que el guardado podría mover: fila de `articles`, filas de `article_price_type`
     * y de `article_price_type_monedas` del artículo, y el historial. Sólo se saca `updated_at`.
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
     * test (`$ajustar` recibe el modelo por referencia).
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

    /**
     * Cambia el pivot de una lista dentro del `price_types` del modelo del show.
     *
     * @return array el modelo con la lista ajustada
     */
    protected function con_pivot_de_lista(array $modelo, $price_type_id, array $pivot)
    {
        foreach ($modelo['price_types'] as $i => $price_type) {
            if ((int) $price_type['id'] === (int) $price_type_id) {
                $modelo['price_types'][$i]['pivot'] = array_merge($price_type['pivot'], $pivot);
            }
        }

        return $modelo;
    }

    protected function assertSinDiferencias($antes, $despues, $mensaje)
    {
        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals([], $diferencias, $mensaje . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // CA1 — Plazoleta: PUT de la ficha sin cambios (el caso real)
    // ------------------------------------------------------------------------------------------

    /**
     * CA1. Hoy falla: el PUT completa base_margen 3.850000, base_margen_final 4.658500 y deja el
     * `previus_final_price` del pivot en 6.99 (los mismos 3 valores del post-humo de Plazoleta).
     * El calculado (6.98775) queda fuera de la tolerancia 1e-6 y el persistible es 6.99: un diseño
     * que decida «sin cambio» por la rama de tolerancia también falla acá.
     *
     * @return void
     */
    public function test_plazoleta_put_sin_cambios_no_completa_derivados_ni_previus()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('ca1');
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final]);

        $this->llevar_a_estado_de_importacion($articulo, $precio_final);

        $this->assertEquals(3.85, (float) DB::table('articles')->where('id', $articulo->id)->value('costo_real'), 'Precondición: costo_real 3.85.');

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $despues = $this->foto($articulo->id);
        $fila    = DB::table('articles')->where('id', $articulo->id)->first();
        $pivot   = $this->pivote($articulo, $precio_final);

        $this->assertSame('6.99', $fila->final_price, 'El PUT sin cambios movió final_price.');
        $this->assertNull($fila->base_margen, 'base_margen NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->base_margen_final, 'base_margen_final NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->previus_final_price, 'articles.previus_final_price movido sin cambio de precio.');
        $this->assertNull($pivot->previus_final_price, 'El previus del pivot se igualó al precio actual sin cambio de precio.');

        $this->assertSinDiferencias($antes, $despues, 'Guardar sin cambios movió la foto completa:');
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — el «Aumento» del Excel sobrevive a un guardado sin cambios
    // ------------------------------------------------------------------------------------------

    /**
     * Cambia el margen del 50 al 60 % por la ficha (cambio real).
     */
    protected function subir_margen_a_60($article_id)
    {
        return $this->guardar_sin_cambios($article_id, function ($modelo) {
            $modelo['percentage_gain'] = 60;

            return $modelo;
        });
    }

    /**
     * CA2. Cambio real 6.99 -> 7.45 deja previus 6.99; un PUT posterior sin cambios lo conserva y el
     * Excel de clientes sigue diciendo «Aumento». Hoy el segundo PUT iguala previus a 7.45 y da null.
     *
     * @return void
     */
    public function test_aumento_del_excel_sobrevive_a_guardado_sin_cambios()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('ca2');
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final]);
        $this->llevar_a_estado_de_importacion($articulo, $precio_final);

        $this->subir_margen_a_60($articulo->id)->assertOk();

        $pivot = $this->pivote($articulo, $precio_final);
        $this->assertSame('7.45', $pivot->final_price, 'Precondición: el pivot quedó en 7.45.');
        $this->assertSame('6.99', $pivot->previus_final_price, 'Un cambio real deja el precio anterior de la lista.');

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $pivot = $this->pivote($articulo, $precio_final);
        $this->assertSame('7.45', $pivot->final_price);
        $this->assertSame('6.99', $pivot->previus_final_price, 'Un guardado sin cambios borró el «precio anterior» de la lista.');

        $relacion = Article::find($articulo->id)->price_types()->find($precio_final->id);

        $this->assertSame('Aumento', ArticleClientsExportHelper::get_diferencia_de_precio($relacion), 'El Excel de clientes perdió el «Aumento».');
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — lista común por margen y lista a mano
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. Lista común (Mayorista 20 %): final 5.5902 -> '5.59' persistible; previus 5.00 se conserva.
     * Lista a mano (setear_precio_final 1, final 9.00, previus 4.00): se conserva 4.00 (hoy queda NULL).
     *
     * @return void
     */
    public function test_lista_comun_y_lista_a_mano_conservan_previus_sin_cambios()
    {
        list($dueno, $precio_final, $mayorista) = $this->cuenta_ri('ca3', true, true);
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final, $mayorista]);

        $pivote = $this->pivote($articulo, $mayorista);
        $this->assertSame('5.59', $pivote->final_price, 'Precondición: 3.85 x 1.2 x 1.21 = 5.5902 persiste como 5.59.');

        // Lista común por margen, con un anterior conocido.
        DB::table('article_price_type')->where('id', $pivote->id)->update(['previus_final_price' => '5.00']);

        $antes = $this->foto($articulo->id);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $pivote = $this->pivote($articulo, $mayorista);
        $this->assertSame('5.59', $pivote->final_price);
        $this->assertSame('5.00', $pivote->previus_final_price, 'Lista por margen sin cambio: el previus se pisó con el precio actual.');
        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'Lista común: el PUT sin cambios movió la foto:');

        // Lista a mano. Las columnas derivadas (percentage, precio_luego_de_recargos, monto_ganancia)
        // van coherentes con el 9.00 fijado (los valores que calcula el sistema): si no, el recálculo
        // las corregiría y la foto se movería por una incoherencia del fixture, no por el previus.
        DB::table('article_price_type')->where('id', $pivote->id)->update([
            'setear_precio_final'      => 1,
            'final_price'              => '9.00',
            'previus_final_price'      => '4.00',
            'percentage'               => '93.20',
            'precio_luego_de_recargos' => '9.00',
            'monto_ganancia'           => '3.59',
        ]);

        $antes = $this->foto($articulo->id);
        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $pivote = $this->pivote($articulo, $mayorista);
        $this->assertSame('9.00', $pivote->final_price);
        $this->assertSame('4.00', $pivote->previus_final_price, 'Lista a mano sin cambio: el previus se borró.');
        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'Lista a mano: el PUT sin cambios movió la foto:');
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — recálculo masivo sin cambio de precio
    // ------------------------------------------------------------------------------------------

    /**
     * CA4. RecalculoDePreciosEnLote sobre el fixture de CA1, sin cambiar nada: la foto es idéntica.
     *
     * @return void
     */
    public function test_recalculo_masivo_sin_cambio_no_completa_derivados()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('ca4');
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final]);
        $this->llevar_a_estado_de_importacion($articulo, $precio_final);

        $antes = $this->foto($articulo->id);

        $resultado = RecalculoDePreciosEnLote::recalcular([$articulo->id], User::find($dueno->id), $dueno->id);

        $this->assertSame(1, $resultado['recalculados']);
        $this->assertSame([], $resultado['cambiaron'], 'El lote contó como «cambiaron» un artículo cuyo precio no cambió.');

        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'El recálculo masivo sin cambios movió la foto:');
    }

    // ------------------------------------------------------------------------------------------
    // CA6 — Galván: base con valor y base_final vacía
    // ------------------------------------------------------------------------------------------

    /**
     * CA6. `base_margen` ya tiene el valor calculado y `base_margen_final` está vacía (la columna nació
     * después): un PUT sin cambios deja las dos como están. Hoy completa base_margen_final.
     *
     * @return void
     */
    public function test_base_con_valor_y_base_final_vacia()
    {
        list($dueno) = $this->cuenta_ri('ca6', false);
        $articulo = $this->articulo_plazoleta($dueno, []);

        DB::table('articles')->where('id', $articulo->id)->update([
            'final_price'            => '6.99',
            'costo_real'             => '3.85',
            'base_margen'            => '3.850000',
            'base_margen_final'      => null,
            'previus_final_price'    => null,
            'final_price_updated_at' => self::FECHA_FIJA,
        ]);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertSame('3.850000', $fila->base_margen);
        $this->assertNull($fila->base_margen_final, 'base_margen_final vacía fue completada por un guardado sin cambios.');
        $this->assertSinDiferencias($antes, $this->foto($articulo->id), 'El PUT sin cambios movió la foto:');
    }

    // ------------------------------------------------------------------------------------------
    // CA7 / CA8 — precio a mano 9 -> 10 registra el anterior 9
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. Lista común a mano 9 -> 10 por la ficha: previus 9.00 (ni el 4.00 viejo, ni NULL, ni 10.00).
     *
     * @return void
     */
    public function test_lista_a_mano_9_a_10_registra_anterior_9()
    {
        list($dueno, $precio_final, $mayorista) = $this->cuenta_ri('ca7', true, true);
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final, $mayorista]);

        $pivote = $this->pivote($articulo, $mayorista);

        DB::table('article_price_type')->where('id', $pivote->id)->update([
            'setear_precio_final' => 1,
            'final_price'         => '9.00',
            'previus_final_price' => '4.00',
        ]);

        $this->guardar_sin_cambios($articulo->id, function ($modelo) use ($mayorista) {
            return $this->con_pivot_de_lista($modelo, $mayorista->id, ['setear_precio_final' => 1, 'final_price' => 10]);
        })->assertOk();

        $pivote = $this->pivote($articulo, $mayorista);

        $this->assertSame('10.00', $pivote->final_price);
        $this->assertSame('9.00', $pivote->previus_final_price, 'El precio a mano 9 -> 10 no dejó 9 como anterior.');
    }

    /**
     * CA8. Espejo de la lista «Precio final» con precio manual del artículo 9 -> 10: el pivot, que la
     * ficha pisa antes de calcular, igual registra 9 como anterior.
     *
     * @return void
     */
    public function test_espejo_precio_final_a_mano_9_a_10_registra_anterior_9()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('ca8');
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final], ['price' => 9, 'percentage_gain' => null]);

        DB::table('articles')->where('id', $articulo->id)->update([
            'final_price'         => '9.00',
            'previus_final_price' => '4.00',
        ]);
        DB::table('article_price_type')
            ->where('article_id', $articulo->id)
            ->where('price_type_id', $precio_final->id)
            ->update(['setear_precio_final' => 1, 'final_price' => '9.00', 'previus_final_price' => '4.00']);

        $eventos_antes = (int) DB::table('price_changes')->where('article_id', $articulo->id)->count();

        $this->guardar_sin_cambios($articulo->id, function ($modelo) use ($precio_final) {
            $modelo['price'] = 10;

            return $this->con_pivot_de_lista($modelo, $precio_final->id, ['setear_precio_final' => 1, 'final_price' => 10]);
        })->assertOk();

        $fila  = DB::table('articles')->where('id', $articulo->id)->first();
        $pivot = $this->pivote($articulo, $precio_final);

        $this->assertSame('10.00', $fila->final_price);
        $this->assertSame('9.00', $fila->previus_final_price);
        $this->assertSame('10.00', $pivot->final_price);
        $this->assertSame('9.00', $pivot->previus_final_price, 'El espejo «Precio final» a mano 9 -> 10 no dejó 9 como anterior.');
        $this->assertSame($eventos_antes + 1, (int) DB::table('price_changes')->where('article_id', $articulo->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // Guardas: hoy pasan y tienen que seguir pasando
    // ------------------------------------------------------------------------------------------

    /**
     * G1. Un cambio real de margen completa los derivados y registra el anterior y el evento.
     *
     * @return void
     */
    public function test_guarda_cambio_de_margen_completa_y_registra()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('g1');
        $articulo = $this->articulo_plazoleta($dueno, [$precio_final]);
        $this->llevar_a_estado_de_importacion($articulo, $precio_final);

        $eventos_antes = (int) DB::table('price_changes')->where('article_id', $articulo->id)->count();

        $this->subir_margen_a_60($articulo->id)->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertSame('7.45', $fila->final_price);
        $this->assertEquals(3.85, (float) $fila->base_margen, 'Un cambio real tiene que completar base_margen.');
        $this->assertEquals(4.6585, (float) $fila->base_margen_final, 'Un cambio real tiene que completar base_margen_final.');
        $this->assertSame('6.99', $fila->previus_final_price);
        $this->assertNotSame(self::FECHA_FIJA, (string) $fila->final_price_updated_at);
        $this->assertSame($eventos_antes + 1, (int) DB::table('price_changes')->where('article_id', $articulo->id)->count());
        $this->assertSame('7.45', DB::table('price_changes')->where('article_id', $articulo->id)->orderByDesc('id')->value('final_price'));
    }

    /**
     * G2. Precio manual sin cambio de precio final pero con cambio de costo: los derivados se completan.
     *
     * @return void
     */
    public function test_guarda_precio_manual_cambio_de_costo_completa_base()
    {
        list($dueno) = $this->cuenta_ri('g2', false);
        $articulo = $this->articulo_plazoleta($dueno, [], ['cost' => 1000, 'price' => 3600, 'percentage_gain' => null]);

        DB::table('articles')->where('id', $articulo->id)->update([
            'base_margen'       => null,
            'base_margen_final' => null,
        ]);

        $this->assertSame('3600.00', DB::table('articles')->where('id', $articulo->id)->value('final_price'), 'Precondición: precio manual 3600.');

        $this->guardar_sin_cambios($articulo->id, function ($modelo) {
            $modelo['cost'] = 1100;

            return $modelo;
        })->assertOk();

        $fila = DB::table('articles')->where('id', $articulo->id)->first();

        $this->assertSame('3600.00', $fila->final_price);
        $this->assertNotNull($fila->base_margen, 'Con otro costo real la base se completa.');
        $this->assertGreaterThan(0, (float) $fila->base_margen);
        $this->assertEqualsWithDelta((float) $fila->base_margen * 1.21, (float) $fila->base_margen_final, 0.000001);
    }

    /**
     * G3. Un artículo nuevo (POST) nace con los derivados calculados y el previus del pivot vacío.
     *
     * @return void
     */
    public function test_guarda_articulo_nuevo()
    {
        list($dueno, $precio_final) = $this->cuenta_ri('g3');

        $respuesta = $this->postJson('api/article', [
            'name'               => 'zz nuevo ' . uniqid(),
            'cost'               => 1000,
            'cost_incluye_iva'   => 0,
            'price'              => null,
            'percentage_gain'    => 50,
            'apply_provider_percentage_gain' => 0,
            'iva_id'             => 2,
            'aplicar_iva'        => 1,
            'online'             => 1,
            'precio_pausado'     => 0,
            'in_offer'           => 0,
            'default_in_vender'  => 0,
            'status'             => 'active',
            'price_types'        => [[
                'id'    => $precio_final->id,
                'pivot' => ['incluir_en_excel_para_clientes' => 1, 'setear_precio_final' => 0, 'visible_en_tienda' => 0],
            ]],
            'price_type_monedas' => [],
            'tags'               => [],
            'addresses'          => [],
        ]);

        $respuesta->assertStatus(201);

        $id    = $respuesta->json('model.id');
        $fila  = DB::table('articles')->where('id', $id)->first();
        $pivot = DB::table('article_price_type')->where('article_id', $id)->where('price_type_id', $precio_final->id)->first();

        $this->assertNotNull($fila->base_margen, 'Un artículo nuevo nace con base_margen.');
        $this->assertNotNull($fila->base_margen_final, 'Un artículo nuevo nace con base_margen_final.');
        $this->assertNotNull($pivot, 'Falta el pivot de «Precio final».');
        $this->assertNull($pivot->previus_final_price);
    }
}
