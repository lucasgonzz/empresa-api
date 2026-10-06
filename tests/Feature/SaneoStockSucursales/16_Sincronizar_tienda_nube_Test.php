<?php

namespace Tests\Feature\SaneoStockSucursales;

use App\Console\Commands\SanearStockDeSucursalesBorradas;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * `--sincronizar`: marcar para sincronizar con Tienda Nube los artículos cuyo stock cambió por el saneo
 * (misión sanear-stock-sincronizar-tn, 6/10/2026).
 *
 * Tienda Nube publica `articles.stock`, así que un artículo saneado que dejó un movimiento (su stock
 * global cambió) queda desfasado allá hasta su próximo movimiento. Con `--aplicar --sincronizar` el
 * comando lo marca con `TiendaNubeSyncArticleService::add_article_to_sync()`: eso NO llama a la API,
 * inserta una fila pendiente en `sync_to_t_n_articles` que sube después el scheduler del cliente.
 *
 * Lo que fijan estos tests:
 *
 *   1. se marca el artículo saneado CON movimiento y en Tienda Nube (una fila pendiente, con su dueño);
 *   2. sin la opción no se marca nada ni el respaldo trae eventos `tn_*`;
 *   3. que el artículo esté o no en Tienda Nube lo decide `add_article_to_sync()` (`tiendanube_product_id`
 *      o `disponible_tienda_nube`); el comando cuenta aparte los que no se suben;
 *   4. un artículo saneado SIN movimiento (su stock ya estaba bien) no se marca;
 *   5. un artículo de la papelera no se marca (al scheduler le llegaría `null` y le tumbaría la cola);
 *   6. con la instalación sin Tienda Nube (`USA_TIENDA_NUBE` apagado) no se marca nada y se avisa AL EMPEZAR;
 *   7. no se duplica una fila pendiente que ya había, y una ya terminada no la reemplaza;
 *   8. un artículo que falla y se revierte no se marca, y el resto sí;
 *   9. `--ver --sincronizar` no marca nada;
 *  10. si el marcado falla, el artículo queda saneado, la corrida sigue, se anota en el respaldo y sale con 1;
 *  11. con `--limite` solo se marcan los que se sanearon;
 *  12. un artículo con variantes también se marca.
 *
 * Cada test fija `USA_TIENDA_NUBE` explícitamente (prendida o apagada): no depende del `.env.testing`.
 * Todo se afirma leyendo las tablas con `DB::table()`.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Sincronizar_tienda_nube_Test extends SaneoStockSucursalesTestCase
{
    /**
     * Un dueño con una sucursal viva y una muerta.
     *
     * @param  string  $etiqueta
     * @return array  ['dueno', 's1', 'muerta']
     */
    protected function escenario($etiqueta)
    {
        $dueno = $this->dueno($etiqueta);

        return ['dueno' => $dueno, 's1' => $this->sucursal($dueno), 'muerta' => $this->sucursal_muerta($dueno)];
    }

    /**
     * Un artículo que `--aplicar` sanea CON movimiento (vive 10, fantasma −2, stock 8 como lo deja el
     * motor: el saneo lo lleva a 10 y deja un movimiento de +2) y que está en Tienda Nube.
     *
     * @param  array   $e
     * @param  string  $nombre
     * @return \App\Models\Article
     */
    protected function articulo_saneable_en_tienda_nube(array $e, $nombre)
    {
        $articulo = $this->articulo_con_fantasmas($e['dueno'], $nombre, [$e['s1']->id => 10], [[$e['muerta'], -2]])['articulo'];

        $this->articulo_en_tienda_nube($articulo);

        return $articulo;
    }

    /**
     * Los eventos `tn_*` del respaldo JSONL de la última corrida, por evento.
     *
     * @return array  evento => lista de líneas.
     */
    protected function eventos_tienda_nube_del_respaldo()
    {
        $eventos = [];

        foreach ($this->lineas_del_respaldo($this->carpeta_de_salida) as $linea) {
            if (strpos($linea['evento'], 'tn_') === 0) {
                $eventos[$linea['evento']][] = $linea;
            }
        }

        return $eventos;
    }

    /**
     * Registra una subclase del comando cuyo marcado FALLA para un artículo (lo que pasaría si la base
     * o el servicio lanzaran algo), y delega en el original para los demás.
     *
     * @param  int  $article_id  El artículo cuyo marcado lanza.
     * @return void
     */
    protected function registrar_comando_que_falla_al_marcar($article_id)
    {
        $comando = new class($article_id) extends SanearStockDeSucursalesBorradas {
            /** @var int */
            private $id_que_falla;

            public function __construct($id)
            {
                parent::__construct();

                $this->id_que_falla = (int) $id;
            }

            protected function marcar_para_tienda_nube($article_id, $dueno_id)
            {
                if ((int) $article_id === $this->id_que_falla) {
                    throw new \RuntimeException('falla de prueba al marcar');
                }

                return parent::marcar_para_tienda_nube($article_id, $dueno_id);
            }
        };

        Artisan::all();
        Artisan::registerCommand($comando);
    }

    /**
     * 🔴 El caso que justifica la opción: un artículo saneado con movimiento y en Tienda Nube queda
     * marcado, con UNA fila pendiente a nombre de su dueño, y el reporte lo cuenta y lo dice en el detalle.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_sincronizar_el_articulo_saneado_con_movimiento_y_en_tienda_nube_queda_marcado()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-marcado');
        $articulo = $this->articulo_saneable_en_tienda_nube($e, 'En Tienda Nube');

        $this->assertCount(0, $this->cola_tienda_nube($articulo), 'El escenario no quedó armado: la cola tiene que empezar vacía.');

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true, '--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $e['muerta']), 'El saneo tiene que haber borrado el fantasma.');
        $this->assertEquals(10.0, $this->stock($articulo), 'Y dejado el stock en la suma de lo que se ve.');
        $this->assertCount(1, $this->movimientos($articulo), 'Con un movimiento: es lo que dispara el marcado.');

        $cola = $this->cola_tienda_nube($articulo);

        $this->assertCount(1, $cola, 'El artículo saneado con movimiento y en Tienda Nube tiene que quedar con UNA fila pendiente.');
        $this->assertSame('pendiente', $cola[0]['status']);
        $this->assertSame((int) $e['dueno']->id, (int) $cola[0]['user_id'], 'La fila va a nombre del dueño del artículo.');

        $this->assertStringContainsString('sincroniza con Tienda Nube', $this->salida, 'El alcance tiene que decir que se sincroniza.');
        $this->assertStringContainsString('Tienda Nube (--sincronizar): marcados para sincronizar 1', $this->salida);
        $this->assertStringContainsString('· tienda nube: marcado', $this->salida, 'La línea de detalle del artículo tiene que decirlo.');
        $this->assertStringContainsString('el scheduler del cliente los sube a la API de Tienda Nube en serie', $this->salida, 'Y avisar quién llama a la API y cómo.');

        $eventos = $this->eventos_tienda_nube_del_respaldo();

        $this->assertArrayHasKey('tn_marcado', $eventos, 'El respaldo tiene que dejar el rastro del marcado.');
        $this->assertCount(1, $eventos['tn_marcado']);
        $this->assertSame((int) $articulo->id, (int) $eventos['tn_marcado'][0]['article_id']);
    }

    /**
     * Sin `--sincronizar` el comando se comporta exactamente como antes: ninguna fila en la cola, ni
     * bloque de Tienda Nube en el reporte, ni eventos `tn_*` en el respaldo.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function sin_la_opcion_no_se_marca_nada()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-sin-opcion');
        $articulo = $this->articulo_saneable_en_tienda_nube($e, 'En Tienda Nube sin la opcion');

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--detalle' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertCount(1, $this->movimientos($articulo), 'El saneo se hizo igual.');
        $this->assertCount(0, $this->cola_tienda_nube($articulo), 'Sin --sincronizar no se encola nada, aunque el artículo esté en Tienda Nube.');

        $this->assertStringNotContainsString('Tienda Nube', $this->salida, 'Sin la opción el reporte no habla de Tienda Nube.');
        $this->assertStringNotContainsString('tienda nube:', $this->salida, 'Ni la línea de detalle.');
        $this->assertSame([], $this->eventos_tienda_nube_del_respaldo(), 'Ni el respaldo trae eventos tn_*.');
    }

    /**
     * Si el artículo está o no en Tienda Nube lo decide `add_article_to_sync()`: con `tiendanube_product_id`
     * o con `disponible_tienda_nube` se marca; con ninguno de los dos no. El comando cuenta aparte los
     * que tuvieron movimiento y no se suben.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function la_marca_la_decide_add_article_to_sync_con_el_id_de_producto_o_con_disponible()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-gates');

        $fuera = $this->articulo_con_fantasmas($e['dueno'], 'No esta en TN', [$e['s1']->id => 10], [[$e['muerta'], -2]])['articulo'];

        $con_id = $this->articulo_saneable_en_tienda_nube($e, 'Con id de producto');

        $disponible = $this->articulo_con_fantasmas($e['dueno'], 'Disponible en TN', [$e['s1']->id => 10], [[$e['muerta'], -2]])['articulo'];
        DB::table('articles')->where('id', $disponible->id)->update(['disponible_tienda_nube' => 1]);

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        foreach ([$fuera, $con_id, $disponible] as $articulo) {
            $this->assertCount(1, $this->movimientos($articulo), 'Los tres se sanearon con movimiento.');
        }

        $this->assertCount(0, $this->cola_tienda_nube($fuera), 'Sin tiendanube_product_id ni disponible_tienda_nube no se sube.');
        $this->assertCount(1, $this->cola_tienda_nube($con_id), 'Con tiendanube_product_id se marca.');
        $this->assertCount(1, $this->cola_tienda_nube($disponible), 'Con disponible_tienda_nube también.');

        $this->assertStringContainsString('marcados para sincronizar 2', $this->salida);
        $this->assertStringContainsString('con movimiento pero que no se suben 1', $this->salida);
        $this->assertStringContainsString('(artículo que no está en Tienda Nube o instalación sin ella: 1 · papelera: 0)', $this->salida);
    }

    /**
     * Un artículo saneado SIN movimiento (su `articles.stock` ya estaba bien: solo había que borrar el
     * fantasma) no cambió lo que publica Tienda Nube: no se marca, aunque esté ahí.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_saneado_sin_movimiento_no_se_marca_aunque_este_en_tienda_nube()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-sin-movimiento');

        // Vive 10, fantasma −2 y el stock YA está en 10 (alguien lo corrigió a mano): se borra el fantasma y nada más.
        $articulo = $this->articulo_con_fantasmas($e['dueno'], 'Stock ya bien', [$e['s1']->id => 10], [[$e['muerta'], -2]], 10)['articulo'];
        $this->articulo_en_tienda_nube($articulo);

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertSame(0, $this->filas_en($articulo, $e['muerta']), 'El fantasma se borró.');
        $this->assertCount(0, $this->movimientos($articulo), 'Sin cambio de stock no hay movimiento.');
        $this->assertCount(0, $this->cola_tienda_nube($articulo), 'Y lo que publica Tienda Nube no cambió: no se marca.');

        $this->assertStringContainsString('Artículos saneados: 1 (con movimiento de stock: 0', $this->salida);
        $this->assertStringContainsString('marcados para sincronizar 0 · con movimiento pero que no se suben 0', $this->salida);
    }

    /**
     * 🔴 Un artículo de la papelera NO se marca: el scheduler carga el artículo de la fila sin la
     * papelera, le llega `null` y le tumba la corrida a todas las pendientes de ese dueño. El saneo sí
     * lo toca (con su movimiento) y el reporte lo cuenta aparte.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_de_la_papelera_con_movimiento_no_se_marca()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-papelera');
        $articulo = $this->articulo_saneable_en_tienda_nube($e, 'En la papelera');

        DB::table('articles')->where('id', $articulo->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertCount(1, $this->movimientos($articulo), 'El saneo toca la papelera igual: deja su movimiento.');
        $this->assertEquals(10.0, $this->stock($articulo));
        $this->assertCount(0, $this->cola_tienda_nube($articulo), 'Pero un artículo de la papelera no se marca.');

        $this->assertStringContainsString('marcados para sincronizar 0 · con movimiento pero que no se suben 1', $this->salida);
        $this->assertStringContainsString('papelera: 1)', $this->salida, 'El reporte tiene que decir que fue por la papelera.');
        $this->assertStringContainsString('fallidos al marcar 0', $this->salida, 'No es un error.');
    }

    /**
     * Con la instalación sin Tienda Nube (`USA_TIENDA_NUBE` apagado) `add_article_to_sync()` no encola
     * nada: el saneo se hace igual, no se marca ninguno y se AVISA al empezar (no recién en el resumen).
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_la_instalacion_sin_tienda_nube_no_se_marca_nada_y_se_avisa_al_empezar()
    {
        $this->prender_tienda_nube('false');

        $e = $this->escenario('tn-apagada');
        $articulo = $this->articulo_saneable_en_tienda_nube($e, 'En TN con la instalacion apagada');

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertCount(1, $this->movimientos($articulo), 'El saneo se hizo igual.');
        $this->assertCount(0, $this->cola_tienda_nube($articulo), 'Con USA_TIENDA_NUBE apagado no se encola nada.');

        $aviso = strpos($this->salida, 'USA_TIENDA_NUBE está apagado en esta instalación');
        $this->assertNotFalse($aviso, 'Tiene que avisar que la instalación no usa Tienda Nube. Salida:' . "\n" . $this->salida);
        $this->assertLessThan(strpos($this->salida, 'Tanda 1/'), $aviso, 'Y avisarlo ANTES de empezar con las tandas.');

        $this->assertStringContainsString('marcados para sincronizar 0 · con movimiento pero que no se suben 1', $this->salida);
    }

    /**
     * `add_article_to_sync()` no duplica una fila pendiente: el artículo cuenta como marcado (el scheduler
     * lo subirá como esté cuando la procese). Una fila ya terminada (`exitosa`) no cuenta: se encola otra.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function no_se_duplica_una_fila_pendiente_y_una_ya_terminada_no_la_reemplaza()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-pendiente');

        $con_pendiente = $this->articulo_saneable_en_tienda_nube($e, 'Ya tenia una pendiente');
        $con_exitosa = $this->articulo_saneable_en_tienda_nube($e, 'Ya tenia una exitosa');

        $ahora = date('Y-m-d H:i:s');

        DB::table('sync_to_t_n_articles')->insert([
            ['article_id' => $con_pendiente->id, 'user_id' => $e['dueno']->id, 'status' => 'pendiente', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['article_id' => $con_exitosa->id, 'user_id' => $e['dueno']->id, 'status' => 'exitosa', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertCount(1, $this->cola_tienda_nube($con_pendiente), 'Ya había una pendiente: no se duplica.');

        $cola = $this->cola_tienda_nube($con_exitosa);

        $this->assertCount(2, $cola, 'La exitosa queda y se suma una pendiente nueva: lo que se subió antes no refleja este stock.');
        $this->assertSame(['exitosa', 'pendiente'], [$cola[0]['status'], $cola[1]['status']]);

        $this->assertStringContainsString('marcados para sincronizar 2', $this->salida, 'Los dos cuentan como marcados.');
    }

    /**
     * Se marca DESPUÉS del commit de cada artículo: uno que falla y se revierte no queda marcado, y el
     * resto de la corrida sí se marca.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_que_falla_y_se_revierte_no_se_marca_y_el_resto_si()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-falla-y-revierte');

        $falla = $this->articulo_saneable_en_tienda_nube($e, 'Falla al crear su movimiento');
        $anda = $this->articulo_saneable_en_tienda_nube($e, 'Anda');

        StockMovement::creating(function ($movimiento) use ($falla) {
            if ((int) $movimiento->article_id === (int) $falla->id) {
                throw new \RuntimeException('falla de prueba al crear el movimiento');
            }
        });

        $codigo = $this->aplicar($e['dueno'], ['--sincronizar' => true]);

        $this->assertSame(1, $codigo, 'Hubo un artículo fallido: exit 1. Salida:' . "\n" . $this->salida);
        $this->assertStringContainsString('Fallidos: 1', $this->salida);

        $this->assertSame(1, $this->filas_en($falla, $e['muerta']), 'El artículo fallido se revirtió: conserva su fantasma.');
        $this->assertCount(0, $this->cola_tienda_nube($falla), 'Y no se marca: no se sanó.');

        $this->assertSame(0, $this->filas_en($anda, $e['muerta']));
        $this->assertCount(1, $this->cola_tienda_nube($anda), 'El que anduvo sí.');

        $this->assertStringContainsString('marcados para sincronizar 1', $this->salida);
    }

    /**
     * `--ver --sincronizar` no marca nada: la opción solo cuenta con `--aplicar`, y el comando lo dice.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function ver_con_sincronizar_no_marca_nada_y_avisa_que_solo_cuenta_con_aplicar()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-ver');
        $articulo = $this->articulo_saneable_en_tienda_nube($e, 'En TN, solo mirar');

        $antes = $this->foto_de_tablas();

        $this->assertSame(0, $this->ver($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertStringContainsString('--sincronizar solo cuenta en --aplicar', $this->salida);
        $this->assertStringNotContainsString('sincroniza con Tienda Nube', $this->salida, 'El alcance no puede decir que sincroniza: --ver no escribe.');

        $this->assertCount(0, $this->cola_tienda_nube($articulo), '--ver no marca nada.');
        $this->assertFotosIguales($antes, $this->foto_de_tablas(), '--ver --sincronizar no puede escribir');
        $this->assertFalse(is_dir($this->carpeta_de_salida), 'Ni dejar carpeta de respaldo.');
    }

    /**
     * 🔴 Si el marcado falla (la base, el servicio), el artículo YA está saneado y commiteado: no se
     * revierte nada, la corrida sigue con el resto, el fallo queda en el respaldo (`tn_error`) y el
     * reporte nombra el id. El comando sale con 1 porque lo pedido (sincronizar) no se cumplió entero.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function si_el_marcado_falla_el_articulo_queda_saneado_la_corrida_sigue_y_sale_con_uno()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-falla-el-marcado');

        $falla = $this->articulo_saneable_en_tienda_nube($e, 'Falla el marcado');
        $anda = $this->articulo_saneable_en_tienda_nube($e, 'Anda el marcado');

        $this->registrar_comando_que_falla_al_marcar($falla->id);

        $codigo = $this->aplicar($e['dueno'], ['--sincronizar' => true]);

        $this->assertSame(1, $codigo, 'Con un marcado fallido el comando termina con 1. Salida:' . "\n" . $this->salida);

        // El saneo de los dos está hecho: el fallo del marcado no revierte nada.
        foreach ([$falla, $anda] as $articulo) {
            $this->assertSame(0, $this->filas_en($articulo, $e['muerta']), 'El artículo ' . $articulo->name . ' tiene que haberse sanado.');
            $this->assertEquals(10.0, $this->stock($articulo));
            $this->assertCount(1, $this->movimientos($articulo));
        }

        $this->assertCount(0, $this->cola_tienda_nube($falla), 'El que falló al marcar no tiene fila.');
        $this->assertCount(1, $this->cola_tienda_nube($anda), 'La corrida siguió: el otro sí quedó marcado.');

        $this->assertStringContainsString('Fallidos: 0.', $this->salida, 'El saneo no tuvo ningún fallido: es el marcado.');
        $this->assertStringContainsString('marcados para sincronizar 1', $this->salida);
        $this->assertStringContainsString('fallidos al marcar 1', $this->salida);
        $this->assertStringContainsString('No se pudieron marcar', $this->salida);
        $this->assertStringContainsString('falla de prueba al marcar', $this->salida, 'El motivo tiene que estar a la vista.');
        $this->assertStringContainsString((string) $falla->id, $this->salida, 'Y el id del artículo.');

        $eventos = $this->eventos_tienda_nube_del_respaldo();

        $this->assertArrayHasKey('tn_error', $eventos, 'El fallo queda anotado en el respaldo.');
        $this->assertCount(1, $eventos['tn_error']);
        $this->assertSame((int) $falla->id, (int) $eventos['tn_error'][0]['article_id']);
        $this->assertSame('falla de prueba al marcar', $eventos['tn_error'][0]['error']);

        $this->assertArrayHasKey('tn_marcado', $eventos);
        $this->assertCount(1, $eventos['tn_marcado'], 'Y el que anduvo, su rastro.');
        $this->assertSame((int) $anda->id, (int) $eventos['tn_marcado'][0]['article_id']);
    }

    /**
     * Con `--limite` solo se marcan los artículos que se sanearon: el que no se alcanzó ni se toca ni
     * se marca. (El marcado va uno por uno, después de cada commit: un corte no deja saneados sin marcar.)
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function con_limite_solo_se_marcan_los_articulos_que_se_sanearon()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-limite');

        $primero = $this->articulo_saneable_en_tienda_nube($e, 'Primero');
        $segundo = $this->articulo_saneable_en_tienda_nube($e, 'Segundo');
        $tercero = $this->articulo_saneable_en_tienda_nube($e, 'Tercero');

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true, '--limite' => 2]), 'Salida:' . "\n" . $this->salida);

        $this->assertCount(1, $this->cola_tienda_nube($primero), 'El primero se sanó y se marcó.');
        $this->assertCount(1, $this->cola_tienda_nube($segundo), 'El segundo también.');

        $this->assertSame(1, $this->filas_en($tercero, $e['muerta']), 'El tercero quedó sin tocar por el límite.');
        $this->assertCount(0, $this->cola_tienda_nube($tercero), 'Y sin marcar.');

        $this->assertStringContainsString('marcados para sincronizar 2', $this->salida);
    }

    /**
     * Un artículo CON VARIANTES que cambia de stock también se marca: lo que publica Tienda Nube es
     * `articles.stock`, y con variantes el saneo lo recalcula desde ellas.
     *
     * @group saneo-stock-sucursales
     * @test
     */
    public function un_articulo_con_variantes_con_movimiento_y_en_tienda_nube_se_marca()
    {
        $this->prender_tienda_nube('true');

        $e = $this->escenario('tn-variantes');

        $articulo = $this->articulo_con_variantes(
            $e['dueno'],
            'Con variantes en TN',
            [['vivas' => [$e['s1']->id => 6], 'fantasmas' => [[$e['muerta'], -1]]]],
            [[$e['muerta'], -2]]
        )['articulo'];

        // El desvío que obliga a recalcular con la función del sistema (stock 9 en vez de 6).
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 9]);
        $this->articulo_en_tienda_nube($articulo);

        $this->assertSame(0, $this->aplicar($e['dueno'], ['--sincronizar' => true]), 'Salida:' . "\n" . $this->salida);

        $this->assertEquals(6.0, $this->stock($articulo), 'El saneo lo llevó a la suma de su variante.');
        $this->assertCount(1, $this->movimientos($articulo), 'Con un movimiento.');
        $this->assertCount(1, $this->cola_tienda_nube($articulo), 'Y se marcó para Tienda Nube.');
    }
}
