<?php

namespace Tests\Feature\Cheques;

use App\Exports\ChequesFilteredExport;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\Helpers\ChequeHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Models\Cheque;
use App\Models\EtiquetaMedida;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Misión cheque-motivo-rechazo (9/10/2026) — `PUT cheque/rechazar` guarda el motivo del rechazo.
 *
 * Hasta esta misión el motivo se perdía SIEMPRE: RechazarCheque.vue lo manda como `notas`, la API
 * leía `rechazado_observaciones` (que nunca llegaba) y la columna era un INT. Ahora la columna es
 * TEXT y la API acepta las dos claves: `rechazado_observaciones` (la canónica) y `notas` (la que
 * manda el SPA, viejo y nuevo). Si vienen las dos con texto, gana `rechazado_observaciones`.
 *
 * Los pedidos copian lo que manda la SPA: `{cheque_id, notas}` (RechazarCheque.vue). El cheque
 * recibido nace de un cobro real (`POST current-acount/pago`); lo AJENO se crea a mano con el
 * `user_id` de otro dueño, que es la combinación real en una base compartida.
 *
 * @group cheques
 */
class Motivo_del_rechazo_Test extends ChequesTestCase
{
    /** El 422 de un motivo que no es un texto (array, booleano). */
    const MENSAJE_NO_ES_TEXTO = 'El motivo del rechazo tiene que ser un texto.';

    /** El 422 de un motivo de más de 1000 caracteres. */
    const MENSAJE_DEMASIADO_LARGO = 'El motivo del rechazo no puede superar los 1000 caracteres.';

    /** El 422 de un cheque ajeno o inexistente (ChequeController::MENSAJE_CHEQUE_AJENO). */
    const MENSAJE_CHEQUE_AJENO = 'El cheque elegido no existe o no es de tu cuenta.';

    /** El 422 de un motivo que no es UTF-8 válido. */
    const MENSAJE_CARACTERES_INVALIDOS = 'El motivo del rechazo tiene caracteres inválidos.';

    /** La propiedad estática donde ChequeHelper cachea que la columna es de texto. */
    const CACHE_DE_LA_COLUMNA = 'columna_de_motivo_acepta_texto';

    /** @var User|null El otro comercio: un dueño (sin owner_id) que vive en la misma base. */
    protected $otro_dueno = null;

    /** @var array<int, int> Usuarios creados a mano por este test. */
    protected $usuarios_creados = [];

    protected function tearDown(): void
    {
        // El guard de sanctum cachea el usuario que resolvió: se olvida para no arrastrar nada.
        Auth::forgetGuards();

        if (count($this->usuarios_creados)) {
            // El alta de un dueño siembra sus medidas de etiqueta (UserEtiquetaMedidaObserver).
            EtiquetaMedida::whereIn('user_id', $this->usuarios_creados)->delete();
            User::whereIn('id', $this->usuarios_creados)->delete();
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Los casos (numerados como en el plan, §5 de empresa-api)
    // ---------------------------------------------------------------------------------------------

    /**
     * 1. Lo que manda el SPA (viejo y nuevo): el motivo por `notas`. Queda en
     * `rechazado_observaciones`, exacto, y la respuesta lo devuelve.
     *
     * @test
     */
    public function el_motivo_que_manda_la_spa_por_notas_queda_guardado()
    {
        $recibido = $this->recibido_en_cartera('notas');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 'Sin fondos']);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Sin fondos', $response->json('model.rechazado_observaciones'), 'La respuesta trae el motivo.');

        $fila = $this->fila($recibido->id);

        $this->assertSame('rechazado', $fila['estado_manual']);
        $this->assertNotNull($fila['rechazado_en']);
        $this->assertSame($this->dueno->id, (int) $fila['rechazado_por_id']);
        $this->assertSame('Sin fondos', $fila['rechazado_observaciones']);
    }

    /**
     * 2. La clave canónica, `rechazado_observaciones` (la del nombre de la columna).
     *
     * @test
     */
    public function el_motivo_por_rechazado_observaciones_queda_guardado()
    {
        $recibido = $this->recibido_en_cartera('canónica');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'rechazado_observaciones' => 'Firma no coincide']);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Firma no coincide', $this->fila($recibido->id)['rechazado_observaciones']);
        $this->assertSame('rechazado', $this->fila($recibido->id)['estado_manual']);
    }

    /**
     * 3. Las dos claves con texto: gana `rechazado_observaciones`. Y si la canónica viene vacía (o
     * con espacios), se usa `notas`.
     *
     * @test
     */
    public function con_las_dos_claves_gana_rechazado_observaciones()
    {
        $recibido = $this->recibido_en_cartera('las dos');

        $response = $this->putJson('api/cheque/rechazar', [
            'cheque_id'               => $recibido->id,
            'rechazado_observaciones' => 'Cuenta cerrada',
            'notas'                   => 'Sin fondos',
        ]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Cuenta cerrada', $this->fila($recibido->id)['rechazado_observaciones']);

        $otro = $this->recibido_en_cartera('canónica vacía');

        $response = $this->putJson('api/cheque/rechazar', [
            'cheque_id'               => $otro->id,
            'rechazado_observaciones' => '   ',
            'notas'                   => 'Sin fondos',
        ]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Sin fondos', $this->fila($otro->id)['rechazado_observaciones'], 'Una canónica sin texto no tapa a `notas`.');
    }

    /**
     * 4. Espacios alrededor: se recortan. Solo espacios, vacío, null o sin la clave: el cheque se
     * rechaza igual y el motivo queda NULL (no "0", que era lo que dejaba el modal viejo en el campo).
     *
     * @test
     */
    public function los_espacios_se_recortan_y_sin_motivo_queda_null_y_rechazado()
    {
        $recibido = $this->recibido_en_cartera('espacios');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => "   Sin fondos  \t "]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Sin fondos', $this->fila($recibido->id)['rechazado_observaciones']);

        $cuerpos = [
            'solo espacios' => ['notas' => '     '],
            'vacío'         => ['notas' => ''],
            'null'          => ['notas' => null],
            'sin la clave'  => [],
            'las dos vacías' => ['notas' => '', 'rechazado_observaciones' => ''],
        ];

        foreach ($cuerpos as $nombre => $cuerpo) {

            $cheque = $this->recibido_en_cartera($nombre);

            $response = $this->putJson('api/cheque/rechazar', array_merge(['cheque_id' => $cheque->id], $cuerpo));

            $this->assertSame(200, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));

            $fila = $this->fila($cheque->id);

            $this->assertSame('rechazado', $fila['estado_manual'], $nombre . ': el cheque se rechaza igual.');
            $this->assertNull($fila['rechazado_observaciones'], $nombre . ': sin motivo es NULL.');
        }
    }

    /**
     * 5. Acentos, eñes, emojis y saltos de línea en el medio: se guardan idénticos (utf8mb4, TEXT).
     *
     * @test
     */
    public function acentos_enes_emojis_y_saltos_de_linea_se_guardan_identicos()
    {
        $recibido = $this->recibido_en_cartera('utf8');

        $motivo = "Sin fondos — llamó el Banco Nación 🏦.\nEl señor Muñoz pasa el miércoles 💸\r\nÑandú, acción, pingüino.";

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => $motivo]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame($motivo, $this->fila($recibido->id)['rechazado_observaciones']);
        $this->assertSame($motivo, $response->json('model.rechazado_observaciones'));
    }

    /**
     * 6. El tope: 1000 caracteres multibyte entran (200); 1001 es un 422 y NO se escribe nada (la
     * fila queda idéntica: ni el motivo ni la marca de rechazado).
     *
     * @test
     */
    public function mil_caracteres_entran_y_mil_uno_es_422_sin_escribir_nada()
    {
        $recibido = $this->recibido_en_cartera('mil');

        $mil = str_repeat('ñ', 997) . "😀🏦💸";

        $this->assertSame(1000, mb_strlen($mil), 'Armado del caso: son 1000 caracteres.');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => $mil]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame($mil, $this->fila($recibido->id)['rechazado_observaciones']);

        foreach (['notas', 'rechazado_observaciones'] as $clave) {

            $otro = $this->recibido_en_cartera('mil uno ' . $clave);
            $antes = $this->fila($otro->id);

            $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $otro->id, $clave => $mil . 'x']);

            $this->assertSame(422, $response->getStatusCode(), $clave . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_DEMASIADO_LARGO, $response->json('message'), $clave);
            $this->assertSame($antes, $this->fila($otro->id), $clave . ': no se escribe nada, ni la marca de rechazado.');
        }
    }

    /**
     * 7. Un array o un booleano no son un motivo, en ninguna de las dos claves: 422 y nada escrito.
     * Y una `rechazado_observaciones` inválida es 422 aunque `notas` traiga un texto válido: un
     * pedido malo no se esconde detrás de uno bueno.
     *
     * @test
     */
    public function un_array_o_un_booleano_son_422_sin_escribir_nada()
    {
        $cuerpos = [
            'notas array'                          => ['notas' => ['Sin fondos']],
            'notas true'                           => ['notas' => true],
            'notas false'                          => ['notas' => false],
            'rechazado_observaciones array'        => ['rechazado_observaciones' => ['a' => 'Sin fondos']],
            'rechazado_observaciones true'         => ['rechazado_observaciones' => true],
            'rechazado_observaciones false'        => ['rechazado_observaciones' => false],
            'canónica inválida con notas válidas'  => ['rechazado_observaciones' => true, 'notas' => 'Sin fondos'],
        ];

        foreach ($cuerpos as $nombre => $cuerpo) {

            $cheque = $this->recibido_en_cartera($nombre);
            $antes = $this->fila($cheque->id);

            $response = $this->putJson('api/cheque/rechazar', array_merge(['cheque_id' => $cheque->id], $cuerpo));

            $this->assertSame(422, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_NO_ES_TEXTO, $response->json('message'), $nombre);
            $this->assertSame($antes, $this->fila($cheque->id), $nombre . ': no se escribe nada, ni la marca de rechazado.');
        }
    }

    /**
     * 8. Un número es un motivo válido y se guarda como texto.
     *
     * @test
     */
    public function un_numero_se_guarda_como_texto()
    {
        $recibido = $this->recibido_en_cartera('número');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 7]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('7', $this->fila($recibido->id)['rechazado_observaciones']);
    }

    /**
     * 9. `GET cheque` devuelve el motivo en el cheque de la solapa Recibido → Rechazados, que es de
     * donde lo lee la columna "Motivo del rechazo" de la SPA.
     *
     * @test
     */
    public function el_listado_devuelve_el_motivo_en_los_rechazados()
    {
        $recibido = $this->recibido_en_cartera('listado');

        $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 'Sin fondos suficientes'])->assertStatus(200);

        $response = $this->getJson('api/cheque');

        $response->assertStatus(200);

        $del_listado = null;

        foreach ($response->json('models.recibido.rechazados') as $cheque) {
            if ((int) $cheque['id'] === (int) $recibido->id) {
                $del_listado = $cheque;
            }
        }

        $this->assertNotNull($del_listado, 'El cheque rechazado tiene que estar en Recibido → Rechazados.');
        $this->assertSame('Sin fondos suficientes', $del_listado['rechazado_observaciones']);
    }

    /**
     * 10. El Excel de cheques trae el motivo en la columna "Observaciones rechazo".
     *
     * @test
     */
    public function el_excel_trae_el_motivo_en_observaciones_rechazo()
    {
        $recibido = $this->recibido_en_cartera('excel');

        $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 'Cheque adulterado'])->assertStatus(200);

        $export = new ChequesFilteredExport(Cheque::where('id', $recibido->id)->withAll()->get());

        $columna = array_search('Observaciones rechazo', $export->headings(), true);

        $this->assertNotFalse($columna, 'El Excel tiene la columna "Observaciones rechazo".');

        $filas = $export->collection();

        $this->assertSame((int) $recibido->id, (int) $filas[0][0], 'La primera fila es la del cheque.');
        $this->assertSame('Cheque adulterado', $filas[0][$columna]);
    }

    /**
     * 11. La columna es de texto en la base (la migración 2026_10_09_180000 corrió).
     *
     * @test
     */
    public function la_columna_del_motivo_es_de_texto()
    {
        $columna = DB::selectOne(
            "SELECT DATA_TYPE AS tipo, IS_NULLABLE AS nulable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cheques' AND COLUMN_NAME = 'rechazado_observaciones'"
        );

        $this->assertNotNull($columna, 'La columna cheques.rechazado_observaciones existe.');
        $this->assertSame('text', strtolower($columna->tipo));
        $this->assertSame('YES', strtoupper($columna->nulable));
    }

    /**
     * 12. Un cheque de otro comercio, con motivo: 422 con el mensaje de siempre y NADA escrito, ni
     * en el ajeno ni en ningún otro cheque.
     *
     * @test
     */
    public function un_cheque_ajeno_con_motivo_es_422_y_no_escribe_nada()
    {
        $ajeno = $this->cheque_a_mano([
            'user_id' => $this->otro_dueno()->id,
            'numero'  => 'AJENO-' . substr(uniqid(), -6),
        ]);

        $foto = $this->foto_de_cheques();

        foreach (['notas', 'rechazado_observaciones'] as $clave) {

            $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $ajeno->id, $clave => 'Sin fondos']);

            $this->assertSame(422, $response->getStatusCode(), $clave . ': ' . $this->resumen($response));
            $this->assertSame(self::MENSAJE_CHEQUE_AJENO, $response->json('message'), $clave);
        }

        $this->assertSame($foto, $this->foto_de_cheques(), 'Ningún cheque de la base cambió.');
        $this->assertNull($ajeno->fresh()->estado_manual);
        $this->assertNull($ajeno->fresh()->rechazado_observaciones);
    }

    /**
     * 13. La columna todavía INT (la ventana del deploy entre subir la API y migrar), sin tocar el
     * esquema: se fuerza el cache de ChequeHelper a false. El rechazo con motivo es un 200, el cheque
     * queda rechazado SIN motivo (no un 500 por escribir texto en un INT) y queda el warning en el log
     * con el id del cheque.
     *
     * @test
     */
    public function con_la_columna_todavia_int_rechaza_sin_motivo_y_avisa_en_el_log()
    {
        $recibido = $this->recibido_en_cartera('columna int');

        $cache = $this->cache_de_la_columna();

        try {

            $cache->setValue(null, false);

            Log::spy();

            $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 'Sin fondos']);

            $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

            $fila = $this->fila($recibido->id);

            $this->assertSame('rechazado', $fila['estado_manual'], 'El cheque se rechaza igual.');
            $this->assertNull($fila['rechazado_observaciones'], 'Con la columna INT el motivo no se escribe.');

            Log::shouldHaveReceived('warning')
                ->withArgs(function ($mensaje, $contexto = []) use ($recibido) {
                    return strpos((string) $mensaje, 'SIN su motivo') !== false
                        && isset($contexto['cheque_id'])
                        && (int) $contexto['cheque_id'] === (int) $recibido->id;
                })
                ->once();

        } finally {

            $cache->setValue(null, null);
        }
    }

    /**
     * 14. Solo el true queda cacheado. Un false REAL, sin DDL: con otro prefijo de tablas la consulta
     * a information_schema no encuentra la columna y da false, y ese false NO se guarda. Al volver
     * al prefijo de siempre, la próxima pregunta va a la base (la columna es TEXT), da true y el true
     * sí queda guardado. Es lo que le permite a un `queue:work` que arrancó antes de la migración
     * enterarse solo.
     *
     * @test
     */
    public function solo_el_true_de_la_columna_queda_cacheado()
    {
        $cache = $this->cache_de_la_columna();

        $prefijo = DB::connection()->getTablePrefix();

        try {

            $cache->setValue(null, null);

            DB::connection()->setTablePrefix('tabla_que_no_existe_');

            $this->assertFalse(ChequeHelper::columna_de_motivo_acepta_texto(), 'Sin la columna, false.');
            $this->assertNull($cache->getValue(), 'El false no queda guardado.');

            DB::connection()->setTablePrefix($prefijo);

            $this->assertTrue(ChequeHelper::columna_de_motivo_acepta_texto(), 'Se vuelve a preguntar y la columna es de texto.');
            $this->assertTrue($cache->getValue(), 'El true sí queda guardado.');

        } finally {

            DB::connection()->setTablePrefix($prefijo);

            $cache->setValue(null, null);
        }
    }

    /**
     * 15. Por formulario (form-urlencoded, no JSON): un texto se guarda, y un "0" o un vacío son
     * "sin motivo" (NULL, con el cheque rechazado igual). Y un byte que no es UTF-8 válido —por
     * formulario puede llegar— es un 422 sin escribir nada, en vez del 500 de la base.
     *
     * @test
     */
    public function por_formulario_el_texto_se_guarda_y_el_cero_o_el_vacio_son_null()
    {
        $casos = [
            'texto' => ['Sin fondos', 'Sin fondos'],
            'cero'  => ['0', null],
            'vacío' => ['', null],
        ];

        foreach ($casos as $nombre => $caso) {

            $cheque = $this->recibido_en_cartera('formulario ' . $nombre);

            $response = $this->put('api/cheque/rechazar', ['cheque_id' => (string) $cheque->id, 'notas' => $caso[0]], ['Accept' => 'application/json']);

            $this->assertSame(200, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));

            $fila = $this->fila($cheque->id);

            $this->assertSame('rechazado', $fila['estado_manual'], $nombre . ': el cheque se rechaza.');
            $this->assertSame($caso[1], $fila['rechazado_observaciones'], $nombre);
        }

        $cheque = $this->recibido_en_cartera('formulario utf8 inválido');
        $antes = $this->fila($cheque->id);

        $response = $this->put('api/cheque/rechazar', ['cheque_id' => (string) $cheque->id, 'notas' => "Sin fondos \xC3\x28"], ['Accept' => 'application/json']);

        $this->assertSame(422, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame(self::MENSAJE_CARACTERES_INVALIDOS, $response->json('message'));
        $this->assertSame($antes, $this->fila($cheque->id), 'No se escribe nada, ni la marca de rechazado.');
    }

    /**
     * 16. El `notas: 0` ENTERO que mandaba el modal viejo en el segundo rechazo de la misma pestaña
     * (después de rechazar dejaba `this.notas = 0`): no es un motivo, queda NULL y el cheque se
     * rechaza. Y un 0 en la canónica no tapa un texto en `notas`.
     *
     * @test
     */
    public function el_cero_entero_que_dejaba_el_modal_viejo_no_es_un_motivo()
    {
        $recibido = $this->recibido_en_cartera('cero entero');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $recibido->id, 'notas' => 0]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));

        $fila = $this->fila($recibido->id);

        $this->assertSame('rechazado', $fila['estado_manual']);
        $this->assertNull($fila['rechazado_observaciones'], 'Un 0 no es un motivo.');

        $otro = $this->recibido_en_cartera('cero en la canónica');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $otro->id, 'rechazado_observaciones' => 0, 'notas' => 'Sin fondos']);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Sin fondos', $this->fila($otro->id)['rechazado_observaciones']);
    }

    /**
     * 17. Los espacios Unicode (el NBSP de un copiar y pegar, los de ancho fijo, el ideográfico) se
     * recortan como los comunes: solo eso es "sin motivo" (NULL), y alrededor de un texto se sacan.
     *
     * @test
     */
    public function los_espacios_unicode_se_recortan_y_solos_son_null()
    {
        $solos = [
            'NBSP'             => "\u{00A0}\u{00A0}",
            'varios Unicode'   => "\u{2003}\u{3000}\u{00A0} \t\u{2007}",
        ];

        foreach ($solos as $nombre => $notas) {

            $cheque = $this->recibido_en_cartera('unicode ' . $nombre);

            $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $cheque->id, 'notas' => $notas]);

            $this->assertSame(200, $response->getStatusCode(), $nombre . ': ' . $this->resumen($response));

            $fila = $this->fila($cheque->id);

            $this->assertSame('rechazado', $fila['estado_manual'], $nombre);
            $this->assertNull($fila['rechazado_observaciones'], $nombre . ': solo espacios es NULL.');
        }

        $cheque = $this->recibido_en_cartera('unicode alrededor');

        $response = $this->putJson('api/cheque/rechazar', ['cheque_id' => $cheque->id, 'notas' => "\u{00A0}\u{3000}Sin fondos\u{2007}\u{00A0}"]);

        $this->assertSame(200, $response->getStatusCode(), $this->resumen($response));
        $this->assertSame('Sin fondos', $this->fila($cheque->id)['rechazado_observaciones']);
    }

    /**
     * 18. El catálogo de acciones de pantalla del asistente ve las claves del motivo: las saca con una
     * regex sobre el cuerpo de ChequeController::rechazar() (claves_que_lee()), así que tienen que
     * leerse ahí y no adentro del helper. En develop daba `cheque_id` y `rechazado_observaciones`.
     *
     * @test
     */
    public function el_asistente_ve_las_claves_del_motivo_en_el_catalogo()
    {
        $claves = CatalogoDeEscrituraIaHelper::claves_que_lee(ChequeController::class, 'rechazar');

        foreach (['cheque_id', 'rechazado_observaciones', 'notas'] as $clave) {

            $this->assertContains($clave, $claves, 'claves_que_lee(ChequeController@rechazar) = ' . json_encode($claves));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudantes
    // ---------------------------------------------------------------------------------------------

    /**
     * La propiedad estática donde ChequeHelper cachea que la columna del motivo es de texto.
     *
     * @return \ReflectionProperty
     */
    protected function cache_de_la_columna()
    {
        $cache = new \ReflectionProperty(ChequeHelper::class, self::CACHE_DE_LA_COLUMNA);

        $cache->setAccessible(true);

        return $cache;
    }

    /**
     * Un cheque recibido en cartera, nacido de un cobro real a un cliente nuevo.
     *
     * @param string $nombre Para el nombre del cliente (ayuda a leer una falla).
     * @return Cheque
     */
    protected function recibido_en_cartera($nombre)
    {
        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente motivo ' . $nombre . ' ' . uniqid());

        return $this->cobrar_con_cheque($cliente, $cuenta);
    }

    /**
     * La fila cruda de un cheque (sin modelo ni casts), o null.
     *
     * @param int $id
     * @return array|null
     */
    protected function fila($id)
    {
        $fila = DB::table('cheques')->where('id', $id)->first();

        return is_null($fila) ? null : (array) $fila;
    }

    /**
     * Todas las filas de `cheques`, para comparar antes y después.
     *
     * @return array
     */
    protected function foto_de_cheques()
    {
        return DB::table('cheques')->orderBy('id')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();
    }

    /**
     * El otro comercio: un dueño sin owner_id, creado una vez por test.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {

            $this->otro_dueno = User::create([
                'name'     => 'Otro comercio motivo del rechazo',
                'email'    => 'cheques-motivo-otro-' . uniqid() . '@test.local',
                'password' => Hash::make('secret'),
                'owner_id' => null,
            ]);

            $this->usuarios_creados[] = $this->otro_dueno->id;
        }

        return $this->otro_dueno;
    }

    /**
     * Estado y comienzo del cuerpo de una respuesta, para los mensajes de falla.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return string
     */
    protected function resumen($response)
    {
        return $response->getStatusCode() . ' ' . mb_substr((string) $response->getContent(), 0, 300);
    }
}
