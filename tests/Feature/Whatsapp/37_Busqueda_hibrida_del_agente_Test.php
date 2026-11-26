<?php

namespace Tests\Feature\Whatsapp;

use App\Exceptions\EmbeddingsCredencialRechazadaException;
use App\Http\Controllers\Helpers\asistente_ia\BusquedaHibridaDeArticulosHelper;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\User;
use App\Services\ArticleEmbeddingService;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Misión agente-busqueda-hibrida — el agente de WhatsApp recibe los artículos que el cliente NOMBRA.
 *
 * Hasta hoy el catálogo del agente se recuperaba sólo por significado (top 5 de similitud de coseno):
 * "Busco una bordeadora económica" le llegaba con cortacercos y cuchillas, sin ninguna de las 5 Nober
 * que el negocio tiene en stock. Ahora se suman los artículos cuyo NOMBRE contiene la palabra de
 * producto (igualdad de palabra entera, singular/plural en los dos sentidos), con la similitud
 * ordenando sólo DENTRO de cada cola por palabra, y se conservan siempre los 5 vectoriales de hoy.
 *
 * Catálogo de test: nombres REALES de Ferretotal donde la muestra los tiene; GUANTE, FOTOCELULA,
 * TENAZAS y EXTENSION no están en la muestra y llevan un nombre verosímil. Vectores fingidos:
 * consulta [1,0,0], artículo `[s, sqrt(1 - s²), 0]` → coseno = `s`. Nada sale a la red.
 */
class Busqueda_hibrida_del_agente_Test extends TestCase
{
    use DatabaseTransactions;

    const NOBER_M205   = 'NOBER BORDEADORA M205 700W';
    const NOBER_M212   = 'NOBER BORDEADORA M212 900W';
    const NOBER_M215   = 'NOBER BORDEADORA M215 1000W';
    const NOBER_CAP400 = 'NOBER BORDEADORA CAP400 400W';
    const NOBER_CAP500 = 'NOBER BORDEADORA CAP 500';

    const CARRETEL_SCHAEFFER = 'CARRETEL BORDEADORA T/SCHAEFFER C/ROSCA';
    const CARRETEL_TANZA     = 'CARRETEL P/BORDEADORA C/TANZA';
    const CARRETEL_CHICO     = 'CARRETEL P/BORDEADORA L/NUEVA CHICO PP S/TANZA';
    const CARRETEL_GRANDE    = 'CARRETEL P/BORDEADORA L/NUEVA GRANDE PP S/TANZA';
    const CARRETEL_SCHAFER   = 'CARRETEL P/BORDEADORA SCHAFER';
    const CARRETEL_UNIVERSAL = 'CARRETEL P/BORDEADORA UNIVERSAL C/TANZA';
    const CARRETEL_AUTO      = 'CARRETEL PARA BORDEADORA AUTOMATICO (EBOCAA)';
    const BUJIA              = 'BUJIA P/BORDEADORA/DESMALEZADORA. (P15)';
    const CARBON             = 'CARBON (24) 6X6X18 BORDEADORAS VS (X PAR)';
    const FILTRO             = 'FILTRO COMBUSTIBLE P/DESMALEZADORA Y BORDEADORA';
    const LLAVE_SCHAFER      = 'LLAVE SCHAFER P/BORDEADORA';

    const NYLON_ABRAZADERA = 'ABRAZADERA NYLON PARA SIFON';
    const NYLON_PRINGLES   = 'HERRAJE NYLON PRINGLES';
    const NYLON_PLANA      = 'RUEDAMAS NYLON 32MM PLANA (B07)';
    const NYLON_CARRO      = 'RUEDAMAS CARRO NYLON 32MM C/ACC.4 RUED.E16';
    const NYLON_REDONDO    = 'HERRAJE DE NYLON REDONDO';
    const NYLON_DURACRIL   = 'DURACRIL MEMBRANA NYLON TECHOS 5 KILOS BLANCA';

    const LLAVE_CRUZ     = 'LLAVE CRUZ 4 MEDIDAS PARA AUTO LUSQTOFF';
    const LLAVES_LIN     = 'LLAVES LIN P/AMOLADORA 7¨ UNIVER 2095';
    const BOCALLAVES     = 'LUSQTOFF JUEGO BOCALLAVES Y ACC. 1/4 12 PIEZAS';
    const CABLE_PARALELO = 'CABLE PARALELO 2X2,50 MM';
    const CABLE_UNIPOLAR = 'CABLE UNIPOLAR 1X2,50 MM NEGRO';
    const CABLE_SUBT     = 'CABLE SUBTERRÁNEO 3X2,50 MM';
    const GUANTE         = 'GUANTE MOTEADO T9';
    const GUANTES        = 'GUANTES DE NITRILO T8';

    const FOTOCELULA       = 'FOTOCELULA 10A';
    const TENAZAS          = 'TENAZAS ARMADOR 10"';
    const EXTENSION        = 'EXTENSION 3 TOMAS 5M';
    const BUSCAPOLO        = 'BUSCAPOLO TESTER';
    const ESCOBA           = 'ESCOBA ECONOMICA';
    const CORTACERCO       = 'LUSQTOFF CORTACERCO 550 W';
    const NOBER_CORTACERCO = 'NOBER CORTACERCO 450W';
    const CORTADORA        = 'CORTADORA DE CESPED ELECTRICA';
    const CUCHILLA         = 'CUCHILLA P/CORTADORA YAERCO';

    /** Los 11 accesorios con la palabra BORDEADORA (los realmente enumerados en el PLAN). */
    const ACCESORIOS = [
        self::CARRETEL_SCHAEFFER, self::CARRETEL_TANZA, self::CARRETEL_CHICO, self::CARRETEL_GRANDE,
        self::CARRETEL_SCHAFER, self::CARRETEL_UNIVERSAL, self::CARRETEL_AUTO, self::BUJIA, self::CARBON,
        self::FILTRO, self::LLAVE_SCHAFER,
    ];

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    /** @var int Se restaura en tearDown: el test del tope lo baja. */
    protected $tope_original;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => null,
            'services.openai.api_key'          => 'clave-openai-de-prueba',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba',
            'services.deepseek.model_agil'     => 'deepseek-flash-test',
            'services.deepseek.model_profundo' => 'deepseek-pro-test',
            'broadcasting.default'             => 'null',
        ]);

        Cache::forget('embeddings_credencial_rechazada');

        $this->comercio = User::create([
            'name'         => 'Comercio busqueda hibrida',
            'company_name' => 'Ferreteria busqueda hibrida',
            'email'        => 'busqueda-hibrida-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio busqueda hibrida',
            'company_name' => 'Otra ferreteria',
            'email'        => 'busqueda-hibrida-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->tope_original = class_exists(BusquedaHibridaDeArticulosHelper::class)
            ? BusquedaHibridaDeArticulosHelper::$tope_candidatos_con_vector
            : 500;
    }

    protected function tearDown(): void
    {
        if (class_exists(BusquedaHibridaDeArticulosHelper::class)) {
            BusquedaHibridaDeArticulosHelper::$tope_candidatos_con_vector = $this->tope_original;
        }

        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────────────────────

    /** Stub de OpenAI: el embedding de la consulta es [1,0,0]. El '*' final evita salir a la red. */
    protected function fingir_openai(array $vector_consulta = [1.0, 0.0, 0.0])
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => $vector_consulta]],
                'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** Vector de un artículo con coseno `$s` contra la consulta [1,0,0]. */
    protected function vector_con_similitud($s)
    {
        return [$s, sqrt(max(0.0, 1 - $s * $s)), 0.0];
    }

    /**
     * Artículo con su vector guardado como JSON por SQL crudo (sin fila compacta: la búsqueda la arma).
     *
     * @param string     $nombre
     * @param float|null $s      Similitud con la consulta; null = sin embedding.
     * @param array      $extra  Columnas extra (user_id, status, final_price, stock...).
     */
    protected function articulo($nombre, $s, array $extra = [])
    {
        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'final_price' => 1000,
            'stock'       => 10,
            'slug'        => \Illuminate\Support\Str::slug($nombre) . '-' . uniqid(),
        ], $extra));

        if (! is_null($s)) {
            DB::table('articles')->where('id', $article->id)->update([
                'embedding' => json_encode($this->vector_con_similitud($s)),
            ]);
        }

        return $article;
    }

    /**
     * Siembra el catálogo base. `$similitudes` fija la similitud por nombre; lo no listado vale `$resto`.
     * Devuelve nombre => id.
     */
    protected function sembrar_catalogo(array $similitudes = [], $resto = 0.05)
    {
        $precios = [
            self::NOBER_M205   => [0, 0],
            self::NOBER_M212   => [0, 0],
            self::NOBER_M215   => [165165, 2],
            self::NOBER_CAP400 => [117975, 6],
            self::NOBER_CAP500 => [137940, 8],
            self::CORTACERCO   => [93436.20, 4],
        ];

        $nombres = array_merge(
            [self::NOBER_M205, self::NOBER_M212, self::NOBER_M215, self::NOBER_CAP400, self::NOBER_CAP500],
            self::ACCESORIOS,
            [self::NYLON_ABRAZADERA, self::NYLON_PRINGLES, self::NYLON_PLANA, self::NYLON_CARRO, self::NYLON_REDONDO, self::NYLON_DURACRIL],
            [self::LLAVE_CRUZ, self::LLAVES_LIN, self::BOCALLAVES, self::CABLE_PARALELO, self::CABLE_UNIPOLAR, self::CABLE_SUBT],
            [self::GUANTE, self::GUANTES],
            [self::FOTOCELULA, self::TENAZAS, self::EXTENSION, self::BUSCAPOLO, self::ESCOBA],
            [self::CORTACERCO, self::NOBER_CORTACERCO, self::CORTADORA, self::CUCHILLA]
        );

        $ids = [];
        foreach ($nombres as $nombre) {
            $extra = isset($precios[$nombre]) ? ['final_price' => $precios[$nombre][0], 'stock' => $precios[$nombre][1]] : [];
            $s     = isset($similitudes[$nombre]) ? $similitudes[$nombre] : $resto;
            $ids[$nombre] = $this->articulo($nombre, $s, $extra)->id;
        }

        return $ids;
    }

    /** Nombres de una colección de resultados, en orden. */
    protected function nombres($resultados)
    {
        return $resultados->pluck('name')->values()->all();
    }

    protected function ids($resultados)
    {
        return $resultados->pluck('id')->map(function ($id) {
            return (int) $id;
        })->values()->all();
    }

    protected function servicio()
    {
        return new ArticleEmbeddingService();
    }

    // ── CA1: palabras de producto ───────────────────────────────────────────────────────────────

    public function frases_y_palabras()
    {
        return [
            'saludo'                    => ['Hola, buenas tardes', []],
            'plural sin singularizar'   => ['tenes bordeadoras?', ['bordeadoras']],
            'dos productos'             => ['Buen día necesito nylon para bordeadora', ['nylon', 'bordeadora']],
            'adjetivo de conversacion'  => ['Busco una bordeadora económica', ['bordeadora']],
            'seguimiento sin producto'  => ['dale mandame la foto', []],
            'voseo con tilde'           => ['Tenés TENAZA?', ['tenaza']],
            'carretel para bordeadora'  => ['¿Tenés carretel para bordeadora?', ['carretel', 'bordeadora']],
        ];
    }

    /**
     * @dataProvider frases_y_palabras
     * @group whatsapp
     * @test
     */
    public function las_palabras_de_conversacion_se_sacan_sin_recortar($frase, $esperadas)
    {
        $this->assertSame($esperadas, BusquedaHibridaDeArticulosHelper::palabras_de_producto($frase));
    }

    /**
     * Las palabras de menos de 4 letras ("ten", "pp") no generan LIKE: el único LIKE grabado es el de
     * "bordeadora". Y ningún token trae comodines del LIKE.
     *
     * @group whatsapp
     * @test
     */
    public function las_palabras_cortas_no_generan_like()
    {
        $this->sembrar_catalogo();
        $this->fingir_openai();

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->servicio()->search_hybrid_articles('ten pp bordeadora', $this->comercio->id, 8);

        $likes = [];
        foreach ($consultas as $consulta) {
            if (stripos($consulta['sql'], ' like ') !== false) {
                foreach ($consulta['bindings'] as $binding) {
                    if (is_string($binding) && strpos($binding, '%') !== false) {
                        $likes[] = $binding;
                    }
                }
            }
        }

        $this->assertSame(['%bordeadora%'], array_values(array_unique($likes)), 'Sólo "bordeadora" genera LIKE; "ten" y "pp" no.');

        foreach (['100% ¡Ñandú!', 'a_b\\c', 'P/BORDEADORA (24) 6X6'] as $texto) {
            foreach (BusquedaHibridaDeArticulosHelper::tokens($texto) as $token) {
                $this->assertDoesNotMatchRegularExpression('/[%_\\\\]/', $token);
            }
        }
    }

    // ── CA1b: igualdad singular/plural y posiciones ─────────────────────────────────────────────

    public function tabla_de_igualdad()
    {
        return [
            ['llaves', 'llave', true],
            ['llave', 'llaves', true],
            ['llave', 'bocallaves', false],
            ['cable', 'cable', true],
            ['cable', 'cables', true],
            ['cables', 'cable', true],
            ['guantes', 'guante', true],
            ['guante', 'guantes', true],
            ['pinceles', 'pincel', true],
            ['lapiz', 'lapices', true],
            ['bordeadoras', 'bordeadora', true],
            ['foto', 'fotocelula', false],
            ['tenaza', 'tenazas', true],
            ['mes', 'meses', true],
        ];
    }

    /**
     * @dataProvider tabla_de_igualdad
     * @group whatsapp
     * @test
     */
    public function singular_y_plural_encajan_en_los_dos_sentidos($consulta, $nombre, $encaja)
    {
        $this->assertSame($encaja, BusquedaHibridaDeArticulosHelper::misma_palabra($consulta, $nombre));
        // Simétrica: el mismo resultado intercambiando consulta y nombre.
        $this->assertSame($encaja, BusquedaHibridaDeArticulosHelper::misma_palabra($nombre, $consulta));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_raiz_para_el_prefiltro_contiene_toda_variante()
    {
        $esperadas = [
            'llaves' => 'llav', 'cables' => 'cabl', 'guantes' => 'guant', 'bordeadoras' => 'bordeadora',
            'bordeadora' => 'bordeadora', 'lapiz' => 'lapi', 'nylon' => 'nylon',
        ];

        foreach ($esperadas as $palabra => $raiz) {
            $this->assertSame($raiz, BusquedaHibridaDeArticulosHelper::raiz_para_like($palabra));
        }
    }

    /**
     * Ejecuta candidatos + coincidencias y devuelve nombre => fila (cobertura, nivel, cola).
     */
    protected function encajes_de($palabras)
    {
        $filas   = BusquedaHibridaDeArticulosHelper::candidatos($this->comercio->id, $palabras);
        $encajes = BusquedaHibridaDeArticulosHelper::coincidencias($filas, $palabras);

        $por_nombre = [];
        foreach ($encajes as $encaje) {
            $por_nombre[$encaje['name']] = $encaje;
        }

        return $por_nombre;
    }

    /**
     * @group whatsapp
     * @test
     */
    public function llaves_cables_y_guantes_traen_su_singular_y_su_plural()
    {
        $this->sembrar_catalogo();

        $llaves = array_keys($this->encajes_de(['llaves']));
        $this->assertEqualsCanonicalizing([self::LLAVE_SCHAFER, self::LLAVE_CRUZ, self::LLAVES_LIN], $llaves);
        $this->assertNotContains(self::BOCALLAVES, $llaves, 'BOCALLAVES es otra palabra: no encaja con "llave".');

        $this->assertEqualsCanonicalizing(
            [self::CABLE_PARALELO, self::CABLE_UNIPOLAR, self::CABLE_SUBT],
            array_keys($this->encajes_de(['cables']))
        );

        $this->assertEqualsCanonicalizing(
            [self::GUANTE, self::GUANTES],
            array_keys($this->encajes_de(['guantes']))
        );

        $this->assertSame([], array_keys($this->encajes_de(['foto'])), 'FOTOCELULA no es la palabra "foto".');
    }

    /**
     * Un candidato sin ninguna coincidencia en una palabra DE LETRAS se descarta: los números sólo suman
     * cobertura o desempatan, nunca definen nivel ni cola. BOCALLAVES 1/4 coincide en "1" y "4" pero no
     * en "llaves": queda excluido sin excepción.
     *
     * @group whatsapp
     * @test
     */
    public function un_candidato_sin_palabra_de_letras_se_descarta_aunque_coincidan_los_numeros()
    {
        $this->sembrar_catalogo();

        $filas   = BusquedaHibridaDeArticulosHelper::candidatos($this->comercio->id, ['llaves']);
        $encajes = BusquedaHibridaDeArticulosHelper::coincidencias($filas, ['llaves', '1', '4']);

        $nombres = array_column($encajes, 'name');

        $this->assertNotContains(self::BOCALLAVES, $nombres, 'Sólo coincide en números: se excluye sin excepción.');
        $this->assertContains(self::LLAVE_SCHAFER, $nombres);

        // Aunque el prefiltro lo trajera, la coincidencia lo descarta: también con la fila forzada.
        $forzada = [(object) ['id' => 999999, 'name' => self::BOCALLAVES]];
        $this->assertSame([], BusquedaHibridaDeArticulosHelper::coincidencias($forzada, ['llaves', '1', '4']));
    }

    public function nombres_con_su_lista_de_tokens_y_nivel()
    {
        return [
            ['NOBER BORDEADORA CAP400 400W', 'bordeadora', ['nober', 'bordeadora', 'cap400', '400w'], 0],
            ['CARRETEL P/BORDEADORA C/TANZA', 'bordeadora', ['carretel', 'p', 'bordeadora', 'c', 'tanza'], 1],
            ['CARRETEL BORDEADORA T/SCHAEFFER C/ROSCA', 'bordeadora', ['carretel', 'bordeadora', 't', 'schaeffer', 'c', 'rosca'], 0],
            ['BUJIA P/BORDEADORA/DESMALEZADORA. (P15)', 'desmalezadora', ['bujia', 'p', 'bordeadora', 'desmalezadora', 'p15'], 1],
            ['HERRAJE DE NYLON REDONDO', 'nylon', ['herraje', 'de', 'nylon', 'redondo'], 1],
            ['RUEDAMAS CARRO NYLON 32MM C/ACC.4 RUED.E16', 'nylon', ['ruedamas', 'carro', 'nylon', '32mm', 'c', 'acc', '4', 'rued', 'e16'], 1],
            ['RUEDAMAS NYLON 32MM PLANA (B07)', 'nylon', ['ruedamas', 'nylon', '32mm', 'plana', 'b07'], 0],
            ['CARBON (24) 6X6X18 BORDEADORAS VS (X PAR)', 'bordeadora', ['carbon', '24', '6x6x18', 'bordeadoras', 'vs', 'x', 'par'], 1],
        ];
    }

    /**
     * La posición se cuenta sobre la lista COMPLETA de tokens, con conectores, letras sueltas y números.
     *
     * @dataProvider nombres_con_su_lista_de_tokens_y_nivel
     * @group whatsapp
     * @test
     */
    public function la_posicion_cuenta_los_conectores($nombre, $palabra, $tokens, $nivel)
    {
        $this->assertSame($tokens, BusquedaHibridaDeArticulosHelper::tokens($nombre));

        $encajes = BusquedaHibridaDeArticulosHelper::coincidencias([(object) ['id' => 1, 'name' => $nombre]], [$palabra]);

        $this->assertCount(1, $encajes);
        $this->assertSame($nivel, $encajes[0]['nivel']);
        $this->assertSame(1, $encajes[0]['cobertura']);
    }

    // ── CA2: el caso original ───────────────────────────────────────────────────────────────────

    /**
     * Top vectorial = sólo ruido; las bordeadoras con similitud baja (≤ 0,50). Igual llegan al modelo.
     *
     * @group whatsapp
     * @test
     */
    public function la_bordeadora_economica_llega_al_modelo_con_las_nober()
    {
        $this->sembrar_catalogo([
            self::CORTACERCO => 0.99, self::NOBER_CORTACERCO => 0.98, self::CORTADORA => 0.95, self::CUCHILLA => 0.92,
            self::BUSCAPOLO => 0.60, self::ESCOBA => 0.55,
            self::CARRETEL_SCHAEFFER => 0.50,
            self::NOBER_CAP400 => 0.40, self::NOBER_CAP500 => 0.39, self::NOBER_M215 => 0.38,
            self::NOBER_M212 => 0.37, self::NOBER_M205 => 0.36,
        ]);

        $this->fingir_deepseek_y_openai('Sí, tenemos bordeadoras.');

        $resultado = (new WhatsappBotAiService())->simular_respuesta($this->comercio->id, 'Busco una bordeadora económica');
        $this->assertSame('Sí, tenemos bordeadoras.', $resultado['body']);

        $payload = $this->payload_de_chat();

        $this->assertStringContainsString(self::NOBER_CAP400 . ' | Precio: $117.975,00 | Stock: 6', $payload);
        $this->assertStringContainsString(self::NOBER_CAP500 . ' | Precio: $137.940,00 | Stock: 8', $payload);
        $this->assertStringContainsString(self::NOBER_M215 . ' | Precio: $165.165,00 | Stock: 2', $payload);
        $this->assertStringContainsString(self::NOBER_M205 . ' | Precio: $0,00 | Stock: 0', $payload);
        $this->assertStringContainsString(self::NOBER_M212 . ' | Precio: $0,00 | Stock: 0', $payload);
        $this->assertStringNotContainsString(self::BUSCAPOLO, $payload);
        $this->assertStringNotContainsString(self::ESCOBA, $payload);

        $primera_nober = strpos($payload, 'NOBER BORDEADORA');
        $this->assertNotFalse($primera_nober);
        $primer_carretel = strpos($payload, 'CARRETEL P/BORDEADORA');
        $this->assertTrue($primer_carretel === false || $primera_nober < $primer_carretel, 'Ningún CARRETEL P/BORDEADORA antes que una Nober.');
    }

    protected function fingir_deepseek_y_openai($texto)
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [1.0, 0.0, 0.0]]],
                'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
            ], 200),
            'api.deepseek.com/*' => Http::response([
                'model'   => 'deepseek-flash-test',
                'content' => [['type' => 'text', 'text' => $texto]],
                'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** El request de chat (no el de embeddings). */
    protected function request_de_chat()
    {
        $capturado = null;
        Http::assertSent(function ($request) use (&$capturado) {
            if (strpos($request->url(), 'api.deepseek.com') === false) {
                return false;
            }
            $capturado = $request;

            return true;
        });
        $this->assertNotNull($capturado, 'Tendría que haber salido un request a DeepSeek.');

        return $capturado;
    }

    /** El payload de chat como string JSON sin escapar barras ni tildes. */
    protected function payload_de_chat()
    {
        return json_encode($this->request_de_chat()->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── CA3: todos los candidatos antes del corte ───────────────────────────────────────────────

    /**
     * Catálogo aislado (sin el catálogo base): 5 accesorios P/BORDEADORA con ids bajos y una Nober creada
     * ÚLTIMA. Con tope 3 la Nober (nivel 0) tiene que sobrevivir al corte aunque su id sea el más alto.
     *
     * @group whatsapp
     * @test
     */
    public function la_maquina_de_id_alto_sobrevive_al_tope_de_candidatos()
    {
        $this->fingir_openai();

        foreach ([self::CARRETEL_TANZA, self::CARRETEL_CHICO, self::CARRETEL_GRANDE, self::CARRETEL_SCHAFER, self::CARRETEL_UNIVERSAL] as $nombre) {
            $this->articulo($nombre, 0.90);
        }
        $nober = $this->articulo(self::NOBER_CAP400, 0.10);

        BusquedaHibridaDeArticulosHelper::$tope_candidatos_con_vector = 3;

        $resultado = $this->servicio()->search_hybrid_articles('bordeadora', $this->comercio->id, 8);

        $this->assertSame((int) $nober->id, (int) $resultado->first()->id, 'La Nober de id alto va primera pese al tope de 3.');
    }

    // ── CA4: vectoriales garantizados y palabra completa ────────────────────────────────────────

    /**
     * @group whatsapp
     * @test
     */
    public function el_seguimiento_sin_producto_es_el_vectorial_de_siempre()
    {
        $this->sembrar_catalogo([
            self::CORTACERCO => 0.9, self::NOBER_CORTACERCO => 0.8, self::CORTADORA => 0.7, self::CUCHILLA => 0.6,
            self::BUSCAPOLO => 0.5, self::ESCOBA => 0.45, self::TENAZAS => 0.44, self::EXTENSION => 0.43,
            self::FOTOCELULA => 0.01,
        ]);
        $this->fingir_openai();

        $hibrido   = $this->servicio()->search_hybrid_articles('dale mandame la foto', $this->comercio->id, 8);
        $vectorial = $this->servicio()->search_similar_articles('dale mandame la foto', $this->comercio->id, 8);

        $this->assertSame($this->ids($vectorial), $this->ids($hibrido), 'Sin palabra de producto: idéntico, ids y orden, al vectorial.');
        $this->assertNotContains(self::FOTOCELULA, $this->nombres($hibrido));
    }

    /**
     * "desmalezadora" sólo encaja en dos accesorios (nivel 1): quedan primeros y, atrás, los 6 primeros
     * vectoriales de siempre (los 5 mínimos siempre presentes).
     *
     * @group whatsapp
     * @test
     */
    public function una_palabra_secundaria_conserva_cinco_vectoriales()
    {
        $this->sembrar_catalogo([
            self::CORTACERCO => 0.99, self::NOBER_CORTACERCO => 0.98, self::CORTADORA => 0.97, self::CUCHILLA => 0.96,
            self::BUSCAPOLO => 0.95, self::ESCOBA => 0.94, self::TENAZAS => 0.93, self::EXTENSION => 0.92,
            self::BUJIA => 0.01, self::FILTRO => 0.02,
        ]);
        $this->fingir_openai();

        $vectorial = $this->nombres($this->servicio()->search_similar_articles('desmalezadora', $this->comercio->id, 8));
        $hibrido   = $this->nombres($this->servicio()->search_hybrid_articles('desmalezadora', $this->comercio->id, 8));

        $this->assertCount(8, $hibrido);

        $lexicos = array_slice($hibrido, 0, 2);
        $this->assertEqualsCanonicalizing([self::BUJIA, self::FILTRO], $lexicos, 'Los dos accesorios con "desmalezadora" van primeros.');

        $vectoriales_esperados = array_slice(array_values(array_diff($vectorial, $lexicos)), 0, 6);
        $this->assertSame($vectoriales_esperados, array_slice($hibrido, 2), 'Atrás, los primeros del vectorial de hoy, en su orden.');
    }

    // ── CA5: carretel antes que máquinas ────────────────────────────────────────────────────────

    /**
     * @group whatsapp
     * @test
     */
    public function el_carretel_va_antes_que_las_maquinas()
    {
        $this->sembrar_catalogo([
            self::NOBER_CAP400 => 0.95, self::NOBER_CAP500 => 0.94, self::NOBER_M215 => 0.93,
            self::CARRETEL_SCHAEFFER => 0.80, self::CARRETEL_TANZA => 0.79, self::CARRETEL_CHICO => 0.78,
            self::CARRETEL_GRANDE => 0.77, self::CARRETEL_SCHAFER => 0.76, self::CARRETEL_UNIVERSAL => 0.75,
            self::CARRETEL_AUTO => 0.74,
        ]);
        $this->fingir_openai();

        $nombres = $this->nombres($this->servicio()->search_hybrid_articles('¿Tenés carretel para bordeadora?', $this->comercio->id, 8));

        $this->assertSame([
            self::CARRETEL_SCHAEFFER, self::CARRETEL_TANZA, self::CARRETEL_CHICO,
            self::CARRETEL_GRANDE, self::CARRETEL_SCHAFER, self::CARRETEL_UNIVERSAL,
        ], array_slice($nombres, 0, 6), 'Los 6 primeros son CARRETEL, ordenados por similitud.');

        foreach ($nombres as $posicion => $nombre) {
            if (strpos($nombre, 'NOBER BORDEADORA') === 0) {
                $this->assertGreaterThanOrEqual(6, $posicion, 'Ninguna Nober antes que un CARRETEL.');
            }
        }
    }

    // ── CA6: alternancia contra vectores adversariales ──────────────────────────────────────────

    /**
     * Toda BORDEADORA es más similar que todo NYLON. Un orden global por similitud pondría 6 bordeadoras
     * arriba; la alternancia por palabra exige el orden exacto de abajo.
     *
     * @group whatsapp
     * @test
     */
    public function la_similitud_no_rompe_la_alternancia_entre_nylon_y_bordeadora()
    {
        $ids = $this->sembrar_catalogo([
            self::CORTACERCO => 0.99, self::NOBER_CORTACERCO => 0.98, self::CORTADORA => 0.97, self::CUCHILLA => 0.96,
            self::TENAZAS => 0.60, self::EXTENSION => 0.60, self::FOTOCELULA => 0.60, self::BUSCAPOLO => 0.60, self::ESCOBA => 0.60,
            self::CARRETEL_SCHAEFFER => 0.95, self::NOBER_CAP400 => 0.94, self::NOBER_CAP500 => 0.93,
            self::NOBER_M215 => 0.92, self::NOBER_M212 => 0.91, self::NOBER_M205 => 0.90,
            self::CARRETEL_TANZA => 0.89, self::CARRETEL_CHICO => 0.88, self::CARRETEL_GRANDE => 0.87,
            self::CARRETEL_SCHAFER => 0.86, self::CARRETEL_UNIVERSAL => 0.85, self::CARRETEL_AUTO => 0.84,
            self::BUJIA => 0.83, self::CARBON => 0.82, self::FILTRO => 0.81, self::LLAVE_SCHAFER => 0.80,
            self::NYLON_ABRAZADERA => 0.30, self::NYLON_PRINGLES => 0.20, self::NYLON_PLANA => 0.10,
            self::NYLON_CARRO => 0.05, self::NYLON_REDONDO => 0.05, self::NYLON_DURACRIL => 0.05,
        ]);
        $this->fingir_openai();

        $resultado = $this->servicio()->search_hybrid_articles('Buen día necesito nylon para bordeadora', $this->comercio->id, 8);

        $esperado = [
            $ids[self::NYLON_ABRAZADERA], $ids[self::CARRETEL_SCHAEFFER], $ids[self::NYLON_PRINGLES],
            $ids[self::NOBER_CAP400], $ids[self::NYLON_PLANA], $ids[self::NOBER_CAP500],
            $ids[self::CORTACERCO], $ids[self::NOBER_CORTACERCO],
        ];

        $this->assertSame($esperado, $this->ids($resultado), 'Alternancia nylon/bordeadora y 2 vectoriales: ' . json_encode($this->nombres($resultado)));

        foreach ([self::TENAZAS, self::EXTENSION, self::FOTOCELULA, self::BUSCAPOLO, self::ESCOBA] as $ruido) {
            $this->assertNotContains($ruido, $this->nombres($resultado));
        }
    }

    // ── CA7: aislamiento ────────────────────────────────────────────────────────────────────────

    /**
     * @group whatsapp
     * @test
     */
    public function otro_dueno_inactivo_y_borrado_no_aparecen()
    {
        $this->fingir_openai();

        $this->articulo(self::NOBER_CAP400, 0.50);
        $ajeno    = $this->articulo('NOBER BORDEADORA DE OTRO DUENO', 0.99, ['user_id' => $this->otro_comercio->id]);
        $inactivo = $this->articulo('NOBER BORDEADORA INACTIVA', 0.99, ['status' => 'inactive']);
        $borrado  = $this->articulo('NOBER BORDEADORA BORRADA', 0.99);
        DB::table('articles')->where('id', $borrado->id)->update(['deleted_at' => Carbon::now()]);

        $resultado = $this->servicio()->search_hybrid_articles('bordeadora', $this->comercio->id, 8);

        $this->assertSame([self::NOBER_CAP400], $this->nombres($resultado));
        foreach ([$ajeno, $inactivo, $borrado] as $excluido) {
            $this->assertNotContains((int) $excluido->id, $this->ids($resultado));
        }
    }

    // ── CA8: costo, proveedor y corte ───────────────────────────────────────────────────────────

    /**
     * @group whatsapp
     * @test
     */
    public function una_sola_llamada_a_openai_y_ninguna_a_anthropic()
    {
        $this->sembrar_catalogo([self::NOBER_CAP400 => 0.40]);
        $this->fingir_deepseek_y_openai('Sí, tenemos.');

        (new WhatsappBotAiService())->simular_respuesta($this->comercio->id, 'Busco una bordeadora económica');

        $openai = 0;
        $anthropic = 0;
        foreach (Http::recorded() as $par) {
            $url = $par[0]->url();
            if (strpos($url, 'api.openai.com') !== false && strpos($url, '/embeddings') !== false) {
                $openai++;
            }
            if (strpos($url, 'api.anthropic.com') !== false) {
                $anthropic++;
            }
        }

        $this->assertSame(1, $openai, 'Un solo embedding por respuesta.');
        $this->assertSame(0, $anthropic);
        $this->assertSame(1, AiTokenUsage::where('user_id', $this->comercio->id)->where('proceso', 'embeddings_busqueda')->count());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_el_corte_abierto_lanza_como_la_vectorial()
    {
        $this->sembrar_catalogo();
        $this->fingir_openai();

        Cache::put('embeddings_credencial_rechazada', [
            'desde'   => Carbon::now()->toIso8601String(),
            'status'  => 401,
            'mensaje' => 'Incorrect API key provided',
        ], 3600);

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $lanzo = null;
        try {
            $this->servicio()->search_hybrid_articles('bordeadora', $this->comercio->id, 8);
        } catch (\Throwable $e) {
            $lanzo = $e;
        }

        $this->assertInstanceOf(EmbeddingsCredencialRechazadaException::class, $lanzo);

        foreach ($consultas as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(' like ', $sql, 'Ninguna query léxica antes del embedding.');
        }
    }

    // ── CA9: instrucción al modelo ──────────────────────────────────────────────────────────────

    const ENCABEZADO_NUEVO = '[Nota interna del sistema, el cliente no la ve] Resultado de buscar la consulta del cliente en el catálogo del negocio. Usá solo los productos que sirven para lo que pidió. Los que no sirven no los nombres, ni siquiera para decir que no sirven: el cliente no ve esta lista y para él no existen. No hables de la búsqueda ni de "los productos que aparecen". Es una selección del catálogo, no el catálogo completo: si lo que pidió no está en la lista, no digas que no lo tienen; pedí un dato más o decile que lo consultás y le confirmás.';

    const CATALOGO_VACIO = '[Nota interna del sistema, el cliente no la ve] La búsqueda en el catálogo no trajo productos para esta consulta. Eso no quiere decir que el negocio no los tenga: no digas que no hay. Si pregunta por un producto, decile que lo consultás y le confirmás.';

    /**
     * @group whatsapp
     * @test
     */
    public function el_encabezado_es_exactamente_el_nuevo()
    {
        $metodo = new ReflectionMethod(WhatsappBotAiService::class, 'build_catalog_block');
        $metodo->setAccessible(true);

        $articulo = (object) ['name' => 'Sopapa para inodoro', 'final_price' => 3200, 'stock' => 25, 'bar_code' => '7791', 'slug' => 'sopapa', 'online' => 1];
        $bloque   = $metodo->invoke(new WhatsappBotAiService(), [$articulo], null);

        $this->assertSame(self::ENCABEZADO_NUEVO, explode("\n", $bloque)[0]);
        $this->assertStringNotContainsString('con el equipo', $bloque);
        $this->assertSame(self::CATALOGO_VACIO, $metodo->invoke(new WhatsappBotAiService(), [], null));
    }

    public function respuestas_que_prometen_consultar()
    {
        return [
            'sin marca (red de seguridad)' => [
                'Ese carretel no lo veo en la lista, lo consulto y te confirmo.',
                'Ese carretel no lo veo en la lista, lo consulto y te confirmo.',
                '',
            ],
            'con marca' => [
                "Ese carretel no lo veo en la lista, lo consulto y te confirmo.\n[CONSULTA:carretel para M215]",
                'Ese carretel no lo veo en la lista, lo consulto y te confirmo.',
                'carretel para M215',
            ],
        ];
    }

    /**
     * La detección de seguimiento existente sigue activa con el catálogo híbrido (fija comportamiento).
     *
     * @dataProvider respuestas_que_prometen_consultar
     * @group whatsapp
     * @test
     */
    public function prometer_consultarlo_deja_el_seguimiento($respuesta_del_modelo, $cuerpo, $nota)
    {
        $this->sembrar_catalogo();
        $this->fingir_deepseek_y_openai($respuesta_del_modelo);

        $resultado = (new WhatsappBotAiService())->simular_respuesta($this->comercio->id, '¿Tenés carretel para la M215?');

        $this->assertSame($cuerpo, $resultado['body']);
        $this->assertSame('consulta', $resultado['follow_up']);
        $this->assertSame($nota, $resultado['follow_up_note']);

        $this->assertStringContainsString('[CONSULTA:', json_encode($this->request_de_chat()->data(), JSON_UNESCAPED_UNICODE), 'La FOLLOW_UP_RULE sigue viajando en el prompt.');
    }

    // ── CA10: latencia en el peor caso ──────────────────────────────────────────────────────────

    /**
     * 15.000 artículos con "PINTURA" en el nombre y 500 con compacto fresco. Se mide el tiempo de la
     * búsqueda híbrida menos el de la vectorial pura sobre los mismos datos (= la parte léxica).
     *
     * @group whatsapp
     * @test
     */
    public function quince_mil_candidatos_en_tiempo_acotado()
    {
        $this->fingir_openai();

        $ahora = Carbon::now()->toDateTimeString();
        for ($lote = 0; $lote < 15; $lote++) {
            $filas = [];
            for ($i = 0; $i < 1000; $i++) {
                $n       = $lote * 1000 + $i;
                $filas[] = [
                    'name'        => 'PINTURA LATEX ' . $n . ' PARA PARED',
                    'user_id'     => $this->comercio->id,
                    'status'      => 'active',
                    'final_price' => 1000,
                    'stock'       => 10,
                    'slug'        => 'pintura-latex-' . $n . '-' . $this->comercio->id,
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ];
            }
            DB::table('articles')->insert($filas);
        }

        $ids = DB::table('articles')->where('user_id', $this->comercio->id)->orderBy('id')->limit(500)->pluck('id')->all();
        $compactos = [];
        foreach ($ids as $posicion => $id) {
            $vector = array_fill(0, 512, 0.0);
            $vector[0] = 1.0 - ($posicion / 1000);
            $vector[1] = sqrt(1 - $vector[0] * $vector[0]);
            $compactos[] = [
                'article_id' => $id,
                'user_id'    => $this->comercio->id,
                'vector'     => pack('g*', ...$vector),
                'embedding_source_hash'  => null,
                'embedding_generated_at' => null,
            ];
        }
        DB::table('article_compact_embeddings')->insert($compactos);

        // Calentamiento y vectorial pura de referencia.
        $this->servicio()->search_similar_articles('pintura', $this->comercio->id, 8);
        $inicio    = microtime(true);
        $this->servicio()->search_similar_articles('pintura', $this->comercio->id, 8);
        $vectorial = (microtime(true) - $inicio) * 1000;

        $inicio   = microtime(true);
        $hibrido  = $this->servicio()->search_hybrid_articles('pintura', $this->comercio->id, 8);
        $total_ms = (microtime(true) - $inicio) * 1000;

        $lexica_ms = $total_ms - $vectorial;
        fwrite(STDERR, sprintf("\n[CA10] híbrida %.0f ms · vectorial %.0f ms · parte léxica ≈ %.0f ms\n", $total_ms, $vectorial, $lexica_ms));

        $this->assertCount(8, $hibrido);
        $this->assertLessThan(1500, $lexica_ms, 'La parte léxica con 15.000 candidatos tiene que quedar bajo 1.500 ms.');
    }

    // ── Misión agente-medidas-decimales: "2,5", "2.5" y "2,50" son la misma medida ───────────────

    /**
     * El decimal es UN token: sin esto "cable de 2,5" se partía en `2` y `5` y los cables 1X2,50
     * (que el negocio tiene en stock) quedaban detrás de acero galvanizado 2 MM y morsas de 5".
     *
     * @return array<int, array>
     */
    public function medidas_decimales_con_sus_tokens()
    {
        return [
            'coma'                       => ['2,5', ['2d5']],
            'punto'                      => ['2.5', ['2d5']],
            'cable con 1X2,50'           => ['CABLE UNIPOLAR 1X2,50 MM NEGRO', ['cable', 'unipolar', '1x', '2d5', 'mm', 'negro']],
            'pegado a la unidad'         => ['cable 2,5mm', ['cable', '2d5', 'mm']],
            'cero adelante'              => ['0,9', ['0d9']],
            'fraccion en cero'           => ['2,0', ['2']],
            'tres decimales son miles'   => ['HIDROLAVADORA 1.400 W', ['hidrolavadora', '1', '400', 'w']],
            'barra no es decimal'        => ['3/4', ['3', '4']],
        ];
    }

    /**
     * @dataProvider medidas_decimales_con_sus_tokens
     * @group whatsapp
     * @test
     */
    public function los_decimales_son_un_solo_token($texto, $esperados)
    {
        $this->assertSame($esperados, BusquedaHibridaDeArticulosHelper::tokens($texto));
    }

    /**
     * Con las palabras de "cable de 2,5": los cables 2,50 suman cobertura 2 (cable + medida) y el
     * acero galvanizado de 2 MM baja a 1 (ya no coincide con un "2" suelto).
     *
     * @group whatsapp
     * @test
     */
    public function la_medida_decimal_suma_cobertura_entera()
    {
        $palabras = BusquedaHibridaDeArticulosHelper::palabras_de_producto('cable de 2,5');
        $this->assertSame(['cable', '2d5'], $palabras);

        $filas = [
            (object) ['id' => 1, 'name' => 'CABLE PARALELO 2X2,50 MM'],
            (object) ['id' => 2, 'name' => 'CABLE UNIPOLAR 1X2,50 MM NEGRO'],
            (object) ['id' => 3, 'name' => 'CABLE ACERO GALVANIZADO 2 MM. 1 X 19'],
        ];

        $cobertura = [];
        foreach (BusquedaHibridaDeArticulosHelper::coincidencias($filas, $palabras) as $encaje) {
            $cobertura[$encaje['name']] = $encaje['cobertura'];
        }

        $this->assertSame(2, $cobertura['CABLE PARALELO 2X2,50 MM']);
        $this->assertSame(2, $cobertura['CABLE UNIPOLAR 1X2,50 MM NEGRO']);
        $this->assertSame(1, $cobertura['CABLE ACERO GALVANIZADO 2 MM. 1 X 19']);
    }

    /**
     * Caso original de Ferretotal (26/11): el cliente pide "cable de 2,5" y el agente ofrecía sólo el
     * cable de silicona porque los 6 léxicos del grupo combinado eran acero/metro/morsa y los 2
     * vectoriales, ruido. El UNIPOLAR 1X2,50 (379 en stock) tiene que llegar al modelo.
     *
     * @group whatsapp
     * @test
     */
    public function el_cable_de_2_coma_5_llega_al_modelo_con_los_unipolares()
    {
        $silicona  = 'CABLE SILIC.PARA ALTA TEMPERATURA 1 X 2.5 MM2';
        $unipolar  = 'CABLE UNIPOLAR 1X2,50 MM MARRON';
        $consulta  = 'Hola, necesito 15 metros de cable de 2,5 para una instalación fija en una pared';

        $this->articulo('CABLE ACERO GALVANIZADO 2.5 MM. 1 X 19', 0.10, ['final_price' => 500, 'stock' => 0]);
        $this->articulo($silicona, 0.10, ['final_price' => 3616.05, 'stock' => 7]);
        $this->articulo('CABLE ACERO GALVANIZADO 2 MM. 1 X 19', 0.10);
        $this->articulo('CABLE ACERO GALVANIZADO 5 MM. 6 X 7', 0.10);
        $this->articulo('METRO PLASTICO PLEGABLE DE 2 MT', 0.10);
        $this->articulo('MORSA FIJA CON YUNQUE 5"', 0.10);
        $this->articulo($unipolar, 0.50, ['final_price' => 1181.03, 'stock' => 379.5]);

        $ruido = ['TERMOMETRO DIGITAL', 'LINTERNA RECARGABLE', 'CINTA METRICA', 'BALDE DE OBRA', 'GUANTE DE LATEX', 'SOGA TRENZADA', 'LONA REFORZADA', 'CANDADO REDONDO'];
        foreach ($ruido as $i => $nombre) {
            $this->articulo($nombre, 0.99 - $i * 0.01);
        }

        $this->fingir_deepseek_y_openai('Tenemos cable unipolar.');

        $nombres = $this->nombres($this->servicio()->search_hybrid_articles($consulta, $this->comercio->id, 8));
        $this->assertContains($unipolar, $nombres, 'El unipolar de 2,50 tiene que estar entre los 8 que llegan al modelo.');

        $resultado = (new WhatsappBotAiService())->simular_respuesta($this->comercio->id, $consulta);
        $this->assertSame('Tenemos cable unipolar.', $resultado['body']);
        $this->assertStringContainsString($unipolar . ' | Precio: $1.181,03 | Stock: 379', $this->payload_de_chat());
    }
}
