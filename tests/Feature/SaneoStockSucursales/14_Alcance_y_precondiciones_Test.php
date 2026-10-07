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
 *   1. una opción numérica que llega VACÍA (`--user_id=`, un script con una variable sin valor) o
 *      PELADA (`--user_id`, lo que pasa con `--user_id $ID` y `$ID` vacío sin comillas) es un error:
 *      si valiera como "no se pasó" saneaba a todos los dueños de la base;
 *   2. si alguna de las tablas no es InnoDB, o falta el índice de un camino de bloqueo, no se
 *      escribe nada (el "falló y se revirtió" sería mentira sin transacciones, y sin índice el
 *      bloqueo escanearía la tabla entera);
 *   3. tras 10 artículos que fallan SEGUIDOS la corrida se corta: es el entorno, no el artículo
 *      (solo un artículo que se sanó reinicia la cuenta, no uno que ya estaba limpio);
 *   4. los montos con decimales (balanzas, kilos) conservan los dos decimales;
 *   5. si la función del sistema falla a mitad de un artículo con variantes, el artículo vuelve
 *      entero a como estaba (las filas que ya había borrado también);
 *   6. `--sin_tope` lista todos los artículos del `--detalle`, en `--ver` y en `--aplicar` (el tope
 *      de 200 es de la consola);
 *   7. `--ver` avisa de antemano cuando `--aplicar` se negaría por el motor o por los índices (solo si
 *      `--aplicar` tendría algo que escribir: sin trabajo sale con exit 0 antes de las precondiciones);
 *   8. si el commit de un artículo falla después de escribir su bloque de reversión, el respaldo lo
 *      anota y el `.sql` avisa que ese bloque no se corra (aunque el mensaje del error no sea UTF-8);
 *   9. el borde numérico: un centavo de desfase se corrige, y un stock NULL con suma cero no
 *      recibe un recálculo ni un movimiento de cantidad cero.
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
     * Siembra N artículos del dueño con una fila viva de 10 en `$s1` y un fantasma de −1 en `$muerta`
     * (stock 9, como lo deja el motor), con inserts masivos: sirve para probar el formato del reporte
     * y los topes sin pagar N `Article::create`.
     *
     * @param  \App\Models\User     $dueno
     * @param  \App\Models\Address  $s1
     * @param  int                  $muerta
     * @param  int                  $cantidad
     * @return int[]  Ids de los artículos.
     */
    protected function sembrar_articulos_con_un_fantasma($dueno, $s1, $muerta, $cantidad)
    {
        $filas_de_articulos = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $filas_de_articulos[] = ['name' => 'zz Masivo ' . $i . ' ' . uniqid(), 'user_id' => $dueno->id, 'stock' => 9];
        }

        DB::table('articles')->insert($filas_de_articulos);

        $ids = DB::table('articles')->where('user_id', $dueno->id)->orderBy('id')->pluck('id')->all();

        $this->assertCount($cantidad, $ids, 'El escenario no quedó armado.');

        $filas_de_pivot = [];

        foreach ($ids as $id) {
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $s1->id, 'amount' => 10];
            $filas_de_pivot[] = ['article_id' => $id, 'address_id' => $muerta, 'amount' => -1];
        }

        DB::table('address_article')->insert($filas_de_pivot);

        return $ids;
    }

    /**
     * Registra una subclase del comando que falla DESPUÉS de escribir el bloque de reversión del
     * PRIMER artículo que se sanea: es lo que pasa si el commit falla, y dentro de la transacción de
     * un test no se puede provocar de otra forma. Los artículos siguientes andan normalmente.
     *
     * @param  string  $mensaje  El mensaje de la excepción (un test lo usa con un byte que no es UTF-8).
     * @return void
     */
    protected function registrar_comando_que_falla_despues_del_bloque_sql($mensaje = 'falla de prueba después de escribir el bloque (como un commit que falla)')
    {
        $comando = new class($mensaje) extends SanearStockDeSucursalesBorradas {
            /** @var int */
            private $bloques_escritos = 0;

            /** @var string */
            private $mensaje;

            public function __construct($mensaje)
            {
                parent::__construct();

                $this->mensaje = $mensaje;
            }

            protected function escribir_bloque_sql($sql)
            {
                parent::escribir_bloque_sql($sql);

                $this->bloques_escritos++;

                // Solo el primer artículo: los que vienen después andan normalmente.
                if ($this->bloques_escritos === 1) {
                    throw new \RuntimeException($this->mensaje);
                }
            }
        };

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
     * 🔴 Solo un artículo que SE SANEÓ prueba que el entorno anda. Uno que ya estaba limpio no escribió
     * nada y no dice nada de la salud del entorno: si reiniciara la cuenta, una falla que solo pega en
     * los artículos que dejan movimiento no cortaría nunca con artículos ya limpios intercalados.
     *
     * Orden de la corrida (por id): S (se sana), F1..F5 (fallan), L (ya limpio), F6..F12 (fallan).
     * Los fallidos suman 10 SEGUIDOS aunque L esté en el medio: se corta en F10 y F11 y F12 ni se intentan.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_ya_limpio_entre_fallidos_no_reinicia_la_cuenta()
    {
        $dueno = $this->dueno('ya-limpio-en-el-medio');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $crear = function ($nombre) use ($dueno, $s1, $muerta) {
            return $this->articulo_con_fantasmas($dueno, $nombre, [$s1->id => 10], [[$muerta, -1]])['articulo'];
        };

        $s = $crear('S se sana');

        $fallan = [];

        for ($i = 1; $i <= 5; $i++) {
            $fallan[] = $crear('F' . $i);
        }

        $l = $crear('L ya limpio');

        for ($i = 6; $i <= 12; $i++) {
            $fallan[] = $crear('F' . $i);
        }

        $ids_que_fallan = array_map(function ($articulo) {
            return (int) $articulo->id;
        }, $fallan);

        StockMovement::creating(function ($movimiento) use ($s, $l, $muerta, $ids_que_fallan) {
            if ((int) $movimiento->article_id === (int) $s->id) {
                // Al sanarse S, a L le desaparecen los fantasmas (como si otra corrida lo hubiera
                // limpiado en el medio): cuando le toque va a estar ya limpio.
                DB::table('address_article')->where('article_id', $l->id)->where('address_id', $muerta)->delete();

                return;
            }

            if (in_array((int) $movimiento->article_id, $ids_que_fallan, true)) {
                throw new \RuntimeException('falla sistemática de prueba');
            }
        });

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con fallidos el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('10 artículos SEGUIDOS fallaron', $this->salida, 'El artículo ya limpio no reinicia la cuenta: los 10 fallidos son seguidos.');
        $this->assertStringContainsString('Ya limpios al momento de tocarlos: 1.', $this->salida, 'El escenario no quedó armado: L tenía que estar ya limpio cuando le tocó.');

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

        $this->assertSame(11, $antes, 'S y los 10 fallidos que se intentaron: F11 y F12 ni se intentan.');
        $this->assertSame(10, $revertidos);

        $this->assertSame(0, $this->filas_en($s, $muerta), 'S se sanó.');
        $this->assertEquals(10.0, $this->stock($s));

        foreach (array_slice($fallan, 10) as $sin_intentar) {
            $this->assertSame(1, $this->filas_en($sin_intentar, $muerta), 'Después del corte no se toca a nadie: ' . $sin_intentar->name . ' conserva su fantasma.');
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

        // 205 artículos con un fantasma cada uno: se prueba el formato del reporte y no el motor.
        $this->sembrar_articulos_con_un_fantasma($dueno, $s1, $muerta, 205);

        $this->assertSame(0, $this->ver($dueno, ['--detalle' => true, '--sin_tope' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(205, preg_match_all('/^  art \d+ · dueño /mu', $this->salida), 'Con --sin_tope el detalle tiene que listar los 205 artículos.');
        $this->assertStringNotContainsString('el detalle se corta a las 200 líneas', $this->salida, 'Con --sin_tope no hay aviso de corte.');
    }

    /**
     * 🔴 La opción PELADA (`--user_id` sin `=` ni valor: lo que pasa con `--user_id $ID` y `$ID` vacío
     * sin comillas) llega a Laravel como `null`, igual que si no se hubiera escrito. Sin distinguirla,
     * `--aplicar --user_id` saneaba a TODOS los dueños de la base.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function una_opcion_numerica_pelada_es_un_error_y_no_escribe_nada()
    {
        $e = $this->dueno_con_un_fantasma('opcion-pelada');

        foreach (['--user_id', '--articulo_id', '--limite', '--lote'] as $opcion) {
            $antes = $this->foto_de_tablas();

            // `null` en el arreglo de opciones es la opción pelada: `--opcion` sin valor.
            $codigo = $this->sanear(['--aplicar' => true, '--salida' => $this->carpeta_de_salida, $opcion => null]);

            $this->assertSame(1, $codigo, $opcion . ' pelado tiene que ser un error de uso (exit 1). Salida:' . "\n" . $this->salida);
            $this->assertStringContainsString($opcion . ' tiene que ser un entero positivo', $this->salida, $opcion . ' pelado: el error tiene que nombrar la opción.');
            $this->assertStringContainsString('(sin valor)', $this->salida, $opcion . ' pelado: el error tiene que decir que llegó sin valor.');

            $this->assertFotosIguales($antes, $this->foto_de_tablas(), $opcion . ' pelado no puede escribir');
            $this->assertFalse(is_dir($this->carpeta_de_salida), $opcion . ' pelado no puede dejar ni la carpeta del respaldo.');
        }

        // Y por el camino REAL de la consola: `Artisan::call()` con el comando como texto y sin parámetros
        // lo arma con un `StringInput`, que hereda de `ArgvInput` (el del `php artisan`). La rama de
        // `ArrayInput` de arriba es la de los tests; esta es la que corre en producción. La ruta va entre
        // comillas y con barras normales: el tokenizador se come las invertidas de una ruta de Windows.
        $carpeta = str_replace('\\', '/', $this->carpeta_de_salida);

        foreach (['--aplicar --user_id', '--user_id --aplicar', '--aplicar --articulo_id', '--aplicar --limite', '--aplicar --lote'] as $forma) {
            $antes = $this->foto_de_tablas();

            $codigo = Artisan::call(self::COMANDO . ' ' . $forma . ' --salida="' . $carpeta . '"');
            $this->salida = Artisan::output();

            $this->assertSame(1, $codigo, '`' . $forma . '` tiene que ser un error de uso (exit 1). Salida:' . "\n" . $this->salida);
            $this->assertStringContainsString('tiene que ser un entero positivo. Llegó: (sin valor)', $this->salida, '`' . $forma . '`: el error tiene que decir que la opción llegó sin valor.');

            $this->assertFotosIguales($antes, $this->foto_de_tablas(), '`' . $forma . '` no puede escribir');
            $this->assertFalse(is_dir($this->carpeta_de_salida), '`' . $forma . '` no puede dejar ni la carpeta del respaldo.');
        }

        $this->assertSame(1, $this->filas_en($e['articulo'], $e['muerta']), 'El fantasma tiene que seguir ahí.');
    }

    /**
     * `--sin_tope` valía solo en `--ver`: con `--aplicar --detalle` el corte a 200 líneas seguía, y
     * quien quiere el listado completo de lo que se sanó (para cruzarlo con el respaldo) no lo tenía.
     * Dos dueños de 205 artículos cada uno: sin `--sin_tope` se listan 200 y se avisa del corte; con él, los 205.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function en_aplicar_el_detalle_corta_a_200_y_sin_tope_lista_todos()
    {
        $cortado = $this->dueno('aplicar-corta');
        $s1_cortado = $this->sucursal($cortado);
        $this->sembrar_articulos_con_un_fantasma($cortado, $s1_cortado, $this->sucursal_muerta($cortado), 205);

        $completo = $this->dueno('aplicar-completo');
        $s1_completo = $this->sucursal($completo);
        $this->sembrar_articulos_con_un_fantasma($completo, $s1_completo, $this->sucursal_muerta($completo), 205);

        $this->assertSame(0, $this->aplicar($cortado, ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(200, preg_match_all('/^  art \d+ → saneado /mu', $this->salida), 'Sin --sin_tope el detalle de --aplicar se corta a 200 líneas.');
        $this->assertStringContainsString('el detalle se corta a las 200 líneas; --sin_tope las lista todas', $this->salida, 'Tiene que avisar del corte y de cómo evitarlo.');
        $this->assertStringContainsString('Artículos saneados: 205', $this->salida, 'El corte es del listado, no del saneo: los 205 se sanearon.');

        $this->assertSame(0, $this->aplicar($completo, ['--detalle' => true, '--sin_tope' => true, '--salida' => $this->carpeta_nueva()]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(205, preg_match_all('/^  art \d+ → saneado /mu', $this->salida), 'Con --sin_tope el detalle de --aplicar lista los 205 artículos.');
        $this->assertStringNotContainsString('el detalle se corta a las 200 líneas', $this->salida, 'Con --sin_tope no hay aviso de corte.');
        $this->assertStringContainsString('Artículos saneados: 205', $this->salida);
    }

    /**
     * 🔴 Un dry-run que dice "todo bien" y un `--aplicar` que después se niega es peor que avisar en el
     * dry-run: `--ver` anticipa la precondición del motor y de los índices (exit 0 igual: no escribe).
     * Y no avisa de lo que no aplica: base sana, dueño sin nada para sanear, o dueño cuyos únicos
     * artículos `--aplicar` no toca (`no_recalculable`): sin trabajo `--aplicar` sale con exit 0 y
     * "No hay nada para sanear" ANTES de cualquier precondición, así que no se negaría a nada.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function ver_avisa_cuando_aplicar_se_negaria_por_el_motor_o_los_indices()
    {
        $e = $this->dueno_con_un_fantasma('ver-avisa');
        $limpio = $this->dueno('ver-sin-trabajo');

        // Un dueño con un artículo afectado que --aplicar no puede tocar: hay artículos, pero no hay trabajo.
        $solo_saltados = $this->dueno('ver-solo-saltados');
        $ajena = $this->sucursal($this->dueno('ver-solo-saltados-ajena'), 'zz Sucursal ajena');
        $this->articulo_no_recalculable($solo_saltados, $ajena, $this->sucursal_muerta($solo_saltados));

        // Base sana: ni una palabra de más.
        $this->assertSame(0, $this->ver($e['dueno']), 'Salida:' . "\n" . $this->salida);
        $this->assertStringNotContainsString('--aplicar se negaría', $this->salida, 'Con la base sana --ver no avisa de nada.');

        $this->registrar_comando_con_preflight(['address_article' => 'MyISAM'], ['address_article_variant.article_variant_id']);

        $antes = $this->foto_de_tablas();

        $this->assertSame(0, $this->ver($e['dueno']), '--ver sigue siendo de solo lectura: avisa pero termina bien (exit 0). Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('Ojo: --aplicar se negaría a escribir en esta base (exit 1)', $this->salida, '--ver tiene que anticipar la negativa de --aplicar.');
        $this->assertStringContainsString('address_article es MyISAM', $this->salida, 'Y decir QUÉ tabla y QUÉ motor.');
        $this->assertStringContainsString('address_article_variant.article_variant_id', $this->salida, 'Y QUÉ índice falta.');

        // Sin nada para sanear no hay negativa que anticipar.
        $this->assertSame(0, $this->ver($limpio), 'Salida:' . "\n" . $this->salida);
        $this->assertStringNotContainsString('--aplicar se negaría', $this->salida, 'Sin artículos afectados --aplicar no se negaría a nada: no se avisa.');

        // Con artículos afectados pero ninguno que --aplicar pueda tocar tampoco: sale con "No hay nada
        // para sanear" antes de las precondiciones.
        $this->assertSame(0, $this->ver($solo_saltados), 'Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('no_recalculable 1', $this->salida, 'El escenario no quedó armado: tiene que haber un artículo afectado que --aplicar no toca.');
        $this->assertStringNotContainsString('--aplicar se negaría', $this->salida, 'Sin trabajo --aplicar sale con exit 0 antes de las precondiciones: no se avisa de una negativa que no va a pasar.');

        $this->assertSame(0, $this->aplicar($solo_saltados, ['--salida' => $this->carpeta_nueva()]), 'Y --aplicar, con la base rota, igual sale bien: no tiene nada para escribir. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('No hay nada para sanear', $this->salida);

        $this->assertFotosIguales($antes, $this->foto_de_tablas(), '--ver no puede escribir');
        $this->assertFalse(is_dir($this->carpeta_de_salida), '--ver no deja carpeta de respaldo.');
    }

    /**
     * 🔴 Si el commit de un artículo falla DESPUÉS de que su bloque de reversión se escribió, ese
     * bloque ya no se puede sacar del `.sql` y quien lo corra pisaría ventas posteriores con las filas
     * de antes. El respaldo lo dice (`bloque_sql_escrito`) y el `.sql` lo avisa con un comentario
     * pegado al bloque. Y el aviso es por artículo: el que falla ANTES de escribir su bloque, y el que
     * anda, no lo llevan.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_el_commit_falla_despues_del_bloque_el_sql_avisa_que_no_se_corra()
    {
        $dueno = $this->dueno('commit-falla');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // El orden de la corrida es el de los ids: A, B, C.
        $a = $this->articulo_con_fantasmas($dueno, 'Falla despues del bloque', [$s1->id => 10], [[$muerta, -1]])['articulo'];
        $b = $this->articulo_con_fantasmas($dueno, 'Falla antes del bloque', [$s1->id => 10], [[$muerta, -1]])['articulo'];
        $c = $this->articulo_con_fantasmas($dueno, 'Anda', [$s1->id => 10], [[$muerta, -1]])['articulo'];

        // B falla al crear su movimiento: antes de que su bloque llegue a escribirse.
        StockMovement::creating(function ($movimiento) use ($b) {
            if ((int) $movimiento->article_id === (int) $b->id) {
                throw new \RuntimeException('falla de prueba antes del bloque');
            }
        });

        // A falla después de escribir su bloque (como un commit que falla).
        $this->registrar_comando_que_falla_despues_del_bloque_sql();

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con fallidos el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Fallidos: 2', $this->salida);

        // A y B se revirtieron enteros; C se sanó.
        foreach ([$a, $b] as $articulo) {
            $this->assertSame(1, $this->filas_en($articulo, $muerta), 'El artículo ' . $articulo->id . ' falló: tiene que conservar su fantasma.');
            $this->assertEquals(9.0, $this->stock($articulo), 'Y su stock crudo.');
            $this->assertCount(0, $this->movimientos($articulo), 'Y no dejar movimiento.');
        }

        $this->assertSame(0, $this->filas_en($c, $muerta), 'C andaba: se sanó.');
        $this->assertEquals(10.0, $this->stock($c));

        // El respaldo dice cuál de los dos fallidos dejó su bloque escrito.
        $errores = [];

        foreach ($this->lineas_del_respaldo($this->carpeta_de_salida) as $linea) {
            if ($linea['evento'] === 'revertido_por_error') {
                $errores[(int) $linea['article_id']] = $linea;
            }
        }

        $this->assertCount(2, $errores, 'Los dos fallidos quedan anotados como revertido_por_error.');
        $this->assertTrue($errores[(int) $a->id]['bloque_sql_escrito'], 'A falló DESPUÉS de escribir su bloque: el respaldo tiene que decirlo.');
        $this->assertFalse($errores[(int) $b->id]['bloque_sql_escrito'], 'B falló ANTES de escribir su bloque: el indicador se reinicia por artículo y no arrastra el de A.');

        // El .sql: un solo aviso, el de A, pegado después de su bloque y antes del de C.
        $sql = $this->sql_de_reversion($this->carpeta_de_salida);

        $this->assertSame(1, substr_count($sql, 'ATENCIÓN'), 'Un solo aviso en el .sql: el del artículo que falló después de escribir su bloque.');
        $this->assertStringContainsString('-- ⚠ ATENCIÓN: el bloque del artículo ' . $a->id . ' de arriba NO se aplicó', $sql);

        $bloque_a = strpos($sql, '-- Artículo ' . $a->id . ' (');
        $aviso = strpos($sql, 'ATENCIÓN');
        $bloque_c = strpos($sql, '-- Artículo ' . $c->id . ' (');

        $this->assertNotFalse($bloque_a, 'El bloque de A tiene que estar en el .sql.');
        $this->assertNotFalse($bloque_c, 'El bloque de C tiene que estar en el .sql.');
        $this->assertFalse(strpos($sql, '-- Artículo ' . $b->id . ' ('), 'B falló antes de escribir su bloque: no tiene ninguno.');
        $this->assertTrue($bloque_a < $aviso && $aviso < $bloque_c, 'El aviso va pegado después del bloque de A y antes del de C.');
    }

    /**
     * Un centavo cuenta. La tolerancia es de medio centavo (el redondeo de un `decimal(12,2)`), no de
     * uno: un desfase de 0,01 se corrige y deja su movimiento.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_desfase_de_un_centavo_se_corrige_y_deja_su_movimiento()
    {
        $dueno = $this->dueno('un-centavo');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        // Vive 10,00 y el fantasma resta 0,01: el motor dejó 9,99.
        $e = $this->articulo_con_fantasmas($dueno, 'Un centavo', [$s1->id => 10], [[$muerta, -0.01]]);

        $this->assertEquals(9.99, $this->stock($e['articulo']), 'El escenario no quedó armado: el motor tenía que dejar 9,99.');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(10.0, $this->stock($e['articulo']), 'Un centavo de desfase se corrige: articles.stock = 10,00.');
        $this->assertSame(0, $this->filas_en($e['articulo'], $muerta));

        $movimientos = $this->movimientos($e['articulo']);

        $this->assertCount(1, $movimientos, 'Y deja su movimiento.');
        $this->assertEquals(0.01, (float) $movimientos[0]->amount, 'amount = 10,00 − 9,99.');
    }

    /**
     * Un artículo que nunca tuvo stock cargado (NULL) y cuyas filas vivas suman 0 no necesita
     * recálculo (regla R): se borra el fantasma, el stock sigue NULL (la función del sistema lo
     * escribiría como 0) y no se deja un movimiento de cantidad cero.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_stock_null_con_suma_cero_pierde_el_fantasma_y_no_deja_movimiento()
    {
        $dueno = $this->dueno('stock-null');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $e = $this->articulo_con_fantasmas($dueno, 'Stock null', [$s1->id => 0], [[$muerta, 3]], null);

        $this->assertNull($this->stock($e['articulo']), 'El escenario no quedó armado: stock NULL.');

        $this->assertSame(0, $this->aplicar($dueno), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($e['articulo'], $muerta), 'El fantasma se borró.');
        $this->assertNull($this->stock($e['articulo']), 'Sin desfase no se llama a la función del sistema (escribiría 0): el stock sigue NULL.');
        $this->assertCount(0, $this->movimientos($e['articulo']), 'Sin cambio de stock no hay movimiento.');
        $this->assertStringContainsString('Artículos saneados: 1 (con movimiento de stock: 0, sin movimiento: 1)', $this->salida);
    }

    /**
     * El mensaje de un error de la base puede traer un byte que no es UTF-8 (un dato mal codificado que
     * el motor repite). Sin `JSON_INVALID_UTF8_SUBSTITUTE`, `json_encode` devuelve false, la línea
     * `revertido_por_error` de ese artículo no se escribe y el aviso del `.sql` (que iba en el mismo
     * `try`) se perdía con ella. Las dos cosas quedan escritas: la línea con el byte reemplazado y el
     * aviso, que va primero y en su propio `try`.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_mensaje_de_error_con_bytes_invalidos_no_pierde_la_linea_del_respaldo_ni_el_aviso_del_sql()
    {
        $dueno = $this->dueno('bytes-invalidos');
        $s1 = $this->sucursal($dueno);
        $muerta = $this->sucursal_muerta($dueno);

        $articulo = $this->articulo_con_fantasmas($dueno, 'Falla con bytes invalidos', [$s1->id => 10], [[$muerta, -1]])['articulo'];

        // "\xB1" suelto no es UTF-8 válido (es un byte de continuación sin su inicio).
        $this->registrar_comando_que_falla_despues_del_bloque_sql("falla con un byte \xB1 que no es UTF-8");

        $codigo = $this->aplicar($dueno);

        $this->assertSame(1, $codigo, 'Con un fallido el comando termina con 1. Salida:' . "\n" . $this->salida);
        $this->assertSame(1, $this->filas_en($articulo, $muerta), 'El artículo falló: tiene que conservar su fantasma.');

        // `lineas_del_respaldo()` decodifica cada línea y falla si alguna no es JSON válido.
        $errores = [];

        foreach ($this->lineas_del_respaldo($this->carpeta_de_salida) as $linea) {
            if ($linea['evento'] === 'revertido_por_error') {
                $errores[] = $linea;
            }
        }

        $this->assertCount(1, $errores, 'La línea revertido_por_error tiene que estar aunque el mensaje no sea UTF-8 válido.');
        $this->assertSame((int) $articulo->id, (int) $errores[0]['article_id']);
        $this->assertStringContainsString('falla con un byte', $errores[0]['error'], 'El mensaje se conserva, con el byte inválido reemplazado.');
        $this->assertStringContainsString('que no es UTF-8', $errores[0]['error']);
        $this->assertTrue($errores[0]['bloque_sql_escrito'], 'Falló después de escribir su bloque: el respaldo lo dice.');

        $this->assertStringContainsString('-- ⚠ ATENCIÓN: el bloque del artículo ' . $articulo->id . ' de arriba NO se aplicó', $this->sql_de_reversion($this->carpeta_de_salida));
    }
}
