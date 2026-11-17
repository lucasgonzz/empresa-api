<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionDePantallaIaHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261117, A1 (Truvari, 16/11/2026): la venta 3105 quedó con CAE y el asistente le dijo
 * al dueño «pendiente de verificación».
 *
 * Por qué pasaba: SaleController@makeAfipTicket responde {sale: fullModel, estado, message, afip_ticket_id}. Con
 * una venta grande, el JSON pasaba los 30.000 caracteres de EjecutorAccionDePantallaIaHelper::LARGO_MAXIMO y el
 * recorte lo dejaba en {texto, recortado}: `estado` y `message` se perdían y el ejecutor caía en «No se pudo
 * confirmar el resultado de la facturación.» con estado_fiscal pendiente_verificacion.
 *
 * Qué cubre:
 *  - una venta cuyo fullModel supera el largo máximo (observaciones de 31.000 caracteres) se informa `emitida`,
 *    con el texto de MakeAfipTicket, cuando ARCA dio CAE;
 *  - los casos rechazada y pendiente siguen diciendo lo mismo que antes;
 *  - si el cuerpo llegó sin `estado` (respuesta que no se pudo leer), el ejecutor consulta el ticket nuevo de la
 *    venta antes de decir «pendiente»: con CAE es emitida, con resultado R es rechazada;
 *  - un ticket viejo de la misma venta (anterior a la llamada) no cuenta como resultado de esta emisión;
 *  - (revisión independiente) si la facturación tira DESPUÉS de que ARCA dio CAE, se informa emitida en vez de un
 *    error; si tira antes de crear el ticket, el error sigue como siempre; y un afip_ticket_id de otra venta del
 *    mismo negocio no se lee cuando se sabe qué venta se facturó.
 *
 * El borde con ARCA se reemplaza por un transporte falso, sin red.
 */
class Factura_de_venta_grande_informa_su_estado_real_Test extends EmpresaTestCase
{
    protected $dueno;
    protected $emisor;
    protected $tipo;
    protected $transporte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = Auth::user();
        $this->emisor = AfipInformation::create([
            'user_id' => $this->dueno->id,
            'cuit' => '20111111112', 'punto_venta' => 7, 'razon_social' => 'Emisor de prueba A1',
            'iva_condition_id' => IvaCondition::where('name', 'Monotributista')->firstOrFail()->id,
        ]);
        $this->tipo = AfipTipoComprobante::where('codigo', 11)->firstOrFail();
        $this->transporte = new TransporteFiscalDeVentaGrande();
        $this->app->instance(TransporteDeFacturaHelper::class, $this->transporte);
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes IA']);
        }
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        Catalogo::olvidar();
        CatalogoDeEscrituraIaHelper::olvidar();
    }

    /**
     * Una venta con observaciones de 31.000 caracteres: su fullModel sola ya pasa el largo máximo del ejecutor.
     *
     * @return \App\Models\Sale
     */
    protected function venta_grande()
    {
        return Sale::create([
            'user_id' => $this->dueno->id, 'num' => 3105, 'client_id' => null,
            'total' => 1000, 'sub_total' => 1000, 'moneda_id' => 1, 'descuento' => 0,
            'terminada' => 1, 'is_cerrada' => 0, 'save_current_acount' => 0,
            'observations' => str_repeat('Renglón largo de la venta. ', 1150),
        ]);
    }

    /**
     * Propone la factura por WhatsApp, confirma con un «Sí» y devuelve lo que contestó la confirmación.
     *
     * @param  \App\Models\Sale  $venta
     * @param  callable|null  $antes_de_confirmar  Se corre después de la propuesta y antes del «Sí».
     * @return array
     */
    protected function facturar_por_whatsapp($venta, callable $antes_de_confirmar = null): array
    {
        $conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user', 'contenido' => 'Facturá esta venta', 'estado' => 'listo', 'canal' => 'whatsapp']);
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => 'whatsapp']);
        $resultado = (new AsistenteIaService())->execute_tool_calls([[
            'type' => 'tool_use', 'id' => 'toolu_a1', 'name' => 'proponer_accion_de_pantalla',
            'input' => ['metodo' => 'POST', 'ruta' => 'api/afip-ticket', 'descripcion' => 'Facturar la venta 3105', 'cuerpo' => [
                'sale_id' => $venta->id, 'ventas_afip_information_id' => $this->emisor->id,
                'afip_tipo_comprobante_id' => $this->tipo->id, 'afip_fecha_emision' => date('Y-m-d'),
            ]],
        ]], $conversacion, $mensaje);
        $this->assertArrayNotHasKey('is_error', $resultado[0], $resultado[0]['content']);
        $propuesta = json_decode($resultado[0]['content'], true);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $mensaje->update(['estado' => 'listo', 'contenido' => 'Revisá los datos de la factura y confirmá.']);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user', 'contenido' => 'Sí', 'estado' => 'listo', 'canal' => 'whatsapp']);
        $siguiente = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => 'whatsapp']);
        if (!is_null($antes_de_confirmar)) {
            $antes_de_confirmar();
        }

        return ConfirmacionPorTextoIaHelper::confirmar($conversacion, $siguiente, $propuesta['tarjeta_id']);
    }

    public function test_la_venta_grande_supera_el_largo_maximo_del_ejecutor()
    {
        // Precondición del caso: sin esto el test no reproduce el incidente.
        $venta = $this->venta_grande();
        $this->assertGreaterThan(EjecutorAccionDePantallaIaHelper::LARGO_MAXIMO, mb_strlen(json_encode($venta->toArray(), JSON_UNESCAPED_UNICODE)));
    }

    public function test_venta_grande_con_cae_se_informa_emitida_con_el_texto_de_make_afip_ticket()
    {
        $venta = $this->venta_grande();
        $respuesta = $this->facturar_por_whatsapp($venta);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('emitida', $respuesta['estado_fiscal']);
        $this->assertStringContainsString('Factura emitida y autorizada por ARCA.', $respuesta['resultado']);
        $this->assertStringNotContainsString('No se pudo confirmar', $respuesta['resultado']);
        $this->assertSame(1, $this->transporte->emisiones);
        $this->assertNotNull($venta->afip_tickets()->firstOrFail()->cae);
    }

    public function test_venta_grande_rechazada_y_pendiente_siguen_igual()
    {
        $esperado = [
            'rechazo' => ['rechazada', 'ARCA rechazó el comprobante.'],
            'timeout' => ['pendiente_verificacion', 'No se pudo confirmar la emisión ante ARCA.'],
        ];
        foreach ($esperado as $modo => $par) {
            $this->transporte->modo = $modo;
            $respuesta = $this->facturar_por_whatsapp($this->venta_grande());
            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertSame($par[0], $respuesta['estado_fiscal'], $modo);
            $this->assertStringContainsString($par[1], $respuesta['resultado'], $modo);
            $this->assertStringNotContainsString('autorizada por ARCA', $respuesta['resultado'], $modo);
        }
    }

    public function test_sin_estado_en_el_cuerpo_consulta_el_ticket_nuevo_de_la_venta()
    {
        $venta = $this->venta_grande();
        $contexto = ContextoDeCargaIa::de_la_conversacion(AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]), $this->dueno);
        $metodo = new \ReflectionMethod(EjecutorAccionDePantallaIaHelper::class, 'resultado_fiscal');
        $metodo->setAccessible(true);
        $cuerpo_ilegible = ['texto' => '{"sale":{"id":', 'recortado' => true];

        // Sin ticket nuevo: sigue pendiente, como antes.
        $this->assertSame('pendiente_verificacion', $metodo->invoke(null, $contexto, $cuerpo_ilegible, $venta->id, 0)['estado']);

        // Un ticket nuevo con CAE: emitida, con el texto de MakeAfipTicket.
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'cae' => 'cae-a1', 'resultado' => 'A', 'importe_total' => 1000, 'moneda_id' => 'PES']);
        $con_cae = $metodo->invoke(null, $contexto, $cuerpo_ilegible, $venta->id, 0);
        $this->assertSame('emitida', $con_cae['estado']);
        $this->assertSame('Factura emitida y autorizada por ARCA.', $con_cae['message']);

        // Ese mismo ticket, si ya existía antes de la llamada, no es el resultado de esta emisión.
        $this->assertSame('pendiente_verificacion', $metodo->invoke(null, $contexto, $cuerpo_ilegible, $venta->id, (int) $ticket->id)['estado']);

        // Un ticket nuevo rechazado: rechazada.
        $ticket->update(['cae' => null, 'resultado' => 'R']);
        $this->assertSame('rechazada', $metodo->invoke(null, $contexto, $cuerpo_ilegible, $venta->id, 0)['estado']);

        // Con afip_ticket_id en el cuerpo se lee ese ticket.
        $ticket->update(['cae' => 'cae-a1-bis', 'resultado' => 'A']);
        $this->assertSame('emitida', $metodo->invoke(null, $contexto, ['afip_ticket_id' => $ticket->id], null, 0)['estado']);
    }

    public function test_un_ticket_de_otro_negocio_no_se_lee()
    {
        $otro = \App\Models\User::forceCreate(['name' => 'Otro negocio A1', 'email' => uniqid().'@ejemplo.test', 'password' => 'hash-inutil']);
        $ajena = Sale::create(['user_id' => $otro->id, 'num' => 1, 'total' => 10, 'sub_total' => 10, 'moneda_id' => 1, 'descuento' => 0, 'terminada' => 1]);
        $ticket = AfipTicket::create(['sale_id' => $ajena->id, 'cae' => 'cae-ajeno', 'resultado' => 'A', 'importe_total' => 10, 'moneda_id' => 'PES']);
        $contexto = ContextoDeCargaIa::de_la_conversacion(AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]), $this->dueno);
        $metodo = new \ReflectionMethod(EjecutorAccionDePantallaIaHelper::class, 'resultado_fiscal');
        $metodo->setAccessible(true);

        $this->assertSame('pendiente_verificacion', $metodo->invoke(null, $contexto, ['afip_ticket_id' => $ticket->id], null, 0)['estado']);
        $this->assertSame('pendiente_verificacion', $metodo->invoke(null, $contexto, [], $ajena->id, 0)['estado']);
    }
    /**
     * Hace tirar una excepción la PRIMERA vez que se lee una venta después de que la condición se cumple: simula
     * una falla del controller al armar la respuesta (fullModel), con el ticket ya guardado.
     *
     * @param  callable  $condicion  Recibe la venta leída; true = tirar.
     * @return void
     */
    protected function tirar_al_leer_la_venta(callable $condicion): void
    {
        $tiro = false;
        Event::listen('eloquent.retrieved: '.Sale::class, function ($venta) use ($condicion, &$tiro) {
            if (!$tiro && $condicion($venta)) {
                $tiro = true;
                throw new \RuntimeException('Falla simulada al armar la respuesta');
            }
        });
    }

    public function test_si_la_facturacion_tira_despues_del_cae_se_informa_emitida()
    {
        $venta = $this->venta_grande();
        $this->tirar_al_leer_la_venta(function ($leida) use ($venta) {
            return (int) $leida->id === (int) $venta->id && AfipTicket::where('sale_id', $venta->id)->whereNotNull('cae')->exists();
        });

        $respuesta = $this->facturar_por_whatsapp($venta);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame('emitida', $respuesta['estado_fiscal']);
        $this->assertStringContainsString('Factura emitida y autorizada por ARCA.', $respuesta['resultado']);
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_si_la_facturacion_tira_antes_de_crear_el_ticket_el_error_sigue()
    {
        $venta = $this->venta_grande();
        $contexto = ContextoDeCargaIa::de_la_conversacion(AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]), $this->dueno);
        $metodo = new \ReflectionMethod(EjecutorAccionDePantallaIaHelper::class, 'resultado_fiscal');
        $metodo->setAccessible(true);

        // Sin ticket nuevo no hay nada que informar: resultado_fiscal no devuelve ticket y ejecutar() relanza.
        $this->assertNull($metodo->invoke(null, $contexto, [], $venta->id, 0)['afip_ticket_id']);

        // La falla se arma recién al confirmar: la propuesta también lee la venta.
        $respuesta = $this->facturar_por_whatsapp($venta, function () use ($venta) {
            $this->tirar_al_leer_la_venta(function ($leida) use ($venta) {
                return (int) $leida->id === (int) $venta->id;
            });
        });

        $this->assertFalse($respuesta['ok'], json_encode($respuesta));
        $this->assertNotSame('emitida', isset($respuesta['estado_fiscal']) ? $respuesta['estado_fiscal'] : null);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_un_afip_ticket_id_de_otra_venta_del_negocio_no_se_lee()
    {
        $venta = $this->venta_grande();
        $otra = Sale::create(['user_id' => $this->dueno->id, 'num' => 3106, 'total' => 10, 'sub_total' => 10, 'moneda_id' => 1, 'descuento' => 0, 'terminada' => 1]);
        $ticket_de_otra = AfipTicket::create(['sale_id' => $otra->id, 'cae' => 'cae-otra', 'resultado' => 'A', 'importe_total' => 10, 'moneda_id' => 'PES']);
        $contexto = ContextoDeCargaIa::de_la_conversacion(AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]), $this->dueno);
        $metodo = new \ReflectionMethod(EjecutorAccionDePantallaIaHelper::class, 'resultado_fiscal');
        $metodo->setAccessible(true);

        $this->assertSame('pendiente_verificacion', $metodo->invoke(null, $contexto, ['afip_ticket_id' => $ticket_de_otra->id], $venta->id, 0)['estado']);
        // Sin venta conocida, el mismo id se lee (es del negocio).
        $this->assertSame('emitida', $metodo->invoke(null, $contexto, ['afip_ticket_id' => $ticket_de_otra->id], null, 0)['estado']);
    }
}

/** El borde SOAP de ARCA reemplazado: emite sin red, con CAE, rechazo o timeout según `modo`. */
class TransporteFiscalDeVentaGrande extends TransporteDeFacturaHelper
{
    public $emisiones = 0;
    public $modo = 'ok';

    public function emitir($ticket): void
    {
        $this->emisiones++;
        $ticket->update(['cbte_numero' => $ticket->id, 'cbte_tipo' => $ticket->afip_tipo_comprobante->codigo,
            'imp_total_enviado' => $ticket->facturar_importe_personalizado ?: $ticket->sale->total]);
        if ($this->modo === 'timeout') {
            throw new \RuntimeException('Timeout de prueba, sin salida a la red');
        }
        if ($this->modo === 'rechazo') {
            $ticket->update(['resultado' => 'R']);
            AfipError::create(['sale_id' => $ticket->sale_id, 'afip_ticket_id' => $ticket->id, 'code' => 1, 'message' => 'Rechazo simulado']);
            return;
        }
        $importe = (float) $ticket->imp_total_enviado;
        $ticket->update(['cae' => 'cae-'.$ticket->id, 'resultado' => 'A', 'importe_total' => $importe, 'importe_iva' => 0, 'moneda_id' => 'PES']);
        AfipWsHelper::update_sale_total_facturado($ticket, $importe);
    }

    public function conciliar($ticket): void
    {
    }

    public function consultar($ticket): void
    {
    }
}
