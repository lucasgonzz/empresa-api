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
        return ExportHistory::create([
            'user_id' => (int) $user_id,
            'employee_id' => (int) $employee_id,
            'model_name' => $model_name,
            'status' => 'pending',
        ]);
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
    }

    /**
     * Arma el link público del archivo en exported-files.
     *
     * @param string $file_name
     * @return string
     */
    public static function build_download_url($file_name)
    {
        $api_url = config('app.API_URL');
        if (
            config('app.APP_ENV') == 'production'
            && !config('app.VPS')
        ) {
            $api_url .= '/public';
        }

        return $api_url . '/exported-files/' . rawurlencode($file_name);
    }
}
