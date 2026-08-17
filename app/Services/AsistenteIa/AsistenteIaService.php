<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\AiTokenUsageHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
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
 * El consumo de tokens se registra en CADA iteración del loop (cada
 * respuesta de la API trae su propio bloque usage); el registro nunca
 * lanza (AiTokenUsageHelper).
 */
class AsistenteIaService
{
    /** Máximo de iteraciones del loop de tool use, para evitar bucles infinitos. */
    const MAX_TOOL_ITERATIONS = 5;

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
     * más que eso ya está perdida para el usuario).
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
     * retener el worker compartido con las importaciones hasta 5 llamadas
     * enteras. Peor caso real con presupuesto: ~150s + una llamada de 60s en
     * vuelo ≈ 210s, por debajo del $timeout = 240 del job (que sí rige donde
     * hay pcntl).
     */
    const PRESUPUESTO_SEGUNDOS = 150;

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
     * @return string Texto final de la respuesta.
     *
     * @throws \RuntimeException Si la API falla o el loop termina sin texto.
     */
    public function responder(AiConversation $conversation, AiMessage $assistant_message): string
    {
        $owner = User::find($conversation->user_id);

        $system   = $this->build_system_payload($conversation, $owner);
        $messages = $this->build_messages_payload($conversation);
        $tools    = $this->build_tools();
        $model    = (string) config('services.anthropic.model');
        $http     = $this->build_http_client();

        $iterations = 0;
        $final_text = '';
        $inicio_del_loop = time();

        while ($iterations < self::MAX_TOOL_ITERATIONS) {
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

                throw new \RuntimeException(
                    'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.'
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
                    throw new \RuntimeException(
                        'El servicio de IA no está disponible en este momento. Esperá unos segundos y volvé a intentarlo.'
                    );
                }

                throw new \RuntimeException(
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
                $messages[] = [
                    'role'    => 'user',
                    'content' => $this->execute_tool_calls($content_blocks, $conversation),
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

            throw new \RuntimeException(
                'La IA no llegó a generar una respuesta. Probá mandar el mensaje de nuevo.'
            );
        }

        return $final_text;
    }

    /**
     * Texto del bloque 1 del system: identidad, tono, formato texto plano y
     * reglas de qué puede afirmar. Es el prefijo común a TODAS las
     * conversaciones (por eso es el que lleva cache_control).
     *
     * @param AiConversation $conversation
     * @param User|null $owner Dueño de la cuenta, para el nombre del negocio.
     * @return string
     */
    public function build_system_prompt(AiConversation $conversation, $owner): string
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

        return <<<SYSTEM
Sos el asistente de inteligencia artificial del negocio "{$company_name}". Trabajás
adentro del sistema de gestión de ese negocio y le hablás a la persona que lo usa.

Cómo hablás:
- Español rioplatense, tuteando, tono cercano y directo. Nada de solemnidad ni de
  "estimado usuario".
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
- Las herramientas devuelven como máximo 20 registros. Si el resultado llega a 20,
  aclará que puede haber más y que eso es un tope de la consulta, no del negocio.
- Solo podés LEER. No podés crear, modificar ni borrar nada del sistema, ni mandar
  mensajes, ni prometer que vas a hacerlo. Si te lo piden, explicá adónde ir en el
  sistema para hacerlo a mano.
- Los importes son en pesos argentinos.

Hoy es {$fecha}. Usalo para interpretar "este mes", "la semana
pasada" y similares.
SYSTEM;
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
     * @return array<int, array<string, mixed>>
     */
    public function build_system_payload(AiConversation $conversation, $owner): array
    {
        $system = [
            [
                'type'          => 'text',
                'text'          => $this->build_system_prompt($conversation, $owner),
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
     * @param AiConversation $conversation
     * @return array<int, array{role: string, content: string}>
     */
    public function build_messages_payload(AiConversation $conversation): array
    {
        // Los últimos N en orden inverso: el recorte por caracteres protege lo más nuevo.
        $recientes = AiMessage::where('ai_conversation_id', $conversation->id)
            ->where('estado', 'listo')
            ->whereNotNull('contenido')
            ->where('contenido', '!=', '')
            ->orderBy('id', 'DESC')
            ->limit(self::MAX_MENSAJES_HISTORIAL)
            ->get();

        $seleccionados = [];
        $acumulado = 0;

        foreach ($recientes as $message) {
            $largo = mb_strlen((string) $message->contenido);

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

        $turns = [];

        foreach ($seleccionados as $message) {
            $body = trim((string) $message->contenido);
            if ($body === '') {
                continue;
            }

            $role = $message->rol === 'user' ? 'user' : 'assistant';
            $last_index = count($turns) - 1;

            if ($last_index >= 0 && $turns[$last_index]['role'] === $role) {
                // Mismo rol que el turno anterior: se funde en un solo mensaje.
                $turns[$last_index]['content'] .= "\n" . $body;
            } else {
                $turns[] = ['role' => $role, 'content' => $body];
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

        return $turns;
    }

    /**
     * Las tools de LECTURA del asistente, con su input_schema para la API de
     * Anthropic. Ninguna crea, modifica ni borra nada.
     *
     * 🔴 Toda tool se declara en DOS lugares de este archivo: acá (para que
     * Claude sepa que existe) y en el if/elseif de execute_tool_calls() (para
     * que al usarla se resuelva). Con una sola de las dos, la IA "tiene" la
     * tool y al llamarla recibe "Tool desconocida". Se agregan juntas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build_tools(): array
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
            ],
            [
                'name' => 'consultar_movimientos_de_cuenta_corriente',
                'description' => 'Devuelve los últimos movimientos de cuenta corriente de UN cliente: fecha, detalle, debe, haber y saldo. Primero conseguí el id del cliente con consultar_clientes.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => [
                            'type' => 'integer',
                            'description' => 'Id del cliente, tal como lo devolvió consultar_clientes.',
                        ],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'consultar_articulos_mas_vendidos',
                'description' => 'Devuelve los artículos más vendidos del negocio en los últimos días, con las unidades vendidas. Usala para preguntas sobre qué se vende más o cómo vienen las ventas.',
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
            ],
            [
                'name' => 'consultar_precios_de_proveedores',
                'description' => 'Devuelve la última oferta vigente de precio por artículo y proveedor: a cuánto ofreció cada proveedor cada artículo, y cuándo. Filtrá por nombre de artículo o de proveedor. Usala cuando te pregunten a cuánto compra o compró el negocio algo, o qué proveedor ofrece mejor precio.',
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
            ],
            [
                'name' => 'consultar_actividad_de_un_cliente',
                'description' => 'Devuelve qué hizo un cliente en la tienda online: qué artículos miró y cuántos minutos, qué buscó (y si esa búsqueda no devolvió resultados), qué puso en el carrito y qué compró. Primero conseguí el id del cliente con consultar_clientes. Usala cuando te pregunten qué estuvo mirando o qué le interesa a un cliente.',
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
            ],
            [
                'name' => 'consultar_interesados_en_un_articulo',
                'description' => 'Devuelve los clientes que miraron o pusieron en el carrito un artículo en la tienda online y TODAVÍA NO LO COMPRARON, con cuántas veces lo miraron y cuánto tiempo. Filtrá por nombre o código del artículo. Solo lista compradores que estén vinculados a un cliente del sistema: los visitantes anónimos de la tienda no se pueden nombrar y no aparecen acá.',
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
            ],
        ];
    }

    /**
     * Ejecuta las tool calls de un bloque de contenido del asistente y arma
     * los tool_result. Un error en una tool NO corta el loop: viaja como
     * tool_result con is_error para que Claude pueda seguir.
     *
     * Todas filtran por el user_id del DUEÑO resuelto desde la conversación.
     *
     * @param array<int, mixed> $content_blocks Bloques content devueltos por Claude.
     * @param AiConversation $conversation
     * @return array<int, array<string, mixed>>
     */
    public function execute_tool_calls(array $content_blocks, AiConversation $conversation): array
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

            /*
             * A los cuatro json_encode se les pone el fallback `?: '[]'`:
             * con UTF-8 inválido en la base (nombres importados de un Excel
             * roto) json_encode devuelve false, y un content false rompería
             * el request siguiente del loop con un 400 críptico.
             */
            try {
                // true cuando la tool pedida no está en la whitelist: el
                // tool_result viaja con is_error para que Claude no lo lea
                // como un resultado válido de la consulta.
                $tool_desconocida = false;

                if ($tool_name === 'consultar_stock_de_articulos') {
                    $busqueda = (string) ($tool_input['busqueda'] ?? '');
                    $data = ConsultasSistemaIaHelper::stock_de_articulos($owner_id, $busqueda);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_clientes') {
                    $busqueda = (string) ($tool_input['busqueda'] ?? '');
                    $data = ConsultasSistemaIaHelper::clientes($owner_id, $busqueda);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_movimientos_de_cuenta_corriente') {
                    $client_id = (int) ($tool_input['client_id'] ?? 0);
                    $data = ConsultasSistemaIaHelper::movimientos_de_cuenta_corriente($owner_id, $client_id);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_articulos_mas_vendidos') {
                    $dias = (int) ($tool_input['dias'] ?? 30);
                    // Defensa contra un valor fuera del enum: se cae al default.
                    if (! in_array($dias, [7, 30, 90], true)) {
                        $dias = 30;
                    }
                    $data = ConsultasSistemaIaHelper::mas_vendidos($owner_id, $dias);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_precios_de_proveedores') {
                    $busqueda = (string) ($tool_input['busqueda'] ?? '');
                    $data = ConsultasSistemaIaHelper::precios_de_proveedores($owner_id, $busqueda);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_ofertas_activas') {
                    // La otra punta de esta tool está en build_tools(): las dos
                    // se agregan juntas o la IA la llama y recibe un error.
                    $busqueda = (string) ($tool_input['busqueda'] ?? '');
                    $data = ConsultasSistemaIaHelper::ofertas_activas($owner_id, $busqueda);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_actividad_de_un_cliente') {
                    // La otra punta de esta tool está en build_tools(): las dos
                    // se agregan juntas o la IA la llama y recibe un error.
                    $client_id = (int) ($tool_input['client_id'] ?? 0);
                    $dias = (int) ($tool_input['dias'] ?? 30);
                    // Defensa contra un valor fuera del enum: se cae al default.
                    if (! in_array($dias, [7, 30, 90], true)) {
                        $dias = 30;
                    }
                    $data = ConsultasSistemaIaHelper::actividad_de_un_cliente($owner_id, $client_id, $dias);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } elseif ($tool_name === 'consultar_interesados_en_un_articulo') {
                    // La otra punta de esta tool está en build_tools(): las dos
                    // se agregan juntas o la IA la llama y recibe un error.
                    $busqueda = (string) ($tool_input['busqueda'] ?? '');
                    $dias = (int) ($tool_input['dias'] ?? 30);
                    // Defensa contra un valor fuera del enum: se cae al default.
                    if (! in_array($dias, [7, 30, 90], true)) {
                        $dias = 30;
                    }
                    $data = ConsultasSistemaIaHelper::interesados_en_un_articulo($owner_id, $busqueda, $dias);
                    $content = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '[]';
                } else {
                    $tool_desconocida = true;
                    $content = 'Tool desconocida: ' . $tool_name;
                }

                $tool_result = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tool_id,
                    'content'     => $content,
                ];

                if ($tool_desconocida) {
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
