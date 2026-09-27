<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una consulta a un servicio externo del circuito de imágenes de artículos (misión
 * imagenes-catalogo-completo, agregado del 27/9/2026, plan §12.1): una búsqueda a Serper / Google o
 * una validación con IA. Es el registro que el admin muestra por cliente.
 *
 * Se graba SOLO con ImageServiceCallLogger::registrar(), que nunca lanza y tapa las claves.
 */
class ImageServiceCall extends Model
{
    /** El motor de las asignaciones inteligentes. */
    const ORIGEN_ASIGNACION = 'asignacion';

    /** ArticleImageValidationService::validate(): la búsqueda por código del asistente y el lote viejo. */
    const ORIGEN_VALIDACION_INDIVIDUAL = 'validacion_individual';

    const TIPO_BUSQUEDA      = 'busqueda';
    const TIPO_VALIDACION_IA = 'validacion_ia';

    const PROVEEDOR_ANTHROPIC = 'anthropic';

    /** Días que se guarda el registro (se purga al crear una asignación). */
    const DIAS_DE_RETENCION = 180;

    protected $table = 'image_service_calls';

    protected $guarded = [];

    protected $casts = [
        'user_id'                => 'integer',
        'run_id'                 => 'integer',
        'item_id'                => 'integer',
        'article_id'             => 'integer',
        'ok'                     => 'boolean',
        'cobrada'                => 'boolean',
        'http_status'            => 'integer',
        'resultados'             => 'integer',
        'candidatas'             => 'integer',
        'tokens_entrada'         => 'integer',
        'tokens_salida'          => 'integer',
        'tokens_cache_escritura' => 'integer',
        'tokens_cache_lectura'   => 'integer',
        'duracion_ms'            => 'integer',
    ];

    /**
     * Convención del workspace: todo modelo nuevo lo declara aunque quede vacío. Las filas las arma
     * AdminSync\ImagenesController campo por campo.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
    }
}
