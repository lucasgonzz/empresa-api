<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\Log;

/**
 * 🔴 NINGÚN LINK A UN ARCHIVO DEL AGENTE QUE NO EXISTA (misión asistente-diario-20261119, rama A, A2).
 *
 * EL CASO REAL (Ferre Total, 18/11/2026, conv. 72, msgs 697-709). Pablo pidió dos Excel «a medida» (145
 * esmaltes con categoría, subcategoría y margen; después, el nombre actual al lado de cómo quedaría). El
 * asistente le mandó dos links `/asistente/archivo/<uuid>?t=…` que dan 404 (medido el 19/11: `6dae5055…` y
 * `53cbda62…`), mientras que los otros dos links de esa misma charla bajaban bien. «No me abre», «No lo puedo
 * abrir»; y en 709 dijo «te lo vuelvo a armar» y mandó el MISMO link roto.
 *
 * LO QUE SE MIDIÓ EN EL CÓDIGO (ArchivosDelAgenteIaHelper): el link se arma recién DESPUÉS de escribir el
 * archivo en el disco y de crear su fila en `ai_archivos`; si cualquiera de las dos cosas falla, la
 * herramienta devuelve error y nunca un link. No hay transacción que deshaga la fila, ni otra rutina que la
 * borre antes de los 7 días (solo el comando diario de vencidos). La descarga contesta 404 si no hay fila con
 * ese uuid, si el token no coincide con el hash guardado, si venció o si el archivo no está en el disco. Los
 * dos links rotos son justo los de los Excel que el modelo tenía que escribir fila por fila con exportar_excel
 * (145 filas en el input de la herramienta), en turnos que se cortaron por tiempo (705: «cURL error 28» en la
 * vuelta 3) o que retomaron con «seguí»: el link que llegó al dueño no lo devolvió ninguna herramienta.
 * 🔴 LA PRUEBA, en los mismos links: el token lo arma Str::random(48), que solo da letras y números (base64
 * sin `/`, `+` ni `=`). Los dos tokens rotos tienen un `_` (`…YAPcIit_83WL…`, el de 6dae5055) y un `-`
 * (`…2vw2l9l-qhzs…`, el de 53cbda62): ninguna herramienta pudo haberlos devuelto, los escribió el modelo. Los
 * cuatro links de esa charla que sí abrían tienen tokens de letras y números. Un link inventado es
 * indistinguible de uno real para quien lo lee.
 *
 * LA DEFENSA, en el texto final y sin depender del modelo: cada link a `/asistente/archivo/` que va a salir
 * se busca en `ai_archivos` con la misma regla que la descarga (para_descargar(): uuid, token, vigencia,
 * carpeta del dueño y archivo en el disco) y, además, tiene que ser DE ESTE NEGOCIO. Si no abriría, se saca
 * del texto y en su lugar va un aviso honesto. Un link válido que se repite (el mismo Excel de antes) se deja:
 * abre.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class LinksDeArchivosIaHelper
{
    /**
     * Lo que queda en el lugar de un link que no abriría. Es para la persona: dice qué pasó y qué hacer.
     */
    const AVISO = '(este link no quedó bien armado y no te iba a abrir; pedime de nuevo el archivo y te lo armo)';

    /**
     * Los links a archivos del agente: con o sin `https://` y dominio, el uuid y, si viene, el `?t=` del
     * token. El real es de Str::random (letras y números), pero se toman también `_` y `-` para que un token
     * inventado con esos signos (los dos de Ferre Total los tenían) salga ENTERO y no quede la cola pegada al
     * aviso. Sin `^`/`$`: puede estar en el medio de una oración, entre asteriscos de WhatsApp o entre
     * paréntesis.
     */
    const PATRON = '~(?:https?://)?(?:[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+(?::\d+)?)?/?' . 'asistente/archivo/([0-9A-Fa-f\-]{36})(?:\?t=([A-Za-z0-9_\-]*))?~';

    /**
     * Saca del texto los links a archivos del agente que no abrirían.
     *
     * @param  int  $owner_id  El dueño de la conversación (el archivo tiene que ser suyo).
     * @param  string  $texto  El texto final que va a salir.
     * @return array{texto: string, quitados: array<int, string>}  El texto (igual si no había nada que sacar) y
     *               los uuid de los links sacados.
     */
    public static function sin_links_que_no_abren($owner_id, $texto)
    {
        $texto = (string) $texto;

        // Rápido: la gran mayoría de las respuestas no tienen ningún link de archivo.
        if (strpos($texto, 'asistente/archivo/') === false) {

            return ['texto' => $texto, 'quitados' => []];
        }

        $quitados = [];

        $resultado = preg_replace_callback(self::PATRON, function ($coincidencia) use ($owner_id, &$quitados) {

            $uuid = $coincidencia[1];
            $token = isset($coincidencia[2]) ? $coincidencia[2] : null;

            if (self::abre((int) $owner_id, $uuid, $token)) {

                return $coincidencia[0];
            }

            $quitados[] = $uuid;

            return self::AVISO;

        }, $texto);

        if (is_null($resultado)) {

            // Un error del motor de expresiones no puede tumbar la respuesta: sale como estaba.
            return ['texto' => $texto, 'quitados' => []];
        }

        if (count($quitados)) {

            Log::warning('LinksDeArchivosIaHelper: se sacó de la respuesta un link de archivo que no abre (no lo devolvió ninguna herramienta o se copió mal).', [
                'owner_id' => (int) $owner_id,
                'uuids'    => $quitados,
            ]);
        }

        return ['texto' => $resultado, 'quitados' => $quitados];
    }

    /**
     * true si el link abriría para quien lo toca: la regla de la descarga (para_descargar()) y, además, que el
     * archivo sea de este negocio. Sin la tabla (instalación a mitad de upgrade) ningún link abre.
     *
     * @param  int  $owner_id
     * @param  string  $uuid
     * @param  string|null  $token
     * @return bool
     */
    public static function abre($owner_id, $uuid, $token)
    {
        try {

            $archivo = ArchivosDelAgenteIaHelper::para_descargar($uuid, $token);

        } catch (\Throwable $e) {

            // Ante la duda no se borra nada: el control es una defensa, no puede romper la respuesta.
            Log::warning('LinksDeArchivosIaHelper: no se pudo verificar un link de archivo; se deja.', [
                'owner_id' => (int) $owner_id,
                'error'    => $e->getMessage(),
            ]);

            return true;
        }

        return !is_null($archivo) && (int) $archivo->user_id === (int) $owner_id;
    }
}
