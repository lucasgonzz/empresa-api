<?php

namespace Tests\Feature\Listado;

use App\Models\Article;
use App\Models\Iva;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Misión `masiva-costo-neto-o-bruto` (3/10/2026) — la actualización masiva de artículos permite
 * declarar si el costo que se fija (o se aumenta/disminuye) es NETO o BRUTO, igual que el ABM, la
 * compra y el import de Excel.
 *
 * LA REGLA (la misma del 20/8/2026, misión `costo-bruto-por-condicion-fiscal`): el que carga el
 * costo declara si es neto o bruto; si es bruto el sistema le saca el IVA con la alícuota DE CADA
 * ARTÍCULO; siempre se guarda el neto. La masiva era la "quinta vía" que escribía `articles.cost`
 * sin resolverlo (hallazgo 3 de ese informe).
 *
 * El contrato con la SPA es un campo OPCIONAL en el ítem del formulario: `cost_incluye_iva`.
 * Ausente significa "como siempre" (neto) — es lo que manda el asistente de IA, una SPA vieja en
 * caché o una masiva ya encolada.
 *
 * 🔴 Los números son la especificación: está prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema. Si un test queda en rojo, se corrige el código.
 *
 * QUEUE sync: ProcessMasiveUpdateJob corre inline dentro del request (mismo criterio que
 * 4_Masiva_de_costo_recalcula_y_revierte_Test).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Masiva_de_costo_neto_o_bruto_Test extends EmpresaTestCase
{
    /** Tolerancia para comparaciones de plata (nunca comparación exacta sobre floats). */
    const DELTA = 0.01;

    /** Condición fiscal que tenía el owner antes del test, para restaurarla en tearDown. */
    private $condicion_previa;

    /**
     * Deja la cuenta como Responsable Inscripto (la que ve el selector neto/bruto) y recuerda lo
     * que había. Los tests que necesitan otra condición la mueven y la restauran en un `finally`.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->condicion_previa = $this->owner()->condicion_iva_precios;

        $this->set_condicion(User::CONDICION_RRII);
    }

    /**
     * Restaura la condición fiscal que tenía el owner.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->set_condicion($this->condicion_previa);

        parent::tearDown();
    }

    /**
     * Owner del fixture, de quien se lee la condición fiscal.
     *
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * @param  string|null $condicion 'MT' o 'RRII'.
     * @return void
     */
    private function set_condicion($condicion)
    {
        $user = $this->owner();
        $user->condicion_iva_precios = $condicion;
        $user->save();
    }

    /**
     * Crea un artículo propio del test, con costo neto y alícuota conocidos.
     *
     * Fixture propio (no el centinela del seeder): sus expectativas absolutas viven en la suite e2e.
     *
     * @param  string $nombre
     * @param  float  $costo_neto
     * @param  string $alicuota   Valor de `ivas.percentage` ('21', '10.5', 'Exento'...).
     * @return \App\Models\Article
     */
    private function crear_articulo_de_costo($nombre, $costo_neto, $alicuota = '21')
    {
        $iva = Iva::where('percentage', $alicuota)->first();

        $this->assertNotNull($iva, 'La base de testing no tiene la alícuota ' . $alicuota . ' sembrada.');

        $provider = Provider::firstOrCreate([
            'name'    => 'zz Proveedor Masiva Costo Neto o Bruto',
            'user_id' => $this->owner()->id,
        ]);

        $article = Article::create([
            'name'        => $nombre,
            'user_id'     => $this->owner()->id,
            'provider_id' => $provider->id,
            'cost'        => $costo_neto,
            'iva_id'      => $iva->id,
            'aplicar_iva' => 1,
        ]);

        return Article::find($article->id);
    }

    /**
     * Dispara la masiva real (`PUT api/update/article`) sobre una selección manual y devuelve el
     * registro de la masiva ya procesado.
     *
     * @param  array $articulos
     * @param  array $update_form
     * @return \App\Models\MasiveUpdate
     */
    private function masiva($articulos, $update_form)
    {
        $ids = [];

        foreach ($articulos as $article) {
            $ids[] = $article->id;
        }

        $response = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => $ids,
            'update_form' => $update_form,
        ]);

        $response->assertStatus(200);

        $masive_update = MasiveUpdate::find(json_decode($response->getContent(), true)['masive_update_id']);

        $this->assertNotNull($masive_update, 'No quedó registrado el MasiveUpdate.');
        $this->assertSame('completed', $masive_update->status, 'La masiva no terminó de procesarse.');

        return $masive_update;
    }

    /**
     * @param  \App\Models\Article $article
     * @return float
     */
    private function costo($article)
    {
        return (float) Article::find($article->id)->cost;
    }

    /**
     * Test 1 — el caso que motivó la misión: se fija 1210 declarando que es BRUTO y el sistema
     * guarda 1000 (artículo al 21%).
     *
     * @group costeo-precios
     * @test
     */
    public function fijar_un_costo_bruto_guarda_el_neto()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva bruto fija', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(1000, $this->costo($article), self::DELTA,
            '1210 con IVA al 21% son 1000 de costo neto, que es lo único que se guarda');
    }

    /**
     * Test 2 — compatibilidad hacia atrás: sin la clave, el valor entra tal cual. Es lo que manda el
     * asistente de IA, una SPA vieja en caché y una masiva ya encolada.
     *
     * @group costeo-precios
     * @test
     */
    public function sin_la_declaracion_el_valor_entra_tal_cual()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva sin clave', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210],
        ]);

        $this->assertEqualsWithDelta(1210, $this->costo($article), self::DELTA,
            'sin cost_incluye_iva el costo se guarda como siempre: literal');
    }

    /**
     * Test 3 — declarar NETO explícitamente (false) tampoco toca el valor.
     *
     * @group costeo-precios
     * @test
     */
    public function declarar_neto_guarda_el_valor_tal_cual()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva neto explicito', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210, 'cost_incluye_iva' => false],
        ]);

        $this->assertEqualsWithDelta(1210, $this->costo($article), self::DELTA,
            'cost_incluye_iva en false es el costo base: no se descompone nada');
    }

    /**
     * Test 4 — cada artículo se descompone con SU alícuota: 21%, 10,5% y Exento en la misma masiva.
     * El Exento no tiene IVA adentro por más que se declare bruto: se guarda tal cual.
     *
     * @group costeo-precios
     * @test
     */
    public function cada_articulo_usa_su_propia_alicuota_y_el_exento_queda_tal_cual()
    {
        $al_21 = $this->crear_articulo_de_costo('zz Masiva bruto 21', 100, '21');
        $al_105 = $this->crear_articulo_de_costo('zz Masiva bruto 10.5', 100, '10.5');
        $exento = $this->crear_articulo_de_costo('zz Masiva bruto exento', 100, 'Exento');

        $this->masiva([$al_21, $al_105, $exento], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1105, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(1105 / 1.21, $this->costo($al_21), self::DELTA, 'al 21%: 1105 / 1,21');
        $this->assertEqualsWithDelta(1000, $this->costo($al_105), self::DELTA, 'al 10,5%: 1105 / 1,105 = 1000');
        $this->assertEqualsWithDelta(1105, $this->costo($exento), self::DELTA,
            'un artículo Exento no tiene IVA que sacar: se guarda tal cual');
    }

    /**
     * Test 5 — un % sin redondear da el mismo costo sobre el neto que sobre el bruto: el selector
     * no cambia el resultado (es proporcional). Se prueba con las dos declaraciones.
     *
     * @group costeo-precios
     * @test
     */
    public function un_porcentaje_sin_redondear_da_lo_mismo_en_neto_y_en_bruto()
    {
        $en_neto = $this->crear_articulo_de_costo('zz Masiva pct neto', 1000);
        $en_bruto = $this->crear_articulo_de_costo('zz Masiva pct bruto', 1000);

        $this->masiva([$en_neto], [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 10, 'cost_incluye_iva' => false],
        ]);
        $this->masiva([$en_bruto], [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 10, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(1100, $this->costo($en_neto), self::DELTA, '+10% sobre 1000 neto');
        $this->assertEqualsWithDelta(1100, $this->costo($en_bruto), self::DELTA,
            '+10% sobre el bruto (1210 → 1331) es 1100 de neto: el IVA es un factor constante');
    }

    /**
     * Test 6 — con "Redondear resultado" y costo BRUTO, lo que queda redondeado es el bruto:
     * 1000 neto = 1210 bruto; +10% = 1331 → redondeado 1331; neto = 1331 / 1,21 = 1100.
     * Con 5%: 1210 × 1,05 = 1270,5 → 1271 (ROUND_HALF_UP) → neto 1050,413...
     * El redondeo sobre el NETO daría 1050 y bruto 1270,5: ese es el comportamiento que NO se quiere.
     *
     * @group costeo-precios
     * @test
     */
    public function redondear_con_costo_bruto_redondea_el_bruto()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva redondea bruto', 1000);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 5, 'round' => 1, 'cost_incluye_iva' => true],
        ]);

        $neto = $this->costo($article);

        $this->assertEqualsWithDelta(1271, $neto * 1.21, self::DELTA,
            'el bruto resultante (neto × 1,21) tiene que ser el entero 1271, no 1270,5');
        $this->assertEqualsWithDelta(1271 / 1.21, $neto, self::DELTA, 'y el neto guardado es 1271 / 1,21');
    }

    /**
     * Test 7 — la contracara: con "Redondear resultado" y costo NETO (o sin declarar) se redondea
     * el neto, como siempre.
     *
     * @group costeo-precios
     * @test
     */
    public function redondear_con_costo_neto_redondea_el_neto_como_siempre()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva redondea neto', 1010);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 5.5, 'round' => 1, 'cost_incluye_iva' => false],
        ]);

        // 1010 + 5,5% = 1065,55: sin redondeo quedaría con decimales, así que este caso sí distingue.
        $this->assertEqualsWithDelta(1066, $this->costo($article), self::DELTA,
            '1010 + 5,5% = 1065,55 y redondeando el NETO queda en 1066');
        $this->assertSame(0.0, fmod($this->costo($article), 1.0), 'el neto queda redondeado');
    }

    /**
     * Test 8 — disminuir con redondeo sobre el bruto, mismo criterio.
     *
     * @group costeo-precios
     * @test
     */
    public function disminuir_con_redondeo_sobre_el_bruto_redondea_el_bruto()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva disminuye bruto', 1000);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'decrement_cost', 'value' => 5, 'round' => 1, 'cost_incluye_iva' => true],
        ]);

        // 1210 × 0,95 = 1149,5 → 1150 (ROUND_HALF_UP) bruto.
        $this->assertEqualsWithDelta(1150, $this->costo($article) * 1.21, self::DELTA,
            'el bruto resultante tiene que ser el entero 1150');
    }

    /**
     * Test 8b — empate genuino en .5 sobre el bruto. `articles.cost` guarda 6 decimales, así que un
     * bruto de 100 vive como neto 82,644628 y al volver a sumarle el IVA da 99,99999988: sin limpiar
     * ese ruido, 100 + 5,5% = 105,5 caía a 105 (ROUND_HALF_UP promete 106). Lo midió el checker
     * adversarial de la Fase 7, en la mitad de los empates.
     *
     * @group costeo-precios
     * @test
     */
    public function un_empate_de_medio_en_el_bruto_redondea_hacia_arriba()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva empate bruto', 82.644628);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'increment_cost', 'value' => 5.5, 'round' => 1, 'cost_incluye_iva' => true],
        ]);

        // El bruto exacto es 100 × 1,055 = 105,5 → 106.
        $this->assertEqualsWithDelta(106, $this->costo($article) * 1.21, self::DELTA,
            'el bruto 105,5 tiene que redondear a 106, no a 105');
    }

    /**
     * Test 8c — repetir la misma masiva de costo bruto no cuenta cambios la segunda vez: el neto se
     * redondea a los 6 decimales de la columna antes de compararlo con el guardado. Antes quedaba
     * 826,4462809917355 contra "826.446281" y cada artículo contaba como modificado.
     *
     * @group costeo-precios
     * @test
     */
    public function repetir_una_masiva_de_costo_bruto_no_cuenta_cambios()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva repetida bruto', 500);

        $form = [['type' => 'number', 'key' => 'set_cost', 'value' => 1000, 'cost_incluye_iva' => true]];

        $primera = $this->masiva([$article], $form);
        $this->assertSame(1, (int) $primera->changes_count, 'la primera vez sí cambia el costo');

        $segunda = $this->masiva([$article], $form);
        $this->assertSame(0, (int) $segunda->changes_count,
            'la segunda vez el costo ya es ese: no hay ningún cambio que contar');
    }

    /**
     * Test 8d — un "false" como TEXTO (solo por API directa; la pantalla manda booleanos) no se
     * trata como bruto ni reordena el formulario: la condición del reordenamiento y la decisión
     * final coinciden.
     *
     * @group costeo-precios
     * @test
     */
    public function un_false_como_texto_se_trata_como_neto()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva false texto', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210, 'cost_incluye_iva' => 'false'],
        ]);

        $this->assertEqualsWithDelta(1210, $this->costo($article), self::DELTA,
            '"false" es neto: el valor se guarda tal cual');
    }

    /**
     * Test 9 — si la misma masiva cambia la alícuota y fija un costo bruto, el costo se descompone
     * con la alícuota NUEVA, sin importar el orden en que llegue el formulario (el costo va primero
     * a propósito: es el orden que hace fallar una implementación ingenua).
     *
     * @group costeo-precios
     * @test
     */
    public function el_costo_bruto_usa_la_alicuota_nueva_si_la_misma_masiva_la_cambia()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva cambia iva', 500, '21');

        $iva_105 = Iva::where('percentage', '10.5')->first();

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1105, 'cost_incluye_iva' => true],
            ['type' => 'select', 'key' => 'iva_id', 'value' => $iva_105->id],
        ]);

        $this->assertEqualsWithDelta(1000, $this->costo($article), self::DELTA,
            '1105 con la alícuota NUEVA (10,5%) son 1000; con la vieja (21%) darían 913,22');
    }

    /**
     * Test 10 — un Monotributista migrado nunca descompone: se ignora lo que declare la carga
     * (decide la condición fiscal, no el que llama). Lo hace seguro frente a una pantalla vieja o
     * a un llamado directo a la API que mande `true`.
     *
     * @group costeo-precios
     * @test
     */
    public function un_monotributista_migrado_ignora_la_declaracion_de_bruto()
    {
        $this->set_condicion(User::CONDICION_MT);

        $user = $this->owner();
        $this->assertTrue((bool) $user->usar_condicion_fiscal_en_costeo,
            'el fixture tiene que estar migrado a precios por condición fiscal para este test');

        $article = $this->crear_articulo_de_costo('zz Masiva MT', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(1210, $this->costo($article), self::DELTA,
            'el costo que carga un Monotributista es el que paga: no se le saca el IVA');
    }

    /**
     * Test 11 — revertir devuelve el costo NETO original: el historial guarda el neto que se
     * escribió, no el bruto que se tipeó.
     *
     * @group costeo-precios
     * @test
     */
    public function revertir_una_masiva_de_costo_bruto_restaura_el_neto_original()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva revierte bruto', 777);

        $masive_update = $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_cost', 'value' => 1210, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(1000, $this->costo($article), self::DELTA);

        $this->postJson('api/masive-update/' . $masive_update->id . '/revert')->assertStatus(200);

        $this->assertEqualsWithDelta(777, $this->costo($article), self::DELTA,
            'revertir tiene que dejar el costo neto exactamente como estaba');
    }

    /**
     * Test 12 — la clave solo tiene sentido para el costo de un artículo: sobre otro campo numérico
     * no cambia nada (set_stock lo resuelve el movimiento de stock, ajeno al IVA).
     *
     * @group costeo-precios
     * @test
     */
    public function la_declaracion_no_afecta_a_otros_campos_numericos()
    {
        $article = $this->crear_articulo_de_costo('zz Masiva otro campo', 500);

        $this->masiva([$article], [
            ['type' => 'number', 'key' => 'set_stock_min', 'value' => 121, 'cost_incluye_iva' => true],
        ]);

        $this->assertEqualsWithDelta(121, (float) Article::find($article->id)->stock_min, self::DELTA,
            'stock_min se guarda literal: cost_incluye_iva solo aplica a `cost`');
        $this->assertEqualsWithDelta(500, $this->costo($article), self::DELTA, 'y el costo no se toca');
    }
}
