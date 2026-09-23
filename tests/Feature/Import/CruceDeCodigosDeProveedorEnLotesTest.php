<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\import\article\ExcelDuplicateStats;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión `importaciones-largas-y-cruce-de-codigos` (23/9/2026): el cruce de provider_codes del
 * análisis de Excel (`ExcelDuplicateStats::crossCheckProviderCodes()`) consulta en lotes de a 200.
 *
 * Con lotes de 5000, en Servian (568k artículos) MySQL pasó el límite de
 * `eq_range_index_dive_limit` (200), estimó mal, examinó 686.402 filas y la consulta murió a los
 * 120 s del `max_execution_time` del VPS: el análisis quedó en "Falló". El porqué completo está en
 * el docblock de `DB_CHUNK_SIZE`.
 *
 * Lo que protege:
 *  - ninguna consulta a `articles` lleva más de 200 valores en el IN (lo mira `DB::listen`, así
 *    que no depende de la constante sino de lo que de verdad sale hacia MySQL);
 *  - partir en más lotes no cambia los conteos: los códigos que caen justo en los bordes de lote
 *    (199/200/201, 399/400) cuentan igual, un código con artículos en los dos proveedores cuenta
 *    en los dos contadores, y un artículo de OTRO usuario no cuenta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group import
 */
class CruceDeCodigosDeProveedorEnLotesTest extends EmpresaTestCase
{
    /** Tope que tiene que respetar cada IN: `eq_range_index_dive_limit` por defecto. */
    const MAXIMO_POR_IN = 200;

    /** Cantidad de códigos del "Excel": cruza dos bordes de lote (200 y 400). */
    const CANTIDAD_DE_CODIGOS = 450;

    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();
    }

    /** @test */
    public function el_cruce_parte_en_lotes_de_a_lo_sumo_200_y_cuenta_igual()
    {
        $mismo = $this->crear_proveedor('Proveedor del Excel (cruce en lotes)');
        $otro  = $this->crear_proveedor('Otro proveedor (cruce en lotes)');

        $otro_usuario = User::create([
            'name'         => 'Otro comercio cruce en lotes',
            'company_name' => 'Otro comercio cruce en lotes',
            'email'        => 'cruce-lotes-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $codigos = [];
        for ($i = 0; $i < self::CANTIDAD_DE_CODIGOS; $i++) {
            $codigos[] = $this->codigo($i);
        }

        /*
         * Posición en la lista => proveedores del usuario que tienen ese código. Los índices están
         * elegidos sobre los bordes de lote: 199 | 200 y 399 | 400.
         */
        $escenario = [
            0   => [$mismo->id],
            100 => [$mismo->id],
            199 => [$otro->id],
            200 => [$mismo->id, $otro->id],
            201 => [$mismo->id],
            399 => [$mismo->id, $otro->id],
            400 => [null],
            449 => [$mismo->id],
        ];

        foreach ($escenario as $posicion => $proveedores) {
            foreach ($proveedores as $provider_id) {
                $this->crear_articulo($this->user_id, $this->codigo($posicion), $provider_id);
            }
        }

        /* Otro usuario: ni un código que el usuario no tiene (300) ni uno que sí tiene (100) cuentan de más. */
        $this->crear_articulo($otro_usuario->id, $this->codigo(300), $mismo->id);
        $this->crear_articulo($otro_usuario->id, $this->codigo(100), $otro->id);

        /* Un código del usuario que no está en el Excel no cuenta. */
        $this->crear_articulo($this->user_id, 'CRUCE-FUERA-DEL-EXCEL', $mismo->id);

        $tamanios_de_in = [];

        DB::listen(function ($consulta) use (&$tamanios_de_in) {
            if (stripos($consulta->sql, 'from `articles`') === false) {
                return;
            }

            if (preg_match('/`provider_code` in \(([^)]*)\)/i', $consulta->sql, $partes)) {
                $tamanios_de_in[] = substr_count($partes[1], '?');
            }
        });

        $resultado = ExcelDuplicateStats::crossCheckProviderCodes($codigos, $mismo->id, $this->user_id);

        $this->assertNotEmpty($tamanios_de_in, 'no se vio ninguna consulta a articles: el test no está mirando lo que dice');

        foreach ($tamanios_de_in as $tamanio) {
            $this->assertLessThanOrEqual(
                self::MAXIMO_POR_IN,
                $tamanio,
                'una consulta a articles llevó ' . $tamanio . ' valores en el IN: con más de 200 MySQL deja de hacer index dives y puede recorrer todo el catálogo'
            );
        }

        $this->assertSame(self::CANTIDAD_DE_CODIGOS, array_sum($tamanios_de_in), 'algún código no se consultó');
        $this->assertCount(3, $tamanios_de_in, '450 códigos en lotes de 200 son 3 consultas');

        /* 0, 100, 200, 201, 399 y 449. */
        $this->assertSame(6, $resultado['provider_codes_existentes_mismo_proveedor']);

        /* 199, 200, 399 y 400 (sin proveedor también es "otro"). */
        $this->assertSame(4, $resultado['provider_codes_existentes_otros_proveedores']);
    }

    /**
     * Los códigos numéricos viajan a MySQL como string, no como int.
     *
     * analyze() arma la lista con array_keys() de un array indexado por la celda, y PHP convierte
     * "12345" en la clave int 12345. Bindeado como int, MySQL compara la columna varchar
     * numéricamente: no puede usar `articles_user_provider_code_index` para el IN (warning 1739) y
     * recorre todo el catálogo del usuario, sea cual sea el tamaño del lote. Y cuenta de más: el
     * 777 del Excel matchea el "0777" de la base, que la importación (índice por clave de array)
     * nunca matchea.
     *
     * @test
     */
    public function los_codigos_numericos_se_consultan_como_string_y_no_matchean_ceros_a_la_izquierda()
    {
        $mismo = $this->crear_proveedor('Proveedor del Excel (códigos numéricos)');

        $this->crear_articulo($this->user_id, '12345', $mismo->id);
        $this->crear_articulo($this->user_id, '0777', $mismo->id);

        /* Exactamente como los arma analyze(): claves de un array indexado por el valor de la celda. */
        $codigos = array_keys(['12345' => 1, '777' => 1, 'ABC-1' => 1]);

        $this->assertSame('integer', gettype($codigos[0]), 'el escenario pide las claves numéricas convertidas a int, como en analyze()');

        $bindings_del_in = [];

        DB::listen(function ($consulta) use (&$bindings_del_in) {
            if (stripos($consulta->sql, 'from `articles`') === false || stripos($consulta->sql, '`provider_code` in') === false) {
                return;
            }

            /* El primer binding es el user_id; el resto, los valores del IN. */
            $bindings_del_in = array_merge($bindings_del_in, array_slice($consulta->bindings, 1));
        });

        $resultado = ExcelDuplicateStats::crossCheckProviderCodes($codigos, $mismo->id, $this->user_id);

        $this->assertCount(3, $bindings_del_in, 'no se vio la consulta del cruce: el test no está mirando lo que dice');

        foreach ($bindings_del_in as $valor) {
            $this->assertIsString(
                $valor,
                'el código ' . var_export($valor, true) . ' viajó como ' . gettype($valor) . ': MySQL no puede usar el índice de provider_code para ese IN'
            );
        }

        /* Solo "12345": el "777" del Excel no es el "0777" de la base. */
        $this->assertSame(1, $resultado['provider_codes_existentes_mismo_proveedor']);
        $this->assertSame(0, $resultado['provider_codes_existentes_otros_proveedores']);
    }

    /**
     * @param  int $posicion
     * @return string
     */
    protected function codigo($posicion)
    {
        return 'CRUCE-' . str_pad((string) $posicion, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  string $nombre
     * @return Provider
     */
    protected function crear_proveedor($nombre)
    {
        $provider = new Provider();

        $provider->name    = $nombre;
        $provider->user_id = $this->user_id;
        $provider->status  = 'active';

        $provider->save();

        return $provider;
    }

    /**
     * Insert crudo: el cruce solo lee user_id, provider_code y provider_id, y los observers del
     * modelo Article no aportan nada acá.
     *
     * @param  int         $user_id
     * @param  string      $provider_code
     * @param  int|null    $provider_id
     * @return void
     */
    protected function crear_articulo($user_id, $provider_code, $provider_id)
    {
        DB::table('articles')->insert([
            'user_id'       => $user_id,
            'name'          => 'Artículo ' . $provider_code,
            'provider_code' => $provider_code,
            'provider_id'   => $provider_id,
            'status'        => 'active',
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
    }
}
