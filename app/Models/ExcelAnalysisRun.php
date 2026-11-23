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
            /*
             * Hoja elegida y fila de encabezado con las que se corrió ESTA corrida.
             *
             * Van en el tronco común (no dentro de la rama 'analisis') porque una
             * recomendación también se calcula sobre una hoja y con una fila de
             * encabezado: si el modal se rearma en el paso 3 sin estos valores,
             * vuelve a mostrar la hoja 0 mientras el backend trabajó sobre otra, y
             * el usuario no tiene forma de darse cuenta.
             *
             * Los defaults son los del contrato de §1.4 del plan y son los que
             * hacen que una corrida vieja —creada antes de que existiera la
             * elección de hoja— se lea exactamente como se leía: hoja 0 y
             * detección automática del encabezado.
             */
            'hoja'              => isset($payload['hoja']) && is_numeric($payload['hoja'])
                                    ? (int) $payload['hoja']
                                    : 0,
            'hoja_nombre'       => isset($payload['hoja_nombre']) && $payload['hoja_nombre'] !== ''
                                    ? (string) $payload['hoja_nombre']
                                    : null,
            'header_row'        => isset($payload['header_row']) && is_numeric($payload['header_row'])
                                    ? (int) $payload['header_row']
                                    : null,
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

    /**
     * Antigüedad a partir de la cual la limpieza de RunExcelAnalysisJob borra una corrida (2 días).
     * No aplica a las importaciones pendientes recuperables: esas se conservan hasta que se
     * lanza la importación o el usuario las descarta (ver conservar_al_limpiar()).
     */
    const LIMPIEZA_DIAS = 2;

    /**
     * Máximo de decisiones de nombres parecidos del borrador y largo máximo de cada clave.
     */
    const BORRADOR_MAX_DECISIONES = 5000;
    const BORRADOR_MAX_CLAVE = 300;

    /**
     * Tope del borrador serializado. El borrador guarda el mapeo y las opciones del
     * modal (no filas del Excel), así que unos cientos de KB sobran; el tope evita que
     * un cliente use el payload de la corrida como almacenamiento.
     */
    const BORRADOR_MAX_BYTES = 200000;

    /**
     * Claves "simples" del borrador que se aceptan. Cualquier otra se descarta en silencio:
     * es el estado del modal, nada más. Además de estas, el borrador acepta
     * `decisiones_relaciones` (mapa clave -> índice de opción de los nombres parecidos del
     * paso 3) y `revision` (marca creciente del cliente para ignorar guardados fuera de orden);
     * esas dos se validan aparte en sanear_borrador().
     */
    const BORRADOR_CLAVES = [
        'paso',
        'column_mapping',
        'selected_provider_id',
        'provider_code_column_index',
        'start_row',
        'finish_row',
        'has_header_row',
        'hoja_seleccionada',
        'precios_incluyen_iva',
        'vaciar_valores_en_blanco',
        'actualizar_articulos_de_otro_proveedor',
        'permitir_provider_code_repetido',
        'permitir_provider_code_repetido_en_multi_providers',
        'actualizar_por_provider_code',
        'actualizar_proveedor',
        'create_and_edit',
        'interpretacion_punto',
        'politica_colision',
        'politica_otro_proveedor',
        'politica_intra_archivo',
        'desempatar_por_nombre',
        // Misión importacion-formula-precio (20/11/2026): opciones de lectura del paso 3.
        'descuentos_negativos_descuentan',
        'barras_repetidas_por_codigo_de_proveedor',
        // Misión historial-precios-proveedor: respuesta del paso 3 sobre artículos que ya están con otro proveedor.
        'other_provider_articles_policy',
    ];

    /**
     * Corridas de análisis que el usuario puede retomar: las suyas (mismo owner Y mismo
     * usuario que subió el archivo), del módulo pedido, que no terminaron en error ni fueron
     * finalizadas (importación lanzada o descartada) y cuyo archivo sigue en disco.
     *
     * No hay caducidad por antigüedad: una importación pendiente se conserva hasta que se
     * lanza o se descarta (la limpieza del job respeta eso, ver limpiar_antiguas()).
     *
     * Las que todavía están trabajando se incluyen: el modal las sigue en vivo.
     *
     * @param  int         $owner_id      Dueño del comercio (user_id de la corrida).
     * @param  int|null    $auth_user_id  Usuario autenticado; sin él no se lista nada.
     * @param  string      $model         article | client | provider.
     * @return \Illuminate\Support\Collection  De ExcelAnalysisRun, la más nueva primero.
     */
    public static function pendientes_de($owner_id, $auth_user_id, $model)
    {
        if (empty($owner_id) || empty($auth_user_id)) {
            return collect();
        }

        /* Sin "resultado": es grande y la lista sólo necesita metadatos. */
        $corridas = self::where('user_id', $owner_id)
            ->where('auth_user_id', $auth_user_id)
            ->where('tipo', 'analisis')
            ->where('estado', '!=', 'error')
            ->orderBy('id', 'DESC')
            ->get(self::COLUMNAS_LIVIANAS);

        return $corridas->filter(function ($run) use ($model) {
            $payload = $run->payload ?? [];

            if ((string) ($payload['model'] ?? 'article') !== (string) $model) {
                return false;
            }

            return self::es_recuperable($run);
        })->values();
    }

    /**
     * Columnas que se leen cuando no hace falta el resultado completo del análisis.
     */
    const COLUMNAS_LIVIANAS = [
        'id', 'uuid', 'user_id', 'auth_user_id', 'tipo', 'estado', 'progreso', 'paso',
        'excel_path', 'payload', 'created_at', 'updated_at',
    ];

    /**
     * True si la corrida de análisis es una importación pendiente recuperable: sin error,
     * sin finalizar, no es de PDF (el PDF se borra al terminar) y con el archivo en disco.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @return bool
     */
    public static function es_recuperable($run)
    {
        $payload = $run->payload ?? [];

        if ($run->tipo !== 'analisis' || $run->estado === 'error') {
            return false;
        }

        if (!empty($payload['finalizada_at']) || !empty($payload['pdf_path'])) {
            return false;
        }

        /* Sin el archivo en disco no se puede importar: ofrecerla sería una trampa. */
        return !empty($run->excel_path)
            && file_exists(storage_path('app/' . $run->excel_path));
    }

    /**
     * Limpieza de corridas viejas del comercio (la hace RunExcelAnalysisJob al arrancar).
     *
     * Borra todo lo de más de LIMPIEZA_DIAS días EXCEPTO las importaciones pendientes
     * recuperables ya analizadas (estado "listo") y las recomendaciones "listo" que cuelgan
     * de ellas: el usuario no pidió que caduquen. Las finalizadas, las con error, las sin
     * archivo y las que quedaron colgadas sin terminar se borran como siempre.
     *
     * @param  int  $owner_id
     * @return int  Cuántas filas se borraron.
     */
    public static function limpiar_antiguas($owner_id)
    {
        $viejas = self::where('user_id', $owner_id)
            ->where('created_at', '<', now()->subDays(self::LIMPIEZA_DIAS))
            ->get(self::COLUMNAS_LIVIANAS);

        $conservar_analisis = [];

        foreach ($viejas as $run) {
            if ($run->estado === 'listo' && self::es_recuperable($run)) {
                $conservar_analisis[$run->uuid] = true;
            }
        }

        $a_borrar = [];

        foreach ($viejas as $run) {
            if ($run->tipo === 'analisis') {
                if (!isset($conservar_analisis[$run->uuid])) {
                    $a_borrar[] = $run->id;
                }
                continue;
            }

            /* Recomendación (u otro tipo): se queda sólo si es la lista hija de un análisis que se queda. */
            $padre = ($run->payload ?? [])['analysis_uuid'] ?? null;

            if (!($run->tipo === 'recomendacion' && $run->estado === 'listo' && $padre && isset($conservar_analisis[$padre]))) {
                $a_borrar[] = $run->id;
            }
        }

        foreach (array_chunk($a_borrar, 500) as $ids) {
            self::whereIn('id', $ids)->delete();
        }

        return count($a_borrar);
    }

    /**
     * Última recomendación (paso 3) ya terminada que salió de este análisis, o null.
     * Sirve para retomar en el paso 3 en vez de volver a pedirla a la IA.
     *
     * @return \App\Models\ExcelAnalysisRun|null
     */
    public function recomendacion_lista_hija()
    {
        $candidatas = self::where('user_id', $this->user_id)
            ->where('auth_user_id', $this->auth_user_id)
            ->where('tipo', 'recomendacion')
            ->where('estado', 'listo')
            ->orderBy('id', 'DESC')
            ->get(self::COLUMNAS_LIVIANAS);

        foreach ($candidatas as $candidata) {
            $payload = $candidata->payload ?? [];

            if (($payload['analysis_uuid'] ?? null) === $this->uuid) {
                return $candidata;
            }
        }

        return null;
    }

    /**
     * Deja sólo las claves permitidas del borrador, valida las dos estructuradas y verifica
     * el tope de tamaño.
     *
     * - decisiones_relaciones: mapa { clave(string) => índice(entero 0..99) }; lo que no cumple
     *   se descarta, y más de BORRADOR_MAX_DECISIONES entradas rechaza el borrador entero.
     * - revision: entero positivo (se descarta si no lo es).
     *
     * @param  array  $borrador
     * @return array|null  El borrador saneado, o null si supera los topes.
     */
    public static function sanear_borrador(array $borrador)
    {
        $limpio = array_intersect_key($borrador, array_flip(self::BORRADOR_CLAVES));

        if (isset($borrador['decisiones_relaciones']) && is_array($borrador['decisiones_relaciones'])) {
            if (count($borrador['decisiones_relaciones']) > self::BORRADOR_MAX_DECISIONES) {
                return null;
            }

            $decisiones = [];

            foreach ($borrador['decisiones_relaciones'] as $clave => $indice) {
                $clave = (string) $clave;

                if ($clave === '' || strlen($clave) > self::BORRADOR_MAX_CLAVE) {
                    continue;
                }

                if (is_int($indice) || (is_string($indice) && ctype_digit($indice))) {
                    $indice = (int) $indice;

                    if ($indice >= 0 && $indice <= 99) {
                        $decisiones[$clave] = $indice;
                    }
                }
            }

            $limpio['decisiones_relaciones'] = $decisiones;
        }

        if (isset($borrador['revision']) && is_numeric($borrador['revision']) && $borrador['revision'] > 0) {
            $limpio['revision'] = (int) $borrador['revision'];
        }

        if (strlen(json_encode($limpio)) > self::BORRADOR_MAX_BYTES) {
            return null;
        }

        return $limpio;
    }

    /**
     * Guarda el borrador del modal dentro del payload, sin pisar el resto de las claves.
     * Relee la fila con candado: el job también escribe el payload (limpieza de PDF).
     *
     * Con `revision`: si ya hay guardado un borrador con una revisión mayor, este llegó fuera de
     * orden (un guardado viejo que tardó más que uno nuevo) y se ignora.
     *
     * @param  array  $borrador  Ya saneado con sanear_borrador().
     * @return void
     */
    public function guardar_borrador(array $borrador)
    {
        $this->actualizar_payload(function (array $payload) use ($borrador) {
            $guardada = $payload['borrador']['revision'] ?? null;

            if (isset($borrador['revision']) && !is_null($guardada) && (int) $guardada > (int) $borrador['revision']) {
                return $payload;
            }

            $payload['borrador'] = $borrador;
            $payload['borrador_at'] = now()->toDateTimeString();

            return $payload;
        });
    }

    /**
     * Saca la corrida de la lista de pendientes (importación lanzada o descartada).
     * No borra la fila ni el archivo: eso lo hace la limpieza de 2 días que ya existe.
     *
     * @return void
     */
    public function marcar_finalizada()
    {
        $this->actualizar_payload(function (array $payload) {
            if (empty($payload['finalizada_at'])) {
                $payload['finalizada_at'] = now()->toDateTimeString();
            }

            return $payload;
        });
    }

    /**
     * Marca como finalizadas las corridas del owner que usaron ese archivo. Se llama
     * cuando /import lanza la importación: así la lista se limpia aunque la SPA sea
     * una versión anterior que no avisa nada.
     *
     * @param  int     $owner_id
     * @param  string  $excel_path
     * @return int  Cuántas corridas se marcaron.
     */
    public static function marcar_finalizada_por_archivo($owner_id, $excel_path)
    {
        if (empty($owner_id) || empty($excel_path)) {
            return 0;
        }

        $marcadas = 0;

        $corridas = self::where('user_id', $owner_id)
            ->where('excel_path', $excel_path)
            ->get();

        foreach ($corridas as $corrida) {
            $corrida->marcar_finalizada();
            $marcadas++;
        }

        return $marcadas;
    }

    /**
     * Borrador del modal para el frontend (array vacío si no hay).
     *
     * @return array
     */
    public function borrador_para_frontend()
    {
        $payload = $this->payload ?? [];

        return isset($payload['borrador']) && is_array($payload['borrador'])
            ? $payload['borrador']
            : [];
    }

    /**
     * Modifica el payload con la fila bloqueada, para no perder una escritura concurrente.
     *
     * @param  callable  $cambio  Recibe el payload actual y devuelve el nuevo.
     * @return void
     */
    protected function actualizar_payload(callable $cambio)
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($cambio) {
            $fresca = self::whereKey($this->id)->lockForUpdate()->first();

            if (is_null($fresca)) {
                return;
            }

            $nuevo = $cambio($fresca->payload ?? []);

            $fresca->update(['payload' => $nuevo]);
            $this->payload = $nuevo;
            $this->syncOriginalAttribute('payload');
        });
    }
}
