<?php

namespace Tests\Feature\SugerenciasCompras;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\ExtencionSeeder;
use Database\Seeders\ExtencionSugerenciasComprasSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión sugerencias-compra-proveedores — archivo 1: el esquema de las tres
 * tablas nuevas, el índice único del pivot ya migrado, y el alta de la
 * extensión por las dos vías (ExtencionSeeder y el seeder suelto).
 *
 * Protege lo que el resto de la misión asume sin volver a chequear: que las
 * migraciones 100000-100500 dejaron exactamente el esquema que el plan
 * describe (§3 y §6.1), que el índice único que arregla el pivot
 * `article_provider` (§4) quedó puesto, y que el gate por extensión
 * 'sugerencias_compras' existe de verdad en las rutas (la aserción del 403
 * no es decorativa: es la única que prueba que el gate está conectado).
 */
class Esquema_y_extencion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea todo el módulo. */
    const SLUG = 'sugerencias_compras';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Si no, el pipeline sale a la API real de Anthropic (la clave real vive
        // en el .env.testing y la cola de phpunit corre en sync).
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * Asigna la extensión al usuario dado (creando la fila del catálogo si la
     * base del slot todavía no la tiene sembrada). Mismo molde que
     * SugerenciasStock y ChatIa.
     *
     * @param  \App\Models\User $user
     * @return void
     */
    protected function dar_extension($user)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            // forceCreate: el modelo no declara $fillable.
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Sugerencias de compra a proveedores',
            ]);
        }

        $user->extencions()->attach($extencion->id);
        // El middleware lee la colección del user: se refresca para que el
        // attach recién hecho se vea en este mismo request de test.
        $user->load('extencions');
    }

    /**
     * Default de una columna según information_schema (null si no hay default).
     *
     * @param string $tabla
     * @param string $columna
     * @return string|null
     */
    protected function default_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            "SELECT COLUMN_DEFAULT as valor
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );

        return $fila ? $fila->valor : null;
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
     * Detalle de tipo/precisión/nulabilidad de una columna, vía information_schema.
     *
     * @param string $tabla
     * @param string $columna
     * @return object|null
     */
    protected function detalle_de_columna($tabla, $columna)
    {
        return DB::selectOne(
            "SELECT DATA_TYPE as tipo, NUMERIC_PRECISION as precision_num,
                    NUMERIC_SCALE as escala, IS_NULLABLE as nullable
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function provider_price_offers_tiene_las_columnas_del_historico_con_cost_decimal_22_6()
    {
        $columnas = [
            'user_id', 'article_id', 'provider_id', 'provider_code',
            'cost', 'moneda_id', 'origen', 'fecha', 'referencia_id',
        ];

        foreach ($columnas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('provider_price_offers', $columna),
                "Falta la columna provider_price_offers.{$columna}."
            );
        }

        // La aserción que da sentido al test: el pivot article_provider.cost es
        // INT (se pierden los centavos). Esta tabla nace justo para no repetir
        // ese error, así que tiene que ser decimal(22,6) de verdad, no un tipo
        // cualquiera que "alcance".
        $detalle = $this->detalle_de_columna('provider_price_offers', 'cost');

        $this->assertNotNull($detalle, 'No se pudo leer el detalle de provider_price_offers.cost.');
        $this->assertEquals('decimal', $detalle->tipo);
        $this->assertEquals(22, (int) $detalle->precision_num);
        $this->assertEquals(6, (int) $detalle->escala);
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function provider_price_offers_tiene_el_unique_de_dedupe_y_sus_indices()
    {
        $this->assertTrue(
            $this->indice_existe('provider_price_offers', 'provider_price_offers_articulo_proveedor_fecha_origen_unique'),
            'Sin este unique, registrar_lote() no puede deduplicar por upsert.'
        );

        $this->assertTrue(
            $this->indice_existe('provider_price_offers', 'provider_price_offers_proveedor_fecha_index')
        );

        $this->assertTrue(
            $this->indice_existe('provider_price_offers', 'provider_price_offers_user_fecha_index')
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function purchase_suggestions_y_sus_lineas_tienen_las_columnas_nuevas()
    {
        $columnas_cabecera = [
            'user_id', 'status', 'error_mensaje', 'origen_generacion',
            'total_chunks', 'processed_chunks', 'resumen_ia', 'resumen_ia_estado',
            'resumen_ia_error', 'dias_punto_pedido', 'dias_cobertura_objetivo',
            'dias_lead_time', 'dias_vigencia_oferta', 'total_estimado', 'contexto_financiero',
        ];

        foreach ($columnas_cabecera as $columna) {
            $this->assertTrue(
                Schema::hasColumn('purchase_suggestions', $columna),
                "Falta la columna purchase_suggestions.{$columna}."
            );
        }

        $this->assertEquals(
            'pendiente',
            $this->default_de_columna('purchase_suggestions', 'status')
        );
        $this->assertEquals(
            'manual',
            $this->default_de_columna('purchase_suggestions', 'origen_generacion')
        );

        $columnas_lineas = [
            'purchase_suggestion_id', 'article_id', 'provider_id', 'provider_id_titular',
            'cantidad_sugerida', 'stock_global', 'stock_min_global', 'velocidad_diaria',
            'cobertura_dias', 'costo_estimado', 'costo_proveedor_titular', 'ahorro_estimado',
            'oferta_fecha', 'prioridad',
        ];

        foreach ($columnas_lineas as $columna) {
            $this->assertTrue(
                Schema::hasColumn('purchase_suggestion_articles', $columna),
                "Falta la columna purchase_suggestion_articles.{$columna}."
            );
        }

        // provider_id nullable: sin titular y sin ofertas, la línea igual se
        // guarda (agrupada como "sin proveedor asignado"), no se descarta.
        $detalle_provider_id = $this->detalle_de_columna('purchase_suggestion_articles', 'provider_id');
        $this->assertEquals('YES', $detalle_provider_id->nullable);

        $this->assertTrue($this->indice_existe(
            'purchase_suggestion_articles',
            'purchase_suggestion_articles_suggestion_prioridad_index'
        ));
        $this->assertTrue($this->indice_existe(
            'purchase_suggestion_articles',
            'purchase_suggestion_articles_suggestion_provider_index'
        ));
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function article_provider_tiene_el_indice_unico_uniq_article_provider_despues_de_migrar()
    {
        // R1: sin este índice, set_articles_providers() (attach() ciego) inserta
        // duplicados en silencio y upsert_provider_relations() nunca actualiza.
        $this->assertTrue(
            $this->indice_existe('article_provider', 'uniq_article_provider'),
            'La migración de dedupe tiene que dejar puesto uniq_article_provider sobre (article_id, provider_id).'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function users_sugerencias_compras_periodicidad_defaultea_a_nunca_y_provider_orders_queda_con_purchase_suggestion_id_nullable()
    {
        foreach (['sugerencias_compras_periodicidad', 'sugerencias_compras_ultima_generacion_at'] as $columna) {
            $this->assertTrue(
                Schema::hasColumn('users', $columna),
                "Falta la columna users.{$columna}."
            );
        }

        // La aserción que da sentido al test: migrar NO puede prender la
        // generación automática en ninguna cuenta existente.
        $this->assertEquals(
            'nunca',
            $this->default_de_columna('users', 'sugerencias_compras_periodicidad')
        );

        $this->assertTrue(Schema::hasColumn('provider_orders', 'purchase_suggestion_id'));

        $detalle = $this->detalle_de_columna('provider_orders', 'purchase_suggestion_id');
        $this->assertEquals(
            'YES',
            $detalle->nullable,
            'purchase_suggestion_id tiene que ser nullable: la enorme mayoría de las órdenes se cargan a mano.'
        );
    }

    /**
     * 🔴 Los dos tests de seeders de acá abajo corren el seeder vía
     * `db:seed` (Artisan::call/$this->artisan), NUNCA instanciándolo y
     * llamando ->run() a mano: el comando real de Laravel envuelve la
     * corrida en Model::unguarded() (SeedCommand::handle(), vendor/laravel/
     * framework/.../Console/Seeds/SeedCommand.php:65), que es lo que permite
     * que ExtencionEmpresa::create()/firstOrCreate() funcionen pese a que el
     * modelo no declara $fillable. UserSetupHelper y DemoSetupHelper (los
     * únicos llamadores reales) siempre pasan por Artisan::call('db:seed',
     * ...), nunca por una instancia directa -- probado a mano: instanciar y
     * llamar ->run() directo revienta con MassAssignmentException, porque
     * ahí no hay ningún unguard corriendo. Replicar la forma real de
     * invocación es lo que hace al test fiel a producción.
     *
     * @group sugerencias-compras
     * @test
     */
    public function el_seeder_suelto_de_la_extension_es_idempotente()
    {
        // Vía B (bases de producción existentes): correrlo dos veces no puede
        // dejar dos filas para el mismo slug.
        $this->artisan('db:seed', ['--class' => ExtencionSugerenciasComprasSeeder::class, '--force' => true])
            ->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => ExtencionSugerenciasComprasSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'ExtencionSugerenciasComprasSeeder usa firstOrCreate: correrlo dos veces no puede duplicar la extensión.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function el_slug_sugerencias_compras_esta_en_extencion_seeder()
    {
        // Vía A (instancias nuevas): UserSetupHelper/DemoSetupHelper corren SOLO
        // este seeder. Si el slug no está en su array, una instancia nueva
        // arranca sin la extensión y nadie lo nota hasta que el cliente reclama
        // (precedente real: ai_excel_import quedó con rutas sin gatear).
        $this->artisan('db:seed', ['--class' => ExtencionSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertTrue(
            ExtencionEmpresa::where('slug', self::SLUG)->exists(),
            'El slug sugerencias_compras tiene que estar en el array de ExtencionSeeder.'
        );
    }

    /**
     * @group sugerencias-compras
     * @test
     */
    public function sin_la_extension_el_endpoint_da_403_y_con_ella_responde()
    {
        $user = User::create([
            'name'     => 'Comercio esquema P1',
            'email'    => 'sugerencias-compras-p1-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->actingAs($user, 'web');

        $response = $this->getJson('api/purchase-suggestion');
        $response->assertStatus(403);

        $this->dar_extension($user);

        $response = $this->getJson('api/purchase-suggestion');
        $response->assertStatus(200);
        $response->assertJsonStructure(['models']);
    }
}
