<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para guardar el estado y el resultado del análisis de un Excel que corre
 * en segundo plano.
 *
 * Con archivos de miles de filas, el análisis síncrono dentro de un request HTTP
 * supera el timeout. Esta tabla permite persistir la corrida en cola y consultarla
 * cuando esté lista.
 *
 * Los campos `payload`, `resultado` y `codigos_proveedor` se almacenan como JSON
 * y se cargan automáticamente como arrays PHP mediante los casts.
 */
class ExcelAnalysisRun extends Model
{
    /* Sin restricciones de asignación masiva: se inserta/actualiza vía array. */
    protected $guarded = [];

    /* Campos JSON que deben exponerse como arrays PHP. */
    protected $casts = [
        'payload' => 'array',
        'resultado' => 'array',
        'codigos_proveedor' => 'array',
        /* Momento en que el usuario abrió el resultado; null mientras no lo vio. */
        'visto_at' => 'datetime',
    ];

    /**
     * Scope requerido por Controller::fullModel() para poder traer el modelo
     * con sus relaciones "completas". Este modelo no expone relaciones propias
     * por ahora, así que devuelve el query sin modificar (se completa si se
     * agregan relaciones).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query;
    }

    /**
     * Corrida de análisis de la que salió esta recomendación, o null.
     *
     * Una corrida tipo 'recomendacion' nace del paso 2 del modal, que a su vez
     * nació de un análisis: guarda el uuid de ese análisis en su payload. No es
     * una relación de Eloquent porque el vínculo vive dentro del JSON, no en una
     * foreign key.
     *
     * @return \App\Models\ExcelAnalysisRun|null
     */
    public function analisis_padre()
    {
        $payload = $this->payload ?? [];

        if (empty($payload['analysis_uuid'])) {
            return null;
        }

        return Self::where('uuid', $payload['analysis_uuid'])
            ->where('user_id', $this->user_id)
            ->first();
    }

    /**
     * Los dos datos que el frontend necesita para hablar de esta corrida sin
     * abrirla: de qué archivo es y a qué módulo pertenece (article/client/provider).
     *
     * Los dos se guardan al crear el análisis. Una recomendación no los tiene
     * propios — es el segundo tramo del mismo flujo —, así que los hereda de su
     * análisis padre. Si el padre ya no existe (limpieza por antigüedad), se
     * devuelven los defaults: el aviso pierde el nombre del archivo, no se rompe.
     *
     * @return array  ['model' => string, 'original_filename' => string]
     */
    public function datos_de_presentacion()
    {
        $payload = $this->payload ?? [];

        if ($this->tipo === 'recomendacion') {
            $padre = $this->analisis_padre();
            $payload = !is_null($padre) ? ($padre->payload ?? []) : [];
        }

        return [
            'model'             => (string) ($payload['model'] ?? 'article'),
            'original_filename' => (string) ($payload['original_filename'] ?? ''),
        ];
    }

    /**
     * Todo lo que el modal necesita para rearmarse desde cero, sin el archivo
     * local y sin nada guardado en memoria del navegador.
     *
     * Es la contracara de haber sacado al usuario de la pantalla de espera: si
     * puede irse a otro módulo, cerrar la pestaña y volver una hora después, el
     * estado del modal ya no puede vivir en el componente. Lo que devuelve este
     * método es exactamente ese estado.
     *
     * No incluye el resultado del análisis (eso va aparte, en 'resultado', y solo
     * cuando la corrida terminó).
     *
     * @return array
     */
    public function contexto_para_frontend()
    {
        $payload = $this->payload ?? [];
        $presentacion = $this->datos_de_presentacion();

        $contexto = [
            'model'             => $presentacion['model'],
            'original_filename' => $presentacion['original_filename'],
        ];

        if ($this->tipo === 'recomendacion') {
            /*
             * Para volver al paso 3 hace falta el paso 2 tal como el usuario lo
             * dejó (proveedor confirmado y mapeo corregido a mano), más el uuid
             * del análisis del que salió.
             */
            $contexto['analysis_uuid']              = $payload['analysis_uuid'] ?? null;
            $contexto['provider_id']                = $payload['provider_id'] ?? null;
            $contexto['provider_code_column_index'] = $payload['provider_code_column_index'] ?? null;
            $contexto['column_mapping']             = $payload['column_mapping'] ?? [];

            return $contexto;
        }

        /* Análisis: el rango de filas que el navegador calculó al elegir el archivo. */
        $contexto['start_row']      = $payload['start_row'] ?? null;
        $contexto['finish_row']     = $payload['finish_row'] ?? null;
        $contexto['has_header_row'] = $payload['has_header_row'] ?? null;

        return $contexto;
    }
}
