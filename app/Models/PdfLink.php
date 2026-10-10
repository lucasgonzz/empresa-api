<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un link con token para abrir un PDF sin sesión (misión pdf-de-venta-publico, 10/10/2026).
 *
 * Una fila por recurso compartido (`tipo` + `model_id`), creada la primera vez que alguien arma un
 * link para mandarlo fuera del sistema (WhatsApp, mail, plantilla de Meta, asistente, tienda) y
 * reutilizada después. La crea y la busca `PdfLinkHelper`; la valida el middleware
 * `DescargaDelComercio`.
 *
 * 🔴 CONTRATO CON tienda-api: la tabla vive en la base que comparten empresa y tienda, y tienda-api
 * inserta filas acá. Ver la migración `create_pdf_links_table` antes de tocar una columna.
 *
 * - `user_id`: dueño del recurso.
 * - `tipo`: uno de `PdfLinkHelper::TIPOS_CON_TOKEN`.
 * - `revoked_at`: con fecha, el link deja de abrir.
 */
class PdfLink extends Model
{
    /**
     * @var array
     */
    protected $fillable = [
        'user_id',
        'tipo',
        'model_id',
        'token',
        'revoked_at',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'user_id'    => 'integer',
        'model_id'   => 'integer',
        'revoked_at' => 'datetime',
    ];

    /**
     * Scope que piden `fullModel()` y los index de los ABM (regla del repo). Vacío a propósito: el
     * link no tiene relaciones que cargar.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeWithAll($query)
    {
    }
}
