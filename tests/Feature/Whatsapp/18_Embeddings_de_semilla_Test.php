<?php

namespace Tests\Feature\Whatsapp;

use App\Console\Commands\HornearEmbeddingsDeSemilla;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Models\Article;
use App\Models\Description;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión embeddings-por-lote-y-semilla — el catálogo de la demo viene con los vectores puestos.
 *
 * QUÉ PROTEGE ESTE ARCHIVO, Y POR QUÉ VALE LO QUE TARDA
 * ----------------------------------------------------
 * `FerreteriaArticlesSeeder` corre en cada alta de demo y en cada instalación de un cliente
 * nuevo, desde `DemoSetupHelper::run()`. Antes de esta misión, cada una de esas corridas
 * disparaba 138 llamadas pagas a OpenAI: 46 artículos × 3 jobs cada uno (uno de
 * `Article::created` y dos de `Description::created`), y como los tres jobs de un mismo
 * artículo ven un texto distinto —sin descripción, con una, con las tres—, el corte por
 * `embedding_source_hash` no salvaba ninguno.
 *
 * Y había algo peor que el gasto: `DemoSetupHelper` no configura `OPENAI_API_KEY`. En una demo
 * levantada sin esa clave, los embeddings no se generaban NUNCA, así que el lead probaba el
 * agente de WhatsApp contra un catálogo que la búsqueda semántica no veía.
 *
 * Los dos mecanismos que arreglan eso son invisibles cuando funcionan y silenciosos cuando se
 * rompen, y por eso necesitan test:
 *
 * 1. El flag de semilla (`ArticleObserver::empezar_semilla()`) evita que se despachen los jobs.
 * 2. `aplicar_embedding_horneado()` le copia al artículo el vector que viaja commiteado, pero
 *    SOLO si la huella del texto coincide con la que se horneó.
 *
 * Si alguien edita una descripción del seeder y no rehornea, el mecanismo 2 tiene que dejar el
 * artículo SIN vector (que el comando agendado regenera solo) en vez de guardar uno que no
 * corresponde al texto. Un vector que falta se cura solo; uno que miente se queda para siempre,
 * porque el corte por huella impide que se regenere. Eso es lo que fija el caso del auto-curado.
 *
 * 🔴 ACÁ ESTÁ PROHIBIDO `Event::fake()` PELADO, igual que en el archivo 6: silencia también los
 * eventos de modelo de Eloquent, así que los observers no correrían y los casos que verifican que
 * NO se despachan jobs darían verde sin probar nada — justamente al revés de lo que hay que
 * probar. Los despachos se capturan con `Queue::fake()`, que es otra cosa.
 *
 * Nota de tiempo: sembrar el catálogo completo crea categorías, subcategorías, marcas, precios y
 * stock por sucursal, así que es lento. Se siembra UNA sola vez por caso y solo en los casos que
 * de verdad lo necesitan; los que alcanzan con un artículo armado a mano no lo siembran.
 */
class Embeddings_de_semilla_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita la vectorización del catálogo. */
    const SLUG = 'whatsapp_ia';

    /** Artículos que trae el catálogo de la semilla. */
    const ARTICULOS_DEL_CATALOGO = 46;

    /** Bloques de descripción que se escriben por artículo. */
    const BLOQUES_POR_ARTICULO = 3;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de esta suite sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio embeddings semilla',
            'company_name' => 'Ferreteria semilla',
            'email'        => 'embeddings-semilla-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        // El seeder resuelve absolutamente todo por acá.
        config(['app.USER_ID' => $this->comercio->id]);
    }

    protected function tearDown(): void
    {
        // El flag de semilla es estático igual que el memo del gate: si un caso reventara entre
        // empezar_semilla() y su finally, dejarlo prendido apagaría los observers para el resto
        // de la suite entera, en silencio.
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    /**
     * Asigna la extensión al comercio y olvida el memo del gate.
     *
     * Importa que la extensión esté PUESTA en estos tests: sin ella el gate cortaría por otro
     * motivo y los casos que verifican que no se despachan jobs darían verde por la razón
     * equivocada.
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'WhatsApp con IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        ArticleObserver::resetear_cache_gate();
    }

    /**
     * Deja proveedores en la base para que el seeder pueda repartir los artículos.
     *
     * `FerreteriaArticlesSeeder::get_available_providers()` hace `$indice % count($providers)`:
     * con cero proveedores eso es una división por cero y el seeder no arranca.
     *
     * @return void
     */
    protected function crear_proveedores()
    {
        for ($i = 1; $i <= 3; $i++) {
            Provider::create([
                'name'    => 'Proveedor semilla ' . $i,
                'user_id' => $this->comercio->id,
            ]);
        }
    }

    /**
     * Corre el seeder del catálogo con la extensión activa y proveedores disponibles.
     *
     * @return void
     */
    protected function sembrar_catalogo()
    {
        $this->dar_extension();
        $this->crear_proveedores();

        (new FerreteriaArticlesSeeder())->run();
    }

    /**
     * Los artículos del catálogo que quedaron en la base para este comercio.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function articulos_sembrados()
    {
        return Article::where('user_id', $this->comercio->id)->get();
    }

    /**
     * @test
     * @group whatsapp
     */
    public function sembrar_el_catalogo_no_encola_ni_un_job_de_embedding()
    {
        Queue::fake();

        $this->sembrar_catalogo();

        /*
         * Sin el flag de semilla, acá habría 138 jobs: uno por artículo creado más dos por cada
         * descripción. Cada uno es una llamada paga a OpenAI, y el corte por huella no evita
         * ninguna porque los tres ven un texto distinto.
         */
        Queue::assertNotPushed(GenerateArticleEmbeddingJob::class);

        $this->assertCount(self::ARTICULOS_DEL_CATALOGO, $this->articulos_sembrados());
    }

    /**
     * @test
     * @group whatsapp
     */
    public function sembrar_el_catalogo_no_hace_ni_una_llamada_a_openai()
    {
        Http::fake();

        $this->sembrar_catalogo();

        // Es, textualmente, el pedido: los seeders nunca vuelven a pagarle a OpenAI.
        Http::assertNothingSent();
    }

    /**
     * @test
     * @group whatsapp
     */
    public function los_articulos_sembrados_quedan_con_vector_y_con_huella()
    {
        $this->sembrar_catalogo();

        $articulos = $this->articulos_sembrados();

        $this->assertCount(self::ARTICULOS_DEL_CATALOGO, $articulos);

        foreach ($articulos as $articulo) {
            $this->assertNotNull(
                $articulo->embedding,
                'El artículo "' . $articulo->name . '" quedó sin vector. Si cambió su texto, '
                . 'hay que rehornear con: php artisan semilla:embeddings'
            );

            $this->assertNotNull($articulo->embedding_source_hash, 'Falta la huella de "' . $articulo->name . '".');
            $this->assertNotNull($articulo->embedding_generated_at, 'Falta la marca de tiempo de "' . $articulo->name . '".');

            /** Vector decodificado; en MySQL se persiste como JSON. */
            $vector = json_decode((string) $articulo->embedding, true);

            $this->assertIsArray($vector, 'El vector de "' . $articulo->name . '" no es un JSON válido.');
            $this->assertCount(1536, $vector, 'El vector de "' . $articulo->name . '" no tiene 1536 componentes.');
        }
    }

    /**
     * @test
     * @group whatsapp
     */
    public function la_huella_guardada_coincide_con_el_texto_real_del_articulo_sembrado()
    {
        $this->sembrar_catalogo();

        /** @var ArticleEmbeddingService $service */
        $service = app(ArticleEmbeddingService::class);

        $articulos = Article::with(['category', 'brand', 'descriptions'])
            ->where('user_id', $this->comercio->id)
            ->get();

        foreach ($articulos as $articulo) {
            /*
             * 🔴 ESTE ES EL CANDADO CONTRA "alguien editó una descripción del seeder y no
             * rehorneó". Si el texto que el sistema arma hoy para este artículo no produce la
             * misma huella que quedó guardada al sembrar, el vector horneado ya no corresponde
             * al artículo y este test se pone rojo. Es la única red que avisa antes de que el
             * agente de WhatsApp empiece a buscar contra vectores viejos.
             */
            $hash_real = sha1($service->embedding_for_article($articulo));

            $this->assertSame(
                $hash_real,
                (string) $articulo->embedding_source_hash,
                'La huella de "' . $articulo->name . '" no corresponde a su texto actual. '
                . 'Rehorneá con: php artisan semilla:embeddings'
            );
        }
    }

    /**
     * @test
     * @group whatsapp
     */
    public function si_el_texto_no_coincide_con_lo_horneado_no_se_escribe_ningun_vector()
    {
        /*
         * Queue::fake() acá NO es decoración: este caso crea un artículo a mano, fuera del
         * seeder, así que el flag de semilla está apagado y ArticleObserver::created() despacha
         * su job como corresponde. Con la cola real ese job se ejecuta en el acto y sale a
         * OpenAI de verdad (devuelve 401 y revienta el test). Lo que se está probando acá es
         * aplicar_embedding_horneado() en aislamiento, no el observer — que ya tiene su propio
         * archivo, el 6.
         */
        Queue::fake();

        $this->dar_extension();

        /*
         * Se arma un artículo con el NOMBRE de uno del catálogo pero con un texto que no es el
         * que se horneó (le falta la categoría, la marca y las descripciones). O sea: el caso de
         * alguien que retocó el catálogo y no volvió a hornear.
         */
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();
        $nombre   = $catalogo[0]['name'];

        $articulo = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        /** Se invoca el método protegido igual que lo invoca el seeder. */
        $seeder = new FerreteriaArticlesSeeder();
        $metodo = new \ReflectionMethod($seeder, 'aplicar_embedding_horneado');
        $metodo->setAccessible(true);
        $metodo->invoke($seeder, $articulo, $catalogo[0]);

        $fresco = Article::find($articulo->id);

        /*
         * 🔴 Las tres columnas en null, y esto es lo que hay que defender. Las otras dos salidas
         * posibles son peores y no se curan:
         *
         * - vector viejo + huella nueva: GenerateArticleEmbeddingJob corta por huella y no lo
         *   regenera JAMÁS. El agente busca para siempre contra un vector que no corresponde.
         * - vector viejo + huella vieja: se regenera en el próximo ciclo, pero hasta entonces la
         *   búsqueda usa un vector que miente.
         *
         * Con las tres en null, `embedding IS NULL` es exactamente el filtro con el que
         * articles:generate-embeddings lo levanta, así que se arregla solo.
         */
        $this->assertNull($fresco->embedding, 'Se guardó un vector que no corresponde al texto del artículo.');
        $this->assertNull($fresco->embedding_source_hash);
        $this->assertNull($fresco->embedding_generated_at);
    }

    /**
     * @test
     * @group whatsapp
     */
    public function el_archivo_de_descripciones_cubre_exactamente_el_catalogo()
    {
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();

        /** Nombres del catálogo, que son las claves esperadas del archivo de datos. */
        $nombres_del_catalogo = array_column($catalogo, 'name');

        $descripciones = require database_path('seeders/data/ferreteria_descripciones.php');

        $this->assertIsArray($descripciones);

        /*
         * El archivo se indexa por el `name` EXACTO de get_catalog(). Es un acoplamiento por
         * texto —el mismo que el repo ya denuncia en archivo_de_imagen_de()— y este test
         * es lo que lo hace visible: si alguien retoca un nombre en el catálogo y no allá, el
         * artículo se crearía sin descripciones y sin vector, en silencio.
         */
        $faltantes = array_diff($nombres_del_catalogo, array_keys($descripciones));
        $sobrantes = array_diff(array_keys($descripciones), $nombres_del_catalogo);

        $this->assertSame([], array_values($faltantes), 'Hay artículos del catálogo sin descripciones a medida.');
        $this->assertSame([], array_values($sobrantes), 'Hay descripciones para artículos que no están en el catálogo.');

        foreach ($descripciones as $nombre => $bloques) {
            $this->assertCount(
                self::BLOQUES_POR_ARTICULO,
                $bloques,
                'El artículo "' . $nombre . '" no tiene los ' . self::BLOQUES_POR_ARTICULO . ' bloques.'
            );

            foreach ($bloques as $bloque) {
                $this->assertArrayHasKey('title', $bloque);
                $this->assertArrayHasKey('content', $bloque);
                $this->assertNotSame('', trim($bloque['content']));
            }
        }
    }

    /**
     * @test
     * @group whatsapp
     */
    public function ninguna_oracion_larga_se_repite_entre_dos_articulos()
    {
        $descripciones = require database_path('seeders/data/ferreteria_descripciones.php');

        /** Oración normalizada => nombre del primer artículo que la usó. */
        $vistas = [];
        /** Choques encontrados, para que el mensaje de error diga cuáles son. */
        $repetidas = [];

        foreach ($descripciones as $nombre => $bloques) {
            foreach ($bloques as $bloque) {
                foreach (explode('. ', (string) $bloque['content']) as $oracion) {
                    $normalizada = $this->normalizar_oracion($oracion);

                    // Las oraciones cortas se descartan: "Se vende por unidad" puede repetirse
                    // sin que eso vuelva parecidos a dos artículos.
                    if (str_word_count($normalizada) <= 8) {
                        continue;
                    }

                    if (isset($vistas[$normalizada]) && $vistas[$normalizada] !== $nombre) {
                        $repetidas[] = '"' . $normalizada . '" aparece en "' . $vistas[$normalizada] . '" y en "' . $nombre . '"';

                        continue;
                    }

                    $vistas[$normalizada] = $nombre;
                }
            }
        }

        /*
         * 🔴 Este test es lo que impide que la plantilla vuelva. Antes de esta misión los 46
         * artículos compartían un bloque entero byte a byte ("Excelente relacion costo-beneficio,
         * facil de manipular..."), y eso no es texto de más: es texto que empuja los 46 vectores
         * hacia el mismo punto del espacio y le saca capacidad de discriminar a la búsqueda. Una
         * oración compartida entre dos artículos los vuelve más parecidos de lo que son.
         */
        $this->assertSame([], $repetidas, "Hay oraciones repetidas entre artículos:\n" . implode("\n", $repetidas));

        // Guarda de sanidad: si el parseo fallara y no encontrara oraciones, el assert de arriba
        // daría verde sin haber comparado nada.
        $this->assertGreaterThan(150, count($vistas), 'Se encontraron muy pocas oraciones largas; revisar el parseo.');
    }

    /**
     * @test
     * @group whatsapp
     */
    public function el_archivo_horneado_cubre_exactamente_el_catalogo()
    {
        $catalogo = (new FerreteriaArticlesSeeder())->get_catalog();

        $ruta = HornearEmbeddingsDeSemilla::ruta_del_archivo();

        $this->assertFileExists($ruta, 'Falta el archivo de vectores horneados. Generalo con: php artisan semilla:embeddings');

        $crudo = json_decode((string) file_get_contents($ruta), true);

        $this->assertIsArray($crudo, 'El archivo de vectores horneados no es un JSON válido.');
        $this->assertArrayHasKey('articulos', $crudo);

        $faltantes = array_diff(array_column($catalogo, 'name'), array_keys($crudo['articulos']));

        $this->assertSame([], array_values($faltantes), 'Hay artículos del catálogo sin vector horneado.');

        foreach ($crudo['articulos'] as $nombre => $datos) {
            $this->assertArrayHasKey('source_hash', $datos, 'Falta la huella de "' . $nombre . '".');
            $this->assertArrayHasKey('embedding', $datos, 'Falta el vector de "' . $nombre . '".');
            $this->assertCount(1536, $datos['embedding'], 'El vector de "' . $nombre . '" no tiene 1536 componentes.');
        }
    }

    /**
     * @test
     * @group whatsapp
     */
    public function cada_articulo_sembrado_queda_con_sus_tres_descripciones()
    {
        $this->sembrar_catalogo();

        $total = Description::whereIn(
            'article_id',
            Article::where('user_id', $this->comercio->id)->pluck('id')
        )->count();

        $this->assertSame(
            self::ARTICULOS_DEL_CATALOGO * self::BLOQUES_POR_ARTICULO,
            $total,
            'No quedaron las tres descripciones por artículo.'
        );
    }

    /**
     * Deja una oración comparable: sin mayúsculas, sin tildes, sin puntuación y sin espacios
     * repetidos. Sin esto, "Se usa en obra." y "se usa en obra" contarían como distintas.
     *
     * @param string $oracion
     *
     * @return string
     */
    protected function normalizar_oracion($oracion)
    {
        $texto = mb_strtolower(trim($oracion));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);

        $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto);
        $texto = preg_replace('/\s+/', ' ', $texto);

        return trim((string) $texto);
    }
}
