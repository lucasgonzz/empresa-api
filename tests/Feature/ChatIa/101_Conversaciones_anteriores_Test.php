<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConversacionesAnterioresIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeContexto;
use App\Services\AsistenteIa\HerramientasDeConversaciones;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Misión agente-memoria-y-sin-techo (30/10/2026, §2.A del plan) — la memoria de las conversaciones
 * anteriores del agente.
 *
 * EL CASO REAL (Ferre Total, WhatsApp, Pablo): el 29/10, en la conversación 23, el agente registró el
 * Pago N° 1706 a Pinturas Sudamericanas con 5 e-cheq. Al otro día, en la 28 («¿Podemos seguir con lo
 * último de ayer? ¿Lo del pago de Pinturería Sudamericana?»), contestó «No tengo el detalle de lo que
 * hablamos ayer: esta conversación no trae el historial anterior». Lo que protege este archivo:
 *
 *  - buscar_conversaciones_anteriores encuentra la charla por palabras (en los mensajes, en el título y
 *    en el resultado de una carga confirmada, con y sin tildes) y por fechas, sin la conversación actual;
 *  - leer_conversacion_anterior trae los mensajes con las tarjetas (el mismo renglón del historial), las
 *    fotos con su imagen_id y la respuesta que falló marcada; lee también la conversación ACTUAL; pagina
 *    hacia atrás con un techo de caracteres;
 *  - 🔴 la tenencia: otra persona del mismo negocio, otro negocio y las de MCP son invisibles, y una
 *    ajena contesta lo mismo que una que no existe;
 *  - el bloque del system con las últimas conversaciones (sin la actual, sin cache_control) y la regla
 *    fija en el bloque cacheado, en los tres canales;
 *  - las dos herramientas al final de build_tools() en todos los juegos, y NO en el MCP;
 *  - un turno completo (Http::fake, nada sale a la red) donde el modelo pide leer la conversación de
 *    ayer y el tool_result le trae el pago.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Conversaciones_anteriores_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_proveedor' => 'anthropic']);

        // 🔴 Una clave de mentira y Http::fake donde corre el loop: nada sale a la red.
        config([
            'services.anthropic.api_key' => 'clave-de-prueba',
            'services.deepseek.api_key'  => null,
        ]);

        $this->service = new AsistenteIaService();
    }

    /**
     * Una conversación con sus mensajes, en el momento pedido.
     *
     * @param  string|null  $titulo
     * @param  array  $mensajes  Cada uno: [rol, contenido] o [rol, contenido, estado].
     * @param  \Carbon\Carbon|null  $cuando  El momento del primer mensaje (cada uno siguiente, un minuto después).
     * @param  array  $extras  Columnas de más de la conversación (user_id, auth_user_id, origen).
     * @param  string  $canal
     * @return array{0: AiConversation, 1: array<int, AiMessage>}
     */
    protected function charla($titulo, array $mensajes, $cuando = null, array $extras = [], $canal = AiMessage::CANAL_WHATSAPP)
    {
        $cuando = is_null($cuando) ? Carbon::now()->subHours(3) : $cuando->copy();

        $conversation = AiConversation::create(array_merge([
            'user_id'         => $this->dueno->id,
            'auth_user_id'    => $this->dueno->id,
            'titulo'          => $titulo,
            'origen'          => 'whatsapp',
            'created_at'      => $cuando,
            'updated_at'      => $cuando,
            'last_message_at' => $cuando->copy()->addMinutes(max(0, count($mensajes) - 1)),
        ], $extras));

        $creados = [];

        foreach ($mensajes as $i => $mensaje) {
            $momento = $cuando->copy()->addMinutes($i);

            $creados[] = AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'rol'                => $mensaje[0],
                'contenido'          => $mensaje[1],
                'estado'             => isset($mensaje[2]) ? $mensaje[2] : 'listo',
                'canal'              => $canal,
                'created_at'         => $momento,
                'updated_at'         => $momento,
            ]);
        }

        return [$conversation, $creados];
    }

    /**
     * Una tarjeta colgada de un mensaje del asistente.
     *
     * @param  AiMessage  $mensaje
     * @param  string  $estado
     * @param  string|null  $texto_del_resultado
     * @param  string  $tipo
     * @return AiMessageAction
     */
    protected function tarjeta(AiMessage $mensaje, $estado, $texto_del_resultado = null, $tipo = AiMessageAction::TIPO_PAGO)
    {
        $conversation = AiConversation::find($mensaje->ai_conversation_id);

        return AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $mensaje->id,
            'user_id'            => $conversation->user_id,
            'auth_user_id'       => $conversation->auth_user_id,
            'tipo'               => $tipo,
            'clave'              => 'zz101-' . uniqid(),
            'estado'             => $estado,
            'datos'              => [],
            'presentacion'       => [
                'titulo'    => 'Pago a proveedor',
                'renglones' => [['etiqueta' => 'Proveedor', 'valor' => 'Pinturas Sudamericanas']],
            ],
            'resultado'          => is_null($texto_del_resultado) ? null : ['texto' => $texto_del_resultado],
        ]);
    }

    /**
     * Una foto que mandó la persona (solo la fila: la lectura usa su id, no el archivo).
     *
     * @param  AiMessage  $mensaje
     * @param  int  $orden
     * @return AiMessageImagen
     */
    protected function foto(AiMessage $mensaje, $orden)
    {
        return AiMessageImagen::create([
            'ai_message_id' => $mensaje->id,
            'user_id'       => $this->dueno->id,
            'orden'         => $orden,
            'path'          => 'asistente_imagenes/zz101/' . $mensaje->id . '/' . $orden . '.webp',
            'mime'          => 'image/webp',
            'bytes'         => 10,
        ]);
    }

    /**
     * La CONV 23 de Ferre Total (ayer): el pago con los 5 e-cheq, confirmado.
     *
     * @return array{0: AiConversation, 1: AiMessageAction}
     */
    protected function conversacion_del_pago()
    {
        list($conversation, $mensajes) = $this->charla('Pago a Pinturería Sudamericana', [
            ['user', 'Cargame el pago a Pinturería Sudamericana con los 5 e-cheq del Galicia'],
            ['assistant', 'Te dejo el pago armado para que lo confirmes.'],
            ['user', 'Sí'],
            ['assistant', 'Quedó hecho.'],
        ], Carbon::now()->subDay()->setTime(15, 10));

        $tarjeta = $this->tarjeta($mensajes[1], AiMessageAction::ESTADO_CONFIRMADA, 'Pago N° 1706 registrado a Pinturas Sudamericanas por $1.250.000 con 5 e-cheq.');

        return [$conversation, $tarjeta];
    }

    /**
     * La CONV 28 de Ferre Total (hoy): la pregunta por lo de ayer y el assistant que se está generando.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_de_hoy()
    {
        list($conversation) = $this->charla('Seguimos con lo de ayer', [
            ['user', '¿Podemos seguir con lo último de ayer? ¿Lo del pago de Pinturería Sudamericana?'],
        ], Carbon::now()->subMinutes(1));

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
            'canal'                => AiMessage::CANAL_WHATSAPP,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Corre una de las dos herramientas por el despacho real del loop y devuelve lo que ve el modelo.
     *
     * @param  AiConversation  $conversation
     * @param  string  $herramienta
     * @param  array  $input
     * @param  AiMessage|null  $assistant
     * @return array
     */
    protected function herramienta_de_conversaciones(AiConversation $conversation, $herramienta, array $input, $assistant = null)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_zz101_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertCount(1, $resultados);
        $this->assertArrayNotHasKey('is_error', $resultados[0], 'No es una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * @param  AiConversation  $conversation
     * @param  array  $input
     * @return array
     */
    protected function buscar(AiConversation $conversation, array $input)
    {
        return $this->herramienta_de_conversaciones($conversation, HerramientasDeConversaciones::BUSCAR, $input);
    }

    /**
     * @param  AiConversation  $conversation
     * @param  array  $input
     * @return array
     */
    protected function leer(AiConversation $conversation, array $input)
    {
        return $this->herramienta_de_conversaciones($conversation, HerramientasDeConversaciones::LEER, $input);
    }

    /**
     * Los conversacion_id que devolvió una búsqueda.
     *
     * @param  array  $respuesta
     * @return array<int, int>
     */
    protected function ids_de(array $respuesta)
    {
        return array_column($respuesta['conversaciones'], 'conversacion_id');
    }

    /**
     * 🔴 CONV 28: «¿lo del pago de Pinturería Sudamericana?» encuentra la CONV 23 de ayer, con el pago y
     * dónde se habló, y nunca la conversación actual (que también lo nombra).
     *
     * @test
     */
    public function la_busqueda_por_texto_encuentra_el_pago_de_ayer_y_no_la_actual()
    {
        list($del_pago) = $this->conversacion_del_pago();
        list($otra) = $this->charla('Ventas de hoy', [
            ['user', '¿Cuánto vendí hoy?'],
            ['assistant', 'Hoy vendiste $350.000 en 42 ventas.'],
        ], Carbon::now()->subHours(5));
        list($hoy) = $this->conversacion_de_hoy();

        $respuesta = $this->buscar($hoy, ['texto' => 'Sudamericana']);

        $this->assertSame([$del_pago->id], $this->ids_de($respuesta), 'Solo la del pago: ni la de ventas ni esta.');
        $this->assertSame(1, $respuesta['encontradas']);

        $encontrada = $respuesta['conversaciones'][0];

        $this->assertSame('Pago a Pinturería Sudamericana', $encontrada['titulo']);
        $this->assertSame(AiMessage::CANAL_WHATSAPP, $encontrada['canal']);
        $this->assertSame(4, $encontrada['mensajes']);
        $this->assertSame(['Pago N° 1706 registrado a Pinturas Sudamericanas por $1.250.000 con 5 e-cheq.'], $encontrada['cargas']);
        $this->assertNotEmpty($encontrada['coincidencias']);
        $this->assertStringStartsWith('persona: ', $encontrada['coincidencias'][0]);
        $this->assertStringContainsString('Pinturería Sudamericana', $encontrada['coincidencias'][0]);
        $this->assertLessThanOrEqual(ConversacionesAnterioresIaHelper::LARGO_DE_COINCIDENCIA + mb_strlen('persona: '), mb_strlen($encontrada['coincidencias'][0]));
        $this->assertSame(Carbon::now()->subDay()->format('d/m/Y') . ' 15:10', $encontrada['empezo']);

        // Con y sin tilde, en mayúsculas, y con palabras vacías alrededor: la misma.
        $this->assertSame([$del_pago->id], $this->ids_de($this->buscar($hoy, ['texto' => 'pintureria'])));
        $this->assertSame([$del_pago->id], $this->ids_de($this->buscar($hoy, ['texto' => 'PINTURERÍA'])));
        $this->assertSame([$del_pago->id], $this->ids_de($this->buscar($hoy, ['texto' => 'el pago de la Sudamericana'])));

        // Todas las palabras tienen que estar: el pago no habla de ventas.
        $ninguna = $this->buscar($hoy, ['texto' => 'Sudamericana ventas']);

        $this->assertSame([], $ninguna['conversaciones']);
        $this->assertSame(0, $ninguna['encontradas']);
        $this->assertStringContainsString('No encontré', $ninguna['nota']);

        // Sin nada, las más recientes primero (y sin esta).
        $this->assertSame([$otra->id, $del_pago->id], $this->ids_de($this->buscar($hoy, [])));
    }

    /**
     * El número del pago y una palabra con tilde que SOLO está en el resultado de la tarjeta también la
     * encuentran. 🔴 La columna `resultado` es JSON escapado por el cast de Eloquent («Fumigación»):
     * sin el segundo LIKE, una palabra con tilde nunca la encontraría.
     *
     * @test
     */
    public function la_busqueda_mira_el_resultado_de_las_cargas_confirmadas()
    {
        list($del_pago) = $this->conversacion_del_pago();

        list($del_gasto, $mensajes) = $this->charla(null, [
            ['user', 'Cargá el gasto del bicho que vino a la mañana'],
            ['assistant', 'Te dejé el gasto.'],
        ], Carbon::now()->subHours(6));
        $gasto = $this->tarjeta($mensajes[1], AiMessageAction::ESTADO_CONFIRMADA, 'Gasto N° 88 registrado: Fumigación del depósito por $45.000.', AiMessageAction::TIPO_GASTO);

        // Una tarjeta NO confirmada con la misma palabra no cuenta (no se hizo).
        list($propuesta, $de_la_propuesta) = $this->charla('Otra cosa', [
            ['user', 'Hola'],
            ['assistant', 'Te dejé la tarjeta.'],
        ], Carbon::now()->subHours(7));
        $this->tarjeta($de_la_propuesta[1], AiMessageAction::ESTADO_PROPUESTA, 'Fumigación 1706 de prueba');

        list($hoy) = $this->conversacion_de_hoy();

        $this->assertStringContainsString('Fumigaci\\u00f3n', (string) DB::table('ai_message_actions')->where('id', $gasto->id)->value('resultado'), 'Así queda guardado: escapado.');

        $this->assertSame([$del_gasto->id], $this->ids_de($this->buscar($hoy, ['texto' => 'fumigación'])));
        $this->assertSame([$del_pago->id], $this->ids_de($this->buscar($hoy, ['texto' => '1706'])), 'El número del pago está solo en el resultado.');

        // Sin título, la conversación dice cómo empezó.
        $this->assertSame('Cargá el gasto del bicho que vino a la mañana', $this->buscar($hoy, ['texto' => 'fumigación'])['conversaciones'][0]['titulo']);
    }

    /**
     * Por fechas: «lo de ayer» son las conversaciones con mensajes ayer. Una fecha mal escrita vuelve
     * con el formato, no se ignora callada.
     *
     * @test
     */
    public function la_busqueda_por_fechas()
    {
        list($ayer) = $this->conversacion_del_pago();
        list($anteayer) = $this->charla('Stock de pinturas', [
            ['user', '¿Cuánto látex me queda?'],
            ['assistant', 'Te quedan 12 latas.'],
        ], Carbon::now()->subDays(2)->setTime(10, 0));
        list($hoy) = $this->conversacion_de_hoy();

        $dia_de_ayer = Carbon::now()->subDay()->format('Y-m-d');

        $this->assertSame([$ayer->id], $this->ids_de($this->buscar($hoy, ['desde' => $dia_de_ayer, 'hasta' => $dia_de_ayer])));
        $this->assertSame([$ayer->id, $anteayer->id], $this->ids_de($this->buscar($hoy, ['hasta' => $dia_de_ayer])));
        $this->assertSame([$ayer->id], $this->ids_de($this->buscar($hoy, ['desde' => $dia_de_ayer])), 'La actual nunca aparece.');
        $this->assertSame([$anteayer->id], $this->ids_de($this->buscar($hoy, ['texto' => 'látex', 'hasta' => $dia_de_ayer])));

        $mal = $this->buscar($hoy, ['desde' => '29/10/2026']);

        $this->assertArrayHasKey('error', $mal);
        $this->assertStringContainsString('AAAA-MM-DD', $mal['error']);

        // El límite se respeta.
        $this->assertCount(1, $this->buscar($hoy, ['limite' => 1])['conversaciones']);
        $this->assertSame(2, $this->buscar($hoy, ['limite' => 1])['encontradas'], 'encontradas es el total, no lo que entró.');
    }

    /**
     * 🔴 LA TENENCIA. Un empleado del mismo negocio, otro negocio y una conversación de MCP de la misma
     * persona: nada de eso aparece en la búsqueda, y leerlos por id contesta exactamente lo mismo que un
     * id que no existe. Y al revés: el empleado no ve las del dueño.
     *
     * @test
     */
    public function solo_se_ven_las_conversaciones_de_la_misma_persona_del_mismo_negocio()
    {
        $empleado = User::create([
            'name'     => 'Empleado ZZ101',
            'email'    => 'zz101-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        $otro_negocio = User::create([
            'name'         => 'Otro negocio ZZ101',
            'company_name' => 'Otro negocio ZZ101',
            'email'        => 'zz101-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $pedido = [['user', 'El pago a Pinturería Sudamericana'], ['assistant', 'Listo el pago a Sudamericana.']];

        list($del_dueno) = $this->conversacion_del_pago();
        list($del_empleado) = $this->charla('Pago del empleado', $pedido, null, ['auth_user_id' => $empleado->id]);
        list($de_otro) = $this->charla('Pago de otro negocio', $pedido, null, ['user_id' => $otro_negocio->id, 'auth_user_id' => $otro_negocio->id]);
        list($de_mcp) = $this->charla('Registro MCP', $pedido, null, ['origen' => AiConversation::ORIGEN_MCP], AiMessage::CANAL_MCP);
        list($hoy) = $this->conversacion_de_hoy();

        $this->assertSame([$del_dueno->id], $this->ids_de($this->buscar($hoy, ['texto' => 'Sudamericana'])));
        $this->assertSame([$del_dueno->id], $this->ids_de($this->buscar($hoy, [])));

        $inexistente = (int) AiConversation::max('id') + 1000;

        foreach ([$del_empleado, $de_otro, $de_mcp] as $ajena) {
            $this->assertSame(['error' => 'No encontré esa conversación entre las tuyas.'], $this->leer($hoy, ['conversacion_id' => $ajena->id]), 'La #' . $ajena->id . ' no es de esta persona.');
        }

        $this->assertSame(['error' => 'No encontré esa conversación entre las tuyas.'], $this->leer($hoy, ['conversacion_id' => $inexistente]), 'Ajena e inexistente contestan igual.');

        // Del lado del empleado: ve la suya y nunca la del dueño.
        $this->assertSame([], $this->ids_de($this->buscar($del_empleado, ['texto' => 'Sudamericana'])), 'La suya es la actual: no aparece.');
        $this->assertSame(['error' => 'No encontré esa conversación entre las tuyas.'], $this->leer($del_empleado, ['conversacion_id' => $del_dueno->id]));
        $this->assertSame($del_empleado->id, $this->leer($del_empleado, [])['conversacion_id']);

        // Y el bloque del system tampoco las nombra.
        $bloque = ConversacionesAnterioresIaHelper::bloque_para_prompt($hoy);

        $this->assertStringContainsString('#' . $del_dueno->id . ' ', $bloque);

        foreach ([$del_empleado, $de_otro, $de_mcp] as $ajena) {
            $this->assertStringNotContainsString('#' . $ajena->id . ' ', $bloque);
        }
    }

    /**
     * La lectura: en orden, quién dijo qué, las tarjetas con el MISMO renglón del historial (sin las
     * descartadas), las fotos con su imagen_id y la respuesta que falló marcada.
     *
     * @test
     */
    public function leer_trae_los_mensajes_con_las_tarjetas_las_fotos_y_lo_que_fallo()
    {
        list($conversation, $mensajes) = $this->charla('Pago con cheques', [
            ['user', 'Te mando los cheques del pago a Sudamericana'],
            ['assistant', 'Te dejo el pago armado con los 5 e-cheq.'],
            ['user', 'Hacele un recordatorio a cada uno'],
            ['assistant', 'La consulta se hizo larga y no llegué a terminarla.', 'error'],
        ], Carbon::now()->subDay()->setTime(23, 40));

        $fotos = [$this->foto($mensajes[0], 1), $this->foto($mensajes[0], 2)];
        $confirmada = $this->tarjeta($mensajes[1], AiMessageAction::ESTADO_CONFIRMADA, 'Pago N° 1706 registrado a Pinturas Sudamericanas.');
        $this->tarjeta($mensajes[3], AiMessageAction::ESTADO_DESCARTADA, null, AiMessageAction::TIPO_TAREA_NUEVA);

        list($hoy) = $this->conversacion_de_hoy();

        $leida = $this->leer($hoy, ['conversacion_id' => $conversation->id]);

        $this->assertSame($conversation->id, $leida['conversacion_id']);
        $this->assertSame('Pago con cheques', $leida['titulo']);
        $this->assertFalse($leida['es_esta_conversacion']);
        $this->assertFalse($leida['hay_anteriores']);
        $this->assertNull($leida['antes_de_mensaje_para_seguir']);
        $this->assertStringContainsString('volvé a consultarlos', $leida['nota']);
        $this->assertSame(Carbon::now()->subDay()->format('d/m/Y') . ' 23:43', $leida['termino']);

        $this->assertSame(array_map(function ($m) {
            return $m->id;
        }, $mensajes), array_column($leida['mensajes'], 'id'), 'Todos, en orden cronológico.');
        $this->assertSame(['persona', 'asistente', 'persona', 'asistente'], array_column($leida['mensajes'], 'rol'));

        $primero = $leida['mensajes'][0];
        $this->assertSame('Te mando los cheques del pago a Sudamericana', $primero['texto']);
        $this->assertSame('[2 fotos adjuntas] [imagen_id ' . $fotos[0]->id . ', ' . $fotos[1]->id . ']', $primero['fotos'], 'La misma marca que el historial: se reusan por su id.');
        $this->assertSame(AiMessage::CANAL_WHATSAPP, $primero['canal']);
        $this->assertSame(Carbon::now()->subDay()->format('d/m/Y') . ' 23:40', $primero['fecha']);

        $this->assertNull($leida['mensajes'][1]['fotos']);
        $this->assertSame([AccionesIaHelper::linea_de_historial($confirmada->fresh())], $leida['mensajes'][1]['cargas'], 'El mismo renglón que el historial.');
        $this->assertStringContainsString('estado: confirmada (Pago N° 1706 registrado a Pinturas Sudamericanas.)', $leida['mensajes'][1]['cargas'][0]);

        $fallida = $leida['mensajes'][3];
        $this->assertStringStartsWith('[Esta respuesta falló', $fallida['texto']);
        $this->assertStringContainsString('La consulta se hizo larga', $fallida['texto']);
        $this->assertSame([], $fallida['cargas'], 'La tarjeta descartada no se muestra: la persona nunca la vio.');

        // El id como lo ve en la lista («#27») también sirve; un texto que no es un número no abre esta.
        $this->assertSame($conversation->id, $this->leer($hoy, ['conversacion_id' => '#' . $conversation->id])['conversacion_id']);
        $this->assertSame($conversation->id, $this->leer($hoy, ['conversacion_id' => (string) $conversation->id])['conversacion_id']);
        $this->assertArrayHasKey('error', $this->leer($hoy, ['conversacion_id' => 'la de ayer']));

        // Una sola foto: «[Foto adjunta]».
        list($con_una, $de_con_una) = $this->charla('Una foto', [['user', 'Mirá esta']]);
        $una = $this->foto($de_con_una[0], 1);

        $this->assertSame('[Foto adjunta] [imagen_id ' . $una->id . ']', $this->leer($hoy, ['conversacion_id' => $con_una->id])['mensajes'][0]['fotos']);
    }

    /**
     * Lee también la conversación ACTUAL (sin conversacion_id, o con el suyo): sirve para releer su
     * principio. El mensaje que se está generando no viaja.
     *
     * @test
     */
    public function leer_acepta_la_conversacion_actual()
    {
        list($hoy, $assistant) = $this->conversacion_de_hoy();

        foreach ([[], ['conversacion_id' => $hoy->id]] as $input) {
            $leida = $this->herramienta_de_conversaciones($hoy, HerramientasDeConversaciones::LEER, $input, $assistant);

            $this->assertSame($hoy->id, $leida['conversacion_id']);
            $this->assertTrue($leida['es_esta_conversacion']);
            $this->assertCount(1, $leida['mensajes'], 'Sin el assistant pendiente.');
            $this->assertSame('persona', $leida['mensajes'][0]['rol']);
            $this->assertStringContainsString('Es ESTA conversación', $leida['nota']);
        }
    }

    /**
     * 🔴 El techo de caracteres y la paginación hacia atrás: la primera lectura trae los más nuevos que
     * entran; con antes_de_mensaje_para_seguir llegan los de antes, sin repetir ni saltear ninguno. Un
     * mensaje larguísimo se recorta.
     *
     * @test
     */
    public function leer_pagina_hacia_atras_con_el_techo_de_caracteres()
    {
        $mensajes = [];

        for ($i = 1; $i <= 30; $i++) {
            $mensajes[] = [$i % 2 ? 'user' : 'assistant', str_pad('Mensaje ' . $i . ' ', 1000, 'x')];
        }

        list($larga, $creados) = $this->charla('Charla larga', $mensajes, Carbon::now()->subDay());
        list($hoy) = $this->conversacion_de_hoy();

        $primera = $this->leer($hoy, ['conversacion_id' => $larga->id]);

        $total = 0;

        foreach ($primera['mensajes'] as $mensaje) {
            $total += mb_strlen($mensaje['texto']);
        }

        $this->assertLessThanOrEqual(ConversacionesAnterioresIaHelper::TECHO_CARACTERES_DE_LECTURA, $total);
        $this->assertCount(16, $primera['mensajes'], '16 de 1.000 caracteres entran en 16.000.');
        $this->assertSame($creados[29]->id, $primera['mensajes'][15]['id'], 'Los más nuevos.');
        $this->assertTrue($primera['hay_anteriores']);
        $this->assertSame($primera['mensajes'][0]['id'], $primera['antes_de_mensaje_para_seguir']);
        $this->assertStringContainsString('antes_de_mensaje = ' . $primera['mensajes'][0]['id'], $primera['nota']);

        $segunda = $this->leer($hoy, ['conversacion_id' => $larga->id, 'antes_de_mensaje' => $primera['antes_de_mensaje_para_seguir']]);

        $this->assertCount(14, $segunda['mensajes']);
        $this->assertFalse($segunda['hay_anteriores']);
        $this->assertNull($segunda['antes_de_mensaje_para_seguir']);
        $this->assertSame(
            array_map(function ($m) {
                return $m->id;
            }, $creados),
            array_merge(array_column($segunda['mensajes'], 'id'), array_column($primera['mensajes'], 'id')),
            'Entre las dos, todos, una sola vez y en orden.'
        );

        // Antes del primero no hay nada.
        $vacia = $this->leer($hoy, ['conversacion_id' => $larga->id, 'antes_de_mensaje' => $creados[0]->id]);
        $this->assertSame([], $vacia['mensajes']);
        $this->assertFalse($vacia['hay_anteriores']);

        // Un mensaje de 5.000 caracteres viaja recortado.
        list($con_uno_largo) = $this->charla('Un listado', [['assistant', str_repeat('Tornillo drywall 6x1. ', 250)]]);
        $texto = $this->leer($hoy, ['conversacion_id' => $con_uno_largo->id])['mensajes'][0]['texto'];

        $this->assertSame(ConversacionesAnterioresIaHelper::TECHO_CARACTERES_POR_MENSAJE, mb_strlen($texto));
        $this->assertStringEndsWith('…', $texto);
    }

    /**
     * El bloque del system: sin conversaciones previas no va; con previas, va sin cache_control,
     * después del cacheado y antes del contexto de fondo, con hasta 8 de los últimos 30 días, sin la
     * actual, sin las vacías y sin las más viejas.
     *
     * @test
     */
    public function el_bloque_del_system_con_las_ultimas_conversaciones()
    {
        list($hoy) = $this->conversacion_de_hoy();

        foreach ($this->service->build_system_payload($hoy, $this->dueno, true, true) as $bloque) {
            $this->assertStringNotContainsString('Tus últimas conversaciones', $bloque['text'], 'Sin conversaciones previas no va.');
        }

        list($del_pago) = $this->conversacion_del_pago();
        list($vieja) = $this->charla('Del mes pasado', [['user', 'Hola']], Carbon::now()->subDays(40));
        $vacia = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id, 'titulo' => 'Vacía', 'last_message_at' => Carbon::now()]);
        list($del_sistema) = $this->charla(null, [['user', '¿Cuánto le debo a Rex?'], ['assistant', 'Le debés $80.000.']], Carbon::now()->subHours(2), ['origen' => 'usuario'], AiMessage::CANAL_SISTEMA);

        $hoy->contexto = 'Datos ya calculados: 3 líneas';
        $hoy->save();

        $system = $this->service->build_system_payload($hoy, $this->dueno, true, true);

        $indice = null;

        foreach ($system as $i => $bloque) {
            if (strpos($bloque['text'], 'Tus últimas conversaciones con esta persona') === 0) {
                $indice = $i;
            }
        }

        $this->assertNotNull($indice, 'Con conversaciones previas va el bloque.');
        $this->assertGreaterThan(0, $indice, 'Después del cacheado.');
        $this->assertArrayNotHasKey('cache_control', $system[$indice]);
        $this->assertStringStartsWith('Contexto de fondo de esta conversación', $system[count($system) - 1]['text'], 'El contexto de fondo sigue último.');
        $this->assertSame($indice + 1, count($system) - 1, 'Justo antes del contexto de fondo.');

        $texto = $system[$indice]['text'];

        $this->assertStringContainsString('- #' . $del_sistema->id . ' · ', $texto);
        $this->assertStringContainsString('«¿Cuánto le debo a Rex?» · sistema', $texto, 'Sin título: cómo empezó.');
        $this->assertStringContainsString(
            '- #' . $del_pago->id . ' · ' . Carbon::now()->subDay()->format('d/m') . ' 15:13 · «Pago a Pinturería Sudamericana» · WhatsApp',
            $texto
        );
        $this->assertTrue(strpos($texto, '#' . $del_sistema->id . ' ') < strpos($texto, '#' . $del_pago->id . ' '), 'La más reciente primero.');
        $this->assertStringNotContainsString('#' . $hoy->id . ' ', $texto, 'Sin la actual.');
        $this->assertStringNotContainsString('#' . $vieja->id . ' ', $texto, 'Sin las de más de 30 días.');
        $this->assertStringNotContainsString('#' . $vacia->id . ' ', $texto, 'Sin las vacías.');
        $this->assertStringContainsString('leer_conversacion_anterior', $texto);

        // Hasta 8.
        for ($i = 0; $i < 10; $i++) {
            $this->charla('Charla ' . $i, [['user', 'Hola ' . $i]], Carbon::now()->subMinutes(30 + $i));
        }

        $lineas = preg_grep('/^- #\d+ · /', explode("\n", ConversacionesAnterioresIaHelper::bloque_para_prompt($hoy)));

        $this->assertCount(ConversacionesAnterioresIaHelper::CONVERSACIONES_EN_EL_PROMPT, $lineas);
    }

    /**
     * La regla fija va en el bloque CACHEADO, en los tres canales, con y sin acciones.
     *
     * @test
     */
    public function la_regla_va_en_el_bloque_cacheado_en_todos_los_canales()
    {
        list($hoy) = $this->conversacion_de_hoy();

        $casos = [
            'sistema sin acciones' => [false, false, false],
            'sistema'              => [true, false, false],
            'whatsapp'             => [true, true, false],
            'app'                  => [true, false, true],
        ];

        foreach ($casos as $caso => $flags) {
            $system = $this->service->build_system_payload($hoy, $this->dueno, $flags[0], $flags[1], $flags[2]);

            $this->assertEquals(['type' => 'ephemeral'], $system[0]['cache_control']);
            $this->assertStringContainsString('no digas que no tenés el historial ni le', $system[0]['text'], $caso);
            $this->assertStringContainsString('leer_conversacion_anterior', $system[0]['text'], $caso);
            $this->assertStringContainsString('buscar_conversaciones_anteriores', $system[0]['text'], $caso);
            $this->assertSame(1, substr_count($system[0]['text'], 'Lo que hablaste con esta persona en OTRAS conversaciones'), $caso . ': una sola vez.');
        }
    }

    /**
     * Las dos herramientas van AL FINAL de build_tools() en todos los juegos (el marcador de caché pasa
     * a la última), y el MCP no las tiene.
     *
     * @test
     */
    public function las_dos_herramientas_van_al_final_en_todos_los_canales_y_no_en_el_mcp()
    {
        $juegos = [
            'sin dueño'          => $this->service->build_tools(),
            'sistema'            => $this->service->build_tools(false, false, $this->dueno),
            'sistema + acciones' => $this->service->build_tools(true, false, $this->dueno),
            'whatsapp'           => $this->service->build_tools(true, true, $this->dueno),
            'app'                => $this->service->build_tools(true, false, $this->dueno, true),
        ];

        foreach ($juegos as $juego => $tools) {
            $nombres = array_column($tools, 'name');

            $this->assertSame(HerramientasDeConversaciones::nombres(), array_slice($nombres, -2), $juego . ': al final.');
            $this->assertSame(1, count(array_keys($nombres, HerramientasDeConversaciones::LEER)), $juego . ': una sola vez.');
            $this->assertEquals(['type' => 'ephemeral'], $tools[count($tools) - 1]['cache_control'], $juego);
            $this->assertSame(1, count(array_filter(array_column($tools, 'cache_control'))), $juego . ': un solo marcador.');
        }

        // Las de la memoria siguen antes (su prefijo no cambia).
        $sistema = array_column($juegos['sistema'], 'name');
        $this->assertSame(array_merge($this->service->nombres_de_lectura(), HerramientasDeContexto::nombres(), HerramientasDeConversaciones::nombres()), $sistema);

        $this->assertSame([], array_values(array_intersect(HerramientasDeConversaciones::nombres(), McpHerramientasHelper::nombres())), 'El MCP no las tiene.');
    }

    /**
     * 🔴 EL TURNO COMPLETO (CONV 28): el modelo ve la conversación de ayer en la lista, pide leerla y el
     * tool_result le trae el pago con su tarjeta. Nada sale a la red.
     *
     * @test
     */
    public function el_turno_lee_la_conversacion_de_ayer_y_el_tool_result_trae_el_pago()
    {
        list($del_pago) = $this->conversacion_del_pago();
        list($hoy, $assistant) = $this->conversacion_de_hoy();

        $respuesta_final = 'Encontré lo de ayer: el Pago N° 1706 a Pinturas Sudamericanas tiene 5 e-cheq. ¿Qué querés hacer con los cheques?';

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [[
                        'type'  => 'tool_use',
                        'id'    => 'toolu_zz101_leer',
                        'name'  => HerramientasDeConversaciones::LEER,
                        'input' => ['conversacion_id' => $del_pago->id],
                    ]],
                    'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
                ], 200)
                ->push([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => $respuesta_final]],
                    'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
                ], 200),
        ]);

        $texto = $this->service->responder($hoy, $assistant);

        $this->assertSame($respuesta_final, $texto);
        $this->assertCount(2, Http::recorded());

        $primero = Http::recorded()[0][0]->data();
        $system = implode("\n", array_column($primero['system'], 'text'));

        $this->assertStringContainsString('- #' . $del_pago->id . ' · ', $system, 'El modelo sabe qué abrir.');
        $this->assertStringContainsString('no digas que no tenés el historial', $system);
        $this->assertContains(HerramientasDeConversaciones::LEER, array_column($primero['tools'], 'name'));
        $this->assertContains(HerramientasDeConversaciones::BUSCAR, array_column($primero['tools'], 'name'));

        $segundo = Http::recorded()[1][0]->data();
        $ultimo = $segundo['messages'][count($segundo['messages']) - 1];

        $this->assertSame('user', $ultimo['role']);
        $this->assertSame('tool_result', $ultimo['content'][0]['type']);
        $this->assertSame('toolu_zz101_leer', $ultimo['content'][0]['tool_use_id']);
        $this->assertArrayNotHasKey('is_error', $ultimo['content'][0]);

        $resultado = json_decode($ultimo['content'][0]['content'], true);

        $this->assertSame($del_pago->id, $resultado['conversacion_id']);
        $this->assertStringContainsString('Cargame el pago a Pinturería Sudamericana', $resultado['mensajes'][0]['texto']);
        $this->assertStringContainsString('Pago N° 1706 registrado a Pinturas Sudamericanas', $resultado['mensajes'][1]['cargas'][0]);
    }

    // ---------------------------------------------------------------------
    // R2 de la ronda de arreglos: la guarda de la carga prometida y lo leído de otra conversación
    // ---------------------------------------------------------------------

    /**
     * Una conversación de HOY en el panel (canal sistema, con acciones: ahí corre la guarda de la carga
     * prometida) con el pedido de la persona y el assistant que se está generando.
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function pedido_en_el_panel($pedido)
    {
        list($conversation) = $this->charla('Hoy en el panel', [['user', $pedido]], Carbon::now()->subMinutes(1), ['origen' => 'usuario'], AiMessage::CANAL_SISTEMA);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
            'canal'                => AiMessage::CANAL_SISTEMA,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * El doble de Anthropic: con `$leer`, la primera llamada pide leer esa conversación; después contesta
     * SIEMPRE `$texto` (también en un reintento de la guarda). Devuelve el contador de llamadas.
     *
     * @param  int|null  $leer  El conversacion_id a leer, o null para contestar de una.
     * @param  string  $texto
     * @return \stdClass  {llamadas: int}
     */
    protected function modelo_que_lee_y_contesta($leer, $texto)
    {
        $contador = new \stdClass();
        $contador->llamadas = 0;

        Http::fake(function ($request) use ($contador, $leer, $texto) {
            $contador->llamadas++;

            if ($contador->llamadas === 1 && !is_null($leer)) {
                return Http::response([
                    'model'       => 'claude-modelo-fake',
                    'stop_reason' => 'tool_use',
                    'content'     => [[
                        'type'  => 'tool_use',
                        'id'    => 'toolu_zz101_r2',
                        'name'  => HerramientasDeConversaciones::LEER,
                        'input' => ['conversacion_id' => $leer],
                    ]],
                    'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200);
            }

            return Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => $texto]],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200);
        });

        return $contador;
    }

    /**
     * 🔴 R2 (verificado con este mismo test antes del arreglo: el «quedó registrado» disparaba el
     * reintento —3 llamadas— y el «Ojo: con este mensaje no registré ningún cambio…»): en el panel, el
     * modelo abre la conversación de ayer y cuenta el pago que se registró AHÍ. No es una carga que este
     * mensaje prometió: ni reintento ni aviso. Con «Ayer quedó registrado…» (el caso de la revisión) ya
     * no disparaba antes del arreglo —«ayer» es una marca de repaso de la guarda—: queda como prueba.
     *
     * @test
     */
    public function una_carga_de_otra_conversacion_leida_no_dispara_la_guarda_de_la_carga()
    {
        $textos = [
            'El Pago N° 1706 a Pinturas Sudamericanas quedó registrado con los 5 e-cheq. ¿Querés que te arme los recordatorios?',
            'Registré el Pago N° 1706 a Pinturas Sudamericanas con 5 e-cheq.',
            'Listo, quedó registrado el pago Nº 1.706 con los 5 cheques. Ahora sigo con los recordatorios.',
            'Ayer quedó registrado el pago N° 1706 a Pinturas Sudamericanas, con los 5 e-cheq del Galicia.',
        ];

        foreach ($textos as $texto_del_modelo) {
            list($del_pago) = $this->conversacion_del_pago();
            list($hoy, $assistant) = $this->pedido_en_el_panel('Seguí con lo del pago de Pinturería Sudamericana');

            $contador = $this->modelo_que_lee_y_contesta($del_pago->id, $texto_del_modelo);

            $texto = (new AsistenteIaService())->responder($hoy, $assistant);

            $this->assertSame($texto_del_modelo, $texto, 'Sin aviso: ' . $texto_del_modelo);
            $this->assertStringNotContainsString(TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
            $this->assertSame(2, $contador->llamadas, 'Leer y contestar, sin reintento: ' . $texto_del_modelo);

            Http::swap(new HttpFactorySinSalida(app('events')));
        }
    }

    /**
     * R2, lo que la guarda sigue viendo: (a) el mismo texto SIN haber abierto la otra conversación en este
     * turno; (b) una mentira en OTRA oración del mismo texto («Ya te agendé los recordatorios»); (c) un
     * registro con otro número. En los tres hay reintento y el texto no llega tal cual.
     *
     * @test
     */
    public function la_guarda_sigue_viendo_lo_que_no_es_de_la_conversacion_leida()
    {
        $casos = [
            'sin leer'        => [false, 'El Pago N° 1706 a Pinturas Sudamericanas quedó registrado con los 5 e-cheq.', 2],
            'otra oración'    => [true, 'El Pago N° 1706 quedó registrado con los 5 e-cheq. Ya te agendé los recordatorios de los cheques.', 3],
            'otro número'     => [true, 'Registré el Pago N° 1800 a Pinturas Sudamericanas con 5 e-cheq.', 3],
        ];

        foreach ($casos as $caso => $datos) {
            list($del_pago) = $this->conversacion_del_pago();
            list($hoy, $assistant) = $this->pedido_en_el_panel('Seguí con lo del pago de Pinturería Sudamericana');

            $contador = $this->modelo_que_lee_y_contesta($datos[0] ? $del_pago->id : null, $datos[1]);

            $texto = (new AsistenteIaService())->responder($hoy, $assistant);

            $this->assertNotSame($datos[1], $texto, $caso . ': la guarda tiene que actuar.');
            $this->assertSame($datos[2], $contador->llamadas, $caso . ': con el reintento de la guarda.');

            Http::swap(new HttpFactorySinSalida(app('events')));
        }
    }

    /**
     * R2, los registros: salen del resultado de las cargas CONFIRMADAS de las conversaciones vistas, con
     * la tenencia de siempre (la de otra persona y la actual no dan nada); y una oración se saca solo si
     * nombra la entidad Y el número del mismo registro.
     *
     * @test
     */
    public function los_registros_de_las_conversaciones_leidas_y_las_oraciones_que_se_sacan()
    {
        list($del_pago) = $this->conversacion_del_pago();
        list($hoy) = $this->conversacion_de_hoy();

        $this->assertSame(
            [['entidad' => 'pago', 'numero' => '1706']],
            ConversacionesAnterioresIaHelper::registros_confirmados($hoy, [$del_pago->id, $hoy->id])
        );

        /* La de otra persona del mismo negocio, y la actual: nada. */
        $otra_persona = User::create([
            'name'     => 'Empleado R2',
            'email'    => 'empleado-r2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        list($ajena, $de_la_ajena) = $this->charla('Pago ajeno', [['user', 'Cargá el pago'], ['assistant', 'Listo']], null, ['auth_user_id' => $otra_persona->id]);
        $this->tarjeta($de_la_ajena[1], AiMessageAction::ESTADO_CONFIRMADA, 'Pago N° 2000 registrado.');

        $this->assertSame([], ConversacionesAnterioresIaHelper::registros_confirmados($hoy, [$ajena->id]));
        $this->assertSame([], ConversacionesAnterioresIaHelper::registros_confirmados($hoy, [$hoy->id]));
        $this->assertSame([], ConversacionesAnterioresIaHelper::registros_confirmados($hoy, []));

        $registros = [['entidad' => 'pago', 'numero' => '1706']];

        $this->assertSame(
            'Ya te agendé los recordatorios.',
            ConversacionesAnterioresIaHelper::sin_oraciones_sobre_registros('El Pago Nº 1.706 quedó registrado. Ya te agendé los recordatorios.', $registros)
        );
        $this->assertSame(
            "Registré el Gasto N° 1706.\nLos pagos 17060 no existen.",
            ConversacionesAnterioresIaHelper::sin_oraciones_sobre_registros("Registré el Gasto N° 1706.\nLos pagos 17060 no existen.", $registros),
            'Otra entidad con el mismo número, u otro número con la misma entidad, no se sacan.'
        );
    }
}
