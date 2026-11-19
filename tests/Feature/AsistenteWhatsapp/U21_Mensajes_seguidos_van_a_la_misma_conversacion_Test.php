<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\HiloPorTemaIaHelper;
use App\Models\AiConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Misión asistente-diario-20261119, rama B — A6 (1): conversaciones partidas por mensajes seguidos.
 *
 * 🔴 EL CASO REAL (Ferre Total, 18/11/2026). Pablo pegó una lista de 37 renglones (conv 62, 11:15:51) y 14 segundos
 * después mandó el audio «¿Me podés preparar un presupuesto para Fideicomiso El Cantón con estos productos?», que
 * abrió la conv 63: la decisión de tema (HiloPorTemaIaHelper) contestó «nueva». La 63 pidió los productos y la 62 el
 * cliente; el presupuesto nunca se armó.
 *
 * Se protege: un mensaje dentro de la ventana de ráfaga sigue en la conversación vigente SIN preguntarle al modelo,
 * fuera de la ventana el corte por tema sigue andando, y la conversación de la ráfaga que se usa adentro de la
 * transacción (contra la carrera de dos requests casi juntos).
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class U21_Mensajes_seguidos_van_a_la_misma_conversacion_Test extends AsistenteWhatsappTestCase
{
    /** @var string Lo que contesta el modelo de la decisión de tema. */
    protected $texto_del_modelo = 'nueva';

    /** @var callable|null Algo que pasa MIENTRAS se decide el tema afuera del candado (la carrera). */
    public $mientras_decide = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        Queue::fake();

        config(['services.deepseek.api_key' => 'clave-de-deepseek-u21']);

        $test = $this;

        Http::fake([
            'api.deepseek.com/*' => function () use ($test) {
                if (is_callable($test->mientras_decide)) {
                    call_user_func($test->mientras_decide);
                    $test->mientras_decide = null;
                }

                return Http::response([
                    'model'       => 'modelo-fake-u21',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => $test->texto_del_modelo]],
                    'usage'       => ['input_tokens' => 50, 'output_tokens' => 1],
                ], 200);
            },
        ]);
    }

    protected function llamadas_al_modelo()
    {
        $cuantas = 0;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $cuantas++;
            }
        }

        return $cuantas;
    }

    /**
     * La conv 62: la lista pegada, con título y su respuesta.
     */
    protected function la_lista($hace)
    {
        $conversacion = $this->conversacion_whatsapp($hace);
        $conversacion->titulo = 'Lista de materiales y herramientas solicitados';
        $conversacion->save();

        $this->mensaje($conversacion, 'user', 'listo', ['contenido' => "50 DISCO DE CORTE DE 115mm TYROLIT\n10 ACEITE LUBRICANTE WD-40\n500 REMACHES 5X16mm FISCHER"]);
        $this->mensaje($conversacion, 'assistant', 'listo', ['contenido' => 'Voy a armar la carga con esta lista. ¿Es una compra o un ajuste de stock?']);

        return $conversacion;
    }

    /**
     * 🔴 El audio 14 segundos después de la lista entra en la MISMA conversación, aunque el modelo de la decisión de
     * tema dijera «nueva»: ni se le pregunta.
     *
     * @group asistente-hilos
     * @test
     */
    public function el_audio_a_segundos_de_la_lista_sigue_en_la_misma_conversacion()
    {
        $lista = $this->la_lista(Carbon::now()->subSeconds(14));

        $respuesta = $this->mandar([
            'texto' => '¿Me podés preparar un presupuesto para Fideicomiso El Cantón con estos productos?',
            'tipo'  => 'audio',
        ])->assertStatus(202);

        $this->assertSame((int) $lista->id, (int) $respuesta->json('ai_conversation_id'), 'Mensajes seguidos son una sola tanda.');
        $this->assertSame(0, $this->llamadas_al_modelo(), 'Dentro de la ráfaga no se gasta la llamada de la decisión de tema.');
        $this->assertSame(1, AiConversation::where('user_id', $this->comercio->id)->count());
    }

    /**
     * Fuera de la ventana el corte por tema sigue como siempre: un tema nuevo abre su hilo.
     *
     * @group asistente-hilos
     * @test
     */
    public function pasada_la_rafaga_un_tema_nuevo_abre_su_conversacion()
    {
        $lista = $this->la_lista(Carbon::now()->subSeconds(AsistenteCanalHelper::SEGUNDOS_DE_LA_RAFAGA + 60));

        $respuesta = $this->mandar(['texto' => 'Sacale a Brisa el permiso de ver las ventas'])->assertStatus(202);

        $this->assertNotSame((int) $lista->id, (int) $respuesta->json('ai_conversation_id'));
        $this->assertSame(1, $this->llamadas_al_modelo());
    }

    /**
     * La guarda vive en HiloPorTemaIaHelper (no le pregunta al modelo) y la ventana es una constante con motivo.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_guarda_de_la_rafaga_no_corta_ni_pregunta()
    {
        $lista = $this->la_lista(Carbon::now()->subSeconds(30));

        $this->assertFalse(HiloPorTemaIaHelper::abre_otro_hilo($lista, 'Haceme un presupuesto para el cliente Fideicomiso con estos productos'));
        $this->assertSame(0, $this->llamadas_al_modelo());
        $this->assertSame(180, AsistenteCanalHelper::SEGUNDOS_DE_LA_RAFAGA);
    }

    /**
     * La conversación de la ráfaga (la que se usa adentro de la transacción cuando la elección de afuera dio
     * «nueva»): la vigente si tuvo actividad recién, null si no o si es de otro dueño.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_conversacion_de_la_rafaga_es_la_vigente_reciente_del_dueno()
    {
        $this->assertNull(AsistenteCanalHelper::conversacion_de_la_rafaga($this->comercio), 'Sin conversación no hay ráfaga.');

        $vieja = $this->conversacion_whatsapp(Carbon::now()->subMinutes(20));
        $this->assertNull(AsistenteCanalHelper::conversacion_de_la_rafaga($this->comercio), 'Vigente pero sin actividad reciente.');

        $reciente = $this->conversacion_whatsapp(Carbon::now()->subSeconds(5));
        $this->assertSame((int) $reciente->id, (int) AsistenteCanalHelper::conversacion_de_la_rafaga($this->comercio)->id);

        $this->conversacion_whatsapp(Carbon::now(), $this->otro_dueno());
        $this->assertSame((int) $reciente->id, (int) AsistenteCanalHelper::conversacion_de_la_rafaga($this->comercio)->id, 'La de otro dueño no se cruza.');

        $this->assertNotSame((int) $vieja->id, (int) $reciente->id);
    }

    /**
     * 🔴 LA CARRERA: si la elección de afuera llegó a «nueva» (sin conversación vigente) pero, mientras tanto, otro
     * mensaje del dueño creó una, el alta adentro de la transacción entra en esa. Se simula con un dueño sin
     * conversaciones: el primer mensaje crea una y el segundo, a segundos, entra en la misma.
     *
     * @group asistente-hilos
     * @test
     */
    public function dos_mensajes_seguidos_sin_conversacion_previa_quedan_juntos()
    {
        $primero = $this->mandar(['texto' => "50 DISCO DE CORTE DE 115mm TYROLIT\n10 ACEITE LUBRICANTE WD-40"])->assertStatus(202);
        $segundo = $this->mandar(['texto' => '¿Me podés preparar un presupuesto para Fideicomiso El Cantón con estos productos?', 'tipo' => 'audio'])->assertStatus(202);

        $this->assertSame((int) $primero->json('ai_conversation_id'), (int) $segundo->json('ai_conversation_id'));
        $this->assertSame(1, AiConversation::where('user_id', $this->comercio->id)->count());
    }

    /**
     * 🔴 Revisión independiente (19/11/2026), punto 2: la carrera en el OTRO sentido. Afuera del candado el mensaje 2
     * eligió A («sigue»); mientras tanto el mensaje 1 abrió B. Adentro del candado gana la de la ráfaga (B), y los dos
     * mensajes quedan juntos.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_rafaga_gana_tambien_si_afuera_se_eligio_otra_conversacion()
    {
        $a = $this->la_lista(Carbon::now()->subMinutes(4));
        $this->texto_del_modelo = 'sigue';

        $b = null;
        $test = $this;

        /* Mientras se decide el tema del mensaje 2, el mensaje 1 abre B y queda guardado. */
        $this->mientras_decide = function () use (&$b, $test) {
            $b = $test->conversacion_whatsapp(Carbon::now());
            $test->mensaje($b, 'user', 'listo', ['contenido' => 'Hacé un presupuesto para Fideicomiso El Cantón']);
        };

        $respuesta = $this->mandar(['texto' => 'Con estos productos de la lista que te pasé recién'])->assertStatus(202);

        $this->assertNotNull($b, 'La carrera se simuló durante la decisión de tema.');
        $this->assertSame((int) $b->id, (int) $respuesta->json('ai_conversation_id'), 'La conversación de la ráfaga gana.');
        $this->assertNotSame((int) $a->id, (int) $respuesta->json('ai_conversation_id'));
    }

    /**
     * Una cita del admin manda siempre: aunque haya otra conversación con actividad recién, el mensaje entra en la
     * citada.
     *
     * @group asistente-hilos
     * @test
     */
    public function la_cita_del_admin_gana_sobre_la_rafaga()
    {
        $citada = $this->la_lista(Carbon::now()->subHours(2));
        $this->conversacion_whatsapp(Carbon::now()->subSeconds(10));

        $respuesta = $this->mandar(['texto' => 'Sobre esto que me mandaste', 'ai_conversation_id' => $citada->id])->assertStatus(202);

        $this->assertSame((int) $citada->id, (int) $respuesta->json('ai_conversation_id'));
    }
}
