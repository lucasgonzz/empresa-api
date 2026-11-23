<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTareaDelAsistente;
use App\Models\AiTareaDelAsistenteEjecucion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Que el resultado de una tarea programada llegue SIEMPRE al dueño, aunque la ventana de 24 h de WhatsApp
 * estuviera cerrada cuando el admin lo quiso mandar (misión agente-tareas-programadas, enmienda 1 / E1,
 * 22/11/2026).
 *
 * El problema: con la ventana cerrada el admin sólo puede mandar una plantilla («…Ya te dejé el mensaje:
 * respondeme por acá y seguimos»), pero sellaba la ejecución como `entregada` igual que si hubiera salido el
 * texto. Cuando el dueño contestaba, nada garantizaba que recibiera el contenido: el modelo veía su propia
 * respuesta en el historial como ya dicha.
 *
 * La solución, en dos tiempos:
 *   1. Al sellar, la ejecución guarda `entregada_via`: «texto» (salió completo) o «plantilla» (sólo la plantilla).
 *      Lo manda el admin nuevo en el campo opcional `via`; si no viene, se DEDUCE (via_del_sello()).
 *   2. Mientras exista una ejecución `entregada` con via «plantilla», sin `mostrada_at` y de las últimas 24 h, el
 *      PRIMER turno de WhatsApp que inicia el dueño la entrega por código: la respuesta de ese turno EMPIEZA con el
 *      texto guardado (anteponer()) y después sigue lo que contestó el modelo. Al quedar guardada la respuesta, la
 *      ejecución se marca `mostrada_at` (marcar_mostradas()). Si el turno falla, no se marca y se reintenta.
 *
 * 🔴 DÓNDE ENGANCHA: el texto se antepone en ResponderMensajeChatIaJob, justo antes de guardar el mensaje. Es el
 * único punto donde el texto que queda en `ai_messages.contenido` (el historial) y el que el admin lee por el
 * polling (AdminSync/AsistenteController::estado_del_mensaje lee ese mismo campo) son EL MISMO: el dueño lee en
 * su teléfono exactamente lo que dice el historial. Hacerlo en el admin, o en el Service antes del cierre del
 * turno, dejaría el historial sin el resultado.
 *
 * Todo está acotado por el dueño de la instancia (hay bases compartidas entre comercios).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class EntregaDeResultadosDeTareasIaHelper
{
    /** Durante cuántas horas desde que se mandó la plantilla se entrega el resultado en el turno del dueño. */
    const HORAS_DE_VENTANA = 24;

    /** Cuántos resultados pendientes se entregan como mucho por turno. */
    const MAX_POR_TURNO = 3;

    /** Largo máximo del texto de un mensaje de WhatsApp (el tope de la API). */
    const LARGO_MAXIMO_DEL_MENSAJE = 4096;

    /**
     * Lo mínimo que tiene que mostrarse de un resultado (sin contar su encabezado) para que valga la pena
     * anteponerlo. Si el espacio que deja la respuesta del modelo no da para esto, el resultado no se antepone en
     * este turno y queda pendiente para el siguiente.
     */
    const LARGO_MINIMO_POR_RESULTADO = 200;

    /** Lo que separa los resultados entre sí y de la respuesta del modelo (cuenta dentro del largo máximo). */
    const SEPARADOR = "\n\n";

    /* ─────────────────────────────── el sello: ¿salió el texto o una plantilla? ─────────────────────────────── */

    /**
     * La vía con la que salió el resultado, para guardar en la ejecución al sellarla.
     *
     * Con `via` válido («texto» o «plantilla») se usa tal cual. Con cualquier otro valor o sin él (el admin de
     * hoy no lo manda) se deduce: «texto» si el dueño tiene algún mensaje propio por WhatsApp en las 24 h
     * anteriores al sello (con la ventana abierta el admin manda el texto completo), «plantilla» si no. Un mensaje
     * sintético (tipo `tarea_programada`) no es del dueño.
     *
     * @param  mixed  $via  Lo que mandó el admin (o null).
     * @param  \App\Models\User  $dueno
     * @param  \Carbon\Carbon  $ahora  El momento del sello.
     * @return string  AiTareaDelAsistenteEjecucion::VIA_TEXTO | VIA_PLANTILLA.
     */
    public static function via_del_sello($via, User $dueno, Carbon $ahora): string
    {
        $via = is_string($via) ? strtolower(trim($via)) : '';

        if ($via === AiTareaDelAsistenteEjecucion::VIA_TEXTO || $via === AiTareaDelAsistenteEjecucion::VIA_PLANTILLA) {

            return $via;
        }

        $escribio = AiMessage::where('rol', 'user')
            ->where('canal', AiMessage::CANAL_WHATSAPP)
            ->where(function ($q) {
                $q->whereNull('tipo')->orWhere('tipo', '<>', AiMessage::TIPO_TAREA_PROGRAMADA);
            })
            ->where('created_at', '>', $ahora->copy()->subHours(self::HORAS_DE_VENTANA)->format('Y-m-d H:i:s'))
            ->whereIn('ai_conversation_id', function ($q) use ($dueno) {
                $q->select('id')
                    ->from('ai_conversations')
                    ->where('user_id', (int) $dueno->id)
                    ->where('auth_user_id', (int) $dueno->id);
            })
            ->exists();

        return $escribio ? AiTareaDelAsistenteEjecucion::VIA_TEXTO : AiTareaDelAsistenteEjecucion::VIA_PLANTILLA;
    }

    /* ─────────────────────────────── el turno del dueño: qué hay por entregar ─────────────────────────────── */

    /**
     * Los resultados que este turno tiene que entregar: vacío salvo que sea un turno de WhatsApp que inició el
     * dueño (no uno sintético ni desatendido) y haya ejecuciones entregadas por plantilla, sin mostrar y de las
     * últimas 24 h. En orden de ocurrencia, como mucho MAX_POR_TURNO. Protegido: si algo falla, no se entrega
     * nada (el turno sigue como siempre).
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  \App\Models\AiMessage  $assistant  El `assistant` del turno.
     * @return array<int, array{id: int, titulo: string, texto: string}>
     */
    public static function pendientes(AiConversation $conversation, AiMessage $assistant): array
    {
        try {

            if (!TareasDelAsistenteIaHelper::hay_tablas() || !$assistant->es_de_whatsapp()) {

                return [];
            }

            /* Sólo cuando habla el dueño (no un empleado) y no cuando el turno lo disparó una tarea. */
            if ((int) $conversation->auth_user_id !== (int) $conversation->user_id
                || DespertadorDeTareasIaHelper::es_turno_desatendido($conversation, $assistant)) {

                return [];
            }

            $ejecuciones = AiTareaDelAsistenteEjecucion::where('owner_id', (int) $conversation->user_id)
                ->where('estado', AiTareaDelAsistenteEjecucion::ESTADO_ENTREGADA)
                ->where('entregada_via', AiTareaDelAsistenteEjecucion::VIA_PLANTILLA)
                ->whereNull('mostrada_at')
                ->whereNotNull('texto')
                ->where('entregada_at', '>', TareasDelAsistenteIaHelper::ahora()->subHours(self::HORAS_DE_VENTANA)->format('Y-m-d H:i:s'))
                ->orderBy('ocurrencia_at')
                ->orderBy('id')
                ->limit(self::MAX_POR_TURNO)
                ->get();

            if (!count($ejecuciones)) {

                return [];
            }

            $titulos = AiTareaDelAsistente::where('owner_id', (int) $conversation->user_id)
                ->whereIn('id', $ejecuciones->pluck('tarea_id')->all())
                ->pluck('titulo', 'id')
                ->all();

            $pendientes = [];

            foreach ($ejecuciones as $ejecucion) {

                $texto = trim((string) $ejecucion->texto);

                if ($texto === '') {

                    continue;
                }

                $pendientes[] = [
                    'id'     => (int) $ejecucion->id,
                    'titulo' => isset($titulos[$ejecucion->tarea_id]) ? trim((string) $titulos[$ejecucion->tarea_id]) : 'tu asistente',
                    'texto'  => $texto,
                ];
            }

            return $pendientes;

        } catch (\Throwable $e) {

            Log::warning('EntregaDeResultadosDeTareasIaHelper: no se pudo ver qué resultados entregar', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Lo que se le dice al modelo (en el system NO cacheado) para que no repita lo que el código ya va a poner
     * delante de su respuesta. '' si no hay nada por entregar.
     *
     * @param  array<int, array{id: int, titulo: string, texto: string}>  $pendientes
     * @return string
     */
    public static function aviso_para_el_modelo(array $pendientes): string
    {
        if (!count($pendientes)) {

            return '';
        }

        $aviso = 'El dueño todavía no había visto ' . (count($pendientes) === 1 ? 'el resultado de una tarea programada tuya' : 'el resultado de ' . count($pendientes) . ' tareas programadas tuyas')
            . ' (sólo recibió un aviso con plantilla). El sistema lo pone, tal cual, al principio de tu respuesta (sólo entra lo que cabe en un mensaje de WhatsApp: '
            . 'lo que no entre se lo muestra en el turno siguiente). '
            . 'No lo repitas, no lo resumas ni anuncies que se lo mostrás; contestá sólo lo que el dueño acaba de decir, sabiendo que lo está leyendo. Lo que se le muestra:';

        foreach ($pendientes as $pendiente) {

            $aviso .= "\n«" . $pendiente['titulo'] . "»:\n" . $pendiente['texto'];
        }

        return $aviso;
    }

    /* ─────────────────────────────── armar la respuesta y marcar ─────────────────────────────── */

    /**
     * La respuesta del turno con los resultados por delante, y cuáles resultados entraron de verdad.
     *
     * Regla de reparto (el mensaje final NUNCA pasa de LARGO_MAXIMO_DEL_MENSAJE, contando encabezados,
     * separadores, el aviso «seguí en la app» y la respuesta del modelo):
     *   1. La respuesta del modelo nunca se recorta ni se pierde.
     *   2. Los resultados se reparten el espacio que queda: el que es corto se queda con lo que mide y lo que
     *      sobra pasa a los demás; el que no entra se recorta (con el aviso de que sigue en la app).
     *   3. Si ni así alcanza para mostrar LARGO_MINIMO_POR_RESULTADO caracteres de cada resultado, se prueba con
     *      uno menos (el más nuevo es el que queda afuera), y así hasta 0: la respuesta sale sola.
     * Los que quedan afuera NO están en `entregadas`, así que no se marcan como mostrados y se entregan en un
     * turno siguiente (dentro de sus 24 h).
     *
     * Una respuesta del modelo que ya pasa sola del largo máximo sale tal cual (no se recorta: es lo que dijo el
     * modelo), sin ningún resultado por delante.
     *
     * @param  array<int, array{id: int, titulo: string, texto: string}>  $pendientes
     * @param  string  $respuesta  Lo que contestó el modelo.
     * @return array{texto: string, entregadas: array<int, array{id: int, titulo: string, texto: string}>}
     */
    public static function armar(array $pendientes, $respuesta): array
    {
        $respuesta = (string) $respuesta;

        /* Sin nada antepuesto, el texto sale exactamente como lo dijo el modelo. */
        $sin_resultados = ['texto' => $respuesta, 'entregadas' => []];

        if (!count($pendientes)) {

            return $sin_resultados;
        }

        $respuesta_corta = trim($respuesta);

        $largo_separador = mb_strlen(self::SEPARADOR);

        /* Lo que ocupa la respuesta del modelo con su separador (0 si no dijo nada). */
        $reservado = $respuesta_corta === '' ? 0 : mb_strlen($respuesta_corta) + $largo_separador;

        for ($cantidad = count($pendientes); $cantidad >= 1; $cantidad--) {

            $candidatos = array_slice($pendientes, 0, $cantidad);

            /* Lo que queda para los resultados: sin la respuesta ni los separadores entre resultados. */
            $disponible = self::LARGO_MAXIMO_DEL_MENSAJE - $reservado - $largo_separador * ($cantidad - 1);

            $largos = self::repartir($candidatos, $disponible);

            if (is_null($largos)) {

                continue;
            }

            $partes = [];

            foreach ($candidatos as $indice => $pendiente) {

                $encabezado = self::encabezado_de($pendiente, $cantidad);

                $partes[] = $encabezado . self::recortar(trim($pendiente['texto']), $largos[$indice] - mb_strlen($encabezado));
            }

            $texto = implode(self::SEPARADOR, $partes);

            if ($respuesta_corta !== '') {

                $texto .= self::SEPARADOR . $respuesta_corta;
            }

            return ['texto' => $texto, 'entregadas' => $candidatos];
        }

        return $sin_resultados;
    }

    /**
     * Sólo el texto de armar(): la respuesta del turno con los resultados que entran por delante.
     *
     * @param  array<int, array{id: int, titulo: string, texto: string}>  $pendientes
     * @param  string  $respuesta  Lo que contestó el modelo.
     * @return string
     */
    public static function anteponer(array $pendientes, $respuesta): string
    {
        return self::armar($pendientes, $respuesta)['texto'];
    }

    /**
     * El encabezado de un resultado: su título entre comillas angulares si el mensaje lleva más de uno, nada si
     * lleva uno solo.
     *
     * @param  array{id: int, titulo: string, texto: string}  $pendiente
     * @param  int  $cantidad  Cuántos resultados lleva el mensaje.
     * @return string
     */
    protected static function encabezado_de(array $pendiente, $cantidad): string
    {
        return $cantidad > 1 ? '«' . $pendiente['titulo'] . "»\n" : '';
    }

    /**
     * Cuántos caracteres le toca a cada resultado (encabezado incluido) dentro de `$disponible`.
     *
     * Reparto parejo con lo que sobra redistribuido: se recorren de menor a mayor largo; cada uno recibe lo que
     * mide o, si no entra, una parte igual de lo que queda. Devuelve null si a alguno no le toca para mostrar al
     * menos LARGO_MINIMO_POR_RESULTADO caracteres de su texto (o su texto entero, si es más corto que eso).
     *
     * @param  array<int, array{id: int, titulo: string, texto: string}>  $candidatos
     * @param  int  $disponible  Caracteres para todos los resultados juntos.
     * @return array<int, int>|null  Largo asignado por posición, o null si no alcanza.
     */
    protected static function repartir(array $candidatos, $disponible)
    {
        $naturales = [];
        $minimos = [];

        foreach ($candidatos as $indice => $pendiente) {

            $largo_encabezado = mb_strlen(self::encabezado_de($pendiente, count($candidatos)));

            $naturales[$indice] = $largo_encabezado + mb_strlen(trim($pendiente['texto']));
            $minimos[$indice] = min($naturales[$indice], $largo_encabezado + self::LARGO_MINIMO_POR_RESULTADO);
        }

        /* De menor a mayor: los cortos se quedan con lo que miden y lo que sobra pasa a los largos. */
        asort($naturales);

        $restante = $disponible;
        $faltan = count($naturales);
        $largos = [];

        foreach ($naturales as $indice => $natural) {

            $parte = (int) floor($restante / $faltan);

            $largos[$indice] = min($natural, $parte);

            if ($largos[$indice] < $minimos[$indice]) {

                return null;
            }

            $restante -= $largos[$indice];
            $faltan--;
        }

        return $largos;
    }

    /**
     * Marca los resultados como mostrados (una sola vez: sólo los que todavía no lo estaban). Se llama cuando la
     * respuesta del turno ya quedó guardada; si el turno falló, no se llama y el siguiente turno los entrega.
     *
     * @param  array<int, array{id: int, titulo: string, texto: string}>  $pendientes
     * @return void
     */
    public static function marcar_mostradas(array $pendientes)
    {
        if (!count($pendientes)) {

            return;
        }

        try {

            $ids = [];

            foreach ($pendientes as $pendiente) {

                $ids[] = (int) $pendiente['id'];
            }

            AiTareaDelAsistenteEjecucion::whereIn('id', $ids)
                ->whereNull('mostrada_at')
                ->update(['mostrada_at' => TareasDelAsistenteIaHelper::ahora()->format('Y-m-d H:i:s')]);

        } catch (\Throwable $e) {

            Log::warning('EntregaDeResultadosDeTareasIaHelper: no se pudo marcar el resultado como mostrado', ['error' => $e->getMessage()]);
        }
    }

    /**
     * El texto recortado a `$largo` caracteres, con el aviso de que sigue en la app.
     *
     * @param  string  $texto
     * @param  int  $largo
     * @return string
     */
    protected static function recortar($texto, $largo): string
    {
        if (mb_strlen($texto) <= $largo) {

            return $texto;
        }

        $sufijo = DespertadorDeTareasIaHelper::SUFIJO_RECORTADO;

        return rtrim(mb_substr($texto, 0, max(0, $largo - mb_strlen($sufijo)))) . $sufijo;
    }
}
