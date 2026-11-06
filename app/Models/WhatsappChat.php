<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Representa una conversación de WhatsApp entre la empresa y un teléfono (un chat por
 * teléfono por empresa). `client_id` puede ser null cuando el teléfono todavía no está
 * cargado como cliente del negocio.
 *
 * `last_inbound_at` es la única fuente de verdad de la ventana de 24 h de Meta: fuera de
 * esa ventana solo se puede responder con plantillas aprobadas (lo consumen los
 * controllers/services de los Prompts 02, 04 y 05 vía `is_within_service_window()`).
 */
class WhatsappChat extends Model
{
    /**
     * Valores de `follow_up` / `ai_follow_up` (misión senal-pedidos-y-consultas-del-agente): lo que el
     * agente dejó para que una persona atienda. 'pedido' = armó un pedido que el negocio tiene que
     * confirmar; 'consulta' = prometió averiguar o confirmar algo («lo consulto y te confirmo»).
     * Es el vocabulario del marcador que el modelo escribe (`[PEDIDO:…]` / `[CONSULTA:…]`).
     */
    const FOLLOW_UP_PEDIDO = 'pedido';
    const FOLLOW_UP_CONSULTA = 'consulta';

    const MODE_AUTOMATIC = 'automatic';
    const MODE_SEMI_AUTOMATIC = 'semi_automatic';
    const MODE_MANUAL = 'manual';
    const TEST_PHONE_PREFIX = 'test-';
    const TRIAL_TEST_PHONE = 'test-trial';

    /**
     * Resultado memoizado de `follow_up_available()`: solo se guarda el `true` (ver ese método).
     *
     * @var bool|null
     */
    private static $follow_up_available = null;

    protected $guarded = [];

    protected $appends = ['is_test', 'ai_mode'];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'ai_schedule_token' => 'integer',
    ];

    public function getIsTestAttribute(): bool
    {
        return strpos((string) $this->phone, self::TEST_PHONE_PREFIX) === 0;
    }

    public function getAiModeAttribute(): string
    {
        return $this->configured_ai_mode ?: ($this->ai_enabled ? self::MODE_AUTOMATIC : self::MODE_MANUAL);
    }

    public static function configured_ai_mode_available(): bool
    {
        return Schema::hasColumn('whatsapp_chats', 'configured_ai_mode');
    }

    public function scopeRealContacts($query)
    {
        return $query->where('phone', 'not like', self::TEST_PHONE_PREFIX.'%');
    }

    public function bot_config(): ?WhatsappBotConfig
    {
        $config = WhatsappBotConfig::where('user_id', $this->user_id)->first();

        // La prueba usa el catálogo y el perfil del comercio aunque no conectó su línea.
        return $config ?? ($this->is_test ? new WhatsappBotConfig(['user_id' => $this->user_id]) : null);
    }

    public function can_generate_ai(WhatsappBotConfig $config): bool
    {
        return ($this->is_test || $config->is_active) && $this->ai_mode !== self::MODE_MANUAL;
    }

    /**
     * Relaciones a precargar cuando el controller pide el modelo completo vía
     * `Controller::fullModel()`. Sin este scope, `fullModel()` rompe.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with(['client', 'user']);
    }

    /**
     * Empresa (dueño) a la que pertenece el chat.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Cliente del negocio vinculado al chat (puede ser null).
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Mensajes del chat, ordenados cronológicamente (el más viejo primero).
     */
    public function messages()
    {
        return $this->hasMany(WhatsappChatMessage::class)->orderBy('created_at');
    }

    /**
     * Indica si el chat todavía está dentro de la ventana de 24 h que impone Meta para
     * poder responder libremente (sin usar una plantilla aprobada). Se calcula a partir
     * de `last_inbound_at`: si nunca hubo un mensaje entrante, o pasaron 24 h o más desde
     * el último, el chat queda fuera de ventana.
     *
     * @return bool
     */
    public function is_within_service_window()
    {
        if ($this->is_test) {
            return true;
        }

        if (is_null($this->last_inbound_at)) {
            return false;
        }

        return $this->last_inbound_at->diffInHours(now()) < 24;
    }

    /**
     * Indica si el chat tiene una respuesta de la IA generada y todavía sin aprobar
     * (`ai_status = 'a_confirmar'`, ver el docblock de la máquina de estados en
     * `WhatsappChatHelper`). Es la tarjeta amarilla del tablero (misión
     * whatsapp-tablero-clientes) y el resaltado amarillo de la fila en la bandeja.
     *
     * @return bool
     */
    public function is_esperando_aprobacion()
    {
        return WhatsappChatMessage::where('whatsapp_chat_id', $this->id)
            ->where('ai_status', 'a_confirmar')
            ->exists();
    }

    /**
     * Indica si el último mensaje "real" del chat (excluyendo los `a_confirmar`, que
     * todavía no se dijeron) es entrante: o sea, si el cliente escribió y nadie —ni un
     * humano ni la IA— le contestó todavía. Es la tarjeta roja del tablero (misión
     * whatsapp-tablero-clientes) y el resaltado rojo de la fila en la bandeja.
     *
     * 🔴 ES EL MISMO CRITERIO, A PROPÓSITO REPLICADO Y NO IMPORTADO, que
     * `GenerateWhatsappAiReplyJob::has_unanswered_inbound()` (el que usa el agente
     * automático para decidir si tiene que generar una respuesta). No se centralizó ahí
     * porque ese Job dispara mensajes reales a clientes reales y tocarlo por una razón
     * puramente visual es más riesgo del que vale la pena — si el criterio de "sin
     * responder" cambia alguna vez, hay que cambiarlo en los dos lugares.
     *
     * @return bool
     */
    public function is_sin_responder()
    {
        $last_real_message = WhatsappChatMessage::where('whatsapp_chat_id', $this->id)
            ->where(function ($query) {
                $query->whereNull('ai_status')->orWhere('ai_status', '!=', 'a_confirmar');
            })
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        if (is_null($last_real_message)) {
            return false;
        }

        return $last_real_message->direction === 'in';
    }

    /**
     * Estado del chat para el tablero y el color de la fila en la bandeja (misión
     * whatsapp-tablero-clientes): 'esperando_aprobacion' | 'sin_responder' | null.
     *
     * 🔴 LA PRIORIDAD NO ES ARBITRARIA. Un chat con un mensaje `a_confirmar` pendiente
     * TAMBIÉN cumple el criterio crudo de `is_sin_responder()`: ese método excluye a
     * propósito los `a_confirmar` al buscar el último mensaje "real", así que ese último
     * real sigue siendo el entrante del cliente. Sin esta precedencia, el mismo chat
     * contaría en las dos tarjetas del tablero a la vez y tendría dos colores. Que "hay
     * algo generado esperando que lo aprueben" es un estado más avanzado que "todavía no
     * se generó nada", así que gana el amarillo.
     *
     * @return string|null
     */
    public function estado_pendiente()
    {
        if ($this->is_esperando_aprobacion()) {
            return 'esperando_aprobacion';
        }

        if ($this->is_sin_responder()) {
            return 'sin_responder';
        }

        return null;
    }

    /**
     * Indica si el esquema ya tiene las columnas del seguimiento (misión
     * senal-pedidos-y-consultas-del-agente). Existe por la VENTANA DEL DEPLOY: el código nuevo
     * puede quedar sirviendo unos segundos antes de que corra la migración, y el hilo caliente del
     * agente (guardar y enviar su respuesta) escribe y lee estas columnas. Sin esta guarda, esa
     * ventana serían 500 y respuestas del agente perdidas sin ningún error a la vista; con ella, el
     * seguimiento simplemente no se marca hasta que la migración corre.
     *
     * 🔴 SE MEMOIZA SOLO EL SÍ, NUNCA EL NO (mismo criterio que `Address::columna_madre_existe()` y
     * `ChequeHelper::columna_de_motivo_acepta_texto()`). Un worker de cola de larga vida que evalúe esto en la
     * ventana entre el deploy del código y la migración, y guardara el `false`, se quedaría sin seguimiento
     * hasta que lo reinicien, y sin ningún error. Con el sí memoizado, el costo de `Schema::hasColumn` (una
     * consulta al catálogo de MySQL) se paga una vez por proceso cuando la migración ya corrió.
     *
     * Se miran las ÚLTIMAS columnas que crea la migración (`follow_up_at` y `ai_follow_up_note`) y no las
     * primeras: una migración cortada a mitad de camino no puede dar «disponible» con una columna que falta.
     *
     * @return bool
     */
    public static function follow_up_available()
    {
        if (self::$follow_up_available === true) {
            return true;
        }

        if (Schema::hasColumn('whatsapp_chats', 'follow_up_at') && Schema::hasColumn('whatsapp_chat_messages', 'ai_follow_up_note')) {
            self::$follow_up_available = true;
        }

        return self::$follow_up_available === true;
    }

    /**
     * Deja el chat marcado para que una persona lo atienda: el agente armó un pedido o prometió
     * averiguar algo y el mensaje YA SALIÓ (lo llama `WhatsappChatHelper::mark_ai_message_sent()`).
     * Modifica el modelo en memoria; el caller hace el `save()`.
     *
     * Reglas:
     *  - 'pedido' gana a 'consulta': una consulta nueva NO baja un pedido que sigue esperando; un
     *    pedido nuevo sí sube una consulta. Es lo más caro de perder, y el tablero lo cuenta aparte.
     *  - `follow_up_at` es «desde cuándo espera»: solo se escribe cuando el chat pasa a un tipo
     *    distinto (de nada a algo, o de consulta a pedido). Otro mensaje del mismo tipo no lo mueve.
     *  - La nota es la del último mensaje que marcó del mismo tipo y trajo detalle (el modelo ve toda la
     *    conversación, así que la última describe el pedido completo y no solo el último renglón).
     *
     * @param string      $tipo Una de las constantes FOLLOW_UP_*.
     * @param string|null $nota Detalle que escribió el modelo (ya acotado), o null.
     *
     * @return bool true si el modelo quedó con cambios para guardar.
     */
    public function mark_follow_up($tipo, $nota = null)
    {
        if (! self::follow_up_available()) {
            return false;
        }

        if (! in_array($tipo, [self::FOLLOW_UP_PEDIDO, self::FOLLOW_UP_CONSULTA], true)) {
            return false;
        }

        // Un pedido pendiente no se degrada a consulta.
        if ($this->follow_up === self::FOLLOW_UP_PEDIDO && $tipo === self::FOLLOW_UP_CONSULTA) {
            return false;
        }

        if ($this->follow_up !== $tipo) {
            // Pasa de nada a algo, o de consulta a pedido: empieza una espera nueva y la nota es la de ahora.
            $this->follow_up_at = now();
            $this->follow_up_note = $nota;
        } elseif (! is_null($nota) && trim((string) $nota) !== '') {
            // Mismo tipo: la nota nueva reemplaza a la vieja SOLO si trae algo (una consulta marcada por la red de
            // seguridad no trae detalle, y no tiene que borrar el que ya había).
            $this->follow_up_note = $nota;
        }

        $this->follow_up = $tipo;

        return true;
    }

    /**
     * Apaga el seguimiento del chat: una persona lo atendió (escribió en el chat o apretó «Ya lo
     * atendí»). Idempotente: sin seguimiento no hace nada y devuelve false.
     *
     * 🔴 VA POR QUERY Y NO POR `save()`, A PROPÓSITO. El modelo que llega acá puede estar viejo
     * (el controller lo cargó antes de que otro proceso —el envío del agente— lo marcara): si en
     * memoria `follow_up` ya es null, Eloquent no ve ningún cambio y NO escribe el UPDATE, y la marca
     * se queda en la base para siempre. El `WHERE follow_up IS NOT NULL` hace la escritura
     * independiente de lo que crea el modelo en memoria; después se lo pone al día para que el
     * broadcast que sigue lleve el estado real.
     *
     * @return bool true si había un seguimiento y se apagó.
     */
    public function clear_follow_up()
    {
        if (! self::follow_up_available()) {
            return false;
        }

        $apagadas = (int) self::where('id', $this->id)
            ->whereNotNull('follow_up')
            ->update(['follow_up' => null, 'follow_up_note' => null, 'follow_up_at' => null]);

        foreach (['follow_up', 'follow_up_note', 'follow_up_at'] as $columna) {
            $this->setAttribute($columna, null);
            $this->syncOriginalAttribute($columna);
        }

        return $apagadas > 0;
    }
}
