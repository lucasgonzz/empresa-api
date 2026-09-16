<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Las fotos que el dueño le manda al asistente por WhatsApp (misión asistente-por-whatsapp, §3.4
 * del plan, 16/9/2026): validarlas, redimensionarlas, guardarlas y volver a leerlas para mandarlas
 * a Anthropic.
 *
 * 🔴 LOS TOPES SON LOS MISMOS QUE LOS DEL ESCANEO DE FACTURAS, y salen de la misma config. No es
 * prolijidad: el destino más importante de estas fotos ES el escaneo de facturas
 * (proponer_compra_con_factura las engancha a una compra). Si acá entrara una foto que el escaneo
 * rechaza, el dueño mandaría la factura, el asistente le diría que la cargó y el escaneo la
 * rebotaría después, sin que nadie se lo cuente. Es la clase "el mismo invariante con dos
 * criterios" de APRENDER_NO_PARCHEAR.md.
 *
 * El binario va al disco **local** (privado), nunca al público: una factura de proveedor lleva
 * CUIT, razón social y precios de compra. La ruta es
 * `asistente_imagenes/{user_id}/{ai_message_id}/{orden}.webp`.
 */
class AsistenteImagenHelper
{
    /**
     * Fotos por mensaje. Es el mismo tope que usa el recolector de imágenes del soporte en el
     * admin (SupportAiImageCollector): más de tres fotos en un solo mensaje de WhatsApp no es un
     * caso real, y cada una que entra viaja a Anthropic en base64.
     */
    const MAX_IMAGENES = 3;

    /**
     * Formatos aceptados, nombrados para el mensaje de error. La validación NO los mira: mira los
     * bytes (ver motivo_de_rechazo()).
     */
    const FORMATOS_ACEPTADOS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Valida el lote antes de tocar nada: cuántas son, que cada una haya subido bien, el peso y que
     * sea de verdad una imagen que Anthropic acepte. Devuelve el motivo del rechazo (texto para el
     * 422) o null si está todo bien.
     *
     * Se valida ANTES de crear el mensaje a propósito: un 422 que llega con el AiMessage ya creado
     * dejaría en la conversación un mensaje del dueño que nadie va a contestar.
     *
     * 🔴 SE VALIDA POR LOS BYTES, NUNCA POR LA EXTENSIÓN DEL NOMBRE. Acá el que sube el archivo no
     * es un navegador con un <input type="file">: es el admin reenviando lo que bajó de Kapso, y
     * una descarga de media de WhatsApp normalmente llega SIN extensión en el nombre. Validando por
     * `getClientOriginalExtension()`, un JPEG perfecto daba 422 y el dueño recibía el texto de
     * disculpa por una foto que estaba impecable. `getimagesizefromstring` lee la firma del
     * archivo, que es el mismo criterio con el que después se arma el bloque de Anthropic
     * (media_type()): un solo criterio de punta a punta.
     *
     * El peso se chequea ANTES de leer el binario, para no traer a memoria un archivo enorme solo
     * para descubrir que no entraba.
     *
     * @param  array  $imagenes  UploadedFile[]
     * @return string|null
     */
    public static function motivo_de_rechazo(array $imagenes)
    {
        if (count($imagenes) > self::MAX_IMAGENES) {

            return 'Se pueden mandar hasta ' . self::MAX_IMAGENES . ' fotos por mensaje.';
        }

        $max_mb = self::max_mb();

        foreach ($imagenes as $imagen) {

            if (is_null($imagen) || !$imagen->isValid()) {

                return 'Una de las fotos no llegó completa.';
            }

            $nombre = self::nombre_para_el_error($imagen);

            if ($imagen->getSize() > ($max_mb * 1024 * 1024)) {

                return 'El archivo ' . $nombre . ' pesa más de ' . $max_mb . ' MB.';
            }

            try {

                $binario = (string) file_get_contents($imagen->getRealPath());

            } catch (\Throwable $e) {

                Log::info('AsistenteImagenHelper: no se pudo leer el archivo subido -- ' . $e->getMessage());

                return 'Una de las fotos no llegó completa.';
            }

            if (is_null(self::media_type($binario))) {

                return 'El archivo ' . $nombre . ' no es una imagen que pueda leer. ' .
                       'Se aceptan: ' . implode(', ', self::FORMATOS_ACEPTADOS) . '.';
            }
        }

        return null;
    }

    /**
     * Cómo se nombra un archivo en un mensaje de error. Una descarga de Kapso puede llegar sin
     * nombre, y decir 'El archivo "" pesa más de 12 MB' no le sirve a nadie.
     *
     * @param  mixed  $imagen
     * @return string
     */
    protected static function nombre_para_el_error($imagen)
    {
        $nombre = trim((string) $imagen->getClientOriginalName());

        return $nombre === '' ? 'que mandaste' : '"' . $nombre . '"';
    }

    /**
     * Guarda las fotos de un mensaje ya creado y deja una fila por cada una.
     *
     * 🔴 Una foto que Intervention no puede procesar se SALTEA, no voltea el mensaje. El texto del
     * dueño ("esto es la compra de Distribuidora Sur") vale por sí solo y el asistente puede
     * contestar que no le llegó la foto; abortar el mensaje entero por una imagen rota lo dejaría
     * sin ninguna respuesta y sin saber por qué.
     *
     * @param  \App\Models\AiMessage  $mensaje  El 'user' recién creado.
     * @param  array  $imagenes  UploadedFile[], ya validados por motivo_de_rechazo().
     * @param  int  $owner_id
     * @return int  Cuántas quedaron guardadas.
     */
    public static function guardar(AiMessage $mensaje, array $imagenes, $owner_id)
    {
        $carpeta = 'asistente_imagenes/' . (int) $owner_id . '/' . (int) $mensaje->id;
        $orden   = 1;

        foreach ($imagenes as $imagen) {

            try {

                $binario = self::redimensionar_a_webp(file_get_contents($imagen->getRealPath()));

            } catch (\Throwable $e) {

                Log::info('AsistenteImagenHelper: no se pudo leer el archivo subido -- ' . $e->getMessage());

                $binario = null;
            }

            if (is_null($binario)) {

                continue;
            }

            $path = $carpeta . '/' . $orden . '.webp';

            try {

                /* Disco 'local' (privado): una factura es información fiscal, nunca va al público. */
                Storage::disk('local')->put($path, $binario);

                AiMessageImagen::create([
                    'ai_message_id' => $mensaje->id,
                    'user_id'       => (int) $owner_id,
                    'orden'         => $orden,
                    'path'          => $path,
                    'mime'          => 'image/webp',
                    'bytes'         => strlen($binario),
                ]);

            } catch (\Throwable $e) {

                Log::warning('AsistenteImagenHelper: no se pudo guardar una foto del asistente', [
                    'ai_message_id' => $mensaje->id,
                    'error'         => $e->getMessage(),
                ]);

                continue;
            }

            $orden++;
        }

        return $orden - 1;
    }

    /**
     * El binario de una foto guardada, o null si el archivo ya no está.
     *
     * @param  \App\Models\AiMessageImagen  $imagen
     * @return string|null
     */
    public static function binario(AiMessageImagen $imagen)
    {
        try {

            if (!Storage::disk('local')->exists($imagen->path)) {

                return null;
            }

            $binario = Storage::disk('local')->get($imagen->path);

        } catch (\Throwable $e) {

            Log::warning('AsistenteImagenHelper: no se pudo leer una foto del asistente', [
                'ai_message_imagen_id' => $imagen->id,
                'error'                => $e->getMessage(),
            ]);

            return null;
        }

        return $binario === '' ? null : $binario;
    }

    /**
     * El media_type de un binario, leído DE LOS BYTES y no de la columna.
     *
     * 🔴 Es lo que ya hace SupportAiImageCollector en el admin, y por un motivo concreto: si la
     * columna dijera `image/webp` y el archivo fuera otra cosa, Anthropic rebota el bloque entero
     * con un 400 que no nombra la imagen. Los bytes no mienten. Null si no es una imagen que
     * Anthropic acepte.
     *
     * @param  string  $binario
     * @return string|null
     */
    public static function media_type($binario)
    {
        $info = @getimagesizefromstring($binario);

        if ($info === false || !isset($info['mime'])) {

            return null;
        }

        $mime = (string) $info['mime'];

        /* Los cuatro que acepta la API de Anthropic. Cualquier otro no se manda. */
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {

            return null;
        }

        return $mime;
    }

    /**
     * Sella como gestionadas las fotos que una carga ya usó, para que no se enganchen dos veces.
     *
     * @param  array<int,int>  $ids
     * @return int  Cuántas se sellaron.
     */
    public static function marcar_gestionadas(array $ids)
    {
        if (!count($ids)) {

            return 0;
        }

        return AiMessageImagen::whereIn('id', $ids)
                                ->whereNull('gestionada_at')
                                ->update(['gestionada_at' => now()]);
    }

    /**
     * Peso máximo de una foto, en MB. Misma config que el escaneo de facturas.
     *
     * @return int
     */
    protected static function max_mb()
    {
        $max_mb = (int) config('services.escaneo_factura_compra.max_mb', 12);

        return $max_mb > 0 ? $max_mb : 12;
    }

    /**
     * Redimensiona al lado mayor configurado y re-encodea como webp. Copia literal del criterio de
     * ProviderOrderScanController::redimensionar_a_webp(): 1568 px es el lado máximo que Anthropic
     * procesa sin downsamplear, y para leer un código de artículo impreso chico hace falta esa
     * resolución.
     *
     * @param  string  $binario
     * @return string|null  Binario webp, o null si Intervention no pudo procesar la imagen.
     */
    protected static function redimensionar_a_webp($binario)
    {
        $max_side = (int) config('services.escaneo_factura_compra.max_side', 1568);
        $max_side = $max_side > 0 ? $max_side : 1568;

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

            Log::info('AsistenteImagenHelper: Intervention no pudo procesar la imagen -- ' . $e->getMessage());

            return null;
        }

        return $encoded === '' ? null : $encoded;
    }
}
