<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ReferenciaALoAnteriorIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítem 31/10 #5 — «poné el stock de esos en 0».
 *
 * EL CASO REAL (Ferre Total, conversación 33, 31/10/2026): el agente avisó que el taladro y el reflector
 * habían quedado negativos y, ante «Poné el stock de esos productos en 0», habló de una foto vieja y pidió que
 * le dijeran cuáles. Lo que protege este archivo, con un doble del modelo que LEE el historial (patrón del
 * test 63):
 *
 *  - el mensaje con «esos» le llega al modelo con la nota que nombra los artículos de la ÚLTIMA respuesta,
 *    con su id (y no los de una respuesta anterior);
 *  - el doble arma la carga con esos ids, que es lo que el modelo tiene que poder hacer sin preguntar;
 *  - sin referencia, o sin respuesta anterior, no hay nota;
 *  - si no se reconoce ningún artículo, la nota trae el recorte de la última respuesta.
 *
 * 🔴 Ningún test sale a la red: Http::fake con claves e ids de modelo inventados.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T172_Esos_se_resuelven_contra_la_ultima_respuesta_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => 'clave-anthropic-de-prueba-c172',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-c172',
            'services.deepseek.model'          => 'deepseek-general-c172',
            'services.deepseek.model_agil'     => 'deepseek-flash-c172',
            'services.deepseek.model_profundo' => 'deepseek-pro-c172',
            'services.deepseek.model_vision'   => 'deepseek-vision-c172',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio esos C172',
            'company_name' => 'Ferreteria C172',
            'email'        => 'esos-c172-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    /**
     * @param  string  $nombre
     * @return Article
     */
    protected function articulo($nombre)
    {
        return Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active', 'stock' => -2]);
    }

    /**
     * Una conversación con pares [pedido, respuesta] ya cerrados y el pedido nuevo con su assistant pendiente.
     *
     * @param  array<int, array{0: string, 1: string}>  $pares
     * @param  string  $pedido_nuevo
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion(array $pares, $pedido_nuevo)
    {
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        foreach ($pares as $par) {
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $par[0], 'estado' => 'listo']);
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $par[1], 'estado' => 'listo', 'acciones_habilitadas' => true]);
        }

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido_nuevo, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * El texto del último turno del usuario de un body.
     *
     * @param  array  $body
     * @return string
     */
    protected function ultimo_turno(array $body)
    {
        $ultimo = $body['messages'][count($body['messages']) - 1];

        if (is_string($ultimo['content'])) {
            return $ultimo['content'];
        }

        $textos = [];

        foreach ($ultimo['content'] as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $textos[] = $bloque['text'];
            }
        }

        return implode("\n", $textos);
    }

    /**
     * 🔴 El caso de Ferre Total: «esos» son el taladro y el reflector de la última respuesta, no el artículo
     * de la foto vieja; el doble del modelo lee la nota y arma la carga con esos ids.
     *
     * @test
     */
    public function esos_son_los_de_la_ultima_respuesta_y_el_modelo_los_recibe_con_su_id()
    {
        $viejo     = $this->articulo('Mascara de soldar C172');
        $taladro   = $this->articulo('Taladro Percutor C172');
        $reflector = $this->articulo('Reflector LED 50W C172');

        list($conversation, $assistant) = $this->conversacion([
            ['Te mando la foto de la planilla', 'En la foto veo la Mascara de soldar C172 con el código 10354.'],
            ['Cargá las ventas', 'Listo. Ojo: el *Taladro Percutor C172* y el *Reflector LED 50W C172* quedaron con stock negativo.'],
        ], 'Poné el stock de esos productos en 0');

        $ids_leidos = [];

        Http::fake([
            'api.deepseek.com/*' => function ($request) use (&$ids_leidos) {
                // El doble lee el historial como lo haría el modelo: los articulo_id de la nota.
                $body = json_decode($request->body(), true);
                $texto = $this->ultimo_turno($body);

                preg_match_all('/articulo_id (\d+)/', $texto, $m);
                $ids_leidos = array_map('intval', $m[1]);

                return Http::response([
                    'model'       => 'deepseek-flash-c172',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => 'Dejo en 0 el stock de los ' . count($ids_leidos) . ' artículos.']],
                    'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200);
            },
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        (new AsistenteIaService())->responder($conversation, $assistant);

        sort($ids_leidos);
        $esperados = [(int) $taladro->id, (int) $reflector->id];
        sort($esperados);

        $this->assertSame($esperados, $ids_leidos, 'La nota nombra los dos de la última respuesta.');
        $this->assertNotContains((int) $viejo->id, $ids_leidos, 'El de la foto vieja no es «esos».');
    }

    /**
     * Sin referencia, o sin respuesta anterior, no hay nota.
     *
     * @test
     */
    public function sin_referencia_o_sin_respuesta_anterior_no_hay_nota()
    {
        $this->articulo('Taladro Percutor C172');

        list($conversation, $assistant) = $this->conversacion([
            ['Cargá las ventas', 'El Taladro Percutor C172 quedó negativo.'],
        ], 'Poné el stock del taladro en 0');

        $this->assertNull(ReferenciaALoAnteriorIaHelper::nota($conversation, $assistant));

        list($conversation, $assistant) = $this->conversacion([], 'Poné el stock de esos en 0');

        $this->assertNull(ReferenciaALoAnteriorIaHelper::nota($conversation, $assistant));
    }

    /**
     * Si no se reconoce ningún artículo, la nota trae el recorte de la última respuesta.
     *
     * @test
     */
    public function sin_articulos_reconocidos_la_nota_trae_el_recorte()
    {
        list($conversation, $assistant) = $this->conversacion([
            ['¿Quién me debe?', 'Te deben Juan C172 y Pedro C172.'],
        ], 'Mandales un recordatorio a los dos');

        $nota = ReferenciaALoAnteriorIaHelper::nota($conversation, $assistant);

        $this->assertNotNull($nota);
        $this->assertStringContainsString('«los dos»', $nota);
        $this->assertStringContainsString('Te deben Juan C172 y Pedro C172.', $nota);
    }

    /**
     * El detector: palabras enteras, sin falsos positivos con «eso» o «ese».
     *
     * @test
     */
    public function el_detector_de_referencias()
    {
        $this->assertTrue(ReferenciaALoAnteriorIaHelper::usa_una_referencia('Poné el stock de ESOS en 0'));
        $this->assertTrue(ReferenciaALoAnteriorIaHelper::usa_una_referencia('subile 10% a los dos'));
        $this->assertTrue(ReferenciaALoAnteriorIaHelper::usa_una_referencia('Estos, borralos'));
        $this->assertFalse(ReferenciaALoAnteriorIaHelper::usa_una_referencia('eso es todo, gracias'));
        $this->assertFalse(ReferenciaALoAnteriorIaHelper::usa_una_referencia('ese día no vendí nada'));
        $this->assertFalse(ReferenciaALoAnteriorIaHelper::usa_una_referencia('pesos'));
    }
}
