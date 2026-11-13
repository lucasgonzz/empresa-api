<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un aviso del asistente para el dueño que sale POR WHATSAPP por el camino de avisos que consulta el
 * admin (misión agente-mejoras-uso-real, ronda de arreglos, 30/10/2026): el de un turno que no se
 * pudo terminar —se cortó con fotos sin cargar, o quedó colgado en `pendiente`—, el de una importación
 * lista y, desde el 13/11/2026, el de una imagen pedida por WhatsApp. Guarda también los sellos de los
 * avisos opcionales del dueño (pedidos de la tienda, stock cero), que salen por los recordatorios.
 *
 * Lo escribe AvisosDeTurnosIaHelper::dejar() (una fila por mensaje del asistente, nunca dos) y lo
 * sirve y lo sella AvisosDelAsistenteHelper (`turno_sin_terminar:{uuid}`). `owner_id` es el dueño (no
 * `user_id`: ver la migración 2026_10_30_090000).
 */
class AiAvisoDelAsistente extends Model
{
    /** El aviso de un turno que no terminó (cortado con fotos sin cargar, o colgado). */
    const TIPO_TURNO_SIN_TERMINAR = 'turno_sin_terminar';

    /**
     * El aviso de «ya leí tu Excel» de una importación que el admin pidió en segundo plano desde el ticket
     * de soporte (misión prueba-martin-importacion, 3/11/2026): ver AvisosDeImportacionHelper. `uuid` es el
     * de la `ExcelAnalysisRun`; sin conversación ni mensaje.
     */
    const TIPO_IMPORTACION_LISTA = 'importacion_lista';

    /**
     * El aviso de «ya está la imagen que me pediste» por WhatsApp (misión asistente-truvari-pedidos-fallidos,
     * C1, 13/11/2026): ver ImagenPorWhatsappIaHelper. `uuid` es el del IaImagenPedido y `ai_message_id` el
     * mensaje de WhatsApp que la pidió. También lleva el «no pude generarla», para que la promesa «te la
     * mando por acá» se cumpla pase lo que pase.
     */
    const TIPO_IMAGEN_POR_WHATSAPP = 'imagen_por_whatsapp';

    /**
     * Los SELLOS de los avisos opcionales del dueño que salen por el canal de recordatorios (misión
     * asistente-truvari-pedidos-fallidos, C2): una fila por pedido de la tienda avisado, por resumen de una
     * hora fija y por artículo sin stock en el día. Nacen ya selladas (con `avisado_por_whatsapp_at`), así
     * que el camino de avisos nunca las ofrece: son la memoria de «esto ya salió». Ver
     * AvisosDelDuenoIaHelper.
     */
    const TIPO_PEDIDO_TIENDA = 'pedido_tienda';

    const TIPO_PEDIDOS_HORA = 'pedidos_hora';

    const TIPO_STOCK_CERO = 'stock_cero';

    protected $table = 'ai_avisos_del_asistente';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id'                => 'integer',
        'ai_conversation_id'      => 'integer',
        'ai_message_id'           => 'integer',
        'avisado_por_whatsapp_at' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel(). Sin relaciones que traer.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
