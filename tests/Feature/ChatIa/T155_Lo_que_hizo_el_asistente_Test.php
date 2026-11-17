<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionesHechasPorElAsistenteIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessageAction;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261117 (parte B, A2) — «¿qué me cargaste / facturaste?» sale de lo que el
 * asistente EJECUTÓ (ai_message_actions confirmadas), no de la memoria ni de los resúmenes de charlas viejas.
 *
 * El caso real: Truvari, 16/11/2026, conversación 40 — listas contradictorias, nombres faltantes y la venta
 * 3105 afuera. Lo que cuida este archivo:
 *  - dos negocios y dos personas por negocio: cada negocio ve solo lo suyo; el dueño ve lo de todo su negocio
 *    y el empleado sin acceso de administrador, solo lo que hizo él;
 *  - una venta creada por el asistente aparece con su número, su cliente y su factura con CAE como están HOY;
 *  - nunca viajan `datos` ni `presentacion`, ni links con token del texto del resultado;
 *  - solo las confirmadas, y la herramienta se despacha por el registro de lectura.
 */
class T155_Lo_que_hizo_el_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $empleado;

    /** @var User */
    protected $otro_comercio;

    /** @var User */
    protected $empleado_del_otro;

    /** @var Sale */
    protected $venta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create(['name' => 'Comercio T155', 'email' => 't155-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $this->empleado = User::create(['name' => 'Empleado T155', 'email' => 't155-emp-' . uniqid() . '@test.local', 'password' => Hash::make('secret'), 'owner_id' => $this->comercio->id]);

        $this->otro_comercio = User::create(['name' => 'Otro T155', 'email' => 't155-otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        $this->empleado_del_otro = User::create(['name' => 'Empleado otro T155', 'email' => 't155-emp-otro-' . uniqid() . '@test.local', 'password' => Hash::make('secret'), 'owner_id' => $this->otro_comercio->id]);

        $cliente = DB::table('clients')->insertGetId(['name' => 'Bodega Los Andes T155', 'user_id' => $this->comercio->id, 'created_at' => now(), 'updated_at' => now()]);

        // La venta que creó el asistente, con su factura emitida (CAE).
        $this->venta = Sale::create([
            'user_id'   => $this->comercio->id,
            'num'       => 3105,
            'client_id' => $cliente,
            'total'     => 15000,
            'terminada' => 1,
            'moneda_id' => 1,
        ]);

        DB::table('afip_tickets')->insert([
            'sale_id'     => $this->venta->id,
            'cae'         => '76123456789012',
            'resultado'   => 'A',
            'cbte_letra'  => 'B',
            'punto_venta' => 3,
            'cbte_numero' => 1110,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // 1) El dueño: la venta 3105 (confirmada).
        $this->accion($this->comercio, $this->comercio, AiMessageAction::TIPO_VENTA, [
            'texto'    => 'Venta N° 3105 registrada',
            'ruta'     => ['name' => 'ventas', 'params' => new \stdClass(), 'texto' => 'Ver en Ventas'],
            'venta_id' => $this->venta->id,
            'numero'   => 3105,
            'total'    => 15000,
        ], ['firma_fiscal_ia' => 'FIRMA-SECRETA-T155', 'payload' => ['items' => [1]]]);

        // 2) El dueño: un alta de cliente con un link con token en el texto (confirmada).
        $this->accion($this->comercio, $this->comercio, AiMessageAction::TIPO_ALTA, [
            'texto'   => 'Cliente Juan Pérez T155 creado. PDF: https://ejemplo.test/pdf?token=SECRETO-T155',
            'entidad' => 'client',
            'id'      => 77,
            'nombre'  => 'Juan Pérez T155',
            'respuesta' => ['token' => 'OTRO-SECRETO-T155'],
        ]);

        // 3) El empleado: un gasto (confirmada).
        $this->accion($this->comercio, $this->empleado, AiMessageAction::TIPO_GASTO, ['texto' => 'Gasto de luz T155 registrado']);

        // 4) Una propuesta sin confirmar: no se hizo, no aparece.
        $this->accion($this->comercio, $this->comercio, AiMessageAction::TIPO_PAGO, ['texto' => 'Pago sin confirmar T155'], [], AiMessageAction::ESTADO_PROPUESTA);

        // 5) El otro negocio: lo suyo no se mezcla.
        $this->accion($this->otro_comercio, $this->empleado_del_otro, AiMessageAction::TIPO_GASTO, ['texto' => 'Gasto ajeno T155 registrado']);
        $this->accion($this->otro_comercio, $this->otro_comercio, AiMessageAction::TIPO_ALTA, ['texto' => 'Proveedor ajeno T155 creado', 'entidad' => 'provider', 'id' => 5]);
    }

    /**
     * El dueño ve todo lo de su negocio (y quién lo pidió); nada del otro negocio ni lo no confirmado.
     *
     * @group chat-ia
     * @test
     */
    public function el_dueno_ve_todo_lo_de_su_negocio_y_nada_ajeno()
    {
        $respuesta = $this->consultar($this->comercio, $this->comercio);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(3, $respuesta['acciones_en_total']);

        $textos = array_column($respuesta['acciones'], 'que_hizo');

        $this->assertContains('Venta N° 3105 registrada', $textos);
        $this->assertContains('Gasto de luz T155 registrado', $textos);
        $this->assertNotContains('Pago sin confirmar T155', $textos, 'Una propuesta no es algo que el asistente hizo.');

        foreach ($textos as $texto) {
            $this->assertStringNotContainsString('ajeno', (string) $texto, 'Lo del otro negocio no aparece.');
        }

        $gasto = $this->buscar($respuesta, 'Gasto de luz T155 registrado');
        $this->assertSame('Empleado T155', $gasto['pedido_por'], 'El dueño ve quién lo pidió.');
    }

    /**
     * El empleado sin acceso de administrador ve solo lo que el asistente hizo para él.
     *
     * @group chat-ia
     * @test
     */
    public function el_empleado_ve_solo_lo_suyo()
    {
        $respuesta = $this->consultar($this->comercio, $this->empleado);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(1, $respuesta['acciones_en_total']);
        $this->assertSame('Gasto de luz T155 registrado', $respuesta['acciones'][0]['que_hizo']);
        $this->assertArrayNotHasKey('pedido_por', $respuesta['acciones'][0]);

        // El empleado del otro negocio, igual: solo lo suyo, en su negocio.
        $ajeno = $this->consultar($this->otro_comercio, $this->empleado_del_otro);
        $this->assertSame(1, $ajeno['acciones_en_total']);
        $this->assertSame('Gasto ajeno T155 registrado', $ajeno['acciones'][0]['que_hizo']);

        // Una persona de OTRO negocio que pregunta por éste no ve nada.
        $intruso = $this->consultar($this->comercio, $this->empleado_del_otro);
        $this->assertFalse($intruso['ok']);
        $this->assertArrayNotHasKey('acciones', $intruso);
    }

    /**
     * La venta creada por el asistente aparece con su número, su cliente y su factura con CAE, como hoy.
     *
     * @group chat-ia
     * @test
     */
    public function la_venta_trae_su_numero_su_cliente_y_su_factura()
    {
        $respuesta = $this->consultar($this->comercio, $this->comercio);

        $venta = $this->buscar($respuesta, 'Venta N° 3105 registrada');

        $this->assertSame('venta', $venta['tipo']);
        $this->assertSame(3105, $venta['venta']['numero']);
        $this->assertSame('Bodega Los Andes T155', $venta['venta']['cliente']);
        $this->assertEquals(15000.0, $venta['venta']['total']);
        $this->assertFalse($venta['venta']['anulada']);
        $this->assertSame('emitida', $venta['venta']['factura']['estado']);
        $this->assertSame('Factura B 0003-00001110', $venta['venta']['factura']['comprobante']);
        $this->assertSame('76123456789012', $venta['venta']['factura']['cae']);
        $this->assertFalse($venta['en_esta_conversacion'], 'La venta se hizo en otra charla, no en la que pregunta.');
        $this->assertArrayNotHasKey('venta_id', $venta, 'El id interno solo sirve para completar la venta.');

        // Si después se anula, se informa como está HOY.
        $this->venta->delete();
        $despues = $this->buscar($this->consultar($this->comercio, $this->comercio), 'Venta N° 3105 registrada');
        $this->assertTrue($despues['venta']['anulada']);
    }

    /**
     * Nunca viajan `datos`, `presentacion`, la respuesta cruda ni los links con token.
     *
     * @group chat-ia
     * @test
     */
    public function nunca_viajan_datos_presentacion_ni_tokens()
    {
        $respuesta = $this->consultar($this->comercio, $this->comercio);

        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('FIRMA-SECRETA-T155', $json);
        $this->assertStringNotContainsString('SECRETO-T155', $json);
        $this->assertStringNotContainsString('presentacion', $json);
        $this->assertStringNotContainsString('"datos"', $json);
        $this->assertStringNotContainsString('respuesta', $json);

        $alta = $this->buscar($respuesta, 'Cliente Juan Pérez T155 creado. PDF: [link]');
        $this->assertSame('client', $alta['entidad']);
        $this->assertSame('Juan Pérez T155', $alta['nombre']);
    }

    /**
     * Se despacha por el registro de lectura, al final, y sin persona no muestra nada.
     *
     * @group chat-ia
     * @test
     */
    public function se_despacha_por_el_registro_y_sin_persona_no_muestra_nada()
    {
        $service = new AsistenteIaService();

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->empleado->id]);

        $resultados = $service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_t155',
            'name'  => 'consultar_lo_que_hizo_el_asistente',
            'input' => [],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame(1, $contenido['acciones_en_total'], 'Por el registro también se recorta por persona.');

        $nombres = $service->nombres_de_lectura();
        $this->assertSame('consultar_lo_que_hizo_el_asistente', end($nombres), 'Al final del registro (prefijo del caché).');

        $sin_persona = AccionesHechasPorElAsistenteIaHelper::consultar($this->comercio->id, null, []);
        $this->assertFalse($sin_persona['ok']);
    }

    /**
     * Revisión independiente (punto 2): una factura hecha por acción de pantalla (POST afip-ticket) cuya respuesta
     * quedó recortada igual se vincula a su venta, leyendo el sale_id de `datos` del lado del servidor, sin que
     * `datos` viaje.
     *
     * @group chat-ia
     * @test
     */
    public function la_factura_por_pantalla_con_respuesta_recortada_se_vincula_a_su_venta()
    {
        $venta = Sale::create(['user_id' => $this->comercio->id, 'num' => 3106, 'total' => 900, 'terminada' => 1, 'moneda_id' => 1]);

        DB::table('afip_tickets')->insert([
            'sale_id' => $venta->id, 'cae' => '76999999999999', 'resultado' => 'A', 'cbte_letra' => 'A',
            'punto_venta' => 3, 'cbte_numero' => 1111, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->accion($this->comercio, $this->comercio, AiMessageAction::TIPO_ACCION_PANTALLA, [
            'texto'         => 'Factura T155 por pantalla',
            'uri'           => 'api/afip-ticket',
            'status'        => 200,
            'respuesta'     => ['texto' => str_repeat('x', 200), 'recortado' => true],
            'recortado'     => true,
            'estado_fiscal' => 'pendiente_verificacion',
        ], [
            'metodo'          => 'POST',
            'ruta'            => 'afip-ticket',
            'parametros'      => [],
            'cuerpo'          => ['sale_id' => $venta->id, 'afip_tipo_comprobante_id' => 1],
            'firma_fiscal_ia' => 'FIRMA-PANTALLA-T155',
        ]);

        $respuesta = $this->consultar($this->comercio, $this->comercio);

        $factura = $this->buscar($respuesta, 'Factura T155 por pantalla');

        $this->assertSame(3106, $factura['venta']['numero'], 'La venta sale de datos.cuerpo.sale_id aunque la respuesta se recortó.');
        $this->assertSame('emitida', $factura['venta']['factura']['estado']);

        $json = json_encode($respuesta, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('FIRMA-PANTALLA-T155', $json);
        $this->assertStringNotContainsString('afip_tipo_comprobante_id', $json);
        $this->assertStringNotContainsString('"cuerpo"', $json);
    }

    // ---------------------------------------------------------------------

    /**
     * Consulta como `persona` sobre el negocio `owner`.
     *
     * @param  User  $owner
     * @param  User  $persona
     * @return array
     */
    protected function consultar(User $owner, User $persona)
    {
        $conversation = AiConversation::create(['user_id' => $owner->id, 'auth_user_id' => $persona->id]);

        return AccionesHechasPorElAsistenteIaHelper::consultar($owner->id, $conversation, [
            'desde' => now()->subDays(2)->format('Y-m-d'),
            'hasta' => now()->format('Y-m-d'),
        ]);
    }

    /**
     * La acción cuyo texto es `que_hizo`; falla si no está.
     *
     * @param  array  $respuesta
     * @param  string  $que_hizo
     * @return array
     */
    protected function buscar(array $respuesta, $que_hizo)
    {
        foreach ($respuesta['acciones'] as $accion) {
            if ($accion['que_hizo'] === $que_hizo) {
                return $accion;
            }
        }

        $this->fail('No está la acción «' . $que_hizo . '»: ' . json_encode($respuesta, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Una tarjeta del asistente con su resultado.
     *
     * @param  User  $owner
     * @param  User  $persona
     * @param  string  $tipo
     * @param  array  $resultado
     * @param  array  $datos
     * @param  string  $estado
     * @return int
     */
    protected function accion(User $owner, User $persona, $tipo, array $resultado, array $datos = [], $estado = AiMessageAction::ESTADO_CONFIRMADA)
    {
        $conversation = AiConversation::create(['user_id' => $owner->id, 'auth_user_id' => $persona->id]);

        return DB::table('ai_message_actions')->insertGetId([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => 0,
            'user_id'            => $owner->id,
            'auth_user_id'       => $persona->id,
            'tipo'               => $tipo,
            'clave'              => $tipo . ':' . uniqid(),
            'estado'             => $estado,
            'datos'              => json_encode($datos),
            'presentacion'       => json_encode(['titulo' => 'Tarjeta T155', 'renglones' => []]),
            'resultado'          => json_encode($resultado),
            'resuelta_at'        => $estado === AiMessageAction::ESTADO_CONFIRMADA ? now() : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}
