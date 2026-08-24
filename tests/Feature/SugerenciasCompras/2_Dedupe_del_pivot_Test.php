<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Http\Controllers\Helpers\import\article\ActualizarBBDD;
use App\Models\Article;
use App\Models\Provider;
use App\Models\ProviderPriceOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 2: la migración de dedupe
 * del pivot `article_provider` (2026_08_16_100500) y los dos arreglos que
 * dependen del índice único que esa migración deja puesto.
 *
 * 🔴 Cómo se prueba una migración que YA CORRIÓ sobre una base compartida con
 * otro agente de tests (grupos 4-7 de esta misma misión corren en paralelo
 * sobre empresa_testing_s1): insertar duplicados de verdad en article_provider
 * no se puede sin dropear antes el índice único que la propia migración crea
 * — y ya está puesto desde que se migró la rama. Un DDL (DROP/ADD INDEX en
 * InnoDB) hace COMMIT implícito: DatabaseTransactions no revierte nada de lo
 * que se escriba después. Por eso el primer test de este archivo:
 *   (a) usa un artículo/proveedor PROPIOS (ids frescos, cero chance de pisar
 *       el fixture de otro test);
 *   (b) deja el índice ausente el mínimo tiempo posible: se dropea, se
 *       insertan las duplicadas y se llama a up() de la migración real en la
 *       misma respiración — up() ya termina recreándolo como último paso;
 *   (c) todo el armado corre en un try/finally que SIEMPRE limpia lo suyo y
 *       repone el índice si por lo que sea no quedó puesto, incluso si una
 *       aserción falla.
 * Los otros dos tests de este archivo (R1: set_articles_providers() y el
 * upsert de ActualizarBBDD) NO tocan el índice -- se apoyan en que YA está
 * puesto, así que corren enteramente dentro de la transacción normal.
 */
class Dedupe_del_pivot_Test extends TestCase
{
    use DatabaseTransactions;

    /** Nombre del índice único que la migración de dedupe deja puesto. */
    const INDICE = 'uniq_article_provider';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * true si el índice existe en la tabla.
     *
     * @param string $tabla
     * @param string $nombre_indice
     * @return bool
     */
    protected function indice_existe($tabla, $nombre_indice)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'");

        return !empty($filas);
    }

    /**
     * Usuario de prueba fresco, para no compartir ids con ningún otro test.
     *
     * @param string $sufijo
     * @return \App\Models\User
     */
    protected function usuario_de_test($sufijo)
    {
        return User::create([
            'name'     => 'Comercio dedupe ' . $sufijo,
            'email'    => 'sugerencias-compras-dedupe-' . $sufijo . '-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * R1 y R2: 3 duplicadas con costos distintos → sobrevive la de id más
     * alto, las 2 descartadas quedan volcadas al histórico con
     * origen='pivot_dedupe'; y una fila con cost NULL se borra pero NUNCA se
     * vuelca (no es una oferta real).
     *
     * @group sugerencias-compras
     * @test
     */
    public function la_migracion_de_dedupe_vuelca_al_historico_conserva_el_id_mas_alto_y_no_vuelca_costos_null()
    {
        $archivo_migracion = base_path('database/migrations/2026_08_16_100500_dedup_and_index_article_provider_table.php');
        $this->assertFileExists($archivo_migracion);

        // La clase de la migración no tiene namespace (vive en el global): solo
        // hace falta requerirla una vez por proceso de phpunit.
        if (!class_exists('DedupAndIndexArticleProviderTable', false)) {
            require_once $archivo_migracion;
        }

        $user      = $this->usuario_de_test('p2a');
        $provider  = Provider::create(['name' => 'Proveedor dedupe P2', 'user_id' => $user->id]);
        $articuloA = Article::create(['name' => 'Articulo dedupe A P2', 'user_id' => $user->id]);
        $articuloB = Article::create(['name' => 'Articulo dedupe B P2', 'user_id' => $user->id]);

        $indice_existia_antes = $this->indice_existe('article_provider', self::INDICE);

        $fecha_vieja = now()->subDays(10)->startOfDay();
        $fecha_media = now()->subDays(5)->startOfDay();
        $fecha_hoy   = now();

        try {
            if ($indice_existia_antes) {
                Schema::table('article_provider', function ($table) {
                    $table->dropUnique(self::INDICE);
                });
            }

            // Par 1 (articuloA, provider): 3 duplicadas, costos y fechas
            // distintas para que, al volcarse, NO colapsen entre sí (el unique
            // de provider_price_offers incluye la fecha).
            $id1 = DB::table('article_provider')->insertGetId([
                'article_id' => $articuloA->id, 'provider_id' => $provider->id,
                'cost' => 100, 'provider_code' => 'COD-A-1',
                'created_at' => $fecha_vieja, 'updated_at' => $fecha_vieja,
            ]);
            $id2 = DB::table('article_provider')->insertGetId([
                'article_id' => $articuloA->id, 'provider_id' => $provider->id,
                'cost' => 150, 'provider_code' => 'COD-A-2',
                'created_at' => $fecha_media, 'updated_at' => $fecha_media,
            ]);
            $id3 = DB::table('article_provider')->insertGetId([
                'article_id' => $articuloA->id, 'provider_id' => $provider->id,
                'cost' => 200, 'provider_code' => 'COD-A-3',
                'created_at' => $fecha_hoy, 'updated_at' => $fecha_hoy,
            ]);

            // Par 2 (articuloB, provider): la descartable tiene cost NULL -- se
            // borra igual, pero jamás se vuelca (no es un hecho de precio real).
            DB::table('article_provider')->insertGetId([
                'article_id' => $articuloB->id, 'provider_id' => $provider->id,
                'cost' => null, 'provider_code' => 'COD-B-NULL',
                'created_at' => $fecha_media, 'updated_at' => $fecha_media,
            ]);
            $id_keeper_b = DB::table('article_provider')->insertGetId([
                'article_id' => $articuloB->id, 'provider_id' => $provider->id,
                'cost' => 300, 'provider_code' => 'COD-B-KEEP',
                'created_at' => $fecha_hoy, 'updated_at' => $fecha_hoy,
            ]);

            // La migración real, end-to-end: vuelca, borra, verifica y recrea
            // el índice (su último paso).
            $migracion = new \DedupAndIndexArticleProviderTable();
            $migracion->up();

            // --- Par 1: sobrevive la de id más alto ---
            $filas_pivot_a = DB::table('article_provider')
                ->where('article_id', $articuloA->id)
                ->where('provider_id', $provider->id)
                ->get();

            $this->assertCount(1, $filas_pivot_a, 'Después del dedupe tiene que quedar 1 sola fila del par.');
            $this->assertEquals($id3, $filas_pivot_a[0]->id, 'Tiene que sobrevivir la fila de id más alto.');
            $this->assertEquals(200, (int) $filas_pivot_a[0]->cost);

            $historico_a = ProviderPriceOffer::where('article_id', $articuloA->id)
                ->where('provider_id', $provider->id)
                ->where('origen', 'pivot_dedupe')
                ->orderBy('fecha')
                ->get();

            $this->assertCount(2, $historico_a, 'Las 2 filas descartadas tienen que quedar volcadas al histórico.');

            // ProviderPriceOffer no declara $casts para 'fecha': llega como
            // string plano (columna DATE), no como Carbon -- se compara
            // directo contra el string, sin volver a llamar toDateString().
            $this->assertEquals($fecha_vieja->toDateString(), $historico_a[0]->fecha);
            $this->assertEquals(100.0, (float) $historico_a[0]->cost);
            $this->assertEquals('COD-A-1', $historico_a[0]->provider_code);
            $this->assertEquals($user->id, (int) $historico_a[0]->user_id);

            $this->assertEquals($fecha_media->toDateString(), $historico_a[1]->fecha);
            $this->assertEquals(150.0, (float) $historico_a[1]->cost);
            $this->assertEquals('COD-A-2', $historico_a[1]->provider_code);

            // --- Par 2: cost null se borra pero NO se vuelca ---
            $filas_pivot_b = DB::table('article_provider')
                ->where('article_id', $articuloB->id)
                ->where('provider_id', $provider->id)
                ->get();

            $this->assertCount(1, $filas_pivot_b);
            $this->assertEquals($id_keeper_b, $filas_pivot_b[0]->id);

            $this->assertEquals(
                0,
                ProviderPriceOffer::where('article_id', $articuloB->id)
                    ->where('provider_id', $provider->id)
                    ->where('origen', 'pivot_dedupe')
                    ->count(),
                'La fila con cost null NUNCA fue una oferta real: no puede volcarse al histórico.'
            );

            // La migración recreó el índice como último paso.
            $this->assertTrue($this->indice_existe('article_provider', self::INDICE));
        } finally {
            // El DDL de arriba (dropUnique) hizo commit implícito: nada de lo
            // escrito después se revierte solo. Limpieza manual explícita, para
            // no dejar residuo en la base compartida del slot.
            DB::table('article_provider')
                ->where('article_id', $articuloA->id)
                ->where('provider_id', $provider->id)
                ->delete();
            DB::table('article_provider')
                ->where('article_id', $articuloB->id)
                ->where('provider_id', $provider->id)
                ->delete();
            DB::table('provider_price_offers')
                ->where('article_id', $articuloA->id)
                ->where('provider_id', $provider->id)
                ->delete();
            DB::table('provider_price_offers')
                ->where('article_id', $articuloB->id)
                ->where('provider_id', $provider->id)
                ->delete();

            // Red de seguridad: si algo de lo de arriba explotó ANTES de que
            // up() llegara a recrear el índice, se repone acá para no dejar la
            // base compartida sin él.
            if (!$this->indice_existe('article_provider', self::INDICE)) {
                Schema::table('article_provider', function ($table) {
                    $table->unique(['article_id', 'provider_id'], self::INDICE);
                });
            }

            $articuloA->forceDelete();
            $articuloB->forceDelete();
            $provider->forceDelete();
            $user->delete();
        }
    }

    /**
     * R1: con el índice ya puesto, set_articles_providers() (que pasó de
     * attach() ciego a syncWithoutDetaching) llamado dos veces sobre el mismo
     * artículo no tira "Integrity constraint violation" y no deja 2 filas.
     *
     * Se instancia ActualizarBBDD SIN pasar por su constructor real (que
     * dispara guardar_articulos(), el pipeline entero de la importación):
     * ReflectionClass::newInstanceWithoutConstructor() deja construir el
     * objeto y setear a mano únicamente lo que set_articles_providers()
     * necesita (articulos_creados_models + observations), sin ejecutar precios
     * finales, stock, descuentos ni nada del resto del importador -- ninguno
     * de los cuales es lo que este test protege.
     *
     * @group sugerencias-compras
     * @test
     */
    public function set_articles_providers_llamado_dos_veces_no_lanza_ni_duplica_el_pivot()
    {
        $this->assertTrue(
            $this->indice_existe('article_provider', self::INDICE),
            'Este test asume el índice YA puesto (no lo toca): si no está, algo se desconfiguró en el slot.'
        );

        $user     = $this->usuario_de_test('p2b');
        $provider = Provider::create(['name' => 'Proveedor P2B', 'user_id' => $user->id]);
        $articulo = Article::create([
            'name'          => 'Articulo P2B',
            'user_id'       => $user->id,
            'provider_id'   => $provider->id,
            'provider_code' => 'PC-P2B',
            'cost'          => 111,
        ]);

        $instancia = (new ReflectionClass(ActualizarBBDD::class))->newInstanceWithoutConstructor();
        $instancia->observations = [];
        $instancia->articulos_creados_models = collect([$articulo]);

        // Antes del arreglo, el segundo llamado (segundo attach() ciego sobre
        // el mismo par) tiraba Integrity constraint violation con el índice
        // puesto. No debe lanzar ninguna excepción.
        $instancia->set_articles_providers();
        $instancia->set_articles_providers();

        $filas = DB::table('article_provider')
            ->where('article_id', $articulo->id)
            ->where('provider_id', $provider->id)
            ->get();

        $this->assertCount(1, $filas, 'syncWithoutDetaching llamado dos veces no puede dejar 2 filas del mismo par.');
        $this->assertEquals('PC-P2B', $filas[0]->provider_code);

        $articulo->forceDelete();
        $provider->forceDelete();
        $user->delete();
    }

    /**
     * Efecto colateral bueno y esperado (§4 del plan): con el índice ya
     * puesto, el upsert() masivo de ActualizarBBDD::upsert_provider_relations()
     * (hoy siempre INSERT sin índice) empieza a hacer UPDATE cuando el par ya
     * existe. Se invoca el método real (protected) vía Reflection sobre una
     * instancia sin constructor, mismo motivo que el test anterior.
     *
     * @group sugerencias-compras
     * @test
     */
    public function upsert_provider_relations_con_el_indice_puesto_actualiza_en_vez_de_duplicar()
    {
        $this->assertTrue($this->indice_existe('article_provider', self::INDICE));

        $user     = $this->usuario_de_test('p2c');
        $provider = Provider::create(['name' => 'Proveedor P2C', 'user_id' => $user->id]);
        $articulo = Article::create(['name' => 'Articulo P2C', 'user_id' => $user->id]);

        $instancia = (new ReflectionClass(ActualizarBBDD::class))->newInstanceWithoutConstructor();
        $instancia->observations = [];

        $metodo = new ReflectionMethod(ActualizarBBDD::class, 'upsert_provider_relations');
        $metodo->setAccessible(true);

        $metodo->invoke($instancia, [
            $articulo->id => [$provider->id => ['provider_code' => 'PC-1', 'cost' => 500]],
        ]);
        $metodo->invoke($instancia, [
            $articulo->id => [$provider->id => ['provider_code' => 'PC-1', 'cost' => 777]],
        ]);

        $filas = DB::table('article_provider')
            ->where('article_id', $articulo->id)
            ->where('provider_id', $provider->id)
            ->get();

        $this->assertCount(1, $filas, 'El upsert con el índice puesto tiene que actualizar, no insertar de nuevo.');
        $this->assertEquals(777, (int) $filas[0]->cost, 'Tiene que quedar el costo de la segunda llamada (la más reciente).');

        $articulo->forceDelete();
        $provider->forceDelete();
        $user->delete();
    }
}
