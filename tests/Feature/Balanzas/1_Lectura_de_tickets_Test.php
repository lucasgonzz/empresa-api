<?php

namespace Tests\Feature\Balanzas;

use App\Models\Article;
use App\Models\Balanza;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión balanzas-configurables (3/10/2026) — la lectura de tickets de balanza en VENDER, contra el
 * endpoint real `GET api/vender/buscar-articulo-por-codido/{code}`.
 *
 * Los códigos son los REALES relevados en producción el 3/10/2026 (plan §2 y §3.3): los `22…` de
 * Panchito, cuyo importe coincide con los renglones guardados en sus ventas, y el `20…` de La
 * Martina (PLU 143, 0,170 kg). Los resultados esperados están escritos a mano, no calculados con
 * el helper: si la lectura se corre un dígito, estos números lo dicen.
 *
 * Lo que se protege, en orden:
 *   1. Los siete vectores del plan (importe, peso en Kilo y en Gramo, dígitos configurados, y el
 *      prefijo más largo que gana).
 *   2. La configuración del dueño manda: sin modo (o 'ninguno') no se lee NADA aunque el dueño
 *      tenga las extensiones viejas, y las dos lecturas son excluyentes.
 *   3. Los bordes: un artículo con ese código de barras le gana a la balanza, una balanza de otro
 *      dueño no existe para este, una balanza sin artículo válido avisa, y un código corto no se lee.
 *
 * Todo corre adentro de la transacción de EmpresaTestCase (dueño 500 del fixture, autenticado).
 * El modo se escribe directo en la fila del dueño: sin sesión, UserHelper lo relee de la base en
 * cada request.
 *
 * @group balanzas
 */
class Lectura_de_tickets_Test extends EmpresaTestCase
{
    /** Tolerancia para los pesos (son floats). */
    const DELTA = 0.0001;

    /** Ids de `unidad_medidas` (UnidadMedidaSeeder): 2 = Gramo, 3 = Kilo. */
    const UNIDAD_GRAMO = 2;
    const UNIDAD_KILO = 3;

    /** Códigos reales de Panchito: prefijo 22 + importe(7) + verificador. */
    const CODIGO_PANCHITO_2714 = '2201000027143';
    const CODIGO_PANCHITO_8580 = '2202000085805';
    const CODIGO_PANCHITO_9955 = '2203000099557';

    /** Código real de La Martina: 20 + PLU(5) 00143 + peso(5) 00170 + verificador. */
    const CODIGO_LA_MARTINA = '2000143001702';

    /** @var \App\Models\User */
    protected $dueno;

    /**
     * Toma al dueño del fixture de testing (TestingFerreteriaSeeder): las balanzas, el modo y los
     * artículos de cada test se le cargan a él, adentro de la transacción del test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Ayudas
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Escribe `tickets_de_balanza` en la fila del dueño.
     *
     * @param  string|null  $modo
     * @return void
     */
    protected function modo($modo)
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['tickets_de_balanza' => $modo]);
    }

    /**
     * Un artículo del dueño (o de otro), con nombre único para no chocar con el fixture.
     *
     * @param  string  $nombre
     * @param  array   $extra
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $extra = [])
    {
        return Article::create(array_merge([
            'name'    => 'zz Balanzas ' . $nombre . ' ' . uniqid(),
            'user_id' => $this->dueno->id,
            'status'  => 'active',
        ], $extra));
    }

    /**
     * Una balanza, creada directo (el ABM se prueba en el archivo 2).
     *
     * @param  string    $prefijo
     * @param  int|null  $article_id
     * @param  string    $tipo_dato
     * @param  int|null  $digitos
     * @param  array     $extra
     * @return \App\Models\Balanza
     */
    protected function balanza($prefijo, $article_id, $tipo_dato = 'importe', $digitos = null, $extra = [])
    {
        return Balanza::create(array_merge([
            'user_id'    => $this->dueno->id,
            'nombre'     => null,
            'prefijo'    => $prefijo,
            'article_id' => $article_id,
            'tipo_dato'  => $tipo_dato,
            'digitos'    => $digitos,
        ], $extra));
    }

    /**
     * Escanea un código como lo hace VENDER y devuelve la respuesta (siempre 200).
     *
     * @param  string  $codigo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function escanear($codigo)
    {
        $response = $this->getJson('api/vender/buscar-articulo-por-codido/' . $codigo);

        $response->assertStatus(200);

        return $response;
    }

    /**
     * Le asigna al dueño una extensión del catálogo (la crea si la base no la tiene).
     *
     * @param  string  $slug
     * @return void
     */
    protected function prender_extension_vieja($slug)
    {
        $extension = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extension)) {
            $extension = ExtencionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug]);
        }

        $this->dueno->extencions()->attach($extension->id);
    }

    /**
     * Afirma que la respuesta NO leyó ningún ticket: sin artículo y sin ninguna de las claves de
     * balanza.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @param  string  $que
     * @return void
     */
    protected function assert_no_leyo_nada($response, $que)
    {
        $json = $response->json();

        $this->assertNull($json['article'], $que . ': no tenía que encontrar artículo. Respuesta: ' . json_encode($json));
        $this->assertArrayNotHasKey('from_balanza', $json, $que . ': no tenía que leerse como importe.');
        $this->assertArrayNotHasKey('from_balanza_plu', $json, $que . ': no tenía que leerse como peso/PLU.');
        $this->assertArrayNotHasKey('balanza_sin_articulo', $json, $que . ': no tenía que avisar de una balanza.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Los vectores del plan (§3.3)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Los tres códigos reales de Panchito con la balanza '22' de importe: $2.714, $8.580 y $9.955.
     * Son los importes que coinciden con los renglones guardados en sus ventas.
     *
     * @test
     */
    public function importe_los_tres_codigos_reales_de_panchito()
    {
        $this->modo('balanzas');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $balanza = $this->balanza('22', $carniceria->id, 'importe');

        $esperados = [
            self::CODIGO_PANCHITO_2714 => 2714,
            self::CODIGO_PANCHITO_8580 => 8580,
            self::CODIGO_PANCHITO_9955 => 9955,
        ];

        foreach ($esperados as $codigo => $importe) {

            $json = $this->escanear($codigo)->json();

            $this->assertTrue($json['from_balanza'] ?? false, $codigo . ': tenía que leerse como ticket de importe. Respuesta: ' . json_encode($json));
            $this->assertSame($importe, $json['price_vender'], $codigo . ': importe mal leído.');
            $this->assertSame($balanza->id, $json['balanza_id'], $codigo . ': la balanza no es la que leyó el ticket.');
            $this->assertEquals($carniceria->id, $json['article']['id'], $codigo . ': el ticket se le imputa al artículo de la balanza.');
            $this->assertArrayNotHasKey('from_balanza_plu', $json);
        }
    }

    /**
     * `2203000099557` con las balanzas '22' y '2203': gana '2203', el prefijo más largo, aunque
     * se haya cargado DESPUÉS (id más grande). Un `2201…` sigue yendo a la '22'.
     *
     * @test
     */
    public function gana_el_prefijo_mas_largo()
    {
        $this->modo('balanzas');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $fiambreria = $this->crear_articulo('Fiambreria', ['price' => 0]);

        $general = $this->balanza('22', $carniceria->id, 'importe');
        $especifica = $this->balanza('2203', $fiambreria->id, 'importe');

        $this->assertGreaterThan($general->id, $especifica->id, 'La específica tiene que ser la más nueva para que el test mida algo.');

        $json = $this->escanear(self::CODIGO_PANCHITO_9955)->json();

        $this->assertSame($especifica->id, $json['balanza_id'] ?? null, 'Con 22 y 2203 tiene que ganar 2203. Respuesta: ' . json_encode($json));
        $this->assertEquals($fiambreria->id, $json['article']['id']);
        $this->assertSame(9955, $json['price_vender']);

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertSame($general->id, $json['balanza_id'], 'Un 2201… no empieza con 2203: es de la 22.');
        $this->assertEquals($carniceria->id, $json['article']['id']);
    }

    /**
     * `2300013002006` con la balanza '23' de peso y un artículo en Kilo: 200 gramos -> 0,2.
     * Viaja con las claves del PLU (`from_balanza_plu` + `amount`) más `balanza_id`.
     *
     * @test
     */
    public function peso_en_kilo_divide_por_mil()
    {
        $this->modo('balanzas');

        $verdura = $this->crear_articulo('Verduleria kilo', ['unidad_medida_id' => self::UNIDAD_KILO]);
        $balanza = $this->balanza('23', $verdura->id, 'peso');

        $json = $this->escanear('2300013002006')->json();

        $this->assertTrue($json['from_balanza_plu'] ?? false, 'El peso viaja con las claves del PLU. Respuesta: ' . json_encode($json));
        $this->assertEqualsWithDelta(0.2, $json['amount'], self::DELTA, '200 gramos de un artículo en Kilo son 0,2.');
        $this->assertSame($balanza->id, $json['balanza_id']);
        $this->assertEquals($verdura->id, $json['article']['id']);
        $this->assertArrayNotHasKey('from_balanza', $json, 'Un ticket de peso no es de importe.');
    }

    /**
     * El mismo código con un artículo en Gramo: 200, sin dividir (la misma regla que el PLU).
     *
     * @test
     */
    public function peso_en_gramo_no_divide()
    {
        $this->modo('balanzas');

        $especias = $this->crear_articulo('Especias gramo', ['unidad_medida_id' => self::UNIDAD_GRAMO]);
        $this->balanza('23', $especias->id, 'peso');

        $json = $this->escanear('2300013002006')->json();

        $this->assertTrue($json['from_balanza_plu'] ?? false);
        $this->assertEqualsWithDelta(200, $json['amount'], self::DELTA, 'En Gramo el peso se usa tal cual.');
    }

    /**
     * `2100143001702` con la balanza '21' de importe y 5 dígitos: los 5 anteriores al verificador
     * son 00170 -> $170.
     *
     * @test
     */
    public function importe_con_digitos_configurados()
    {
        $this->modo('balanzas');

        $articulo = $this->crear_articulo('Panaderia');
        $this->balanza('21', $articulo->id, 'importe', 5);

        $json = $this->escanear('2100143001702')->json();

        $this->assertTrue($json['from_balanza'] ?? false, 'Respuesta: ' . json_encode($json));
        $this->assertSame(170, $json['price_vender'], 'Con 5 dígitos el importe es 00170.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  La configuración del dueño manda
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Sin modo (NULL) o con 'ninguno' no se lee ningún ticket, AUNQUE el dueño tenga las dos
     * extensiones viejas prendidas, una balanza '22' cargada y un artículo con PLU 143. Con el
     * código anterior a esta misión, la extensión de PLU leía `2000143001702`: esto prueba que las
     * extensiones ya no deciden nada.
     *
     * @test
     */
    public function sin_modo_o_ninguno_no_lee_nada_aunque_tenga_las_extensiones_viejas()
    {
        $this->prender_extension_vieja('balanza_bar_code');
        $this->prender_extension_vieja('plu_balanza_bar_code');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');
        $this->crear_articulo('Hamburguesa PLU', ['plu' => '143', 'unidad_medida_id' => self::UNIDAD_KILO]);

        foreach ([null, 'ninguno'] as $modo) {

            $this->modo($modo);

            $etiqueta = 'modo ' . var_export($modo, true);

            $this->assert_no_leyo_nada($this->escanear(self::CODIGO_PANCHITO_2714), $etiqueta . ', ticket de importe');
            $this->assert_no_leyo_nada($this->escanear(self::CODIGO_LA_MARTINA), $etiqueta . ', ticket PLU');
        }
    }

    /**
     * Modo 'plu' con el código real de La Martina: PLU 143 en Kilo, 170 gramos -> 0,17. Es la
     * lectura de siempre, con las mismas claves (sin `balanza_id`).
     *
     * @test
     */
    public function modo_plu_lee_el_codigo_real_de_la_martina()
    {
        $this->modo('plu');

        $hamburguesa = $this->crear_articulo('Hamburguesa PLU', ['plu' => '143', 'unidad_medida_id' => self::UNIDAD_KILO]);

        $json = $this->escanear(self::CODIGO_LA_MARTINA)->json();

        $this->assertTrue($json['from_balanza_plu'] ?? false, 'Respuesta: ' . json_encode($json));
        $this->assertEquals($hamburguesa->id, $json['article']['id']);
        $this->assertEqualsWithDelta(0.17, $json['amount'], self::DELTA, 'PLU 143, peso 00170 en Kilo: 0,17.');
        $this->assertArrayNotHasKey('balanza_id', $json, 'El PLU no pasa por las balanzas del ABM.');
    }

    /**
     * Modo 'balanzas' NO lee por PLU: el mismo código de La Martina, con el artículo de PLU 143
     * cargado y sin una balanza '20', no encuentra nada.
     *
     * @test
     */
    public function modo_balanzas_no_lee_por_plu()
    {
        $this->modo('balanzas');

        $this->crear_articulo('Hamburguesa PLU', ['plu' => '143', 'unidad_medida_id' => self::UNIDAD_KILO]);

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');

        $this->assert_no_leyo_nada($this->escanear(self::CODIGO_LA_MARTINA), 'modo balanzas, ticket PLU');
    }

    /**
     * 🔴 Una sesión abierta ANTES del despliegue guarda una foto del dueño SIN la columna nueva.
     * Si el código nuevo la atiende en caliente, la configuración se relee de la base: el cajero no
     * tiene que recargar para que la balanza siga andando después de la migración.
     *
     * @test
     */
    public function una_sesion_anterior_a_la_columna_igual_lee_los_tickets()
    {
        $this->modo('balanzas');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');

        // La foto que guardaba la sesión antes de la migración: el dueño sin `tickets_de_balanza`.
        $foto = User::find($this->dueno->id);
        $atributos = $foto->getAttributes();
        unset($atributos['tickets_de_balanza']);
        $foto->setRawAttributes($atributos, true);

        $this->withSession(['auth_user' => $foto, 'owner' => $foto]);

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertTrue($json['from_balanza'] ?? false, 'Con la foto vieja de la sesión igual tiene que leer. Respuesta: ' . json_encode($json));
        $this->assertSame(2714, $json['price_vender']);
    }

    /**
     * Y al revés: modo 'plu' NO lee las balanzas del ABM. Un `2201…` se intenta como PLU (tipo 22,
     * PLU 1000), no encuentra ese PLU y no se le imputa a la balanza '22'.
     *
     * @test
     */
    public function modo_plu_no_lee_las_balanzas()
    {
        $this->modo('plu');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');

        $this->assert_no_leyo_nada($this->escanear(self::CODIGO_PANCHITO_2714), 'modo plu, ticket de importe');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    //  Bordes
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Un artículo cuyo código de barras es exactamente el del ticket le gana a la balanza: la
     * lectura de tickets corre solo si la búsqueda normal no encontró nada.
     *
     * @test
     */
    public function un_articulo_con_ese_codigo_de_barras_le_gana_a_la_balanza()
    {
        $this->modo('balanzas');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');

        $con_ese_codigo = $this->crear_articulo('Con ese codigo', ['bar_code' => self::CODIGO_PANCHITO_2714]);

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertEquals($con_ese_codigo->id, $json['article']['id'], 'El artículo con ese código de barras tiene que ganar.');
        $this->assertFalse($json['has_variants']);
        $this->assertArrayNotHasKey('from_balanza', $json);
    }

    /**
     * Una balanza de OTRO dueño no existe para este: el ticket no se lee (ni avisa).
     *
     * @test
     */
    public function la_balanza_de_otro_dueno_se_ignora()
    {
        $this->modo('balanzas');

        $otro = User::create([
            'name'     => 'Otro comercio balanzas',
            'email'    => 'balanzas-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $suyo = $this->crear_articulo('Carniceria ajena', ['user_id' => $otro->id]);
        $this->balanza('22', $suyo->id, 'importe', null, ['user_id' => $otro->id]);

        $this->assert_no_leyo_nada($this->escanear(self::CODIGO_PANCHITO_2714), 'balanza de otro dueño');
    }

    /**
     * La balanza existe pero su artículo no sirve -> `balanza_sin_articulo` con el nombre de la
     * balanza, para que VENDER diga cuál revisar. Tres formas: borrado, de otro dueño y sin
     * asignar. Sin nombre, se muestra el prefijo.
     *
     * @test
     */
    public function balanza_sin_articulo_valido_avisa()
    {
        $this->modo('balanzas');

        $borrado = $this->crear_articulo('Carniceria borrada', ['price' => 0]);
        $balanza = $this->balanza('22', $borrado->id, 'importe', null, ['nombre' => 'Balanza carnicería']);
        $borrado->delete();

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertNull($json['article']);
        $this->assertFalse($json['has_variants']);
        $this->assertTrue($json['balanza_sin_articulo'] ?? false, 'Artículo borrado. Respuesta: ' . json_encode($json));
        $this->assertSame('Balanza carnicería', $json['balanza_nombre']);
        $this->assertArrayNotHasKey('from_balanza', $json);

        // Artículo de otro dueño (en las bases compartidas viejas un id puede ser de otro comercio).
        $otro = User::create([
            'name'     => 'Otro comercio balanzas sin articulo',
            'email'    => 'balanzas-sin-articulo-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = $this->crear_articulo('Ajeno', ['user_id' => $otro->id]);
        $balanza->article_id = $ajeno->id;
        $balanza->save();

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertTrue($json['balanza_sin_articulo'] ?? false, 'Artículo de otro dueño. Respuesta: ' . json_encode($json));
        $this->assertNull($json['article']);

        // Sin artículo asignado y sin nombre: el aviso nombra el prefijo.
        $balanza->article_id = null;
        $balanza->nombre = null;
        $balanza->save();

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertTrue($json['balanza_sin_articulo'] ?? false, 'Sin artículo. Respuesta: ' . json_encode($json));
        $this->assertSame('22', $json['balanza_nombre'], 'Sin nombre, se muestra el prefijo.');
    }

    /**
     * Un código más corto que prefijo + dígitos + verificador no es un ticket de esa balanza: no se
     * lee y no avisa. Con el largo justo (2 + 7 + 1 = 10) sí se lee.
     *
     * @test
     */
    public function un_codigo_mas_corto_que_prefijo_mas_digitos_mas_verificador_no_se_lee()
    {
        $this->modo('balanzas');

        $carniceria = $this->crear_articulo('Carniceria', ['price' => 0]);
        $this->balanza('22', $carniceria->id, 'importe');

        $this->assert_no_leyo_nada($this->escanear('220002714'), 'código de 9 caracteres con prefijo 22 y 7 dígitos');

        $json = $this->escanear('2200027148')->json();

        $this->assertTrue($json['from_balanza'] ?? false, 'Con 10 caracteres el ticket entra justo. Respuesta: ' . json_encode($json));
        $this->assertSame(2714, $json['price_vender']);
    }

    /**
     * Empate de prefijo (dos balanzas '22', que el ABM no deja crear pero pueden venir de otro
     * lado): gana la de id más chico, siempre la misma.
     *
     * @test
     */
    public function empate_de_prefijo_gana_la_de_id_mas_chico()
    {
        $this->modo('balanzas');

        $primera_articulo = $this->crear_articulo('Primera');
        $segunda_articulo = $this->crear_articulo('Segunda');

        $primera = $this->balanza('22', $primera_articulo->id, 'importe');
        $this->balanza('22', $segunda_articulo->id, 'importe');

        $json = $this->escanear(self::CODIGO_PANCHITO_2714)->json();

        $this->assertSame($primera->id, $json['balanza_id'] ?? null, 'Respuesta: ' . json_encode($json));
        $this->assertEquals($primera_articulo->id, $json['article']['id']);
    }
}
