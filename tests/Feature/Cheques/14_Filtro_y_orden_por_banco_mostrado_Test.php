<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\Helpers\ColumnFiltersHelper;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Misión cheques-filtro-banco-catalogo (10/10/2026) — la lupa y el orden de la columna "Banco" de
 * Tesorería → Cheques van por LO QUE LA COLUMNA MUESTRA.
 *
 * La columna muestra el nombre del banco del catálogo (`cheque_bancos.name`) si el cheque tiene
 * `cheque_banco_id` apuntando a un banco que existe y con nombre no vacío; si no, el texto libre
 * `cheques.banco` (`cheque_banco_texto` de src/mixins/model_functions.js de la SPA). Hasta esta
 * misión el filtro (`POST global-search/cheque` con `filters: [{key: 'banco', type: 'text', ...}]`)
 * y el orden iban sobre el texto guardado: después de que el asistente unifica los bancos (asigna
 * `cheque_banco_id` y no toca el texto) "Banco Entre Rios" se ve "Banco de Entre Ríos" y filtrar
 * "de Entre" no lo encontraba; "Banco BSAS" se ve "Banco Provincia de Buenos Aires" y "Provincia"
 * tampoco; y un cheque con banco del catálogo y sin texto no aparecía nunca.
 *
 * Decisión de interpretación (plan de la misión): es ESTRICTO. Un cheque que se ve "Banco Provincia
 * de Buenos Aires" ya no aparece filtrando por su texto viejo "BSAS": la fila que aparece tiene que
 * tener en pantalla lo que se buscó.
 *
 * De dónde sale cada cosa:
 *  - Los bancos del catálogo, por el endpoint real (`POST api/cheque-banco`).
 *  - El cheque "elegido con el select y sin texto", por un cobro real (`POST api/current-acount/pago`).
 *  - El estado "banco del catálogo + texto viejo distinto" es el que deja la unificación del
 *    asistente (PropuestaBancosChequesIaHelper asigna el id y no toca el texto). Reproducirlo por esa
 *    vía exige el circuito de herramientas del asistente, así que esos cheques se insertan a mano con
 *    los dos datos, igual que en 6_Unificar_bancos_por_ia_Test.
 *  - Un banco inexistente o con nombre vacío, a mano: ningún endpoint deja esos estados.
 *
 * Cada caso afirma primero la PREMISA (qué muestra la tabla, con la regla de `cheque_banco_texto`
 * aplicada sobre `GET cheque`) y recién después qué devuelve el filtro: un rojo por un fixture mal
 * armado tiene que saltar en la premisa, no pasar por un rojo "del defecto".
 *
 * @group cheques
 */
class Filtro_y_orden_por_banco_mostrado_Test extends ChequesTestCase
{
    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * Un banco del catálogo del dueño, por el endpoint real.
     *
     * @param string $nombre
     * @return ChequeBanco
     */
    protected function banco_del_catalogo($nombre)
    {
        $response = $this->postJson('api/cheque-banco', ['name' => $nombre]);

        $response->assertStatus(201);

        return ChequeBanco::find((int) $response->json('model.id'));
    }

    /**
     * Un cheque "unificado": conserva el texto que se escribió al cargarlo y apunta a un banco del
     * catálogo (el estado que deja la unificación del asistente).
     *
     * @param string|null $texto
     * @param ChequeBanco $banco
     * @param array $otros
     * @return Cheque
     */
    protected function cheque_unificado($texto, ChequeBanco $banco, array $otros = [])
    {
        return $this->cheque_a_mano(array_merge([
            'banco'           => $texto,
            'cheque_banco_id' => $banco->id,
        ], $otros));
    }

    /**
     * Un cheque sin banco del catálogo: solo el texto libre de siempre.
     *
     * @param string|null $texto
     * @param array $otros
     * @return Cheque
     */
    protected function cheque_con_texto($texto, array $otros = [])
    {
        return $this->cheque_a_mano(array_merge([
            'banco'           => $texto,
            'cheque_banco_id' => null,
        ], $otros));
    }

    /**
     * Un id de `cheque_bancos` que no existe (no hay foreign keys físicas: un cheque puede quedar
     * apuntando a uno así).
     *
     * @return int
     */
    protected function id_de_banco_inexistente()
    {
        return (int) (ChequeBanco::max('id') ?? 0) + 100000;
    }

    /**
     * Busca cheques por `POST api/global-search/cheque` con el body que arma la SPA (mismo molde
     * que 8_Solapa_endosados_Test::buscar_cheques()). Corta el test si la respuesta no es 200.
     *
     * @param array $filters Filtros/orden de columna, con la forma que manda la lupa del header.
     * @param array $extra_filters Objetos { key, operator, value } (el `in` de la solapa).
     * @return array{ids: array<int, int>, total: int} Los ids EN EL ORDEN en que vinieron y el total.
     */
    protected function buscar_cheques(array $filters = [], array $extra_filters = [])
    {
        $response = $this->postJson('api/global-search/cheque?page=1', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => $extra_filters,
            'filters'        => $filters,
            'conector'       => 'or',
            'per_page'       => 200,
        ]);

        if ($response->getStatusCode() !== 200) {
            $this->fail('global-search/cheque tenía que dar 200 y dio ' . $response->getStatusCode() . ': ' . $response->getContent());
        }

        $ids = [];

        foreach ($response->json('models.data') as $cheque) {
            $ids[] = (int) $cheque['id'];
        }

        return ['ids' => $ids, 'total' => (int) $response->json('models.total')];
    }

    /**
     * El filtro de la lupa de la columna Banco, tal como lo manda la SPA (prop `banco` de
     * src/models/cheque.js: `type: 'text'`), con el criterio que se pida.
     *
     * @param array $criterio que_contenga | igual_que | en_blanco | no_en_blanco | ordenar_de
     * @param string $tipo
     * @return array
     */
    protected function filtro_de_banco(array $criterio, $tipo = 'text')
    {
        return [array_merge(['key' => 'banco', 'type' => $tipo], $criterio)];
    }

    /**
     * El `in` de la solapa (`extra_filters_de_barra` de la SPA).
     *
     * @param array<int, int> $ids
     * @return array
     */
    protected function solapa(array $ids)
    {
        return [['key' => 'id', 'operator' => 'in', 'value' => array_values($ids)]];
    }

    /**
     * Lo que la tabla MUESTRA en la columna Banco para un cheque: la regla de `cheque_banco_texto`
     * de la SPA (el nombre del banco del catálogo si viene y no es vacío; si no, el texto) aplicada
     * sobre el cheque tal como lo devuelve `GET cheque`.
     *
     * @param int $cheque_id
     * @return string
     */
    protected function banco_que_se_ve($cheque_id)
    {
        $cheque = $this->cheque_del_listado($cheque_id);

        $this->assertNotNull($cheque, 'Premisa: el cheque ' . $cheque_id . ' tenía que estar en GET cheque.');

        if (!empty($cheque['cheque_banco'])
            && isset($cheque['cheque_banco']['name'])
            && is_string($cheque['cheque_banco']['name'])
            && $cheque['cheque_banco']['name'] !== '') {
            return $cheque['cheque_banco']['name'];
        }

        return is_null($cheque['banco']) ? '' : (string) $cheque['banco'];
    }

    // ------------------------------------------------------------------------------------------
    // Que contenga
    // ------------------------------------------------------------------------------------------

    /**
     * Los tres ejemplos de Lucas: el texto "Banco Entre Rios" que se ve "Banco de Entre Ríos", el
     * "Banco BSAS" que se ve "Banco Provincia de Buenos Aires", y un cheque elegido con el select y
     * sin texto. Filtrando por lo que se ve, aparecen.
     *
     * @test
     */
    public function que_contenga_encuentra_el_cheque_por_el_nombre_del_catalogo_que_se_ve()
    {
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $er = $this->cheque_unificado('Banco Entre Rios', $entre_rios);
        $bsas = $this->cheque_unificado('Banco BSAS', $provincia);

        // Elegido con el select y sin escribir el texto: un cobro real.
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco mostrado ' . uniqid());

        $sin_texto = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => '', 'cheque_banco_id' => $provincia->id]);

        // Premisas: qué guarda cada cheque y qué muestra la tabla.
        $this->assertEquals($provincia->id, (int) $sin_texto->cheque_banco_id);
        $this->assertTrue(is_null($sin_texto->banco) || $sin_texto->banco === '', 'Premisa: el cheque del cobro no tiene texto.');
        $this->assertEquals('Banco de Entre Ríos', $this->banco_que_se_ve($er->id));
        $this->assertEquals('Banco Provincia de Buenos Aires', $this->banco_que_se_ve($bsas->id));
        $this->assertEquals('Banco Provincia de Buenos Aires', $this->banco_que_se_ve($sin_texto->id));

        // "de Entre": solo está en lo que se ve del primero (su texto dice "Banco Entre Rios").
        $de_entre = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'de Entre']));

        $this->assertContains($er->id, $de_entre['ids'], 'Se ve "Banco de Entre Ríos": filtrar "de Entre" lo tiene que encontrar.');
        $this->assertNotContains($bsas->id, $de_entre['ids']);
        $this->assertNotContains($sin_texto->id, $de_entre['ids']);

        // "Provincia": el unificado con texto "Banco BSAS" y el que no tiene texto.
        $provincia_r = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Provincia']));

        $this->assertContains($bsas->id, $provincia_r['ids'], 'Se ve "Banco Provincia de Buenos Aires": filtrar "Provincia" lo tiene que encontrar.');
        $this->assertContains($sin_texto->id, $provincia_r['ids'], 'Con banco del catálogo y sin texto: se lo encuentra por el nombre que se ve.');
        $this->assertNotContains($er->id, $provincia_r['ids']);

        // Varias palabras: todas tienen que estar en lo que se ve, en cualquier orden (el mismo
        // explode por espacios de siempre).
        $varias = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Buenos Provincia']));

        $this->assertContains($bsas->id, $varias['ids']);
        $this->assertContains($sin_texto->id, $varias['ids']);
        $this->assertNotContains($er->id, $varias['ids']);

        // Sin distinguir mayúsculas ni acentos, como la columna de siempre (utf8mb4_unicode_ci).
        $sin_acento = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'de entre rios']));

        $this->assertContains($er->id, $sin_acento['ids'], '"de entre rios" encuentra "Banco de Entre Ríos" (sin acento ni mayúsculas).');

        // "Ríos", el ejemplo literal de Lucas que ya andaba antes (lo encontraba por el texto
        // "Banco Entre Rios"): sigue encontrándolo, ahora por lo que se ve.
        $rios = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Ríos']));

        $this->assertContains($er->id, $rios['ids'], '"Ríos" sigue encontrando el cheque que se ve "Banco de Entre Ríos".');
        $this->assertNotContains($bsas->id, $rios['ids']);
    }

    /**
     * La otra cara del filtro estricto: el texto viejo que YA NO SE VE no encuentra el cheque. Y un
     * cheque sin banco del catálogo cuyo texto es justamente ese sí aparece, porque eso es lo que
     * se ve en su fila.
     *
     * @test
     */
    public function el_texto_viejo_que_ya_no_se_ve_no_encuentra_el_cheque()
    {
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $unificado = $this->cheque_unificado('Banco BSAS', $provincia);
        $solo_texto = $this->cheque_con_texto('Banco BSAS');

        $this->assertEquals('Banco Provincia de Buenos Aires', $this->banco_que_se_ve($unificado->id));
        $this->assertEquals('Banco BSAS', $this->banco_que_se_ve($solo_texto->id));

        $bsas = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'BSAS']));

        $this->assertNotContains($unificado->id, $bsas['ids'], 'Se ve "Banco Provincia de Buenos Aires": "BSAS" no está en pantalla y no lo puede traer.');
        $this->assertContains($solo_texto->id, $bsas['ids'], 'Sin banco del catálogo se ve "Banco BSAS": ese sí aparece.');

        $igual = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco BSAS']));

        $this->assertNotContains($unificado->id, $igual['ids']);
        $this->assertContains($solo_texto->id, $igual['ids']);
    }

    /**
     * Lo de siempre no se rompe: un cheque sin banco del catálogo se sigue encontrando por su
     * texto, nazca de un cobro real o de un alta a mano.
     *
     * @test
     */
    public function sin_banco_del_catalogo_se_sigue_encontrando_por_el_texto()
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco texto ' . uniqid());

        $del_cobro = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => 'Banco Credicoop', 'cheque_banco_id' => 0]);
        $a_mano = $this->cheque_con_texto('Banco Galicia');

        $this->assertNull($del_cobro->cheque_banco_id, 'Premisa: "Sin banco" del select deja el cheque sin banco del catálogo.');
        $this->assertEquals('Banco Credicoop', $this->banco_que_se_ve($del_cobro->id));
        $this->assertEquals('Banco Galicia', $this->banco_que_se_ve($a_mano->id));

        $credicoop = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Credicoop']));

        $this->assertContains($del_cobro->id, $credicoop['ids']);
        $this->assertNotContains($a_mano->id, $credicoop['ids']);

        $galicia = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Galicia']));

        $this->assertContains($a_mano->id, $galicia['ids']);
        $this->assertNotContains($del_cobro->id, $galicia['ids']);
    }

    /**
     * Un `cheque_banco_id` que apunta a un banco que ya no existe, o a uno con nombre vacío, no
     * muestra nada del catálogo: la tabla cae al texto, y el filtro también.
     *
     * @test
     */
    public function un_banco_inexistente_o_con_nombre_vacio_cae_al_texto()
    {
        $inexistente = $this->cheque_a_mano(['banco' => 'Banco Hipotecario', 'cheque_banco_id' => $this->id_de_banco_inexistente()]);

        // Nombre vacío: el ABM no lo deja (la columna es NOT NULL y el pedido convierte '' en null),
        // así que se inserta a mano.
        $vacio = ChequeBanco::create(['name' => '', 'user_id' => $this->dueno->id]);

        $con_banco_vacio = $this->cheque_a_mano(['banco' => 'Banco Supervielle', 'cheque_banco_id' => $vacio->id]);

        $this->assertNull(ChequeBanco::find($inexistente->cheque_banco_id), 'Premisa: el banco no existe.');
        $this->assertEquals('Banco Hipotecario', $this->banco_que_se_ve($inexistente->id));
        $this->assertEquals('Banco Supervielle', $this->banco_que_se_ve($con_banco_vacio->id));

        $hipotecario = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Hipotecario']));

        $this->assertContains($inexistente->id, $hipotecario['ids'], 'Banco del catálogo inexistente: se encuentra por el texto, que es lo que se ve.');

        $supervielle = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'Supervielle']));

        $this->assertContains($con_banco_vacio->id, $supervielle['ids'], 'Banco del catálogo con nombre vacío: se encuentra por el texto, que es lo que se ve.');

        $igual = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco Supervielle']));

        $this->assertContains($con_banco_vacio->id, $igual['ids']);

        // Y ninguno de los dos está "en blanco": se ve su texto.
        $en_blanco = $this->buscar_cheques($this->filtro_de_banco(['en_blanco' => true]));

        $this->assertNotContains($inexistente->id, $en_blanco['ids']);
        $this->assertNotContains($con_banco_vacio->id, $en_blanco['ids']);
    }

    // ------------------------------------------------------------------------------------------
    // Igual que, en blanco, no en blanco
    // ------------------------------------------------------------------------------------------

    /**
     * "Igual que" compara contra el nombre que se ve, entero (no una parte), sin distinguir
     * mayúsculas ni acentos como la columna de siempre.
     *
     * @test
     */
    public function igual_que_compara_contra_el_nombre_que_se_ve()
    {
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');

        $unificado = $this->cheque_unificado('Banco Entre Rios', $entre_rios);
        $solo_texto = $this->cheque_con_texto('Banco de Entre Ríos');

        $this->assertEquals('Banco de Entre Ríos', $this->banco_que_se_ve($unificado->id));

        $igual = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco de Entre Ríos']));

        $this->assertContains($unificado->id, $igual['ids'], 'Se ve "Banco de Entre Ríos": igual a eso lo tiene que encontrar.');
        $this->assertContains($solo_texto->id, $igual['ids']);

        $texto_viejo = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco Entre Rios']));

        $this->assertNotContains($unificado->id, $texto_viejo['ids'], 'El texto viejo ya no es lo que se ve.');

        $sin_acento = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'banco de entre rios']));

        $this->assertContains($unificado->id, $sin_acento['ids']);
        $this->assertContains($solo_texto->id, $sin_acento['ids']);

        $una_parte = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco de Entre']));

        $this->assertNotContains($unificado->id, $una_parte['ids'], '"Igual que" es el nombre entero, no una parte.');
        $this->assertNotContains($solo_texto->id, $una_parte['ids']);

        // La precedencia de siempre: con "igual que" puesto, el "que contenga" no se aplica.
        $gana_igual = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco de Entre Ríos', 'que_contenga' => 'Provincia']));

        $this->assertContains($unificado->id, $gana_igual['ids'], 'Con "igual que" puesto, el "que contenga" no se aplica (como siempre).');
    }

    /**
     * "En blanco" es que la columna se vea vacía: sin banco del catálogo que se muestre Y sin
     * texto. Un cheque con banco del catálogo y sin texto NO está en blanco (se ve el nombre del
     * banco); uno sin nada, sí — también si apunta a un banco inexistente o con nombre vacío.
     * "No en blanco" es exactamente lo contrario.
     *
     * @test
     */
    public function en_blanco_y_no_en_blanco_miran_lo_que_se_ve()
    {
        $credicoop = $this->banco_del_catalogo('Banco Credicoop');
        $vacio = ChequeBanco::create(['name' => '', 'user_id' => $this->dueno->id]);

        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco en blanco ' . uniqid());

        // Se ven con el nombre del catálogo, sin texto (null y '').
        $catalogo_del_cobro = $this->cobrar_con_cheque($cliente, $cuenta, ['banco' => '', 'cheque_banco_id' => $credicoop->id]);
        $catalogo_texto_vacio = $this->cheque_unificado('', $credicoop);

        // Se ve el texto.
        $con_texto = $this->cheque_con_texto('Banco Galicia');

        // Se ven vacíos.
        $sin_nada = $this->cheque_con_texto(null);
        $sin_nada_texto_vacio = $this->cheque_con_texto('');
        $inexistente_sin_texto = $this->cheque_a_mano(['banco' => null, 'cheque_banco_id' => $this->id_de_banco_inexistente()]);
        $nombre_vacio_sin_texto = $this->cheque_a_mano(['banco' => '', 'cheque_banco_id' => $vacio->id]);

        $no_vacios = [$catalogo_del_cobro, $catalogo_texto_vacio, $con_texto];
        $vacios = [$sin_nada, $sin_nada_texto_vacio, $inexistente_sin_texto, $nombre_vacio_sin_texto];

        $this->assertEquals('Banco Credicoop', $this->banco_que_se_ve($catalogo_del_cobro->id));
        $this->assertEquals('Banco Credicoop', $this->banco_que_se_ve($catalogo_texto_vacio->id));

        foreach ($vacios as $cheque) {
            $this->assertEquals('', $this->banco_que_se_ve($cheque->id), 'Premisa: el cheque ' . $cheque->numero . ' se ve sin banco.');
        }

        $en_blanco = $this->buscar_cheques($this->filtro_de_banco(['en_blanco' => true]));

        foreach ($vacios as $cheque) {
            $this->assertContains($cheque->id, $en_blanco['ids'], 'El cheque ' . $cheque->numero . ' se ve sin banco: está en blanco.');
        }

        foreach ($no_vacios as $cheque) {
            $this->assertNotContains($cheque->id, $en_blanco['ids'], 'El cheque ' . $cheque->numero . ' se ve con banco: no está en blanco.');
        }

        $no_en_blanco = $this->buscar_cheques($this->filtro_de_banco(['no_en_blanco' => true]));

        foreach ($no_vacios as $cheque) {
            $this->assertContains($cheque->id, $no_en_blanco['ids'], 'El cheque ' . $cheque->numero . ' se ve con banco: no está en blanco.');
        }

        foreach ($vacios as $cheque) {
            $this->assertNotContains($cheque->id, $no_en_blanco['ids'], 'El cheque ' . $cheque->numero . ' se ve sin banco: está en blanco.');
        }
    }

    // ------------------------------------------------------------------------------------------
    // Orden
    // ------------------------------------------------------------------------------------------

    /**
     * Las flechas de la columna ordenan por el nombre que se ve, intercalando cheques con y sin
     * banco del catálogo (no los agrupa por el texto guardado). Acotado con el `in` de la solapa,
     * que es como lo manda la SPA y deja el resultado sin cheques ajenos al test.
     *
     *   se ve                              texto guardado
     *   Banco Credicoop                    (sin texto)
     *   Banco de Entre Ríos                Banco Entre Rios
     *   Banco Galicia                      Banco Galicia       (sin catálogo)
     *   Banco Nación                       Banco Nación        (sin catálogo)
     *   Banco Provincia de Buenos Aires    Banco BSAS
     *
     * Por el texto guardado el orden ASC sería Credicoop, Provincia (BSAS), Entre Ríos, Galicia,
     * Nación: no coincide con lo que se ve.
     *
     * @test
     */
    public function las_flechas_ordenan_por_el_nombre_que_se_ve()
    {
        $credicoop = $this->banco_del_catalogo('Banco Credicoop');
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        // Se crean en un orden que no es ninguno de los dos, para que el orden por defecto
        // (created_at, id DESC) tampoco coincida.
        $nacion = $this->cheque_con_texto('Banco Nación');
        $prov = $this->cheque_unificado('Banco BSAS', $provincia);
        $cred = $this->cheque_unificado(null, $credicoop);
        $galicia = $this->cheque_con_texto('Banco Galicia');
        $er = $this->cheque_unificado('Banco Entre Rios', $entre_rios);

        // Uno que no está en la solapa: no puede venir aunque su nombre caiga en el medio.
        $afuera = $this->cheque_con_texto('Banco Ciudad');

        $solapa = $this->solapa([$nacion->id, $prov->id, $cred->id, $galicia->id, $er->id]);

        $this->assertEquals('Banco Credicoop', $this->banco_que_se_ve($cred->id));
        $this->assertEquals('Banco Provincia de Buenos Aires', $this->banco_que_se_ve($prov->id));

        $asc = $this->buscar_cheques($this->filtro_de_banco(['ordenar_de' => 'ASC']), $solapa);

        $this->assertEquals(
            [$cred->id, $er->id, $galicia->id, $nacion->id, $prov->id],
            $asc['ids'],
            'ASC por lo que se ve: Credicoop, de Entre Ríos, Galicia, Nación, Provincia de Buenos Aires.'
        );
        $this->assertEquals(5, $asc['total']);
        $this->assertNotContains($afuera->id, $asc['ids']);

        $desc = $this->buscar_cheques($this->filtro_de_banco(['ordenar_de' => 'DESC']), $solapa);

        $this->assertEquals(
            [$prov->id, $nacion->id, $galicia->id, $er->id, $cred->id],
            $desc['ids'],
            'DESC por lo que se ve: Provincia de Buenos Aires, Nación, Galicia, de Entre Ríos, Credicoop.'
        );
        $this->assertEquals(5, $desc['total']);

        // Orden y filtro a la vez (la lupa con la flecha puesta): filtra por lo que se ve y ordena
        // lo que queda. "ci" está en Galicia, Nación y Provincia; no en Credicoop ni en Entre Ríos
        // (ni en el texto viejo "Banco BSAS").
        $con_filtro = $this->buscar_cheques(
            $this->filtro_de_banco(['ordenar_de' => 'DESC', 'que_contenga' => 'ci']),
            $solapa
        );

        $this->assertEquals(
            [$prov->id, $nacion->id, $galicia->id],
            $con_filtro['ids'],
            '"ci" en lo que se ve, DESC: Provincia de Buenos Aires, Nación, Galicia.'
        );
    }

    /**
     * Una dirección de orden que no es ASC ni DESC sigue siendo un 422, igual que en cualquier otra
     * columna (la guarda de la misión filtros-key-sin-inyeccion no cambia para la columna mostrada).
     *
     * @test
     */
    public function una_direccion_de_orden_invalida_sigue_dando_422()
    {
        $response = $this->postJson('api/global-search/cheque?page=1', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => $this->filtro_de_banco(['ordenar_de' => 'ASC, (SELECT 1)']),
            'conector'       => 'or',
            'per_page'       => 50,
        ]);

        $response->assertStatus(422);

        // La minúscula es una dirección válida (la normaliza direccion_de_orden()): buscar_cheques()
        // corta el test si no da 200.
        $minuscula = $this->buscar_cheques($this->filtro_de_banco(['ordenar_de' => 'asc']));

        $this->assertGreaterThanOrEqual(0, $minuscula['total']);
    }

    // ------------------------------------------------------------------------------------------
    // La solapa, el dueño, el Excel, las otras columnas
    // ------------------------------------------------------------------------------------------

    /**
     * La lupa de Banco combinada con el `in` de la solapa: solo cheques de esa lista que además
     * muestran lo buscado.
     *
     * @test
     */
    public function la_lupa_de_banco_se_combina_con_el_in_de_la_solapa()
    {
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $bsas = $this->cheque_unificado('Banco BSAS', $provincia);
        $pcia = $this->cheque_unificado('Bco Pcia', $provincia);
        $texto_provincia = $this->cheque_con_texto('Banco Provincia');
        $galicia = $this->cheque_con_texto('Banco Galicia');

        // La solapa tiene bsas, texto_provincia y galicia; pcia muestra "Provincia" pero no está en ella.
        $resultado = $this->buscar_cheques(
            $this->filtro_de_banco(['que_contenga' => 'Provincia']),
            $this->solapa([$bsas->id, $texto_provincia->id, $galicia->id])
        );

        $this->assertEqualsCanonicalizing([$bsas->id, $texto_provincia->id], $resultado['ids']);
        $this->assertEquals(2, $resultado['total']);
        $this->assertNotContains($pcia->id, $resultado['ids'], 'Muestra "Provincia" pero no está en la solapa.');
        $this->assertNotContains($galicia->id, $resultado['ids'], 'Está en la solapa pero no muestra "Provincia".');
    }

    /**
     * El scope por dueño no cambia: los cheques de otro comercio no aparecen aunque muestren lo
     * buscado (con su propio banco del catálogo o con el texto).
     *
     * @test
     */
    public function los_cheques_de_otro_dueno_no_aparecen()
    {
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');

        $propio = $this->cheque_unificado('Banco Entre Rios', $entre_rios);

        // El otro comercio, con su propio banco del catálogo y sus cheques (a mano: ningún endpoint
        // crea cheques de otro dueño).
        $otro_dueno = User::create([
            'name'     => 'Otro comercio banco mostrado',
            'email'    => 'cheques-banco-mostrado-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $banco_ajeno = ChequeBanco::create(['name' => 'Banco de Entre Ríos', 'user_id' => $otro_dueno->id]);

        $ajeno_unificado = $this->cheque_a_mano([
            'user_id'         => $otro_dueno->id,
            'banco'           => 'Banco Entre Rios',
            'cheque_banco_id' => $banco_ajeno->id,
        ]);
        $ajeno_texto = $this->cheque_a_mano(['user_id' => $otro_dueno->id, 'banco' => 'Banco de Entre Ríos']);

        $de_entre = $this->buscar_cheques($this->filtro_de_banco(['que_contenga' => 'de Entre']));

        $this->assertContains($propio->id, $de_entre['ids']);
        $this->assertNotContains($ajeno_unificado->id, $de_entre['ids'], 'Un cheque de otro comercio no aparece aunque muestre lo buscado.');
        $this->assertNotContains($ajeno_texto->id, $de_entre['ids']);

        // Ni pidiéndolo por el `in` de la solapa.
        $con_solapa = $this->buscar_cheques(
            $this->filtro_de_banco(['ordenar_de' => 'ASC']),
            $this->solapa([$propio->id, $ajeno_unificado->id, $ajeno_texto->id])
        );

        $this->assertEquals([$propio->id], $con_solapa['ids']);
    }

    /**
     * El Excel ya estaba bien (ChequesFilteredExport): la columna Banco trae el nombre del banco
     * del catálogo y, sin catálogo, el texto, con las filas en el orden de los ids pedidos (el de
     * la tabla). Se fija acá para que no se desalinee de la tabla y del filtro.
     *
     * @test
     */
    public function el_excel_trae_en_la_columna_banco_lo_que_se_ve()
    {
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $er = $this->cheque_unificado('Banco Entre Rios', $entre_rios);
        $galicia = $this->cheque_con_texto('Banco Galicia');
        $sin_texto = $this->cheque_unificado(null, $provincia);

        $ids = [$galicia->id, $er->id, $sin_texto->id];

        Excel::fake();

        // La ruta es de routes/web.php: después de los pedidos a la API el guard puede quedar en
        // sanctum, así que se vuelve a autenticar en web antes de bajar el Excel.
        $this->actingAs($this->dueno, 'web');

        $response = $this->get('cheque/excel/export?cheque_ids=' . implode('-', $ids));

        $response->assertStatus(200);

        $export = null;

        Excel::assertDownloaded('cheques_' . Carbon::now()->format('d-m-y') . '.xlsx', function ($descargado) use (&$export) {
            $export = $descargado;
            return true;
        });

        $encabezados = $export->headings();
        $columna_id = array_search('ID', $encabezados, true);
        $columna_banco = array_search('Banco', $encabezados, true);

        $this->assertNotFalse($columna_banco, 'El Excel tiene que tener la columna Banco.');

        $filas = [];

        foreach ($export->collection() as $fila) {
            $fila = is_array($fila) ? $fila : (array) $fila;

            // La última fila es la del total, sin ID.
            if ($fila[$columna_id] === 'Total' || $fila[$columna_id] === '') {
                continue;
            }

            $filas[] = [(int) $fila[$columna_id], $fila[$columna_banco]];
        }

        $this->assertEquals([
            [$galicia->id, 'Banco Galicia'],
            [$er->id, 'Banco de Entre Ríos'],
            [$sin_texto->id, 'Banco Provincia de Buenos Aires'],
        ], $filas);
    }

    /**
     * El mecanismo no se derrama a las otras columnas de texto de cheques: `numero` sigue
     * filtrando y ordenando por su columna, y se combina (AND) con la lupa de Banco.
     *
     * @test
     */
    public function las_otras_columnas_de_texto_siguen_igual()
    {
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $marca = 'NUMB' . substr(uniqid(), -6);

        $c1 = $this->cheque_unificado('Banco BSAS', $provincia, ['numero' => $marca . '-1']);
        $c2 = $this->cheque_con_texto('Banco Galicia', ['numero' => $marca . '-2']);
        $otro = $this->cheque_con_texto('Banco Galicia', ['numero' => 'OTRO-' . uniqid()]);

        $por_numero = $this->buscar_cheques([['key' => 'numero', 'type' => 'text', 'que_contenga' => $marca]]);

        $this->assertContains($c1->id, $por_numero['ids']);
        $this->assertContains($c2->id, $por_numero['ids']);
        $this->assertNotContains($otro->id, $por_numero['ids']);

        $igual = $this->buscar_cheques([['key' => 'numero', 'type' => 'text', 'igual_que' => $marca . '-2']]);

        $this->assertContains($c2->id, $igual['ids']);
        $this->assertNotContains($c1->id, $igual['ids']);

        $orden = $this->buscar_cheques(
            [['key' => 'numero', 'type' => 'text', 'ordenar_de' => 'DESC']],
            $this->solapa([$c1->id, $c2->id])
        );

        $this->assertEquals([$c2->id, $c1->id], $orden['ids'], 'Por número DESC: -2, -1.');

        // Las dos lupas a la vez: AND (con un banco sin catálogo, que se ve igual que antes).
        $las_dos = $this->buscar_cheques([
            ['key' => 'numero', 'type' => 'text', 'que_contenga' => $marca],
            ['key' => 'banco', 'type' => 'text', 'que_contenga' => 'Galicia'],
        ]);

        $this->assertEquals([$c2->id], $las_dos['ids']);
    }

    /**
     * El helper anota los MISMOS `used_filters` que la rama de texto de siempre (el historial de
     * filtrados y las masivas los leen), con la misma precedencia: en blanco / no en blanco ganan
     * sobre igual / que contenga, e igual gana sobre que contenga. Y un filtro de la key `banco`
     * con un tipo que no es texto sigue por el camino de siempre (la columna guardada).
     *
     * @test
     */
    public function el_helper_anota_los_mismos_used_filters_y_respeta_la_precedencia()
    {
        $usados = function (array $filtros) {
            $resultado = ColumnFiltersHelper::apply(Cheque::where('user_id', $this->dueno->id), $filtros, 'cheque', Cheque::class);

            return $resultado['used_filters'];
        };

        $this->assertEquals([
            ['key' => 'banco', 'operator' => 'order_by', 'value' => 'ASC', 'type' => 'text'],
            ['key' => 'banco', 'operator' => 'que_contenga', 'value' => 'Provincia', 'type' => 'text'],
        ], $usados($this->filtro_de_banco(['ordenar_de' => 'ASC', 'que_contenga' => 'Provincia'])));

        $this->assertEquals([
            ['key' => 'banco', 'operator' => 'en_blanco', 'value' => true, 'type' => 'text'],
        ], $usados($this->filtro_de_banco(['en_blanco' => true, 'que_contenga' => 'Provincia', 'igual_que' => 'x'])));

        $this->assertEquals([
            ['key' => 'banco', 'operator' => 'no_en_blanco', 'value' => true, 'type' => 'text'],
        ], $usados($this->filtro_de_banco(['no_en_blanco' => true, 'que_contenga' => 'Provincia'])));

        $this->assertEquals([
            ['key' => 'banco', 'operator' => 'igual_que', 'value' => 'Banco Galicia', 'type' => 'textarea'],
        ], $usados($this->filtro_de_banco(['igual_que' => 'Banco Galicia', 'que_contenga' => 'Provincia'], 'textarea')));

        // Los vacíos que manda la SPA en los filtros que nadie tocó no anotan nada.
        $this->assertEquals([], $usados($this->filtro_de_banco([
            'en_blanco'    => 0,
            'no_en_blanco' => 0,
            'igual_que'    => '',
            'que_contenga' => '',
            'ordenar_de'   => '',
        ])));

        // Un tipo que no es texto sobre la key `banco` va por el camino de siempre: la columna
        // guardada. (La SPA no lo manda; se fija para que el mecanismo no se derrame a otros tipos.)
        $entre_rios = $this->banco_del_catalogo('Banco de Entre Ríos');
        $provincia = $this->banco_del_catalogo('Banco Provincia de Buenos Aires');

        $er = $this->cheque_unificado('Banco Entre Rios', $entre_rios);
        $bsas = $this->cheque_unificado('Banco BSAS', $provincia);

        $como_select = $this->buscar_cheques($this->filtro_de_banco(['igual_que' => 'Banco BSAS'], 'select'));

        $this->assertContains($bsas->id, $como_select['ids']);
        $this->assertNotContains($er->id, $como_select['ids']);
    }
}
