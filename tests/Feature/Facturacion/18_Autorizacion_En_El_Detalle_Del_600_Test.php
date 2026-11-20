<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\IvaCondition;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión arca-facturas-fallidas (20/11/2026) — corrección de la revisión 1 (hallazgo mayor, REVISION-1-C).
 *
 * ─── El defecto ───────────────────────────────────────────────────────────────────────────────
 *
 * `AfipWsfeHelper::rechazar_si_arca_no_proceso_el_pedido()` marca el intento `R` (y habilita borrarlo o
 * reemitirlo) cuando `FECAESolicitar` contesta un 600 sin CAE. Sólo descartaba la autorización mirando
 * `FeCabResp->Resultado = 'A'` (cabecera) y la presencia de CAE. Una respuesta contradictoria — error 600
 * pero `FeDetResp->FECAEDetResponse->Resultado = 'A'` SIN CAE — pasaba la guarda y quedaba `R`, aunque el
 * propio detalle de ARCA dice que el comprobante se autorizó.
 *
 * ─── Lo que se exige ──────────────────────────────────────────────────────────────────────────
 *
 * Ante cualquier señal de autorización en la respuesta (cabecera o detalle, en cualquiera de las formas
 * que deja el SOAP: nodo único o lista) el intento NO se rechaza: queda incierto, protegido contra
 * borrado y reemisión. Los errores igual se guardan para que el usuario los vea. El negocio del 600 no
 * cambia: sin ninguna señal de autorización sigue siendo rechazo firme.
 *
 * Archivo aparte del de aceptación (`16_…`, commit `eccd970d`, que no se toca). Usa el helper real y sin
 * transporte: `checkErrors()` no abre el webservice, así que el helper se crea sin constructor.
 *
 * @group facturacion
 * @group afip
 */
class Autorizacion_En_El_Detalle_Del_600_Test extends EmpresaTestCase
{
    /** Código de ARCA de la Factura B. */
    const FACTURA_B = 6;

    const MENSAJE_600 = 'ValidacionDeToken: No apareció CUIT en lista de relaciones: 20111111112';

    const REQUEST_DE_EMISION = '<emision-solicitud/>';

    const RESPONSE_DE_EMISION = '<emision-respuesta/>';

    // =========================================================================================
    // El defecto: autorización en el detalle sin CAE
    // =========================================================================================

    /**
     * 600 + `FeDetResp->FECAEDetResponse->Resultado = 'A'` sin CAE (forma de nodo único): no es un rechazo
     * firme. El intento sigue incierto y los errores se guardan. Contra el código anterior falla: queda `R`.
     *
     * @test
     */
    public function test_600_con_resultado_a_en_el_detalle_sin_cae_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, (object) [
            'FECAEDetResponse' => (object) ['Resultado' => 'A'],
        ]);

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorGuardado($ticket);
    }

    /**
     * Mismo caso con el detalle como lista (`FECAEDetResponse` repetido: SoapClient lo entrega como array
     * de objetos): la autorización está en el segundo elemento y también cuenta.
     *
     * @test
     */
    public function test_600_con_resultado_a_en_una_lista_de_detalles_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, (object) [
            'FECAEDetResponse' => [
                (object) ['Concepto' => 1],
                (object) ['Resultado' => 'A'],
            ],
        ]);

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorGuardado($ticket);
    }

    /**
     * El propio `FeDetResp` como lista de nodos (otra forma normalizada del SOAP).
     *
     * @test
     */
    public function test_600_con_resultado_a_en_un_fedetresp_lista_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, [
            (object) ['FECAEDetResponse' => (object) ['Resultado' => 'R']],
            (object) ['FECAEDetResponse' => (object) ['Resultado' => 'A']],
        ]);

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorGuardado($ticket);
    }

    /**
     * El detalle como array asociativo (la respuesta ya convertida por `Utf8Helper`) también cuenta.
     *
     * @test
     */
    public function test_600_con_resultado_a_en_detalle_array_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, ['FECAEDetResponse' => ['Resultado' => 'A']]);

        $this->assertIntentoIncierto($ticket);
        $this->assertErrorGuardado($ticket);
    }

    // =========================================================================================
    // Guardas: lo que no cambia
    // =========================================================================================

    /**
     * Guarda positiva — 600 sin ninguna señal de autorización (sin detalle) sigue siendo rechazo firme.
     *
     * @test
     */
    public function test_600_sin_detalle_sigue_rechazando()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, null);

        $this->assertIntentoRechazado($ticket);
    }

    /**
     * Guarda positiva — 600 con un detalle que NO autoriza (`Resultado = 'R'`, sin CAE) sigue siendo
     * rechazo firme: el detalle no autoriza nada.
     *
     * @test
     */
    public function test_600_con_resultado_r_en_el_detalle_sigue_rechazando()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, (object) [
            'FECAEDetResponse' => (object) ['Resultado' => 'R', 'Concepto' => 1],
        ]);

        $this->assertIntentoRechazado($ticket);
    }

    /**
     * Guarda negativa — el 600 con CAE en el detalle (y `Resultado = 'A'`) no se toca.
     *
     * @test
     */
    public function test_600_con_resultado_a_y_cae_en_el_detalle_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, (object) [
            'FECAEDetResponse' => (object) ['Resultado' => 'A', 'CAE' => '71279049124261'],
        ]);

        $this->assertIntentoIncierto($ticket);
    }

    /**
     * Guarda negativa — el 600 con `Resultado = 'A'` en la cabecera no se toca (cubierto por la
     * implementación anterior; se fija para que la corrección no lo pierda).
     *
     * @test
     */
    public function test_600_con_resultado_a_en_la_cabecera_no_rechaza()
    {
        $ticket = $this->ticket_incierto();

        $this->emitir_con_600($ticket, null, (object) ['Resultado' => 'A']);

        $this->assertIntentoIncierto($ticket);
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Corre `checkErrors()` real con una respuesta de `FECAESolicitar` que trae un error 600 y el detalle
     * indicado, y con el número y tipo del ticket como los que viajaron en la emisión.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  mixed $detalle Valor de `FeDetResp`; null si la respuesta no lo trae.
     * @param  mixed $cabecera Valor de `FeCabResp`; null si la respuesta no lo trae.
     * @return void
     */
    protected function emitir_con_600($ticket, $detalle, $cabecera = null)
    {
        $resultado = [
            'Errors' => (object) ['Err' => (object) ['Code' => 600, 'Msg' => self::MENSAJE_600]],
        ];

        if (!is_null($cabecera)) {
            $resultado['FeCabResp'] = $cabecera;
        }

        if (!is_null($detalle)) {
            $resultado['FeDetResp'] = $detalle;
        }

        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->testing = true;
        $helper->afip_ticket = AfipTicket::find($ticket->id);
        $helper->comprobante_numero = 1;
        $helper->comprobante_tipo = self::FACTURA_B;

        $helper->checkErrors((object) ['FECAESolicitarResult' => (object) $resultado], [
            'hubo_un_error' => false,
            'request'       => '<otra-solicitud/>',
            'response'      => '<otra-respuesta/>',
        ]);
    }

    /**
     * El intento sigue incierto: sin `R`, sin CAE y con la evidencia de su emisión intacta.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertIntentoIncierto($ticket)
    {
        $actual = AfipTicket::find($ticket->id);

        $this->assertNull($actual->resultado, 'Con una señal de autorización en la respuesta el intento no puede quedar rechazado.');
        $this->assertTrue(empty($actual->cae));
        $this->assertSame(self::REQUEST_DE_EMISION, $actual->request, 'El request guardado es evidencia: no se pisa.');
        $this->assertSame(self::RESPONSE_DE_EMISION, $actual->response, 'El response guardado es evidencia: no se pisa.');
    }

    /**
     * El intento quedó rechazado firme: `R`, sin CAE y con la evidencia de esa emisión.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertIntentoRechazado($ticket)
    {
        $actual = AfipTicket::find($ticket->id);

        $this->assertSame('R', $actual->resultado);
        $this->assertTrue(empty($actual->cae));
        $this->assertSame('<otra-solicitud/>', $actual->request);
        $this->assertSame('<otra-respuesta/>', $actual->response);
    }

    /**
     * El error 600 sigue guardado (una sola vez) para que el usuario lo vea, sin el texto de rechazo.
     *
     * @param  \App\Models\AfipTicket $ticket
     * @return void
     */
    protected function assertErrorGuardado($ticket)
    {
        $errores = AfipError::where('afip_ticket_id', $ticket->id)->get();

        $this->assertCount(1, $errores);
        $this->assertSame('600', (string) $errores[0]->code);
        $this->assertStringContainsString(self::MENSAJE_600, $errores[0]->message);
        $this->assertStringNotContainsString('Podés eliminar este intento', $errores[0]->message, 'No se promete que se puede borrar un intento incierto.');
    }

    /**
     * Intento numerado, sin CAE y sin resultado (estado «incierto») con el `request/response` de su
     * emisión guardado. Mismos campos que `MakeAfipTicket::make_afip_ticket()`; no necesita venta.
     *
     * @return \App\Models\AfipTicket
     */
    protected function ticket_incierto()
    {
        $afip_information = $this->punto_de_venta();

        return AfipTicket::create([
            'cuit_negocio'                    => $afip_information->cuit,
            'iva_negocio'                     => $afip_information->iva_condition->name,
            'punto_venta'                     => $afip_information->punto_venta,
            'iva_cliente'                     => '',
            'afip_information_id'             => $afip_information->id,
            'afip_tipo_comprobante_id'        => AfipTipoComprobante::firstOrCreate(['codigo' => self::FACTURA_B], ['name' => 'Comprobante '.self::FACTURA_B])->id,
            'afip_fecha_emision'              => date('Y-m-d'),
            'facturar_importe_personalizado'  => null,
            'importe_personalizado_ivas_json' => null,
            'forma_de_pago'                   => null,
            'permiso_existente'               => 'N',
            'cbte_tipo'                       => (string) self::FACTURA_B,
            'cbte_numero'                     => 1,
            'imp_total_enviado'               => 12100.00,
            'request'                         => self::REQUEST_DE_EMISION,
            'response'                        => self::RESPONSE_DE_EMISION,
        ]);
    }

    /**
     * El punto de venta del fixture como Responsable inscripto en producción.
     *
     * @return \App\Models\AfipInformation
     */
    protected function punto_de_venta()
    {
        $user_id = (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;

        $afip_information = AfipInformation::with('iva_condition')
                                ->where('user_id', $user_id)
                                ->where('punto_venta', TestingFerreteriaSeeder::PUNTO_VENTA)
                                ->first();

        if (is_null($afip_information) || is_null($afip_information->iva_condition)) {

            $condicion = IvaCondition::firstOrCreate(['name' => 'Responsable inscripto']);

            $afip_information = AfipInformation::create([
                'user_id'          => $user_id,
                'punto_venta'      => TestingFerreteriaSeeder::PUNTO_VENTA,
                'iva_condition_id' => $condicion->id,
                'razon_social'     => 'zz Test autorización en el detalle',
                'cuit'             => '20111111112',
            ]);
        }

        $afip_information->afip_ticket_production = 1;
        $afip_information->save();

        return AfipInformation::with('iva_condition')->find($afip_information->id);
    }
}
