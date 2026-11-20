<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión arca-facturas-fallidas (20/11/2026) — una factura que falló por la vinculación incompleta con
 * ARCA quedaba trabada: no se podía volver a consultar ni eliminar.
 *
 * ─── Los defectos ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. `AfipWsfeHelper::consultar_comprobante()` leía los errores de ARCA de `$result` (el envoltorio de
 *    `WS::__call()`) en vez de `$afip_result` (la respuesta): rama muerta, la consulta no informaba nada.
 * 2. Un 600 (`ValidacionDeToken`) al emitir dejaba el intento numerado, sin CAE y sin `R`: «incierto».
 * 3. Con más de un `Err`, `checkErrors()` rompía con «Undefined index: Code» y el intento quedaba incierto.
 *
 * ─── La decisión de Lucas (20/11/2026 14:26) ──────────────────────────────────────────────────
 *
 * 🔴 Enfoque conservador: un 602 de ARCA («no existen datos») NUNCA convierte el intento en `R`: no hay
 * evidencia de que ARCA no pueda estar todavía procesando el envío, y marcarlo rechazado dejaría borrar o
 * reemitir una factura que después puede autorizarse (dos facturas para la misma venta). Ni el tiempo
 * transcurrido, ni la consulta manual, ni el ambiente cambian eso. El único rechazo firme por error de
 * negocio es el 600 de la EMISIÓN (autenticación rechazada antes de procesar), sin CAE y con prueba de que
 * es ese intento.
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * Con el helper real y un doble de SOAP que sólo reemplaza `$helper->wsfe` (mismo patrón que
 * `11_Reintento_Exitoso_Saca_La_Venta_De_Alertas_Test`): sin constructor (abriría el webservice), sin
 * certificado y sin red. Cada ticket nace con los mismos campos que `MakeAfipTicket`.
 *
 * @group facturacion
 * @group afip
 */
class Errores_De_Arca_Resuelven_O_Dejan_Pendiente_El_Intento_Test extends EmpresaTestCase
{
    /** Código de ARCA de la Factura B. */
    const FACTURA_B = 6;

    /** Código de ARCA de la Nota de Crédito B. */
    const NOTA_DE_CREDITO_B = 8;

    const MENSAJE_600 = 'ValidacionDeToken: No apareció CUIT en lista de relaciones: 20111111112';

    const MENSAJE_602 = 'No existen datos en nuestros registros para los parámetros ingresados';

    const CAE = '71279049124261';

    const REQUEST_DE_EMISION = '<emision-solicitud/>';

    const RESPONSE_DE_EMISION = '<emision-respuesta/>';

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Precio de la próxima venta: `SaleController::store()` descarta una venta idéntica de los últimos
     * 5 segundos (candado antiduplicados).
     *
     * @var float
     */
    protected $proximo_precio = 12100.00;

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    // =========================================================================================
    // CA1 — la consulta lee los errores de ARCA
    // =========================================================================================

    /**
     * CA1 — `FECompConsultar` contesta 600: la bandera queda en `true`, el usuario ve UN error con el
     * detalle de ARCA y la aclaración de que el intento sigue pendiente, y el ticket no cambia.
     *
     * Contra el código anterior falla: leía `$result['FECompConsultarResult']` (clave que no existe), así
     * que la bandera quedaba en `false` y no se guardaba ningún error.
     *
     * @test
     */
    public function test_consulta_con_error_600_informa_y_deja_el_intento_pendiente()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $helper = $this->consultar($ticket, [['Code' => 600, 'Msg' => self::MENSAJE_600]]);

        $this->assertTrue($helper->error_al_consultar_comprobante, 'La consulta con errores tiene que dejar la bandera en true.');

        $errores = AfipError::where('afip_ticket_id', $ticket->id)->get();
        $this->assertCount(1, $errores, 'El usuario tiene que ver exactamente un error de la consulta.');
        $this->assertStringContainsString('600', (string) $errores[0]->code);
        $this->assertStringContainsString(self::MENSAJE_600, $errores[0]->message, 'El mensaje tiene que traer el detalle de ARCA.');
        $this->assertStringContainsString('pendiente', $errores[0]->message, 'El mensaje tiene que decir que el intento sigue pendiente.');

        $this->assertIntentoIntacto($ticket);
    }

    // =========================================================================================
    // CA2 — el 602 informa y NO rechaza
    // =========================================================================================

    /**
     * CA2 — 602 en una consulta manual (sin error de red previo): el intento NO se rechaza, el
     * `request/response` de la emisión queda intacto y el usuario ve UN mensaje claro con el XML de la
     * consulta como evidencia propia. Contra el parche original falla: marcaba `R` y pisaba el XML.
     *
     * @test
     */
    public function test_602_informa_sin_rechazar_y_conserva_la_evidencia_de_la_emision()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $helper = $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $this->assertTrue($helper->error_al_consultar_comprobante);
        $this->assertIntentoIntacto($ticket);

        $errores = AfipError::where('afip_ticket_id', $ticket->id)->get();
        $this->assertCount(1, $errores);
        $this->assertSame('602', (string) $errores[0]->code);
        $this->assertStringContainsString(self::MENSAJE_602, $errores[0]->message);
        $this->assertStringContainsString('todavía no informa', $errores[0]->message);
        $this->assertStringContainsString('no confirma', $errores[0]->message, 'Tiene que aclarar que un 602 no prueba un rechazo.');
        $this->assertStringContainsString('No lo elimines', $errores[0]->message);
        $this->assertSame('<consulta-solicitud/>', $errores[0]->request, 'El XML de la consulta es evidencia del AfipError, no del ticket.');
        $this->assertSame('<consulta-respuesta/>', $errores[0]->response);
    }

    /**
     * CA2 — Consultar varias veces no apila mensajes: queda UN `AfipError` 602.
     *
     * @test
     */
    public function test_602_repetido_no_duplica_el_error()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);
        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);
        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $this->assertSame(1, AfipError::where('afip_ticket_id', $ticket->id)->where('code', '602')->count());
        $this->assertIntentoIntacto($ticket);
    }

    /**
     * CA2 — Con `Err` como lista de dos 602, el intento tampoco se rechaza y se conservan los dos
     * mensajes (ARCA devuelve un `stdClass` con un error y una lista con varios).
     *
     * @test
     */
    public function test_varios_err_602_informan_sin_rechazar()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $this->consultar($ticket, [
            ['Code' => 602, 'Msg' => self::MENSAJE_602.' (primero)'],
            ['Code' => 602, 'Msg' => self::MENSAJE_602.' (segundo)'],
        ]);

        $this->assertIntentoIntacto($ticket);

        $mensajes = AfipError::where('afip_ticket_id', $ticket->id)->where('code', '602')->pluck('message')->implode(' | ');
        $this->assertStringContainsString('(primero)', $mensajes);
        $this->assertStringContainsString('(segundo)', $mensajes);
    }

    // =========================================================================================
    // CA3 — el 602 no resuelve en ningún caso de mera ausencia
    // =========================================================================================

    /**
     * CA3 (a) — La consulta automática que `solicitar_cae()` dispara tras un error de red contesta 602:
     * el envío puede seguir en curso en ARCA, el intento queda pendiente y protegido contra el borrado.
     *
     * @test
     */
    public function test_602_tras_error_de_red_no_resuelve()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]], true);

        $this->assertIntentoIntacto($ticket);
        $this->assertSame(1, AfipError::where('afip_ticket_id', $ticket->id)->where('code', '602')->count());
        $this->assertDeleteBloqueado($ticket);
    }

    /**
     * CA3 (b) — El paso del tiempo no cambia nada: ni a los 2 minutos, ni a los 11, ni a los 31. No hay
     * evidencia de que 10 minutos sean el plazo máximo de procesamiento de ARCA.
     *
     * @dataProvider minutos_desde_el_envio
     * @test
     */
    public function test_602_no_resuelve_por_el_paso_del_tiempo($minutos)
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), $minutos);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $this->assertIntentoIntacto($ticket);
        $this->assertSame(1, AfipError::where('afip_ticket_id', $ticket->id)->where('code', '602')->count());
        $this->assertDeleteBloqueado($ticket);
    }

    /**
     * @return array
     */
    public function minutos_desde_el_envio()
    {
        return [
            'a los 2 minutos'  => [2],
            'a los 11 minutos' => [11],
            'a los 31 minutos' => [31],
        ];
    }

    /**
     * CA3 (c) — Tampoco importa en qué ambiente está hoy el emisor: producción u homologación.
     *
     * @dataProvider ambientes_del_emisor
     * @test
     */
    public function test_602_no_resuelve_en_ningun_ambiente($en_produccion)
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 31);

        AfipInformation::where('id', $ticket->afip_information_id)->update(['afip_ticket_production' => $en_produccion ? 1 : 0]);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]], false, !$en_produccion);

        $this->assertIntentoIntacto($ticket);
        $this->assertDeleteBloqueado($ticket);
    }

    /**
     * @return array
     */
    public function ambientes_del_emisor()
    {
        return [
            'producción'   => [true],
            'homologación' => [false],
        ];
    }

    /**
     * CA3 (d) — 602 y 600 juntos: el intento no se rechaza y se conservan los DOS mensajes, cada uno con
     * su código.
     *
     * @test
     */
    public function test_602_con_600_conserva_los_dos_mensajes_sin_rechazar()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 31);

        $this->consultar($ticket, [
            ['Code' => 602, 'Msg' => self::MENSAJE_602],
            ['Code' => 600, 'Msg' => self::MENSAJE_600],
        ]);

        $this->assertIntentoIntacto($ticket);

        $por_codigo = AfipError::where('afip_ticket_id', $ticket->id)->get()->keyBy(function ($error) {
            return (string) $error->code;
        });
        $this->assertEqualsCanonicalizing(['600', '602'], $por_codigo->keys()->all());
        $this->assertStringContainsString(self::MENSAJE_602, $por_codigo['602']->message);
        $this->assertStringContainsString(self::MENSAJE_600, $por_codigo['600']->message);
        $this->assertDeleteBloqueado($ticket);
    }

    // =========================================================================================
    // CA4 — el 600 al emitir es rechazo firme
    // =========================================================================================

    /**
     * CA4 — `FECAESolicitar` contesta 600 sin CAE: ARCA rechazó la autenticación del pedido antes de
     * procesarlo. El intento queda `R` (conserva el número), sin CAE, con UN error 600 que explica qué
     * pasó y qué hacer, y el `request/response` de ESA emisión guardado en el ticket.
     *
     * Contra el código anterior falla: `resultado` quedaba NULL (intento incierto, trabado).
     *
     * @test
     */
    public function test_600_al_emitir_es_rechazo_firme_con_mensaje_claro()
    {
        $ticket = $this->intento_nuevo($this->crear_venta());

        $this->emitir($ticket, ['ultimo' => 0, 'errores' => [['Code' => 600, 'Msg' => self::MENSAJE_600]]]);

        $ticket = AfipTicket::find($ticket->id);
        $this->assertSame('R', $ticket->resultado);
        $this->assertTrue(empty($ticket->cae));
        $this->assertSame('1', (string) $ticket->cbte_numero);
        $this->assertSame(self::REQUEST_DE_EMISION, $ticket->request);
        $this->assertSame(self::RESPONSE_DE_EMISION, $ticket->response);

        $errores = AfipError::where('afip_ticket_id', $ticket->id)->get();
        $this->assertCount(1, $errores);
        $this->assertSame('600', (string) $errores[0]->code);
        $this->assertStringContainsString('no se autorizó', $errores[0]->message);
        $this->assertStringContainsString('vinculación del certificado', $errores[0]->message);
        $this->assertStringContainsString('eliminar este intento', $errores[0]->message);
        $this->assertStringContainsString(self::MENSAJE_600, $errores[0]->message, 'Tiene que conservar el detalle de ARCA.');
    }

    /**
     * CA4 (guarda de identidad) — Un 600 no rechaza si no hay prueba de que corresponde a ESE intento:
     * el número que viajó en el pedido tiene que ser el del ticket.
     *
     * @test
     */
    public function test_600_sin_prueba_de_que_es_ese_intento_no_rechaza()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11);

        $helper = $this->helper_con_doble([]);
        $helper->afip_ticket = AfipTicket::find($ticket->id);
        $helper->comprobante_numero = 99;
        $helper->comprobante_tipo = self::FACTURA_B;

        $helper->checkErrors($this->respuesta_de_emision_con_errores([['Code' => 600, 'Msg' => self::MENSAJE_600]]), $this->envoltorio_de_emision());

        $this->assertIntentoIntacto($ticket, self::REQUEST_DE_EMISION, self::RESPONSE_DE_EMISION);
    }

    // =========================================================================================
    // CA5 — varios errores no rompen la emisión
    // =========================================================================================

    /**
     * CA5 — Dos `Err` con un 600 adentro: no se lanza excepción, se guardan los DOS errores (uno por
     * código) y el intento queda `R`. Contra el código anterior falla con «Undefined index: Code».
     *
     * @test
     */
    public function test_dos_errores_con_600_no_explotan_y_se_guardan_los_dos()
    {
        $ticket = $this->intento_nuevo($this->crear_venta());

        $this->emitir($ticket, ['ultimo' => 0, 'errores' => [
            ['Code' => 600, 'Msg' => self::MENSAJE_600],
            ['Code' => 10016, 'Msg' => 'El campo CbteDesde no es el próximo a autorizar'],
        ]]);

        $ticket = AfipTicket::find($ticket->id);
        $this->assertSame('R', $ticket->resultado);

        $codigos = AfipError::where('afip_ticket_id', $ticket->id)->pluck('code')->map(function ($codigo) {
            return (string) $codigo;
        })->all();
        $this->assertEqualsCanonicalizing(['600', '10016'], $codigos);
    }

    /**
     * CA5 — Dos `Err` sin 600 y la cabecera con `Resultado = 'R'`: se guardan los dos y queda `R`
     * (lo escribe `update_afip_ticket()`).
     *
     * @test
     */
    public function test_dos_errores_sin_600_quedan_r_por_la_cabecera()
    {
        $ticket = $this->intento_nuevo($this->crear_venta());

        $this->emitir($ticket, ['ultimo' => 0, 'cabecera' => 'R', 'errores' => [
            ['Code' => 10016, 'Msg' => 'El campo CbteDesde no es el próximo a autorizar'],
            ['Code' => 10048, 'Msg' => 'El campo ImpTotal no coincide con la suma de sus partes'],
        ]]);

        $ticket = AfipTicket::find($ticket->id);
        $this->assertSame('R', $ticket->resultado);
        $this->assertSame(2, AfipError::where('afip_ticket_id', $ticket->id)->count());
    }

    /**
     * CA5 (guarda) — Dos `Err` sin 600 y sin resultado de cabecera: se guardan los dos, no hay
     * excepción y el intento NO se rechaza (sin prueba firme sigue incierto).
     *
     * @test
     */
    public function test_dos_errores_sin_600_ni_cabecera_se_guardan_y_no_rechazan()
    {
        $ticket = $this->intento_nuevo($this->crear_venta());

        $this->emitir($ticket, ['ultimo' => 0, 'errores' => [
            ['Code' => 10016, 'Msg' => 'El campo CbteDesde no es el próximo a autorizar'],
            ['Code' => 10048, 'Msg' => 'El campo ImpTotal no coincide con la suma de sus partes'],
        ]]);

        $ticket = AfipTicket::find($ticket->id);
        $this->assertNull($ticket->resultado);
        $this->assertSame(2, AfipError::where('afip_ticket_id', $ticket->id)->count());
    }

    // =========================================================================================
    // CA6 — lo que nunca se toca
    // =========================================================================================

    /**
     * CA6 — Un ticket con CAE no se toca con ninguna respuesta de la consulta: ni CAE, ni resultado, ni
     * errores nuevos.
     *
     * @test
     */
    public function test_602_no_toca_un_ticket_con_cae()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 31, ['cae' => self::CAE, 'resultado' => 'A']);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $ticket = AfipTicket::find($ticket->id);
        $this->assertSame(self::CAE, $ticket->cae);
        $this->assertSame('A', $ticket->resultado);
        $this->assertSame(0, AfipError::where('afip_ticket_id', $ticket->id)->count());
    }

    /**
     * CA6 — Una nota de crédito (enlaza la venta por `sale_nota_credito_id`, tipo 8) conserva su
     * `resultado` y no recibe mensajes de consulta.
     *
     * @test
     */
    public function test_602_no_toca_una_nota_de_credito()
    {
        $venta = $this->crear_venta();
        $nota = $this->ticket_incierto($venta, 31, [
            'sale_id'              => null,
            'sale_nota_credito_id' => $venta->id,
            'cbte_tipo'            => (string) self::NOTA_DE_CREDITO_B,
            'resultado'            => null,
        ]);

        $this->consultar($nota, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $nota = AfipTicket::find($nota->id);
        $this->assertNull($nota->resultado);
        $this->assertSame(self::REQUEST_DE_EMISION, $nota->request);
        $this->assertSame(0, AfipError::where('afip_ticket_id', $nota->id)->count());
    }

    /**
     * CA6 — Un ticket que ya es `R` no acumula errores nuevos de la consulta.
     *
     * @test
     */
    public function test_602_sobre_rechazado_no_agrega_errores()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 31, ['resultado' => 'R']);

        $this->consultar($ticket, [['Code' => 602, 'Msg' => self::MENSAJE_602]]);

        $this->assertSame('R', AfipTicket::find($ticket->id)->resultado);
        $this->assertSame(0, AfipError::where('afip_ticket_id', $ticket->id)->count());
    }

    /**
     * CA6 — El 600 en `checkErrors()` no marca `R` una nota de crédito (reutiliza el ticket) ni una
     * respuesta que trae CAE.
     *
     * @test
     */
    public function test_600_no_toca_nota_de_credito_ni_respuesta_con_cae()
    {
        $ticket = $this->ticket_incierto($this->crear_venta(), 11, ['nota_credito_id' => 987654]);

        $helper = $this->helper_con_doble([]);
        $helper->afip_ticket = AfipTicket::find($ticket->id);
        $helper->comprobante_numero = 1;
        $helper->comprobante_tipo = self::FACTURA_B;
        $helper->checkErrors($this->respuesta_de_emision_con_errores([['Code' => 600, 'Msg' => self::MENSAJE_600]]), $this->envoltorio_de_emision());

        $this->assertNull(AfipTicket::find($ticket->id)->resultado, 'Una nota de crédito no se rechaza por un 600.');

        $factura = $this->ticket_incierto($this->crear_venta(), 11);
        $respuesta = $this->respuesta_de_emision_con_errores([['Code' => 600, 'Msg' => self::MENSAJE_600]]);
        $respuesta->FECAESolicitarResult->FeDetResp = (object) [
            'FECAEDetResponse' => (object) ['Resultado' => 'A', 'CAE' => self::CAE, 'CAEFchVto' => '20261231'],
        ];

        $helper = $this->helper_con_doble([]);
        $helper->afip_ticket = AfipTicket::find($factura->id);
        $helper->comprobante_numero = 1;
        $helper->comprobante_tipo = self::FACTURA_B;
        $helper->checkErrors($respuesta, $this->envoltorio_de_emision());

        $this->assertNotSame('R', AfipTicket::find($factura->id)->resultado, 'Si la respuesta trae CAE no se marca rechazada.');
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Aserta que el intento sigue exactamente como lo dejó la emisión: sin resultado, sin CAE, con su
     * número y con el `request/response` de la emisión.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  string $request Evidencia de la emisión que tiene que seguir en el ticket.
     * @param  string $response
     * @return void
     */
    protected function assertIntentoIntacto($ticket, $request = self::REQUEST_DE_EMISION, $response = self::RESPONSE_DE_EMISION)
    {
        $actual = AfipTicket::find($ticket->id);

        $this->assertNull($actual->resultado, 'El intento no puede quedar rechazado: sigue pendiente de verificación.');
        $this->assertTrue(empty($actual->cae));
        $this->assertSame('1', (string) $actual->cbte_numero);
        $this->assertSame($request, $actual->request, 'El request de la emisión es evidencia fiscal: no se pisa.');
        $this->assertSame($response, $actual->response, 'El response de la emisión es evidencia fiscal: no se pisa.');
    }

    /**
     * El borrado de un intento numerado no resuelto sigue bloqueado por el endpoint real.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertDeleteBloqueado($ticket)
    {
        $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(422);
        $this->assertNotNull(AfipTicket::find($ticket->id), 'El intento incierto no se puede borrar.');
    }

    /**
     * Corre `consultar_comprobante()` real con la respuesta de ARCA transcripta.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  array $errores Lista de ['Code' =>, 'Msg' =>] que contesta `FECompConsultar`.
     * @param  bool $despues_de_error_de_red Simula la consulta automática de `solicitar_cae()`.
     * @param  bool $homologacion Ambiente con el que se construyó el helper.
     * @return \App\Http\Controllers\Helpers\Afip\AfipWsfeHelper
     */
    protected function consultar($ticket, array $errores, $despues_de_error_de_red = false, $homologacion = false)
    {
        $helper = $this->helper_con_doble(['consulta_errores' => $errores]);
        $helper->afip_ticket = AfipTicket::find($ticket->id);
        $helper->consulto_despues_de_error_en_emision = $despues_de_error_de_red;
        $helper->testing = $homologacion;

        $helper->consultar_comprobante();

        return $helper;
    }

    /**
     * Corre la emisión real (`procesar()`) de un intento contra el doble de WSFE.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  array $guion Ver `DobleDeWsfeConErroresDeArca`.
     * @return void
     */
    protected function emitir($ticket, array $guion)
    {
        $helper = $this->helper_con_doble($guion);
        $helper->afip_ticket = AfipTicket::find($ticket->id);

        $helper->procesar();
    }

    /**
     * `AfipWsfeHelper` sin constructor (abriría el webservice y leería el ticket de acceso del disco)
     * con el doble de SOAP en su lugar.
     *
     * @param  array $guion
     * @return \App\Http\Controllers\Helpers\Afip\AfipWsfeHelper
     */
    protected function helper_con_doble(array $guion)
    {
        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->wsfe = new DobleDeWsfeConErroresDeArca($guion);
        $helper->testing = true;

        return $helper;
    }

    /**
     * Respuesta de `FECAESolicitar` con sólo `Errors`, como la contesta ARCA ante una falla de
     * autenticación: `Err` es un objeto con un error y una lista con varios.
     *
     * @param  array $errores
     * @return object
     */
    protected function respuesta_de_emision_con_errores(array $errores)
    {
        return (object) [
            'FECAESolicitarResult' => (object) [
                'Errors' => DobleDeWsfeConErroresDeArca::nodo_de_errores($errores),
            ],
        ];
    }

    /**
     * Envoltorio de `WS::__call()` de una emisión.
     *
     * @return array
     */
    protected function envoltorio_de_emision()
    {
        return [
            'hubo_un_error' => false,
            'request'       => self::REQUEST_DE_EMISION,
            'response'      => self::RESPONSE_DE_EMISION,
        ];
    }

    /**
     * Intento recién creado con los MISMOS campos que `MakeAfipTicket::make_afip_ticket()`: todavía sin
     * número, sin CAE y sin resultado.
     *
     * @param  \App\Models\Sale $venta
     * @param  array $campos Lo que el escenario quiere fijar.
     * @return \App\Models\AfipTicket
     */
    protected function intento_nuevo($venta, array $campos = [])
    {
        $afip_information = $this->punto_de_venta();

        return AfipTicket::create(array_merge([
            'cuit_negocio'                      => $afip_information->cuit,
            'iva_negocio'                       => $afip_information->iva_condition->name,
            'punto_venta'                       => $afip_information->punto_venta,
            'iva_cliente'                       => '',
            'sale_id'                           => $venta->id,
            'afip_information_id'               => $afip_information->id,
            'afip_tipo_comprobante_id'          => $this->tipo_de_comprobante(self::FACTURA_B)->id,
            'afip_fecha_emision'                => date('Y-m-d'),
            'facturar_importe_personalizado'    => null,
            'importe_personalizado_ivas_json'   => null,
            'forma_de_pago'                     => null,
            'permiso_existente'                 => 'N',
        ], $campos));
    }

    /**
     * Intento numerado, sin CAE y sin resultado (estado «incierto»), creado hace `$minutos` minutos, con
     * el `request/response` de su emisión guardado.
     *
     * @param  \App\Models\Sale $venta
     * @param  int $minutos Antigüedad del intento.
     * @param  array $campos Lo que el escenario quiere fijar.
     * @return \App\Models\AfipTicket
     */
    protected function ticket_incierto($venta, $minutos, array $campos = [])
    {
        $ticket = $this->intento_nuevo($venta, array_merge([
            'cbte_tipo'         => (string) self::FACTURA_B,
            'cbte_numero'       => 1,
            'imp_total_enviado' => 12100.00,
            'request'           => self::REQUEST_DE_EMISION,
            'response'          => self::RESPONSE_DE_EMISION,
        ], $campos));

        DB::table('afip_tickets')->where('id', $ticket->id)->update(['created_at' => Carbon::now()->subMinutes($minutos)]);

        return AfipTicket::find($ticket->id);
    }

    /**
     * El punto de venta del fixture como Responsable inscripto en producción.
     *
     * @return \App\Models\AfipInformation
     */
    protected function punto_de_venta()
    {
        $afip_information = AfipInformation::with('iva_condition')
                                ->where('user_id', $this->user_id())
                                ->where('punto_venta', TestingFerreteriaSeeder::PUNTO_VENTA)
                                ->first();

        if (is_null($afip_information) || is_null($afip_information->iva_condition)) {

            $condicion = IvaCondition::firstOrCreate(['name' => 'Responsable inscripto']);

            $afip_information = AfipInformation::create([
                'user_id'          => $this->user_id(),
                'punto_venta'      => TestingFerreteriaSeeder::PUNTO_VENTA,
                'iva_condition_id' => $condicion->id,
                'razon_social'     => 'zz Test errores de ARCA',
                'cuit'             => '20111111112',
            ]);
        }

        $afip_information->afip_ticket_production = 1;
        $afip_information->save();

        return AfipInformation::with('iva_condition')->find($afip_information->id);
    }

    /**
     * El tipo de comprobante con ese código (el fixture los siembra; si faltara, se crea).
     *
     * @param  int $codigo
     * @return \App\Models\AfipTipoComprobante
     */
    protected function tipo_de_comprobante($codigo)
    {
        return AfipTipoComprobante::firstOrCreate(['codigo' => $codigo], ['name' => 'Comprobante '.$codigo]);
    }

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea por el endpoint real. Cada una con otro total.
     *
     * @return \App\Models\Sale
     */
    protected function crear_venta()
    {
        $precio = $this->proximo_precio;
        $this->proximo_precio += 10;

        $articulo = Article::create([
            'name'       => 'zz Test errores de ARCA '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => 100,
        ]);

        $this->articulos_creados[] = $articulo->id;

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $precio,
            'total'                            => $precio,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $precio,
                'amount'       => 1,
                'costo_real'   => 100,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return Sale::find($response->json('model.id'));
    }
}

/**
 * Doble de WSFE que contesta según un guion y no abre ninguna conexión: devuelve la forma exacta de
 * `WS::__call()` (`hubo_un_error`, `result`, `request`, `response`).
 *
 * Guion (todas las claves opcionales):
 *  - `ultimo`: último número autorizado que contesta `FECompUltimoAutorizado`.
 *  - `errores`: lista de ['Code' =>, 'Msg' =>] que contesta `FECAESolicitar` (sin `FeDetResp`).
 *  - `cabecera`: `Resultado` de `FeCabResp` en esa respuesta (por defecto no se envía cabecera).
 *  - `consulta_errores`: lista de errores que contesta `FECompConsultar`.
 *
 * Nombre propio para poder cargarse en la misma corrida que los otros dobles de `Tests\Feature`.
 */
class DobleDeWsfeConErroresDeArca
{
    /** @var array */
    private $guion;

    /**
     * @param  array $guion
     */
    public function __construct(array $guion)
    {
        $this->guion = $guion;
    }

    /**
     * Arma el nodo `Errors` como lo deserializa `SoapClient`: un solo error llega como objeto en `Err`
     * y varios como lista de objetos.
     *
     * @param  array $errores Lista de ['Code' =>, 'Msg' =>].
     * @return object
     */
    public static function nodo_de_errores(array $errores)
    {
        $objetos = [];
        foreach ($errores as $error) {
            $objetos[] = (object) $error;
        }

        return (object) ['Err' => count($objetos) === 1 ? $objetos[0] : $objetos];
    }

    /**
     * @param  array $pto_vta ['PtoVta' => ..., 'CbteTipo' => ...]
     * @return array
     */
    public function FECompUltimoAutorizado($pto_vta)
    {
        return $this->respuesta((object) [
            'FECompUltimoAutorizadoResult' => (object) [
                'PtoVta'   => $pto_vta['PtoVta'],
                'CbteTipo' => $pto_vta['CbteTipo'],
                'CbteNro'  => isset($this->guion['ultimo']) ? $this->guion['ultimo'] : 0,
            ],
        ], '<ultimo-solicitud/>', '<ultimo-respuesta/>');
    }

    /**
     * @param  array $invoice Payload armado por `solicitar_cae()`.
     * @return array
     */
    public function FECAESolicitar($invoice)
    {
        $resultado = ['Errors' => self::nodo_de_errores($this->guion['errores'])];

        if (isset($this->guion['cabecera'])) {
            $resultado['FeCabResp'] = (object) [
                'PtoVta'    => $invoice['FeCAEReq']['FeCabReq']['PtoVta'],
                'CbteTipo'  => $invoice['FeCAEReq']['FeCabReq']['CbteTipo'],
                'Resultado' => $this->guion['cabecera'],
            ];
        }

        return $this->respuesta((object) ['FECAESolicitarResult' => (object) $resultado], '<emision-solicitud/>', '<emision-respuesta/>');
    }

    /**
     * @param  array $invoice ['FeCompConsReq' => [CbteTipo, CbteNro, PtoVta]]
     * @return array
     */
    public function FECompConsultar($invoice)
    {
        return $this->respuesta((object) [
            'FECompConsultarResult' => (object) ['Errors' => self::nodo_de_errores($this->guion['consulta_errores'])],
        ], '<consulta-solicitud/>', '<consulta-respuesta/>');
    }

    /**
     * @param  object $result
     * @param  string $request
     * @param  string $response
     * @return array
     */
    private function respuesta($result, $request, $response)
    {
        return [
            'hubo_un_error' => false,
            'result'        => $result,
            'request'       => $request,
            'response'      => $response,
        ];
    }
}
