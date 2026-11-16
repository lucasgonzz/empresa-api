<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EjecucionDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\LecturaDePlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Models\AiPlanilla;
use App\Models\Article;
use App\Models\Sale;

/**
 * Identificadores y personas100%sintéticos. Los caracteres de formato de una operación no
 * cambian su identidad; ningún carácter visible puede perderse. Sin proveedores reales.
 * PHP7.4. No se normalizan otros campos para resolver el identificador.
 */
class Planilla_operacion_sin_formato_Test extends PlanillaTestCase
{
    const OPERACION = '#2000000000000099';

    protected function con_formato($operacion)
    {
        return '#' . "\u{200B}" . ltrim($operacion, '#');
    }

    protected function fila_de_texto($operacion)
    {
        $fila = $this->filas_de_prueba()[0];
        $fila[5] = $operacion;

        return $fila;
    }

    protected function texto_de_operacion($operacion)
    {
        $titulos = ['COMPROBANTE', 'IMPORTE', 'FECHA', 'CODIGO', 'DESCRIPCION', 'OPERACION',
            'CANTIDAD', 'TIPO DE DOCUMENTO', 'DOCUMENTO', 'NOMBRE', 'DIRECCION'];

        return implode("\t", $titulos) . "\n" . implode("\t", $this->fila_de_texto($operacion));
    }

    protected function invocar($clase, $metodo, array $argumentos)
    {
        $reflexion = new \ReflectionMethod($clase, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs(null, $argumentos);
    }

    public function orden_de_las_lecturas()
    {
        return [
            ['A con formato' => true],
            ['B con formato' => false],
            [true, '#0000000000000099'],
            [false, '#0000000000000099'],
        ];
    }

    /** @dataProvider orden_de_las_lecturas */
    public function test_las_lecturas_equivalentes_guardan_el_mismo_identificador($formato_en_a, $operacion = self::OPERACION)
    {
        // Las otras filas fijan el rol de la columna, como en la tabla real que motivó el caso.
        $filas_a = $filas_b = $this->filas_de_prueba();
        $quinta = $this->filas_de_prueba()[3];
        $quinta[5] = '#2000000000000005';
        $filas_a[] = $filas_b[] = $quinta;
        $filas_a[0][5] = $formato_en_a ? $this->con_formato($operacion) : $operacion;
        $filas_b[0][5] = $formato_en_a ? $operacion : $this->con_formato($operacion);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_a')] = $this->lectura($filas_a);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_b')] = $this->lectura($filas_b);
        $this->falsear_ia();
        $conversation = $this->conversacion();
        $mensaje = $this->del_dueno($conversation, 'Mirá esta planilla de ventas.');
        $this->foto($mensaje);
        $assistant = $this->assistant($conversation);
        PlanillaIaHelper::al_arrancar_el_turno($conversation, $assistant);
        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();
        $this->assertNotNull($planilla);
        $this->assertCount(5, $planilla->filas);
        $fila = $planilla->filas[0];
        $this->assertArrayHasKey(Roles::OPERACION, $fila['valores']);
        $this->assertSame($operacion, $fila['valores'][Roles::OPERACION]);
        $this->assertSame([], $fila['dudas']);
        $columna = array_search(Roles::OPERACION, $planilla->roles, true);
        $this->assertSame($filas_a[0][5], $fila['celdas'][$columna], 'La celda fuente sigue siendo evidencia cruda.');
        $this->assertSame($filas_a[0][9], $fila['valores'][Roles::NOMBRE]);
        $this->assertSame($filas_a[0][1], $fila['valores'][Roles::IMPORTE]);
        $this->assertCount(0, $this->lecturas_de((string) config('services.lectura_planilla.modelo_desempate')));
    }

    public function test_el_consenso_no_fusiona_operaciones_con_ceros_visiblemente_distintos()
    {
        $a = $b = $c = $this->filas_de_prueba();
        $quinta = $this->filas_de_prueba()[3];
        $quinta[5] = '#2000000000000005';
        $a[] = $b[] = $c[] = $quinta;
        $a[0][5] = '#0000000000000099';
        $b[0][5] = '#000000000000099';
        $c[0][5] = '#0000000000000098';
        $this->lecturas[(string) config('services.lectura_planilla.modelo_a')] = $this->lectura($a);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_b')] = $this->lectura($b);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_desempate')] = $this->lectura($c);
        $this->falsear_ia();
        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, 'Mirá esta planilla de ventas.'));
        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));
        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();
        $this->assertNotNull($planilla);
        $fila = $planilla->filas[0];
        $this->assertArrayNotHasKey(Roles::OPERACION, $fila['valores']);
        $this->assertTrue(Roles::tiene_duda($fila, Roles::OPERACION));
        $this->assertCount(1, $this->lecturas_de((string) config('services.lectura_planilla.modelo_desempate')));
    }

    public function sujetos_del_consenso()
    {
        $limpia = ['encabezado' => null, 'filas' => [['#0000000000000099']]];
        $ausente_igual = ['encabezado' => null, 'filas' => [["#\u{200B}0000000000000099"]]];
        $ausente_distinta = ['encabezado' => null, 'filas' => [["#\u{200B}0000000000000098"]]];
        $op = ['encabezado' => ['OPERACION'], 'filas' => [['#0000000000000099']]];
        $codigo = ['encabezado' => ['CODIGO'], 'filas' => [['#0000000000000099']]];
        $dos_a = ['encabezado' => null, 'filas' => [['#2000000000000099', "#\u{200B}2000000000000098"]]];
        $dos_b = ['encabezado' => null, 'filas' => [["#\u{200B}2000000000000099", '#2000000000000098']]];
        $otro_a = ['encabezado' => ['IMPORTE', 'NOMBRE'], 'filas' => [['1.500,00', "Ana\u{200B} Prueba"]],
            'roles' => [Roles::OPERACION, Roles::OPERACION]];
        $otro_b = ['encabezado' => ['IMPORTE', 'NOMBRE'], 'filas' => [['1500.00', 'Ana Prueba']],
            'roles' => [Roles::OPERACION, Roles::OPERACION]];

        return [
            'un rol y ausencia con identidad equivalente' => [[$ausente_igual, $limpia], false, true],
            'dos roles mantienen mayoria con tercera ausencia distinta' => [[$limpia, $limpia, $ausente_distinta], false, true],
            'un titulo especifico contrario veta incluso dos roles' => [[$op, $op, $codigo], true, false],
            'no se pueden confirmar dos columnas de operacion' => [[$dos_a, $dos_b], true, false],
            'otros roles y roles inyectados no aportan operacion' => [[$otro_a, $otro_b], false, false],
            'un rol con ausencia visiblemente distinta no elige' => [[$limpia, $ausente_distinta], true, false],
        ];
    }

    /** @dataProvider sujetos_del_consenso */
    public function test_el_sujeto_del_consenso_es_server_y_conserva_el_mapa_completo($lecturas, $sin_consenso, $confirma_operacion)
    {
        $tabla = $this->invocar(LecturaDePlanillaIaHelper::class, 'combinar', [$lecturas]);
        $this->assertIsArray($tabla);
        $this->assertArrayHasKey('operacion_sin_consenso', $tabla);
        $this->assertSame($sin_consenso, $tabla['operacion_sin_consenso']);
        $this->assertArrayHasKey('roles', $tabla);
        $this->assertCount(count($tabla['filas'][0]), $tabla['roles']);

        if ($sin_consenso) {
            return;
        }

        if ($confirma_operacion) {
            $this->assertSame(Roles::OPERACION, $tabla['roles'][0]);
            $this->assertCount(0, $tabla['dudas']);
        } else {
            $this->assertSame(Roles::roles_de($tabla['encabezado'], $tabla['filas']), $tabla['roles']);
            $this->assertNotContains(Roles::OPERACION, $tabla['roles']);
            $this->assertSame($lecturas[0]['filas'], $tabla['filas'], 'No se limpian celdas de otros campos.');
        }
    }

    public function test_roles_del_json_del_modelo_no_reemplazan_el_mapa_server()
    {
        $filas = $this->filas_de_prueba();
        $json = json_encode(['encabezado' => null, 'filas' => $filas,
            'roles' => array_fill(0, count($filas[0]), Roles::OPERACION)], JSON_UNESCAPED_UNICODE);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_a')] = $this->texto_del_modelo($json);
        $this->lecturas[(string) config('services.lectura_planilla.modelo_b')] = $this->texto_del_modelo($json);
        $this->falsear_ia();
        $conversation = $this->conversacion();
        $this->foto($this->del_dueno($conversation, 'Mirá esta planilla de ventas.'));
        PlanillaIaHelper::al_arrancar_el_turno($conversation, $this->assistant($conversation));
        $planilla = AiPlanilla::where('ai_conversation_id', $conversation->id)->first();
        $this->assertNotNull($planilla);
        $this->assertCount(1, array_keys($planilla->roles, Roles::OPERACION, true));
        $this->assertContains(Roles::IMPORTE, $planilla->roles);
        $this->assertContains(Roles::NOMBRE, $planilla->roles);
    }

    public function identificadores_con_formato()
    {
        return [
            ["#\u{200B}2000000000000099", self::OPERACION],
            [" #OP-AB/\u{200B}0007 ", '#OP-AB/0007'],
            ["#000\u{200B}000000000000099", '#000000000000000099'],
        ];
    }

    /** @dataProvider identificadores_con_formato */
    public function test_el_texto_limpia_solo_formato_de_operacion($entrada, $esperada)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, $this->texto_de_operacion($entrada));
        $this->assertNotNull($planilla);
        $valores = $planilla->filas[0]['valores'];
        $this->assertSame($esperada, $valores[Roles::OPERACION]);
        $original = $this->fila_de_texto($entrada);
        $this->assertSame($original[0], $valores[Roles::COMPROBANTE]);
        $this->assertSame($original[1], $valores[Roles::IMPORTE]);
        $this->assertSame($original[8], $valores[Roles::DOCUMENTO]);
        $this->assertSame($original[9], $valores[Roles::NOMBRE]);
    }

    public function equivalencias_con_formato()
    {
        return [
            [true, false],
            [false, true],
        ];
    }

    /** @dataProvider equivalencias_con_formato */
    public function test_un_complemento_equivalente_no_agrega_otro_renglon($primera_con_formato, $segunda_con_formato)
    {
        $conversation = $this->conversacion();
        $primera = $this->planilla_pegada($conversation, $this->texto_de_operacion($primera_con_formato ? $this->con_formato(self::OPERACION) : self::OPERACION));
        $segunda = $this->planilla_pegada($conversation, $this->texto_de_operacion($segunda_con_formato ? $this->con_formato(self::OPERACION) : self::OPERACION));
        $this->assertSame((int) $primera->id, (int) $segunda->id);
        $this->assertCount(1, $segunda->filas);
        $this->assertSame(self::OPERACION, $segunda->filas[0]['valores'][Roles::OPERACION]);
    }

    public function test_la_correccion_de_operacion_usa_el_mismo_identificador()
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, $this->texto_de_operacion('#2000000000000001'));
        $antes = $planilla->filas[0]['valores'];
        $valor = $this->con_formato(self::OPERACION);
        $pedido = 'En el renglón 1 la operación es ' . $valor . '.';
        $this->del_dueno($conversation, $pedido);
        $resultado = $this->herramienta('corregir_renglon_de_planilla', [
            'renglon' => 1, 'campo' => 'operacion', 'valor' => $valor, 'lo_que_dijo_el_dueno' => $pedido,
        ], $conversation, $this->assistant($conversation));
        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $despues = $planilla->fresh()->filas[0]['valores'];
        $this->assertSame(self::OPERACION, $despues[Roles::OPERACION]);
        unset($antes[Roles::OPERACION], $despues[Roles::OPERACION]);
        $this->assertSame($antes, $despues);
    }

    public function test_las_observaciones_de_una_fila_legada_usan_la_operacion_sin_formato()
    {
        $planilla = new AiPlanilla();
        $planilla->id = 77;
        $fila = Roles::renglon(1, [self::OPERACION, '11-nov'], [Roles::OPERACION, Roles::FECHA]);
        $fila['valores'][Roles::OPERACION] = $this->con_formato(self::OPERACION);
        $texto = $this->invocar(EjecucionDePlanillaIaHelper::class, 'observaciones', [$planilla, $fila]);
        $this->assertSame('Planilla N° 77, renglón 1 · 11-nov · Op. ' . self::OPERACION, $texto);
    }

    public function operaciones_visiblemente_distintas()
    {
        return [
            ['#0007', '#007'],
            ['#OP-a-7', '#OP-A-7'],
            ['#OP-A-7', '#OPA-7'],
            ['#OP/A7', '#OPA7'],
            ['#OP-07', '#OP-O7'],
        ];
    }

    /** @dataProvider operaciones_visiblemente_distintas */
    public function test_el_cruce_no_fusiona_identificadores_visiblemente_distintos($una, $otra)
    {
        $fila = Roles::renglon(1, [$una], [Roles::OPERACION]);
        $nueva = Roles::renglon(2, [$otra], [Roles::OPERACION]);
        $this->assertNull($this->invocar(PlanillaIaHelper::class, 'renglon_que_coincide', [[$fila], $nueva]));
    }

    public function test_la_firma_ignora_solo_formato_y_detecta_un_cambio_visible_de_operacion()
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, $this->texto_de_operacion(self::OPERACION));
        $original = PlanillaIaHelper::firma($planilla, [1]);
        $filas = $planilla->filas;
        $filas[0]['valores'][Roles::OPERACION] = $this->con_formato(self::OPERACION);
        $planilla->filas = $filas;
        $this->assertSame($original, PlanillaIaHelper::firma($planilla, [1]));
        $filas[0]['valores'][Roles::OPERACION] = '#2000000000000098';
        $planilla->filas = $filas;
        $this->assertNotSame($original, PlanillaIaHelper::firma($planilla, [1]));
    }

    public function ventas_y_reimportaciones()
    {
        return [
            [true, false, false],
            [false, true, false],
            [false, false, true],
        ];
    }

    /** @dataProvider ventas_y_reimportaciones */
    public function test_reimportar_la_misma_operacion_no_propone_otra_venta($primera_con_formato, $segunda_con_formato, $observacion_legada)
    {
        $this->falsear_ia();
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation, $this->texto_de_operacion($primera_con_formato ? $this->con_formato(self::OPERACION) : self::OPERACION));
        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);
        $this->definir_para_cargar($conversation, $assistant);
        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $assistant->contenido = 'La propuesta de una venta.';
        $assistant->estado = 'listo';
        $assistant->save();
        $this->del_dueno($conversation, 'Sí');
        $this->responder($conversation, $this->assistant($conversation));
        $ventas = $this->ventas_de($planilla);
        $this->assertCount(1, $ventas);
        $venta = $ventas->first();

        if ($observacion_legada) {
            $venta->observations = str_replace('Op. ' . self::OPERACION, 'Op. ' . $this->con_formato(self::OPERACION), $venta->observations);
            $venta->save();
        }

        $cantidad_ventas = Sale::count();
        $stock = Article::where('user_id', $this->dueno->id)->orderBy('id')->pluck('stock', 'id')->all();
        $otra_conversacion = $this->conversacion();
        $otra = $this->planilla_pegada($otra_conversacion, $this->texto_de_operacion($segunda_con_formato ? $this->con_formato(self::OPERACION) : self::OPERACION));
        $this->assertSame(AiPlanilla::FILA_YA_REGISTRADA, $otra->filas[0]['estado']);
        $this->assertSame((int) $venta->id, (int) $otra->filas[0]['venta_id']);
        $this->assertSame($cantidad_ventas, Sale::count());
        $this->assertSame($stock, Article::where('user_id', $this->dueno->id)->orderBy('id')->pluck('stock', 'id')->all());
        $this->del_dueno($otra_conversacion, self::PEDIDO_PARA_CARGAR);
        $otro_assistant = $this->assistant($otra_conversacion);
        $this->definir_para_cargar($otra_conversacion, $otro_assistant);
        $rechazo = $this->herramienta('proponer_ventas_de_planilla', [], $otra_conversacion, $otro_assistant);
        $this->assertFalse($rechazo['ok']);
        $this->assertSame('planilla_sin_pendientes', $rechazo['codigo']);
    }
}
