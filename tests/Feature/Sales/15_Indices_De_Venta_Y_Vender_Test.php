<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\VenderController;
use App\Models\Article;
use App\Models\Client;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Mision "optimizar guardado de venta y busqueda de articulo en vender" (1/9/2026).
 *
 * Cubre las tres piezas que trajo la migracion 2026_09_01_180000_add_indexes_ventas_vender_performance:
 *
 *   1-3. La migracion en si: idempotencia (correrla dos veces no crea ni rompe nada), que NO duplica
 *        los 3 indices que ya estaban cubiertos con otro nombre (article_sale x2, current_acount_
 *        payment_method_sale x1), y que crea los 5 que faltaban con las columnas correctas y en
 *        orden -- el orden importa porque un indice con las columnas al reves existe con el nombre
 *        correcto y no sirve para nada.
 *   4.   Que Controller::num() y SaleController::venta_ya_cread() no cambiaron de comportamiento al
 *        agregarles select(): siguen devolviendo lo mismo que antes, con la misma ventana de 5
 *        segundos para la guarda anti-duplicados.
 *   5.   Que Article::scopeWithAllSinAcopio() es exactamente withAll() menos
 *        sales_with_deliveries_in_acopio, y que VenderController ya no usa withAll() en el escaneo.
 *
 * Se prueba contra MySQL real (empresa_testing_s10): la migracion y las guardas de idempotencia leen
 * information_schema.STATISTICS, que no existe en sqlite. Con otro driver, se saltea con
 * markTestSkipped() (patron de Sales/1_Esquema_De_Pivotes_De_Venta_Test.php:154-161).
 */
class Indices_De_Venta_Y_Vender_Test extends TestCase
{
    use DatabaseTransactions;

    /** Las 5 tablas que toca la migracion. */
    const TABLAS = ['sales', 'article_sale', 'article_purchases', 'articles', 'current_acount_payment_method_sale'];

    /**
     * La migracion vive en el namespace global (como el resto de las migraciones del repo): alcanza
     * con requerirla una vez por proceso de phpunit. Patron de
     * SugerenciasCompras/2_Dedupe_del_pivot_Test.php:98-105.
     *
     * @return void
     */
    private function requerir_migracion()
    {
        $archivo_migracion = base_path('database/migrations/2026_09_01_180000_add_indexes_ventas_vender_performance.php');

        $this->assertFileExists($archivo_migracion);

        if (!class_exists('AddIndexesVentasVenderPerformance', false)) {
            require_once $archivo_migracion;
        }
    }

    /**
     * Foto del esquema de indices de las 5 tablas de la migracion: nombre + posicion + columna, en
     * un solo array comparable con assertEquals. Sirve para probar que una segunda corrida de up()
     * no cambia absolutamente nada.
     *
     * @return array
     */
    private function foto_de_los_indices()
    {
        $foto = [];

        foreach (self::TABLAS as $tabla) {

            $filas = DB::select(
                'SELECT INDEX_NAME as indice, SEQ_IN_INDEX as seq, COLUMN_NAME as columna
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                  ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$tabla]
            );

            $foto[$tabla] = array_map(function ($fila) {
                return $fila->indice . ':' . $fila->seq . ':' . $fila->columna;
            }, $filas);
        }

        return $foto;
    }

    /**
     * true si la tabla tiene un indice con ese nombre exacto.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return bool
     */
    private function indice_existe($tabla, $nombre)
    {
        $filas = DB::select('SHOW INDEX FROM `' . $tabla . '` WHERE Key_name = ?', [$nombre]);

        return !empty($filas);
    }

    /**
     * Columnas del indice, en orden (SEQ_IN_INDEX). Vacio si el indice no existe.
     *
     * @param  string $tabla
     * @param  string $nombre
     * @return array
     */
    private function columnas_del_indice($tabla, $nombre)
    {
        $filas = DB::select(
            'SELECT COLUMN_NAME as columna
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              ORDER BY SEQ_IN_INDEX',
            [$tabla, $nombre]
        );

        return array_map(function ($fila) {
            return $fila->columna;
        }, $filas);
    }

    /**
     * Usuario de prueba fresco, para no compartir ids con ningun otro test del slot.
     *
     * @param  string $sufijo
     * @return \App\Models\User
     */
    private function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio indices ' . $sufijo,
            'email'    => 'indices-venta-vender-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * SHOW INDEX / information_schema son de MySQL. Contra cualquier otro motor el test no aplica.
     *
     * @return void
     */
    private function saltar_si_no_es_mysql()
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped('Estos tests leen el esquema con information_schema, que es de MySQL. Driver actual: ' . $driver . '.');
        }
    }

    /**
     * Correr up() dos veces seguidas no puede tirar excepcion, y la segunda corrida no puede crear
     * ni modificar ningun indice: reproduce el escenario de lamartina2, donde los 8 indices ya
     * existen creados a mano y la migracion tiene que pasar en verde sin hacer nada.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function la_migracion_es_idempotente_corriendola_dos_veces()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        $migracion = new \AddIndexesVentasVenderPerformance();

        // Primera corrida: en el slot la migracion real ya corrio (el runner migra antes de la
        // suite), asi que esto en si mismo prueba que up() tolera encontrar todo puesto.
        $migracion->up();

        $foto_antes = $this->foto_de_los_indices();

        $excepcion = null;

        try {
            $migracion->up();
        } catch (\Throwable $e) {
            $excepcion = $e;
        }

        $this->assertNull($excepcion, 'La segunda corrida de up() no puede tirar excepcion: '
            . ($excepcion ? $excepcion->getMessage() : ''));

        $foto_despues = $this->foto_de_los_indices();

        $this->assertEquals($foto_antes, $foto_despues,
            'La segunda corrida de up() no puede crear ni modificar ningun indice: tiene que ser un no-op.');
    }

    /**
     * De los 8 indices declarados, 3 ya estaban cubiertos con otro nombre (article_sale x2,
     * current_acount_payment_method_sale x1). La migracion no puede crear el nombre nuevo al lado
     * del viejo -- eso serian 2 indices redundantes por columna en cada uno de los ~40 clientes que
     * ya venian con las migraciones 050 / 375.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function la_migracion_no_duplica_indices_ya_cubiertos_por_otra_migracion()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        (new \AddIndexesVentasVenderPerformance())->up();

        $this->assertFalse($this->indice_existe('article_sale', 'article_sale_sale_id_idx'),
            'article_sale_sale_id_index ya cubre (sale_id): crear article_sale_sale_id_idx al lado seria redundante.');
        $this->assertFalse($this->indice_existe('article_sale', 'article_sale_article_id_idx'),
            'article_sale_article_id_index ya cubre (article_id): idem.');
        $this->assertFalse($this->indice_existe('current_acount_payment_method_sale', 'caps_sale_id_idx'),
            'current_acount_payment_method_sale_sale_id_index ya cubre (sale_id): idem.');

        $this->assertTrue($this->indice_existe('article_sale', 'article_sale_sale_id_index'),
            'El indice default de la migracion 050 tiene que seguir existiendo.');
        $this->assertTrue($this->indice_existe('article_sale', 'article_sale_article_id_index'),
            'El indice default de la migracion 050 tiene que seguir existiendo.');
        $this->assertTrue($this->indice_existe('current_acount_payment_method_sale', 'current_acount_payment_method_sale_sale_id_index'),
            'El indice default de la migracion 375 tiene que seguir existiendo.');
    }

    /**
     * Los 5 indices que SI faltaban se crean con las columnas correctas y EN ORDEN. El chequeo de
     * orden es el que detecta el modo de falla silencioso: un indice con las columnas al reves
     * existe con el nombre correcto y no sirve para nada.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function la_migracion_crea_los_indices_que_faltan()
    {
        $this->saltar_si_no_es_mysql();
        $this->requerir_migracion();

        (new \AddIndexesVentasVenderPerformance())->up();

        $this->assertEquals(['user_id', 'num'], $this->columnas_del_indice('sales', 'sales_user_id_num_idx'));
        $this->assertEquals(['user_id', 'created_at'], $this->columnas_del_indice('sales', 'sales_user_id_created_at_idx'));
        $this->assertEquals(['article_id', 'delivered_amount'], $this->columnas_del_indice('article_sale', 'article_sale_article_delivered_idx'));
        $this->assertEquals(['article_id'], $this->columnas_del_indice('article_purchases', 'article_purchases_article_id_idx'));
        $this->assertEquals(['bar_code'], $this->columnas_del_indice('articles', 'articles_bar_code_idx'));
    }

    /**
     * El select('num') de Controller::num() y el select('num', 'total', 'created_at') de
     * SaleController::venta_ya_cread() no cambiaron ningun comportamiento: siguen devolviendo
     * exactamente lo mismo que antes del arreglo de performance.
     *
     * @group sales
     * @test
     */
    public function num_y_venta_ya_cread_no_cambian_de_comportamiento()
    {
        $user   = $this->usuario_de_test('n4');
        $client = Client::create(['name' => 'Cliente indices n4', 'user_id' => $user->id]);

        $controller = new Controller();

        // Tabla vacia para este user_id: is_null($last) -> 1.
        $this->assertEquals(1, $controller->num('sales', null, 'user_id', $user->id),
            'Sin ventas previas para este user_id, num() tiene que devolver 1.');

        Sale::create(['user_id' => $user->id, 'client_id' => $client->id, 'num' => 1, 'total' => 100]);
        Sale::create(['user_id' => $user->id, 'client_id' => $client->id, 'num' => 2, 'total' => 100]);
        Sale::create(['user_id' => $user->id, 'client_id' => $client->id, 'num' => 7, 'total' => 100]);

        // Devuelve MAX(num) + 1 (8), no COUNT(*) + 1 (4): el 7 esta salteado a proposito.
        $this->assertEquals(8, $controller->num('sales', null, 'user_id', $user->id),
            'num() tiene que devolver el maximo + 1, no el conteo de filas + 1.');

        Sale::create(['user_id' => $user->id, 'client_id' => $client->id, 'num' => null, 'total' => 100]);

        // MySQL ordena los NULL al final en ORDER BY ... DESC: la fila con num=7 sigue siendo $last.
        // Esto prueba que ->select('num') no le cambio el comportamiento a la funcion.
        $this->assertEquals(8, $controller->num('sales', null, 'user_id', $user->id),
            'Agregar una fila con num NULL no puede cambiar el resultado: sigue ganando la de num=7.');

        // --- venta_ya_cread(): la guarda anti-duplicados, con la ventana de 5 segundos intacta ---

        $this->actingAs($user, 'web');

        Log::spy();

        $sale_reciente = Sale::create([
            'user_id'     => $user->id,
            'client_id'   => $client->id,
            'employee_id' => null,
            'num'         => 100,
            'total'       => 555.50,
            'created_at'  => now(),
        ]);

        $sale_controller = new SaleController();

        $request = (object) [
            'client_id'   => $client->id,
            'employee_id' => 0,
            'total'       => 555.50,
        ];

        $this->assertTrue($sale_controller->venta_ya_cread($request),
            'Con una venta identica creada hace 0 segundos (dentro de la ventana de 5s), la guarda tiene que verla y abortar.');

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje) use ($sale_reciente) {
            return strpos($mensaje, 'Casi se vuelve a crear venta') !== false
                && strpos($mensaje, (string) $sale_reciente->num) !== false
                && strpos($mensaje, (string) $sale_reciente->total) !== false;
        })->once();

        // Sacamos la venta de la ventana de 5 segundos: la guarda ahora tiene que dejar pasar.
        DB::table('sales')->where('id', $sale_reciente->id)->update(['created_at' => now()->subSeconds(10)]);

        $this->assertFalse($sale_controller->venta_ya_cread($request),
            'Fuera de la ventana de 5 segundos, la guarda tiene que dejar pasar la venta: la ventana sigue significando 5 segundos.');
    }

    /**
     * Guardia contra el drift entre withAll() y withAllSinAcopio(): tienen que diferir en
     * EXACTAMENTE sales_with_deliveries_in_acopio, ni una relacion mas ni una menos. Y el escaneo de
     * Vender no puede volver a usar withAll() en un merge futuro.
     *
     * @group sales
     * @group esquema-ventas
     * @test
     */
    public function las_dos_listas_difieren_en_una_sola_relacion()
    {
        $con = array_keys(Article::withAll()->getEagerLoads());
        $sin = array_keys(Article::withAllSinAcopio()->getEagerLoads());

        $this->assertEquals(['sales_with_deliveries_in_acopio'], array_values(array_diff($con, $sin)),
            'withAllSinAcopio tiene que ser withAll MENOS sales_with_deliveries_in_acopio y nada mas. '
            . 'Si alguien agrega una relacion a withAll y se olvida del otro scope, Vender deja de cargar '
            . 'algo que la pantalla si usa, en silencio.');

        $this->assertEquals([], array_values(array_diff($sin, $con)),
            'withAllSinAcopio no puede cargar nada que withAll no cargue.');

        // Regresion barata contra "alguien revirtio el arreglo sin querer en un merge": ninguno de
        // los 3 metodos del escaneo puede volver a llamar ->withAll(). Patron de lectura de fuente
        // por reflexion de Demo/AvisoDeSetupCompletadoTest.php:441-449.
        foreach (['search_bar_code', 'check_balanza', 'check_balanza_plu'] as $metodo) {

            $reflexion = new ReflectionMethod(VenderController::class, $metodo);

            $lineas = file($reflexion->getFileName());

            $cuerpo = implode('', array_slice(
                $lineas,
                $reflexion->getStartLine() - 1,
                $reflexion->getEndLine() - $reflexion->getStartLine() + 1
            ));

            $this->assertStringNotContainsString('->withAll()', $cuerpo,
                'VenderController::' . $metodo . '() no puede volver a usar withAll(): tiene que usar '
                . 'withAllSinAcopio(), que es el arreglo de performance de esta mision.');
        }
    }
}
