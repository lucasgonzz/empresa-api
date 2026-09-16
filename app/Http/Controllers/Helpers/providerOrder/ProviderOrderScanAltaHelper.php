<?php

namespace App\Http\Controllers\Helpers\providerOrder;

use App\Http\Controllers\ProviderOrderScanController;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderScan;
use App\Models\ProviderOrderScanImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * El alta de un escaneo de factura de compra, en un solo lugar (misión asistente-por-whatsapp,
 * §3.6 del plan, 16/9/2026).
 *
 * Mismo motivo que ProviderOrderAltaHelper: hasta hoy el cuerpo vivía inline en
 * ProviderOrderScanController::store(), leyendo el `$request`. El segundo llamador es el asistente
 * colgándole a una compra la foto que el dueño mandó por WhatsApp, y no tiene request.
 *
 * 🔴 EL CUERPO ES EL MISMO: la guarda del escaneo en curso con su ventana de vencimiento, el
 * redimensionado a webp, las filas de ProviderOrderScan y ProviderOrderScanImage, el despacho del
 * job y la limpieza de la carpeta si algo falla a mitad. `store()` pasó a delegar y no cambió nada
 * observable — los mismos códigos (202, 409, 422, 500) y los mismos textos.
 *
 * Lo que NO entra acá es la validación del lote (cuántas, extensión, peso): eso es validación del
 * request y se queda en el controlador, que es quien tiene el UploadedFile con su nombre original.
 * El asistente valida lo suyo en AsistenteImagenHelper, con los MISMOS topes y la misma config.
 */
class ProviderOrderScanAltaHelper
{
    /**
     * Crea el escaneo con sus fotos y despacha el job que lo procesa.
     *
     * `$imagenes` acepta las dos formas que existen en el sistema:
     *   - UploadedFile: lo que sube el usuario desde la pantalla;
     *   - array ['binario' => string, 'nombre_original' => string]: lo que ya está guardado, que es
     *     el caso del asistente (las fotos de WhatsApp ya viven en el disco local).
     * Las dos pasan por el MISMO redimensionado: una foto que ya es de 1568 px no se agranda
     * (upsize), así que re-encodearla no cambia nada visible y a cambio hay un solo camino que
     * mantener.
     *
     * @param  \App\Models\ProviderOrder  $orden  La compra a la que se le cuelga la factura.
     * @param  array  $imagenes  UploadedFile[] o arrays ['binario', 'nombre_original'].
     * @param  mixed  $persona  La persona que disparó el escaneo (User o null): va en auth_user_id
     *                          para que el aviso de "terminó" no le llegue a todos los empleados.
     * @return array  ['status' => int, 'body' => array]
     */
    public static function crear(ProviderOrder $orden, array $imagenes, $persona = null)
    {
        $owner_id = (int) $orden->user_id;

        /*
         * Un solo escaneo en curso por compra: dos jobs escribiendo la misma compra al mismo
         * tiempo se pisarían, y el usuario no tendría forma de saber cuál ganó.
         *
         * 🔴 Solo cuentan los RECIENTES (ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO).
         * Un escaneo en 'pendiente'/'procesando' más viejo que la ventana se considera abandonado
         * y no bloquea: no hay ningún worker que lo vaya a terminar, y sin este vencimiento la
         * compra queda tapiada para siempre.
         *
         * Se mira `created_at` y no `updated_at` a propósito: un escaneo que nunca salió de
         * 'pendiente' porque el worker estaba caído no tiene NINGUNA escritura posterior, y
         * `created_at` es lo único que lo fecha.
         */
        $desde = now()->subMinutes(ProviderOrderScanController::MINUTOS_ESCANEO_EN_CURSO);

        $hay_uno_en_curso = ProviderOrderScan::where('provider_order_id', $orden->id)
                                                ->where('user_id', $owner_id)
                                                ->whereIn('estado', ProviderOrderScanController::ESTADOS_EN_CURSO)
                                                ->where('created_at', '>=', $desde)
                                                ->exists();

        if ($hay_uno_en_curso) {

            return self::respuesta(409, [
                'message' => 'Esta compra ya tiene un escaneo en curso. Esperá a que termine.',
            ]);
        }

        /*
         * El uuid se genera acá porque forma parte de la ruta donde se guardan las fotos
         * (storage/app/provider_order_scans/{user_id}/{uuid}/{orden}.webp) y las fotos se guardan
         * antes de crear la fila.
         */
        $uuid      = Str::uuid()->toString();
        $carpeta   = 'provider_order_scans/' . $owner_id . '/' . $uuid;
        $guardadas = [];

        try {

            $orden_de_la_imagen = 1;

            foreach ($imagenes as $imagen) {

                $nombre_original = self::nombre_original($imagen);

                $binario = self::redimensionar_a_webp(self::binario_de($imagen));

                if (is_null($binario)) {

                    /* Salimos por el 422 y no por el catch: hay que limpiar lo ya guardado igual. */
                    self::borrar_carpeta($carpeta);

                    return self::respuesta(422, [
                        'message' => 'No se pudo procesar la imagen "' . $nombre_original . '". Probá con otra foto.',
                    ]);
                }

                $path = $carpeta . '/' . $orden_de_la_imagen . '.webp';

                /* Disco 'local' (privado): una factura es información fiscal, nunca va al disco público. */
                Storage::disk('local')->put($path, $binario);

                $guardadas[] = [
                    'orden'           => $orden_de_la_imagen,
                    'path'            => $path,
                    'mime'            => 'image/webp',
                    'bytes'           => strlen($binario),
                    'nombre_original' => $nombre_original,
                ];

                $orden_de_la_imagen++;
            }

            $scan = ProviderOrderScan::create([
                'uuid'              => $uuid,
                'user_id'           => $owner_id,
                /*
                 * Quién disparó el escaneo. El canal global_notification.{owner_id} lo escuchan
                 * TODOS los empleados: sin este id, el aviso de "terminó" le llegaría también a
                 * los compañeros que no sacaron ninguna foto.
                 */
                'auth_user_id'      => is_null($persona) ? null : $persona->id,
                'provider_order_id' => $orden->id,
                'estado'            => 'pendiente',
                'progreso'          => 0,
            ]);

            foreach ($guardadas as $datos) {

                ProviderOrderScanImage::create([
                    'provider_order_scan_id' => $scan->id,
                    /* Desnormalizados a propósito: ver §1.2 del plan del escaneo. */
                    'provider_order_id'      => $orden->id,
                    'user_id'                => $owner_id,
                    'orden'                  => $datos['orden'],
                    'path'                   => $datos['path'],
                    'mime'                   => $datos['mime'],
                    'bytes'                  => $datos['bytes'],
                    'nombre_original'        => $datos['nombre_original'],
                ]);
            }

            /*
             * Solo el id, no el modelo (mismo criterio que RunExcelAnalysisJob).
             *
             * 🔴 `afterCommit()` EXPLÍCITO, Y NO SE SACA. Por la pantalla este alta corre sin
             * ninguna transacción abierta, pero desde el asistente la cadena es
             * ConfirmacionPorTextoIaHelper::confirmar → EjecutorAccionesIaHelper::confirmar →
             * DB::transaction → acá adentro. Sin esto, un worker libre puede tomar el job ANTES
             * del commit, no encontrar el scan y volver con un warning
             * (RunProviderOrderScanJob:47-53): la compra queda creada, el dueño lee "estoy leyendo
             * la factura" y la factura no se lee nunca. Falla muda.
             *
             * Va acá en el código y no confiado al default de config porque el default DEPENDE DE
             * LA CONEXIÓN que tenga cada cliente: `config/queue.php` tiene `after_commit => true`
             * solo en `database` (:70); en `redis` (:105), que es lo que corren los clientes del
             * VPS, está en `false`. Un arreglo que ande en una conexión y no en la otra no es un
             * arreglo. Sin transacción abierta, `afterCommit()` despacha igual, de inmediato: la
             * pantalla no cambia de comportamiento.
             */
            RunProviderOrderScanJob::dispatch($scan->id)->afterCommit();

            return self::respuesta(202, [
                'uuid'              => $scan->uuid,
                'estado'            => $scan->estado,
                'provider_order_id' => $orden->id,
                'cantidad_imagenes' => count($guardadas),
            ]);

        } catch (\Throwable $e) {

            /* Si algo falló a mitad, no dejamos fotos huérfanas ocupando disco. */
            self::borrar_carpeta($carpeta);

            Log::error('ProviderOrderScanAltaHelper::crear - error al encolar el escaneo', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return self::respuesta(500, [
                'message' => 'Ocurrió un error inesperado al iniciar el escaneo: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Los bytes de una imagen, venga como UploadedFile o ya leída.
     *
     * @param  mixed  $imagen
     * @return string
     */
    protected static function binario_de($imagen)
    {
        if (is_array($imagen)) {

            return isset($imagen['binario']) ? (string) $imagen['binario'] : '';
        }

        return (string) file_get_contents($imagen->getRealPath());
    }

    /**
     * El nombre con el que se guarda la fila. Lo que el usuario ve si tiene que reclamar por una
     * foto puntual, así que una imagen sin nombre lleva uno que al menos dice de dónde salió.
     *
     * @param  mixed  $imagen
     * @return string
     */
    protected static function nombre_original($imagen)
    {
        if (is_array($imagen)) {

            $nombre = isset($imagen['nombre_original']) ? trim((string) $imagen['nombre_original']) : '';

            return $nombre === '' ? 'foto.webp' : $nombre;
        }

        return (string) $imagen->getClientOriginalName();
    }

    /**
     * Redimensiona al lado mayor configurado y re-encodea como webp.
     *
     * 1568 px, no 512 como la validación de imágenes de producto: para leer un código de artículo
     * de 8 caracteres impreso chico hace falta resolución. Es además el lado máximo que Anthropic
     * procesa sin downsamplear.
     *
     * @param  string  $binario
     * @return string|null  Binario webp, o null si Intervention no pudo procesar la imagen.
     */
    protected static function redimensionar_a_webp($binario)
    {
        $max_side = (int) config('services.escaneo_factura_compra.max_side', 1568);
        $max_side = $max_side > 0 ? $max_side : 1568;

        if ((string) $binario === '') {

            return null;
        }

        try {

            $manager = new ImageManager();
            $img     = $manager->make($binario);

            $img->resize($max_side, $max_side, function ($constraint) {
                $constraint->aspectRatio();
                /* upsize evita agrandar una foto que ya es más chica: solo agregaría peso. */
                $constraint->upsize();
            });

            $encoded = (string) $img->encode('webp', 80);

        } catch (\Throwable $e) {

            Log::info('ProviderOrderScanAltaHelper: Intervention no pudo procesar la imagen -- ' . $e->getMessage());

            return null;
        }

        return $encoded === '' ? null : $encoded;
    }

    /**
     * Borra la carpeta de fotos de un escaneo que no llegó a crearse.
     *
     * @param  string  $carpeta
     * @return void
     */
    protected static function borrar_carpeta($carpeta)
    {
        try {

            Storage::disk('local')->deleteDirectory($carpeta);

        } catch (\Throwable $e) {

            Log::info('ProviderOrderScanAltaHelper: no se pudo limpiar ' . $carpeta . ' -- ' . $e->getMessage());
        }
    }

    /**
     * @param  int  $status
     * @param  array  $body
     * @return array
     */
    protected static function respuesta($status, array $body)
    {
        return [
            'status' => (int) $status,
            'body'   => $body,
        ];
    }
}
