<?php

namespace App\Models;

use App\Http\Controllers\Helpers\asistente_ia\MencionesIaHelper;
use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje de una conversación del asistente de IA.
 *
 * rol: 'user' (lo escribió la persona) | 'assistant' (lo generó la IA).
 *
 * estado (relevante para rol 'assistant'):
 * - 'listo': tiene `contenido` definitivo.
 * - 'pendiente': el job de respuesta todavía no terminó; `contenido` es null
 *   y la SPA muestra el indicador de "pensando".
 * - 'error': la generación falló. `contenido` lleva un texto amigable que el
 *   usuario SÍ ve en la conversación y `error_mensaje` el detalle técnico.
 *
 * Los mensajes 'user' nacen directamente 'listo'.
 *
 * `acciones_habilitadas` (misión asistente-ia-acciones): true si el assistant
 * se generó con las herramientas de carga (la SPA nueva manda `acciones: true`
 * en el POST). Sin el flag, la respuesta es de solo lectura como siempre.
 *
 * `menciones` (misión agente-ia-mano-derecha): los clientes y artículos que la
 * respuesta nombró, con el literal exacto con que los nombró, para que la SPA
 * los pinte clickeables. Ver MencionesIaHelper.
 *
 * `canal` (misión asistente-por-whatsapp): 'sistema' (el panel del chat, el
 * default de la columna y lo que era todo hasta hoy) | 'whatsapp' (el dueño
 * escribiendo al número de ComercioCity, empujado por el admin). El canal
 * cambia el prompt y qué herramientas se declaran: en WhatsApp no hay tarjeta
 * que tocar, así que la confirmación es por texto (confirmar_carga_pendiente).
 * `whatsapp_message_id` guarda el wamid del entrante que originó el turno. Va en
 * las DOS filas del turno (el 'user' y el 'assistant') y es lo que vuelve
 * idempotente el reintento del admin: si ese wamid ya entró, se devuelven los
 * ids de la primera vez en vez de crear un segundo turno y mandarle al dueño dos
 * veces la misma respuesta.
 *
 * `tipo`: 'texto' | 'audio' | 'imagen' — con qué lo mandó la persona. Un audio
 * llega ya transcripto por Kapso; el que llega SIN transcribir se contesta de
 * forma determinista y sin salir a la IA (ver AdminSync\AsistenteController).
 */
class AiMessage extends Model
{
    /** Canal de un mensaje escrito desde el panel del chat del sistema (default de la columna). */
    const CANAL_SISTEMA = 'sistema';

    /** Canal de un mensaje que entró por WhatsApp, empujado por el admin. */
    const CANAL_WHATSAPP = 'whatsapp';

    /** Con qué lo mandó la persona. 'texto' es el default de la columna y lo único que hay en la pantalla. */
    const TIPO_TEXTO = 'texto';

    const TIPO_AUDIO = 'audio';

    const TIPO_IMAGEN = 'imagen';

    protected $guarded = [];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'acciones_habilitadas' => 'boolean',
    ];

    /**
     * 🔴 `menciones` va en $appends ADEMÁS de ser una columna, y no es redundante: el
     * `assistant_message` que devuelve el POST es un modelo recién creado, y un modelo recién
     * creado SOLO tiene en $attributes lo que se le pasó al create(). Sin el append, la clave no
     * viajaba en ese tercero de los tres lugares —justo el que la SPA usa para pintar el globo
     * optimista— y el contrato dice "nunca ausente". Es el mismo problema que en este mismo módulo
     * resolvió el refresh() de AiConversationController::store(), acá resuelto sin una consulta de
     * más en el camino caliente del POST.
     *
     * @var array<int, string>
     */
    protected $appends = ['menciones'];

    /**
     * Las menciones SIEMPRE como lista (contrato §1: si no hay va `[]`, nunca null y nunca
     * ausente, igual que `acciones`).
     *
     * 🔴 POR QUÉ UN ACCESSOR Y NO UN CAST NI UN ARMADO EN EL CONTROLLER. Un mensaje viaja por TRES
     * lugares —el índice paginado, show_message y el `assistant_message` del POST— y la SPA usa los
     * tres. Con el accessor, cualquier serialización de un AiMessage sale con `menciones`: no hay
     * una cuarta punta que se pueda olvidar mañana. Con el cast 'array' la columna en null saldría
     * como null, que es justo lo que el contrato prohíbe.
     *
     * ⚠️ Lee de $attributes y NO del argumento: por el append, Laravel llama a este accessor una
     * segunda vez con null (Model::attributesToArray()), y si se usara el argumento esa pasada
     * pisaría las menciones reales con [].
     *
     * @param  mixed  $valor  Ignorado a propósito (ver arriba).
     * @return array<int, array<string, mixed>>
     */
    public function getMencionesAttribute($valor = null)
    {
        $crudo = array_key_exists('menciones', $this->attributes) ? $this->attributes['menciones'] : null;

        return MencionesIaHelper::normalizar($crudo);
    }

    /**
     * Guarda las menciones como JSON. Una lista vacía se guarda como null: es lo mismo que "no
     * tiene" y deja la columna igual a la de todos los mensajes anteriores a la misión.
     *
     * @param  mixed  $valor
     * @return void
     */
    public function setMencionesAttribute($valor)
    {
        $menciones = MencionesIaHelper::normalizar($valor);

        $this->attributes['menciones'] = empty($menciones)
            ? null
            : json_encode($menciones, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Conversación a la que pertenece el mensaje.
     */
    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * Tarjetas de carga que propuso este mensaje, en el orden en que se crearon.
     * Qué se muestra de cada mensaje lo decide AccionesIaHelper::cargar_en_mensajes():
     * un mensaje que no está 'listo' nunca muestra tarjetas.
     */
    public function acciones()
    {
        return $this->hasMany(AiMessageAction::class, 'ai_message_id')->orderBy('id');
    }

    /**
     * Fotos que trajo este mensaje, en el orden en que llegaron (misión asistente-por-whatsapp).
     * Solo las tienen los mensajes 'user' de canal 'whatsapp'.
     */
    public function imagenes()
    {
        return $this->hasMany(AiMessageImagen::class, 'ai_message_id')->orderBy('orden');
    }

    /**
     * true si el mensaje entró por WhatsApp.
     *
     * @return bool
     */
    public function es_de_whatsapp()
    {
        return (string) $this->canal === self::CANAL_WHATSAPP;
    }
}
