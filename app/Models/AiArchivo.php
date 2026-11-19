<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un archivo que el agente personal le armó al dueño para bajar (misión agente-mejoras-uso-real,
 * 29/10/2026, §4 E3.1 del plan). Hoy, el Excel de la herramienta exportar_excel.
 *
 * El binario vive en el disco `local` (PRIVADO, nunca en `public`), en
 * `asistente_archivos/{user_id}/{uuid}.xlsx`. Se baja por la ruta pública
 * `GET asistente/archivo/{uuid}?t=<token>` (ArchivoDelAgenteController), que exige el token del link:
 * acá se guarda SOLO su sha256 (`token_hash`), así que ni con la base en la mano se arma un link que
 * funcione. A los 7 días (`expires_at`) el link da 404 y el comando diario `asistente:limpiar-archivos`
 * borra el archivo y la fila.
 *
 * `user_id` es el DUEÑO de la cuenta (la tenencia del asistente), `auth_user_id` la persona que lo
 * pidió y `ai_message_id` el mensaje del agente que lo generó (null si vino del MCP, que no tiene
 * mensaje).
 *
 * Toda la lógica vive en ArchivosDelAgenteIaHelper: este modelo es solo la fila.
 */
class AiArchivo extends Model
{
    /** El único tipo que existe hoy. */
    const TIPO_EXCEL = 'excel';

    /**
     * Una foto que el agente dejó en un tamaño exacto (misión asistente-diario-20261119, rama C, M3:
     * FotoAMedidaIaHelper). Mismo link con token y vencimiento que el Excel; cambia el Content-Type.
     */
    const TIPO_IMAGEN = 'imagen';

    protected $table = 'ai_archivos';

    protected $guarded = [];

    /**
     * El hash del token nunca sale en un toArray()/JSON: es la llave del link.
     *
     * @var array<int, string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'filas'      => 'integer',
    ];

    /**
     * Scope estándar del proyecto (fullModel): este modelo no tiene relaciones que expandir, pero
     * el scope existe para que nadie tenga que acordarse de que este es el único que no lo trae.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * El mensaje del agente que generó el archivo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function mensaje()
    {
        return $this->belongsTo(AiMessage::class, 'ai_message_id');
    }

    /**
     * true si el link ya venció (o si la fila no tiene vencimiento, que no debería pasar: sin fecha
     * se toma como vencido para no dejar un link eterno).
     *
     * @return bool
     */
    public function vencido(): bool
    {
        return is_null($this->expires_at) || $this->expires_at->isPast();
    }

    /**
     * El Content-Type con el que se sirve el archivo (misión asistente-diario-20261119, rama C, M3). El
     * Excel, como siempre; una imagen, por la extensión de SU ruta (la escribe FotoAMedidaIaHelper: .jpg
     * o .png). Sale de la fila, nunca del pedido.
     *
     * @return string
     */
    public function content_type(): string
    {
        if ($this->tipo === self::TIPO_IMAGEN) {

            return substr(strtolower((string) $this->path), -4) === '.png' ? 'image/png' : 'image/jpeg';
        }

        return \App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper::MIME_XLSX;
    }
}
