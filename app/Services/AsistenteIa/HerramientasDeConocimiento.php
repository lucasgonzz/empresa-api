<?php

namespace App\Services\AsistenteIa;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConocimientoDelNegocioIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\OnboardingDelNegocioIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TareasDelAsistenteIaHelper;
use App\Models\AiConocimientoDelNegocio;
use App\Models\AiConversation;
use App\Models\User;

/**
 * La herramienta `conocimiento_del_negocio` (misión onboarding-conversacional-whatsapp, 23-24/11/2026): cómo el
 * agente maneja el onboarding con el dueño cuando no alcanza con `guardar_en_contexto` (que ya marca los temas
 * respondidos con su campo `temas`).
 *
 *   omitir  → el tema no aplica o el dueño no quiere contestarlo: queda `omitido`, el cursor avanza y no se vuelve a preguntar;
 *   empezar → el dueño pide que le hagan las preguntas (cuando no arrancó solo);
 *   pausar  → «no me preguntes más»: se cancelan los recordatorios;
 *   retomar → vuelve a preguntar;
 *   estado  → cómo viene (qué falta, qué sabe).
 *
 * 🔴 SE OFRECE SÓLO SI: el dueño tiene `agentes_ia`, existen las tablas, QUIEN HABLA ES EL DUEÑO y el turno NO es
 * desatendido (decide quien llama: AsistenteIaService, igual que con las tareas). El despacho vuelve a mirar lo que
 * no depende del turno. Va justo después de las herramientas de tareas para no mover el prefijo del caché.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class HerramientasDeConocimiento
{
    const NOMBRE = 'conocimiento_del_negocio';

    const ACCIONES = ['omitir', 'empezar', 'pausar', 'retomar', 'estado'];

    /**
     * La definición para la API (forma de Anthropic: name / description / input_schema).
     *
     * @return array<string, mixed>
     */
    public static function definicion()
    {
        return [
            'name'         => self::NOMBRE,
            'description'  => 'Maneja las preguntas con las que conocés el negocio del dueño (el onboarding). `omitir`: un tema no aplica o no quiere contestarlo (mandá `temas` con las claves): queda marcado y no se lo volvés a preguntar. `pausar`: dijo "no me preguntes más". `retomar`: pidió seguir con las preguntas. `empezar`: pidió que le hagas las preguntas para conocerlo. `estado`: qué sabés y qué te falta. Lo que el dueño SÍ contesta se guarda con guardar_en_contexto (campo `temas`), no con esta herramienta.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'accion' => ['type' => 'string', 'enum' => self::ACCIONES, 'description' => 'Qué hacer.'],
                    'temas'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Sólo para `omitir`: las claves de los temas (por ejemplo ["competencia"]), las que figuran en <onboarding_del_negocio>.'],
                ],
                'required'   => ['accion'],
            ],
        ];
    }

    /**
     * true si la tool es de este registro.
     *
     * @param  string  $tool_name
     * @return bool
     */
    public static function maneja($tool_name)
    {
        return (string) $tool_name === self::NOMBRE;
    }

    /**
     * true si hay que ofrecerla en esta conversación: Agentes de IA, las tablas y habla el dueño. El turno
     * desatendido lo decide quien llama.
     *
     * @param  \App\Models\User|null  $owner
     * @param  \App\Models\AiConversation|null  $conversation
     * @return bool
     */
    public static function se_ofrece($owner, $conversation)
    {
        if (!($owner instanceof User) || !($conversation instanceof AiConversation)) {

            return false;
        }

        return AgentesIaHelper::tiene($owner)
            && ConocimientoDelNegocioIaHelper::hay_tablas()
            && TareasDelAsistenteIaHelper::hay_tablas()
            && HerramientasDeTareasDelAsistente::habla_el_dueno($conversation);
    }

    /**
     * Ejecuta la herramienta.
     *
     * @param  array  $input
     * @param  \App\Models\AiConversation  $conversation
     * @return array{content: string, is_error: bool}
     */
    public static function ejecutar(array $input, AiConversation $conversation)
    {
        $owner = User::find((int) $conversation->user_id);

        if (!self::se_ofrece($owner, $conversation)) {

            return self::resultado(['error' => 'El onboarding del negocio es parte de Agentes de IA y sólo lo maneja el dueño del negocio.'], true);
        }

        $accion = isset($input['accion']) ? trim((string) $input['accion']) : '';

        switch ($accion) {

            case 'omitir':
                $temas = isset($input['temas']) && is_array($input['temas']) ? $input['temas'] : [];

                if (!count($temas)) {

                    return self::resultado(['error' => 'Mandame en `temas` las claves de los temas que se omiten.'], true);
                }

                $marca = ConocimientoDelNegocioIaHelper::marcar($owner, $temas, AiConocimientoDelNegocio::ESTADO_OMITIDO, AiConocimientoDelNegocio::FUENTE_AGENTE, $conversation->id);

                $datos = ['ok' => count($marca['marcados']) > 0, 'omitidos' => $marca['marcados']];

                if (count($marca['desconocidos'])) {

                    $datos['temas_desconocidos'] = $marca['desconocidos'];
                    $datos['claves_validas'] = ConocimientoDelNegocioIaHelper::claves();
                }

                if ($marca['aviso'] !== '') {

                    $datos['aviso'] = $marca['aviso'];
                }

                return self::resultado($datos, !$datos['ok']);

            case 'empezar':
                $datos = OnboardingDelNegocioIaHelper::empezar($owner, OnboardingDelNegocioIaHelper::ORIGEN_PEDIDO, $conversation->id);

                return self::resultado($datos, false);

            case 'pausar':
                return self::resultado(OnboardingDelNegocioIaHelper::pausar($owner), false);

            case 'retomar':
                return self::resultado(OnboardingDelNegocioIaHelper::retomar($owner, $conversation->id), false);

            case 'estado':
                return self::resultado(self::estado($owner), false);
        }

        return self::resultado(['error' => 'La accion "' . $accion . '" no existe: usá ' . implode(', ', self::ACCIONES) . '.'], true);
    }

    /**
     * La acción `estado`: cómo viene el conocimiento del negocio.
     *
     * @param  \App\Models\User  $owner
     * @return array<string,mixed>
     */
    protected static function estado(User $owner)
    {
        $conocimiento = ConocimientoDelNegocioIaHelper::estado($owner);
        $catalogo = ConocimientoDelNegocioIaHelper::catalogo();
        $faltan = [];
        $viejos = [];
        $omitidos = [];

        foreach (ConocimientoDelNegocioIaHelper::orden_de_preguntas() as $clave) {

            $estado = $conocimiento['temas'][$clave]['estado'];

            if ($estado === ConocimientoDelNegocioIaHelper::FALTA && count($faltan) < 10) {

                $faltan[] = ['clave' => $clave, 'destino' => $catalogo[$clave]['destino'], 'pregunta' => $catalogo[$clave]['pregunta']];

            } elseif ($estado === ConocimientoDelNegocioIaHelper::VIEJO) {

                $viejos[] = $clave;

            } elseif ($estado === AiConocimientoDelNegocio::ESTADO_OMITIDO) {

                $omitidos[] = $clave;
            }
        }

        return [
            'onboarding' => OnboardingDelNegocioIaHelper::estado_de($owner),
            'cuentas'    => $conocimiento['cuentas'],
            'faltan'     => $faltan,
            'viejos'     => $viejos,
            'omitidos'   => $omitidos,
        ];
    }

    /**
     * El tool_result: JSON con el mismo fallback que el resto del asistente.
     *
     * @param  array  $datos
     * @param  bool  $is_error
     * @return array{content: string, is_error: bool}
     */
    protected static function resultado(array $datos, $is_error)
    {
        return [
            'content'  => json_encode($datos, JSON_UNESCAPED_UNICODE) ?: '[]',
            'is_error' => (bool) $is_error,
        ];
    }
}
