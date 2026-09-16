<?php

namespace App\Services\AsistenteIa;

use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AccionesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteImagenHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\MencionesIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Traits\TonoDeRedaccionIa;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El cerebro del chat con el asistente de IA del negocio.
 *
 * Copia adaptada del loop de tool use de admin-api
 * (SupportAiSuggestionService): pedir → si Claude corta con tool_use,
 * ejecutar las consultas, devolver los tool_result y repetir, hasta
 * end_turn o hasta el techo de iteraciones. Las cuatro tools son de
 * LECTURA pura y delegan en ConsultasSistemaIaHelper filtrando por el
 * user_id del DUEÑO resuelto desde la conversación (nunca desde Auth:
 * esto corre adentro de un job sin sesión).
 *
 * Misión asistente-ia-acciones (15/9/2026): si el mensaje del assistant
 * tiene `acciones_habilitadas` (la SPA nueva manda `acciones: true`), el
 * loop suma las herramientas de carga de HerramientasDeCarga, que PROPONEN
 * gastos, pagos y tareas como tarjetas que la persona confirma (nunca
 * escriben en el sistema), con el prompt que las explica y otro techo de
 * iteraciones. Sin el flag queda exactamente como antes: las mismas tools,
 * el mismo prompt de solo lectura y el mismo techo.
 *
 * El consumo de tokens se registra en CADA iteración del loop (cada
 * respuesta de la API trae su propio bloque usage); el registro nunca
 * lanza (AiTokenUsageHelper).
 */
class AsistenteIaService
{
    use TonoDeRedaccionIa;

    /** Máximo de iteraciones del loop de tool use, para evitar bucles infinitos. */
    const MAX_TOOL_ITERATIONS = 5;

    /**
     * Techo de iteraciones cuando el mensaje tiene las herramientas de carga. Armar una carga
     * encadena más llamadas que una consulta (buscar el cliente → sus cuentas → las opciones de
     * carga → proponer), y con 5 una carga completa quedaba al borde del corte.
     *
     * Cuando se subió a 8 (misión asistente-ia-acciones) NO se subió el presupuesto, y así el techo
     * de vueltas quedó más alto que el tiempo para gastarlas: el que cortaba era el reloj, no las
     * iteraciones. PRESUPUESTO_SEGUNDOS lo alinea (ver la cuenta ahí).
     */
    const MAX_TOOL_ITERATIONS_CON_ACCIONES = 8;

    /** Techo de mensajes del historial que viajan a la API. */
    const MAX_MENSAJES_HISTORIAL = 30;

    /**
     * Techo de caracteres acumulados del historial (~6-8k tokens): una
     * conversación larga no puede convertir cada mensaje nuevo en un gasto
     * creciente sin límite.
     */
    const MAX_CARACTERES_HISTORIAL = 24000;

    /** Techo de tokens de la respuesta. */
    const MAX_TOKENS = 1500;

    /**
     * Timeout de cada llamada HTTP a Anthropic, en segundos (alineado con el
     * timeout(60) de WhatsappBotAiService: una respuesta de chat que tarda
     * más que eso ya está perdida para el usuario). Es el primer escalón de la
     * cadena de techos: la cuenta completa está en PRESUPUESTO_SEGUNDOS.
     */
    const TIMEOUT_SEGUNDOS = 60;

    /**
     * Presupuesto acumulado del loop completo, en segundos: al inicio de cada
     * iteración, si ya pasó este techo desde el arranque, se corta con el
     * error amigable en vez de pedir otra llamada.
     *
     * Existe porque en WAMP/Windows sin pcntl el $timeout del job NO se
     * aplica (Laravel lo implementa con pcntl_alarm): sin este techo, un
     * Anthropic colgado que responde lento —sin vencer el timeout HTTP— puede
     * retener el worker compartido con las importaciones tantas llamadas
     * enteras como iteraciones tenga el techo. 🔴 ES EL ÚNICO TECHO DE TIEMPO
     * QUE RIGE DE VERDAD EN ESTA MÁQUINA.
     *
     * LA CUENTA DE LOS 210 (misión agente-ia-mano-derecha). El chequeo va ANTES de cada llamada,
     * así que el presupuesto tiene que alcanzar para las 7 vueltas previas a la última:
     *
     *   210 / (MAX_TOOL_ITERATIONS_CON_ACCIONES - 1) = 30s por vuelta (llamada + tools)
     *
     * Una vuelta del chat son 5-20s, así que las 8 entran cómodas y el corte por presupuesto queda
     * para el caso patológico, que es para lo que se escribió. Con 150 daban 21s por vuelta: el
     * reloj cortaba antes que el techo de iteraciones que se había subido a 8.
     *
     * Y LA CADENA COMPLETA, que tiene que quedar coherente en los cuatro escalones:
     *
     *   1. TIMEOUT_SEGUNDOS = 60      una llamada HTTP, lo único que corta a Anthropic
     *   2. PRESUPUESTO_SEGUNDOS = 210 el loop no arranca una vuelta nueva pasado esto
     *   3. peor caso = 210 + 60 = 270 el presupuesto más la llamada en vuelo, que no se corta
     *   4. $timeout del job = 300     tiene que ser MAYOR que 270 (donde hay pcntl, si no mataría
     *                                 al worker antes del corte prolijo) más el margen de las
     *                                 tools, los saves y el broadcast
     *
     * El quinto escalón vive en la SPA (ai_chat.js): el aviso de demora tiene que estar por encima
     * de 270, no de 210 — si no, la SPA dice "demorado" y deja de pollear mientras el servidor
     * todavía está trabajando bien.
     */
    const PRESUPUESTO_SEGUNDOS = 210;

    /**
     * Los pares (tipo, id, texto) que dejaron las tools de la respuesta que se está generando, sin
     * cruzar todavía contra el texto (misión agente-ia-mano-derecha, §1 del contrato).
     *
     * 🔴 SE JUNTAN EN execute_tool_calls() Y NO RELEYENDO LOS tool_result DE $messages. Los dos
     * caminos llegan a lo mismo, pero acá el dato está crudo y con el nombre de la tool al lado:
     * releyendo $messages habría que json_decode cada content y reconstruir a qué tool pertenece
     * cruzando `tool_use_id` contra los bloques del assistant, que es el mismo trabajo hecho al
     * revés y con una forma más de romperse.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $candidatos_a_mencion = [];

    /**
     * Las menciones de la ÚLTIMA respuesta que generó responder(), ya cruzadas contra su texto.
     *
     * 🔴 POR QUÉ NO SE DEVUELVEN EN responder(). responder() devuelve `string` y lo usa el job, los
     * tests y el resto del sistema: cambiarle el tipo de retorno a un array obligaría a tocar cada
     * llamador para ganar nada. El texto sigue siendo el valor de la función y las menciones se
     * piden al lado, como `hay_credenciales()`.
     *
     * @var array<int, array<string, mixed>>
     */
    protected $menciones = [];

    /**
     * true si hay clave de Anthropic configurada. No tener IA contratada no
     * es un error: sin clave, el job deja el mensaje en error amigable sin
     * salir a la red.
     *
     * @return bool
     */
    public function hay_credenciales(): bool
    {
        return (string) config('services.anthropic.api_key') !== '';
    }

    /**
     * Genera la respuesta del assistant para una conversación, corriendo el
     * loop de tool use completo.
     *
     * @param AiConversation $conversation Conversación con historial en la base.
     * @param AiMessage $assistant_message El mensaje 'pendiente' que se está generando
     *                                     (queda afuera del historial por su estado).
     * @return string Texto final de la respuesta. Las menciones de esa misma respuesta quedan en
     *                menciones(), que se pide al lado (ver la propiedad $menciones).
     *
     * @throws AsistenteIaException Si la API falla o el loop termina sin texto. Extiende
     *                              RuntimeException, y lleva el motivo para que el job elija qué
     *                              texto ve la persona.
     */
    public function responder(AiConversation $conversation, AiMessage $assistant_message): string
    {
        /*
         * Se limpian las dos por si el servicio se reusa para más de un mensaje: los candidatos de
         * una respuesta anterior en el texto de esta serían menciones de otra conversación.
         */
        $this->candidatos_a_mencion = [];
        $this->menciones = [];

        $owner = User::find($conversation->user_id);

        /*
         * Misión asistente-ia-acciones: con `acciones_habilitadas` el loop lleva
         * las herramientas de carga, el prompt que las explica y el techo de
         * iteraciones más alto. Sin el flag, lo de siempre.
         */
        $con_acciones = (bool) $assistant_message->acciones_habilitadas;

        /*
         * Misión asistente-por-whatsapp: el canal sale del MENSAJE y no de la
         * conversación, por el mismo motivo por el que `acciones_habilitadas`
         * vive en el mensaje. Una conversación de WhatsApp se puede seguir
         * desde el panel del chat (es la misma conversación, §4 del plan): ese
         * mensaje tiene canal 'sistema', la persona está mirando la pantalla y
         * su respuesta tiene que traer tarjetas con Confirmar, no el flujo de
         * confirmación por texto.
         */
        $es_whatsapp = $assistant_message->es_de_whatsapp();

        $system   = $this->build_system_payload($conversation, $owner, $con_acciones, $es_whatsapp);
        $messages = $this->build_messages_payload($conversation);
        $tools    = $this->build_tools($con_acciones, $es_whatsapp);
        $model    = (string) config('services.anthropic.model');
        $http     = $this->build_http_client();

        $max_iterations = $con_acciones ? self::MAX_TOOL_ITERATIONS_CON_ACCIONES : self::MAX_TOOL_ITERATIONS;

        $iterations = 0;
        $final_text = '';
        $inicio_del_loop = time();

        while ($iterations < $max_iterations) {
            /*
             * Presupuesto acumulado (ver PRESUPUESTO_SEGUNDOS): el chequeo va
             * ANTES de cada llamada — la que ya está en vuelo no se puede
             * cortar, pero no se arranca una nueva con el tiempo vencido.
             */
            if ((time() - $inicio_del_loop) > self::PRESUPUESTO_SEGUNDOS) {
                Log::warning('AsistenteIaService: presupuesto de tiempo del loop agotado.', [
                    'ai_conversation_id' => $conversation->id,
                    'iterations'         => $iterations,
                ]);

                throw AsistenteIaException::tiempo_agotado(
                    'presupuesto de tiempo del loop agotado (' . self::PRESUPUESTO_SEGUNDOS . 's) tras ' . $iterations . ' iteraciones'
                );
            }

            $iterations++;

            $response = $http->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => self::MAX_TOKENS,
                'system'     => $system,
                'tools'      => $tools,
                'messages'   => $messages,
            ]);

            if (! $response->successful()) {
                /*
                 * Mismo manejo de errores transitorios que ResumenIaService:
                 * overloaded_error / api_error / HTTP 529 reciben un mensaje amigable.
                 */
                $error_body = $response->json();
                $error_type = $error_body['error']['type'] ?? null;

                $transient_error_types = ['overloaded_error', 'api_error'];

                if (in_array($error_type, $transient_error_types) || $response->status() === 529) {
                    throw AsistenteIaException::sobrecargado(
                        '(HTTP ' . $response->status() . ', type ' . (is_null($error_type) ? 'sin type' : (string) $error_type) . ')'
                    );
                }

                /*
                 * 🔴 El body crudo va al detalle técnico y NO a la pantalla: es justo lo que
                 * AsistenteIaException::MOTIVO_FALLA_TECNICA resuelve, cayendo al genérico del job.
                 */
                throw AsistenteIaException::falla_tecnica(
                    'Error al comunicarse con Claude API (HTTP ' . $response->status() . '): ' . $response->body()
                );
            }

            $response_body = $response->json();

            // Cada iteración trae su propio usage: se registra acá y no al final del loop.
            AiTokenUsageHelper::registrar([
                'user_id'            => $conversation->user_id,
                'auth_user_id'       => $conversation->auth_user_id,
                'proceso'            => 'chat_mensaje',
                'modelo'             => $model,
                'body'               => is_array($response_body) ? $response_body : [],
                'ai_conversation_id' => $conversation->id,
            ]);

            $stop_reason    = (string) ($response_body['stop_reason'] ?? '');
            $content_blocks = isset($response_body['content']) && is_array($response_body['content'])
                ? $response_body['content']
                : [];

            if ($stop_reason === 'tool_use') {
                // Normalizar bloques antes de reenviarlos: PHP decodifica input:{} como [] y Anthropic exige object.
                $messages[] = [
                    'role'    => 'assistant',
                    'content' => $this->normalize_assistant_content_for_api($content_blocks),
                ];

                // Ejecutar cada tool_use y devolver los tool_result como mensaje user.
                // El mensaje viaja para que las herramientas de carga cuelguen de él sus tarjetas.
                $messages[] = [
                    'role'    => 'user',
                    'content' => $this->execute_tool_calls($content_blocks, $conversation, $assistant_message),
                ];

                continue;
            }

            // end_turn (o stop_reason desconocido): extraer el texto y salir.
            $final_text = $this->extract_response_text($response_body);
            break;
        }

        $final_text = trim($final_text);

        if ($final_text === '') {
            Log::warning('AsistenteIaService: el loop terminó sin texto final.', [
                'ai_conversation_id' => $conversation->id,
                'iterations'         => $iterations,
            ]);

            /*
             * Dos finales distintos con el mismo síntoma: el loop se comió TODAS las vueltas
             * encadenando tools y nunca llegó a contestar (a la persona hay que decirle que acote
             * la consulta: repetirla tal cual va a volver a chocar con el mismo techo), o cerró
             * antes sin texto, que es otra cosa y se reintenta igual.
             */
            if ($iterations >= $max_iterations) {

                throw AsistenteIaException::tiempo_agotado(
                    'el loop llegó al techo de ' . $max_iterations . ' iteraciones sin texto final'
                );
            }

            throw AsistenteIaException::sin_respuesta(
                'el loop terminó sin texto final en la iteración ' . $iterations
            );
        }

        /*
         * §1 del contrato: las menciones salen de cruzar lo que devolvieron las tools de ESTA
         * respuesta contra el texto que escribió el modelo. Va acá y no adentro del loop porque
         * recién con el texto final se sabe a quién nombró.
         *
         * Protegido: una mención es un adorno clickeable. Si el cruce falla por lo que sea, la
         * respuesta ya está escrita y tiene que llegar igual — sin menciones, como la de una SPA
         * vieja.
         */
        try {
            $this->menciones = MencionesIaHelper::cruzar(
                $this->candidatos_a_mencion,
                $final_text,
                (int) $conversation->user_id
            );
        } catch (\Throwable $e) {
            Log::warning('AsistenteIaService: no se pudieron armar las menciones de la respuesta.', [
                'ai_conversation_id' => $conversation->id,
                'error'              => $e->getMessage(),
            ]);

            $this->menciones = [];
        }

        return $final_text;
    }

    /**
     * Las menciones de la última respuesta generada por responder(): `[{ tipo, id, texto }]` con
     * `tipo` ∈ cliente|articulo y `texto` el literal exacto tal como aparece en el texto.
     *
     * Vacío si no hubo ninguna, si el loop falló o si todavía no se llamó a responder().
     *
     * @return array<int, array<string, mixed>>
     */
    public function menciones(): array
    {
        return $this->menciones;
    }

    /**
     * Texto del bloque 1 del system: identidad, tono, formato texto plano y
     * reglas de qué puede afirmar. Es el prefijo común a TODAS las
     * conversaciones (por eso es el que lleva cache_control).
     *
     * @param AiConversation $conversation
     * @param User|null $owner Dueño de la cuenta, para el nombre del negocio.
     * @param bool $con_acciones true si el mensaje tiene las herramientas de carga.
     * @param bool $es_whatsapp true si el mensaje entró por WhatsApp (misión asistente-por-whatsapp).
     * @return string
     */
    public function build_system_prompt(AiConversation $conversation, $owner, $con_acciones = false, $es_whatsapp = false): string
    {
        // Mismo fallback que el resto del sistema cuando el negocio no cargó su nombre.
        $company_name = '';
        if (! is_null($owner)) {
            $company_name = trim((string) ($owner->company_name ?: $owner->name));
        }
        if ($company_name === '') {
            $company_name = 'el negocio';
        }

        $fecha = now()->format('d/m/Y');

        /*
         * Misión asistente-ia-acciones: el día de la semana de hoy y los
         * próximos 7 días con su nombre, escritos a mano (FormatoIaHelper, sin
         * depender del locale). Sin esto la IA calculaba sola qué fecha es
         * "este viernes" y se equivocaba: el 15/9/2026, martes, contestó
         * "viernes 19/09", que es sábado. Va en las dos variantes, porque las
         * consultas ("lo que vendí el lunes") hacen la misma cuenta. La oración
         * "Hoy es {fecha}." queda literal adelante.
         */
        $dia_de_hoy = FormatoIaHelper::dia_de_la_semana(now());
        $proximos_dias = FormatoIaHelper::proximos_dias(now());
        $dias_anteriores = FormatoIaHelper::dias_anteriores(now());

        // El bloque de registro sale del trait compartido para que el chat y los tres
        // resumenes de sugerencias suenen igual: es la MISMA IA para el que la lee, y un
        // ajuste de tono aplicado en un solo lado deja los otros hablando distinto.
        $tono = $this->reglas_de_tono();

        /*
         * Sin acciones, el renglón de solo lectura de siempre, tal cual. Con
         * acciones ese renglón no va (sería mentira) y en su lugar se suma el
         * bloque "Qué podés cargar", con sus reglas.
         */
        $regla_de_solo_lectura = $con_acciones ? '' : $this->regla_de_solo_lectura();
        $bloque_de_carga = $con_acciones ? $this->bloque_de_carga() : '';

        /*
         * Misión asistente-por-whatsapp: el bloque del canal va DESPUÉS del de
         * carga a propósito, porque lo corrige. El de carga habla de "la
         * tarjeta que la persona confirma" y en WhatsApp no hay tarjeta que
         * tocar: la confirmación es por texto.
         */
        $bloque_de_whatsapp = $es_whatsapp ? $this->bloque_de_whatsapp($con_acciones) : '';

        return <<<SYSTEM
Sos el asistente de inteligencia artificial del negocio "{$company_name}". Trabajás
adentro del sistema de gestión de ese negocio y le hablás a la persona que lo usa.

Cómo hablás:
- Español rioplatense, tuteando. Nada de solemnidad ni de "estimado usuario".
{$tono}
- Respuestas cortas: hasta 6 oraciones, salvo que te pidan explícitamente el detalle.
- No saludes ni te presentes en cada mensaje: la conversación ya está empezada.

Formato de la respuesta (regla dura):
- TEXTO PLANO. Está prohibido el markdown: nada de asteriscos, almohadillas,
  guiones de lista, tablas ni bloques de código. La pantalla que te muestra NO
  interpreta markdown y los símbolos se ven crudos.
- Si tenés que enumerar, usá líneas cortas separadas por salto de línea, empezando
  cada una con la cosa nombrada. Nunca con "- " ni "* ".

Qué podés afirmar:
- SOLO lo que salga de las herramientas de consulta o del contexto de esta
  conversación. Nunca inventes números, precios, saldos, stock ni fechas.
- Si no tenés el dato, decilo en una oración y ofrecé qué sí podés consultar.
- Las herramientas devuelven 20 registros por vez. Varias te dicen además cuántos hay EN
  TOTAL (encontrados) y cuántos te mandaron (en_esta_lista): cuando difieren, el número del
  negocio es el total, nunca la cantidad de filas que tenés a la vista.
- Si necesitás más filas de las que entraron, pedí la página siguiente o subí el límite en
  la misma herramienta, hasta 100. Recién cuando ya no podés traer más, aclará que hay más
  y que es un tope de la consulta, no del negocio: no te disculpes por un límite que podés
  correr vos.
{$regla_de_solo_lectura}- Los importes son en pesos argentinos, salvo los de una cuenta corriente o una carga en
  dólares, que se escriben con US$.

{$bloque_de_carga}{$bloque_de_whatsapp}Hoy es {$fecha}. Es {$dia_de_hoy}. Usalo para interpretar "este mes", "la semana
pasada" y similares.
Los próximos 7 días son: {$proximos_dias}.
Los 7 días anteriores fueron: {$dias_anteriores}.
SYSTEM;
    }

    /**
     * El renglón de solo lectura del prompt, idéntico al de antes de la
     * misión (con su salto de línea final). Solo va cuando el mensaje NO
     * tiene las herramientas de carga.
     *
     * @return string
     */
    protected function regla_de_solo_lectura(): string
    {
        return <<<REGLA
- Solo podés LEER. No podés crear, modificar ni borrar nada del sistema, ni mandar
  mensajes, ni prometer que vas a hacerlo. Si te lo piden, explicá adónde ir en el
  sistema para hacerlo a mano.

REGLA;
    }

    /**
     * Las reglas de carga del prompt (plan §3.7, con la redacción pulida y
     * sin sacar ninguna regla), con una línea en blanco al final. Solo va
     * cuando el mensaje tiene las herramientas de carga.
     *
     * 🔴 Cada regla tapa un error concreto: la IA que dice "listo, cargado"
     * cuando solo dejó una tarjeta; la que supone el monto o la caja en vez
     * de preguntar; la que inventa un motivo distinto del que devolvió la
     * herramienta; la que vuelve a proponer lo que el historial ya dice que
     * se confirmó. No se "simplifica" sacando renglones.
     *
     * @return string
     */
    protected function bloque_de_carga(): string
    {
        return <<<CARGA
Qué podés cargar, siempre con una tarjeta que la persona confirma:
- Gastos, pagos de clientes, pagos a proveedores, tareas nuevas de la agenda, cambios en
  una tarea, marcar una tarea como hecha, armar un combo y armar una oferta para un
  cliente. Nada más: no anulás ni editás gastos o pagos, no creás clientes, proveedores ni
  subcategorías, no mandás mensajes, y los cheques, los cobros con tarjeta de crédito y los
  cobros en otra moneda que la de la cuenta se cargan desde la pantalla.
- Una oferta se le muestra al cliente en la tienda; desde el chat no se le manda ningún mail
  ni WhatsApp, y eso decíselo a la persona.
- Vos nunca registrás nada: llamás a la herramienta proponer_ que corresponde y el sistema
  le muestra a la persona una tarjeta con Confirmar y Cancelar. Nunca digas "ya lo cargué",
  "listo" ni "registrado": decí que dejaste la tarjeta para confirmar y resumila en una línea.
- Antes de proponer, juntá todos los datos. Si falta algo (cuánto, a quién, cómo se pagó, a
  qué caja, qué día, qué subcategoría) o hay más de una opción posible (dos clientes con
  nombre parecido, una cuenta en pesos y otra en dólares, varias subcategorías que encajan),
  preguntá en UN solo mensaje corto todo lo que falta, ofreciendo las opciones por su nombre.
  No supongas montos, personas, cajas ni fechas. 🔴 Si la fecha que pidieron es futura, lo único
  que puede faltar es el monto y la subcategoría: NO preguntes cómo se paga ni a qué caja va,
  porque eso se pregunta el día que la tarea se marca como hecha.
- Si la herramienta devuelve "faltan", preguntá eso. Si devuelve "error", contá ese motivo
  tal cual y no agregues otro. Si devuelve "confirmada_parecida", avisá que hace un momento
  se confirmó una carga parecida y que confirme esta solo si es otra carga.
- Un gasto con fecha futura todavía no es un gasto: se agenda como tarea con su gasto
  asociado. Un pago futuro, como tarea para cobrar o pagar ese día. Para una tarea con gasto
  preguntá el monto una vez; si la persona no lo sabe, se agenda sin monto.
- 🔴 Con fecha futura NO preguntes cómo se paga ni a qué caja va: eso se pregunta el día que
  la tarea se marca como hecha. Pedí solo el monto si falta, y proponé la tarea.
- Si la persona corrige una tarjeta, proponé una nueva: la anterior queda reemplazada sola.
  Si la corrección cambia la subcategoría, la cuenta o la tarea, pasá en reemplaza_a la
  tarjeta que corrige.
- Convertí las fechas relativas ("este viernes", "ayer") con las listas de días de abajo
  —una hacia adelante y otra hacia atrás— y escribí la fecha con el día de la semana.
- Si la persona no tiene permiso para algo, decile que no tiene permiso para cargarlo desde
  su usuario.
- Nunca muestres ni pidas números internos (ids).
- Las líneas del historial que empiezan con "[Tarjeta" las escribe el sistema: te dicen qué
  pasó con cada tarjeta. No las repitas.

CARGA;
    }

    /**
     * Las reglas del canal de WhatsApp (misión asistente-por-whatsapp, §3.5 del
     * plan), con una línea en blanco al final. Solo va cuando el mensaje entró
     * por WhatsApp.
     *
     * 🔴 VA DESPUÉS DEL BLOQUE DE CARGA PORQUE LO CORRIGE. El bloque de carga
     * dice "el sistema le muestra a la persona una tarjeta con Confirmar y
     * Cancelar" y en WhatsApp eso es mentira: no hay nada que tocar. Dejarlo
     * sin corregir es la peor forma de fallar de este canal — el dueño lee
     * "te dejé la tarjeta para que la confirmes", busca la tarjeta, no la
     * encuentra, y el gasto no se carga nunca.
     *
     * La segunda mitad (la confirmación por texto) solo se escribe cuando el
     * mensaje tiene las herramientas de carga: sin ellas no hay nada que
     * confirmar y el renglón sobraría.
     *
     * @param bool $con_acciones true si el mensaje tiene las herramientas de carga.
     * @return string
     */
    protected function bloque_de_whatsapp($con_acciones = false): string
    {
        $confirmacion = $con_acciones ? $this->bloque_de_confirmacion_por_texto() : '';

        return <<<WHATSAPP
Estás hablando por WhatsApp, no por la pantalla del sistema:
- La persona te lee en el teléfono, muchas veces en la calle. Mensajes cortos de verdad:
  dos o tres oraciones. Si hay mucho para contar, decí lo importante y ofrecé el detalle.
- Nunca nombres botones, pantallas, tarjetas ni "el panel": acá no hay nada para tocar.
  Si algo se hace desde el sistema, decí en qué parte del sistema, no qué botón apretar.
- La mayoría de las veces te va a hablar por audio y te llega ya pasado a texto. Si te
  llega un audio sin transcribir, decilo en una línea y pedile que te lo escriba.
{$confirmacion}
WHATSAPP;
    }

    /**
     * Cómo se confirma una carga cuando no hay tarjeta que tocar.
     *
     * 🔴 La regla del medio (no confirmar en el mismo mensaje en el que se
     * propone) está además puesta con una guarda dura en
     * HerramientasDeCarga::confirmar_carga_pendiente(): el prompt la explica
     * para que la IA no la intente, y la guarda la sostiene para cuando la
     * intente igual. Sin las dos, la IA puede proponer y confirmar de una sola
     * pasada y la persona se entera de la carga cuando ya está hecha.
     *
     * @return string
     */
    protected function bloque_de_confirmacion_por_texto(): string
    {
        return <<<CONFIRMACION
- Cómo se confirma una carga acá, que REEMPLAZA lo de la tarjeta: llamás igual a la
  herramienta proponer_ que corresponde, pero no digas que dejaste una tarjeta. Decí los
  datos exactos de lo que vas a cargar (cuánto, a quién, de qué, qué día, cómo se paga) y
  preguntá si lo registrás.
- Recién cuando la persona te contesta que sí, llamás a confirmar_carga_pendiente con el
  tarjeta_id que te devolvió la propuesta. Si te dice que no, cancelar_carga_pendiente.
- 🔴 No podés proponer y confirmar en el mismo mensaje: siempre tiene que haber una
  respuesta de la persona en el medio. Si lo intentás, la herramienta te lo rechaza.
- Nunca digas que algo quedó cargado hasta que confirmar_carga_pendiente te conteste que
  sí. Cuando te conteste, repetí lo que te devolvió (el número del gasto, del pago o de la
  compra) en una línea.
CONFIRMACION;
    }

    /**
     * Array `system` completo para la API: el bloque 1 (común, con
     * cache_control ephemeral) y, si la conversación tiene contexto de
     * fondo (datos ya calculados de una sugerencia), un bloque 2 SIN
     * cache_control — el prefijo cacheable tiene que ser idéntico entre
     * conversaciones para que el caché pegue.
     *
     * @param AiConversation $conversation
     * @param User|null $owner
     * @param bool $con_acciones true si el mensaje tiene las herramientas de carga.
     * @param bool $es_whatsapp true si el mensaje entró por WhatsApp (misión asistente-por-whatsapp).
     * @return array<int, array<string, mixed>>
     */
    public function build_system_payload(AiConversation $conversation, $owner, $con_acciones = false, $es_whatsapp = false): array
    {
        $system = [
            [
                'type'          => 'text',
                'text'          => $this->build_system_prompt($conversation, $owner, $con_acciones, $es_whatsapp),
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];

        $contexto = trim((string) ($conversation->contexto ?? ''));

        if ($contexto !== '') {
            $system[] = [
                'type' => 'text',
                'text' => "Contexto de fondo de esta conversación (datos ya calculados por el sistema, no los recalcules):\n"
                    . $contexto,
            ];
        }

        return $system;
    }

    /**
     * Convierte el historial de la conversación en el array `messages` de la
     * API, con techo doble (mensajes y caracteres) y la misma disciplina de
     * alternancia que WhatsappBotAiService: turnos consecutivos del mismo
     * rol se funden en uno, y la conversación tiene que arrancar en 'user'.
     *
     * Solo viajan los mensajes 'listo' con contenido: el assistant
     * 'pendiente' que se está generando y los mensajes en error quedan
     * afuera solos por el filtro de estado.
     *
     * Misión asistente-ia-acciones: un mensaje del assistant con tarjetas de
     * carga suma al final una línea por tarjeta (ver
     * contenido_para_el_historial()). Un mensaje sin tarjetas viaja idéntico.
     *
     * Misión asistente-por-whatsapp: un mensaje del dueño puede traer fotos.
     * El `content` de un turno pasa a ser ARRAY DE BLOQUES (imágenes primero,
     * el texto al final) SOLO en ese caso; sin fotos sigue siendo el string de
     * siempre, y el chat de la pantalla arma exactamente el mismo payload que
     * antes de la misión.
     *
     * 🔴 SOLO EL ÚLTIMO MENSAJE DEL USUARIO MANDA SUS FOTOS EN BASE64. Las de
     * los mensajes anteriores viajan como "[Foto adjunta]" dentro del texto
     * (ver contenido_para_el_historial()). Sin esta regla, cada turno reenvía
     * todo el historial de imágenes y el costo de una conversación crece sin
     * techo: cinco fotos charladas durante la mañana se pagarían de nuevo en
     * cada pregunta de la tarde. Lo que hace que no se pierda nada es que la
     * foto no la necesita el modelo para cargar la compra: la engancha
     * proponer_compra_con_factura leyendo las que todavía no se gestionaron.
     *
     * @param AiConversation $conversation
     * @return array<int, array{role: string, content: string|array}>
     */
    public function build_messages_payload(AiConversation $conversation): array
    {
        /*
         * Los últimos N en orden inverso: el recorte por caracteres protege lo más nuevo.
         *
         * 🔴 UN MENSAJE CON FOTOS VIAJA AUNQUE NO TENGA TEXTO. Mandar la foto de la factura sin
         * escribir nada es el caso normal de WhatsApp, y con el filtro de siempre
         * (`contenido != ''`) ese mensaje quedaba afuera del historial: el modelo nunca veía la
         * foto, y la conversación tenía un hueco justo donde estaba lo importante. Para todo lo que
         * no tiene fotos —o sea, todo el chat de la pantalla— la condición es exactamente la de
         * antes.
         */
        $recientes = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('estado', 'listo')
            ->where(function ($query) {
                $query->where(function ($con_texto) {
                    $con_texto->whereNotNull('contenido')->where('contenido', '!=', '');
                })->orWhereExists(function ($con_fotos) {
                    $con_fotos->selectRaw('1')
                        ->from('ai_message_imagenes')
                        ->whereColumn('ai_message_imagenes.ai_message_id', 'ai_messages.id');
                });
            })
            ->orderBy('id', 'DESC')
            ->limit(self::MAX_MENSAJES_HISTORIAL)
            ->with(['acciones', 'imagenes'])
            ->get();

        $seleccionados = [];
        $acumulado = 0;

        foreach ($recientes as $message) {
            $largo = mb_strlen($this->contenido_para_el_historial($message));

            /*
             * El mensaje que hace pasar el techo queda afuera, salvo que sea
             * el primero (el más nuevo): sin él no habría nada que responder.
             */
            if ($acumulado + $largo > self::MAX_CARACTERES_HISTORIAL && count($seleccionados) > 0) {
                break;
            }

            $acumulado += $largo;
            $seleccionados[] = $message;
        }

        // Recién acá se ordena ascendente (venían del más nuevo al más viejo).
        $seleccionados = array_reverse($seleccionados);

        /*
         * Cuál es el último mensaje del usuario de los que van a viajar: el único que puede
         * mandar sus fotos en base64 (ver el 🔴 del docblock). Se decide ya ordenado y ya
         * recortado, para que sea el último REAL del payload y no el último de la base.
         */
        $id_ultimo_user = 0;

        foreach ($seleccionados as $message) {
            if ($message->rol === 'user') {
                $id_ultimo_user = (int) $message->id;
            }
        }

        $turns = [];

        foreach ($seleccionados as $message) {
            $body = trim($this->contenido_para_el_historial($message, (int) $message->id === $id_ultimo_user));

            $imagenes = (int) $message->id === $id_ultimo_user
                ? $this->bloques_de_imagen($message)
                : [];

            if ($body === '' && count($imagenes) === 0) {
                continue;
            }

            $role = $message->rol === 'user' ? 'user' : 'assistant';
            $last_index = count($turns) - 1;

            if ($last_index >= 0 && $turns[$last_index]['role'] === $role) {
                // Mismo rol que el turno anterior: se funde en un solo mensaje.
                $turns[$last_index]['texto'] = trim($turns[$last_index]['texto'] . "\n" . $body);
                $turns[$last_index]['imagenes'] = array_merge($turns[$last_index]['imagenes'], $imagenes);
            } else {
                $turns[] = ['role' => $role, 'texto' => $body, 'imagenes' => $imagenes];
            }
        }

        /*
         * La conversación tiene que arrancar en 'user' para la API de
         * Anthropic; si el recorte dejó un turno assistant al principio,
         * se descarta para no romper la alternancia.
         */
        while (! empty($turns) && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $this->turnos_para_la_api($turns);
    }

    /**
     * Pasa los turnos internos a la forma que espera la API.
     *
     * Un turno sin fotos viaja con `content` string, exactamente como antes de
     * la misión asistente-por-whatsapp: el chat de la pantalla no cambia de
     * payload por existir el canal de WhatsApp. Uno con fotos viaja con
     * `content` array de bloques, las imágenes primero y el texto al final.
     *
     * Un bloque `text` vacío NO se agrega: la API rechaza el request entero con
     * un 400 si un bloque de texto viene en blanco, y una foto sola (sin
     * epígrafe) es un mensaje perfectamente válido de WhatsApp.
     *
     * @param array<int, array{role: string, texto: string, imagenes: array}> $turns
     * @return array<int, array{role: string, content: string|array}>
     */
    protected function turnos_para_la_api(array $turns): array
    {
        $payload = [];

        foreach ($turns as $turn) {
            if (count($turn['imagenes']) === 0) {
                $payload[] = ['role' => $turn['role'], 'content' => $turn['texto']];

                continue;
            }

            $bloques = $turn['imagenes'];

            if (trim($turn['texto']) !== '') {
                $bloques[] = ['type' => 'text', 'text' => $turn['texto']];
            }

            $payload[] = ['role' => $turn['role'], 'content' => $bloques];
        }

        return $payload;
    }

    /**
     * Los bloques `image` de un mensaje, en base64, listos para la API.
     *
     * 🔴 El `media_type` sale DE LOS BYTES (AsistenteImagenHelper::media_type,
     * con getimagesizefromstring) y nunca de la columna `mime`. Es lo que ya
     * hace SupportAiImageCollector en el admin: si la columna dijera una cosa y
     * el archivo fuera otra, Anthropic rebota el request completo con un 400
     * que no nombra la imagen y la conversación entera queda muda.
     *
     * Una foto cuyo archivo ya no está, o que no es de un tipo que Anthropic
     * acepte, se saltea sin voltear nada: el texto del mensaje viaja igual.
     *
     * @param AiMessage $message
     * @return array<int, array<string, mixed>>
     */
    protected function bloques_de_imagen(AiMessage $message): array
    {
        if ($message->rol !== 'user' || ! $message->relationLoaded('imagenes') || $message->imagenes->isEmpty()) {
            return [];
        }

        $bloques = [];

        foreach ($message->imagenes as $imagen) {
            $binario = AsistenteImagenHelper::binario($imagen);

            if (is_null($binario)) {
                continue;
            }

            $media_type = AsistenteImagenHelper::media_type($binario);

            if (is_null($media_type)) {
                continue;
            }

            $bloques[] = [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $media_type,
                    'data'       => base64_encode($binario),
                ],
            ];
        }

        return $bloques;
    }

    /**
     * Texto de un mensaje tal como viaja en el historial. Un mensaje del
     * assistant con tarjetas suma al final una línea por tarjeta
     * (`[Tarjeta #12 · Gasto · ... · estado: confirmada (Gasto N° 88 registrado)]`,
     * ver AccionesIaHelper::linea_de_historial()): así la IA sabe qué se
     * confirmó, qué se canceló y qué quedó reemplazado, y no vuelve a
     * proponer lo que ya está cargado. Sin tarjetas, el contenido de siempre.
     *
     * Misión asistente-por-whatsapp: un mensaje del dueño con fotos que NO es
     * el último del payload suma "[Foto adjunta]" (o "[N fotos adjuntas]") al
     * final del texto. Es la contracara de la regla del base64: el modelo tiene
     * que saber que hubo una foto —para poder decir "la que me mandaste
     * recién"— sin que la imagen se vuelva a pagar en cada turno.
     *
     * @param AiMessage $message Con las relaciones `acciones` e `imagenes` cargadas.
     * @param bool $manda_las_imagenes true si este mensaje manda sus fotos en base64.
     * @return string
     */
    protected function contenido_para_el_historial(AiMessage $message, $manda_las_imagenes = false): string
    {
        $contenido = (string) $message->contenido;

        if ($message->rol === 'user') {
            if ($manda_las_imagenes || ! $message->relationLoaded('imagenes')) {
                return $contenido;
            }

            $cuantas = $message->imagenes->count();

            if ($cuantas === 0) {
                return $contenido;
            }

            $marca = $cuantas === 1 ? '[Foto adjunta]' : '[' . $cuantas . ' fotos adjuntas]';

            return trim($contenido) === '' ? $marca : rtrim($contenido) . "\n" . $marca;
        }

        if (! $message->relationLoaded('acciones') || $message->acciones->isEmpty()) {
            return $contenido;
        }

        $lineas = [];

        foreach ($message->acciones as $accion) {
            $lineas[] = AccionesIaHelper::linea_de_historial($accion);
        }

        return rtrim($contenido) . "\n" . implode("\n", $lineas);
    }

    /**
     * Las tools que viajan a la API para un mensaje. Sin acciones son
     * EXACTAMENTE las de lectura de siempre (herramientas_de_lectura()); con
     * acciones se suman las de HerramientasDeCarga, que declaran y despachan
     * sus herramientas juntas en su propio archivo.
     *
     * 🔴 EL ORDEN TIENE QUE SER ESTABLE ENTRE LLAMADAS, y no es cosmética: el caché de prompt de
     * Anthropic es por PREFIJO de bytes y el request se renderiza tools → system → messages, así
     * que las tools son el prefijo de todo. Mover una sola tool de lugar entre dos llamadas cambia
     * esos bytes y tira el caché entero, el de las tools y el del system que viene atrás. Por eso
     * las dos fuentes son arrays literales —registro_de_lectura() y HerramientasDeCarga::
     * definiciones()— recorridos en orden: sin sort, sin claves que vengan de la base y sin nada
     * que dependa del usuario, de la fecha o de la conversación. Lo único que cambia el juego de
     * tools es el flag `acciones`, que es por mensaje y da dos prefijos distintos, cada uno con su
     * propio caché.
 *
     * Misión asistente-por-whatsapp: con el canal de WhatsApp se suman además
     * confirmar_carga_pendiente y cancelar_carga_pendiente, que son el
     * equivalente de los botones Confirmar y Cancelar de la tarjeta. En el
     * sistema NO se declaran: ahí la decisión la toma la persona con el dedo,
     * y darle a la IA una herramienta para confirmar sola lo que ella misma
     * propuso sería sacarle el control a quien tiene que darlo.
     *
     * @param bool $con_acciones true si el mensaje tiene las herramientas de carga.
     * @param bool $es_whatsapp true si el mensaje entró por WhatsApp.
     * @return array<int, array<string, mixed>>
     */
    public function build_tools($con_acciones = false, $es_whatsapp = false): array
    {
        $tools = $this->herramientas_de_lectura();

        if ($con_acciones) {
            foreach (HerramientasDeCarga::definiciones($es_whatsapp) as $definicion) {
                $tools[] = $definicion;
            }
        }

        return $this->con_cache_control($tools);
    }

    /**
     * Le pone el marcador de caché a la ÚLTIMA tool del array, que es como la API de Anthropic
     * cachea el bloque `tools` COMPLETO: el marcador no cachea "esa tool", cierra el prefijo que
     * viene hasta ahí.
     *
     * POR QUÉ HACE FALTA SI EL BLOQUE 1 DEL SYSTEM YA TIENE UNO (build_system_payload): el system se
     * renderiza DESPUÉS de las tools, así que su marcador cachea tools+system juntos — pero ese
     * prefijo se rompe cada vez que el system cambia, y el system cambia siempre: lleva la fecha de
     * hoy, el nombre del negocio y el prompt de carga o el de solo lectura. Un marcador propio al
     * final de las tools deja el bloque de definiciones cacheado POR SÍ SOLO: sobrevive al cambio
     * de system, al cambio de día y al cambio de dueño, y lo comparten todas las conversaciones que
     * van con el mismo juego de tools.
     *
     * Lo que se ahorra, medido el 16/9/2026 sobre el JSON que se manda: 5.534 bytes de definiciones
     * de lectura (≈1,6k tokens) y 15.956 con las de carga (≈4,5k), en CADA iteración del loop —
     * hasta 8 por mensaje. El mínimo cacheable de un Sonnet es 1024 tokens, así que los dos casos
     * entran; abajo de ese piso la API no avisa nada, simplemente no cachea.
     *
     * Dos marcadores no cuestan dos escrituras: el tramo entre uno y otro se escribe una sola vez.
     * El techo de la API son 4 breakpoints por request y acá van 2 (tools y bloque 1 del system).
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    protected function con_cache_control(array $tools): array
    {
        if (empty($tools)) {

            return $tools;
        }

        $ultima = count($tools) - 1;

        $tools[$ultima]['cache_control'] = ['type' => 'ephemeral'];

        return $tools;
    }

    /**
     * El registro ÚNICO de las tools de LECTURA del asistente: cada entrada lleva JUNTAS su
     * definición para la API de Anthropic (name / description / input_schema) y el `handler` que la
     * resuelve cuando Claude la llama. Ninguna crea, modifica ni borra nada.
     *
     * 🔴 POR QUÉ UN REGISTRO Y NO DOS LISTAS: hasta esta misión toda tool se declaraba en DOS
     * lugares de este archivo —el array de definiciones y su rama del if/elseif de
     * execute_tool_calls()—, y con una sola de las dos la IA "tenía" la tool y al usarla recibía
     * "Tool desconocida". Acá las dos puntas son la MISMA entrada: agregar una tool es agregar un
     * elemento a este array, y olvidarse una punta dejó de ser posible. Es el mismo movimiento que
     * ya había hecho HerramientasDeCarga con sus definiciones y su despacho.
     *
     * El `handler` recibe (array $input, int $owner_id) y devuelve los DATOS crudos: el json_encode
     * con su fallback vive centralizado en contenido_de_tool_result() y la defensa del enum `dias`
     * en dias_del_enum(), en vez de repetidos ocho y tres veces. La clave `handler` va siempre
     * ÚLTIMA: herramientas_de_lectura() la saca, y lo que viaja a la API queda con el mismo orden
     * de claves de siempre.
     *
     * 🔴 EL ORDEN DE ESTE ARRAY ES PARTE DEL CACHÉ DE PROMPT (ver build_tools): no se reordena.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function registro_de_lectura(): array
    {
        return [
            [
                'name' => 'consultar_stock_de_articulos',
                'description' => 'Devuelve artículos activos del negocio con su precio y su stock actual. Filtrá por nombre, código de barras o código de proveedor. Usala cuando te pregunten cuánto stock hay de algo, o el precio de un artículo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Texto a buscar en el nombre o los códigos del artículo. Ejemplo: "coca cola". Mandá cadena vacía para traer los primeros artículos sin filtrar.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::stock_de_articulos($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'consultar_clientes',
                'description' => 'Devuelve clientes del negocio con su teléfono, su email y su saldo de cuenta corriente. Saldo positivo significa que el cliente debe plata. Usala cuando te pregunten por un cliente puntual o por su saldo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre del cliente. Cadena vacía trae los primeros clientes.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::clientes($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'consultar_movimientos_de_cuenta_corriente',
                'description' => 'Devuelve los movimientos de cuenta corriente de UN cliente del ERP: fecha, detalle, debe, haber y saldo acumulado. Podés filtrar por tipo (solo deudas o solo pagos), acotar por rango de fechas, elegir el orden y pedir la página siguiente. Por defecto contesta la cuenta EN PESOS: si el cliente además tiene cuenta en dólares, viene en "cuentas" y podés volver a llamar con su credit_account_id — no mezcles las dos, el saldo de cada fila es el acumulado de SU cuenta. La respuesta trae movimientos_encontrados (cuántos hay en total) y movimientos_en_esta_lista (cuántos viajan): si difieren, pedí la página siguiente en vez de contestar con los que tenés. Para saber QUÉ VENTAS quedaron sin cobrar, que es la pregunta de "qué me debe" y "desde cuándo", usá consultar_ventas_impagas_de_un_cliente. Primero conseguí el id del cliente con consultar_clientes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => [
                            'type' => 'integer',
                            'description' => 'Id del cliente, tal como lo devolvió consultar_clientes.',
                        ],
                        'tipo' => [
                            'type' => 'string',
                            'description' => 'Qué movimientos traer: "debe" son las deudas (ventas), "haber" son los pagos, "todos" es el default.',
                            'enum' => ['todos', 'debe', 'haber'],
                        ],
                        'desde' => [
                            'type' => 'string',
                            'description' => 'Fecha desde la cual mirar, en formato AAAA-MM-DD. Sin ella no hay piso.',
                        ],
                        'hasta' => [
                            'type' => 'string',
                            'description' => 'Fecha hasta la cual mirar, en formato AAAA-MM-DD. Sin ella no hay techo.',
                        ],
                        'orden' => [
                            'type' => 'string',
                            'description' => 'Del más nuevo al más viejo ("mas_nuevos", el default) o al revés ("mas_viejos"), que es lo que sirve para encontrar lo más antiguo.',
                            'enum' => ['mas_nuevos', 'mas_viejos'],
                        ],
                        'pagina' => [
                            'type' => 'integer',
                            'description' => 'Número de página, arrancando en 1.',
                        ],
                        'limite' => [
                            'type' => 'integer',
                            'description' => 'Cuántos movimientos por página. El default son 20 y el máximo 100.',
                        ],
                        'credit_account_id' => [
                            'type' => 'integer',
                            'description' => 'Cuenta puntual del cliente, tal como vino en "cuentas". Sin esto se contestan los movimientos en pesos.',
                        ],
                    ],
                    'required' => ['client_id'],
                ],
                /*
                 * 🔴 La tool apunta a movimientos_de_cuenta_corriente_detalle() y NO al método
                 * viejo del mismo nombre (misión agente-ia-mano-derecha, bloque B2). El viejo se
                 * conserva porque su shape es el contrato del canal "sistema:" de admin-api, pero
                 * traía los 20 movimientos más nuevos sin ventana ni filtro de cuenta: en un
                 * cliente con veinte pagos recientes la venta vieja que todavía debe quedaba fuera
                 * de la ventana y el asistente contestaba que no había ninguna.
                 *
                 * Y va con el MISMO nombre de tool en vez de sumar una segunda: dos tools con la
                 * misma forma para la misma pregunta es cómo el modelo termina eligiendo la peor,
                 * que es exactamente lo que pasó con los interesados de la tienda.
                 */
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente_detalle(
                        $owner_id,
                        (int) ($input['client_id'] ?? 0),
                        (string) ($input['tipo'] ?? 'todos'),
                        isset($input['desde']) ? $input['desde'] : null,
                        isset($input['hasta']) ? $input['hasta'] : null,
                        (string) ($input['orden'] ?? 'mas_nuevos'),
                        (int) ($input['pagina'] ?? 1),
                        (int) ($input['limite'] ?? 0),
                        isset($input['credit_account_id']) ? (int) $input['credit_account_id'] : null
                    );
                },
            ],
            [
                'name' => 'consultar_articulos_mas_vendidos',
                'description' => 'Devuelve los artículos más vendidos del negocio en los últimos días, con las unidades vendidas, según las VENTAS DEL ERP. Usala para preguntas sobre qué se vende más o cómo vienen las ventas. Agrupa por artículo y no sabe QUIÉN compró: para eso está consultar_quien_compro_un_articulo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'dias' => [
                            'type' => 'integer',
                            'description' => 'Ventana de días hacia atrás. Si no la mandás se usan 30.',
                            'enum' => [7, 30, 90],
                        ],
                    ],
                    'required' => [],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::mas_vendidos($owner_id, self::dias_del_enum($input));
                },
            ],
            [
                'name' => 'consultar_precios_de_proveedores',
                'description' => 'Devuelve la última oferta vigente de precio por artículo y proveedor: a cuánto OFRECIÓ cada proveedor cada artículo, y cuándo. Filtrá por nombre de artículo o de proveedor. Usala cuando te pregunten qué proveedor ofrece mejor precio, o a cuánto le están ofreciendo algo hoy. 🔴 Un precio ofertado NO es una compra hecha: si te preguntan cuándo fue la última compra de un artículo, a quién se la compró o a cuánto la pagó, va consultar_compras_de_un_articulo, que lee las compras reales.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre de un artículo o de un proveedor. Cadena vacía trae las ofertas más recientes sin filtrar.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::precios_de_proveedores($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'consultar_ofertas_activas',
                'description' => 'Devuelve las ofertas personalizadas VIGENTES HOY en la tienda online: qué descuento tiene cada cliente sobre cada artículo, desde cuándo, hasta cuándo y si se le avisó por mail. Filtrá por nombre de cliente o de artículo. Usala cuando te pregunten qué se le está ofreciendo a un cliente, o qué promociones hay corriendo.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre de un cliente o de un artículo. Cadena vacía trae las ofertas que se vencen antes.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::ofertas_activas($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'consultar_actividad_de_un_cliente',
                'description' => '🔴 ESTA HERRAMIENTA MIRA LA TIENDA ONLINE, NO EL ERP. Devuelve qué hizo un cliente en la tienda online: qué artículos miró y cuántos minutos, qué buscó (y si esa búsqueda no devolvió resultados), qué puso en el carrito y qué compró EN LA TIENDA. Si la pregunta es qué le vendió el negocio —lo que se cargó como venta, con o sin tienda de por medio— no es esta: es consultar_quien_compro_un_articulo para un artículo, consultar_ventas_impagas_de_un_cliente para lo que quedó sin cobrar y consultar_movimientos_de_cuenta_corriente para su cuenta. La respuesta trae "totales" con los números completos del periodo y "movimientos" con el detalle, que puede venir recortado: si movimientos_en_esta_lista es menor que movimientos_encontrados, contá con los totales y no con la cantidad de filas. En totales, compras_sin_articulo son compras que la tienda no informó de qué artículo eran: si es mayor a cero, no afirmes que el cliente no compró un artículo determinado. Primero conseguí el id del cliente con consultar_clientes. Usala cuando te pregunten qué estuvo mirando o qué le interesa a un cliente.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => [
                            'type' => 'integer',
                            'description' => 'Id del cliente, tal como lo devolvió consultar_clientes.',
                        ],
                        'dias' => [
                            'type' => 'integer',
                            'description' => 'Ventana de días hacia atrás. Si no la mandás se usan 30.',
                            'enum' => [7, 30, 90],
                        ],
                    ],
                    'required' => ['client_id'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::actividad_de_un_cliente($owner_id, (int) ($input['client_id'] ?? 0), self::dias_del_enum($input));
                },
            ],
            [
                'name' => 'consultar_interesados_en_un_articulo',
                'description' => '🔴 ESTA HERRAMIENTA MIRA LA TIENDA ONLINE, NO EL ERP: contesta quién MIRÓ un artículo, no quién lo compró. Para "qué cliente me compró más X", "a quién le vendí X" o "cuándo le vendí X a alguien" va consultar_quien_compro_un_articulo, que lee las ventas del ERP. Devuelve los clientes que miraron o pusieron en el carrito un artículo en la tienda online y, hasta donde el sistema puede saber, todavía no lo compraron: se descartan los que tienen una venta confirmada de ese artículo y los que lo compraron en la tienda. No es una certeza — una compra por mostrador todavía sin facturar, o un checkout que llegó sin el artículo, no se pueden descontar —, así que decilo como "no figura que lo haya comprado" y no como un hecho. Una fila con lo_compro_antes_y_lo_volvio_a_mirar en una fecha SÍ lo compró: quedó en la lista porque volvió a mirarlo después, así que hablá de recompra y nunca digas que no lo compró. Filtrá por nombre o código del artículo. La lista solo trae compradores vinculados a un cliente del sistema, porque a un visitante anónimo no se lo puede nombrar ni llamar; cuántos anónimos anduvieron sobre el mismo artículo viene aparte, en visitantes_anonimos, y con la lista vacía ese número puede ser lo único que haya para contestar.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre del artículo, o su código de barras / de proveedor.',
                        ],
                        'dias' => [
                            'type' => 'integer',
                            'description' => 'Ventana de días hacia atrás. Si no la mandás se usan 30.',
                            'enum' => [7, 30, 90],
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::interesados_en_un_articulo($owner_id, (string) ($input['busqueda'] ?? ''), self::dias_del_enum($input));
                },
            ],
            /*
             * 🔴 DE ACÁ PARA ABAJO VAN LAS DE LA MISIÓN agente-ia-mano-derecha (bloque B), Y VAN AL
             * FINAL A PROPÓSITO: el orden de este array es el prefijo que cachea con_cache_control(),
             * así que lo nuevo se agrega atrás y lo de arriba no se mueve.
             */
            [
                'name' => 'consultar_ventas_impagas_de_un_cliente',
                'description' => 'Devuelve las VENTAS DEL ERP que un cliente todavía no pagó, de la más vieja a la más nueva, con la fecha, hace cuántos días están sin cobrar, el total y lo que queda pendiente. Es la herramienta de "qué me debe", "cuál es la venta más vieja que me debe" y "desde cuándo me debe". 🔴 venta_impaga_mas_vieja viene calculada sobre TODAS las ventas impagas y no sobre las que entran en la lista, así que podés contestar cuál es la más vieja aunque la lista venga recortada. 🔴 CADA VENTA DICE SU MONEDA en "en_pesos": cuando es false ese importe está en DÓLARES y tenés que escribirlo con US$, nunca en pesos. total_pendiente_en_pesos_en_esta_lista suma solo las que están en pesos, y ventas_en_otra_moneda_en_esta_lista dice cuántas quedaron afuera de ese total. saldo_en_cuenta_corriente_en_pesos es la deuda total del cliente en pesos e incluye lo que no está en ninguna venta (saldos iniciales, notas de crédito, ajustes): puede no coincidir con la suma de las ventas listadas, y eso no es un error, son dos cosas distintas. Si el cliente no existe o no es de este negocio la respuesta trae "error": NO contestes que no debe nada, porque no se pudo mirar. Primero conseguí el id del cliente con consultar_clientes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => [
                            'type' => 'integer',
                            'description' => 'Id del cliente, tal como lo devolvió consultar_clientes.',
                        ],
                        'orden' => [
                            'type' => 'string',
                            'description' => 'De la más vieja a la más nueva ("mas_viejas", el default) o al revés.',
                            'enum' => ['mas_viejas', 'mas_nuevas'],
                        ],
                        'limite' => [
                            'type' => 'integer',
                            'description' => 'Cuántas ventas traer. El default son 20 y el máximo 100.',
                        ],
                    ],
                    'required' => ['client_id'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::ventas_impagas_de_un_cliente(
                        $owner_id,
                        (int) ($input['client_id'] ?? 0),
                        (string) ($input['orden'] ?? 'mas_viejas'),
                        (int) ($input['limite'] ?? 0)
                    );
                },
            ],
            [
                'name' => 'consultar_quien_compro_un_articulo',
                'description' => 'Devuelve qué CLIENTES le compraron un artículo al negocio y cuántas unidades, según las VENTAS DEL ERP (no la tienda online). Es la herramienta de "qué cliente me compró más X", "a quién le vendí X" y "cuándo fue la última vez que le vendí X a alguien". Viene ordenada por unidades, de mayor a menor. unidades_sin_cliente son las que se vendieron por mostrador sin cliente cargado: si la lista viene vacía y ese número es mayor a cero, el artículo SÍ se vendió y no se sabe a quién — no contestes que no lo compró nadie. 🔴 LAS UNIDADES SON EXACTAS, EL MONTO PUEDE NO SERLO: monto_en_pesos suma únicamente las unidades que tienen precio en pesos guardado, y unidades_sin_precio_en_pesos dice cuántas quedaron afuera (ventas en dólares y ventas viejas, anteriores a que el sistema guardara la moneda). Si ese número es mayor a cero, decí el monto como incompleto y aclará sobre cuántas unidades está calculado: NUNCA lo presentes como todo lo que compró, porque un monto bajo sobre muchas unidades se lee como un artículo barato. No la confundas con consultar_interesados_en_un_articulo, que es quién lo MIRÓ en la tienda.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre del artículo, o su código de barras / de proveedor.',
                        ],
                        'dias' => [
                            'type' => 'integer',
                            'description' => 'Ventana de días hacia atrás. 0 (el default) es toda la historia, que es lo que la persona suele querer decir con "quién me compró más".',
                            'enum' => [0, 7, 30, 90, 365],
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::quien_compro_un_articulo($owner_id, (string) ($input['busqueda'] ?? ''), self::dias_de_historia($input));
                },
            ],
            [
                'name' => 'consultar_compras_de_un_articulo',
                'description' => 'Devuelve las COMPRAS REALES que el negocio le hizo a sus proveedores de un artículo: cuándo, a qué proveedor, cuántas unidades pidió y recibió, a qué costo y con qué comprobante. Vienen de la más nueva a la más vieja, así que la PRIMERA FILA es la última compra. Es la herramienta de "cuándo fue la última compra de X", "a quién se la compré" y "a cuánto la pagué". ⚠️ costo_en_dolares dice si ese costo está en dólares: cuando es true no lo informes como pesos. No la confundas con consultar_precios_de_proveedores, que son precios ofertados y no compras hechas.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre del artículo, o su código de barras / de proveedor.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::compras_de_un_articulo($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'consultar_compras_a_un_proveedor',
                'description' => 'Devuelve las compras que el negocio le hizo a un proveedor: número, fecha, comprobante, estado, total y cuántos artículos distintos y cuántas unidades tiene cada una, de la más nueva a la más vieja. Los estados son solo dos: "En proceso" y "Recibido". ⚠️ total_comprado_en_pesos suma SOLO las compras en pesos; las que están en otra moneda se cuentan aparte en compras_en_otra_moneda y no entran en ese total, así que no lo presentes como todo lo que le compró. Buscá el proveedor por nombre, razón social o CUIT.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre, razón social o CUIT del proveedor.',
                        ],
                        'dias' => [
                            'type' => 'integer',
                            'description' => 'Ventana de días hacia atrás. 0 (el default) es toda la historia.',
                            'enum' => [0, 7, 30, 90, 365],
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::compras_a_un_proveedor($owner_id, (string) ($input['busqueda'] ?? ''), self::dias_de_historia($input));
                },
            ],
            [
                'name' => 'consultar_stock_por_deposito',
                'description' => 'Devuelve cómo está repartido el stock de un artículo entre las sucursales o depósitos del negocio, incluidas las que están en cero (que suele ser justo el dato que se busca). Si trabaja_con_depositos es false, el negocio tiene una sola sucursal y no hay reparto que contar. 🔴 Vienen DOS totales: stock_total_del_articulo, que es el de la ficha y el que muestra el listado, y stock_sumado_por_deposito, que es la suma del reparto. Si no coinciden, decilo: es un dato roto que el comerciante tiene que saber, y no elijas uno de los dos por tu cuenta.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'busqueda' => [
                            'type' => 'string',
                            'description' => 'Nombre o parte del nombre del artículo, o su código de barras / de proveedor.',
                        ],
                    ],
                    'required' => ['busqueda'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return ConsultasSistemaIaHelper::stock_por_deposito($owner_id, (string) ($input['busqueda'] ?? ''));
                },
            ],
            [
                'name' => 'que_puedo_consultar',
                'description' => 'Te dice qué datos del sistema podés pedir con consultar_datos y cómo filtrarlos. Llamala SIN entidad para ver la lista de lo que hay, y de nuevo CON una entidad para ver sus campos, el tipo de cada uno y qué operadores acepta. Usala antes de consultar_datos cuando la pregunta no encaja en ninguna de las otras herramientas: no adivines nombres de campos, un campo que no existe devuelve error y te gasta una vuelta.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entidad' => [
                            'type' => 'string',
                            'description' => 'Sobre cuál querés el detalle. Sin esto devuelve la lista de todas.',
                            // La lista sale del propio catálogo: una entidad nueva aparece acá sola.
                            'enum' => CatalogoDeDatosIaHelper::entidades(),
                        ],
                    ],
                    'required' => [],
                ],
                'handler' => function (array $input, $owner_id) {
                    return CatalogoDeDatosIaHelper::que_puedo_consultar(isset($input['entidad']) ? (string) $input['entidad'] : null);
                },
            ],
            [
                'name' => 'consultar_datos',
                'description' => 'Consulta genérica sobre los datos del negocio: artículos, clientes, proveedores, ventas, compras a proveedores, gastos, tareas y vencimientos, presupuestos, cheques, cajas, pedidos y compradores de la tienda, vendedores, combos, rubros, sub rubros y marcas. Usala para lo que no tiene herramienta propia — cuando sí la tiene, la propia contesta mejor y más barato. 🔴 Pedí primero que_puedo_consultar con la entidad para saber qué campos tiene y qué operadores acepta cada uno: un campo o un operador que no existe devuelve error, no resultados. Devuelve registros_encontrados (cuántos hay en total) y registros_en_esta_lista (cuántos viajan), así que si difieren podés pedir la página siguiente. De artículos devuelve solo los activos.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entidad' => [
                            'type' => 'string',
                            'description' => 'Qué se consulta.',
                            'enum' => CatalogoDeDatosIaHelper::entidades(),
                        ],
                        'filtros' => [
                            'type' => 'array',
                            'description' => 'Condiciones que tienen que cumplir los registros. Sin filtros trae los últimos cargados.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'campo' => [
                                        'type' => 'string',
                                        'description' => 'Nombre del campo, tal como lo devolvió que_puedo_consultar. Para filtrar por una relación va su campo con _id (por ejemplo category_id), no el nombre.',
                                    ],
                                    'operador' => [
                                        'type' => 'string',
                                        'description' => 'Qué comparación hacer. "contiene" es solo para texto; "mayor" y "menor" para números y fechas.',
                                        'enum' => ['contiene', 'igual', 'mayor', 'menor', 'vacio', 'no_vacio'],
                                    ],
                                    'valor' => [
                                        'type' => 'string',
                                        'description' => 'Contra qué comparar. Las fechas van en formato AAAA-MM-DD. No va con "vacio" ni con "no_vacio".',
                                    ],
                                ],
                                'required' => ['campo', 'operador'],
                            ],
                        ],
                        'orden' => [
                            'type' => 'object',
                            'description' => 'Por qué campo ordenar. Sin esto vienen los más nuevos primero.',
                            'properties' => [
                                'campo' => [
                                    'type' => 'string',
                                    'description' => 'Campo por el que ordenar, de los que declaró que_puedo_consultar.',
                                ],
                                'direccion' => [
                                    'type' => 'string',
                                    'description' => 'ASC de menor a mayor, DESC de mayor a menor.',
                                    'enum' => ['ASC', 'DESC'],
                                ],
                            ],
                            'required' => ['campo'],
                        ],
                        'pagina' => [
                            'type' => 'integer',
                            'description' => 'Número de página, arrancando en 1.',
                        ],
                        'limite' => [
                            'type' => 'integer',
                            'description' => 'Cuántos registros por página. El default son 20 y el máximo 100.',
                        ],
                    ],
                    'required' => ['entidad'],
                ],
                'handler' => function (array $input, $owner_id) {
                    return CatalogoDeDatosIaHelper::consultar_datos(
                        $owner_id,
                        (string) ($input['entidad'] ?? ''),
                        is_array($input['filtros'] ?? null) ? $input['filtros'] : [],
                        is_array($input['orden'] ?? null) ? $input['orden'] : null,
                        (int) ($input['pagina'] ?? 1),
                        (int) ($input['limite'] ?? 0)
                    );
                },
            ],
        ];
    }

    /**
     * Las tools de lectura tal como viajan a la API: el registro sin la clave `handler`, que es
     * interna (y además es un Closure, que no se serializa a JSON).
     *
     * @return array<int, array<string, mixed>>
     */
    public function herramientas_de_lectura(): array
    {
        $definiciones = [];

        foreach ($this->registro_de_lectura() as $herramienta) {
            unset($herramienta['handler']);
            $definiciones[] = $herramienta;
        }

        return $definiciones;
    }

    /**
     * Nombres de todas las tools de lectura, derivados del registro (mismo patrón que
     * HerramientasDeCarga::nombres()).
     *
     * @return array<int, string>
     */
    public function nombres_de_lectura(): array
    {
        return array_column($this->registro_de_lectura(), 'name');
    }

    /**
     * true si la tool es de lectura (mismo patrón que HerramientasDeCarga::maneja()).
     *
     * @param string $tool_name
     * @return bool
     */
    public function maneja_lectura($tool_name): bool
    {
        return in_array((string) $tool_name, $this->nombres_de_lectura(), true);
    }

    /**
     * El handler de una tool de lectura, o null si el nombre no está en el registro.
     *
     * @param string $tool_name
     * @return callable|null
     */
    protected function handler_de_lectura($tool_name)
    {
        foreach ($this->registro_de_lectura() as $herramienta) {
            if ($herramienta['name'] === (string) $tool_name) {

                return $herramienta['handler'];
            }
        }

        return null;
    }

    /**
     * El contenido JSON de un tool_result.
     *
     * El fallback `?: '[]'` estaba repetido en las ocho ramas del despacho y es el mismo que usa
     * HerramientasDeCarga::resultado(): con UTF-8 inválido en la base (nombres importados de un
     * Excel roto) json_encode devuelve false, y un content false rompería el request siguiente del
     * loop con un 400 críptico.
     *
     * @param mixed $datos
     * @return string
     */
    protected function contenido_de_tool_result($datos): string
    {
        return json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * La ventana de días de un input, defendida contra un valor fuera del enum: cualquier cosa que
     * no sea 7, 30 o 90 se cae al default de 30. Lo usan las tres tools que aceptan `dias`, que
     * repetían el mismo bloque.
     *
     * @param array $input
     * @return int
     */
    protected static function dias_del_enum(array $input): int
    {
        $dias = (int) ($input['dias'] ?? 30);

        if (! in_array($dias, [7, 30, 90], true)) {

            return 30;
        }

        return $dias;
    }

    /**
     * La misma defensa que dias_del_enum(), pero para las consultas que SÍ pueden mirar toda la
     * historia (misión agente-ia-mano-derecha, bloque B).
     *
     * 🔴 Son dos escalas distintas y por eso son dos métodos. Las tools de la tienda leen de
     * `buyer_tracking_events` a través de ActividadDeClientesService, que CAMBIA DE TABLA según la
     * antigüedad pedida: ahí una ventana libre no es un filtro más amplio, es leer de otro lado. Las
     * del ERP corren sobre `sales` y `provider_orders`, donde "toda la historia" es un pedido
     * legítimo y además el más frecuente: "quién me compró más la lámpara" casi nunca quiere decir
     * "en los últimos 30 días".
     *
     * Por eso el default acá es 0 (sin ventana) y no 30: con 30, la respuesta se recortaría sola a
     * un mes sin que la persona lo haya pedido ni pueda darse cuenta.
     *
     * @param array $input
     * @return int
     */
    protected static function dias_de_historia(array $input): int
    {
        $dias = (int) ($input['dias'] ?? 0);

        if (! in_array($dias, [0, 7, 30, 90, 365], true)) {

            return 0;
        }

        return $dias;
    }

    /**
     * Ejecuta las tool calls de un bloque de contenido del asistente y arma
     * los tool_result. Un error en una tool NO corta el loop: viaja como
     * tool_result con is_error para que Claude pueda seguir.
     *
     * Todas filtran por el user_id del DUEÑO resuelto desde la conversación.
     *
     * Misión asistente-ia-acciones: las herramientas de carga se despachan en
     * HerramientasDeCarga, y SOLO si el mensaje que se está generando tiene
     * `acciones_habilitadas`. Sin mensaje (los llamadores viejos pasan dos
     * argumentos) o sin el flag, una de esas tools cae en "Tool desconocida"
     * con is_error, igual que antes de la misión.
     *
     * @param array<int, mixed> $content_blocks Bloques content devueltos por Claude.
     * @param AiConversation $conversation
     * @param AiMessage|null $assistant_message El assistant que se está generando (opcional).
     * @return array<int, array<string, mixed>>
     */
    public function execute_tool_calls(array $content_blocks, AiConversation $conversation, $assistant_message = null): array
    {
        $owner_id = (int) $conversation->user_id;
        $tool_results = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $tool_id    = (string) ($block['id'] ?? '');
            $tool_name  = (string) ($block['name'] ?? '');
            $tool_input = isset($block['input']) && is_array($block['input']) ? $block['input'] : [];

            try {
                // true cuando la tool pedida no está en la whitelist: el
                // tool_result viaja con is_error para que Claude no lo lea
                // como un resultado válido de la consulta.
                $tool_desconocida = false;

                // true cuando una herramienta de carga devolvió una falla
                // técnica (por ejemplo, una propuesta sin mensaje donde colgar
                // la tarjeta): también viaja con is_error.
                $error_de_la_herramienta = false;

                $handler = $this->handler_de_lectura($tool_name);

                if (! is_null($handler)) {
                    // Las dos puntas de una tool de lectura (su definición y su handler) son la
                    // misma entrada de registro_de_lectura(): acá solo se la invoca.
                    $datos = call_user_func($handler, $tool_input, $owner_id);

                    /*
                     * Misión agente-ia-mano-derecha (§1): de los datos CRUDOS —antes del
                     * json_encode— salen los pares (tipo, id, texto) que después se cruzan contra
                     * el texto final. Acá no se decide ninguna mención: se junta la materia prima,
                     * que es lo único que existe solo en este punto del loop.
                     */
                    $this->juntar_candidatos_a_mencion($tool_name, $datos);

                    $content = $this->contenido_de_tool_result($datos);
                } elseif (! is_null($assistant_message) && $assistant_message->acciones_habilitadas && HerramientasDeCarga::maneja($tool_name)) {
                    // Las dos puntas de las herramientas de carga (definición y
                    // despacho) viven juntas en HerramientasDeCarga: acá solo se
                    // les delega, y solo con el flag del mensaje.
                    $resultado_de_carga = HerramientasDeCarga::ejecutar($tool_name, $tool_input, $conversation, $assistant_message);
                    $content = $resultado_de_carga['content'];
                    $error_de_la_herramienta = (bool) $resultado_de_carga['is_error'];
                } else {
                    $tool_desconocida = true;
                    $content = 'Tool desconocida: ' . $tool_name;
                }

                $tool_result = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'content'     => $content,
                ];

                if ($tool_desconocida || $error_de_la_herramienta) {
                    $tool_result['is_error'] = true;
                }

                $tool_results[] = $tool_result;
            } catch (\Throwable $exception) {
                Log::warning('AsistenteIaService: error en tool call.', [
                    'ai_conversation_id' => $conversation->id,
                    'tool'               => $tool_name,
                    'error'              => $exception->getMessage(),
                ]);

                // Devuelve el error a Claude para que pueda continuar sin romper el flujo.
                $tool_results[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'is_error'    => true,
                    'content'     => 'Error al ejecutar ' . $tool_name . ': ' . $exception->getMessage(),
                ];
            }
        }

        return $tool_results;
    }

    /**
     * Suma a la bolsa de candidatos lo que dejó una tool de lectura.
     *
     * Protegido y sin tocar nada del resultado: si la extracción falla, la tool ya respondió bien y
     * el loop tiene que seguir. Lo único que se pierde son las menciones de esa consulta.
     *
     * @param  string  $tool_name
     * @param  mixed   $datos  Lo crudo que devolvió el handler.
     * @return void
     */
    protected function juntar_candidatos_a_mencion($tool_name, $datos)
    {
        try {
            $candidatos = MencionesIaHelper::candidatos_de_tool($tool_name, $datos);

            if (!empty($candidatos)) {
                $this->candidatos_a_mencion = array_merge($this->candidatos_a_mencion, $candidatos);
            }
        } catch (\Throwable $e) {
            Log::warning('AsistenteIaService: no se pudieron leer los candidatos a mención de una tool.', [
                'tool'  => $tool_name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normaliza bloques content del asistente para reenviarlos a Anthropic
     * en el loop.
     *
     * 🔴 json_decode convierte input:{} en array vacío [] y la API exige
     * object en tool_use.input: sin el cast, el segundo request del loop
     * rebota con un 400 críptico (y acá pasa seguro: dos de las cuatro
     * tools no tienen argumentos obligatorios). También se eliminan campos
     * de respuesta (p. ej. caller) que el request no acepta.
     *
     * @param array<int, mixed> $content_blocks Bloques devueltos por Claude.
     * @return array<int, mixed>
     */
    public function normalize_assistant_content_for_api(array $content_blocks): array
    {
        $normalized = [];

        foreach ($content_blocks as $block) {
            if (! is_array($block)) {
                $normalized[] = $block;
                continue;
            }

            if (($block['type'] ?? '') === 'tool_use') {
                $input = $block['input'] ?? [];
                if (! is_array($input) || $input === []) {
                    $block['input'] = new \stdClass();
                } else {
                    $block['input'] = (object) $input;
                }
                unset($block['caller']);
            }

            $normalized[] = $block;
        }

        return $normalized;
    }

    /**
     * Cliente HTTP hacia Anthropic: headers de versión y caché de prompt,
     * timeout de 60s por llamada (el techo del loop completo lo pone
     * PRESUPUESTO_SEGUNDOS) y el mismo bloque TLS que ResumenIaService
     * (WAMP/Windows suele requerir ca_bundle o verify_ssl=false).
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function build_http_client()
    {
        $api_key = (string) config('services.anthropic.api_key');

        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'anthropic-beta'    => 'prompt-caching-2024-07-31',
            'content-type'      => 'application/json',
        ])->timeout(self::TIMEOUT_SEGUNDOS);

        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');

        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        return $http;
    }

    /**
     * Concatena el texto de los bloques text de una respuesta.
     *
     * @param array<string, mixed> $body Respuesta JSON de Anthropic.
     * @return string
     */
    protected function extract_response_text(array $body): string
    {
        $text = '';

        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }

        return $text;
    }
}
