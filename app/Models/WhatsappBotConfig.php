<?php

namespace App\Models;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use Illuminate\Database\Eloquent\Model;

class WhatsappBotConfig extends Model
{
    /*
     * Objetivos de la conversación que el dueño le puede poner al agente (misión
     * objetivo-agente-whatsapp, columna `agent_objective`). NULL = sin objetivo, que es como quedan
     * todos los negocios que ya existían: el system prompt no cambia.
     */

    /** Que el cliente pase por el local (usa las sucursales con domicilio y el horario). */
    const OBJETIVO_VISITAR_LOCAL = 'visit_branch';

    /** Que el cliente compre en la tienda online (usa `users.online`). */
    const OBJETIVO_COMPRAR_ONLINE = 'buy_online';

    /** Que el cliente deje el pedido por el chat, para que el negocio lo confirme. */
    const OBJETIVO_PEDIDO_POR_WHATSAPP = 'order_by_whatsapp';

    /** Otro objetivo: lo escribe el dueño en `agent_objective_detail` (sin texto, no hay objetivo). */
    const OBJETIVO_PERSONALIZADO = 'custom';

    /**
     * Lista cerrada de valores válidos de `agent_objective`. La usan la validación del endpoint
     * (`WhatsappBotController::update_config`) y el armado de la capa del prompt
     * (`AgentObjectivePromptBuilder`), que trata cualquier otro valor como sin objetivo.
     *
     * @var array<int, string>
     */
    const OBJETIVOS = [
        self::OBJETIVO_VISITAR_LOCAL,
        self::OBJETIVO_COMPRAR_ONLINE,
        self::OBJETIVO_PEDIDO_POR_WHATSAPP,
        self::OBJETIVO_PERSONALIZADO,
    ];

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        // Si los chats nuevos nacen con la respuesta automática de IA prendida por defecto.
        'ai_enabled_default' => 'boolean',
        // Si se debe enviar automáticamente el comprobante PDF de la venta por el agente.
        'auto_send_sale_pdf' => 'boolean',
        // Segundos que espera el agente después de un mensaje entrante antes de generar la
        // respuesta (agrupa los mensajes que el cliente manda seguidos). 0 = responde al instante.
        'ai_reply_delay_seconds' => 'integer',
        // Segundos que la respuesta generada espera confirmación humana antes de enviarse
        // sola. 0 = se envía sin esperar a nadie.
        'ai_confirm_delay_seconds' => 'integer',
        // Si el agente interpreta las imágenes que manda el cliente. Se castea a booleano
        // porque MySQL devuelve 0/1 como string y este valor decide, con un `if`, si el turno
        // viaja a Anthropic como texto o como bloques con la imagen en base64: un '0' string
        // es truthy en PHP y prendería la visión (y su costo) en TODOS los negocios.
        'ai_vision_enabled' => 'boolean',
        // Si el dueño habilitó el botón de simular mensajes del cliente dentro de la
        // conversación (y en la bandeja). Mismo motivo que `ai_vision_enabled`: MySQL
        // devuelve 0/1 como string y un '0' string es truthy en PHP.
        'chat_simulation_enabled' => 'boolean',
        // Envío simulado de las instancias de demo (misión whatsapp-envio-simulado-demos). Se
        // castea a booleano por el mismo motivo que los otros toggles: MySQL devuelve 0/1 como
        // string y un '0' string es truthy en PHP. NO se lee directo para decidir si se simula:
        // se pasa siempre por `esta_en_modo_demostracion()`.
        'envio_simulado' => 'boolean',
    ];

    /**
     * Viaja siempre en la serialización: la SPA lo lee para mostrar el cartel «Modo
     * demostración: los mensajes no se envían» en la pantalla de Conexión. Es un valor derivado
     * y de solo lectura (no existe como columna ni se puede escribir por la API), y opcional
     * para el front: una SPA vieja no lo mira.
     *
     * @var array
     */
    protected $appends = ['modo_demostracion'];

    /**
     * ¿Este negocio está en modo demostración, o sea los mensajes de WhatsApp NO se mandan a
     * Kapso sino que se dan por enviados con un id ficticio?
     *
     * 🔴 SON DOS CONDICIONES Y NO SE PUEDE QUITAR NINGUNA, aunque parezca que con la columna
     * alcanza. `envio_simulado` por sí sola no basta: si alguien la deja en 1 en la base de un
     * cliente real (a mano, por una copia de base de una demo, por un script mal apuntado), ese
     * cliente tendría comprobantes y recordatorios de cobro que la pantalla muestra como
     * enviados y que nunca salen — sin un solo error en ningún lado, que es el peor modo de
     * falla de este módulo (ya costó el bug del 15/8/2026: «una fila persistida como enviada
     * sobre un envío que nunca ocurrió»). Por eso además se exige que la instalación sea una
     * instancia de demo (`FOR_USER=demo` en el `.env`, o un entorno local/de test): en una
     * instalación de cliente la columna se ignora y el mensaje sale de verdad por Kapso. Falla
     * hacia el lado seguro.
     *
     * Y `envio_simulado` no la escribe ninguna pantalla ni endpoint (`update_config` no la
     * acepta): la siembra `WhatsappDemoHelper`, o se prende a mano en la base.
     *
     * @return bool
     */
    public function esta_en_modo_demostracion(): bool
    {
        if (! $this->envio_simulado) {
            return false;
        }

        return DemoSetupHelper::es_instancia_de_demo();
    }

    /**
     * Valor del atributo derivado `modo_demostracion` (ver `$appends`).
     *
     * @return bool
     */
    public function getModoDemostracionAttribute(): bool
    {
        return $this->esta_en_modo_demostracion();
    }

    /**
     * Retorna la configuración activa para el user dado, o null si no existe.
     */
    public static function getForUser(int $user_id): ?self
    {
        return static::where('user_id', $user_id)
            ->where('is_active', true)
            ->first();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
