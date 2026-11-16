<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\OpcionesDeCargaIaHelper;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Caja;
use App\Models\Client;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Datos inventados; herramientas reales, sin proveedores y sin ejecutar ventas. PHP 7.4. */
class Planilla_caja_explicita_Test extends PlanillaTestCase
{
    const NORTE = 'Caja Sintetica Norte128';
    const SUR = 'Caja Sintetica Sur128';
    const MEDIO = 'Cobro Sintetico128';

    protected $norte;
    protected $sur;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // Aislar las ofrecibles dentro de la transacción de EmpresaTestCase.
        Caja::where('user_id', $this->dueno->id)->update(['abierta' => 0]);
        CurrentAcountPaymentMethod::forceCreate(['name' => self::MEDIO, 'c_a_payment_method_type_id' => null]);
        $this->norte = $this->crear_caja(self::NORTE);
        $this->sur = $this->crear_caja(self::SUR);
    }

    protected function crear_caja($nombre, $owner = null)
    {
        $owner = is_null($owner) ? $this->dueno->id : $owner;
        $caja = Caja::forceCreate(['user_id' => $owner, 'name' => $nombre,
            'num' => (int) Caja::where('user_id', $owner)->max('num') + 1,
            'saldo' => 0, 'abierta' => 0, 'moneda_id' => 1]);
        $caja->users()->syncWithoutDetaching([(int) $owner]);
        (new CajaAperturaHelper($caja->id))->abrir_caja();
        return $caja->fresh();
    }

    protected function preparada()
    {
        $conversation = $this->conversacion();
        $fila = $this->filas_de_prueba()[0];
        $fila[1] = '1.300,00';
        $titulos = ['COMPROBANTE', 'IMPORTE TOTAL', 'FECHA', 'CODIGO', 'DESCRIPCION',
            'OPERACION', 'CANTIDAD', 'TIPO DE DOCUMENTO', 'DOCUMENTO', 'NOMBRE', 'DIRECCION'];
        $planilla = $this->planilla_pegada($conversation, implode("\t", $titulos) . "\n" . implode("\t", $fila));
        $this->assertNotNull($planilla);
        $this->del_dueno($conversation, 'El importe es el total y se cobraron con ' . self::MEDIO . '.');
        $assistant = $this->assistant($conversation);
        $definida = $this->herramienta('definir_planilla', ['importe_es' => 'total', 'metodo_de_pago' => self::MEDIO], $conversation, $assistant);
        $this->assertTrue($definida['ok'], json_encode($definida));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        return [$conversation, $planilla];
    }

    protected function proponer_con($conversation, $texto)
    {
        $this->del_dueno($conversation, $texto);
        return $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $this->assistant($conversation));
    }

    protected function estado_negocio()
    {
        return [Client::count(), Sale::count(), Article::where('user_id', $this->dueno->id)->orderBy('id')->pluck('stock', 'id')->all()];
    }

    public function nominaciones_afirmadas()
    {
        return [
            ['Se cobraron con ' . self::MEDIO . ' en ' . self::NORTE . '. Prepará la propuesta.'],
            ['La caja es ' . self::NORTE . '. Prepará la propuesta.'],
            ['No cambies los clientes. Se cobraron con ' . self::MEDIO . ' en ' . self::NORTE . '.'],
            ['No estoy seguro del cliente. Se cobraron con ' . self::MEDIO . ' en ' . self::NORTE . '.'],
            ['No uses ' . self::SUR . '; cobrá con ' . self::MEDIO . ' en ' . self::NORTE . '.'],
            ['Se cobraron con ' . self::MEDIO . ' en "' . self::NORTE . '".'],
        ];
    }

    /** @dataProvider nominaciones_afirmadas */
    public function test_consume_la_caja_literal_sin_argumento_del_modelo_y_sin_vender($texto)
    {
        list($conversation, $planilla) = $this->preparada();
        $antes = $this->estado_negocio();
        $resultado = $this->proponer_con($conversation, $texto);
        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(self::NORTE, $planilla->fresh()->definiciones['caja']);
        $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', 'propuesta')->get();
        $this->assertCount(1, $tarjetas);
        $this->assertSame(PlanillaIaHelper::firma($planilla->fresh(), [1]), $tarjetas[0]->datos['firma']);
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }

    public function nominaciones_no_autorizantes()
    {
        return [
            ['No uses ' . self::NORTE . '; todavía no elegí otra caja.'],
            ['No quiero que cobres en ' . self::NORTE . '.'],
            ['No se cobraron en ' . self::NORTE . '.'],
            ['No quiero cobrar en ' . self::NORTE . '.'],
            ['Tal vez se cobren en ' . self::NORTE . '; no estoy seguro.'],
            ['Se cobraron en ' . self::NORTE . '; no estoy seguro de esa caja.'],
            ['Se cobraron en ' . self::NORTE . '. No estoy seguro.'],
            ['¿Se cobraron en ' . self::NORTE . '?'],
            ['Si se cobraran en ' . self::NORTE . ', sería un ejemplo.'],
            ['"Cobrá en ' . self::NORTE . '" es una cita de otra persona.'],
            ["'Cobrá en " . self::NORTE . "' es una cita de otra persona."],
            ['No hagas lo siguiente. Cobrá en ' . self::NORTE . '.'],
            ['Cobrá en ' . self::NORTE . ' o en ' . self::SUR . '.'],
            ['Prepará la propuesta sin cambiar los clientes.'],
        ];
    }

    /** @dataProvider nominaciones_no_autorizantes */
    public function test_no_infiere_caja_de_negacion_cita_duda_o_ausencia($texto)
    {
        list($conversation, $planilla) = $this->preparada();
        $antes = $this->estado_negocio();
        $resultado = $this->proponer_con($conversation, $texto);
        $this->assertFalse($resultado['ok'], json_encode($resultado));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', 'propuesta')->count());
        $this->assertSame($antes, $this->estado_negocio());
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        Http::assertNothingSent();
    }

    public function test_no_elige_la_primera_caja_si_hay_dos_nombres_identicos()
    {
        $this->crear_caja(self::NORTE);
        list($conversation, $planilla) = $this->preparada();
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Cobrá con ' . self::MEDIO . ' en ' . self::NORTE . '.');
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame($antes, $this->estado_negocio());
    }

    public function test_no_elige_una_caja_sin_apertura()
    {
        $this->norte->abierta = 0;
        $this->norte->current_apertura_caja_id = null;
        $this->norte->save();
        list($conversation, $planilla) = $this->preparada();
        $this->assert_ofrecible_unica($conversation, $this->sur->id);
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Cobrá con ' . self::MEDIO . ' en ' . self::NORTE . '.');
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame($antes, $this->estado_negocio());
    }

    protected function assert_ofrecible_unica($conversation, $id)
    {
        $ofrecibles = OpcionesDeCargaIaHelper::cajas_ofrecibles(ContextoDeCargaIa::de_la_conversacion($conversation), 1);
        $this->assertCount(1, $ofrecibles);
        $this->assertSame((int) $id, (int) $ofrecibles[0]->id);
    }

    public function nominaciones_plurales_con_una_ofrecible()
    {
        return [
            ['Cobrá en ' . self::NORTE . ' y en ' . self::SUR . '.'],
            ['Cobrá en ' . self::SUR . ' y en ' . self::NORTE . '.'],
            ['Cobrá en ' . self::NORTE . ' y en Caja Ajena No Descubierta128.'],
            ['Cobrá en Caja Ajena No Descubierta128 y en ' . self::NORTE . '.'],
        ];
    }

    /** @dataProvider nominaciones_plurales_con_una_ofrecible */
    public function test_no_desambigua_dos_destinos_por_disponibilidad($texto)
    {
        $this->sur->abierta = 0;
        $this->sur->current_apertura_caja_id = null;
        $this->sur->save();
        list($conversation, $planilla) = $this->preparada();
        $this->assert_ofrecible_unica($conversation, $this->norte->id);
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, $texto);
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }

    public function test_no_elige_una_caja_de_otro_owner()
    {
        $otro = User::where('id', '!=', $this->dueno->id)->first();
        $this->assertNotNull($otro);
        $this->crear_caja('Caja Ajena Sintetica128', $otro->id);
        list($conversation, $planilla) = $this->preparada();
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Cobrá en Caja Ajena Sintetica128.');
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame($antes, $this->estado_negocio());
    }

    public function test_un_veto_revoca_la_guardada_y_una_continuacion_no_la_resucita()
    {
        list($conversation, $planilla) = $this->preparada();
        $this->del_dueno($conversation, 'La caja es ' . self::NORTE . '.');
        $r = $this->herramienta('definir_planilla', ['caja' => self::NORTE], $conversation, $this->assistant($conversation));
        $this->assertTrue($r['ok'], json_encode($r));
        $antes = $this->estado_negocio();
        $veto = $this->proponer_con($conversation, 'No uses ' . self::NORTE . '; todavía no elegí otra.');
        $this->assertFalse($veto['ok'], json_encode($veto));
        $continuacion = $this->proponer_con($conversation, 'Seguí con la propuesta.');
        $this->assertFalse($continuacion['ok'], json_encode($continuacion));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', 'propuesta')->count());
        $this->assertSame($antes, $this->estado_negocio());
        $nuevo = $this->proponer_con($conversation, 'Ahora cobrá con ' . self::MEDIO . ' en ' . self::SUR . '.');
        $this->assertTrue($nuevo['ok'], json_encode($nuevo));
        $this->assertSame(self::SUR, $planilla->fresh()->definiciones['caja']);
        $this->assertSame($antes, $this->estado_negocio());
        $duda = $this->proponer_con($conversation, 'No estoy seguro de la caja; todavía no elegí otra.');
        $this->assertFalse($duda['ok'], json_encode($duda));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $continuacion = $this->proponer_con($conversation, 'Seguí.');
        $this->assertFalse($continuacion['ok'], json_encode($continuacion));
        $this->assertSame($antes, $this->estado_negocio());
    }

    /** La nueva nominación por la herramienta existente habilita un turno posterior, sin revivir la tarjeta anterior. */
    public function test_una_nueva_eleccion_por_definir_levanta_el_veto_para_el_turno_siguiente()
    {
        list($conversation, $planilla) = $this->preparada();
        $antes = $this->estado_negocio();
        $this->del_dueno($conversation, 'La caja es ' . self::NORTE . '.');
        $definida = $this->herramienta('definir_planilla', ['caja' => self::NORTE], $conversation, $this->assistant($conversation));
        $this->assertTrue($definida['ok'], json_encode($definida));
        $primera = $this->proponer_con($conversation, 'La caja es ' . self::NORTE . '. Prepará la propuesta.');
        $this->assertTrue($primera['ok'], json_encode($primera));
        $viejas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
        $this->assertCount(1, $viejas);
        $vieja = $viejas[0];

        $veto = $this->proponer_con($conversation, 'No uses ' . self::NORTE . '; todavía no elegí otra.');
        $this->assertFalse($veto['ok'], json_encode($veto));
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $vieja->fresh()->estado);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());

        $this->del_dueno($conversation, 'Ahora la caja es ' . self::SUR . '.');
        $nueva = $this->herramienta('definir_planilla', ['caja' => self::SUR], $conversation, $this->assistant($conversation));
        $this->assertTrue($nueva['ok'], json_encode($nueva));
        $this->assertSame(self::SUR, $planilla->fresh()->definiciones['caja']);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $vieja->fresh()->estado);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());

        $continuacion = $this->proponer_con($conversation, 'Seguí con la propuesta.');
        $this->assertTrue($continuacion['ok'], json_encode($continuacion));
        $this->assertSame(self::SUR, $planilla->fresh()->definiciones['caja']);
        $vigentes = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
        $this->assertCount(1, $vigentes);
        $this->assertNotSame((int) $vieja->id, (int) $vigentes[0]->id);
        $this->assertSame(PlanillaIaHelper::firma($planilla->fresh(), [1]), $vigentes[0]->datos['firma']);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $vieja->fresh()->estado);
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }

    /** El medio ya definido y una caja nominada son roles distintos aunque se coordinen con «y en». */
    public function test_medio_resuelto_y_caja_nominada_no_son_dos_cajas()
    {
        list($conversation, $planilla) = $this->preparada();
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $ofrecibles = OpcionesDeCargaIaHelper::cajas_ofrecibles(ContextoDeCargaIa::de_la_conversacion($conversation), 1);
        $this->assertCount(2, $ofrecibles);
        // El catálogo ordena created_at DESC, id DESC: Sur se creó después. Norte no es la primera.
        $this->assertSame([(int) $this->sur->id, (int) $this->norte->id], array_map(function ($caja) {
            return (int) $caja->id;
        }, $ofrecibles));
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Se cobraron en ' . self::MEDIO . ' y en ' . self::NORTE . '.');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(self::NORTE, $planilla->fresh()->definiciones['caja']);
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
        $this->assertCount(1, $tarjetas);
        $this->assertSame(PlanillaIaHelper::firma($planilla->fresh(), [1]), $tarjetas[0]->datos['firma']);
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }
    public function elecciones_que_no_levantan_un_veto()
    {
        return [
            ['No uses ' . self::SUR . '; todavía no elegí otra.', 'normal'],
            ['"La caja es ' . self::SUR . '" es una cita de otra persona.', 'normal'],
            ['Tal vez la caja es ' . self::SUR . '; no estoy seguro.', 'normal'],
            ['Cobrá en ' . self::SUR . ' y en ' . self::NORTE . '.', 'normal'],
            ['La caja es ' . self::SUR . '.', 'cerrada'],
            ['La caja es ' . self::SUR . '.', 'duplicada'],
        ];
    }

    /** @dataProvider elecciones_que_no_levantan_un_veto */
    public function test_definir_no_levanta_el_veto_sin_eleccion_nueva_valida($texto, $estado)
    {
        list($conversation, $planilla) = $this->preparada();
        $this->del_dueno($conversation, 'La caja es ' . self::NORTE . '.');
        $definida = $this->herramienta('definir_planilla', ['caja' => self::NORTE], $conversation, $this->assistant($conversation));
        $this->assertTrue($definida['ok'], json_encode($definida));
        $veto = $this->proponer_con($conversation, 'No uses ' . self::NORTE . '; todavía no elegí otra.');
        $this->assertFalse($veto['ok'], json_encode($veto));
        if ($estado === 'cerrada') {
            $this->sur->abierta = 0;
            $this->sur->current_apertura_caja_id = null;
            $this->sur->save();
        } elseif ($estado === 'duplicada') {
            $this->crear_caja(self::SUR);
        }
        $antes = $this->estado_negocio();
        $this->del_dueno($conversation, $texto);
        $nueva = $this->herramienta('definir_planilla', ['caja' => self::SUR], $conversation, $this->assistant($conversation));
        $this->assertFalse($nueva['ok'], json_encode($nueva));
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $continuacion = $this->proponer_con($conversation, 'Seguí con la propuesta.');
        $this->assertFalse($continuacion['ok'], json_encode($continuacion));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }

    public function estados_para_destino_desconocido_sin_palabra_caja()
    {
        return [['guardada'], ['unica_ofrecible']];
    }

    /** @dataProvider estados_para_destino_desconocido_sin_palabra_caja */
    public function test_destino_desconocido_tras_medio_resuelto_bloquea_fallback_y_continuacion($estado)
    {
        if ($estado === 'unica_ofrecible') {
            $this->sur->abierta = 0;
            $this->sur->current_apertura_caja_id = null;
            $this->sur->save();
        }
        list($conversation, $planilla) = $this->preparada();
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $vieja = null;
        if ($estado === 'guardada') {
            $this->del_dueno($conversation, 'La caja es ' . self::NORTE . '.');
            $definida = $this->herramienta('definir_planilla', ['caja' => self::NORTE], $conversation, $this->assistant($conversation));
            $this->assertTrue($definida['ok'], json_encode($definida));
            $primera = $this->proponer_con($conversation, 'La caja es ' . self::NORTE . '. Prepará la propuesta.');
            $this->assertTrue($primera['ok'], json_encode($primera));
            $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
            $this->assertCount(1, $tarjetas);
            $vieja = $tarjetas[0];
        } else {
            $this->assert_ofrecible_unica($conversation, $this->norte->id);
            $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        }
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Se cobraron en ' . self::MEDIO . ' y en Banco Inexistente Sintetico128.');
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        if (!is_null($vieja)) {
            $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $vieja->fresh()->estado);
        }
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $this->assertSame($antes, $this->estado_negocio());
        $continuacion = $this->proponer_con($conversation, 'Seguí con la propuesta.');
        $this->assertFalse($continuacion['ok'], json_encode($continuacion));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }
    public function test_medio_resuelto_sin_segundo_destino_conserva_la_opcion_unica()
    {
        $this->sur->abierta = 0;
        $this->sur->current_apertura_caja_id = null;
        $this->sur->save();
        list($conversation, $planilla) = $this->preparada();
        $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        $this->assert_ofrecible_unica($conversation, $this->norte->id);
        $antes = $this->estado_negocio();
        $r = $this->proponer_con($conversation, 'Se cobraron en ' . self::MEDIO . '.');
        $this->assertTrue($r['ok'], json_encode($r));
        $faltan = [];
        $opciones = [];
        $cobro = PlanillaIaHelper::definiciones_de_cobro(ContextoDeCargaIa::de_la_conversacion($conversation), $planilla->fresh(), $faltan, $opciones);
        $this->assertCount(0, $faltan);
        $this->assertSame((int) $this->norte->id, (int) $cobro['caja']->id);
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
        $this->assertCount(1, $tarjetas);
        $this->assertSame(PlanillaIaHelper::firma($planilla->fresh(), [1]), $tarjetas[0]->datos['firma']);
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }

    public function destinos_delimitados_o_incompletos_tras_medio_resuelto()
    {
        return [
            ['guardada', '"Banco Inexistente Sintetico128"'],
            ['unica_ofrecible', '"Banco Inexistente Sintetico128"'],
            ['guardada', "'Banco Inexistente Sintetico128'"],
            ['unica_ofrecible', "'Banco Inexistente Sintetico128'"],
            ['guardada', ''],
            ['unica_ofrecible', ''],
        ];
    }

    /** @dataProvider destinos_delimitados_o_incompletos_tras_medio_resuelto */
    public function test_destino_delimitado_o_incompleto_no_es_ausencia_de_eleccion($estado, $destino)
    {
        if ($estado === 'unica_ofrecible') {
            $this->sur->abierta = 0;
            $this->sur->current_apertura_caja_id = null;
            $this->sur->save();
        }
        list($conversation, $planilla) = $this->preparada();
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $vieja = null;
        if ($estado === 'guardada') {
            $this->del_dueno($conversation, 'La caja es ' . self::NORTE . '.');
            $definida = $this->herramienta('definir_planilla', ['caja' => self::NORTE], $conversation, $this->assistant($conversation));
            $this->assertTrue($definida['ok'], json_encode($definida));
            $primera = $this->proponer_con($conversation, 'La caja es ' . self::NORTE . '. Prepará la propuesta.');
            $this->assertTrue($primera['ok'], json_encode($primera));
            $tarjetas = AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->get();
            $this->assertCount(1, $tarjetas);
            $vieja = $tarjetas[0];
        } else {
            $this->assert_ofrecible_unica($conversation, $this->norte->id);
            $this->assertEmpty($planilla->fresh()->definiciones['caja'] ?? null);
        }
        $antes = $this->estado_negocio();
        $pedido = 'Se cobraron en ' . self::MEDIO . ' y en' . ($destino === '' ? '' : ' ' . $destino . '.');
        $r = $this->proponer_con($conversation, $pedido);
        $this->assertFalse($r['ok'], json_encode($r));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        if (!is_null($vieja)) {
            $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $vieja->fresh()->estado);
        }
        $this->assertSame(self::MEDIO, $planilla->fresh()->metodo_de_pago);
        $this->assertSame($antes, $this->estado_negocio());
        $continuacion = $this->proponer_con($conversation, 'Seguí con la propuesta.');
        $this->assertFalse($continuacion['ok'], json_encode($continuacion));
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->where('estado', AiMessageAction::ESTADO_PROPUESTA)->count());
        $this->assertSame($antes, $this->estado_negocio());
        Http::assertNothingSent();
    }
}
