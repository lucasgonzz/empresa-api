<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Address;
use App\Models\BusinessHoursConfig;
use App\Models\User;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\WhatsappBotAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión agente-no-niega-reservas (16/10/2026): el agente de WhatsApp de los clientes dejó de NEGAR de plano los
 * servicios que el negocio no le contó («no hacemos reservas», «no manejo envíos», «no puedo reservarlo»).
 *
 * El problema: la regla fija «No afirmes que el negocio … reserva productos … si eso no está escrito» se leía como «el
 * negocio no reserva», y la capa del objetivo «Que pase por el local» («no le ofrezcas apartarle ni reservarle») se
 * convertía en una negativa. Negar es OTRA afirmación no cargada: el negocio quizás sí guarda productos. Lo que el
 * agente tiene que contestar es lo que ya decía su regla: «lo consulto y te confirmo» (y entonces deja la marca de
 * consulta de la misión de la señal), salvo que el dueño haya escrito en la personalidad, las habilidades o las
 * indicaciones del objetivo que sí (o que no) se hace.
 *
 * ⚠️ Lo que decide el modelo no se puede testear con un stub: el comportamiento se midió contra DeepSeek real (ver el
 * informe de la misión). Este archivo protege lo que SÍ es del código, para que un retoque posterior del prompt no se
 * lleve puesta la regla sin que nadie se entere:
 *  - La regla nueva está, va justo después de la de servicios no cargados (que sigue textual) y las cinco reglas viejas
 *    de `7_Link_de_tienda_Test` siguen enteras.
 *  - Va dentro del bloque fijo: ANTES de la consigna de las marcas y de las capas opcionales (nombre, horario,
 *    objetivo), que se suman siempre al final para que el prompt de antes quede como prefijo (tests 21 y 28).
 *  - Deja la salida del dueño («Si estas instrucciones lo dicen, contestá eso, que sí o que no»), no cita ninguna frase entre comillas (el
 *    modelo la toma como prohibida aunque la escriba el dueño) y lo que el dueño escribió viaja antes de las reglas,
 *    tal cual.
 *  - La capa del objetivo «local» conserva la frase vieja y NO suma ninguna orden de consultar (pisaba la negativa del
 *    dueño). El cierre de la capa de TODOS los objetivos tampoco se toca: la primera versión le sumó «decile que lo
 *    consultás» y el chequeo independiente mostró que se pasaba (links y descuentos).
 */
class Servicios_no_cargados_no_se_niegan_Test extends TestCase
{
    use DatabaseTransactions;

    /** Habilidades que escribe un dueño que SÍ reserva: el prompt las lleva antes de las reglas fijas. */
    const HABILIDADES_CON_RESERVA = 'Reservamos los productos hasta 24 horas, sin seña.';

    /** Habilidades de un dueño que NO reserva: el prompt las lleva antes de las reglas y de la capa del objetivo. */
    const HABILIDADES_SIN_RESERVA = 'No hacemos reservas ni apartados: los productos se venden a quien llega primero.';

    /** La regla de servicios no cargados que ya existía (la fija el test 28): sigue textual. */
    const REGLA_NO_AFIRMES = 'No afirmes que el negocio hace envíos a domicilio, reserva productos, toma pedidos, acepta una forma de pago, cuotas o tarjetas, da garantía o hace factura si eso no está escrito en estas instrucciones. Si te lo preguntan, decí que lo consultás y le confirmás.';

    /** La regla nueva (arranque). */
    const REGLA_NO_LO_NIEGUES = 'Decir que el negocio hace algo, o que NO lo hace, es afirmar algo que no sabés si no está escrito:';

    /** Las cinco reglas fijas viejas (las fija el test 7): una regla nueva se suma, nunca las reescribe. */
    const REGLAS_VIEJAS = [
        'Respondé solo con información que esté en el catálogo de productos de abajo o en la conversación.',
        'Si no sabés algo, decilo con honestidad y ofrecé que una persona del negocio siga la consulta.',
        'Nunca inventes precios ni stock que no estén en el catálogo de productos.',
        'Nunca confirmes pagos ni acuerdes descuentos que no te hayan sido informados explícitamente acá.',
        'Texto plano, sin markdown, sin asteriscos ni listas con guiones.',
    ];

    /** @var User */
    protected $comercio;

    /** @var WhatsappBotConfig */
    protected $config;

    /** @var WhatsappChat */
    protected $chat;

    /** Payload que viajó a Anthropic (lo captura el stub). */
    protected $payload_anthropic = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        // Sin DeepSeek: el bot cae a Anthropic, que es lo que stubea este archivo.
        config(['services.deepseek.api_key' => null]);
        // WhatsappChatUpdated es ShouldBroadcastNow: sin esto los tests pegan en Pusher de verdad.
        config(['broadcasting.default' => 'null']);

        // El horario se resuelve por usuario o por el owner de la instancia: se vacía la tabla (dentro de la
        // transacción) para que "sin horario" no dependa de lo que haya en la base.
        BusinessHoursConfig::query()->delete();

        $this->comercio = User::create([
            'name'         => 'Ferreteria servicios',
            'company_name' => 'Ferreteria servicios',
            'email'        => 'whatsapp-servicios-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->config = WhatsappBotConfig::create([
            'user_id'            => $this->comercio->id,
            'kapso_api_key'      => 'kapso-servicios',
            'phone_number_id'    => '5491100000030',
            'webhook_secret'     => 'secreto-servicios',
            'is_active'          => true,
            'ai_enabled_default' => true,
        ]);

        $this->chat = WhatsappChat::create([
            'user_id'                => $this->comercio->id,
            'phone'                  => '5493416013330',
            'ai_enabled'             => true,
            'unread_count'           => 1,
            'last_message_at'        => now(),
            'last_inbound_at'        => now(),
            'last_inbound_simulated' => 0,
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $this->chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => '¿Me guardás el taladro hasta mañana?',
        ]);
    }

    /**
     * Dispara la generación con la config guardada y devuelve el `system` que viajó a la IA.
     *
     * @return string
     */
    protected function system_generado()
    {
        $this->payload_anthropic = null;
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.openai.com/*'    => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]], 200),
            'api.anthropic.com/*' => function ($request) {
                $this->payload_anthropic = $request->data();

                return Http::response([
                    'model'   => 'claude-de-prueba',
                    'content' => [['type' => 'text', 'text' => 'Lo consulto y te confirmo.']],
                    'usage'   => ['input_tokens' => 100, 'output_tokens' => 20],
                ], 200);
            },
            '*'                   => Http::response([], 200),
        ]);

        (new WhatsappBotAiService())->generate_response($this->chat, $this->config->fresh());

        $this->assertNotNull($this->payload_anthropic, 'La llamada a la IA tiene que haber salido.');

        return (string) ($this->payload_anthropic['system'] ?? '');
    }

    /**
     * Guarda el objetivo y/o las habilidades en la config.
     *
     * @param string|null $objetivo
     * @param string|null $detalle
     * @param string|null $habilidades
     * @return void
     */
    protected function configurar($objetivo = null, $detalle = null, $habilidades = null)
    {
        $this->config->agent_objective        = $objetivo;
        $this->config->agent_objective_detail = $detalle;
        $this->config->agent_skills           = $habilidades;
        $this->config->save();
    }

    /**
     * La regla nueva está, justo después de la de servicios no cargados, y no se llevó puesta ninguna de las viejas.
     *
     * @group whatsapp
     * @test
     */
    public function la_regla_de_no_negar_va_despues_de_la_de_servicios_y_las_viejas_siguen_enteras()
    {
        $system = $this->system_generado();

        $this->assertStringContainsString(self::REGLA_NO_AFIRMES, $system, 'La regla de servicios no cargados sigue textual.');
        $this->assertStringContainsString(self::REGLA_NO_LO_NIEGUES, $system, 'La regla nueva tiene que estar.');

        foreach (self::REGLAS_VIEJAS as $regla) {
            $this->assertStringContainsString($regla, $system, 'Una regla fija vieja se perdió: ' . $regla);
        }

        // "Justo después" de verdad: por renglones (separador que no depende de CRLF/LF), la regla nueva es el renglón
        // siguiente a la de servicios no cargados.
        $renglones = preg_split('/\r?\n/', $system);
        $i_afirmes = $this->indice_del_renglon($renglones, '- No afirmes que el negocio hace envíos a domicilio');

        $this->assertStringStartsWith('- ' . self::REGLA_NO_LO_NIEGUES, $renglones[$i_afirmes + 1], 'La regla nueva es el renglón siguiente a la de servicios no cargados.');
    }

    /**
     * Índice del primer renglón que arranca con el texto dado (falla si no está).
     *
     * @param array  $renglones
     * @param string $arranque
     * @return int
     */
    protected function indice_del_renglon(array $renglones, $arranque)
    {
        foreach ($renglones as $i => $renglon) {
            if (strpos($renglon, $arranque) === 0) {
                return $i;
            }
        }

        $this->fail('No está el renglón que arranca con: ' . $arranque);
    }

    /**
     * La regla nombra la clase de cosas que no se niegan, manda a consultar y deja la salida del dueño. Y NO cita
     * ninguna frase entre comillas: medido contra DeepSeek, un ejemplo como «no hacemos reservas» lo toma como
     * PROHIBIDO aun cuando el dueño escribió exactamente eso en sus habilidades (la negativa del dueño pasó de salir
     * 15 de 15 a 2 de 15).
     *
     * @group whatsapp
     * @test
     */
    public function la_regla_nombra_las_negativas_deja_la_salida_del_dueno_y_no_cita_frases()
    {
        $system = $this->system_generado();

        $this->assertStringContainsString('si en estas instrucciones no se dice si el negocio hace envíos, si reserva o guarda productos, si toma pedidos, si acepta una forma de pago, cuotas o tarjetas, si da garantía o si hace factura, no le digas al cliente ni que sí ni que no ni se lo prometas; decile que lo consultás y le confirmás.', $system, 'Tiene que nombrar los mismos servicios que la regla de arriba.');
        $this->assertStringContainsString('Si estas instrucciones lo dicen, contestá eso, que sí o que no.', $system, 'Y dejar la salida del dueño, en los dos sentidos.');

        // El renglón de la regla no puede tener ninguna comilla (ASCII, angulares ni tipográficas): cualquier frase
        // citada ahí se vuelve prohibida para el modelo.
        $renglones = preg_split('/\r?\n/', $system);
        $regla     = $renglones[$this->indice_del_renglon($renglones, '- ' . self::REGLA_NO_LO_NIEGUES)];

        foreach (['"', '«', '»', '“', '”', "'"] as $comilla) {
            $this->assertStringNotContainsString($comilla, $regla, 'La regla no puede citar frases: el modelo las toma como prohibidas aunque las escriba el dueño.');
        }
    }

    /**
     * Va dentro del bloque fijo: antes de la consigna de las marcas y de las capas opcionales (nombre del negocio,
     * horario, objetivo), que siempre se suman al final.
     *
     * @group whatsapp
     * @test
     */
    public function la_regla_va_antes_de_las_marcas_y_de_las_capas_opcionales()
    {
        $this->configurar('visit_branch');
        Address::create(['user_id' => $this->comercio->id, 'street' => 'Casa central', 'street_number' => 'Av. Almafuerte 850', 'city' => 'Paraná', 'province' => 'Entre Ríos']);

        $system = $this->system_generado();

        $pos_regla  = mb_strpos($system, self::REGLA_NO_LO_NIEGUES);
        $pos_marcas = mb_strpos($system, 'Marcas internas para que el sistema deje anotado');
        $pos_nombre = mb_strpos($system, 'Nombre del negocio:');
        $pos_capa   = mb_strpos($system, 'Objetivo de la conversación, que te pidió el negocio:');

        $this->assertNotFalse($pos_regla);
        $this->assertNotFalse($pos_marcas);
        $this->assertNotFalse($pos_nombre);
        $this->assertNotFalse($pos_capa);
        $this->assertTrue($pos_regla < $pos_marcas, 'La regla va antes de la consigna de las marcas.');
        $this->assertTrue($pos_marcas < $pos_nombre, 'Las marcas son parte fija: van antes de las capas opcionales.');
        $this->assertTrue($pos_nombre < $pos_capa, 'La capa del objetivo sigue yendo al final.');
    }

    /**
     * Lo que el dueño escribió (que sí reserva) viaja ANTES de las reglas fijas y sin tocar: la regla nueva dice
     * «si no está escrito en estas instrucciones», así que lo suyo manda.
     *
     * @group whatsapp
     * @test
     */
    public function lo_que_el_dueno_escribio_viaja_antes_de_las_reglas_y_sin_tocar()
    {
        $this->configurar(null, null, self::HABILIDADES_CON_RESERVA);

        $system = $this->system_generado();

        $pos_dueno = mb_strpos($system, self::HABILIDADES_CON_RESERVA);
        $pos_regla = mb_strpos($system, self::REGLA_NO_LO_NIEGUES);

        $this->assertNotFalse($pos_dueno, 'Las habilidades del dueño tienen que viajar enteras.');
        $this->assertTrue($pos_dueno < $pos_regla, 'Lo del dueño va antes de las reglas y la regla se apoya en «estas instrucciones».');
    }

    /**
     * Objetivo «Que pase por el local»: la capa NO suma ninguna orden de consultar. La frase vieja («no le ofrezcas
     * apartarle, reservarle…», la fija el test 28) queda tal cual y el trabajo lo hace la regla fija.
     *
     * 🔴 Se probó sumarle a esa capa «si es él quien te pide que se lo guardes y no está escrito, decile que lo
     * consultás» (más «si está escrito que no, decile que no»): con un dueño que escribió «No hacemos reservas» y el
     * objetivo del local, la negativa del dueño pasó de salir 13 de 15 veces a 3 de 15, porque la orden de la capa
     * (que va al final y es la más cercana al mensaje) le gana a lo que escribió el dueño. Sin esa frase la regla fija
     * sola deja las negativas del objetivo del local en 4 de 45 (antes: 7 de 15, 6 de plano).
     *
     * @group whatsapp
     * @test
     */
    public function el_objetivo_del_local_conserva_la_frase_vieja_y_no_suma_ordenes_de_consultar()
    {
        $this->configurar('visit_branch');

        $system = $this->system_generado();

        $this->assertStringContainsString('no le ofrezcas apartarle, reservarle ni dejarle listo un producto si eso no está escrito en estas instrucciones.', $system);

        foreach (['visit_branch', 'order_by_whatsapp', 'buy_online'] as $objetivo) {
            $this->comercio->online = 'https://ferreteriaeltornillo.com.ar';
            $this->comercio->save();
            $this->configurar($objetivo, null, self::HABILIDADES_SIN_RESERVA);

            $system   = $this->system_generado();
            $pos_capa = mb_strpos($system, 'Objetivo de la conversación, que te pidió el negocio:');

            $this->assertNotFalse($pos_capa, $objetivo);
            $this->assertStringNotContainsString('consult', mb_substr($system, $pos_capa), 'La capa del objetivo (' . $objetivo . ') no manda a consultar: pisa lo que escribió el dueño.');
        }
    }

    /**
     * El dueño que NO reserva y además usa el objetivo «Que pase por el local»: lo que escribió viaja antes de las
     * reglas y de la capa, y la capa no trae ninguna orden que lo contradiga. La combinación se midió contra DeepSeek
     * (ver el informe).
     *
     * @group whatsapp
     * @test
     */
    public function el_dueno_que_no_reserva_con_el_objetivo_del_local_conserva_su_negativa()
    {
        $this->configurar('visit_branch', null, self::HABILIDADES_SIN_RESERVA);

        $system = $this->system_generado();

        $pos_dueno  = mb_strpos($system, self::HABILIDADES_SIN_RESERVA);
        $pos_regla  = mb_strpos($system, self::REGLA_NO_LO_NIEGUES);
        $pos_capa   = mb_strpos($system, 'Objetivo de la conversación, que te pidió el negocio:');

        $this->assertNotFalse($pos_dueno, 'Lo que escribió el dueño viaja entero.');
        $this->assertTrue($pos_dueno < $pos_regla, 'Lo del dueño va antes de las reglas.');
        $this->assertTrue($pos_regla < $pos_capa, 'Y las reglas antes de la capa, que va al final.');
        $this->assertStringContainsString('Si estas instrucciones lo dicen, contestá eso, que sí o que no.', $system, 'La regla fija deja la salida del dueño.');
    }

    /**
     * El cierre de la capa (todos los objetivos) NO se tocó: la primera versión le sumó "no le digas que no, decile que
     * lo consultás", y el chequeo independiente mostró que se pasaba (aplicaba también a links y descuentos, que el
     * sistema sí sabe contestar).
     *
     * @group whatsapp
     * @test
     */
    public function el_cierre_de_la_capa_no_obliga_a_consultar_todo()
    {
        $this->comercio->online = 'https://ferreteriaeltornillo.com.ar';
        $this->comercio->save();

        foreach (['order_by_whatsapp', 'buy_online', 'visit_branch'] as $objetivo) {
            $this->configurar($objetivo);

            $this->assertStringContainsString('reservas ni descuentos. Las reglas fijas de arriba siguen ganando.', $this->system_generado(), $objetivo);
        }
    }

    /**
     * Sin objetivo la capa no existe (nada nuevo para quien no lo usa): la única diferencia con el prompt de antes es
     * la regla fija nueva, que está dentro del bloque de reglas.
     *
     * @group whatsapp
     * @test
     */
    public function sin_objetivo_no_aparece_ninguna_frase_de_la_capa()
    {
        $system = $this->system_generado();

        $this->assertStringNotContainsString('Objetivo de la conversación, que te pidió el negocio:', $system);
        $this->assertStringNotContainsString('no inventes direcciones, links, horarios', $system);
    }
}
