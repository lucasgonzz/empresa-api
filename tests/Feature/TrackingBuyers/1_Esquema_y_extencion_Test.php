<?php

namespace Tests\Feature\TrackingBuyers;

use App\Models\BuyerTrackingEvent;
use App\Models\ExtencionEmpresa;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión tracking-buyers-tienda — el esquema compartido y la extensión que lo enciende.
 *
 * Estos tests protegen el modo de falla propio de una misión CRUZADA: el esquema lo crea
 * empresa-api y lo escribe tienda-api, así que una columna que no está, que quedó con otro
 * nombre o —peor— con un tipo más angosto que su fuente no se manifiesta acá, se manifiesta
 * como un insert que falla en silencio del otro lado del contrato. Por eso se verifican las
 * columnas y los TIPOS contra information_schema, no la existencia de la tabla a secas.
 *
 * Los índices se leen con SHOW INDEX en vez de asumirlos: son el único motivo por el que la
 * tabla de alto volumen es consultable, y un índice que no se creó no rompe ningún test
 * funcional — sólo hace lenta la lectura del ERP dentro de un año, cuando ya nadie se acuerda.
 *
 * Y la parte de la extensión protege lo de siempre con este repo: que el seeder que Lucas corre
 * en las bases de producción DUPLIQUE el catálogo. `ExtencionSeeder` hace create() en un foreach
 * sin firstOrCreate, así que correrlo dos veces duplica todo; el standalone de esta misión tiene
 * que ser idempotente, y eso sólo se puede afirmar corriéndolo dos veces y contando.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Esquema_y_extencion_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión, compartido con tienda-api (que lo consulta por la base, uniendo
     * extencion_empresa_user con extencion_empresas) y con el SPA de la tienda.
     *
     * @var string
     */
    const SLUG = 'tracking_buyers';

    /**
     * Nombre mostrado al asignar la extensión en el admin.
     *
     * @var string
     */
    const NAME = 'Seguimiento de compradores en la tienda';

    /**
     * Deja la base sin la extensión, para que el test arranque siempre del mismo estado
     * (la base del slot puede tener el catálogo ya sembrado de una corrida anterior).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        ExtencionEmpresa::where('slug', self::SLUG)->delete();
    }

    /**
     * Tipo de columna tal como lo ve MySQL (`bigint unsigned`, `varchar(32)`, ...).
     *
     * @param string $tabla
     * @param string $columna
     * @return string|null Null si la columna no existe
     */
    protected function tipo_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            'SELECT COLUMN_TYPE AS tipo FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );

        return $fila ? $fila->tipo : null;
    }

    /**
     * Columnas que componen un índice, en orden, o null si el índice no existe.
     *
     * @param string $tabla
     * @param string $nombre
     * @return array<int, string>|null
     */
    protected function columnas_del_indice($tabla, $nombre)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", [$nombre]);

        if (empty($filas)) {
            return null;
        }

        usort($filas, function ($a, $b) {
            return $a->Seq_in_index - $b->Seq_in_index;
        });

        return array_map(function ($fila) {
            return $fila->Column_name;
        }, $filas);
    }

    /**
     * Corre el seeder standalone (el mismo que se declara en el despliegue para las bases de
     * producción existentes; nunca ExtencionSeeder).
     *
     * @return void
     */
    protected function correr_seeder()
    {
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionTrackingBuyersSeeder',
        ])->assertExitCode(0);
    }

    /**
     * Las columnas de la tabla de eventos crudos, con el tipo exacto. Los tipos de las FK
     * lógicas están espejados de su tabla de origen: `buyers.id`, `articles.id`,
     * `categories.id`, `sub_categories.id` y `orders.id` son todos bigint unsigned, y
     * `users.id` es int unsigned porque su migración usa increments() y no bigIncrements.
     * Que esto quede clavado en un test es el punto: es el contrato con tienda-api.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_tabla_de_eventos_tiene_sus_columnas_con_el_tipo_de_la_tabla_de_origen()
    {
        $this->assertTrue(Schema::hasTable('buyer_tracking_events'));

        $esperados = [
            'id'              => 'bigint unsigned',
            'user_id'         => 'int unsigned',
            'buyer_id'        => 'bigint unsigned',
            'visitor_id'      => 'char(36)',
            'session_id'      => 'char(36)',
            'event_type'      => 'varchar(32)',
            'article_id'      => 'bigint unsigned',
            'category_id'     => 'bigint unsigned',
            'sub_category_id' => 'bigint unsigned',
            'search_term'     => 'varchar(120)',
            'results_count'   => 'int unsigned',
            'quantity'        => 'int unsigned',
            'amount'          => 'decimal(22,2)',
            'dwell_ms'        => 'int unsigned',
            'order_id'        => 'bigint unsigned',
            'occurred_at'     => 'datetime',
            'created_at'      => 'timestamp',
            'updated_at'      => 'timestamp',
        ];

        foreach ($esperados as $columna => $tipo) {
            $this->assertEquals(
                $tipo,
                $this->tipo_de_columna('buyer_tracking_events', $columna),
                "buyer_tracking_events.{$columna} no tiene el tipo del contrato con tienda-api."
            );
        }

        /*
         * buyer_id nullable no es un detalle: null = visitante anónimo, y ese es el caso
         * normal de la tienda (el grupo auth:sanctum de tienda-api está comentado). Si la
         * columna quedara NOT NULL, la ingesta rechazaría la mayoría del tráfico.
         */
        $nulabilidad = DB::selectOne(
            "SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'buyer_tracking_events'
             AND COLUMN_NAME = 'buyer_id'"
        );
        $this->assertEquals('YES', $nulabilidad->n, 'buyer_id tiene que aceptar null: es el visitante anónimo.');
    }

    /**
     * Los CUATRO índices de la tabla de eventos, con sus columnas en orden — y ni uno más:
     * es una tabla de escritura intensiva y cada árbol extra se paga en cada insert.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_tabla_de_eventos_tiene_exactamente_sus_cuatro_indices()
    {
        $esperados = [
            'bte_user_ocurrido_index'     => ['user_id', 'occurred_at'],
            'bte_buyer_ocurrido_index'    => ['buyer_id', 'occurred_at'],
            'bte_articulo_ocurrido_index' => ['article_id', 'occurred_at'],
            'bte_visitante_index'         => ['visitor_id'],
        ];

        foreach ($esperados as $nombre => $columnas) {
            $this->assertEquals(
                $columnas,
                $this->columnas_del_indice('buyer_tracking_events', $nombre),
                "El índice {$nombre} no existe o no tiene las columnas esperadas."
            );
        }

        /*
         * "Y ni uno más": los 4 índices + el PRIMARY. Si alguien agrega un quinto índice,
         * este test lo frena para que tenga que justificarlo, que es exactamente la
         * conversación que hay que tener antes de encarecer cada insert de la tabla.
         */
        $nombres = DB::select("SHOW INDEX FROM buyer_tracking_events");
        $distintos = array_unique(array_map(function ($fila) {
            return $fila->Key_name;
        }, $nombres));

        $this->assertCount(
            5,
            $distintos,
            'buyer_tracking_events tiene índices de más o de menos (esperado: PRIMARY + 4).'
        );
    }

    /**
     * El agregado diario: sus columnas, sus tres índices, y que NO tenga un unique.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_tabla_del_agregado_diario_tiene_sus_columnas_y_sus_tres_indices_sin_unique()
    {
        $this->assertTrue(Schema::hasTable('buyer_tracking_daily'));

        /*
         * search_term, results_count y visitantes están acá porque son lo único que la tabla
         * puede recordar de una búsqueda y de cuánta gente distinta pasó: a los 90 días la
         * purga se lleva los eventos crudos y lo que no esté en estas columnas no se puede
         * reconstruir con nada. Que estén clavadas en el test es para que un "esto no lo usa
         * nadie todavía" no se las lleve puestas antes de que la misión de lectura las use.
         */
        $esperados = [
            'id'             => 'bigint unsigned',
            'user_id'        => 'int unsigned',
            'fecha'          => 'date',
            'buyer_id'       => 'bigint unsigned',
            'article_id'     => 'bigint unsigned',
            'event_type'     => 'varchar(32)',
            'search_term'    => 'varchar(120)',
            'results_count'  => 'int unsigned',
            'total'          => 'int unsigned',
            'visitantes'     => 'int unsigned',
            'dwell_ms_total' => 'bigint unsigned',
            'amount_total'   => 'decimal(22,2)',
            'created_at'     => 'timestamp',
            'updated_at'     => 'timestamp',
        ];

        foreach ($esperados as $columna => $tipo) {
            $this->assertEquals(
                $tipo,
                $this->tipo_de_columna('buyer_tracking_daily', $columna),
                "buyer_tracking_daily.{$columna} no tiene el tipo esperado."
            );
        }

        $indices = [
            'btd_user_fecha_index'     => ['user_id', 'fecha'],
            'btd_buyer_fecha_index'    => ['buyer_id', 'fecha'],
            'btd_articulo_fecha_index' => ['article_id', 'fecha'],
        ];

        foreach ($indices as $nombre => $columnas) {
            $this->assertEquals(
                $columnas,
                $this->columnas_del_indice('buyer_tracking_daily', $nombre),
                "El índice {$nombre} no existe o no tiene las columnas esperadas."
            );
        }

        /*
         * Ningún índice único fuera del PRIMARY, y esto es a propósito: con buyer_id y
         * article_id nullable, en MySQL NULL != NULL y dos filas con buyer_id null nunca
         * chocarían — el unique no protegería la idempotencia, sólo la aparentaría. La
         * idempotencia la da el comando (borra el día y lo reinserta), y el test 2 la mide.
         */
        $unicos = DB::select("SHOW INDEX FROM buyer_tracking_daily WHERE Non_unique = 0");
        $nombres_unicos = array_unique(array_map(function ($fila) {
            return $fila->Key_name;
        }, $unicos));

        $this->assertEquals(
            ['PRIMARY'],
            array_values($nombres_unicos),
            'buyer_tracking_daily tiene un índice único: la idempotencia la da el comando, no el esquema.'
        );
    }

    /**
     * Los dos índices que `views` nunca tuvo. Cierra el hallazgo del relevamiento.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_tabla_views_tiene_sus_dos_indices_nuevos()
    {
        $this->assertEquals(
            ['viewable_type', 'viewable_id'],
            $this->columnas_del_indice('views', 'views_viewable_index'),
            'views_viewable_index no existe: la polimórfica sigue sin índice.'
        );

        $this->assertEquals(
            ['buyer_id'],
            $this->columnas_del_indice('views', 'views_buyer_index'),
            'views_buyer_index no existe: Buyer::views() sigue escaneando la tabla entera.'
        );
    }

    /**
     * La lista blanca de tipos de evento es el contrato con la ingesta de tienda-api, que la
     * espeja. Está clavada acá para que un renombre no pase desapercibido: los dos proyectos
     * nunca llegan a producción a la vez, así que renombrar un tipo hace que la tienda vieja
     * siga mandando el nombre anterior y esos eventos se descarten en silencio.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_lista_blanca_de_tipos_de_evento_es_la_del_contrato()
    {
        $this->assertEquals(
            [
                'product_view',
                'search',
                'cart_add',
                'cart_remove',
                'checkout_start',
                'checkout_complete',
            ],
            BuyerTrackingEvent::TIPOS,
            'Cambió la lista blanca de tipos de evento: tienda-api la espeja y hay que actualizar las dos puntas.'
        );
    }

    /**
     * Dos corridas seguidas del seeder standalone dejan UNA sola fila con el slug, y no tocan
     * el resto del catálogo.
     *
     * @group tracking_buyers
     * @test
     */
    public function el_seeder_de_la_extencion_es_idempotente_y_no_altera_el_resto_del_catalogo()
    {
        /*
         * Fila testigo del catálogo. Va sembrada a mano porque la base de testing del slot
         * puede tener extencion_empresas vacía, y contra una tabla vacía la comparación del
         * final sería [] contra [] — una aserción que pasa sin medir nada.
         */
        // forceCreate: el modelo no declara $fillable y afuera de `artisan db:seed` no rige el
        // Model::unguarded() que aplica el comando, así que un create() común no asignaría nada.
        $testigo = ExtencionEmpresa::forceCreate([
            'name' => 'Testigo de catalogo (solo test)',
            'slug' => 'testigo_de_catalogo_del_test',
        ]);

        $total_antes = ExtencionEmpresa::count();
        $otras_antes = $this->foto_del_resto_del_catalogo();
        $this->assertContains(
            $testigo->id . '|testigo_de_catalogo_del_test|Testigo de catalogo (solo test)',
            $otras_antes,
            'La foto del catálogo no incluye la fila testigo: la comparación del final no mediría nada.'
        );

        $this->correr_seeder();

        $total_primera = ExtencionEmpresa::count();
        $this->assertEquals(1, ExtencionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals($total_antes + 1, $total_primera);

        $this->correr_seeder();

        // Segunda corrida: nada cambió. Es la aserción que da sentido al test.
        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El seeder duplicó la extensión al correrlo dos veces: no es idempotente.'
        );
        $this->assertEquals(
            $total_primera,
            ExtencionEmpresa::count(),
            'La segunda corrida cambió la cantidad de filas de extencion_empresas.'
        );

        $this->assertEquals($otras_antes, $this->foto_del_resto_del_catalogo());
    }

    /**
     * La fila que siembra es la que esperan los tres gates: el slug exacto que consulta
     * tienda-api por la base y que lee el SPA, y el nombre con el que se asigna en el admin.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_extencion_queda_con_el_slug_y_el_nombre_esperados()
    {
        $this->correr_seeder();

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        $this->assertNotNull($extencion, 'El seeder no creó la extensión.');
        $this->assertEquals(self::NAME, $extencion->name);
    }

    /**
     * Foto del resto del catálogo: id, slug y nombre de cada fila que no es la de esta misión.
     *
     * Va con `slug` adentro y no sólo `name` porque lo que apaga funcionalidad en producción es
     * que a otra extensión le cambie el slug, no el nombre que se muestra.
     *
     * @return array<int, string>
     */
    protected function foto_del_resto_del_catalogo()
    {
        return ExtencionEmpresa::where('slug', '!=', self::SLUG)
            ->orderBy('id')
            ->get(['id', 'slug', 'name'])
            ->map(function ($extencion) {
                return $extencion->id . '|' . $extencion->slug . '|' . $extencion->name;
            })
            ->toArray();
    }
}
