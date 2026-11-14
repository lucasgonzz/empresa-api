<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\EjecucionDePlanillaIaHelper as Ejecucion;
use App\Http\Controllers\Helpers\asistente_ia\PlanillaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RolesDePlanillaIaHelper as Roles;
use App\Models\AiMessageAction;
use App\Models\AiPlanilla;
use App\Models\Client;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Las tarjetas se reservan por separado, pero sus renglones viven en la misma planilla.
 * Estas intercalaciones reproducen dos ejecuciones sin lanzar workers ni usar otra base.
 * Los datos son inventados y las ventas usan el camino real del sistema.
 *
 * PHP 7.4: sin match, argumentos nombrados, union types ni str_contains.
 *
 * @group chat-ia
 */
class Planilla_concurrencia_Test extends PlanillaTestCase
{
    /** Una tarjeta real de la planilla, acotada a una tanda para intercalar dos ejecuciones. */
    protected function tarjeta_en_curso(array $numeros = [1], $sin_documento = false)
    {
        $conversation = $this->conversacion();
        $planilla = $this->planilla_pegada($conversation);

        if ($sin_documento) {
            $filas = $planilla->filas;
            unset($filas[0]['valores'][Roles::DOCUMENTO]);
            $planilla->filas = $filas;
            $planilla->save();
        }

        $this->del_dueno($conversation, self::PEDIDO_PARA_CARGAR);
        $assistant = $this->assistant($conversation);
        $this->definir_para_cargar($conversation, $assistant);
        $propuesta = $this->herramienta('proponer_ventas_de_planilla', [], $conversation, $assistant);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));

        $tarjeta = AiMessageAction::find((int) $propuesta['tarjeta_id']);
        $datos = $tarjeta->datos;
        $datos['renglones'] = $numeros;
        $datos['firma'] = PlanillaIaHelper::firma($planilla->fresh(), $numeros);
        $tarjeta->datos = $datos;
        $tarjeta->estado = AiMessageAction::ESTADO_EN_CURSO;
        $tarjeta->save();

        return [$conversation, $planilla->fresh(), $tarjeta];
    }

    /** Otra tarjeta que ya pasó la reserva del ejecutor, para la misma o para otra tanda. */
    protected function otra_tarjeta(AiMessageAction $tarjeta, AiPlanilla $planilla, array $numeros)
    {
        $otra = $tarjeta->replicate();
        $datos = $otra->datos;
        $datos['renglones'] = $numeros;
        $datos['firma'] = PlanillaIaHelper::firma($planilla, $numeros);
        $otra->datos = $datos;
        $otra->estado = AiMessageAction::ESTADO_EN_CURSO;
        $otra->save();

        return $otra;
    }

    /**
     * Ejecuta otra tarjeta justo cuando la primera busca si la venta ya existe.
     * Antes del arreglo ese SELECT ocurría ANTES de reservar la fila; las dos podían ejecutar.
     */
    protected function intercalar_otra_tarjeta($conversation, AiPlanilla $planilla, AiMessageAction $primera, AiMessageAction $segunda)
    {
        $conexion = DB::connection();
        $original = $conexion->getEventDispatcher();
        $temporal = clone $original;
        $intercalada = false;
        $resultado_de_la_segunda = null;
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $temporal->listen(QueryExecuted::class, function ($consulta) use (&$intercalada, &$resultado_de_la_segunda, $contexto, $segunda) {
            $sql = mb_strtolower($consulta->sql);

            if (!$intercalada && mb_strpos($sql, 'select') === 0 && mb_strpos($sql, 'from `sales`') !== false) {
                $intercalada = true;
                $resultado_de_la_segunda = Ejecucion::ejecutar($contexto, $segunda);
            }
        });

        $conexion->setEventDispatcher($temporal);

        try {
            $resultado = Ejecucion::ejecutar($contexto, $primera);
        } finally {
            $conexion->setEventDispatcher($original);
        }

        $this->assertTrue($intercalada, 'La prueba tiene que entrar en el punto real de la carrera.');
        $this->assertNotNull($resultado_de_la_segunda);

        return [$resultado, $resultado_de_la_segunda];
    }

    /** @test */
    public function dos_tarjetas_en_curso_no_se_apropian_del_mismo_renglon()
    {
        list($conversation, $planilla, $primera) = $this->tarjeta_en_curso();
        $segunda = $this->otra_tarjeta($primera, $planilla, [1]);

        list($resultado, $otro) = $this->intercalar_otra_tarjeta($conversation, $planilla, $primera, $segunda);

        $this->assertSame(1, $otro['sin_intentar']);
        $this->assertSame([], $otro['ventas']);
        $this->assertSame(0, $otro['clientes_creados']);
        $this->assertCount(1, $resultado['ventas']);
        $this->assertCount(1, $this->ventas_de($planilla));
        $this->assertSame(1, Client::where('user_id', $this->dueno->id)->where('dni', '17000001')->count());
        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);
        $this->assertSame(AiPlanilla::FILA_REGISTRADA, $fila['estado']);
        $this->assertSame((int) $resultado['ventas'][0]['venta_id'], (int) $fila['venta_id']);
    }

    /** @test */
    public function otra_tanda_espera_mientras_se_crea_un_cliente_en_la_misma_planilla()
    {
        list($conversation, $planilla, $primera) = $this->tarjeta_en_curso();
        $segunda = $this->otra_tarjeta($primera, $planilla, [2]);

        list($resultado, $otro) = $this->intercalar_otra_tarjeta($conversation, $planilla, $primera, $segunda);

        $this->assertSame(1, $otro['sin_intentar']);
        $this->assertSame([], $otro['ventas']);
        $this->assertSame(0, $otro['clientes_creados']);
        $this->assertCount(1, $resultado['ventas']);
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('dni', '14000002')->count());
        $this->assertSame(AiPlanilla::FILA_PENDIENTE, PlanillaIaHelper::fila_numero($planilla->fresh(), 2)['estado']);
    }

    /** @test */
    public function dos_homonimos_creados_despues_de_proponer_no_eligen_un_cliente_arbitrario()
    {
        list($conversation, $planilla, $tarjeta) = $this->tarjeta_en_curso([1], true);
        $nombre = PlanillaIaHelper::fila_numero($planilla, 1)['valores'][Roles::NOMBRE];

        foreach ([911301, 911302] as $numero) {
            Client::create(['user_id' => $this->dueno->id, 'name' => $nombre, 'num' => $numero]);
        }

        try {
            Ejecucion::ejecutar(ContextoDeCargaIa::de_la_conversacion($conversation), $tarjeta);
        } catch (AccionIaException $e) {
            $this->assertStringContainsString('más de un cliente', $e->getMessage());
        }

        $this->assertCount(0, $this->ventas_de($planilla), 'Una coincidencia ambigua no puede dejarle una venta a cualquiera.');
        $this->assertSame(2, Client::where('user_id', $this->dueno->id)->where('name', $nombre)->count());
        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);
        $this->assertSame(AiPlanilla::FILA_BLOQUEADA, $fila['estado']);
        $this->assertEmpty($fila['venta_id'] ?? null);
    }

    /** @test */
    public function una_correccion_entre_la_lectura_inicial_y_la_reserva_invalida_la_tarjeta()
    {
        list($conversation, $planilla, $tarjeta) = $this->tarjeta_en_curso();
        $original = AiPlanilla::getEventDispatcher();
        AiPlanilla::setEventDispatcher(clone $original);
        $corregida = false;

        AiPlanilla::retrieved(function ($leida) use ($planilla, &$corregida) {
            if (!$corregida && (int) $leida->id === (int) $planilla->id) {
                $corregida = true;
                $filas = $leida->filas;
                $filas[0]['valores'][Roles::CANTIDAD] = '3';
                DB::table('ai_planillas')->where('id', $leida->id)->update(['filas' => json_encode($filas)]);
            }
        });

        try {
            try {
                Ejecucion::ejecutar(ContextoDeCargaIa::de_la_conversacion($conversation), $tarjeta);
            } catch (AccionIaException $e) {
                $this->assertStringContainsString('cambió', $e->getMessage());
            }
        } finally {
            AiPlanilla::setEventDispatcher($original);
        }

        $this->assertTrue($corregida);
        $this->assertCount(0, $this->ventas_de($planilla), 'No se puede vender una cantidad que no figuraba en la tarjeta.');
        $this->assertEquals(3, Roles::cantidad_de(PlanillaIaHelper::fila_numero($planilla->fresh(), 1)));
    }

    /** Una copia reservada, anterior a una escritura de otro proceso. */
    protected function fila_reservada(AiPlanilla $planilla)
    {
        $filas = $planilla->filas;
        $filas[0]['en_curso'] = time();
        $filas[0]['reserva_token'] = 'reserva-de-prueba-113';
        $planilla->filas = $filas;
        $planilla->save();

        return $filas[0];
    }

    /** Un guardado tardío puede rechazar; lo que no puede hacer es pisar la fila fresca. */
    protected function intentar_guardado_tardio(AiPlanilla $planilla, array $fila, array $cambios)
    {
        $guardar = new \ReflectionMethod(Ejecucion::class, 'guardar_renglon');
        $guardar->setAccessible(true);

        try {
            $guardar->invoke(null, $planilla, $fila, $cambios);
        } catch (AccionIaException $e) {
            // El rechazo deja el resultado ajeno intacto.
        }
    }

    /** @test */
    public function un_fallo_tardio_no_borra_una_venta_que_otro_proceso_ya_guardo()
    {
        list(, $planilla) = $this->tarjeta_en_curso();
        $vieja = $this->fila_reservada($planilla);
        $filas = $planilla->filas;
        $filas[0] = array_merge($filas[0], [
            'estado' => AiPlanilla::FILA_REGISTRADA,
            'venta_id' => 911303,
            'venta_numero' => 911304,
            'en_curso' => null,
            'reserva_token' => null,
        ]);
        $planilla->filas = $filas;
        $planilla->save();

        $this->intentar_guardado_tardio($planilla, $vieja, ['estado' => AiPlanilla::FILA_BLOQUEADA, 'motivo' => 'falló la ejecución anterior']);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);
        $this->assertSame(AiPlanilla::FILA_REGISTRADA, $fila['estado']);
        $this->assertSame(911303, $fila['venta_id']);
        $this->assertSame(911304, $fila['venta_numero']);
        $this->assertEmpty($fila['motivo']);
    }

    /** @test */
    public function un_guardado_tardio_no_asocia_una_venta_a_una_fila_corregida()
    {
        list(, $planilla) = $this->tarjeta_en_curso();
        $vieja = $this->fila_reservada($planilla);
        $filas = $planilla->filas;
        $filas[0]['valores'][Roles::CANTIDAD] = '3';
        $planilla->filas = $filas;
        $planilla->save();

        $this->intentar_guardado_tardio($planilla, $vieja, ['estado' => AiPlanilla::FILA_REGISTRADA, 'venta_id' => 911305]);

        $fila = PlanillaIaHelper::fila_numero($planilla->fresh(), 1);
        $this->assertSame(AiPlanilla::FILA_PENDIENTE, $fila['estado']);
        $this->assertEmpty($fila['venta_id'] ?? null);
        $this->assertEquals(3, Roles::cantidad_de($fila));
    }
}
