<?php

namespace App\Http\Controllers\Helpers;

use App\Events\BackgroundProcessUpdated;
use App\Models\BackgroundProcess;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registro único de los procesos en segundo plano que el usuario puede ver
 * (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Cada job largo lo usa en tres momentos: `iniciar()` cuando arranca (o cuando se encola),
 * `avanzar()` / `incrementar()` mientras trabaja, y `completar()` o `fallar()` al cerrar. La
 * fila resultante es lo que muestra la SPA en la píldora de arriba a la derecha, en el modal de
 * procesos y en el detalle.
 *
 * 🔴 Tres reglas que no se negocian, porque esto se mete adentro de la importación de
 * artículos, del recálculo de precios y de la actualización masiva:
 *
 * 1. **Nunca voltea al proceso que lo llama.** Todos los métodos públicos atrapan `\Throwable`,
 *    loguean y devuelven null. El registro es presentación; si falla, el usuario pierde una
 *    barrita, no una importación. Un helper "de progreso" que pueda tirar una excepción dentro
 *    de `ProcessArticleChunk` sería un motivo nuevo de importación fallida.
 * 2. **El broadcast tampoco.** Va envuelto aparte, porque una caída de Pusher (timeout, 502,
 *    límite de payload) es el fallo más probable de todos y no tiene por qué enterarse el job.
 *    Mismo criterio que `InstantBroadcastChannel`: un aviso perdido es aceptable, un job volteado
 *    o reintentado por el aviso no lo es. La SPA además vuelve a pedir el estado al reconectar y
 *    pollea mientras la conexión esté caída, así que un aviso perdido se recupera solo.
 * 3. **Throttle.** `avanzar()` emite como mucho una vez cada SEGUNDOS_ENTRE_BROADCASTS por
 *    proceso, salvo cambio de estado o llegada al 100%. Un loop de 3000 artículos con
 *    setFinalPrice avanza varias veces por segundo; sin techo serían cientos de mensajes por
 *    proceso hacia una cuota de Pusher que comparten los ~40 clientes, y otros tantos repintados
 *    en cada pestaña abierta.
 *
 * `completar()` y `fallar()` son idempotentes: una fila cerrada no se reabre ni se pisa. Es lo
 * que permite llamarlos desde el catch del handle() Y desde el failed() del job sin duplicar
 * (el mismo patrón de `BackgroundJobFailureHandler`).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class BackgroundProcessHelper
{
    /** Segundos mínimos entre dos broadcasts de avance del mismo proceso. */
    const SEGUNDOS_ENTRE_BROADCASTS = 2;

    /**
     * Cada cuántas unidades conviene llamar a avanzar() desde un loop. Es una sugerencia para
     * los llamadores (el helper no la impone): cada llamada es un UPDATE, y a 25 un loop de 3000
     * son 120 escrituras en vez de 3000.
     */
    const CADA_CUANTAS_UNIDADES = 25;

    /**
     * Horas sin novedades después de las cuales un proceso activo se da por muerto (ver
     * cerrar_colgados). El recálculo de precios ya se da por perdido a las 2 h
     * (FinalizeSetFinalPrices::TOPE_HORAS) y una importación grande se mide en decenas de
     * minutos, con un aviso por chunk: tres horas mudo es un worker que murió, no un proceso
     * lento.
     */
    const HORAS_SIN_NOVEDADES_PARA_DARLO_POR_MUERTO = 3;

    /** Techo del payload de Pusher que se acepta acá (el límite real es 10.240 bytes). */
    const BYTES_MAXIMOS_DEL_PAYLOAD = 8000;

    /**
     * Abre un proceso nuevo.
     *
     * @param  int    $user_id  Dueño del comercio (canal y scope).
     * @param  string $tipo     importacion_articulos | recalculo_precios | actualizacion_masiva | ...
     * @param  string $titulo   Lo que lee el usuario.
     * @param  array  $opciones auth_user_id, detalle, total, unidad, etapa, referencia (Model),
     *                          resultado (array), status ('pendiente' | 'en_proceso', default
     *                          'en_proceso').
     * @return \App\Models\BackgroundProcess|null null si el registro falló (nunca tira).
     */
    public static function iniciar($user_id, $tipo, $titulo, array $opciones = [])
    {
        try {
            $total = self::entero_o_null($opciones['total'] ?? null);
            $status = ($opciones['status'] ?? null) === BackgroundProcess::STATUS_PENDIENTE
                ? BackgroundProcess::STATUS_PENDIENTE
                : BackgroundProcess::STATUS_EN_PROCESO;

            $datos = [
                'uuid'           => (string) Str::uuid(),
                'user_id'        => (int) $user_id,
                'auth_user_id'   => self::entero_o_null($opciones['auth_user_id'] ?? null),
                'tipo'           => Str::limit((string) $tipo, 50, ''),
                'titulo'         => Str::limit((string) $titulo, 150, ''),
                'detalle'        => self::texto_o_null($opciones['detalle'] ?? null, 255),
                'status'         => $status,
                'total'          => $total,
                'procesados'     => 0,
                'porcentaje'     => is_null($total) ? null : 0,
                'etapa'          => self::texto_o_null($opciones['etapa'] ?? null, 120),
                'unidad'         => self::texto_o_null($opciones['unidad'] ?? null, 30),
                'resultado_json' => self::codificar_resultado($opciones['resultado'] ?? []),
                'started_at'     => Carbon::now(),
            ];

            $referencia = $opciones['referencia'] ?? null;

            if ($referencia instanceof Model) {
                $datos['referencia_type'] = get_class($referencia);
                $datos['referencia_id']   = $referencia->getKey();
            }

            $proceso = BackgroundProcess::create($datos);

            self::emitir($proceso, true);

            return $proceso;
        } catch (\Throwable $e) {
            self::loguear('iniciar', $e, ['tipo' => $tipo, 'user_id' => $user_id]);

            return null;
        }
    }

    /**
     * Escribe el avance con un valor ABSOLUTO de unidades procesadas.
     *
     * Para un loop en un solo proceso (masiva, eliminación) o cuando el llamador ya tiene el
     * contador fresco de su propia tabla (los chunks de la importación leen processed_chunks
     * después de su incremento atómico). Si dos workers en paralelo pueden pisarse el valor,
     * usar `incrementar()`.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso   La fila o su id.
     * @param  int|null $procesados  Unidades hechas hasta ahora (null = no tocar).
     * @param  array    $opciones    total, etapa, detalle, resultado (se MEZCLA con el que
     *                               había), forzar_broadcast (bool).
     * @return \App\Models\BackgroundProcess|null
     */
    public static function avanzar($proceso, $procesados = null, array $opciones = [])
    {
        try {
            $proceso = self::resolver($proceso);

            if (is_null($proceso) || $proceso->esta_terminado()) {
                return $proceso;
            }

            $cambios = self::cambios_de_avance($proceso, $opciones);

            if (!is_null($procesados)) {
                $cambios['procesados'] = max(0, (int) $procesados);
            }

            return self::aplicar_avance($proceso, $cambios, !empty($opciones['forzar_broadcast']));
        } catch (\Throwable $e) {
            self::loguear('avanzar', $e, ['proceso' => self::id_de($proceso)]);

            return null;
        }
    }

    /**
     * Suma unidades de forma ATÓMICA (`procesados = procesados + n`), para chunks que corren en
     * paralelo en varios workers y no pueden leer-y-escribir el contador sin pisarse.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso
     * @param  int   $cantidad
     * @param  array $opciones  Las mismas que avanzar().
     * @return \App\Models\BackgroundProcess|null
     */
    public static function incrementar($proceso, $cantidad = 1, array $opciones = [])
    {
        try {
            $proceso = self::resolver($proceso);

            if (is_null($proceso) || $proceso->esta_terminado()) {
                return $proceso;
            }

            $cantidad = max(0, (int) $cantidad);

            if ($cantidad > 0) {
                DB::table('background_processes')
                    ->where('id', $proceso->id)
                    ->update(['procesados' => DB::raw('procesados + ' . $cantidad)]);

                // El valor real después del incremento, no el que tenía este proceso en memoria.
                $proceso->procesados = (int) DB::table('background_processes')
                    ->where('id', $proceso->id)
                    ->value('procesados');
            }

            $cambios = self::cambios_de_avance($proceso, $opciones);

            return self::aplicar_avance($proceso, $cambios, !empty($opciones['forzar_broadcast']));
        } catch (\Throwable $e) {
            self::loguear('incrementar', $e, ['proceso' => self::id_de($proceso)]);

            return null;
        }
    }

    /**
     * Cambia solo el texto de etapa ("Analizando el archivo"), sin tocar contadores.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso
     * @param  string $etapa
     * @param  int|null $porcentaje  Para procesos por hitos (análisis de Excel: 5, 40, 100...).
     * @return \App\Models\BackgroundProcess|null
     */
    public static function etapa($proceso, $etapa, $porcentaje = null)
    {
        $opciones = ['etapa' => $etapa, 'forzar_broadcast' => true];

        if (!is_null($porcentaje)) {
            // Un hito se expresa como "porcentaje sobre 100" para no inventar un total.
            $opciones['total'] = 100;

            return self::avanzar($proceso, min(100, max(0, (int) $porcentaje)), $opciones);
        }

        return self::avanzar($proceso, null, $opciones);
    }

    /**
     * Cierra el proceso como terminado. Idempotente: una fila ya cerrada no se toca.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso
     * @param  array       $resultado  Números finales (se mezclan con los parciales).
     * @param  string|null $etapa      Texto final ("Terminado" si no se manda nada).
     * @return \App\Models\BackgroundProcess|null
     */
    public static function completar($proceso, array $resultado = [], $etapa = null)
    {
        try {
            $proceso = self::resolver($proceso);

            if (is_null($proceso) || $proceso->esta_terminado()) {
                return $proceso;
            }

            $procesados = is_null($proceso->total)
                ? (int) $proceso->procesados
                : (int) $proceso->total;

            $proceso->fill([
                'status'         => BackgroundProcess::STATUS_COMPLETADO,
                'procesados'     => $procesados,
                'porcentaje'     => 100,
                'etapa'          => self::texto_o_null(is_null($etapa) ? 'Terminado' : $etapa, 120),
                'resultado_json' => self::codificar_resultado(array_merge($proceso->resultado(), $resultado)),
                'finished_at'    => Carbon::now(),
            ]);
            $proceso->save();

            self::emitir($proceso, true);

            return $proceso;
        } catch (\Throwable $e) {
            self::loguear('completar', $e, ['proceso' => self::id_de($proceso)]);

            return null;
        }
    }

    /**
     * Cierra el proceso como fallido. Idempotente, igual que completar(): se puede llamar desde
     * el catch del handle() y desde el failed() del job sin que el segundo pise al primero.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso
     * @param  string $mensaje    Lo que lee el usuario.
     * @param  array  $resultado  Números parciales, si los hay.
     * @return \App\Models\BackgroundProcess|null
     */
    public static function fallar($proceso, $mensaje, array $resultado = [])
    {
        try {
            $proceso = self::resolver($proceso);

            if (is_null($proceso) || $proceso->esta_terminado()) {
                return $proceso;
            }

            $proceso->fill([
                'status'         => BackgroundProcess::STATUS_FALLO,
                'etapa'          => 'Falló',
                'error_message'  => Str::limit(trim((string) $mensaje), 2000, '…'),
                'resultado_json' => self::codificar_resultado(array_merge($proceso->resultado(), $resultado)),
                'finished_at'    => Carbon::now(),
            ]);
            $proceso->save();

            self::emitir($proceso, true);

            return $proceso;
        } catch (\Throwable $e) {
            self::loguear('fallar', $e, ['proceso' => self::id_de($proceso)]);

            return null;
        }
    }

    /**
     * Busca el proceso ABIERTO que apunta a un registro propio (ImportStatus, PriceUpdateRun,
     * MasiveUpdate...). Sirve para que los jobs que ya viajan con su modelo no tengan que
     * arrastrar además el id de esta fila.
     *
     * Devuelve el más nuevo: si por alguna razón hubiera dos, el viejo ya no le importa a nadie.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null $modelo
     * @param  bool $solo_activos  false para encontrarlo también cerrado.
     * @return \App\Models\BackgroundProcess|null
     */
    public static function por_referencia($modelo, $solo_activos = true)
    {
        try {
            if (!($modelo instanceof Model) || is_null($modelo->getKey())) {
                return null;
            }

            $query = BackgroundProcess::where('referencia_type', get_class($modelo))
                ->where('referencia_id', $modelo->getKey())
                ->orderBy('id', 'DESC');

            if ($solo_activos) {
                $query->activos();
            }

            return $query->first();
        } catch (\Throwable $e) {
            self::loguear('por_referencia', $e, ['modelo' => get_class($modelo)]);

            return null;
        }
    }

    /**
     * El proceso ABIERTO más nuevo de un tipo para un comercio.
     *
     * Es para el `failed()` de los jobs que no tienen modelo propio al que referenciar (borrado
     * masivo, imágenes y descripciones IA, reporte de inventario): `failed()` corre sobre una
     * instancia deserializada del payload original, así que nada de lo que `handle()` guardó en
     * memoria existe ahí. Si el proceso ya se cerró (bien o mal), no lo devuelve, y `fallar()`
     * sobre null no hace nada — que es exactamente lo que se quiere en ese caso.
     *
     * @param  int    $user_id
     * @param  string $tipo
     * @return \App\Models\BackgroundProcess|null
     */
    public static function ultimo_activo($user_id, $tipo)
    {
        try {
            return BackgroundProcess::where('user_id', (int) $user_id)
                ->where('tipo', (string) $tipo)
                ->activos()
                ->orderBy('id', 'DESC')
                ->first();
        } catch (\Throwable $e) {
            self::loguear('ultimo_activo', $e, ['user_id' => $user_id, 'tipo' => $tipo]);

            return null;
        }
    }

    /**
     * Payload que viaja por Pusher y que devuelven los endpoints. Es el contrato con la SPA:
     * las claves son las columnas, más `resultado` decodificado.
     *
     * El tamaño está acotado: `resultado` se recorta a escalares y, si aun así el JSON
     * completo supera BYTES_MAXIMOS_DEL_PAYLOAD, se manda sin `resultado` (la SPA lo pide por
     * el endpoint de detalle). Es lo que evita el "exceeds the allowed maximum (10240 bytes)"
     * que ya tumbó el aviso de imágenes automáticas el 25/8/2026.
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @return array
     */
    public static function payload(BackgroundProcess $proceso)
    {
        $payload = [
            'id'              => (int) $proceso->id,
            'uuid'            => $proceso->uuid,
            'user_id'         => (int) $proceso->user_id,
            'auth_user_id'    => is_null($proceso->auth_user_id) ? null : (int) $proceso->auth_user_id,
            'tipo'            => $proceso->tipo,
            'titulo'          => $proceso->titulo,
            'detalle'         => $proceso->detalle,
            'status'          => $proceso->status,
            'total'           => is_null($proceso->total) ? null : (int) $proceso->total,
            'procesados'      => (int) $proceso->procesados,
            'porcentaje'      => is_null($proceso->porcentaje) ? null : (int) $proceso->porcentaje,
            'etapa'           => $proceso->etapa,
            'unidad'          => $proceso->unidad,
            'referencia_type' => $proceso->referencia_type,
            'referencia_id'   => is_null($proceso->referencia_id) ? null : (int) $proceso->referencia_id,
            'error_message'   => is_null($proceso->error_message) ? null : Str::limit($proceso->error_message, 300, '…'),
            'started_at'      => self::fecha($proceso->started_at),
            'finished_at'     => self::fecha($proceso->finished_at),
            'updated_at'      => self::fecha($proceso->updated_at),
            'visto_at'        => self::fecha($proceso->visto_at),
            'resultado'       => self::solo_escalares($proceso->resultado()),
        ];

        if (strlen(json_encode($payload)) > self::BYTES_MAXIMOS_DEL_PAYLOAD) {
            $payload['resultado'] = [];
            $payload['resultado_recortado'] = true;
        }

        return $payload;
    }

    /**
     * Da por muertos los procesos del comercio que llevan demasiado sin novedades.
     *
     * Se llama desde el listado (perezoso): un job que murió sin pasar por failed() dejaría la
     * fila en_proceso para siempre, y la píldora diría "1 proceso en segundo plano" hasta el fin
     * de los tiempos. Marca `fallo` con un mensaje que dice lo que se sabe, nada más.
     *
     * Solo mira `updated_at`, que cada avance renueva. Un proceso legítimamente lento igual
     * avisa cada chunk, así que no queda tres horas mudo.
     *
     * @param  int $user_id
     * @return void
     */
    public static function cerrar_colgados($user_id)
    {
        try {
            $limite = Carbon::now()->subHours(self::HORAS_SIN_NOVEDADES_PARA_DARLO_POR_MUERTO);

            $colgados = BackgroundProcess::where('user_id', (int) $user_id)
                ->activos()
                ->where('updated_at', '<', $limite)
                ->get();

            foreach ($colgados as $proceso) {
                $mensaje = $proceso->status === BackgroundProcess::STATUS_PENDIENTE
                    ? 'El proceso nunca llegó a arrancar (más de ' . self::HORAS_SIN_NOVEDADES_PARA_DARLO_POR_MUERTO . ' horas en espera).'
                    : 'El proceso dejó de reportar avance hace más de ' . self::HORAS_SIN_NOVEDADES_PARA_DARLO_POR_MUERTO . ' horas y se dio por interrumpido.';

                self::fallar($proceso, $mensaje);
            }
        } catch (\Throwable $e) {
            self::loguear('cerrar_colgados', $e, ['user_id' => $user_id]);
        }
    }

    /**
     * Carga el registro propio del flujo, listo para el detalle de la SPA, según el tipo de
     * referencia. Devuelve null si no hay referencia o si el modelo ya no existe.
     *
     * Cada rama elige explícitamente qué devolver: las tablas propias tienen columnas que el
     * detalle no necesita (stats_json entero, criteria_json de la masiva) y no hay motivo para
     * mandarlas.
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @return array|null
     */
    public static function referencia_para_detalle(BackgroundProcess $proceso)
    {
        try {
            if (is_null($proceso->referencia_type) || is_null($proceso->referencia_id)) {
                return null;
            }

            $clase = $proceso->referencia_type;

            if (!class_exists($clase)) {
                return null;
            }

            if ($clase === \App\Models\ImportStatus::class) {
                $import_status = \App\Models\ImportStatus::where('id', $proceso->referencia_id)
                    ->withAll()
                    ->first();

                if (is_null($import_status)) {
                    return null;
                }

                $import_history = \App\Models\ImportHistory::select(
                        'id', 'status', 'excel_url', 'error_message', 'operacion_a_realizar',
                        'total_chunks', 'processed_chunks', 'terminado_at', 'created_at'
                    )
                    ->where('import_status_id', $import_status->id)
                    ->orderBy('id', 'DESC')
                    ->first();

                return [
                    'import_status'  => $import_status,
                    'import_history' => $import_history,
                ];
            }

            if ($clase === \App\Models\PriceUpdateRun::class) {
                $run = \App\Models\PriceUpdateRun::find($proceso->referencia_id);

                if (is_null($run)) {
                    return null;
                }

                return [
                    'price_update_run' => [
                        'id'               => $run->id,
                        'origen'           => $run->origen,
                        'origen_detalle'   => $run->origen_detalle,
                        'origen_texto'     => $run->origen_texto,
                        'status'           => $run->status,
                        'total_chunks'     => (int) $run->total_chunks,
                        'processed_chunks' => (int) $run->processed_chunks,
                        'articles_updated' => (int) $run->articles_updated,
                        'error_detalle'    => $run->error_detalle,
                        'started_at'       => self::fecha($run->started_at),
                        'finished_at'      => self::fecha($run->finished_at),
                    ],
                ];
            }

            if ($clase === \App\Models\MasiveUpdate::class) {
                $masiva = \App\Models\MasiveUpdate::find($proceso->referencia_id);

                if (is_null($masiva)) {
                    return null;
                }

                return [
                    'masive_update' => [
                        'id'             => $masiva->id,
                        'model_name'     => $masiva->model_name,
                        'action'         => $masiva->action,
                        'status'         => $masiva->status,
                        'from_filter'    => (bool) $masiva->from_filter,
                        'affected_count' => (int) $masiva->affected_count,
                        'changes_count'  => (int) $masiva->changes_count,
                        'error_message'  => $masiva->error_message,
                        'created_at'     => self::fecha($masiva->created_at),
                    ],
                ];
            }

            // Cualquier otro modelo: se devuelve tal cual (son tablas chicas de estado).
            $modelo = $clase::find($proceso->referencia_id);

            return is_null($modelo) ? null : ['modelo' => $modelo];
        } catch (\Throwable $e) {
            self::loguear('referencia_para_detalle', $e, ['proceso' => $proceso->id]);

            return null;
        }
    }

    /* ----------------------------------------------------------------------------------------
     * Internos
     * -------------------------------------------------------------------------------------- */

    /**
     * Arma los cambios comunes a avanzar() e incrementar() a partir de las opciones.
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @param  array $opciones
     * @return array
     */
    protected static function cambios_de_avance(BackgroundProcess $proceso, array $opciones)
    {
        $cambios = [];

        if (array_key_exists('total', $opciones)) {
            $cambios['total'] = self::entero_o_null($opciones['total']);
        }

        if (array_key_exists('etapa', $opciones)) {
            $cambios['etapa'] = self::texto_o_null($opciones['etapa'], 120);
        }

        if (array_key_exists('detalle', $opciones)) {
            $cambios['detalle'] = self::texto_o_null($opciones['detalle'], 255);
        }

        if (!empty($opciones['resultado']) && is_array($opciones['resultado'])) {
            $cambios['resultado_json'] = self::codificar_resultado(
                array_merge($proceso->resultado(), $opciones['resultado'])
            );
        }

        return $cambios;
    }

    /**
     * Persiste el avance, recalcula el porcentaje y emite si corresponde.
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @param  array $cambios
     * @param  bool  $forzar_broadcast
     * @return \App\Models\BackgroundProcess
     */
    protected static function aplicar_avance(BackgroundProcess $proceso, array $cambios, $forzar_broadcast)
    {
        $status_anterior = $proceso->status;

        // El primer avance saca al proceso de "pendiente": ya hay alguien trabajando en él. Y
        // recién ahí arranca el reloj que ve el usuario: `started_at` de un encolado era la hora
        // en que se pidió, no la hora en que empezó a correr (created_at guarda la primera).
        if ($proceso->status === BackgroundProcess::STATUS_PENDIENTE) {
            $cambios['status']     = BackgroundProcess::STATUS_EN_PROCESO;
            $cambios['started_at'] = Carbon::now();
        }

        $proceso->fill($cambios);

        $porcentaje_anterior = $proceso->getOriginal('porcentaje');
        $proceso->porcentaje = self::calcular_porcentaje($proceso->total, $proceso->procesados);

        // Aunque no haya cambiado nada, updated_at se renueva: es la señal de vida que mira
        // cerrar_colgados(). touch() ya persiste los atributos sucios, no hace falta un save().
        $proceso->touch();

        $llego_al_final = !is_null($proceso->total) && (int) $proceso->procesados >= (int) $proceso->total;

        $forzar = $forzar_broadcast
            || $proceso->status !== $status_anterior
            || ($llego_al_final && (int) $porcentaje_anterior !== 100);

        self::emitir($proceso, $forzar);

        return $proceso;
    }

    /**
     * Emite el evento por Pusher, con throttle salvo que se fuerce. Nunca tira.
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @param  bool $forzar
     * @return void
     */
    protected static function emitir(BackgroundProcess $proceso, $forzar)
    {
        try {
            if (!$forzar && !self::toca_emitir($proceso)) {
                return;
            }

            // Se escribe ANTES de emitir: si Pusher tarda, otro worker del mismo proceso no
            // emite encima en el mismo segundo.
            DB::table('background_processes')
                ->where('id', $proceso->id)
                ->update(['broadcast_at' => Carbon::now()]);
            $proceso->broadcast_at = Carbon::now();

            broadcast(new BackgroundProcessUpdated(self::payload($proceso), $proceso->user_id));
        } catch (\Throwable $e) {
            // Un aviso perdido es aceptable; el job que lo emite no se entera. La SPA lo
            // recupera al reconectar o por el polling de respaldo.
            Log::warning('BackgroundProcessHelper: falló el broadcast de BackgroundProcessUpdated.', [
                'proceso' => $proceso->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * ¿Pasó el tiempo mínimo desde el último broadcast?
     *
     * @param  \App\Models\BackgroundProcess $proceso
     * @return bool
     */
    protected static function toca_emitir(BackgroundProcess $proceso)
    {
        if (is_null($proceso->broadcast_at)) {
            return true;
        }

        return Carbon::parse($proceso->broadcast_at)
            ->addSeconds(self::SEGUNDOS_ENTRE_BROADCASTS)
            ->lte(Carbon::now());
    }

    /**
     * @param  int|null $total
     * @param  int      $procesados
     * @return int|null
     */
    protected static function calcular_porcentaje($total, $procesados)
    {
        if (is_null($total)) {
            return null;
        }

        $total = (int) $total;

        if ($total <= 0) {
            return null;
        }

        return (int) min(100, floor(max(0, (int) $procesados) * 100 / $total));
    }

    /**
     * Acepta la fila, su id o null y devuelve la fila fresca (o null).
     *
     * Se relee siempre: el job puede tener una copia vieja (otro worker pudo cerrarla), y las
     * decisiones de idempotencia se toman sobre lo que hay en la base.
     *
     * @param  \App\Models\BackgroundProcess|int|null $proceso
     * @return \App\Models\BackgroundProcess|null
     */
    protected static function resolver($proceso)
    {
        if ($proceso instanceof BackgroundProcess) {
            return BackgroundProcess::find($proceso->id);
        }

        if (is_numeric($proceso) && (int) $proceso > 0) {
            return BackgroundProcess::find((int) $proceso);
        }

        return null;
    }

    /**
     * @param  mixed $proceso
     * @return int|null
     */
    protected static function id_de($proceso)
    {
        if ($proceso instanceof BackgroundProcess) {
            return $proceso->id;
        }

        return is_numeric($proceso) ? (int) $proceso : null;
    }

    /**
     * @param  array $resultado
     * @return string|null
     */
    protected static function codificar_resultado(array $resultado)
    {
        if (count($resultado) === 0) {
            return null;
        }

        return json_encode($resultado, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deja solo los valores escalares (y null) de un array de resultado, para el payload.
     *
     * @param  array $resultado
     * @return array
     */
    protected static function solo_escalares(array $resultado)
    {
        $limpio = [];

        foreach ($resultado as $clave => $valor) {
            if (is_scalar($valor) || is_null($valor)) {
                $limpio[$clave] = $valor;
            }
        }

        return $limpio;
    }

    /**
     * @param  mixed $valor
     * @return int|null
     */
    protected static function entero_o_null($valor)
    {
        if (is_null($valor) || $valor === '') {
            return null;
        }

        return max(0, (int) $valor);
    }

    /**
     * @param  mixed $valor
     * @param  int   $largo
     * @return string|null
     */
    protected static function texto_o_null($valor, $largo)
    {
        if (is_null($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : Str::limit($texto, $largo, '');
    }

    /**
     * @param  mixed $fecha
     * @return string|null  ISO 8601 con zona, o null.
     */
    protected static function fecha($fecha)
    {
        if (is_null($fecha)) {
            return null;
        }

        return Carbon::parse($fecha)->toIso8601String();
    }

    /**
     * @param  string     $metodo
     * @param  \Throwable $e
     * @param  array      $contexto
     * @return void
     */
    protected static function loguear($metodo, \Throwable $e, array $contexto = [])
    {
        Log::error('BackgroundProcessHelper::' . $metodo . ' falló (el proceso sigue): ' . $e->getMessage(), array_merge($contexto, [
            'archivo' => $e->getFile() . ':' . $e->getLine(),
        ]));
    }
}
