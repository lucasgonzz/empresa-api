<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Models\ExtencionEmpresa;
use Database\Seeders\ExtencionMotorDeOfertasSeeder;
use Database\Seeders\ExtencionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 1: el esquema de las cuatro
 * tablas nuevas, la config en users y el alta de la extensión por las dos vías
 * (ExtencionSeeder para instancias nuevas, el seeder suelto para las que ya
 * existen). Lo más importante es el test de anchura de client_id/article_id.
 */
class Esquema_y_extencion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que gatea todo el módulo. */
    const SLUG = 'motor_de_ofertas';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Sin esto el pipeline sale a la API real de Anthropic: la clave real
        // vive en el .env.testing y la cola de phpunit corre en sync.
        config(['services.anthropic.api_key' => null]);
    }

    /** Un campo de information_schema.columns. COLUMN_TYPE = la columna Type de SHOW COLUMNS. */
    protected function dato_de_columna($tabla, $columna, $campo)
    {
        $fila = DB::selectOne(
            "SELECT {$campo} as valor FROM information_schema.columns
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$tabla, $columna]
        );

        return $fila ? $fila->valor : null;
    }

    /** true si el índice existe en la tabla. */
    protected function indice_existe($tabla, $nombre_indice)
    {
        return !empty(DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = '{$nombre_indice}'"));
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function las_cuatro_tablas_nuevas_tienen_todas_sus_columnas()
    {
        $esperado = [
            'offer_suggestions' => [
                'user_id', 'status', 'error_mensaje', 'origen_generacion', 'total_chunks', 'processed_chunks',
                'resumen_ia', 'resumen_ia_estado', 'resumen_ia_error', 'dias_historial_afinidad',
                'dias_inactividad_reactivacion', 'dias_carrito_abandonado', 'max_ofertas_por_cliente',
                'dias_vigencia_sugerida', 'total_clientes', 'total_lineas', 'total_clientes_excluidos_por_deuda',
            ],
            'offer_suggestion_lines' => [
                'offer_suggestion_id', 'client_id', 'article_id', 'tipo_descuento', 'porcentaje_sugerido',
                'porcentaje_techo', 'porcentaje_piso', 'margen_base', 'precio_vigente', 'costo_real',
                'price_type_id', 'criterio', 'criterio_detalle', 'es_buen_pagador', 'factor_credito', 'vendibilidad',
                'score', 'prioridad', 'fecha_vencimiento_sugerida', 'tramos_sugeridos', 'motivo_ia', 'client_offer_id',
            ],
            'client_offers' => [
                'user_id', 'client_id', 'article_id', 'tipo_descuento', 'porcentaje', 'desde', 'hasta', 'estado',
                'offer_suggestion_line_id', 'email_destino', 'notificada_email_at', 'whatsapp_telefono', 'whatsapp_url',
            ],
            'client_offer_ranges' => ['client_offer_id', 'min', 'max', 'porcentaje'],
        ];

        foreach ($esperado as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
            $this->assertTrue(Schema::hasColumns($tabla, $columnas), "Faltan columnas en {$tabla}.");
        }
    }

    /**
     * 🔴 EL TEST QUE DA SENTIDO AL ARCHIVO. clients.id y articles.id son los dos
     * bigIncrements: una columna derivada int trunca ids en silencio en un
     * catálogo grande — la clase "columna derivada más angosta que su fuente" de
     * APRENDER_NO_PARCHEAR.md. Si alguien "alinea al molde"
     * (purchase_suggestion_articles.article_id, integer), esto se pone rojo.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function client_id_y_article_id_espejan_bigint_unsigned_y_no_integer()
    {
        $pares = [
            ['offer_suggestion_lines', 'client_id'], ['offer_suggestion_lines', 'article_id'],
            ['offer_suggestion_lines', 'client_offer_id'], ['client_offers', 'client_id'],
            ['client_offers', 'article_id'], ['client_offers', 'offer_suggestion_line_id'],
            ['client_offer_ranges', 'client_offer_id'],
        ];

        foreach ($pares as $par) {
            $tipo = $this->dato_de_columna($par[0], $par[1], 'COLUMN_TYPE');
            $this->assertEquals('bigint unsigned', $tipo, "{$par[0]}.{$par[1]} tiene que espejar su fuente.");
        }

        // Mismo criterio sobre el costo: espeja articles.costo_real DESPUÉS de la
        // migración que lo llevó a 6 decimales. Con (22,2) truncaría igual que antes.
        $costo = $this->dato_de_columna('offer_suggestion_lines', 'costo_real', 'COLUMN_TYPE');
        $this->assertEquals('decimal(22,6)', $costo);
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function los_seis_indices_existen_con_el_nombre_exacto()
    {
        $indices = [
            ['offer_suggestions', 'offer_suggestions_user_id_created_at_index'],
            ['offer_suggestion_lines', 'offer_suggestion_lines_suggestion_prioridad_index'],
            ['offer_suggestion_lines', 'offer_suggestion_lines_suggestion_client_index'],
            // 🔴 El del contrato con la tienda (misión 5): sin él, cada carga de
            // una página del ecommerce escanea client_offers entera.
            ['client_offers', 'client_offers_user_client_article_estado_index'],
            ['client_offers', 'client_offers_user_estado_hasta_index'],
            ['client_offer_ranges', 'client_offer_ranges_offer_min_index'],
        ];

        foreach ($indices as $indice) {
            $this->assertTrue($this->indice_existe($indice[0], $indice[1]), "Falta {$indice[1]}.");
        }

        // 🔴 Y el que NO tiene que estar: un unique sobre (user_id, client_id,
        // article_id) impediría que una cancelada conviva con una activa nueva
        // del mismo par, que es lo que pasa en cada re-activación.
        $unique = 'client_offers_user_id_client_id_article_id_unique';
        $this->assertFalse($this->indice_existe('client_offers', $unique));
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function users_ofertas_periodicidad_defaultea_a_nunca()
    {
        foreach (['ofertas_periodicidad', 'ofertas_ultima_generacion_at'] as $columna) {
            $this->assertTrue(Schema::hasColumn('users', $columna), "Falta users.{$columna}.");
        }

        // La aserción que da sentido al test: migrar NO puede prender la
        // generación automática en ninguna cuenta existente. Acá pesa más que en
        // sugerencias de compra: una corrida de ofertas manda mails a clientes.
        $this->assertEquals('nunca', $this->dato_de_columna('users', 'ofertas_periodicidad', 'COLUMN_DEFAULT'));
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function el_seeder_suelto_de_la_extension_es_idempotente()
    {
        // 🔴 Vía db:seed y NUNCA instanciando el seeder para llamar ->run() a
        // mano: el comando real de Laravel envuelve la corrida en
        // Model::unguarded(), que es lo que permite que firstOrCreate() funcione
        // pese a que ExtencionEmpresa no declara $fillable, y es como lo llaman
        // UserSetupHelper y DemoSetupHelper.
        // Vía B (bases existentes): correrlo dos veces no deja dos filas.
        $this->artisan('db:seed', ['--class' => ExtencionMotorDeOfertasSeeder::class, '--force' => true])->assertExitCode(0);
        $this->artisan('db:seed', ['--class' => ExtencionMotorDeOfertasSeeder::class, '--force' => true])->assertExitCode(0);

        $encontradas = ExtencionEmpresa::where('slug', self::SLUG)->count();
        $this->assertEquals(1, $encontradas, 'firstOrCreate: correrlo dos veces no puede duplicar.');
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function el_slug_motor_de_ofertas_esta_en_extencion_seeder()
    {
        // Vía A (instancias nuevas): UserSetupHelper/DemoSetupHelper corren SOLO
        // este seeder. Si el slug no está en su array, una instancia nueva
        // arranca sin la extensión y nadie lo nota hasta que el cliente reclama.
        $this->artisan('db:seed', ['--class' => ExtencionSeeder::class, '--force' => true])->assertExitCode(0);

        $this->assertTrue(ExtencionEmpresa::where('slug', self::SLUG)->exists());
    }

    /**
     * @group motor-de-ofertas
     * @test
     */
    public function las_migraciones_se_pueden_re_ejecutar_sin_explotar()
    {
        // Los guards hasTable/hasColumn tienen que cortar de una y no intentar
        // el CREATE de vuelta: sin ellos, un migrate sobre una base a medio
        // migrar revienta en lugar de completarse.
        $migraciones = [
            '2026_08_17_100000_create_offer_suggestions_table'      => 'CreateOfferSuggestionsTable',
            '2026_08_17_100100_create_offer_suggestion_lines_table' => 'CreateOfferSuggestionLinesTable',
            '2026_08_17_100200_create_client_offers_table'          => 'CreateClientOffersTable',
            '2026_08_17_100300_create_client_offer_ranges_table'    => 'CreateClientOfferRangesTable',
            '2026_08_17_100400_add_ofertas_config_to_users_table'   => 'AddOfertasConfigToUsersTable',
        ];

        foreach ($migraciones as $archivo => $clase) {
            if (!class_exists($clase)) {
                require_once database_path('migrations/' . $archivo . '.php');
            }

            $migracion = new $clase();
            $migracion->up();
        }

        // Sin los guards, el up() de arriba habría tirado "table already exists"
        // o "duplicate column" y el test no llegaría hasta acá.
        $this->assertTrue(Schema::hasTable('client_offers'));
        $this->assertTrue(Schema::hasColumn('users', 'ofertas_periodicidad'));
    }
}
