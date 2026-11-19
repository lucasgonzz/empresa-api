<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageImagen;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * LA FOTO DEL DUEÑO EN UN TAMAÑO EXACTO (misión asistente-diario-20261119, rama C, M3, 19/11/2026).
 *
 * 🔴 EL CASO REAL (Truvari, conversación 42, mensajes 644-645). Fernando mandó la foto de un vino y
 * preguntó «Esta imagen la podés convertir en 500 px x 500 px?». El agente contestó que no tenía
 * herramienta para redimensionar y le ofreció regenerarla con IA, que cambia la botella y la etiqueta.
 * Ya se había pedido el 17/11 (informe del 18/11, M2) y seguía sin herramienta.
 *
 * La herramienta `ajustar_foto_a_medida` toma una foto de la conversación y la deja EXACTAMENTE en
 * ancho × alto píxeles, de una de dos formas:
 *   - `encuadrar` (la de siempre): la foto entera, sin deformar, centrada sobre un lienzo blanco del
 *     tamaño pedido. Lo que sobra a los costados (o arriba y abajo) queda blanco. Es lo que pide una
 *     tienda o un marketplace para una foto de producto: no se corta nada del envase.
 *   - `recortar`: llena el lienzo entero y recorta lo que sobra, centrado. Solo si la persona lo pide
 *     ("recortala", "que no quede blanco").
 *
 * Devuelve dos cosas:
 *   1. Un LINK para bajarla, por el mismo mecanismo que el Excel del agente (ArchivosDelAgenteIaHelper):
 *      archivo en el disco PRIVADO `asistente_archivos/{dueño}/{uuid}.jpg`, fila en `ai_archivos` con
 *      el sha256 del token, vencimiento de DIAS_DE_VIDA días y la limpieza diaria
 *      (`asistente:limpiar-archivos`, que ya borra todo lo vencido de esa carpeta). La ruta pública
 *      sirve el Content-Type por el tipo de la fila (AiArchivo::content_type()).
 *   2. Un `imagen_id` NUEVO: la foto a medida guardada como una fila más de `ai_message_imagenes`
 *      colgada del mensaje del ASISTENTE en curso (como la foto que encuentra la búsqueda por código
 *      de barras). Con ese id, proponer_foto_articulo la pone en un artículo que ya existe. Va colgada
 *      del asistente y no de un mensaje `user` a propósito: las herramientas que toman "la última foto
 *      que mandó la persona" filtran por rol `user`, y la foto a medida no tiene que ganarle a una foto
 *      nueva del dueño sin que el modelo la pida por su id (ver FotosDeLaConversacionIaHelper).
 *
 * 🔴 DE DÓNDE SALE LA FOTO. Solo de las conversaciones de esta persona en este canal
 * (FotosDeLaConversacionIaHelper::conversaciones_del_mismo_canal(), las últimas HORAS horas) y del dueño
 * de la cuenta: un `imagen_id` de otro negocio o de otra persona es "no está". Sin `imagen_id`, la más
 * nueva que mandó la persona (rol `user`), aunque ya se haya usado: ajustarla no la consume.
 *
 * ⚠️ LA CALIDAD. Las fotos del chat se guardan como webp con el lado mayor en 1568 px
 * (AsistenteImagenHelper). Pedir un tamaño más grande que la foto guardada la agranda: se hace igual
 * (es lo que pidió la persona) y la respuesta trae `agrandada` para que el modelo lo avise.
 *
 * Usa Intervention Image 2 (ya está en composer.json) con GD. Ninguna dependencia nueva.
 *
 * PHP 7.4: sin match, sin str_contains, sin nullsafe, sin argumentos nombrados, sin union types.
 */
class FotoAMedidaIaHelper
{
    /** Los dos modos. */
    const MODO_ENCUADRAR = 'encuadrar';
    const MODO_RECORTAR = 'recortar';
    const MODOS = [self::MODO_ENCUADRAR, self::MODO_RECORTAR];

    /** Los formatos del archivo del link. jpg por defecto (lo abre cualquier celular y cualquier tienda). */
    const FORMATO_JPG = 'jpg';
    const FORMATO_PNG = 'png';
    const FORMATOS = [self::FORMATO_JPG, self::FORMATO_PNG];

    /** El lado mínimo y máximo que se acepta, en píxeles. */
    const LADO_MINIMO = 16;
    const LADO_MAXIMO = 4000;

    /** El color del relleno al encuadrar. */
    const FONDO = '#ffffff';

    /** Calidad del jpg y del webp que se guardan. */
    const CALIDAD = 90;

    /**
     * La herramienta ajustar_foto_a_medida.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessage|null  $mensaje  El assistant en curso (sin él no hay imagen_id nuevo, solo el link).
     * @param  array  $input  ancho*, alto*, modo, formato, imagen_id
     * @return array
     */
    public static function ajustar(ContextoDeCargaIa $contexto, $mensaje, array $input)
    {
        $ancho = self::lado(EntradaDeCargaIa::valor($input, 'ancho'));
        $alto = self::lado(EntradaDeCargaIa::valor($input, 'alto'));

        if (is_null($ancho) || is_null($alto)) {

            return RespuestaDeCargaIa::error(
                'El ancho y el alto van en píxeles, enteros, entre ' . self::LADO_MINIMO . ' y ' . self::LADO_MAXIMO
                . ' (por ejemplo 500 y 500). Si la persona no dijo uno de los dos, preguntáselo.'
            );
        }

        $modo = mb_strtolower(EntradaDeCargaIa::texto($input, 'modo'));
        $modo = $modo === '' ? self::MODO_ENCUADRAR : $modo;

        if (!in_array($modo, self::MODOS, true)) {

            return RespuestaDeCargaIa::error('El modo es "encuadrar" (la foto entera con fondo blanco, el de siempre) o "recortar" (llena todo y recorta lo que sobra).');
        }

        $formato = mb_strtolower(EntradaDeCargaIa::texto($input, 'formato'));
        $formato = $formato === '' || $formato === 'jpeg' ? self::FORMATO_JPG : $formato;

        if (!in_array($formato, self::FORMATOS, true)) {

            return RespuestaDeCargaIa::error('El formato del archivo es "jpg" (el de siempre) o "png".');
        }

        $foto = self::foto($contexto, EntradaDeCargaIa::valor($input, 'imagen_id'));

        if (is_null($foto)) {

            return RespuestaDeCargaIa::error(
                'No encontré esa foto en esta conversación. Si la persona habla de una foto que mandó, pedile que la mande de nuevo; '
                . 'si me pasaste un imagen_id, tiene que ser de una foto de esta conversación de las últimas ' . FotosDeLaConversacionIaHelper::HORAS . ' horas.'
            );
        }

        $binario = AsistenteImagenHelper::binario($foto);

        if (is_null($binario)) {

            return RespuestaDeCargaIa::error('La foto ya no está guardada en el sistema: pedile a la persona que la mande de nuevo.');
        }

        $ajustada = self::ajustar_binario($binario, $ancho, $alto, $modo);

        if (is_null($ajustada)) {

            return RespuestaDeCargaIa::error('No pude procesar esa foto por una falla del sistema. Decíselo a la persona tal cual; no inventes otro motivo.');
        }

        $respuesta = [
            'ok'              => true,
            'ancho'           => $ancho,
            'alto'            => $alto,
            'modo'            => $modo,
            'imagen_original' => (int) $foto->id,
            'tamano_original' => $ajustada['ancho_original'] . ' × ' . $ajustada['alto_original'] . ' px',
        ];

        if ($ajustada['agrandada']) {

            $respuesta['agrandada'] = true;
        }

        // 1. La foto a medida como imagen de la conversación, para usarla en un artículo.
        $nueva = self::guardar_en_la_conversacion($mensaje, $contexto->owner_id, $ajustada['imagen']);

        if (!is_null($nueva)) {

            $respuesta['imagen_id'] = (int) $nueva->id;
        }

        // 2. El link para bajarla.
        $link = self::guardar_link($contexto, $mensaje, $ajustada['imagen'], $formato, $ancho, $alto);

        if (isset($link['error'])) {

            $respuesta['link'] = null;
            $respuesta['sin_link'] = $link['error'];

        } else {

            $respuesta['link'] = $link['link'];
            $respuesta['nombre_archivo'] = $link['nombre'];
            $respuesta['vence'] = $link['vence'];
        }

        $respuesta['nota'] = self::nota($respuesta);

        return $respuesta;
    }

    /**
     * El binario de imagen ajustado a ancho × alto, o null si no se pudo leer. Devuelve la imagen de
     * Intervention ya armada (para codificarla en el formato que haga falta) y el tamaño de origen.
     *
     * 🔴 Se mide el resultado: si el lienzo no quedó exactamente en ancho × alto, null (no se entrega
     * una foto "casi" del tamaño pedido).
     *
     * @param  string  $binario
     * @param  int  $ancho
     * @param  int  $alto
     * @param  string  $modo  encuadrar | recortar
     * @return array|null  ['imagen' => \Intervention\Image\Image, 'ancho_original', 'alto_original', 'agrandada']
     */
    public static function ajustar_binario($binario, $ancho, $alto, $modo)
    {
        try {

            $manager = new ImageManager();

            $foto = $manager->make((string) $binario);

            $ancho_original = (int) $foto->width();
            $alto_original = (int) $foto->height();

            if ($modo === self::MODO_RECORTAR) {

                /*
                 * fit(): escala para LLENAR el lienzo y recorta lo que sobra, centrado. El resultado ya es
                 * ancho × alto. Se pega igual sobre un lienzo blanco para que una foto con transparencia
                 * (png) no quede con fondo negro en el jpg.
                 */
                $foto->fit($ancho, $alto, null, 'center');

                $agrandada = $ancho > $ancho_original || $alto > $alto_original;

            } else {

                /*
                 * Encuadrar: escala para ENTRAR entera en el lienzo sin deformar (aspectRatio) y SÍ agranda
                 * si hace falta (sin upsize): 500 × 500 de una foto de 300 px tiene que llenar el lado
                 * que corresponde, no quedar chiquita en el medio.
                 */
                $foto->resize($ancho, $alto, function ($constraint) {
                    $constraint->aspectRatio();
                });

                $agrandada = $foto->width() > $ancho_original || $foto->height() > $alto_original;
            }

            $lienzo = $manager->canvas($ancho, $alto, self::FONDO);
            $lienzo->insert($foto, 'center');

            if ((int) $lienzo->width() !== (int) $ancho || (int) $lienzo->height() !== (int) $alto) {

                return null;
            }

            return [
                'imagen'         => $lienzo,
                'ancho_original' => $ancho_original,
                'alto_original'  => $alto_original,
                'agrandada'      => $agrandada,
            ];

        } catch (\Throwable $e) {

            Log::warning('FotoAMedidaIaHelper: no se pudo ajustar la foto.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * La foto de origen: por su id (de las conversaciones de esta persona en este canal y del dueño) o,
     * sin id, la más nueva que mandó la persona en las últimas HORAS horas. Usada o sin usar: ajustarla
     * no la consume.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  mixed  $imagen_id
     * @return AiMessageImagen|null
     */
    protected static function foto(ContextoDeCargaIa $contexto, $imagen_id)
    {
        if (!($contexto->conversation instanceof AiConversation)) {

            return null;
        }

        $conversaciones = FotosDeLaConversacionIaHelper::conversaciones_del_mismo_canal($contexto);

        $desde = Carbon::now()->subHours(FotosDeLaConversacionIaHelper::HORAS);

        $consulta = AiMessageImagen::where('user_id', (int) $contexto->owner_id)
            ->where('created_at', '>=', $desde)
            ->whereHas('message', function ($q) use ($conversaciones) {
                $q->whereIn('ai_conversation_id', $conversaciones);
            });

        $tiene_id = !EntradaDeCargaIa::vacio($imagen_id);

        if ($tiene_id) {

            if (!is_numeric($imagen_id) || (int) $imagen_id <= 0) {

                return null;
            }

            return $consulta->where('id', (int) $imagen_id)->first();
        }

        return $consulta->whereHas('message', function ($q) {
                $q->where('rol', 'user');
            })
            ->orderBy('ai_message_id', 'DESC')
            ->orderBy('orden', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Guarda la foto a medida colgada del mensaje del asistente en curso (webp, disco local, la misma
     * estructura de ruta que las demás) y devuelve la fila, o null sin mensaje o si falla. NO pasa por
     * AsistenteImagenHelper::guardar_binario(), que la achicaría a 1568 px: acá el tamaño es el pedido.
     *
     * @param  AiMessage|null  $mensaje
     * @param  int  $owner_id
     * @param  \Intervention\Image\Image  $imagen
     * @return AiMessageImagen|null
     */
    protected static function guardar_en_la_conversacion($mensaje, $owner_id, $imagen)
    {
        if (!($mensaje instanceof AiMessage) || is_null($mensaje->id)) {

            return null;
        }

        $path = null;

        try {

            $webp = (string) (clone $imagen)->encode('webp', self::CALIDAD);

            $orden = (int) AiMessageImagen::where('ai_message_id', $mensaje->id)->max('orden') + 1;
            $path = 'asistente_imagenes/' . (int) $owner_id . '/' . (int) $mensaje->id . '/' . $orden . '.webp';

            Storage::disk('local')->put($path, $webp);

            return AiMessageImagen::create([
                'ai_message_id' => (int) $mensaje->id,
                'user_id'       => (int) $owner_id,
                'orden'         => $orden,
                'path'          => $path,
                'mime'          => 'image/webp',
                'bytes'         => strlen($webp),
            ]);

        } catch (\Throwable $e) {

            Log::warning('FotoAMedidaIaHelper: no se pudo guardar la foto a medida en la conversación.', [
                'ai_message_id' => (int) $mensaje->id,
                'error'         => $e->getMessage(),
            ]);

            if (!is_null($path)) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (\Throwable $ignorada) {
                    // Nada: lo importante es contestarle al modelo.
                }
            }

            return null;
        }
    }

    /**
     * Guarda el archivo del link (disco privado, fila en ai_archivos con el sha256 del token) y devuelve
     * ['link', 'nombre', 'vence'] o ['error' => motivo para el modelo]. Las mismas reglas que el Excel
     * (ArchivosDelAgenteIaHelper): carpeta del dueño, token aleatorio, vencimiento y base pública.
     *
     * @param  ContextoDeCargaIa  $contexto
     * @param  AiMessage|null  $mensaje
     * @param  \Intervention\Image\Image  $imagen
     * @param  string  $formato  jpg | png
     * @param  int  $ancho
     * @param  int  $alto
     * @return array
     */
    protected static function guardar_link(ContextoDeCargaIa $contexto, $mensaje, $imagen, $formato, $ancho, $alto)
    {
        if (!Schema::hasTable('ai_archivos')) {

            return ['error' => 'Esta instalación todavía no tiene lista la parte de archivos del agente: no puedo armar el link.'];
        }

        $owner_id = (int) $contexto->owner_id;

        $base = LinkDePdfIaHelper::base_publica($owner_id);

        if ($base === '') {

            return ['error' => 'Este sistema no tiene cargada la dirección pública de su API, así que el link saldría sin dominio.'];
        }

        $uuid = (string) Str::uuid();
        $token = Str::random(ArchivosDelAgenteIaHelper::LARGO_DEL_TOKEN);
        $path = ArchivosDelAgenteIaHelper::CARPETA . '/' . $owner_id . '/' . $uuid . '.' . $formato;
        $nombre = 'foto-' . (int) $ancho . 'x' . (int) $alto . '.' . $formato;
        $vence = Carbon::now()->addDays(ArchivosDelAgenteIaHelper::DIAS_DE_VIDA);

        try {

            $binario = (string) (clone $imagen)->encode($formato, self::CALIDAD);

            Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->put($path, $binario);

            $conversation = $contexto->conversation;

            AiArchivo::create([
                'uuid'          => $uuid,
                'user_id'       => $owner_id,
                'auth_user_id'  => ($conversation instanceof AiConversation && !is_null($conversation->auth_user_id)) ? (int) $conversation->auth_user_id : null,
                'ai_message_id' => ($mensaje instanceof AiMessage) ? (int) $mensaje->id : null,
                'tipo'          => AiArchivo::TIPO_IMAGEN,
                'path'          => $path,
                'nombre'        => $nombre,
                'token_hash'    => hash('sha256', $token),
                'filas'         => 0,
                'expires_at'    => $vence,
            ]);

        } catch (\Throwable $e) {

            try {
                Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->delete($path);
            } catch (\Throwable $ignorada) {
                // Nada: lo importante es contestarle al modelo.
            }

            Log::warning('FotoAMedidaIaHelper: no se pudo armar el link de la foto a medida.', [
                'owner_id' => $owner_id,
                'error'    => $e->getMessage(),
            ]);

            return ['error' => 'No pude armar el link por una falla del sistema.'];
        }

        return [
            'link'   => $base . '/' . ArchivosDelAgenteIaHelper::RUTA . '/' . $uuid . '?t=' . $token,
            'nombre' => $nombre,
            'vence'  => $vence->format('d/m/Y'),
        ];
    }

    /**
     * La nota para el modelo: qué decir y qué sigue.
     *
     * @param  array  $respuesta
     * @return string
     */
    protected static function nota(array $respuesta)
    {
        $nota = 'La foto quedó en ' . $respuesta['ancho'] . ' × ' . $respuesta['alto'] . ' px exactos'
            . ($respuesta['modo'] === self::MODO_ENCUADRAR ? ', entera y sin deformar, con fondo blanco en lo que sobra' : ', recortada al centro')
            . '. ';

        if (!empty($respuesta['link'])) {

            $nota .= 'Pasale el link TAL CUAL a la persona, sin acortarlo: al tocarlo se abre la foto. Vale hasta el ' . $respuesta['vence'] . '. ';

        } else {

            $nota .= 'No hay link: ' . (isset($respuesta['sin_link']) ? $respuesta['sin_link'] : '') . ' Decíselo tal cual; no inventes un link. ';
        }

        if (isset($respuesta['imagen_id'])) {

            $nota .= 'Si la persona quiere ponerla en un artículo que ya existe, usá proponer_foto_articulo con imagen_id ' . $respuesta['imagen_id'] . ' (la ajustada, no la original). ';
        }

        if (!empty($respuesta['agrandada'])) {

            $nota .= 'La foto guardada era más chica (' . $respuesta['tamano_original'] . '), así que se agrandó y puede verse menos nítida: avisalo en una línea. ';
        }

        return $nota . 'Nunca digas que no podés cambiar el tamaño de una foto: con esta herramienta podés.';
    }

    /**
     * Un lado en píxeles, entero y dentro de los topes, o null.
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected static function lado($valor)
    {
        if (is_string($valor)) {

            // "500px", "500 px" → 500.
            $valor = trim(preg_replace('/\s*px$/i', '', trim($valor)));
        }

        if (!is_numeric($valor) || (float) $valor != (int) $valor) {

            return null;
        }

        $lado = (int) $valor;

        if ($lado < self::LADO_MINIMO || $lado > self::LADO_MAXIMO) {

            return null;
        }

        return $lado;
    }
}
