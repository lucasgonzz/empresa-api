<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\EscondeColumnas;
use Tests\Feature\ListasSiempre\ListasSiempreTestCase;

/**
 * Guardar (o recalcular) un artículo sin cambiarle nada NO le cambia el precio, aunque el cliente
 * tenga `articles.final_price` con más de 2 decimales (misión preservar-precision-legacy-20261121).
 *
 * 🔴 QUE PROTEGE. `ArticleHelper::setFinalPrice()` cuantiza el precio a 2 decimales suponiendo que la
 * columna es DECIMAL(22,2) (commit c5078880). Golden-Breeze tiene la columna con 6 decimales (deriva
 * de esquema vieja) y artículos cargados como 0.266200: al abrir y guardar el artículo sin tocar
 * nada, 0.266200 pasaba a 0.270000 (+1,4 %), con `previus_final_price` y `final_price_updated_at`
 * pisados y un evento en el historial. Medido idéntico en v4.3.27 y v4.3.70.
 *
 * 🔴 QUE NO PROTEGE (y se afirma explícito en CA1-bis). El humo de Golden NO deja `articles` intacto:
 * las pivots de `article_price_type` (NULL en el cliente) reciben el espejo de las monedas en pesos.
 * Son 12 valores y este test los enumera uno por uno: ninguno se omite de la foto ni se da por
 * aceptado. `base_margen`/`base_margen_final` NULL ya se preservan (misión
 * guardar-sin-cambios-derivados-null-20261121): un guardado sin cambios no los completa.
 *
 * Escenario: la columna se ensancha a DECIMAL(22,6) por una conexión PDO aparte (el DDL hace COMMIT
 * implícito y cerraría la transacción del test) y se devuelve a DECIMAL(22,2) en el tearDown pase lo
 * que pase; si una corrida se cortó, el setUp la devuelve antes de empezar.
 * No correr contra una base compartida: ensancha la tabla `articles` de la base del slot.
 *
 * IMPORTANTE: PHP 7.4, sin sintaxis de PHP 8 (ver .mision/RESTRICCIONES.md).
 *
 * @group precios-por-lista
 */
class GuardarSinCambiosPreservaPrecisionTest extends ListasSiempreTestCase
{
    use EscondeColumnas;

    const ARS = 1;
    const USD = 2;

    /** Fecha fija conocida de `final_price_updated_at`: nada del código bajo prueba debería moverla. */
    const FECHA_FIJA = '2026-10-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->devolver_final_price_a_dos_decimales_si_quedo_ancha();
        $this->ensanchar_final_price();
        $this->olvidar_columnas_de_articles_del_lote();
    }

    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];

        // La transacción del test se descarta ANTES del ALTER: el DDL no puede convivir con ella.
        $this->devolver_final_price_a_dos_decimales_si_quedo_ancha();
        $this->olvidar_columnas_de_articles_del_lote();

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Escenario de esquema
    // ------------------------------------------------------------------------------------------

    /**
     * Tipo real de `articles.final_price` leído de information_schema por una conexión aparte
     * (adentro de la transacción del test se vería el snapshot).
     *
     * @return string
     */
    protected function tipo_de_final_price()
    {
        $pdo = $this->conexion_aparte_para_ddl();

        $sentencia = $pdo->prepare(
            'SELECT column_type FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $sentencia->execute(['articles', 'final_price']);

        return strtolower((string) $sentencia->fetchColumn());
    }

    /**
     * Cambia el tipo de la columna por la conexión aparte, sin cerrar la transacción del test.
     *
     * @param  string $definicion  p. ej. DECIMAL(22,6)
     * @return void
     */
    protected function cambiar_tipo_de_final_price($definicion)
    {
        DB::rollBack();

        try {
            $this->conexion_aparte_para_ddl()->exec(
                'ALTER TABLE `articles` MODIFY `final_price` ' . $definicion . ' NULL DEFAULT NULL'
            );
        } finally {
            // Para que el rollback del tearDown de DatabaseTransactions tenga una transacción.
            DB::beginTransaction();
        }
    }

    protected function ensanchar_final_price()
    {
        $this->cambiar_tipo_de_final_price('DECIMAL(22,6)');

        // Control del escenario: sin esto un test que no ensanchó la columna pasaría sin probar nada.
        $this->assertSame(
            'decimal(22,6)',
            $this->tipo_de_final_price(),
            'El escenario no se armó: articles.final_price no quedó en DECIMAL(22,6).'
        );
    }

    /**
     * Red de seguridad: si una corrida anterior se cortó con la columna ancha, la devuelve.
     *
     * @return void
     */
    protected function devolver_final_price_a_dos_decimales_si_quedo_ancha()
    {
        if ($this->tipo_de_final_price() !== 'decimal(22,2)') {
            $this->cambiar_tipo_de_final_price('DECIMAL(22,2)');
        }
    }

    /**
     * `RecalculoDePreciosEnLote` cachea por proceso el SHOW COLUMNS de `articles`: con el tipo
     * cambiado a mano, la caché de otro test (o la mía) quedaría con la escala equivocada.
     *
     * @return void
     */
    protected function olvidar_columnas_de_articles_del_lote()
    {
        $propiedad = new \ReflectionProperty(RecalculoDePreciosEnLote::class, 'columnas_de_articles');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, null);
    }

    // ------------------------------------------------------------------------------------------
    // Cuentas y artículos
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta "tipo Golden": listas, ventas en dólares, dólar 1300, cotiza costos en dólares, IVA al
     * costo y sin impuestos de venta. Cada test arma sus dueños: nada de residuos de la base.
     *
     * @param  int $cantidad_de_listas
     * @return array [User, PriceType[]]
     */
    protected function cuenta_tipo_golden($cantidad_de_listas = 2)
    {
        $dueno = $this->dueno_nuevo('golden');

        DB::table('users')->where('id', $dueno->id)->update([
            'listas_de_precio'           => 1,
            'dollar'                     => 1300,
            'cotizar_precios_en_dolares' => 1,
            'aplicar_iva_al_costo'       => 1,
        ]);

        $dueno = $this->con_extension($dueno, 'ventas_en_dolares');

        $this->sin_impuestos_de_venta($dueno);

        $porcentajes = [20, 50, 30, 40, 10, 60];
        $listas      = [];

        for ($i = 0; $i < $cantidad_de_listas; $i++) {
            $listas[] = $this->otra_lista($dueno, 'zz Lista ' . ($i + 1), $porcentajes[$i], $i + 1);
        }

        $this->actingAs($dueno, 'web');

        return [User::find($dueno->id), $listas];
    }

    /**
     * Cuenta simple: sin listas ni ventas en dólares, el costo en dólares se cotiza a pesos.
     *
     * @param  int $dolar
     * @return \App\Models\User
     */
    protected function cuenta_simple($dolar = 1300)
    {
        $dueno = $this->dueno_nuevo('simple');

        DB::table('users')->where('id', $dueno->id)->update([
            'listas_de_precio'           => 0,
            'dollar'                     => $dolar,
            'cotizar_precios_en_dolares' => 1,
        ]);

        $this->sin_impuestos_de_venta($dueno);

        $this->actingAs($dueno, 'web');

        return User::find($dueno->id);
    }

    protected function sin_impuestos_de_venta($dueno)
    {
        DB::table('sale_taxes')->where('user_id', $dueno->id)->delete();

        ArticlePricesHelper::$sale_taxes_cache = [];
    }

    /**
     * Artículo con sus pivots y monedas por lista armadas como PrecioEnPesosLlegaALaPivotTest, y
     * recalculado UNA vez para que monedas y pivots queden consistentes (estado estable).
     *
     * @return \App\Models\Article
     */
    protected function articulo_golden($dueno, array $listas, $costo_en_dolares, $nombre = 'golden')
    {
        $articulo = $this->articulo($dueno, $nombre, [
            'cost'            => $costo_en_dolares,
            'cost_in_dollars' => 1,
        ]);

        foreach ($listas as $lista) {

            $articulo->price_types()->attach($lista->id, ['percentage' => $lista->percentage]);

            foreach ([self::ARS, self::USD] as $moneda) {
                $articulo->price_type_monedas()->create([
                    'price_type_id'             => $lista->id,
                    'moneda_id'                 => $moneda,
                    'percentage'                => $lista->percentage,
                    'final_price'               => 0,
                    'setear_precio_final'       => 0,
                    'cotizar_desde_otra_moneda' => 0,
                ]);
            }
        }

        $this->recalcular($articulo, $dueno);

        return Article::find($articulo->id);
    }

    protected function escribir_legacy($article_id, $valor)
    {
        DB::table('articles')->where('id', $article_id)->update(['final_price' => $valor]);
    }

    // ------------------------------------------------------------------------------------------
    // Fotos
    // ------------------------------------------------------------------------------------------

    /**
     * Foto de todo lo que el guardado podría mover: la fila de `articles`, las filas de
     * `article_price_type` y de `article_price_type_monedas` del artículo (todas sus columnas) y el
     * historial (`price_changes` y `price_change_price_type`). Sólo se saca `updated_at`, que el
     * guardado toca siempre; nada más se excluye.
     *
     * @return array  clave 'tabla[selector].columna' => valor (string o null)
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

        $foto['price_change_price_type.count'] = (string) DB::table('price_change_price_type')
            ->whereIn('price_change_id', DB::table('price_changes')->where('article_id', $article_id)->pluck('id')->all())
            ->count();

        return $foto;
    }

    /**
     * Claves cuyo valor cambió entre dos fotos, con [antes, después].
     *
     * @return array
     */
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

    /**
     * Texto legible de las diferencias, para el mensaje del assert.
     *
     * @return string
     */
    protected function describir($diferencias)
    {
        $lineas = [];

        foreach ($diferencias as $clave => $par) {
            $lineas[] = $clave . ': ' . var_export($par[0], true) . ' -> ' . var_export($par[1], true);
        }

        return "\n" . implode("\n", $lineas);
    }

    // ------------------------------------------------------------------------------------------
    // Caminos
    // ------------------------------------------------------------------------------------------

    /**
     * Camino de la ficha: GET api/article/{id} y PUT del `model` devuelto, tal cual.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function guardar_sin_cambios($article_id)
    {
        $modelo = $this->getJson('api/article/' . $article_id)->assertOk()->json('model');

        return $this->putJson('api/article/' . $article_id, $modelo);
    }

    protected function precio_de($article_id)
    {
        return DB::table('articles')->where('id', $article_id)->first();
    }

    /**
     * La primera pasada por la ficha normaliza cosas que NADA tienen que ver con el precio (nombre en
     * mayúscula inicial, slug, marca de sincronización con Tienda Nube). Se hace ANTES de escribir el
     * valor legacy para que la foto "antes" ya sea un estado estable: el único movimiento posible de
     * ahí en más es el del precio y sus derivados.
     *
     * @return void
     */
    protected function estabilizar_por_la_ficha($article_id)
    {
        $this->guardar_sin_cambios($article_id)->assertOk();
    }

    /**
     * Cantidad de eventos de historial (price_changes) del artículo.
     *
     * @return int
     */
    protected function eventos($article_id)
    {
        return (int) DB::table('price_changes')->where('article_id', $article_id)->count();
    }

    /**
     * Filas de listas (monedas y pivots) del artículo sin ids, artículo ni fechas: para comparar dos
     * artículos gemelos valor a valor.
     *
     * @return array
     */
    protected function listas_de($article_id)
    {
        $filas = [];

        foreach (DB::table('article_price_type_monedas')->where('article_id', $article_id)->orderBy('price_type_id')->orderBy('moneda_id')->get() as $fila) {
            $f = (array) $fila;
            unset($f['id'], $f['article_id'], $f['created_at'], $f['updated_at']);
            $filas['monedas'][] = $f;
        }

        foreach (DB::table('article_price_type')->where('article_id', $article_id)->orderBy('price_type_id')->get() as $fila) {
            $f = (array) $fila;
            unset($f['id'], $f['article_id'], $f['created_at'], $f['updated_at']);
            $filas['pivots'][] = $f;
        }

        return $filas;
    }

    // ------------------------------------------------------------------------------------------
    // CA1 — guardado sin cambios no mueve nada (estado estable)
    // ------------------------------------------------------------------------------------------

    /**
     * Casos A/B/C/D del diagnóstico: [costo en USD, final guardado].
     *
     * @return array
     */
    public function casos_de_cola()
    {
        return [
            'A 0.266200 (r2 daria 0.27)'  => ['0.22', '0.266200'],
            'B 0.270000 (ya canonico)'    => ['0.22', '0.270000'],
            'C 0.242000 (r2 daria 0.24)'  => ['0.20', '0.242000'],
            'D 121.000000 (entero)'       => ['100', '121.000000'],
        ];
    }

    /**
     * CA1. A y C fallan hoy (el PUT los cuantiza); B y D son guardas de regresión.
     *
     * @dataProvider casos_de_cola
     * @return void
     */
    public function test_guardar_sin_cambios_conserva_precio_y_pivots($costo, $legacy)
    {
        list($dueno, $listas) = $this->cuenta_tipo_golden(2);
        $articulo = $this->articulo_golden($dueno, $listas, $costo);

        $this->estabilizar_por_la_ficha($articulo->id);
        $this->escribir_legacy($articulo->id, $legacy);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $despues = $this->foto($articulo->id);

        $this->assertSame($legacy, $this->precio_de($articulo->id)->final_price, 'El PUT sin cambios movio final_price.');
        $this->assertSame($antes['price_changes.count'], $despues['price_changes.count'], 'El PUT sin cambios registro un evento en price_changes.');

        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals([], $diferencias, 'Guardar sin cambios movio la foto completa:' . $this->describir($diferencias));
    }

    // ------------------------------------------------------------------------------------------
    // CA1-bis — estado Golden REAL: precios preservados, derivados y pivots enumerados
    // ------------------------------------------------------------------------------------------

    /**
     * CA1-bis. Reproduce el artículo 10 de Golden-Breeze: pivots `article_price_type` en NULL y
     * `base_margen`/`base_margen_final` en NULL. El precio y sus fechas quedan intactos; lo único que
     * cambia son EXACTAMENTE 12 valores de pivots, enumerados acá uno por uno; `base_margen*` siguen
     * NULL (CA5 del PLAN guardar-sin-cambios-derivados-null). El comparador
     * de precios del humo sigue siendo estricto: estos 12 seguirán apareciendo como DIFERENCIAS y no se
     * aceptan solos (pregunta P1 del PLAN). Este test no estabiliza el fixture ni elige otro artículo.
     *
     * @return void
     */
    public function test_estado_golden_real_preserva_precio_y_enumera_derivados_y_pivots()
    {
        list($dueno, $listas) = $this->cuenta_tipo_golden(6);
        $articulo = $this->articulo_golden($dueno, $listas, '0.22', 'golden10');

        // Listas 1 y 2 en pesos como Golden 4 y 5: precio fijado en 0.00 y -100 %.
        foreach ([$listas[0], $listas[1]] as $lista) {
            DB::table('article_price_type_monedas')
                ->where('article_id', $articulo->id)
                ->where('price_type_id', $lista->id)
                ->where('moneda_id', self::ARS)
                ->update(['setear_precio_final' => 1, 'final_price' => '0.00', 'percentage' => '-100.00']);
        }

        $this->recalcular($articulo, $dueno);
        $this->estabilizar_por_la_ficha($articulo->id);

        // Estado medido en Golden: precio legacy, fechas fijas, derivados y pivots en NULL.
        DB::table('articles')->where('id', $articulo->id)->update([
            'final_price'            => '0.266200',
            'previus_final_price'    => '346.06',
            'final_price_updated_at' => self::FECHA_FIJA,
            'base_margen'            => null,
            'base_margen_final'      => null,
        ]);

        DB::table('article_price_type')->where('article_id', $articulo->id)->update(['final_price' => null, 'percentage' => null]);

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $despues = $this->foto($articulo->id);
        $fila    = $this->precio_de($articulo->id);

        $this->assertSame('0.266200', $fila->final_price, 'final_price legacy movido por el PUT sin cambios.');
        $this->assertEquals('346.06', $fila->previus_final_price, 'previus_final_price pisado.');
        $this->assertSame(self::FECHA_FIJA, (string) $fila->final_price_updated_at, 'final_price_updated_at movido.');

        $this->assertSame($antes['price_changes.count'], $despues['price_changes.count'], 'El PUT sin cambios registro un evento.');
        $this->assertSame($antes['price_change_price_type.count'], $despues['price_change_price_type.count'], 'El PUT sin cambios registro filas de historial por lista.');

        // Monedas idénticas valor a valor.
        foreach ($antes as $clave => $valor) {
            if (strpos($clave, 'article_price_type_monedas[') === 0) {
                $this->assertSame($valor, $despues[$clave], 'Cambio la moneda ' . $clave);
            }
        }

        // Derivados vacíos y sin cambio real de precio ni de costo: se quedan NULL.
        $this->assertNull($fila->base_margen, 'base_margen NULL fue completado por un guardado sin cambios.');
        $this->assertNull($fila->base_margen_final, 'base_margen_final NULL fue completado por un guardado sin cambios.');

        // Los 12 valores esperados, de pivots (espejo de las monedas en pesos).
        $esperadas = [];

        foreach ($listas as $lista) {

            $en_pesos = 'article_price_type_monedas[lista=' . $lista->id . ',moneda=' . self::ARS . ']';
            $pivot    = 'article_price_type[lista=' . $lista->id . ']';

            $this->assertNotNull($antes[$en_pesos . '.final_price'], 'La foto antes no tiene la fila en pesos de la lista ' . $lista->id);

            $esperadas[$pivot . '.final_price'] = [null, $antes[$en_pesos . '.final_price']];
            $esperadas[$pivot . '.percentage']  = [null, $antes[$en_pesos . '.percentage']];
        }

        // Listas 1 y 2: 0.00 y -100.00, como Golden 4 y 5.
        foreach ([$listas[0], $listas[1]] as $lista) {
            $this->assertSame('0.00', $esperadas['article_price_type[lista=' . $lista->id . '].final_price'][1]);
            $this->assertSame('-100.00', $esperadas['article_price_type[lista=' . $lista->id . '].percentage'][1]);
        }

        // Listas 3..6: espejo de su fila en pesos, y no NULL.
        foreach ([2, 3, 4, 5] as $i) {
            $this->assertNotNull($esperadas['article_price_type[lista=' . $listas[$i]->id . '].final_price'][1]);
            $this->assertNotNull($esperadas['article_price_type[lista=' . $listas[$i]->id . '].percentage'][1]);
        }

        ksort($esperadas);

        $this->assertCount(12, $esperadas);

        $diferencias = $this->diferencias($antes, $despues);

        $this->assertEquals(
            $esperadas,
            $diferencias,
            'La diferencia antes/despues no es exactamente la esperada (12 valores):' . $this->describir($diferencias)
        );
    }

    // ------------------------------------------------------------------------------------------
    // CA2 — recálculo sin cambios (por artículo y en lote)
    // ------------------------------------------------------------------------------------------

    /**
     * CA2. setFinalPrice por artículo y RecalculoDePreciosEnLote sobre A y C: no cuantizan la cola.
     *
     * @return void
     */
    public function test_recalcular_sin_cambios_no_cuantiza_la_cola()
    {
        list($dueno, $listas) = $this->cuenta_tipo_golden(2);
        $a = $this->articulo_golden($dueno, $listas, '0.22', 'a');
        $c = $this->articulo_golden($dueno, $listas, '0.20', 'c');

        $this->estabilizar_por_la_ficha($a->id);
        $this->estabilizar_por_la_ficha($c->id);

        $legacy = [$a->id => '0.266200', $c->id => '0.242000'];

        foreach ($legacy as $id => $valor) {
            $this->escribir_legacy($id, $valor);
        }

        // Por artículo.
        $antes = [$a->id => $this->foto($a->id), $c->id => $this->foto($c->id)];

        $this->recalcular($a, $dueno);
        $this->recalcular($c, $dueno);

        foreach ($legacy as $id => $valor) {
            $this->assertSame($valor, $this->precio_de($id)->final_price, 'setFinalPrice cuantizo el precio legacy del articulo ' . $id);
            $diferencias = $this->diferencias($antes[$id], $this->foto($id));
            $this->assertEquals([], $diferencias, 'setFinalPrice movio la foto del articulo ' . $id . ':' . $this->describir($diferencias));
        }

        // En lote.
        $resultado = RecalculoDePreciosEnLote::recalcular([$a->id, $c->id], User::find($dueno->id), $dueno->id);

        $this->assertSame(2, $resultado['recalculados']);
        $this->assertSame([], $resultado['cambiaron'], 'El lote conto como "cambiaron" articulos cuyo precio no cambio.');

        foreach ($legacy as $id => $valor) {
            $this->assertSame($valor, $this->precio_de($id)->final_price, 'El lote cuantizo el precio legacy del articulo ' . $id);
            $diferencias = $this->diferencias($antes[$id], $this->foto($id));
            $this->assertEquals([], $diferencias, 'El lote movio la foto del articulo ' . $id . ':' . $this->describir($diferencias));
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA3 — gemelo canónico: la cola no influye en las listas
    // ------------------------------------------------------------------------------------------

    /**
     * CA3. A (0.266200) y B (0.270000), mismo costo y mismas listas: tras guardar sin cambios y tras un
     * cambio de dólar, sus filas de monedas y pivots son idénticas valor a valor.
     *
     * @return void
     */
    public function test_la_cola_no_cambia_listas_frente_al_gemelo_canonico()
    {
        list($dueno, $listas) = $this->cuenta_tipo_golden(2);
        $a = $this->articulo_golden($dueno, $listas, '0.22', 'gemelo-a');
        $b = $this->articulo_golden($dueno, $listas, '0.22', 'gemelo-b');

        $this->estabilizar_por_la_ficha($a->id);
        $this->estabilizar_por_la_ficha($b->id);
        $this->escribir_legacy($a->id, '0.266200');
        $this->escribir_legacy($b->id, '0.270000');

        $this->guardar_sin_cambios($a->id)->assertOk();
        $this->guardar_sin_cambios($b->id)->assertOk();

        $this->assertSame('0.266200', $this->precio_de($a->id)->final_price);
        $this->assertEquals($this->listas_de($b->id), $this->listas_de($a->id), 'Tras guardar sin cambios, las listas de A difieren de las del gemelo B.');

        DB::table('users')->where('id', $dueno->id)->update(['dollar' => 1400]);

        RecalculoDePreciosEnLote::recalcular([$a->id, $b->id], User::find($dueno->id), $dueno->id);

        $this->assertSame('0.266200', $this->precio_de($a->id)->final_price);
        $this->assertEquals($this->listas_de($b->id), $this->listas_de($a->id), 'Tras el cambio de dolar, las listas de A difieren de las del gemelo B.');
    }

    // ------------------------------------------------------------------------------------------
    // CA4 — cambio de dólar legítimo
    // ------------------------------------------------------------------------------------------

    /**
     * CA4a. Cuenta tipo Golden: con el dólar 1300 -> 1400 el precio en USD no cambia (queda 0.266200)
     * pero las listas en pesos sí, y la pivot espeja las monedas en pesos.
     *
     * @return void
     */
    public function test_cambio_de_dolar_en_cuenta_con_monedas_recalcula_listas_y_conserva_usd()
    {
        list($dueno, $listas) = $this->cuenta_tipo_golden(2);
        $a = $this->articulo_golden($dueno, $listas, '0.22', 'dolar-a');
        $b = $this->articulo_golden($dueno, $listas, '0.22', 'dolar-b');

        $this->estabilizar_por_la_ficha($a->id);
        $this->estabilizar_por_la_ficha($b->id);
        $this->escribir_legacy($a->id, '0.266200');
        $this->escribir_legacy($b->id, '0.270000');

        $antes_a  = $this->foto($a->id);
        $eventos  = $this->eventos($a->id);

        DB::table('users')->where('id', $dueno->id)->update(['dollar' => 1400]);

        RecalculoDePreciosEnLote::recalcular([$a->id, $b->id], User::find($dueno->id), $dueno->id);

        $this->assertSame('0.266200', $this->precio_de($a->id)->final_price, 'El precio en USD cambio con el dolar.');

        foreach ($listas as $lista) {

            $en_pesos = DB::table('article_price_type_monedas')
                ->where('article_id', $a->id)->where('price_type_id', $lista->id)->where('moneda_id', self::ARS)->first();

            $clave = 'article_price_type_monedas[lista=' . $lista->id . ',moneda=' . self::ARS . '].final_price';

            $this->assertNotSame($antes_a[$clave], (string) $en_pesos->final_price, 'La lista en pesos ' . $lista->id . ' no se movio con el dolar.');

            $pivot = $this->pivote($a, $lista);

            $this->assertSame((string) $en_pesos->final_price, (string) $pivot->final_price, 'La pivot no espeja la fila en pesos.');
        }

        // Valor exacto en pesos fijado contra el gemelo canónico B (no a ojo).
        $this->assertEquals($this->listas_de($b->id), $this->listas_de($a->id), 'A (con cola) y el gemelo B tienen listas distintas.');

        $this->assertSame($eventos + 1, $this->eventos($a->id), 'Se esperaba exactamente 1 evento de historial por el cambio de dolar.');

        $evento = DB::table('price_changes')->where('article_id', $a->id)->orderByDesc('id')->first();
        $cambios = json_decode($evento->cambios_listas, true);

        $this->assertNotEmpty($cambios, 'El evento no trae cambios por lista.');

        $monedas_del_evento = [];
        foreach ($cambios as $cambio) {
            $monedas_del_evento[(int) $cambio['lista_id']][] = isset($cambio['moneda_id']) ? (int) $cambio['moneda_id'] : null;
        }

        foreach ($listas as $lista) {
            $this->assertArrayHasKey($lista->id, $monedas_del_evento, 'El evento no registra la lista ' . $lista->id);
        }
    }

    /**
     * CA4b. Cuenta simple: costo 0.2213 USD, legacy 348.104900. Sin cambios no se mueve nada; con el
     * dólar 1300 -> 1400 el precio se recalcula, se cuantiza y se registra como siempre.
     *
     * @return void
     */
    public function test_cambio_de_dolar_en_cuenta_simple_cuantiza_y_registra()
    {
        $dueno    = $this->cuenta_simple(1300);
        $articulo = $this->articulo($dueno, 'simple-dolar', ['cost' => '0.2213', 'cost_in_dollars' => 1]);
        $this->recalcular($articulo, $dueno);

        $this->estabilizar_por_la_ficha($articulo->id);
        $this->escribir_legacy($articulo->id, '348.104900');
        DB::table('articles')->where('id', $articulo->id)->update(['final_price_updated_at' => self::FECHA_FIJA]);

        $antes   = $this->foto($articulo->id);
        $eventos = $this->eventos($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $this->assertSame('348.104900', $this->precio_de($articulo->id)->final_price, 'El PUT sin cambios cuantizo el precio legacy.');
        $this->assertSame($eventos, $this->eventos($articulo->id), 'El PUT sin cambios registro un evento.');

        $diferencias = $this->diferencias($antes, $this->foto($articulo->id));
        $this->assertEquals([], $diferencias, 'El PUT sin cambios movio la foto:' . $this->describir($diferencias));

        DB::table('users')->where('id', $dueno->id)->update(['dollar' => 1400]);

        RecalculoDePreciosEnLote::recalcular([$articulo->id], User::find($dueno->id), $dueno->id);

        $fila = $this->precio_de($articulo->id);

        $this->assertSame('374.880000', $fila->final_price);
        $this->assertSame('348.10', (string) $fila->previus_final_price);
        $this->assertNotSame(self::FECHA_FIJA, (string) $fila->final_price_updated_at, 'final_price_updated_at no se actualizo.');
        $this->assertSame($eventos + 1, $this->eventos($articulo->id));

        $evento = DB::table('price_changes')->where('article_id', $articulo->id)->orderByDesc('id')->first();
        $this->assertSame('374.88', (string) $evento->final_price);
    }

    // ------------------------------------------------------------------------------------------
    // CA5 / CA6 — cambio de margen y de costo legítimos
    // ------------------------------------------------------------------------------------------

    /**
     * Cuenta simple con un artículo de costo 0.22 en pesos y margen 10 %, legacy 0.292820.
     *
     * @return array [User, Article]
     */
    protected function articulo_con_margen_legacy()
    {
        $dueno    = $this->cuenta_simple(1300);
        $articulo = $this->articulo($dueno, 'margen', ['cost' => '0.22', 'cost_in_dollars' => 0, 'percentage_gain' => 10]);
        $this->recalcular($articulo, $dueno);

        $this->estabilizar_por_la_ficha($articulo->id);
        $this->escribir_legacy($articulo->id, '0.292820');

        return [$dueno, Article::find($articulo->id)];
    }

    /**
     * CA5. Sin cambios queda 0.292820 y 0 eventos; con el margen en 20 % se recalcula (0.32) y registra.
     *
     * @return void
     */
    public function test_cambio_de_margen_es_legitimo()
    {
        list($dueno, $articulo) = $this->articulo_con_margen_legacy();

        $eventos = $this->eventos($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $this->assertSame('0.292820', $this->precio_de($articulo->id)->final_price, 'El PUT sin cambios cuantizo el precio legacy.');
        $this->assertSame($eventos, $this->eventos($articulo->id));

        $modelo = $this->getJson('api/article/' . $articulo->id)->assertOk()->json('model');
        $modelo['percentage_gain'] = 20;
        $this->putJson('api/article/' . $articulo->id, $modelo)->assertOk();

        $fila = $this->precio_de($articulo->id);

        $this->assertSame('0.320000', $fila->final_price);
        $this->assertSame('0.29', (string) $fila->previus_final_price);
        $this->assertSame($eventos + 1, $this->eventos($articulo->id));
        $this->assertSame('0.32', (string) DB::table('price_changes')->where('article_id', $articulo->id)->orderByDesc('id')->value('final_price'));
    }

    /**
     * CA6. Misma cuenta y artículo que CA5: con el costo en 0.30 se recalcula (0.40) y registra.
     *
     * @return void
     */
    public function test_cambio_de_costo_es_legitimo()
    {
        list($dueno, $articulo) = $this->articulo_con_margen_legacy();

        $eventos = $this->eventos($articulo->id);

        $modelo = $this->getJson('api/article/' . $articulo->id)->assertOk()->json('model');
        $modelo['cost'] = '0.30';
        $this->putJson('api/article/' . $articulo->id, $modelo)->assertOk();

        $fila = $this->precio_de($articulo->id);

        $this->assertSame('0.400000', $fila->final_price);
        $this->assertSame($eventos + 1, $this->eventos($articulo->id));
        $this->assertSame('0.40', (string) DB::table('price_changes')->where('article_id', $articulo->id)->orderByDesc('id')->value('final_price'));
    }

    // ------------------------------------------------------------------------------------------
    // CA7 — columna sana (DECIMAL(22,2)) sin cambios de comportamiento
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. Con la columna en DECIMAL(22,2) y el IIBB de la semilla (150 / 0,965 = 155,4404..., por 1,21 de
     * IVA = 188,0829...), guardar sin cambios deja 188.08 y 0 eventos: el arreglo no cambia nada en cuentas sanas.
     *
     * @return void
     */
    public function test_columna_de_dos_decimales_sin_cambios_como_hoy()
    {
        $this->cambiar_tipo_de_final_price('DECIMAL(22,2)');
        $this->olvidar_columnas_de_articles_del_lote();

        $this->assertSame('decimal(22,2)', $this->tipo_de_final_price(), 'El escenario no se armo: la columna no quedo en DECIMAL(22,2).');

        $dueno = User::find(500);

        if (is_null($dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        ArticlePricesHelper::$sale_taxes_cache = [];
        $this->actingAs($dueno, 'web');

        $articulo = $this->articulo($dueno, 'iibb', ['cost' => 100, 'percentage_gain' => 50]);
        $this->recalcular($articulo, $dueno);
        $this->estabilizar_por_la_ficha($articulo->id);

        $this->assertSame('188.08', $this->precio_de($articulo->id)->final_price, 'La precondicion (IIBB de la semilla + IVA 21 %) no produjo 188.08.');

        $antes = $this->foto($articulo->id);

        $this->guardar_sin_cambios($articulo->id)->assertOk();

        $this->assertSame('188.08', $this->precio_de($articulo->id)->final_price);

        $diferencias = $this->diferencias($antes, $this->foto($articulo->id));
        $this->assertEquals([], $diferencias, 'Guardar sin cambios movio la foto:' . $this->describir($diferencias));
    }
}
