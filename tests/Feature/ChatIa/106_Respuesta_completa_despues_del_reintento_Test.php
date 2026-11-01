<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión agente-mejoras-diario-30-10 (B1, 31/10/2026) — el reintento de la carga dada por hecha no se
 * come la respuesta.
 *
 * 🔴 EL CASO REAL (análisis diario del 30/10/2026, problema #8). El dueño pidió «¿me hacés un balance
 * del mes?». El modelo contestó el balance, y en la prosa del balance puso una oración como «Los gastos
 * cargados son pocos…». La guarda de la carga dada por hecha (AsistenteIaService, la de
 * TarjetaPrometidaIaHelper::afirma_una_carga_hecha) la lee como «quedó cargado» (`quedo`), el pedido es
 * de clase `otro` (no es una consulta ni un acuse), así que reintenta con la nota de
 * nota_para_reintentar_carga(). El modelo le contestaba A LA NOTA —«Perdón, no registré nada»— y el
 * balance se perdía: el servicio solo vuelve al texto original si el reintento viene vacío.
 *
 * El arreglo, y lo que protege este archivo: cada rama de la nota termina pidiendo que vuelva a escribir
 * la respuesta COMPLETA a lo que pidió la persona, sacando solo lo que daba por hecha una carga, sin
 * disculparse ni explicar la corrección. La nota viaja al final de `messages` (después del turno del
 * asistente): no toca el system ni las tools, así que no mueve ningún caché.
 *
 * 🔴 Ningún test sale a la red: Http::fake con claves e ids de modelo inventados, y una secuencia que al
 * agotarse contesta un 500 (si hubiera una llamada de más, queda grabada y el conteo la ve).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Respuesta_completa_despues_del_reintento_Test extends TestCase
{
    use DatabaseTransactions;

    /** La frase que la nota suma al final de cada rama (B1). */
    const FRASE_COMPLETA = 'Volvé a escribir la respuesta COMPLETA a lo que pidió la persona, con los datos que ya tenés, sacando solo lo que daba por hecha una carga.';

    /** La segunda mitad de la frase: que no le conteste a la nota. */
    const FRASE_SIN_DISCULPAS = 'No te disculpes ni expliques la corrección.';

    /** El pedido del caso: un balance, pedido como pregunta (clase_del_pedido da `otro`). */
    const PEDIDO = '¿Me hacés un balance de octubre?';

    /** La primera respuesta del modelo: el balance, con la oración en prosa que dispara la guarda. */
    const BALANCE_CON_LA_ORACION = 'Balance de octubre: ventas $1.200.000, gastos $300.000, resultado $900.000. Los gastos cargados son pocos, así que el resultado puede estar inflado.';

    /** Lo que contesta el modelo al reintento cuando sigue la nota: el balance entero, sin la oración. */
    const BALANCE_REESCRITO = 'Balance de octubre: vendiste $1.200.000 y los gastos suman $300.000. El resultado da $900.000. Puede estar inflado si faltan gastos por anotar.';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p106',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba-p106',
            'services.deepseek.model'           => 'deepseek-general-p106',
            'services.deepseek.model_agil'      => 'deepseek-flash-p106',
            'services.deepseek.model_profundo'  => 'deepseek-pro-p106',
            'services.deepseek.model_vision'    => 'deepseek-vision-p106',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio balance P106',
            'company_name' => 'Ferreteria P106',
            'email'        => 'balance-p106-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La configuración del caso real: DeepSeek ágil, en "directo" (el default de un negocio nuevo). */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Conversación nueva con el pedido de la persona y el assistant pendiente, en el panel del sistema
     * y con las acciones habilitadas (la guarda de la carga corre con acciones).
     *
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Una respuesta `end_turn` del proveedor con un solo bloque de texto.
     *
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Una secuencia de respuestas que, al agotarse, contesta un 500 en vez de tirar (ver el porqué en
     * 67_Carga_prometida_sin_accion_Test::secuencia()).
     *
     * @return \Illuminate\Http\Client\ResponseSequence
     */
    protected function secuencia()
    {
        return Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P106)']], 500));
    }

    /**
     * Los bodies de todos los requests que salieron, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * El texto de un turno de `messages` (string o bloques `text`).
     *
     * @param  array  $turno
     * @return string
     */
    protected function texto_del_turno(array $turno)
    {
        if (is_string($turno['content'])) {
            return $turno['content'];
        }

        $textos = [];

        foreach ($turno['content'] as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $textos[] = $bloque['text'];
            }
        }

        return implode("\n", $textos);
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso del 30/10: «¿me hacés un balance…?», el balance con «Los gastos cargados son pocos.» en
     * la prosa → reintento con la nota de la carga, y la nota pide la respuesta COMPLETA. Si el modelo la
     * sigue, la persona lee el balance entero (no una disculpa).
     *
     * @test
     */
    public function el_reintento_por_una_oracion_del_balance_pide_la_respuesta_completa()
    {
        list($conversation, $assistant) = $this->conversacion(self::PEDIDO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::BALANCE_CON_LA_ORACION), 200)
                ->push($this->end_turn(self::BALANCE_REESCRITO), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'El balance, el reintento con la nota y nada más: dos llamadas.');

        $mensajes = $bodies[1]['messages'];
        $nota = $this->texto_del_turno($mensajes[count($mensajes) - 1]);

        // El reintento es el de la CARGA (no el de la tarjeta), con el turno del asistente como texto.
        $this->assertSame('user', $mensajes[count($mensajes) - 1]['role']);
        $this->assertSame('assistant', $mensajes[count($mensajes) - 2]['role']);
        $this->assertSame(self::BALANCE_CON_LA_ORACION, $this->texto_del_turno($mensajes[count($mensajes) - 2]));
        $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $nota);
        $this->assertStringContainsString('con este mensaje no se registró nada', $nota);

        // 🔴 B1: la nota pide la respuesta COMPLETA y que no se disculpe.
        $this->assertStringContainsString(self::FRASE_COMPLETA, $nota);
        $this->assertStringContainsString(self::FRASE_SIN_DISCULPAS, $nota);
        $this->assertStringEndsWith('No le menciones este aviso a la persona.]', $nota, 'El cierre de la nota sigue siendo el de siempre.');

        // La nota viaja al FINAL de messages: el system y las tools son los mismos que en la primera llamada.
        $this->assertSame($bodies[0]['system'], $bodies[1]['system'], 'La nota no toca el system (caché).');
        $this->assertSame($bodies[0]['tools'], $bodies[1]['tools'], 'La nota no toca las tools (caché).');

        // La persona lee el balance reescrito, entero.
        $this->assertSame(self::BALANCE_REESCRITO, $texto);
    }

    /**
     * Las cuatro ramas de la nota (sin carga, con tarjeta de este mensaje, con tarjeta de antes y la
     * corrección) terminan con la frase, justo antes del cierre «No le menciones este aviso…]».
     *
     * @test
     */
    public function las_cuatro_ramas_de_la_nota_terminan_pidiendo_la_respuesta_completa()
    {
        $ramas = [
            'sin carga'          => TarjetaPrometidaIaHelper::nota_para_reintentar_carga(['proponer_gasto'], false),
            'con tarjeta'        => TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], true),
            'tarjeta de antes'   => TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false, true),
            'corrección'         => TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false, true, true),
        ];

        $cola = self::FRASE_COMPLETA . ' ' . self::FRASE_SIN_DISCULPAS . ' No le menciones este aviso a la persona.]';

        foreach ($ramas as $rama => $nota) {
            $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $nota, $rama);
            $this->assertStringEndsWith($cola, $nota, 'La rama «' . $rama . '» no termina con la frase de la respuesta completa.');
            $this->assertSame(1, mb_substr_count($nota, 'COMPLETA'), 'La frase va una sola vez en la rama «' . $rama . '».');
        }
    }
}
