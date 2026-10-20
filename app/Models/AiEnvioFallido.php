<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un envío a clientes del asistente que NO salió después del sí del dueño (misión
 * asistente-arreglos-segunda-prueba, 19/10/2026, defecto N3).
 *
 * 🔴 EL CASO REAL. En la segunda prueba por WhatsApp contra demo3 el dueño confirmó mandarle un
 * presupuesto a un cliente por mail; el asistente le dijo que se lo había mandado y el mail murió en el
 * worker. El fallo quedó en `failed_jobs` y en el log, y nadie se lo contó al dueño. Esta fila ata el
 * fallo a la tarjeta y a la conversación para que el aviso vuelva por WhatsApp
 * (`AvisosDelAsistenteHelper::pendientes()`, id `envio_fallido:{uuid}`).
 *
 * Se escribe SIEMPRE por `EnviosFallidosDelAsistenteHelper::registrar()`, que nunca lanza: un registro
 * que falla no puede voltear el worker ni el turno del asistente que lo disparó.
 *
 * Columnas (ver la migración 2026_10_19_200000): `owner_id` es el dueño (no `user_id`, para que el
 * motor de lectura del asistente no la derive como entidad), `tipo_envio` comprobante | recordatorios |
 * promocion, `canal` mail | whatsapp, `nombres` la lista JSON de un lote, `cantidad` cuántos no
 * salieron, y los dos sellos: `avisado_por_whatsapp_at` (el admin mandó el aviso) y
 * `dicho_en_el_turno_at` (el fallo ya se le dijo en el mismo turno, con la cola sync).
 */
class AiEnvioFallido extends Model
{
    /** Los tres envíos a clientes del asistente. */
    const TIPO_COMPROBANTE = 'comprobante';

    const TIPO_RECORDATORIOS = 'recordatorios';

    const TIPO_PROMOCION = 'promocion';

    /** Los dos canales. */
    const CANAL_MAIL = 'mail';

    const CANAL_WHATSAPP = 'whatsapp';

    protected $table = 'ai_envios_fallidos';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'                => 'integer',
        'ai_conversation_id'      => 'integer',
        'ai_message_action_id'    => 'integer',
        'cantidad'                => 'integer',
        'avisar_por_whatsapp'     => 'boolean',
        'avisado_por_whatsapp_at' => 'datetime',
        'dicho_en_el_turno_at'    => 'datetime',
    ];

    /**
     * Los nombres del lote, como lista (la columna los guarda en JSON).
     *
     * @return array<int, string>
     */
    public function lista_de_nombres()
    {
        $nombres = json_decode((string) $this->nombres, true);

        if (!is_array($nombres)) {
            return is_null($this->cliente) || $this->cliente === '' ? [] : [(string) $this->cliente];
        }

        return array_values(array_map('strval', $nombres));
    }

    /**
     * Es un registro interno sin relaciones que cargar: el scope existe por la regla del repo (todo
     * modelo responde withAll()) y no agrega nada.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
