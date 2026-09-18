<?php

namespace App\Http\Controllers\Helpers;

use App\Models\ExportHistory;

class ExportHistoryHelper
{
    /**
     * Registra una exportación recién encolada.
     *
     * @param int $user_id
     * @param int $employee_id
     * @param string $model_name
     * @return \App\Models\ExportHistory
     */
    public static function create_pending($user_id, $employee_id, $model_name)
    {
        $export_history = ExportHistory::create([
            'user_id' => (int) $user_id,
            'employee_id' => (int) $employee_id,
            'model_name' => $model_name,
            'status' => 'pending',
        ]);

        /*
         * El registro visible (misión procesos-en-segundo-plano, 18/9/2026) nace acá, en el
         * request, y en `pendiente`: entre que el usuario apretó "Exportar" y que un worker
         * levanta el job pueden pasar minutos en el shared hosting, y en ese rato la píldora ya
         * tiene que decir que hay algo esperando. El job lo pasa a en_proceso al arrancar. Sin
         * total: generar un Excel no tiene unidades que contar, la barra es indeterminada.
         */
        BackgroundProcessHelper::iniciar($user_id, 'exportacion', 'Exportación de ' . DeleteModelsHelper::get_model_label($model_name), [
            'auth_user_id' => $employee_id,
            'referencia'   => $export_history,
            'status'       => 'pendiente',
            'etapa'        => 'En espera del procesador',
        ]);

        return $export_history;
    }

    /**
     * Marca la exportación como completada y guarda la URL de descarga.
     *
     * @param \App\Models\ExportHistory $export_history
     * @param string $file_name
     * @param int|null $exported_count
     * @return string URL pública de descarga
     */
    public static function mark_completed($export_history, $file_name, $exported_count = null)
    {
        $download_link = self::build_download_url($file_name);

        $export_history->status = 'completed';
        $export_history->file_name = $file_name;
        $export_history->excel_url = $download_link;
        $export_history->exported_count = $exported_count;
        $export_history->error_message = null;
        $export_history->save();

        // El link viaja en el resultado: es lo que el detalle del modal ofrece para descargar.
        BackgroundProcessHelper::completar(BackgroundProcessHelper::por_referencia($export_history), [
            'archivo'    => $file_name,
            'link'       => $download_link,
            'exportados' => is_null($exported_count) ? null : (int) $exported_count,
        ]);

        return $download_link;
    }

    /**
     * Marca la exportación como fallida.
     *
     * @param \App\Models\ExportHistory $export_history
     * @param string $error_message
     * @return void
     */
    public static function mark_failed($export_history, $error_message)
    {
        $export_history->status = 'failed';
        $export_history->error_message = $error_message;
        $export_history->save();

        /*
         * Único punto de cierre en fallo del registro visible: acá llegan el catch y el
         * failed() de los tres jobs (vía BackgroundJobFailureHandler, que ya es idempotente
         * por el status del historial) y también el comando que detecta historiales colgados.
         */
        BackgroundProcessHelper::fallar(
            BackgroundProcessHelper::por_referencia($export_history),
            (string) $error_message
        );
    }

    /**
     * Arma el link público del archivo en exported-files.
     *
     * @param string $file_name
     * @return string
     */
    public static function build_download_url($file_name)
    {
        /* URL publica centralizada en ApiUrlHelper (grupo 230, prompt 01). Antes esta funcion
           usaba su propia condicion (APP_ENV == 'production'), distinta de la que usan
           ImageController/ProcessArticleBatchImagesJob (APP_ENV != 'local'): en instalaciones de
           staging/beta una de las dos estaba mal. rawurlencode se mantiene porque el nombre del
           archivo lo arma el usuario. */
        return ApiUrlHelper::public_base() . '/exported-files/' . rawurlencode($file_name);
    }
}
