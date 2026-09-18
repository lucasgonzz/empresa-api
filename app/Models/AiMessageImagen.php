<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una foto que el dueño le mandó al asistente por WhatsApp (misión asistente-por-whatsapp,
 * 16/9/2026).
 *
 * Cuelga del AiMessage 'user' que la trajo. El binario vive en el disco 'local' (privado, nunca el
 * público: una factura de proveedor es información fiscal) y la fila guarda su ruta, su tipo y su
 * peso ya redimensionado.
 *
 * `gestionada_at` null significa "todavía nadie dijo de qué es esta foto": es lo que hace que la
 * foto de un turno y el nombre del proveedor del turno siguiente se encuentren. La sella
 * HerramientasDeCarga al confirmar la compra con factura.
 */
class AiMessageImagen extends Model
{
    /** Nombre de la tabla: el plural automático de Eloquent daría `ai_message_imagens`. */
    protected $table = 'ai_message_imagenes';

    protected $guarded = [];

    /**
     * @var array<string,string>
     */
    protected $casts = [
        'ai_message_id' => 'integer',
        'user_id'       => 'integer',
        'orden'         => 'integer',
        'bytes'         => 'integer',
        'gestionada_at' => 'datetime',
    ];

    /**
     * Mensaje que trajo la foto.
     */
    public function message()
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    /**
     * Solo las fotos que todavía no usó ninguna carga.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSinGestionar($query)
    {
        return $query->whereNull('gestionada_at');
    }

    /**
     * Scope requerido por Controller::fullModel(). No tiene relaciones que cargar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }
}
