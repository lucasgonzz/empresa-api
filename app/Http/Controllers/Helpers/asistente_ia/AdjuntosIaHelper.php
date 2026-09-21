<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;

/**
 * Los adjuntos de una respuesta del asistente: hoy, las fotos de los artículos que el dueño
 * pidió ver (misión asistente-omnisciente, §1 y §3 del contrato, 21/9/2026).
 *
 * Es la respuesta a "mostrame la foto", que antes de esta misión no tenía cómo contestarse: la
 * foto existía solo en la tarjeta de hover de una mención, y por WhatsApp ni eso. Ahora la tool
 * `mostrar_imagenes_de_articulos` (la registra AsistenteIaService, contrato §3) devuelve en su
 * tool_result la clave `adjuntos_de_la_respuesta`, el servicio la recolecta, el job la guarda en
 * `ai_messages.adjuntos` y de ahí la leen la SPA (miniaturas debajo del texto) y el admin (una
 * imagen de WhatsApp por adjunto, con su epígrafe).
 *
 * 🔴 A DIFERENCIA DE LAS MENCIONES, NO SE CRUZAN CONTRA EL TEXTO. Una mención equivocada manda al
 * dueño a la ficha de otro artículo, por eso allá el criterio es la precisión y se descarta ante
 * la duda. Acá la foto la pidió el modelo llamando a la tool con ids que otra tool le devolvió, y
 * se confirma contra la base del dueño antes de salir: si después escribe "acá está" o no escribe
 * nada, la imagen viaja igual. Lo que sí se filtra es todo lo que no cumple la forma del contrato
 * (`normalizar()`): lo que llega a la columna es exactamente lo que la SPA y el admin saben pintar.
 */
class AdjuntosIaHelper
{
    /** El único tipo que hoy saben dibujar la SPA y el admin (contrato §1). Un tipo nuevo se agrega acá. */
    const TIPO_IMAGEN = 'imagen';

    /**
     * Tope de adjuntos por mensaje (contrato §1). Es un tope de PRODUCTO y no técnico: seis fotos
     * en un globo del chat ya es una galería, y por WhatsApp son seis mensajes de imagen seguidos
     * detrás del texto. El API recorta; la SPA pinta lo que llega.
     */
    const MAX_ADJUNTOS = 6;

    /** Largo máximo del epígrafe (contrato §1). */
    const MAX_TEXTO = 200;

    /**
     * Las imágenes de los artículos que pidió el modelo, como tool_result (contrato §3).
     *
     * 🔴 SOLO ARTÍCULOS DEL DUEÑO, y un id ajeno no aparece en NINGUNA de las tres listas — ni
     * siquiera en `sin_imagen`—: decir "el artículo 8123 no tiene foto" sobre un id de otro
     * comercio ya es confirmar que existe. Es el mismo criterio de la ficha de hover
     * (FichaArticuloIaHelper::ficha, que contesta 404 sin distinguir "no existe" de "no es tuyo").
     *
     * Los pausados entran a propósito: si el dueño pide la foto de un artículo que pausó, la foto
     * existe y es suya. Los borrados no: SoftDeletes los deja afuera solo.
     *
     * @param  int  $owner_id  Dueño de la cuenta (articles.user_id).
     * @param  array  $articulo_ids  Ids que el modelo obtuvo de otra tool. Se toman los primeros MAX_ADJUNTOS.
     * @return array{articulos: array, sin_imagen: array, adjuntos_de_la_respuesta: array, como_sigo: string}
     */
    public static function imagenes_de_articulos(int $owner_id, array $articulo_ids): array
    {
        $ids = self::ids_limpios($articulo_ids);

        $recortados = count($ids) > self::MAX_ADJUNTOS;

        if ($recortados) {
            $ids = array_slice($ids, 0, self::MAX_ADJUNTOS);
        }

        $articulos = [];
        $sin_imagen = [];
        $adjuntos = [];

        if (count($ids)) {

            /*
             * sinEmbedding(): la columna `embedding` son 1536 floats (~29 KB por fila) que
             * $hidden no ahorra. Para seis fotos no hay por qué hidratar 180 KB de vectores.
             * `images` va con `with` porque getFirstImage() recorre la relación.
             */
            $modelos = Article::sinEmbedding()
                ->where('user_id', $owner_id)
                ->whereIn('id', $ids)
                ->with('images')
                ->get()
                ->keyBy('id');

            // En el orden en que los pidió el modelo, no en el que los devolvió la base.
            foreach ($ids as $id) {

                if (!isset($modelos[$id])) {
                    continue;
                }

                $article = $modelos[$id];

                $url = ArticleHelper::getFirstImage($article);

                $tiene_imagen = is_string($url) && self::es_url_absoluta($url);

                $articulos[] = [
                    'articulo_id'  => (int) $article->id,
                    'nombre'       => (string) $article->name,
                    'tiene_imagen' => $tiene_imagen,
                ];

                if (!$tiene_imagen) {

                    $sin_imagen[] = [
                        'articulo_id' => (int) $article->id,
                        'nombre'      => (string) $article->name,
                    ];

                    continue;
                }

                $adjuntos[] = [
                    'tipo'        => self::TIPO_IMAGEN,
                    'url'         => $url,
                    'texto'       => (string) $article->name,
                    'articulo_id' => (int) $article->id,
                ];
            }
        }

        $como_sigo = 'Las imágenes ya quedan adjuntas a tu respuesta: no pongas la URL en el texto.';

        if ($recortados) {
            $como_sigo .= ' Pediste más de ' . self::MAX_ADJUNTOS . ' artículos: se muestran los primeros '
                . self::MAX_ADJUNTOS . '; si la persona quiere ver el resto, mostralos en otra respuesta.';
        }

        if (count($sin_imagen)) {
            $como_sigo .= ' Los de `sin_imagen` no tienen foto cargada: decíselo a la persona.';
        }

        return [
            'articulos'                => $articulos,
            'sin_imagen'               => $sin_imagen,
            'adjuntos_de_la_respuesta' => self::normalizar($adjuntos),
            'como_sigo'                => $como_sigo,
        ];
    }

    /**
     * Los adjuntos con la forma exacta del contrato §1, y nada más: lista de `{tipo, url, texto,
     * articulo_id}` con las claves en ese orden, tope MAX_ADJUNTOS, o `[]`.
     *
     * Lo usan el accessor y el mutator del modelo (así los tres lugares por donde viaja un mensaje
     * sirven lo mismo) y AsistenteIaService al recolectar lo que devuelve una tool. Defensiva a
     * propósito: la columna puede traer null (todo mensaje anterior a la misión), un JSON inválido
     * o un array ya decodificado, y una tool podría devolver cualquier cosa.
     *
     * Lo que se descarta, y por qué:
     *   - `tipo` que no sea 'imagen': la SPA ignora tipos que no conoce, pero guardarlos sería
     *     guardar algo que nadie pinta.
     *   - `url` que no sea string absoluta http(s): el admin la manda tal cual a Meta como
     *     `image.link`, y una ruta relativa o un `data:` es un mensaje que rebota.
     *   - `texto` se recorta a MAX_TEXTO y vacío queda null (es un epígrafe, no un campo obligatorio).
     *   - `articulo_id` se castea a int y 0 o negativo queda null.
     *
     * @param  mixed  $valor
     * @return array<int, array<string, mixed>>
     */
    public static function normalizar($valor): array
    {
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        if (!is_array($valor)) {

            return [];
        }

        $adjuntos = [];

        foreach ($valor as $adjunto) {

            if (!is_array($adjunto) || !isset($adjunto['tipo']) || !isset($adjunto['url'])) {
                continue;
            }

            if ((string) $adjunto['tipo'] !== self::TIPO_IMAGEN) {
                continue;
            }

            $url = $adjunto['url'];

            if (!is_string($url) || !self::es_url_absoluta($url)) {
                continue;
            }

            $texto = null;

            if (isset($adjunto['texto']) && is_scalar($adjunto['texto']) && !is_bool($adjunto['texto'])) {

                $texto = trim((string) $adjunto['texto']);

                $texto = $texto === '' ? null : mb_substr($texto, 0, self::MAX_TEXTO);
            }

            $articulo_id = null;

            if (isset($adjunto['articulo_id']) && is_numeric($adjunto['articulo_id']) && (int) $adjunto['articulo_id'] > 0) {
                $articulo_id = (int) $adjunto['articulo_id'];
            }

            $adjuntos[] = [
                'tipo'        => self::TIPO_IMAGEN,
                'url'         => trim($url),
                'texto'       => $texto,
                'articulo_id' => $articulo_id,
            ];

            if (count($adjuntos) >= self::MAX_ADJUNTOS) {
                break;
            }
        }

        return $adjuntos;
    }

    /**
     * Los adjuntos como los ve el admin (contrato §2): `{tipo, url, texto}`, sin `articulo_id`.
     * El admin manda una imagen por adjunto con el texto de epígrafe; el id no lo necesita y no
     * tiene por qué viajar a otro sistema.
     *
     * @param  mixed  $adjuntos  Lo que devuelve el accessor del modelo (o cualquier cosa: se normaliza).
     * @return array<int, array<string, mixed>>
     */
    public static function para_el_admin($adjuntos): array
    {
        $recortados = [];

        foreach (self::normalizar($adjuntos) as $adjunto) {

            $recortados[] = [
                'tipo'  => $adjunto['tipo'],
                'url'   => $adjunto['url'],
                'texto' => $adjunto['texto'],
            ];
        }

        return $recortados;
    }

    /**
     * true si la URL es absoluta y http(s). `data:`, rutas relativas y strings vacíos no pasan.
     *
     * @param  string  $url
     * @return bool
     */
    protected static function es_url_absoluta($url): bool
    {
        $url = trim((string) $url);

        return strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0;
    }

    /**
     * Ids enteros positivos, sin repetidos y en el orden en que vinieron.
     *
     * @param  array  $articulo_ids
     * @return array<int, int>
     */
    protected static function ids_limpios(array $articulo_ids): array
    {
        $ids = [];

        foreach ($articulo_ids as $id) {

            if (!is_numeric($id) || (int) $id <= 0) {
                continue;
            }

            $id = (int) $id;

            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
