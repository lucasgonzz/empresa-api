<?php

namespace App\Jobs;

use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\SemillaRegistroHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Borra TODO lo sembrado del dueño antes de aplicar su primera importación de artículos (misión
 * prueba-experiencia-empresa, 3/11/2026; decisión de Lucas: «borrar todo lo de prueba, avisándole al lead»).
 *
 * Va PRIMERO en la cadena de `InitExcelImport::mandar_chain()`, antes de los chunks: si los artículos de
 * ejemplo siguieran en la base cuando se aplica el Excel, un código repetido ACTUALIZARÍA el de ejemplo en vez
 * de crear el real, y después se borraría con él. Corre en el worker, con `$tries = 1`: si falla, la cadena
 * se corta, el import queda marcado como fallido por `ImportFailureHandler` (mismo camino que un chunk roto) y
 * no se aplica nada a medias. Volver a importar lo reintenta: `borrar_lo_sembrado()` es idempotente.
 *
 * El origen (`excel` | `pdf` | `martin`) queda en `prueba_config` para que `FinalizeArticleImport` lo ponga en
 * `datos.origen` del hito `prueba.importo`, que se emite recién cuando la importación TERMINA.
 */
class BorrarSemillaDePruebaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public $tries = 1;

    /** Borrar ~470 operaciones por sus controladores lleva minutos, no segundos. */
    public $timeout = 1800;

    /** @var int */
    public $owner_id;

    /** @var int */
    public $import_history_id;

    /** @var int|null */
    public $import_status_id;

    /** @var string */
    public $origen;

    /**
     * @param int $owner_id
     * @param int $import_history_id
     * @param int|null $import_status_id
     * @param string|null $origen `excel` | `pdf` | `martin`
     */
    public function __construct($owner_id, $import_history_id, $import_status_id = null, $origen = null)
    {
        $this->owner_id = (int) $owner_id;
        $this->import_history_id = (int) $import_history_id;
        $this->import_status_id = is_null($import_status_id) ? null : (int) $import_status_id;
        $this->origen = PruebaHelper::normalizar_origen($origen);
    }

    /**
     * @return void
     */
    public function handle()
    {
        PruebaHelper::olvidar_cache();

        if (!SemillaRegistroHelper::semilla_presente($this->owner_id)) {
            Log::info('BorrarSemillaDePruebaJob: el dueño ' . $this->owner_id . ' ya no tiene datos de ejemplo; nada que borrar.');

            return;
        }

        Log::info('BorrarSemillaDePruebaJob: borrando los datos de ejemplo del dueño ' . $this->owner_id . ' antes de la importación ' . $this->import_history_id . '.');

        $resumen = SemillaRegistroHelper::borrar_lo_sembrado($this->owner_id);

        PruebaHelper::marcar_semilla_borrada($this->owner_id, $this->origen);

        Log::info('BorrarSemillaDePruebaJob: listo.', $resumen);
    }

    /**
     * La importación no se aplica y queda marcada como fallida, con el motivo visible para el negocio.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        Log::error('BorrarSemillaDePruebaJob: falló el borrado de los datos de ejemplo del dueño ' . $this->owner_id . ': ' . $exception->getMessage());

        try {
            ImportFailureHandler::desde_excepcion(
                $this->import_history_id,
                $this->import_status_id,
                $this->owner_id,
                $exception
            );
        } catch (Throwable $e) {
            Log::error('BorrarSemillaDePruebaJob: tampoco se pudo marcar la importación como fallida: ' . $e->getMessage());
        }
    }
}
