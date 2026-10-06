<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Console\Commands\SanearStockDeSucursalesBorradas;
use App\Models\ArticleVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * ALCANCE y PRECONDICIONES de `--aplicar` (misión sanear-stock-de-sucursales-borradas, 6/10/2026,
 * segunda ronda de arreglos tras el chequeo independiente).
 *
 * El comando borra filas y reescribe stock de un negocio real: lo primero que tiene que hacer es
 * negarse cuando no está claro QUÉ ni DÓNDE, y no mentir sobre lo que pasó:
 *
 *   1. una opción numérica que llega VACÍA (`--user_id=`, un script con una variable sin valor) es
 *      un error: si valiera como "no se pasó" saneaba a todos los dueños de la base;
 *   2. si alguna de las tablas no es InnoDB, o falta el índice de un camino de bloqueo, no se
 *      escribe nada (el "falló y se revirtió" sería mentira sin transacciones, y sin índice el
 *      bloqueo escanearía la tabla entera);
 *   3. tras 10 artículos que fallan SEGUIDOS la corrida se corta: es el entorno, no el artículo;
 *   4. los montos con decimales (balanzas, kilos) conservan los dos decimales;
 *   5. si la función del sistema falla a mitad de un artículo con variantes, el artículo vuelve
 *      entero a como estaba (las filas que ya había borrado también);
 *   6. `--sin_tope` lista todos los artículos del `--detalle` (el tope de 200 es de la consola).
 *
 * Todo se afirma leyendo las tablas. El preflight se prueba con una subclase del comando que pisa
 * los dos métodos `protected` que lo consultan: un `ALTER TABLE` adentro de la transacción del test
 * haría COMMIT implícito de todo lo sembrado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Alcance_y_precondiciones_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con una sucursal viva, una muerta y un artículo con un fantasma de −3.
     *
     * @param  string  $etiqueta
     * @return array  ['dueno', 's1', 'muerta', 'articulo']
     */
    protected function dueno_con_un_fantasma($etiqueta)
    {
        $dueno = $this->dueno($etiqueta);
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $articulo = $this->articulo_con_fantasmas($dueno, 'Con fantasma', [$s1->id => 10], [[$muerta, -3]])['articulo'];

        return compact('dueno', 's1', 'muerta', 'articulo');
    }

    /**
     * Registra una subclase del comando que simula una base con motores o índices distintos.
     *
     * @param  array  $motores_pisados  tabla => motor que dice `motores_de_las_tablas()`.
     * @param  array  $indices_faltantes  'tabla.columna' que dice `indices_que_faltan()` además de los reales.
     * @return void
     */
    protected function registrar_comando_con_preflight(array $motores_pisados, array $indices_faltantes)
    {
        $comando = new class($motores_pisados, $indices_faltantes) extends SanearStockDeSucursalesBorradas {
            /** @var array */
            private $motores_pisados;

            /** @var array */
            private $indices_faltantes;

            public function __construct($motores, $indices)
            {
                parent::__construct();

                $this->motores_pisados = $motores;
                $this->indices_faltantes = $indices;
            }

            protected function motores_de_las_tablas()
            {
                return array_merge(parent::motores_de_las_tablas(), $this->motores_pisados);
            }

            protected function indices_que_faltan()
            {
                return array_merge(parent::indices_que_faltan(), $this->indices_faltantes);
            }
        };

        // `all()` fuerza el arranque del Kernel y la carga de los comandos: si el primero que se
        // registra es el de este test, el Artisan nace sin ellos. El registro por nombre pisa al original.
        Artisan::all();
        Artisan::registerCommand($comando);
    }

    /**
     * 🔴 `--user_id=` vacío no es "sin filtro": es un error. Sin esto, `--aplicar --user_id=$ID` con
     * `$ID` vacío saneaba a TODOS los dueños de la base.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_opcion_numerica_vacia_es_un_error_y_no_escribe_nada()
    {
        $e = $this->dueno_con_un_fantasma('opcion-vacia');

        foreach (['--user_id', '--articulo_id', '--limite', '--lote'] as $opcion) {
            $antes = $this->foto_de_tablas();

            $codigo = $this->sanear(['--aplicar' => true, '--salida' => $this->carpeta_de_salida, $opcion => '']);

            $this->assertSame(1, $codigo, $opcion . ' vacío tiene que ser un error de uso (exit 1). Salida:' . "\n" . $this->salida);
            $this->assertStringContainsString('tiene que ser un entero positivo', $this->salida, $opcion . ' vacío: el error tiene que decirlo.');
            $this->assertStringContainsString('(vacío)', $this->salida, $opcion . ' vacío: el error tiene que decir que llegó vacío.');

            $this->assertFotosIguales($antes, $this->foto_de_tablas(), $opcion . ' vacío no puede escribir');
            $this->assertFalse(is_dir($this->carpeta_de_salida), $opcion . ' vacío no puede dejar ni la carpeta del respaldo.');
        }

        $this->assertSame(1, $this->filas_en($e['articulo'], $e['muerta']), 'El fantasma tiene que seguir ahí.');
    }

    /**
     * 🔴 Sin InnoDB no hay rollback por artículo: un error después del DELETE dejaría filas borradas
     * y el reporte diría "se revirtió". Con una tabla que no es InnoDB el comando no escribe nada.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_una_tabla_no_es_innodb_aplicar_se_niega_y_no_toca_nada()
    {
        $e = $this->dueno_con_un_fantasma('preflight-motor');

        $this->registrar_comando_con_preflight(['address_article' => 'MyISAM'], []);

        $antes = $this->foto_de_tablas();

        $codigo = $this->aplicar($e['dueno']);

        $this->assertSame(1, $codigo, 'Con una tabla MyISAM --aplicar tiene que negarse (exit 1). Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('address_article es MyISAM', $this->salida, 'El error tiene que decir QUÉ tabla y QUÉ motor.');
        $this->assertStringContainsString('NO se tocó nada', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Un --aplicar que se niega por el motor de tabla no puede escribir');
        $this->assertFalse(is_dir($this->carpeta_de_salida), 'Ni dejar la carpeta del respaldo.');
        $this->assertSame(1, $this->filas_en($e['articulo'], $e['muerta']), 'El fantasma tiene que seguir ahí.');
    }

    /**
     * 🔴 Sin el índice del camino de bloqueo cada `FOR UPDATE ... WHERE article_id IN (...)` escanea
     * y bloquea el pivot entero y frena las ventas del cliente. Si falta, el comando no escribe.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_falta_un_indice_de_bloqueo_aplicar_se_niega_y_no_toca_nada()
    {
        $e = $this->dueno_con_un_fantasma('preflight-indice');

        $this->registrar_comando_con_preflight([], ['address_article_variant.article_variant_id']);

        $antes = $this->foto_de_tablas();

        $codigo = $this->aplicar($e['dueno']);

        $this->assertSame(1, $codigo, 'Sin un índice de bloqueo --aplicar tiene que negarse (exit 1). Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('address_article_variant.article_variant_id', $this->salida, 'El error tiene que decir QUÉ índice falta.');
        $this->assertStringContainsString('NO se tocó nada', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), 'Un --aplicar que se niega por un índice faltante no puede escribir');
        $this->assertFalse(is_dir($this->carpeta_de_salida));
    }

    /**
     * Una falla sistemática (una columna, un permiso) recorrería toda la lista dejando dos líneas de
     * respaldo por artículo y nada hecho. Tras 10 fallidos SEGUIDOS la corrida se corta.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function tras_diez_fallidos_seguidos_se_corta_la_corrida()
    {
        $dueno = $this->dueno('fallidos-seguidos');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $articulos = [];

        for ($i = 1; $i <= 12; $i++) {
            $articulos[] = $this->articulo_con_fantasmas($dueno, 'Fallido ' . $i, [$s1->id => 10], [[$muerta, -1]])['articulo'];
        }

        // Todos fallan al crear su movimiento: una falla del entorno, no de un artículo.
        StockMovement::creating(function ($movimiento) {
            throw new \RuntimeException('falla sistemática de prueba');
        });

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con fallidos el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('10 artículos SEGUIDOS fallaron', $this->salida, 'Tiene que decir por qué se cortó.');

        // Se intentaron exactamente 10: una línea "antes" y una "revertido_por_error" por intento.
        $antes = 0;
        $revertidos = 0;

        foreach ($this->lineas_del_respaldo($this->carpeta_de_salida) as $linea) {
            if ($linea['evento'] === 'antes') {
                $antes++;
            }

            if ($linea['evento'] === 'revertido_por_error') {
                $revertidos++;
            }
        }

        $this->assertSame(10, $antes, 'Se tenían que intentar exactamente 10 artículos antes de cortar.');
        $this->assertSame(10, $revertidos, 'Cada uno de los 10 tiene que quedar anotado como revertido_por_error.');

        // Ningún artículo quedó modificado: los 10 se revirtieron y los 2 restantes ni se intentaron.
        foreach ($articulos as $articulo) {
            $this->assertSame(1, $this->filas_en($articulo, $muerta), 'Ningún artículo puede haber perdido su fantasma: todos fallaron o no se intentaron.');
            $this->assertEquals(9.0, $this->stock($articulo), 'Ni haber cambiado su stock crudo.');
        }
    }

    /**
     * "Seguidos" es seguidos: un artículo que falla entre dos que andan NO corta la corrida (es un
     * problema de ese artículo). Sin el reinicio del contador tras un éxito, doce fallas salteadas
     * cortarían a la décima y dejarían sin sanear a los que sí andaban.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function fallidos_alternados_con_exitos_no_cortan_la_corrida()
    {
        $dueno = $this->dueno('fallidos-alternados');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $articulos = [];
        $fallan = [];

        for ($i = 0; $i < 24; $i++) {
            $articulo = $this->articulo_con_fantasmas($dueno, 'Alterno ' . $i, [$s1->id => 10], [[$muerta, -1]])['articulo'];

            $articulos[$i] = $articulo;

            // Los pares fallan y los impares andan: la racha más larga de fallas es de una.
            if ($i % 2 === 0) {
                $fallan[] = (int) $articulo->id;
            }
        }

        StockMovement::creating(function ($movimiento) use ($fallan) {
            if (in_array((int) $movimiento->article_id, $fallan, true)) {
                throw new \RuntimeException('falla alternada de prueba');
            }
        });

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Hubo fallidos: el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Fallidos: 12', $this->salida, 'Fallaron los 12 pares y se llegó hasta el final.');
        $this->assertStringNotContainsString('SEGUIDOS', $this->salida, 'Las fallas no son seguidas: no hay corte.');

        foreach ($articulos as $i => $articulo) {
            if ($i % 2 === 0) {
                $this->assertSame(1, $this->filas_en($articulo, $muerta), 'El artículo ' . $i . ' falló: tiene que conservar su fantasma.');
            } else {
                $this->assertSame(0, $this->filas_en($articulo, $muerta), 'El artículo ' . $i . ' andaba: tiene que haberse sanado aunque falle el de al lado.');
                $this->assertEquals(10.0, $this->stock($articulo));
            }
        }
    }

    /**
     * Los montos con decimales (balanzas, kilos): `address_article.amount` y `articles.stock` son
     * decimal(12,2). El stock que queda y el movimiento conservan los dos decimales.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_montos_decimales_el_stock_y_el_movimiento_conservan_los_dos_decimales()
    {
        $dueno = $this->dueno('decimales');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Vive 12,50; fantasmas −2,25 y +0,75: el motor dejó 12,50 − 2,25 + 0,75 = 11,00.
        $e = $this->articulo_con_fantasmas($dueno, 'Decimales', [$s1->id => 12.5], [[$muerta, -2.25], [$muerta, 0.75]]);

        $this->assertEquals(11.0, $this->stock($e['articulo']), 'El escenario no quedó armado: el motor tenía que dejar 11,00.');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(12.5, $this->stock($e['articulo']), 'articles.stock = 12,50 (la fila viva).');
        $this->assertSame('12.50', $this->pivot($e['articulo'])[0]['amount'], 'La fila viva quedó intacta, con sus dos decimales.');
        $this->assertSame(0, $this->filas_en($e['articulo'], $muerta), 'Los dos fantasmas se borraron.');

        $movimientos = $this->movimientos($e['articulo']);

        $this->assertCount(1, $movimientos);
        $this->assertEquals(1.5, (float) $movimientos[0]->amount, 'amount = 12,50 − 11,00.');
        $this->assertEquals(11.0, (float) $movimientos[0]->stock_anterior);
        $this->assertEquals(12.5, (float) $movimientos[0]->stock_resultante);
    }

    /**
     * Si la función del sistema falla a mitad de un artículo con variantes (después de que el saneo
     * ya borró sus fantasmas), el artículo vuelve ENTERO a como estaba: filas, stock y sin
     * movimiento. Es la prueba de que el borrado y el recálculo son una sola unidad.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_la_funcion_del_sistema_falla_a_mitad_el_articulo_se_revierte_entero()
    {
        $dueno = $this->dueno('falla-la-funcion');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_variantes(
            $dueno,
            'Falla la funcion',
            [['vivas' => [$s1->id => 6], 'fantasmas' => [[$muerta, -1]]]],
            [[$muerta, -2]]
        );

        // El desvío que obliga a llamar a la función del sistema (regla R).
        DB::table('articles')->where('id', $e['articulo']->id)->update(['stock' => 9]);

        $pivot_antes = $this->pivot($e['articulo']);
        $pivot_variante_antes = $this->pivot_de_variante($e['variantes'][0]);

        // La función guarda cada variante con `save()`: ahí falla, ya con los fantasmas borrados.
        ArticleVariant::saving(function ($variante) {
            throw new \RuntimeException('falla de prueba dentro de la función del sistema');
        });

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con un artículo fallido el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Fallidos: 1', $this->salida);
        $this->assertStringContainsString('falla de prueba dentro de la función del sistema', $this->salida);

        $this->assertSame($pivot_antes, $this->pivot($e['articulo']), 'El rollback no devolvió las filas del pivot del artículo (incluido su fantasma).');
        $this->assertSame($pivot_variante_antes, $this->pivot_de_variante($e['variantes'][0]), 'El rollback no devolvió las filas del pivot de la variante (incluido su fantasma).');
        $this->assertEquals(9.0, $this->stock($e['articulo']), 'El stock crudo tiene que seguir en 9.');
        $this->assertCount(0, $this->movimientos($e['articulo']), 'Sin movimiento: el artículo no se sanó.');

        // El respaldo lo cuenta: la línea "antes" (write-ahead) y la de la falla.
        $eventos = [];

        foreach ($this->lineas_del_respaldo($this->carpeta_de_salida) as $linea) {
            $eventos[] = $linea['evento'];
        }

        $this->assertSame(['antes', 'revertido_por_error'], $eventos, 'El respaldo tiene que contar que se intentó y que se revirtió.');
    }

    /**
     * `--detalle` corta a 200 líneas (para no ahogar la consola); `--sin_tope` las lista todas, que es
     * lo que hace falta para revisar una base grande antes de `--aplicar`.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function sin_tope_el_detalle_lista_todos_los_articulos()
    {
        $dueno = $this->dueno('sin-tope');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // 205 artículos con un fantasma cada uno, con inserts masivos: se prueba el formato del
        // reporte y no el motor.
        $filas_de_articulos = [];

        for ($i = 1; $i <= 205; $i++) {
            $filas_de_articulos[] = ['name' => 'zz Sin tope ' . $i . ' ' . uniqid(), 'user_id' => $dueno->id, 'stock' => 9];
        }

        DB::table('articles')->insert($filas_de_articulos);

        $ids = DB::table('articles')->where('user_id', $dueno->id)->orderBy('id')->pluck('id')->all();

        $this->assertCount(205, $ids, 'El escenario no quedó armado.');

        $filas_de_pivot = [];

        foreach ($ids as $id) {
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $s1->id, 'amount' => 10];
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $muerta, 'amount' => -1];
        }

        DB::table('address_article')->insert($filas_de_pivot);

        $this->assertSame(0, $this->ver($dueno, ['--detalle' => true, '--sin_tope' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(205, preg_match_all('/^  art \d+ · dueño /mu', $this->salida), 'Con --sin_tope el detalle tiene que listar los 205 artículos.');
        $this->assertStringNotContainsString('el detalle se corta a las 200 líneas', $this->salida, 'Con --sin_tope no hay aviso de corte.');
    }
}
