<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiTareaDelAsistente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las tareas propias del asistente personal (misión agente-tareas-programadas, 21/11/2026): alta, cambio de
 * hora o de día («hoy no, el viernes»), listado y cancelación, más los horarios que prefiere el dueño. Es la
 * lógica de negocio de las cinco herramientas (HerramientasDeTareasDelAsistente); quien las despierta a su hora
 * es DespertadorDeTareasIaHelper y el cálculo de fechas, ProximaEjecucionIaHelper.
 *
 * 🔴 TODO ESTÁ ACOTADO POR EL DUEÑO. Cada consulta lleva `owner_id`: hay bases compartidas entre comercios y un
 * `tarea_id` de otro negocio tiene que dar exactamente el mismo mensaje que uno inexistente (sin delatar que existe).
 *
 * 🔴 LÍMITES (ajustables por constante): 10 tareas activas por negocio, una vez entre +5 minutos y +90 días,
 * recurrencia como mucho diaria, instrucción de hasta 1000 caracteres. Los rechazos son «recuperables»: dicen
 * qué corregir y, si hace falta, la hora actual, para que el modelo se lo pida bien al dueño.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class TareasDelAsistenteIaHelper
{
    /** Cuántas tareas activas puede tener un negocio. */
    const MAX_ACTIVAS = 10;

    /** Largo máximo de la instrucción y del título. */
    const LARGO_INSTRUCCION = 1000;

    const LARGO_TITULO = 120;

    /** Una vez: como mínimo dentro de tantos minutos (menos es «ya»: no alcanza ni a avisarle) y como mucho dentro de tantos días. */
    const MINUTOS_MINIMOS = 5;

    const DIAS_MAXIMOS = 90;

    /** Estado real para el modelo: tope de caracteres del agregado, y la regla fija que lo cierra (no se recorta). */
    const ESTADO_LARGO_MAXIMO = 2000;

    const ESTADO_REGLA = 'No digas que programaste, cambiaste o cancelaste algo que esta lista no muestra así, salvo que llames a la herramienta en este turno. Si antes en esta conversación dijiste otra cosa, vale la lista: corregilo.';

    /** Cuando el modelo no encuentra la tarea: el mismo texto para «es de otro negocio» y «no existe». */
    const NO_ENCONTRADA = 'No encontré esa tarea; usá consultar_tareas_del_asistente para ver las que hay.';

    /** @var bool|null  Sólo se guarda el «true»: si las tablas y la columna ya existen en esta base (por proceso). */
    protected static $hay_tablas = null;

    /**
     * true si esta base tiene las dos tablas, las columnas de la entrega (enmienda 1) y la columna de horarios
     * (una instalación a medio actualizar no ofrece las herramientas ni despierta nada, en silencio).
     *
     * 🔴 SÓLO SE CACHEA EL «TRUE» (enmienda 1, E4). Un worker vivo que arrancó antes de correr las migraciones
     * preguntó una vez, obtuvo «false» y lo habría repetido hasta reiniciarse: las tareas no despertaban nunca
     * aunque la base ya estuviera completa. Con el «false» sin cachear se vuelve a preguntar (tres consultas
     * al esquema, sólo mientras la instalación está incompleta) y apenas existen las tablas se ven.
     *
     * @return bool
     */
    public static function hay_tablas(): bool
    {
        if (self::$hay_tablas === true) {

            return true;
        }

        try {

            $hay = Schema::hasTable('ai_tareas_del_asistente')
                && Schema::hasTable('ai_tareas_del_asistente_ejecuciones')
                && Schema::hasColumn('ai_tareas_del_asistente_ejecuciones', 'mostrada_at')
                && Schema::hasColumn('users', 'agente_horarios');

        } catch (\Throwable $e) {

            return false;
        }

        if ($hay) {

            self::$hay_tablas = true;
        }

        return $hay;
    }

    /**
     * «Ahora» en la zona de la app. Siempre con zona explícita: con el reloj fijo de un test en UTC (o un
     * servidor en otra zona) `Carbon::now()` sin zona devolvería la hora de esa zona.
     *
     * @return \Carbon\Carbon
     */
    public static function ahora(): Carbon
    {
        return Carbon::now(ProximaEjecucionIaHelper::ZONA);
    }

    /* ─────────────────────────────── horarios ─────────────────────────────── */

    /**
     * Los horarios efectivos del dueño (los de fábrica pisados por los que guardó).
     *
     * @param  \App\Models\User  $dueno
     * @return array
     */
    public static function horarios_del_dueno(User $dueno): array
    {
        $guardados = null;

        if (self::hay_tablas()) {

            $json = DB::table('users')->where('id', (int) $dueno->id)->value('agente_horarios');

            $guardados = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        }

        return ProximaEjecucionIaHelper::horarios($guardados);
    }

    /**
     * guardar_horarios_del_dueno: guarda a qué hora es cada franja, la hora preferida y los días sin mensajes.
     * Lo que no viene queda como estaba. Las tareas ya programadas no se mueven.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $input
     * @return array  Datos del resultado, o ['error' => motivo].
     */
    public static function guardar_horarios(User $dueno, array $input): array
    {
        $actuales = self::horarios_del_dueno($dueno);
        $nuevos = $actuales;
        $cambios = 0;

        foreach (array_keys(ProximaEjecucionIaHelper::HORARIOS_DE_FABRICA) as $clave) {

            if (!isset($input[$clave]) || trim((string) $input[$clave]) === '') {

                continue;
            }

            if (!ProximaEjecucionIaHelper::es_hora((string) $input[$clave])) {

                return ['error' => 'La hora de "' . $clave . '" tiene que ser HH:MM (por ejemplo 18:30).'];
            }

            $nuevos[$clave] = ProximaEjecucionIaHelper::normalizar_hora((string) $input[$clave]);
            $cambios++;
        }

        if (isset($input['dias_sin_mensajes']) && is_array($input['dias_sin_mensajes'])) {

            $nuevos['dias_sin_mensajes'] = ProximaEjecucionIaHelper::dias_validos($input['dias_sin_mensajes']);
            $cambios++;
        }

        if ($cambios === 0) {

            return ['error' => 'No me dijiste ningún horario para guardar: manana, mediodia, tarde, noche, hora_preferida o dias_sin_mensajes.'];
        }

        DB::table('users')->where('id', (int) $dueno->id)->update(['agente_horarios' => json_encode($nuevos)]);

        return [
            'ok'       => true,
            'horarios' => $nuevos,
            'nota'     => 'Quedaron guardados. Las tareas que ya están programadas no se mueven: valen para las nuevas (o para las que reprogramen).',
        ];
    }

    /* ─────────────────────────────── alta y cambios ─────────────────────────────── */

    /**
     * programar_tarea_del_asistente: crea la tarea.
     *
     * @param  \App\Models\User  $dueno
     * @param  int|null  $conversacion_id  La conversación donde se pidió.
     * @param  array  $input  Lo que mandó el modelo.
     * @return array  Datos del resultado (tarea_id, cuando, proxima, aviso), o ['error' => motivo].
     */
    public static function programar(User $dueno, $conversacion_id, array $input): array
    {
        $ahora = self::ahora();

        $armada = self::armar($dueno, [
            'titulo'       => '',
            'instruccion'  => '',
            'area'         => 'general',
            'recurrencia'  => 'una_vez',
            'dias'         => null,
            'hora'         => null,
            'franja'       => null,
            'fecha_inicio' => null,
            'fecha_fin'    => null,
        ], $input, $ahora);

        if (isset($armada['error'])) {

            return $armada;
        }

        if (self::activas($dueno) >= self::MAX_ACTIVAS) {

            return ['error' => 'Ya hay ' . self::MAX_ACTIVAS . ' tareas activas, que es el máximo. Cancelá una con cancelar_tarea_del_asistente (o pausá la que ya no haga falta) y volvé a programar esta.'];
        }

        $tarea = AiTareaDelAsistente::create(array_merge($armada['campos'], [
            'owner_id'             => (int) $dueno->id,
            'auth_user_id'         => (int) $dueno->id,
            'ai_conversation_id'   => is_null($conversacion_id) ? null : (int) $conversacion_id,
            'zona_horaria'         => ProximaEjecucionIaHelper::ZONA,
            'proxima_ejecucion_at' => $armada['proxima']->format('Y-m-d H:i:s'),
            'estado'               => AiTareaDelAsistente::ESTADO_ACTIVA,
            'ejecuciones'          => 0,
            'fallos_seguidos'      => 0,
        ]));

        return self::resultado($dueno, $tarea->fresh());
    }

    /**
     * reprogramar_tarea_del_asistente: posponer, cambiar la hora o la instrucción. Reactiva una pausada.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $input  `tarea_id` y lo que cambia.
     * @return array
     */
    public static function reprogramar(User $dueno, array $input): array
    {
        $tarea = self::buscar($dueno, isset($input['tarea_id']) ? $input['tarea_id'] : null, [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA]);

        if (is_null($tarea)) {

            return ['error' => self::NO_ENCONTRADA];
        }

        $ahora = self::ahora();

        $armada = self::armar($dueno, [
            'titulo'       => (string) $tarea->titulo,
            'instruccion'  => (string) $tarea->instruccion,
            'area'         => (string) $tarea->area,
            'recurrencia'  => (string) $tarea->recurrencia,
            'dias'         => $tarea->dias,
            'hora'         => $tarea->hora,
            'franja'       => $tarea->franja,
            'fecha_inicio' => is_null($tarea->fecha_inicio) ? null : $tarea->fecha_inicio->format('Y-m-d'),
            'fecha_fin'    => is_null($tarea->fecha_fin) ? null : $tarea->fecha_fin->format('Y-m-d'),
        ], $input, $ahora);

        if (isset($armada['error'])) {

            return $armada;
        }

        if ($tarea->estado !== AiTareaDelAsistente::ESTADO_ACTIVA && self::activas($dueno) >= self::MAX_ACTIVAS) {

            return ['error' => 'Ya hay ' . self::MAX_ACTIVAS . ' tareas activas, que es el máximo: no puedo reactivar esta. Cancelá otra primero.'];
        }

        $tarea->fill($armada['campos']);
        $tarea->proxima_ejecucion_at = $armada['proxima']->format('Y-m-d H:i:s');
        $tarea->estado = AiTareaDelAsistente::ESTADO_ACTIVA;
        $tarea->fallos_seguidos = 0;
        $tarea->save();

        return self::resultado($dueno, $tarea->fresh());
    }

    /**
     * cancelar_tarea_del_asistente.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $input
     * @return array
     */
    public static function cancelar(User $dueno, array $input): array
    {
        $tarea = self::buscar($dueno, isset($input['tarea_id']) ? $input['tarea_id'] : null, [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA]);

        if (is_null($tarea)) {

            return ['error' => self::NO_ENCONTRADA];
        }

        $tarea->estado = AiTareaDelAsistente::ESTADO_CANCELADA;
        $tarea->proxima_ejecucion_at = null;
        $tarea->save();

        return ['ok' => true, 'tarea_id' => (int) $tarea->id, 'titulo' => (string) $tarea->titulo, 'nota' => 'Cancelada: ya no se va a despertar.'];
    }

    /**
     * consultar_tareas_del_asistente: las activas y las pausadas del dueño, por próxima ejecución.
     *
     * @param  \App\Models\User  $dueno
     * @return array{ahora: string, tareas: array<int,array>}
     */
    public static function listar(User $dueno): array
    {
        $tareas = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->whereIn('estado', [AiTareaDelAsistente::ESTADO_ACTIVA, AiTareaDelAsistente::ESTADO_PAUSADA])
            ->orderByRaw('proxima_ejecucion_at IS NULL')
            ->orderBy('proxima_ejecucion_at')
            ->orderBy('id')
            ->get();

        $items = [];

        foreach ($tareas as $tarea) {

            $items[] = [
                'tarea_id'         => (int) $tarea->id,
                'titulo'           => (string) $tarea->titulo,
                'cuando'           => self::cuando($tarea),
                'proxima'          => is_null($tarea->proxima_ejecucion_at) ? null : self::momento_en_palabras($tarea->proxima_ejecucion_at),
                'estado'           => (string) $tarea->estado,
                'ultimo_resultado' => $tarea->ultimo_resultado,
            ];
        }

        $ahora = self::ahora();

        return [
            'ahora'  => $ahora->format('H:i') . ' del ' . FormatoIaHelper::fecha_con_dia($ahora),
            'tareas' => $items,
        ];
    }

    /**
     * El estado REAL de las tareas del dueño, en texto plano, para el bloque de tareas del system de cada turno
     * (prevención del «la cancelé» dicho de memoria sin haber llamado a la herramienta).
     *
     * 🔴 Lo que dice la base ahora: activas y pausadas (listar(): mismo «cuando» y «próxima» que la herramienta de
     * consulta) y hasta 5 canceladas o terminadas de las últimas 24 h, las más nuevas primero; sin tareas, lo dice.
     * Siempre cierra con la regla. Dos consultas como mucho. Si algo falla devuelve '' y el turno sigue como antes.
     * 🔴 NUNCA se descarta una viva en silencio. Tope de 2.000 caracteres; se recorta en este orden: títulos hasta 12
     * caracteres, recientes (la más vieja primero), vivas en formato compacto y, si aun así no entran, se muestran las
     * que entran y una línea dice cuántas faltan y que hay que consultar la herramienta.
     *
     * @param  \App\Models\User  $dueno
     * @return string  Texto para el modelo, o '' si no se pudo leer.
     */
    public static function estado_para_el_modelo(User $dueno): string
    {
        try {

            if (!self::hay_tablas()) {

                return '';
            }

            $vivas = self::listar($dueno)['tareas'];

            $recientes = AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
                ->whereIn('estado', [AiTareaDelAsistente::ESTADO_CANCELADA, AiTareaDelAsistente::ESTADO_TERMINADA])
                ->where('updated_at', '>=', Carbon::now()->subHours(24))
                ->orderBy('updated_at', 'desc')->orderBy('id', 'desc')->limit(5)->get()->all();

            /* Recorte: largo de títulos, si las vivas van compactas y cuántas vivas se muestran (el resto se cuenta). */
            $largo_titulo = self::LARGO_TITULO;
            $compacto = false;
            $mostradas = count($vivas);

            while (true) {

                $lineas = [];

                foreach (array_slice($vivas, 0, $mostradas) as $viva) {

                    /* Las de una sola vez ya dicen día y hora en «cuando»; la próxima sólo suma en las recurrentes. */
                    $unica = strncmp($viva['cuando'], 'el ', 3) === 0 || strncmp($viva['cuando'], 'a las ', 6) === 0;
                    $proxima = !is_null($viva['proxima']) && ($compacto || !$unica) ? 'próxima ' . $viva['proxima'] : '';

                    $lineas[] = '- [id ' . $viva['tarea_id'] . '] «' . mb_strimwidth($viva['titulo'], 0, $largo_titulo, '…') . '»'
                        . ($compacto ? ($proxima !== '' ? ': ' . $proxima : '') : ': ' . $viva['cuando'] . ($proxima !== '' ? ', ' . $proxima : ''))
                        . ' (' . $viva['estado'] . ')';
                }

                if ($mostradas < count($vivas)) {

                    $faltan = count($vivas) - $mostradas;
                    $lineas[] = '… y ' . $faltan . ' tarea' . ($faltan > 1 ? 's' : '') . ' más que no entran acá: llamá a consultar_tareas_del_asistente antes de afirmar algo sobre ellas.';
                }

                foreach ($recientes as $reciente) {

                    $minutos = max(1, (int) $reciente->updated_at->diffInMinutes(Carbon::now()));

                    $lineas[] = '- [id ' . $reciente->id . '] «' . mb_strimwidth((string) $reciente->titulo, 0, $largo_titulo, '…') . '»: ' . $reciente->estado
                        . ' hace ' . ($minutos < 60 ? $minutos . ' min' : (int) floor($minutos / 60) . ' h');
                }

                $texto = 'Tareas programadas del dueño AHORA (es la verdad; sólo cambia cuando llamás a programar, reprogramar o cancelar en este turno):' . "\n"
                    . (count($lineas) ? implode("\n", $lineas) : 'El dueño no tiene ninguna tarea programada.') . "\n" . self::ESTADO_REGLA;

                /* Termina siempre: cada vuelta recorta algo y, sin nada más que recortar, se devuelve lo que hay. */
                if (mb_strlen($texto) <= self::ESTADO_LARGO_MAXIMO || $mostradas === 0) {

                    return $texto;
                }

                if ($largo_titulo > 12) {

                    $largo_titulo = max(12, $largo_titulo - 8);

                } elseif (count($recientes)) {

                    array_pop($recientes);

                } elseif (!$compacto) {

                    $compacto = true;

                } else {

                    $mostradas--;
                }
            }

        } catch (\Throwable $e) {

            return '';
        }
    }

    /* ─────────────────────────────── textos ─────────────────────────────── */

    /**
     * La tarea contada en palabras: «el viernes 27/11 a las 17:00», «todos los días a las 09:00», etc.
     *
     * @param  \App\Models\AiTareaDelAsistente  $tarea
     * @return string
     */
    public static function cuando(AiTareaDelAsistente $tarea): string
    {
        $hora = (string) $tarea->hora;

        if ($tarea->recurrencia === AiTareaDelAsistente::RECURRENCIA_UNA_VEZ) {

            if (is_null($tarea->fecha_inicio)) {

                return 'a las ' . $hora;
            }

            return 'el ' . FormatoIaHelper::dia_de_la_semana($tarea->fecha_inicio) . ' ' . $tarea->fecha_inicio->format('d/m') . ' a las ' . $hora;
        }

        $dias = ProximaEjecucionIaHelper::dias_validos(explode(',', (string) $tarea->dias));

        /* Nombres de los días ISO (1 = lunes … 7 = domingo), en singular. */
        $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

        if ($tarea->recurrencia === AiTareaDelAsistente::RECURRENCIA_DIARIA) {

            $texto = 'todos los días a las ' . $hora;

        } elseif ($tarea->recurrencia === AiTareaDelAsistente::RECURRENCIA_SEMANAL) {

            $texto = 'todos los ' . (count($dias) ? $nombres[$dias[0]] : 'semana') . ' a las ' . $hora;

        } else {

            $lista = [];

            foreach ($dias as $dia) {
                /* lunes…viernes ya son plurales; sábado y domingo llevan «s». */
                $lista[] = substr($nombres[$dia], -1) === 's' ? $nombres[$dia] : $nombres[$dia] . 's';
            }

            $texto = 'los ' . implode(', ', $lista) . ' a las ' . $hora;
        }

        if (!is_null($tarea->fecha_fin)) {

            $texto .= ' hasta el ' . $tarea->fecha_fin->format('d/m');
        }

        return $texto;
    }

    /**
     * «viernes 27/11 a las 17:00».
     *
     * @param  \Carbon\Carbon  $momento
     * @return string
     */
    public static function momento_en_palabras(Carbon $momento): string
    {
        $momento = $momento->copy()->setTimezone(ProximaEjecucionIaHelper::ZONA);

        return FormatoIaHelper::dia_de_la_semana($momento) . ' ' . $momento->format('d/m') . ' a las ' . $momento->format('H:i');
    }

    /**
     * Lo que el asistente puede prometer sobre CÓMO le llega el resultado, según lo que el admin anunció
     * (misma fuente que los recordatorios de la Agenda: RecordatoriosDelAsistenteHelper::anunciar()).
     *
     * @param  int  $owner_id
     * @return string
     */
    public static function aviso($owner_id): string
    {
        if (RecordatoriosDelAsistenteHelper::admin_avisa_con_plantilla($owner_id)) {

            return 'A esa hora te escribo por WhatsApp y queda también en esta conversación.';
        }

        if (RecordatoriosDelAsistenteHelper::admin_avisa($owner_id)) {

            return 'Te llega por WhatsApp si ese día hablamos por ahí; si no, lo ves en esta conversación.';
        }

        return 'Lo vas a ver en esta conversación del asistente; por WhatsApp todavía no te puedo escribir por mi cuenta.';
    }

    /* ─────────────────────────────── internos ─────────────────────────────── */

    /**
     * Cuántas tareas activas tiene el dueño.
     *
     * @param  \App\Models\User  $dueno
     * @return int
     */
    protected static function activas(User $dueno): int
    {
        return AiTareaDelAsistente::where('owner_id', (int) $dueno->id)->where('estado', AiTareaDelAsistente::ESTADO_ACTIVA)->count();
    }

    /**
     * La tarea del dueño con ese id y en alguno de esos estados, o null (el mismo null para ajena e inexistente).
     *
     * @param  \App\Models\User  $dueno
     * @param  mixed  $tarea_id
     * @param  array<int,string>  $estados
     * @return \App\Models\AiTareaDelAsistente|null
     */
    protected static function buscar(User $dueno, $tarea_id, array $estados)
    {
        if (!is_numeric($tarea_id) || (int) $tarea_id <= 0) {

            return null;
        }

        return AiTareaDelAsistente::where('owner_id', (int) $dueno->id)
            ->where('id', (int) $tarea_id)
            ->whereIn('estado', $estados)
            ->first();
    }

    /**
     * El resultado de un alta o un cambio, tal como lo lee el modelo.
     *
     * @param  \App\Models\User  $dueno
     * @param  \App\Models\AiTareaDelAsistente  $tarea
     * @return array
     */
    protected static function resultado(User $dueno, AiTareaDelAsistente $tarea): array
    {
        return [
            'ok'       => true,
            'tarea_id' => (int) $tarea->id,
            'titulo'   => (string) $tarea->titulo,
            'cuando'   => self::cuando($tarea),
            'proxima'  => self::momento_en_palabras($tarea->proxima_ejecucion_at),
            'aviso'    => self::aviso((int) $dueno->id),
        ];
    }

    /**
     * Mezcla lo actual de la tarea con lo que mandó el modelo, lo valida y calcula la próxima ejecución.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $actual  Los valores de hoy (o los de fábrica en un alta).
     * @param  array  $input  Lo que mandó el modelo.
     * @param  \Carbon\Carbon  $ahora
     * @return array  ['campos' => columnas a guardar, 'proxima' => Carbon] o ['error' => motivo].
     */
    protected static function armar(User $dueno, array $actual, array $input, Carbon $ahora): array
    {
        $campos = $actual;

        /* Título e instrucción. */
        foreach (['titulo' => self::LARGO_TITULO, 'instruccion' => self::LARGO_INSTRUCCION] as $campo => $largo) {

            if (isset($input[$campo])) {

                $campos[$campo] = trim((string) $input[$campo]);
            }

            if ($campos[$campo] === '') {

                return ['error' => 'Falta el ' . $campo . ' de la tarea.'];
            }

            if (mb_strlen($campos[$campo]) > $largo) {

                return ['error' => 'El ' . $campo . ' es demasiado largo (' . mb_strlen($campos[$campo]) . ' caracteres; el máximo es ' . $largo . '). Resumilo.'];
            }
        }

        if (isset($input['area']) && trim((string) $input['area']) !== '') {

            $campos['area'] = mb_substr(trim((string) $input['area']), 0, 40);
        }

        /* Recurrencia. */
        if (isset($input['recurrencia']) && trim((string) $input['recurrencia']) !== '') {

            $campos['recurrencia'] = trim((string) $input['recurrencia']);
        }

        if (!in_array($campos['recurrencia'], AiTareaDelAsistente::RECURRENCIAS, true)) {

            return ['error' => 'La recurrencia "' . $campos['recurrencia'] . '" no existe: usá una_vez, diaria, dias_semana o semanal. No hay nada más frecuente que una vez por día.'];
        }

        /* Hora o franja: si el modelo manda alguna, se recalcula; si no, queda la de hoy. */
        $hora_dicha = isset($input['hora']) && trim((string) $input['hora']) !== '' ? trim((string) $input['hora']) : null;
        $franja_dicha = isset($input['franja']) && trim((string) $input['franja']) !== '' ? trim((string) $input['franja']) : null;

        if (!is_null($hora_dicha) && !ProximaEjecucionIaHelper::es_hora($hora_dicha)) {

            return ['error' => 'La hora "' . $hora_dicha . '" no es válida: tiene que ser HH:MM (por ejemplo 17:00).'];
        }

        if (!is_null($franja_dicha) && !in_array($franja_dicha, AiTareaDelAsistente::FRANJAS, true)) {

            return ['error' => 'La franja "' . $franja_dicha . '" no existe: usá manana, mediodia, tarde o noche (o decime la hora).'];
        }

        $horarios = self::horarios_del_dueno($dueno);

        if (!is_null($hora_dicha) || !is_null($franja_dicha)) {

            $campos['hora'] = ProximaEjecucionIaHelper::hora_de($franja_dicha, $hora_dicha, $horarios);
            $campos['franja'] = $franja_dicha;

        } elseif (is_null($campos['hora'])) {

            $campos['hora'] = $horarios['hora_preferida'];
        }

        /* Fechas. */
        foreach (['fecha' => 'fecha_inicio', 'desde' => 'fecha_inicio', 'hasta' => 'fecha_fin'] as $entrada => $columna) {

            if (!isset($input[$entrada]) || trim((string) $input[$entrada]) === '') {

                continue;
            }

            $dia = self::fecha_valida((string) $input[$entrada]);

            if (is_null($dia)) {

                return ['error' => 'La fecha "' . $input[$entrada] . '" no es válida: usá AAAA-MM-DD, tomándola de la lista de días del prompt.'];
            }

            $campos[$columna] = $dia->format('Y-m-d');
        }

        /* Días de la semana. */
        if (isset($input['dias']) && is_array($input['dias'])) {

            $dias = ProximaEjecucionIaHelper::dias_validos($input['dias']);

            $campos['dias'] = count($dias) ? implode(',', $dias) : null;
        }

        if (in_array($campos['recurrencia'], ['dias_semana', 'semanal'], true)) {

            $dias = ProximaEjecucionIaHelper::dias_validos(explode(',', (string) $campos['dias']));

            if (!count($dias)) {

                return ['error' => 'Para "' . $campos['recurrencia'] . '" decime los días en `dias` (1 = lunes … 7 = domingo).'];
            }

            if ($campos['recurrencia'] === 'semanal' && count($dias) !== 1) {

                return ['error' => 'Una tarea semanal es un solo día por semana: mandame un solo número en `dias`, o usá dias_semana.'];
            }

            $campos['dias'] = implode(',', $dias);

        } else {

            $campos['dias'] = null;
        }

        /* Cuándo es la primera (o la próxima) ejecución. */
        $hoy = $ahora->copy()->startOfDay();

        if (!is_null($campos['fecha_inicio']) && Carbon::parse($campos['fecha_inicio'], ProximaEjecucionIaHelper::ZONA)->gt($hoy->copy()->addDays(self::DIAS_MAXIMOS))) {

            return ['error' => 'Esa fecha está a más de ' . self::DIAS_MAXIMOS . ' días: programo hasta ' . self::DIAS_MAXIMOS . ' días hacia adelante.'];
        }

        if ($campos['recurrencia'] === AiTareaDelAsistente::RECURRENCIA_UNA_VEZ) {

            if (is_null($campos['fecha_inicio'])) {

                $campos['fecha_inicio'] = $hoy->format('Y-m-d');
            }

            $campos['fecha_fin'] = null;
        }

        if (!is_null($campos['fecha_fin']) && !is_null($campos['fecha_inicio']) && $campos['fecha_fin'] < $campos['fecha_inicio']) {

            return ['error' => 'La fecha de fin (' . $campos['fecha_fin'] . ') es anterior a la de inicio (' . $campos['fecha_inicio'] . ').'];
        }

        $proxima = ProximaEjecucionIaHelper::primera($campos, $ahora, $horarios['dias_sin_mensajes']);

        if (is_null($proxima)) {

            return ['error' => 'Con esas fechas no queda ninguna ejecución (la fecha de fin ya pasó o no hay un día que cumpla).'];
        }

        if ($campos['recurrencia'] === AiTareaDelAsistente::RECURRENCIA_UNA_VEZ
            && $proxima->lt($ahora->copy()->addMinutes(self::MINUTOS_MINIMOS))) {

            return ['error' => 'Ese momento ya pasó o es dentro de menos de ' . self::MINUTOS_MINIMOS . ' minutos. Ahora son las ' . $ahora->format('H:i') . ' del ' . FormatoIaHelper::fecha_con_dia($ahora) . ': elegí un momento más tarde.'];
        }

        return ['campos' => $campos, 'proxima' => $proxima];
    }

    /**
     * La fecha AAAA-MM-DD como Carbon a las 00:00, o null si no es una fecha real.
     *
     * @param  string  $texto
     * @return \Carbon\Carbon|null
     */
    protected static function fecha_valida($texto)
    {
        $texto = trim($texto);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto)) {

            return null;
        }

        $dia = Carbon::createFromFormat('Y-m-d', $texto, ProximaEjecucionIaHelper::ZONA);

        if ($dia === false || $dia->format('Y-m-d') !== $texto) {

            return null;
        }

        return $dia->startOfDay();
    }
}
