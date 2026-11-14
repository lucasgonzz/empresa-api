<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TextoDePlanillaIaHelper as Texto;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;

/**
 * El resultado también sale en un único mensaje: un error extenso no puede tapar las filas fallidas
 * ni convertir una ejecución parcial en «todo listo». Datos inventados; PHP 7.4.
 *
 * @group chat-ia
 */
class Planilla_resultado_largo_Test extends PlanillaTestCase
{
    protected function accion_con_resultado(array $cambios = [])
    {
        $accion = new AiMessageAction();
        $accion->estado = AiMessageAction::ESTADO_CONFIRMADA;
        $accion->resultado = array_merge([
            'planilla_id' => 7001,
            'total' => 3000,
            'ventas' => [
                ['renglon' => 1, 'cliente' => 'Ana Prueba', 'numero' => 701, 'total' => 1000],
                ['renglon' => 2, 'cliente' => 'Bruno Prueba', 'numero' => 702, 'total' => 2000],
            ],
            'ya_registradas' => [],
            'no_cargadas' => [],
            'sin_intentar' => 0,
            'clientes_creados' => 0,
            'en_negativo' => [],
            'sin_facturar' => 'Quedan sin facturar: se facturan desde Ventas.',
        ], $cambios);

        return $accion;
    }

    protected function fallidas($desde = 6, $cantidad = 20, $multibyte = false)
    {
        $filas = [];
        $motivo = mb_substr(str_repeat($multibyte ? 'Revisión: ñandú, café y depósito. ' : 'El dato requiere revision. ', 40), 0, 500);

        for ($i = $desde; $i < $desde + $cantidad; $i++) {
            $filas[] = ['renglon' => $i, 'cliente' => 'Cliente de prueba ' . $i, 'motivo' => $motivo];
        }

        return $filas;
    }

    /** El formateo nunca puede editar el resultado completo ni el estado de la tarjeta. */
    protected function texto_sin_mutar(AiMessageAction $accion)
    {
        $antes = $accion->getAttributes();
        // El accessor arma un objeto nuevo en cada lectura: el invariante son sus valores, no su identidad.
        $resultado = json_encode($accion->resultado, JSON_UNESCAPED_UNICODE);
        $texto = Texto::resultado($accion);

        $this->assertSame($antes, $accion->getAttributes());
        $this->assertSame($resultado, json_encode($accion->resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado);
        $this->assertLessThanOrEqual(Texto::LARGO_MAXIMO, mb_strlen($texto));
        $this->assertTrue(mb_check_encoding($texto, 'UTF-8'));

        return $texto;
    }

    /** @test */
    public function un_resultado_corto_conserva_el_texto_exacto_anterior()
    {
        $accion = $this->accion_con_resultado([
            'ventas' => [['renglon' => 1, 'cliente' => 'Ana Prueba', 'numero' => 701, 'total' => 1000]],
            'total' => 1000,
            'ya_registradas' => [['renglon' => 2, 'cliente' => 'Bruno Prueba', 'numero' => 702]],
            'no_cargadas' => [['renglon' => 3, 'cliente' => 'Carla Prueba', 'motivo' => 'Falta el dato.']],
            'sin_intentar' => 1,
            'clientes_creados' => 1,
            'en_negativo' => [['nombre' => 'Bomba de prueba', 'stock' => -2]],
        ]);

        $esperado = 'Listo, cargué 1 venta de la planilla N° 7001 por ' . FormatoIaHelper::monto(1000) . ":\n\n"
            . '1. Ana Prueba: Venta N° 701, ' . FormatoIaHelper::monto(1000) . "\n\n"
            . "Ya estaban registradas, no las volví a cargar:\n- Renglón 2, Bruno Prueba: Venta N° 702\n\n"
            . "No se pudieron cargar:\n- Renglón 3, Carla Prueba: Falta el dato.\n\n"
            . "Quedaron 1 renglones sin cargar porque se terminó el tiempo: pedime seguir y los cargo sin repetir nada.\n\n"
            . "Clientes nuevos creados: 1.\n\nQuedaron con stock en negativo: Bomba de prueba (-2).\n\n"
            . 'Quedan sin facturar: se facturan desde Ventas.';

        $this->assertSame($esperado, $this->texto_sin_mutar($accion));
    }

    /** @test */
    public function veinte_motivos_largos_no_ocultan_el_exito_parcial_ni_los_renglones_fallidos()
    {
        $fallidas = $this->fallidas();
        $accion = $this->accion_con_resultado([
            'ya_registradas' => [
                ['renglon' => 3, 'cliente' => 'Carla Prueba', 'numero' => 703],
                ['renglon' => 4, 'cliente' => 'Diego Prueba', 'numero' => 704],
                ['renglon' => 5, 'cliente' => 'Eva Prueba', 'numero' => 705],
            ],
            'no_cargadas' => $fallidas,
            'sin_intentar' => 4,
            'clientes_creados' => 2,
            'en_negativo' => [['nombre' => 'Bomba de prueba', 'stock' => -2]],
        ]);

        $texto = $this->texto_sin_mutar($accion);

        $this->assertStringContainsString('Registradas: 2', $texto);
        $this->assertStringContainsString('Total registrado: ' . FormatoIaHelper::monto(3000), $texto);
        $this->assertStringContainsString('Renglones registrados: 1, 2.', $texto);
        $this->assertStringContainsString('Ya registradas: 3', $texto);
        $this->assertStringContainsString('Renglones ya registrados: 3, 4, 5.', $texto);
        $this->assertStringContainsString('No se pudieron cargar: 20', $texto);
        $this->assertStringContainsString('Renglones no cargados: ' . implode(', ', range(6, 25)) . '.', $texto);
        $this->assertStringContainsString('Sin intentar: 4', $texto);
        $this->assertStringContainsString('Clientes nuevos creados: 2', $texto);
        $this->assertStringContainsString('Stock negativo: 1', $texto);
        $this->assertStringContainsString('Quedan sin facturar', $texto);
        $this->assertStringContainsString('No repitas los renglones registrados ni los ya registrados.', $texto);
        $this->assertStringContainsString('pedime que consulte la planilla', $texto);
        $persistido = json_decode(json_encode($accion->resultado), true);
        $this->assertSame($fallidas, $persistido['no_cargadas'], 'Los motivos completos permanecen en el resultado.');
    }

    /** @test */
    public function un_resultado_con_todo_fallido_no_anuncia_ventas_registradas()
    {
        $accion = $this->accion_con_resultado(['ventas' => [], 'total' => 0, 'no_cargadas' => $this->fallidas(1)]);

        $texto = $this->texto_sin_mutar($accion);

        $this->assertStringContainsString('No cargué ninguna venta nueva', $texto);
        $this->assertStringContainsString('Registradas: 0', $texto);
        $this->assertStringContainsString('Total registrado: ' . FormatoIaHelper::monto(0), $texto);
        $this->assertStringContainsString('No se pudieron cargar: 20', $texto);
        $this->assertStringContainsString('Renglones no cargados: ' . implode(', ', range(1, 20)) . '.', $texto);
        $this->assertStringNotContainsString('Listo, cargué', $texto);
    }

    /** @test */
    public function nombres_gigantes_y_stock_largo_no_rompen_el_resumen_de_las_categorias()
    {
        $ventas = [];
        $ya = [];

        for ($i = 1; $i <= 10; $i++) {
            $ventas[] = ['renglon' => $i, 'cliente' => str_repeat('Cliente de prueba ', 300), 'numero' => 700 + $i, 'total' => 1000];
            $ya[] = ['renglon' => 10 + $i, 'cliente' => str_repeat('Otro cliente de prueba ', 300), 'numero' => 800 + $i];
        }

        $facturacion = 'Quedan sin facturar: la planilla pide 10 Factura A y 10 a consumidor final. Se facturan desde Ventas.';
        $accion = $this->accion_con_resultado([
            'ventas' => $ventas,
            'ya_registradas' => $ya,
            'total' => 10000,
            'sin_intentar' => 5,
            'clientes_creados' => 10,
            'en_negativo' => [
                ['nombre' => str_repeat('Artículo de prueba ', 300), 'stock' => -2],
                ['nombre' => str_repeat('Otro artículo de prueba ', 300), 'stock' => -3],
            ],
            'sin_facturar' => $facturacion,
        ]);

        $texto = $this->texto_sin_mutar($accion);

        $this->assertStringContainsString('Registradas: 10', $texto);
        $this->assertStringContainsString('Ya registradas: 10', $texto);
        $this->assertStringContainsString('No se pudieron cargar: 0', $texto);
        $this->assertStringContainsString('Sin intentar: 5', $texto);
        $this->assertStringContainsString('Stock negativo: 2', $texto);
        $this->assertStringContainsString($facturacion, $texto);
        $this->assertStringContainsString('Renglones registrados: ' . implode(', ', range(1, 10)) . '.', $texto);
        $this->assertStringContainsString('Renglones ya registrados: ' . implode(', ', range(11, 20)) . '.', $texto);
        $this->assertStringNotContainsString('pedime que consulte la planilla', $texto, 'No promete consultar errores inexistentes de una planilla que puede estar cerrada.');
    }

    /** @test */
    public function motivos_multibyte_conservan_el_limite_y_los_estados_sin_cortar_palabras()
    {
        $accion = $this->accion_con_resultado(['no_cargadas' => $this->fallidas(3, 20, true)]);
        $texto = $this->texto_sin_mutar($accion);

        $this->assertStringContainsString('Registradas: 2', $texto);
        $this->assertStringContainsString('No se pudieron cargar: 20', $texto);
        $this->assertStringContainsString('Renglones no cargados: ' . implode(', ', range(3, 22)) . '.', $texto);
        $this->assertStringNotContainsString('Revisión: ñandú', $texto, 'El resumen no recorta un motivo por la mitad: el detalle permanece en la acción.');
    }

    /** @test */
    public function consultar_la_planilla_activa_realmente_devuelve_los_motivos_guardados()
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);
        $filas = $planilla->filas;
        $motivo = $this->fallidas(1, 1, true)[0]['motivo'];
        $filas[0]['estado'] = AiPlanilla::FILA_BLOQUEADA;
        $filas[0]['motivo'] = $motivo;
        $planilla->filas = $filas;
        $planilla->save();

        $accion = $this->accion_con_resultado(['planilla_id' => $planilla->id, 'no_cargadas' => $this->fallidas(1, 20, true)]);
        $accion->ai_conversation_id = $conversation->id;
        $accion->ai_message_id = $planilla->ai_message_id;
        $accion->user_id = $this->dueno->id;
        $accion->auth_user_id = $this->dueno->id;
        $accion->tipo = AiMessageAction::TIPO_VENTAS_PLANILLA;
        $accion->clave = 'resultado-largo-de-prueba-117';
        $accion->datos = ['planilla_id' => $planilla->id];
        $accion->presentacion = ['titulo' => 'Resultado de prueba', 'renglones' => []];
        $accion->save();
        $antes = $accion->fresh()->getAttributes();
        $filas_antes = $planilla->fresh()->filas;

        $texto = Texto::resultado($accion);

        $this->assertLessThanOrEqual(Texto::LARGO_MAXIMO, mb_strlen($texto));
        $this->assertSame($antes, $accion->fresh()->getAttributes());
        $this->assertSame($filas_antes, $planilla->fresh()->filas);

        $consulta = PlanillaIaHelper::consultar(ContextoDeCargaIa::de_la_conversacion($conversation));
        $this->assertTrue($consulta['ok']);
        $this->assertSame((int) $planilla->id, $consulta['planilla']);
        $this->assertSame($motivo, $consulta['renglones'][0]['motivo']);
    }
}
