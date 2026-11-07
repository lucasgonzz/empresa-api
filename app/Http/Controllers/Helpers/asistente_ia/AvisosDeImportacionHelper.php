<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiAvisoDelAsistente;
use App\Models\ExcelAnalysisRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El aviso por WhatsApp de «ya leí tu Excel» (misión cruzada prueba-martin-importacion, 3/11/2026,
 * programa «Prueba gratuita de 7 días»).
 *
 * Cuando el negocio en prueba le manda su Excel a Martín por el ticket de soporte, el admin lo reenvía a
 * `POST admin-sync/ai-excel-import/analyze` con `segundo_plano=1` y `avisar_por_whatsapp=1`: el análisis
 * corre en `RunExcelAnalysisJob` como si el dueño lo hubiera subido desde el modal, y al terminar queda
 * la notificación `excel_analysis_ready` de siempre en el sistema. Lo que faltaba es avisarle POR
 * WHATSAPP, que es por donde mandó el archivo: este helper deja la fila en `ai_avisos_del_asistente` con
 * el tipo `importacion_lista`, y sale por el mismo camino que «ya leí tu factura» (el admin consulta
 * `avisos-pendientes`, manda, y sella con `avisos/avisado`). Sin claves nuevas ni callbacks.
 *
 * 🔴 Solo cuando el payload de la corrida lo pidió (`avisar_por_whatsapp`): un análisis que el dueño subió
 * desde el modal no deja ningún aviso, igual que hoy. Y una fila por corrida (idempotente por uuid): el
 * job puede terminar por el camino de error y después por el de `failed()`, y el aviso tiene que ser uno.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class AvisosDeImportacionHelper
{
    /** El tipo del aviso, y el prefijo del `id` del contrato ("importacion_lista:{uuid}"). */
    const TIPO = AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA;

    /**
     * La ruta del SPA que abre el Listado de artículos con la importación para seguir (`routes.js`:
     * `/listado-de-articulos`; la SPA levanta la corrida abierta con `analysis-en-curso` al cargar y, desde
     * M3, lee `?importacion=<uuid>` para abrir ese análisis).
     */
    const RUTA = '/listado-de-articulos?importacion=';

    /**
     * Deja el aviso de una corrida que terminó (listo o error), si la corrida pidió el aviso. Nunca lanza.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @return \App\Models\AiAvisoDelAsistente|null
     */
    public static function dejar(ExcelAnalysisRun $run)
    {
        try {

            if (!self::pidio_aviso($run) || !AvisosDeTurnosIaHelper::hay_tabla()) {

                return null;
            }

            if (!in_array((string) $run->estado, ['listo', 'error'], true)) {

                return null;
            }

            $ya = AiAvisoDelAsistente::where('owner_id', (int) $run->user_id)
                                        ->where('tipo', self::TIPO)
                                        ->where('uuid', (string) $run->uuid)
                                        ->first();

            if (!is_null($ya)) {

                return $ya;
            }

            return AiAvisoDelAsistente::create([
                'uuid'               => (string) $run->uuid,
                'owner_id'           => (int) $run->user_id,
                'ai_conversation_id' => null,
                'ai_message_id'      => null,
                'tipo'               => self::TIPO,
                'texto'              => self::texto_del_aviso($run),
            ]);

        } catch (\Throwable $e) {

            Log::warning('AvisosDeImportacionHelper: no se pudo dejar el aviso de la importación', [
                'excel_analysis_run_id' => (int) $run->id,
                'error'                 => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Si la corrida pidió el aviso por WhatsApp (lo manda el admin en `analyze` con `avisar_por_whatsapp`).
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @return bool
     */
    public static function pidio_aviso(ExcelAnalysisRun $run): bool
    {
        $payload = is_array($run->payload) ? $run->payload : [];

        if (!isset($payload['avisar_por_whatsapp'])) {

            return false;
        }

        $valor = $payload['avisar_por_whatsapp'];

        return $valor === true || in_array(strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí'], true);
    }

    /**
     * El texto que sale por WhatsApp, SIN el link (lo agrega el admin, que es quien sabe si lo tiene).
     * En la voz de Martín: corto y sin markdown pesado.
     *
     * @param  \App\Models\ExcelAnalysisRun  $run
     * @return string
     */
    public static function texto_del_aviso(ExcelAnalysisRun $run): string
    {
        $presentacion = $run->datos_de_presentacion();

        $archivo = isset($presentacion['original_filename']) && trim((string) $presentacion['original_filename']) !== ''
            ? '"' . trim((string) $presentacion['original_filename']) . '"'
            : 'tu Excel';

        if ((string) $run->estado !== 'listo') {

            $motivo = trim((string) $run->error);

            return 'No pude leer ' . $archivo . ($motivo !== '' ? ': ' . rtrim($motivo, '.') : '')
                . '. Si podés, mandámelo de nuevo por acá o avisame y lo vemos juntos.';
        }

        $resultado = is_array($run->resultado) ? $run->resultado : [];

        $filas = isset($resultado['row_count']) && is_numeric($resultado['row_count']) ? (int) $resultado['row_count'] : 0;

        $cuantas = $filas > 0
            ? ' Encontré ' . $filas . ' ' . ($filas === 1 ? 'fila' : 'filas') . '.'
            : '';

        return 'Ya leí ' . $archivo . '.' . $cuantas
            . ' Entrá al sistema y te aparece la importación lista para que confirmes las columnas y la termines; '
            . 'al confirmarla se borra la información de ejemplo y queda la tuya.';
    }

    /**
     * Los avisos de importación del dueño que todavía no salieron, de las últimas 24 h, del más viejo al
     * más nuevo. Vacío si la tabla no existe.
     *
     * @param  \App\Models\User  $dueno
     * @param  int  $tope
     * @return \Illuminate\Support\Collection
     */
    public static function pendientes_de_aviso(User $dueno, $tope, ?array $tickets_soporte = null)
    {
        if (!AvisosDeTurnosIaHelper::hay_tabla()) {

            return collect();
        }

        return AiAvisoDelAsistente::where('owner_id', (int) $dueno->id)
                                    ->where('tipo', self::TIPO)
                                    ->whereNull('avisado_por_whatsapp_at')
                                    ->when($tickets_soporte !== null, function ($consulta) use ($tickets_soporte) {
                                        // La ventana de WhatsApp la valida el admin al enviar. Si el cliente vuelve a
                                        // escribir, la pregunta por el Excel todavía tiene que estar disponible.
                                        $consulta->where('modo_soporte', true)->whereIn('support_ticket_id', $tickets_soporte);
                                    }, function ($consulta) {
                                        $consulta->where('created_at', '>=', Carbon::now()->subHours(AvisosDeTurnosIaHelper::HORAS_DEL_AVISO));
                                    })
                                    ->orderBy('created_at')
                                    ->orderBy('id')
                                    ->limit((int) $tope)
                                    ->get();
    }

    /**
     * La ruta relativa del SPA para un aviso.
     *
     * @param  string  $uuid
     * @return string
     */
    public static function ruta($uuid): string
    {
        return self::RUTA . rawurlencode((string) $uuid);
    }
}
