<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Http\Controllers\Helpers\asistente_ia\EjecucionDePlanillaIaHelper;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Client;
use App\Models\Sale;
use Illuminate\Support\Facades\Http;

/**
 * Una ampliación del nombre del cliente comparte palabras con el nombre provisional de la fila.
 * El número de renglón y el valor literal son evidencias separadas: sacar la primera no puede
 * borrar también la segunda. La cita sigue siendo textual y del último mensaje de la persona.
 *
 * Todos los nombres, documentos, operaciones y referencias a fotos son inventados. Sin APIs.
 * PHP 7.4: sin match, argumentos nombrados ni union types.
 */
class Planilla_nombre_completo_Test extends PlanillaTestCase
{
    const NOMBRE_PROVISIONAL = 'Taller Norte';

    const NOMBRE_COMPLETO = 'Taller Norte SA';

    const PEDIDO_COMPLETO = 'Para la fila4 usá el nombre completo Taller Norte SA de las fotos17,19 y23, para el mismo documento. En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.';

    protected function setUp(): void
    {
        parent::setUp();

        // El caso sólo usa texto tabulado y herramientas locales; cualquier HTTP queda interceptado.
        Http::fake();
    }

    protected function planilla_con_nombre_provisional($conversation)
    {
        $filas = $this->filas_de_prueba();

        // El CUIT ficticio del fixture queda sólo en la cuarta fila: no se mezclan identidades.
        $filas[2][0] = 'C FINAL';
        $filas[2][7] = 'DNI';
        $filas[2][8] = '17000003';
        $filas[2][9] = 'Comercio Prueba Tres';
        $filas[3][0] = 'FACTURA A';
        $filas[3][7] = 'CUIT';
        $filas[3][8] = self::CUIT_EMPRESA;
        $filas[3][9] = self::NOMBRE_PROVISIONAL;

        $lineas = [];

        foreach ($filas as $fila) {
            $lineas[] = implode("\t", $fila);
        }

        $planilla = $this->planilla_pegada($conversation, implode("\n", $lineas));

        $this->assertNotNull($planilla);
        $this->assertCount(4, $planilla->filas);
        $this->assertSame(self::NOMBRE_PROVISIONAL, $planilla->filas[3]['valores'][Roles::NOMBRE]);
        $this->assertSame(self::CUIT_EMPRESA, $planilla->filas[3]['valores'][Roles::DOCUMENTO]);
        $this->assertSame('CUIT', $planilla->filas[3]['valores'][Roles::TIPO_DOCUMENTO]);

        return $planilla;
    }

    public function citas_del_nombre_completo()
    {
        return [
            'cita sin el punto final' => ['En el renglón 4 cambiá el nombre del cliente por Taller Norte SA'],
            'cita con el punto final' => ['En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.'],
            'nombre completo con fila sin espacio' => ['Para la fila4 usá el nombre completo Taller Norte SA de las fotos17,19 y23, para el mismo documento.'],
            'un homónimo con otro documento no reemplaza esta identidad' => ['En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.', true],
            'no cambiar otros datos no niega completar el nombre' => [
                'No cambies el documento, el tipo ni el comprobante. No cambies el artículo, la cantidad, el total ni los otros renglones. En el renglón 4 usá el nombre completo Taller Norte SA para el mismo documento.',
                false,
                'No cambies el documento, el tipo ni el comprobante. No cambies el artículo, la cantidad, el total ni los otros renglones. En el renglón 4 usá el nombre completo Taller Norte SA para el mismo documento.',
            ],
            'prohibir ventas no niega completar el nombre' => [
                'En fila4 usá el nombre completo Taller Norte SA. No hagas ventas todavía.',
                false,
                'En fila4 usá el nombre completo Taller Norte SA. No hagas ventas todavía.',
            ],
            'otra fila cambia de cliente y esta completa su nombre' => [
                'En el renglón 4 usá el nombre completo Taller Norte SA.',
                false,
                'En el renglón 3 cambiá el cliente por Comercio Este. En el renglón 4 usá el nombre completo Taller Norte SA.',
            ],
            'un veto de nombre de otra fila no niega esta orden' => [
                'En el renglón 4 usá el nombre completo Taller Norte SA.',
                false,
                'En el renglón 3 no cambies el nombre del cliente. En el renglón 4 usá el nombre completo Taller Norte SA.',
            ],
            'un veto de otra fila compacta no reaparece en la parte más amplia' => [
                'En fila4 usá el nombre completo Taller Norte SA.',
                false,
                'En fila3 no cambies el nombre del cliente. En fila4 usá el nombre completo Taller Norte SA.',
            ],
            'una cita cerrada sólo de otra fila no convierte esta orden en una cita' => [
                'En el renglón 4 usá el nombre completo Taller Norte SA.',
                false,
                '“En el renglón 3 cambiá el nombre del cliente por Comercio Este SA”. En el renglón 4 usá el nombre completo Taller Norte SA.',
            ],
            'las comillas ASCII cerradas de otra fila tampoco citan esta orden' => [
                'En el renglón 4 usá el nombre completo Taller Norte SA.',
                false,
                '"En el renglón 3 cambiá el nombre del cliente por Comercio Este SA". En el renglón 4 usá el nombre completo Taller Norte SA.',
            ],
        ];
    }

    /**
     * @dataProvider citas_del_nombre_completo
     */
    public function test_completar_el_nombre_por_numero_de_fila_conserva_la_identidad_y_la_venta($cita, $con_homonimo = false, $pedido = null)
    {
        $homonimo = $con_homonimo ? Client::create([
            'name' => self::NOMBRE_COMPLETO,
            'dni' => '17000008',
            'user_id' => $this->dueno->id,
        ]) : null;
        // Se comparan dos lecturas completas de la fila persistida, incluidos defaults y timestamps.
        $homonimo_antes = is_null($homonimo) ? null : $homonimo->fresh()->getAttributes();
        $conversation = $this->conversacion();
        $planilla = $this->planilla_con_nombre_provisional($conversation);
        $antes = $planilla->filas;
        $iva_antes = EjecucionDePlanillaIaHelper::condicion_de_iva($antes[3]);
        $this->assertNotNull($iva_antes['id']);
        $this->assertTrue($antes[3]['cliente_nuevo']);
        $this->assertNull($antes[3]['cliente_id']);
        $clientes_antes = Client::where('user_id', $this->dueno->id)->count();
        $ventas_antes = Sale::where('user_id', $this->dueno->id)->count();

        $this->del_dueno($conversation, is_null($pedido) ? self::PEDIDO_COMPLETO : $pedido);
        $assistant = $this->assistant($conversation);
        $respuesta = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 4,
            'campo' => 'cliente',
            'valor' => self::NOMBRE_COMPLETO,
            'lo_que_dijo_el_dueno' => $cita,
        ], $conversation, $assistant);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertTrue($respuesta['cambio']);

        $despues = $planilla->fresh()->filas;
        $valores_esperados = $antes[3]['valores'];
        $valores_esperados[Roles::NOMBRE] = self::NOMBRE_COMPLETO;

        // El mismo documento no se borra por ampliar su razón social: tampoco cambia la venta.
        $this->assertSame($valores_esperados, $despues[3]['valores']);
        $this->assertSame($iva_antes, EjecucionDePlanillaIaHelper::condicion_de_iva($despues[3]));
        $this->assertSame($antes[3]['articulo_id'], $despues[3]['articulo_id']);
        $this->assertNull($despues[3]['cliente_id']);

        if (!is_null($homonimo)) {
            $this->assertSame($homonimo_antes, $homonimo->fresh()->getAttributes());
        }

        foreach ([0, 1, 2] as $i) {
            $this->assertSame($antes[$i]['valores'], $despues[$i]['valores']);
            $this->assertSame($antes[$i]['articulo_id'], $despues[$i]['articulo_id']);
            $this->assertSame($antes[$i]['cliente_id'] ?? null, $despues[$i]['cliente_id'] ?? null);
        }

        $this->assertSame($clientes_antes, Client::where('user_id', $this->dueno->id)->count());
        $this->assertSame($ventas_antes, Sale::where('user_id', $this->dueno->id)->count());
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
        Http::assertNothingSent();
    }

    public function correcciones_sin_evidencias_separadas()
    {
        $casos = [
            'un sí no cambia el cliente resuelto' => ['Sí', 'Sí', self::NOMBRE_COMPLETO, 'un_si_no_corrige'],
            'el valor inventado comparte el nombre anterior' => [self::PEDIDO_COMPLETO, 'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.', 'Taller Norte SRL', 'sin_ancla'],
            'la cita inventa el valor' => [self::PEDIDO_COMPLETO, 'En el renglón 4 cambiá el nombre del cliente por Taller Norte SRL.', 'Taller Norte SRL', 'sin_ancla'],
            'una misma frase no nombra la fila y el valor' => [self::NOMBRE_COMPLETO, self::NOMBRE_COMPLETO, self::NOMBRE_COMPLETO, 'sin_ancla'],
            'la cita omite la fila separada' => [self::PEDIDO_COMPLETO, self::NOMBRE_COMPLETO, self::NOMBRE_COMPLETO, 'sin_ancla'],
            'la acción citada de esta fila sigue sin ser una orden' => ['"En el renglón 4 cambiá el nombre del cliente por Taller Norte SA."', 'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.', self::NOMBRE_COMPLETO, 'sin_ancla'],
            'el número explícito es de otra fila' => ['En el renglón 3 cambiá el nombre del cliente por Taller Norte SA.', 'En el renglón 3 cambiá el nombre del cliente por Taller Norte SA.', self::NOMBRE_COMPLETO, 'sin_ancla'],
            'fila cuarenta no es fila cuatro' => ['En la fila40 cambiá el nombre del cliente por Taller Norte SA.', 'En la fila40 cambiá el nombre del cliente por Taller Norte SA.', self::NOMBRE_COMPLETO, 'sin_ancla'],
            'la cita cruza el valor de otra fila' => ['En el renglón 3 cambiá el nombre del cliente por Taller Norte SA y en el renglón 4 cambiá el cliente por Repuestos Sur.', 'En el renglón 3 cambiá el nombre del cliente por Taller Norte SA', self::NOMBRE_COMPLETO, 'sin_ancla'],
            'la orden de nombre está negada en la cita completa' => [
                'En el renglón4 no hagas lo siguiente: cambiá el nombre del cliente por Taller Norte SA.',
                'En el renglón4 no hagas lo siguiente: cambiá el nombre del cliente por Taller Norte SA.',
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ],
            'la cita recorta la negación que envuelve la orden' => [
                'No hagas lo siguiente: En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
                'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ],
            'una orden citada como ejemplo no es un pedido' => [
                'Esto es sólo un ejemplo, no una orden: En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
                'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ],
            'una hipótesis no es una corrección' => [
                'Si te dijera «En el renglón 4 cambiá el nombre del cliente por Taller Norte SA», sería una hipótesis.',
                'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA',
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ],
        ];

        // La envoltura no deja de negar la orden por cambiar un separador ni por recortar la cita.
        foreach ([
            'punto' => '. ',
            'salto de línea' => "\n",
            'punto y salto de línea' => ".\n",
            'párrafo separado' => ".\n\n",
            'punto y coma' => '; ',
            'exclamación' => '! ',
        ] as $nombre => $separador) {
            $cola = 'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.';
            $casos['negación envuelta con ' . $nombre] = [
                'No hagas lo siguiente' . $separador . $cola,
                $cola,
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ];
        }

        // El número entero de la fila no puede tomarse del prefijo de un decimal o un rango.
        foreach (['4.5', '4,5', '4-5', '4/5', '4 a 5', '4 hasta cinco', '4 a5', '4/cinco'] as $referencia) {
            $pedido = 'En la fila' . $referencia . ' usá el nombre completo Taller Norte SA.';
            $casos['referencia no entera ' . $referencia] = [$pedido, $pedido, self::NOMBRE_COMPLETO, 'sin_ancla'];
        }

        foreach (['No quiero que', 'No hace falta que', 'Te pido que no'] as $envoltura) {
            $cola = 'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.';
            $casos['envoltura indirecta ' . $envoltura] = [
                $envoltura . ' hagas lo siguiente. ' . $cola,
                $cola,
                self::NOMBRE_COMPLETO,
                'sin_ancla',
            ];
        }

        $casos['el rechazo de la excepción no habilita el cambio por una palabra distinta'] = [
            'No hagas lo siguiente. En el renglón4 cambiá el nombre del cliente por Taller Norte Central.',
            'En el renglón4 cambiá el nombre del cliente por Taller Norte Central.',
            'Taller Norte Central',
            'sin_ancla',
        ];
        $casos['recortar la intención de nombre no reabre el cambio habitual'] = [
            'En el renglón4 Taller Norte Central: usá el nombre completo Taller Norte Central.',
            'En el renglón4 Taller Norte Central',
            'Taller Norte Central',
            'sin_ancla',
        ];
        $casos['localizar la cita multifila tampoco borra la candidatura del dueño'] = [
            'En el renglón 3 cambiá el nombre del cliente por Comercio Este SA. En el renglón 4 Taller Norte Central: usá el nombre completo Taller Norte Central.',
            'En el renglón 4 Taller Norte Central',
            'Taller Norte Central',
            'sin_ancla',
        ];
        $casos['una envoltura general sigue gobernando esta fila con varias instrucciones'] = [
            'No hagas lo siguiente. En el renglón 3 cambiá el nombre del cliente por Comercio Este SA. En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
            'En el renglón 4 cambiá el nombre del cliente por Taller Norte SA.',
            self::NOMBRE_COMPLETO,
            'sin_ancla',
        ];

        return $casos;
    }

    /**
     * @dataProvider correcciones_sin_evidencias_separadas
     */
    public function test_sin_valor_cita_y_fila_reales_no_se_modifica_la_tabla($pedido, $cita, $valor, $codigo)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_con_nombre_provisional($conversation);
        $antes = $planilla->filas;

        $this->del_dueno($conversation, $pedido);
        $assistant = $this->assistant($conversation);
        $respuesta = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 4,
            'campo' => 'cliente',
            'valor' => $valor,
            'lo_que_dijo_el_dueno' => $cita,
        ], $conversation, $assistant);

        $this->assertFalse($respuesta['ok']);
        $this->assertSame($codigo, $respuesta['codigo']);
        $this->assertSame($antes, $planilla->fresh()->filas);
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count());
        Http::assertNothingSent();
    }

    public function otros_clientes()
    {
        return [
            'otro nombre' => ['Repuestos Sur'],
            'otro cliente comparte el prefijo del nombre' => ['Taller Norte Sur'],
        ];
    }

    public function test_una_orden_de_nombre_de_otra_fila_no_clasifica_este_cambio_de_cliente()
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_con_nombre_provisional($conversation);
        $antes = $planilla->filas;
        $cita = 'En el renglón 4 cambiá el cliente por Repuestos Sur.';
        $this->del_dueno($conversation, 'En el renglón 3 cambiá el nombre del cliente por Comercio Este SA. ' . $cita);

        $respuesta = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 4,
            'campo' => 'cliente',
            'valor' => 'Repuestos Sur',
            'lo_que_dijo_el_dueno' => $cita,
        ], $conversation, $this->assistant($conversation));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $despues = $planilla->fresh()->filas;
        $esperados = $antes[3]['valores'];
        unset($esperados[Roles::DOCUMENTO], $esperados[Roles::TIPO_DOCUMENTO]);
        $esperados[Roles::NOMBRE] = 'Repuestos Sur';
        $this->assertSame($esperados, $despues[3]['valores']);

        foreach ([0, 1, 2] as $i) {
            $this->assertSame($antes[$i]['valores'], $despues[$i]['valores']);
        }

        Http::assertNothingSent();
    }

    /** @dataProvider otros_clientes */
    public function test_cambiar_a_otro_cliente_sigue_limpiando_el_documento_del_anterior($nombre)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_con_nombre_provisional($conversation);
        $antes = $planilla->filas[3]['valores'];
        $pedido = 'En el renglón 4 cambiá el cliente por ' . $nombre . '.';

        $this->del_dueno($conversation, $pedido);
        $respuesta = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 4,
            'campo' => 'cliente',
            'valor' => $nombre,
            'lo_que_dijo_el_dueno' => $pedido,
        ], $conversation, $this->assistant($conversation));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $valores = $planilla->fresh()->filas[3]['valores'];
        $this->assertSame($nombre, $valores[Roles::NOMBRE]);
        $this->assertArrayNotHasKey(Roles::DOCUMENTO, $valores);
        $this->assertArrayNotHasKey(Roles::TIPO_DOCUMENTO, $valores);
        unset($antes[Roles::DOCUMENTO], $antes[Roles::TIPO_DOCUMENTO]);
        $antes[Roles::NOMBRE] = $nombre;
        $this->assertSame($antes, $valores);
        Http::assertNothingSent();
    }
}
