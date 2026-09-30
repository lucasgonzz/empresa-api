<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\RollbackArticleImportHistory;
use App\Models\Article;
use App\Models\Combo;
use App\Models\ImportHistory;
use App\Models\PriceUpdateRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Los disparadores del recálculo de los combos calculados (misión combos-calculados, 30/9/2026):
 * "cada vez que cambia el precio o el costo de un artículo se recalculan los combos que lo
 * incluyen".
 *
 * Cada test deja un combo calculado con una cuenta VIEJA (escribiendo el artículo por consulta
 * directa, la escritura que ningún gancho ve) y después dispara UN camino de los que la ley del
 * sistema dice que tienen que enganchar el recálculo, comprobando que el combo quedó con la cuenta
 * nueva. Si alguien saca un gancho, el test de ese camino se pone rojo y los demás no: el rojo dice
 * cuál fue.
 *
 * Los caminos: el guardado de un artículo (`setFinalPrice`), la propagación de descuentos del
 * proveedor (motor en lote), la masiva, el cierre del recálculo por cola, el cierre de la
 * importación, la reversión de una importación, el borrado y la restauración de un artículo, la
 * restauración de un combo y la red de seguridad diaria (`combos:recalcular`).
 *
 * @group combos-calculados
 */
class Recalculo_de_combos_Test extends ComboCalculadoTestCase
{
    /** Costo y precio con los que arranca el artículo del combo. */
    const COSTO_INICIAL  = 100;
    const PRECIO_INICIAL = 250;

    /** Lo que el artículo pasa a valer por consulta directa, sin que ningún gancho se entere. */
    const COSTO_NUEVO  = 180;
    const PRECIO_NUEVO = 400;

    /** @var \App\Models\Article */
    protected $articulo;

    /** @var \App\Models\Combo */
    protected $combo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->con_listas(0);

        $this->articulo = $this->nuevo_articulo([
            'costo_real'  => self::COSTO_INICIAL,
            'final_price' => self::PRECIO_INICIAL,
        ]);

        $this->combo = $this->combo_calculado([[$this->articulo, 2]]);

        ComboCalculadoHelper::guardar($this->combo);

        $this->assertSame(200.0, $this->costo_en_base($this->combo), 'punto de partida: 2 x 100');
        $this->assertSame(500.0, $this->precio_en_base($this->combo), 'punto de partida: 2 x 250');
    }

    /**
     * Cambia el artículo por consulta directa: el combo queda VIEJO y ningún gancho lo vio.
     *
     * @return void
     */
    protected function dejar_el_combo_viejo()
    {
        $this->escribir_crudo($this->articulo, [
            'costo_real'  => self::COSTO_NUEVO,
            'final_price' => self::PRECIO_NUEVO,
        ]);

        $this->assertSame(200.0, $this->costo_en_base($this->combo), 'el combo tiene que seguir viejo hasta que se dispare el camino');
    }

    /**
     * Aserta que el combo quedó con la cuenta nueva (2 unidades del artículo).
     *
     * @param  string  $camino  Para el mensaje.
     * @return void
     */
    protected function assert_combo_al_dia($camino)
    {
        $this->assertSame(360.0, $this->costo_en_base($this->combo), $camino . ': el costo del combo tiene que ser 2 x 180');
        $this->assertSame(800.0, $this->precio_en_base($this->combo), $camino . ': el precio del combo tiene que ser 2 x 400');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Los ganchos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guardar un artículo (`setFinalPrice` con guardar_cambios) rehace los combos que lo incluyen.
     * Se compara contra lo que el artículo QUEDÓ teniendo, no contra un número calculado a mano: lo
     * que se mide es el enganche, no la fórmula de precios.
     *
     * @test
     */
    public function guardar_un_articulo_recalcula_los_combos_que_lo_incluyen()
    {
        $this->articulo->cost            = 300;
        $this->articulo->percentage_gain = 50;
        $this->articulo->save();

        ArticleHelper::setFinalPrice($this->articulo, self::DUENO, $this->dueno);

        $articulo = Article::find($this->articulo->id);

        $this->assertNotEquals(self::PRECIO_INICIAL, (float) $articulo->final_price, 'precondición: el artículo cambió de precio');
        $this->assertNotEquals(self::COSTO_INICIAL, (float) $articulo->costo_real, 'precondición: el artículo cambió de costo');

        $this->assertSame(round(2 * (float) $articulo->costo_real, 2), $this->costo_en_base($this->combo));
        $this->assertSame(round(2 * (float) $articulo->final_price, 2), $this->precio_en_base($this->combo));
    }

    /**
     * Un artículo que no está en ningún combo calculado no toca a los demás combos: ni a uno
     * calculado que lleva OTRO artículo ni a un manual que lleva este.
     *
     * @test
     */
    public function guardar_un_articulo_no_toca_los_combos_que_no_lo_incluyen_ni_los_manuales()
    {
        $otro = $this->nuevo_articulo(['costo_real' => 10, 'final_price' => 20]);

        $ajeno  = $this->combo_calculado([[$otro, 1]]);
        $manual = $this->combo([[$this->articulo, 1]], ['cost' => 7, 'price' => 9]);

        ComboCalculadoHelper::guardar($ajeno);

        // El "otro" queda viejo a propósito: si el disparo lo tocara, el combo ajeno cambiaría.
        $this->escribir_crudo($otro, ['final_price' => 999]);

        $this->articulo->cost            = 300;
        $this->articulo->percentage_gain = 50;
        $this->articulo->save();

        ArticleHelper::setFinalPrice($this->articulo, self::DUENO, $this->dueno);

        $this->assertSame(20.0, $this->precio_en_base($ajeno), 'un combo que no incluye este artículo no se recalcula');
        $this->assertSame(7.0, $this->costo_en_base($manual), 'un combo manual no se recalcula');
        $this->assertSame(9.0, $this->precio_en_base($manual));
    }

    /**
     * 🔴 En modo lote (importaciones y recálculos masivos) `setFinalPrice` NO recalcula por
     * artículo: los pivots de las listas todavía no están escritos y el combo leería precios
     * viejos. Esos procesos cierran con su propio recálculo (los tests de más abajo).
     *
     * @test
     */
    public function en_modo_lote_setfinalprice_no_recalcula_los_combos_por_articulo()
    {
        $this->articulo->cost            = 300;
        $this->articulo->percentage_gain = 50;
        $this->articulo->save();

        PreciosEnLote::activar($this->dueno);

        try {

            ArticleHelper::setFinalPrice($this->articulo, self::DUENO, $this->dueno);

        } finally {

            PreciosEnLote::descartar();
        }

        $this->assertSame(500.0, $this->precio_en_base($this->combo), 'en modo lote el combo no se toca por artículo');
        $this->assertSame(200.0, $this->costo_en_base($this->combo));
    }

    /**
     * La propagación de descuentos del proveedor recalcula con el motor en lote, que escribe en
     * bloque y no pasa por `setFinalPrice`: el combo se rehace al cerrar cada tanda.
     *
     * @test
     */
    public function la_propagacion_de_descuentos_del_proveedor_recalcula_los_combos()
    {
        // El motor recalcula el artículo desde su costo: se lo deja con un costo y un margen conocidos.
        $this->articulo->cost            = 400;
        $this->articulo->percentage_gain = 25;
        $this->articulo->save();

        $cache = [];

        ArticleProviderDiscountHelper::recalcular_precios_de_la_tanda(
            [$this->articulo->id],
            [$this->articulo->id => self::DUENO],
            $cache
        );

        $articulo = Article::find($this->articulo->id);

        $this->assertNotEquals(self::PRECIO_INICIAL, (float) $articulo->final_price, 'precondición: el motor recalculó el artículo');

        $this->assertSame(round(2 * (float) $articulo->costo_real, 2), $this->costo_en_base($this->combo));
        $this->assertSame(round(2 * (float) $articulo->final_price, 2), $this->precio_en_base($this->combo));
    }

    /**
     * La masiva recalcula con el mismo motor, tanda por tanda.
     *
     * @test
     */
    public function la_masiva_recalcula_los_combos()
    {
        $this->articulo->cost            = 400;
        $this->articulo->percentage_gain = 25;
        $this->articulo->save();

        $metodo = new \ReflectionMethod(MasiveUpdateHelper::class, 'recalcular_precios_de_la_masiva');
        $metodo->setAccessible(true);

        $metodo->invoke(null, [Article::find($this->articulo->id)], $this->dueno, self::DUENO, self::DUENO);

        $articulo = Article::find($this->articulo->id);

        $this->assertNotEquals(self::PRECIO_INICIAL, (float) $articulo->final_price, 'precondición: el motor recalculó el artículo');

        $this->assertSame(round(2 * (float) $articulo->final_price, 2), $this->precio_en_base($this->combo));
        $this->assertSame(round(2 * (float) $articulo->costo_real, 2), $this->costo_en_base($this->combo));
    }

    /**
     * El cierre del recálculo por cola (dólar, proveedor, configuración) rehace TODOS los combos
     * calculados del dueño, una vez por corrida, aunque el artículo no haya cambiado de precio
     * (un combo también depende del costo real).
     *
     * @test
     */
    public function el_cierre_del_recalculo_por_cola_recalcula_los_combos_del_dueno()
    {
        $this->dejar_el_combo_viejo();

        $run = PriceUpdateRun::create([
            'user_id'          => self::DUENO,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);

        Notification::fake();
        Queue::fake();

        (new FinalizeSetFinalPrices(self::DUENO, $run->id))->handle();

        $this->assertNotEquals('en_proceso', $run->fresh()->status, 'precondición: la corrida cerró');

        $this->assert_combo_al_dia('cierre del recálculo por cola');
    }

    /**
     * Mientras la corrida sigue abierta (faltan lotes) el cierre NO recalcula: los lotes todavía
     * están escribiendo y el combo se rehace una sola vez, al final.
     *
     * @test
     */
    public function el_cierre_del_recalculo_no_recalcula_mientras_falten_lotes()
    {
        $this->dejar_el_combo_viejo();

        $run = PriceUpdateRun::create([
            'user_id'          => self::DUENO,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 3,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);

        Notification::fake();
        Queue::fake();

        (new FinalizeSetFinalPrices(self::DUENO, $run->id))->handle();

        $this->assertSame(200.0, $this->costo_en_base($this->combo), 'con lotes pendientes no se recalcula todavía');
    }

    /**
     * El cierre de la importación de artículos (que escribe los precios en bloque) rehace los
     * combos calculados del dueño.
     *
     * @test
     */
    public function el_cierre_de_la_importacion_recalcula_los_combos_del_dueno()
    {
        $this->dejar_el_combo_viejo();

        config(['services.anthropic.api_key' => null, 'services.openai.api_key' => null, 'broadcasting.default' => 'null']);

        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        Notification::fake();

        $historial = ImportHistory::create([
            'user_id'    => self::DUENO,
            'model_name' => 'Article',
            'status'     => 'en_proceso',
        ]);

        // Sin ImportStatus: el job cae al cierre normal (mismo camino que un import ya completo).
        (new FinalizeArticleImport(self::DUENO, $historial->id, 0))->handle();

        $this->assertSame('terminado', $historial->fresh()->status, 'precondición: la importación cerró');

        $this->assert_combo_al_dia('cierre de la importación');
    }

    /**
     * La reversión de una importación restaura costos y precios con consultas directas y recalcula
     * con el motor: el combo se rehace al final.
     *
     * @test
     */
    public function la_reversion_de_una_importacion_recalcula_los_combos()
    {
        $this->articulo->cost            = 400;
        $this->articulo->percentage_gain = 25;
        $this->articulo->save();

        $job = (new \ReflectionClass(RollbackArticleImportHistory::class))->newInstanceWithoutConstructor();

        $metodo = new \ReflectionMethod(RollbackArticleImportHistory::class, 'recalcular_precios_derivados');
        $metodo->setAccessible(true);

        // El motor del rollback toma el autor de los price_changes de la propiedad del job.
        $propiedad = new \ReflectionProperty(RollbackArticleImportHistory::class, 'owner_user_id');
        $propiedad->setAccessible(true);
        $propiedad->setValue($job, self::DUENO);

        $metodo->invoke($job, [$this->articulo->id], self::DUENO);

        $articulo = Article::find($this->articulo->id);

        $this->assertNotEquals(self::PRECIO_INICIAL, (float) $articulo->final_price, 'precondición: el motor recalculó el artículo');

        $this->assertSame(round(2 * (float) $articulo->final_price, 2), $this->precio_en_base($this->combo));
        $this->assertSame(round(2 * (float) $articulo->costo_real, 2), $this->costo_en_base($this->combo));
    }

    /**
     * Borrar un artículo NO cambia los números del combo (el componente borrado sigue contando con
     * sus últimos valores; lo que cambia es el stock) y no rompe el borrado.
     *
     * @test
     */
    public function borrar_un_articulo_no_rompe_y_conserva_los_numeros_del_combo()
    {
        $this->delete('api/article/' . $this->articulo->id)->assertStatus(200);

        $this->assertNotNull(DB::table('articles')->where('id', $this->articulo->id)->value('deleted_at'), 'precondición: el artículo quedó borrado');

        $this->assertSame(200.0, $this->costo_en_base($this->combo));
        $this->assertSame(500.0, $this->precio_en_base($this->combo));
        $this->assertSame(0, Combo::find($this->combo->id)->stock_disponible, 'pero el combo ya no se puede armar');
    }

    /**
     * Restaurar un artículo de la papelera rehace los combos que lo incluyen: mientras estuvo
     * borrado pudo cambiar (los recálculos saltean los borrados) y el combo se quedó con la cuenta
     * de antes.
     *
     * @test
     */
    public function restaurar_un_articulo_de_la_papelera_recalcula_los_combos()
    {
        $this->articulo->delete();

        $this->escribir_crudo($this->articulo, [
            'costo_real'  => self::COSTO_NUEVO,
            'final_price' => self::PRECIO_NUEVO,
        ]);

        $this->assertSame(200.0, $this->costo_en_base($this->combo), 'precondición: el combo sigue viejo');

        $this->put('api/papelera/restaurar/article/' . $this->articulo->id)->assertStatus(200);

        $this->assertNull(DB::table('articles')->where('id', $this->articulo->id)->value('deleted_at'), 'precondición: el artículo volvió');

        $this->assert_combo_al_dia('restauración del artículo');
    }

    /**
     * Un combo calculado que estuvo en la papelera NO se recalculó mientras tanto (los recálculos
     * saltean los borrados): al restaurarlo se rehace.
     *
     * @test
     */
    public function restaurar_un_combo_de_la_papelera_lo_recalcula()
    {
        $this->combo->delete();

        $this->dejar_el_combo_viejo();

        // Mientras está borrado, ni el disparador ni la red de seguridad lo tocan.
        ComboCalculadoHelper::recalcular_por_articulos([$this->articulo->id]);
        ComboCalculadoHelper::recalcular_de_un_dueno(self::DUENO);

        $this->assertSame(200.0, $this->costo_en_base($this->combo), 'un combo borrado no se recalcula');

        $this->put('api/papelera/restaurar/combo/' . $this->combo->id)->assertStatus(200);

        $this->assertNull($this->fila($this->combo)->deleted_at, 'precondición: el combo volvió');

        $this->assert_combo_al_dia('restauración del combo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  La red de seguridad
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `combos:recalcular` rehace lo que ningún gancho vio: una escritura directa en `articles`.
     *
     * @test
     */
    public function la_red_de_seguridad_recalcula_lo_que_ningun_gancho_vio()
    {
        $this->dejar_el_combo_viejo();

        $codigo = Artisan::call('combos:recalcular', ['--user' => self::DUENO]);

        $this->assertSame(0, $codigo);
        $this->assert_combo_al_dia('combos:recalcular');
    }

    /**
     * Sale con código 1 si el usuario pedido no existe, sin tocar nada.
     *
     * @test
     */
    public function la_red_de_seguridad_avisa_si_el_usuario_no_existe()
    {
        $this->dejar_el_combo_viejo();

        $codigo = Artisan::call('combos:recalcular', ['--user' => 987654321]);

        $this->assertSame(1, $codigo);
        $this->assertSame(200.0, $this->costo_en_base($this->combo));
    }

    /**
     * Un cambio de cotización del dólar que no pasó por `setFinalPrice` (una escritura directa del
     * dólar del dueño) lo levanta la red de seguridad: el costo de un componente en dólares cambia.
     *
     * @test
     */
    public function la_red_de_seguridad_levanta_un_cambio_de_dolar_que_ningun_gancho_vio()
    {
        $this->dueno->dollar                     = 1000;
        $this->dueno->cotizar_precios_en_dolares = 1;
        $this->dueno->save();

        $usd = $this->nuevo_articulo(['costo_real' => 2, 'cost_in_dollars' => 1, 'final_price' => 3000]);

        $combo = $this->combo_calculado([[$usd, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(2000.0, $this->costo_en_base($combo));

        DB::table('users')->where('id', self::DUENO)->update(['dollar' => 1500]);

        Artisan::call('combos:recalcular', ['--user' => self::DUENO]);

        $this->assertSame(3000.0, $this->costo_en_base($combo), '2 USD x 1500');
    }

    /**
     * Correrla dos veces seguidas no reescribe nada la segunda.
     *
     * @test
     */
    public function la_red_de_seguridad_es_idempotente()
    {
        $this->dejar_el_combo_viejo();

        Artisan::call('combos:recalcular', ['--user' => self::DUENO]);

        $modificado = $this->fila($this->combo)->updated_at;

        sleep(1);

        Artisan::call('combos:recalcular', ['--user' => self::DUENO]);

        $this->assertSame($modificado, $this->fila($this->combo)->updated_at);
    }

    /**
     * La red de seguridad está AGENDADA (una vez por día, de madrugada): sin la entrada en el
     * Kernel el comando existe pero nadie lo corre, y los combos que ningún gancho vio quedan
     * viejos para siempre.
     *
     * @test
     */
    public function la_red_de_seguridad_esta_agendada_una_vez_por_dia()
    {
        $eventos = array_filter(
            app(\Illuminate\Console\Scheduling\Schedule::class)->events(),
            function ($evento) {
                return strpos($evento->command, 'combos:recalcular') !== false;
            }
        );

        $this->assertCount(1, $eventos, 'combos:recalcular tiene que estar agendado exactamente una vez');

        $evento = array_values($eventos)[0];

        $this->assertSame('30 2 * * *', $evento->expression, 'diario, a las 02:30');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Los bordes del helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cuando ningún combo calculado incluye el artículo, el disparador cuesta UNA consulta. Es lo
     * que permite engancharlo en `setFinalPrice`, que corre en cada guardado.
     *
     * @test
     */
    public function un_articulo_que_no_esta_en_ningun_combo_calculado_cuesta_una_consulta()
    {
        $suelto = $this->nuevo_articulo();

        // Calienta el memo de la guarda de esquema (esa pregunta se paga una vez por proceso).
        ComboCalculadoHelper::recalcular_por_articulos([$suelto->id]);

        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas) {
            $consultas[] = $consulta->sql;
        });

        $recalculados = ComboCalculadoHelper::recalcular_por_articulos([$suelto->id]);

        $this->assertSame(0, $recalculados);
        $this->assertCount(1, $consultas, 'una sola consulta: ' . implode(' | ', $consultas));
    }

    /**
     * `recalcular_de_un_dueno()` solo toca los combos de ESE dueño: el otro comercio (otra cuenta
     * de la misma base, como en las bases compartidas viejas) conserva su cuenta hasta que le toque
     * a él.
     *
     * @test
     */
    public function recalcular_un_dueno_no_toca_los_combos_de_otro()
    {
        $otro_dueno = \App\Models\User::create([
            'name'         => 'zz Otro comercio',
            'company_name' => 'zz Otro comercio SA',
            'email'        => 'zz-otro-comercio-' . uniqid() . '@test.local',
            'password'     => \Illuminate\Support\Facades\Hash::make('secret'),
        ]);

        $articulo_de_otro = $this->nuevo_articulo(['final_price' => 10, 'costo_real' => 4, 'user_id' => $otro_dueno->id]);

        $de_otro = $this->combo_calculado([[$articulo_de_otro, 1]], ['user_id' => $otro_dueno->id]);

        // El combo del otro dueño queda con una cuenta vieja y el del dueño 500 también.
        DB::table('combos')->where('id', $de_otro->id)->update(['price' => 1, 'cost' => 1]);
        $this->dejar_el_combo_viejo();

        ComboCalculadoHelper::recalcular_de_un_dueno(self::DUENO);

        $this->assert_combo_al_dia('dueño 500');
        $this->assertSame(1.0, $this->precio_en_base($de_otro), 'el combo de otro dueño quedó como estaba');

        ComboCalculadoHelper::recalcular_de_un_dueno($otro_dueno->id);

        $this->assertSame(10.0, $this->precio_en_base($de_otro), 'y se recalcula cuando le toca a él');
        $this->assertSame(4.0, $this->costo_en_base($de_otro));
    }

    /**
     * Una falla en un combo (acá, uno cuyo dueño no existe) se registra y NO corta a los demás ni
     * al que llamó: es el guardado de un artículo, no puede fallar por un combo mal cargado.
     *
     * @test
     */
    public function una_falla_en_un_combo_no_corta_a_los_demas()
    {
        // Mismo artículo en un combo roto (dueño inexistente) y en uno bueno creado DESPUÉS: los
        // combos se recorren por id, así que el roto va antes que el bueno y, si su falla cortara
        // el recorrido, el bueno quedaría viejo.
        $roto  = $this->combo_calculado([[$this->articulo, 1]], ['user_id' => 987655]);
        $bueno = $this->combo_calculado([[$this->articulo, 1]]);

        $this->assertGreaterThan($roto->id, $bueno->id, 'sanidad del escenario: el roto se recorre primero');

        $this->dejar_el_combo_viejo();

        $recalculados = ComboCalculadoHelper::recalcular_por_articulos([$this->articulo->id]);

        $this->assertSame(400.0, $this->precio_en_base($bueno), 'el combo bueno se recalculó a pesar del roto');
        $this->assert_combo_al_dia('combo original después de una falla en otro');
        $this->assertSame(2, $recalculados, 'el roto no cuenta como recalculado');
    }
}
