<?php

namespace App\Http\Controllers\Helpers;

use Intervention\Image\Image;
use Intervention\Image\ImageManager;

/**
 * ImageCropHelper
 *
 * Recorte de imagenes para el endpoint set-image, con soporte para un rectangulo de recorte que
 * SE SALE de la imagen.
 *
 * Por que existe. El modal de recorte del SPA tiene un marco fijo y deja alejar la imagen con la
 * rueda del mouse: una imagen apaisada (3:1) dentro de un marco 1:1 se puede alejar hasta que entre
 * entera, y lo que sobra arriba y abajo del marco queda vacio. Al guardar, el SPA manda el marco
 * como `left / top / width / height` en pixeles de la imagen original, y esos numeros pueden ser
 * negativos o pasarse del ancho / alto de la imagen.
 *
 * Que hace Intervention (driver GD) con eso: `Image::crop()` NO valida los bordes. Con un
 * rectangulo que se sale no falla: mantiene el tamano pedido y rellena de NEGRO lo que quedo afuera
 * (medido con una sonda el 24/9/2026, mision recorte-imagen-zoom-scroll). Una foto de producto con
 * franjas negras es un defecto; el estandar es fondo blanco (y en jpg / webp sin alfa un fondo
 * transparente terminaria igual en negro). Por eso el recorte con relleno se arma aca.
 *
 * Orientacion EXIF (orient_by_exif): la foto de un celular puede traer los pixeles "acostados" y una
 * etiqueta que dice como girarlos. El SPA recorta sobre la foto ya enderezada, asi que el servidor
 * tiene que enderezarla ANTES de aplicar el recorte (ver el docblock de ese metodo).
 *
 * Reglas del recorte, en orden:
 *   1. Los cuatro numeros se normalizan a enteros; si no son numeros o el ancho / alto no llega a
 *      1 pixel se lanza \InvalidArgumentException con un mensaje listo para mostrar al usuario.
 *   2. Si el rectangulo no toca la imagen (interseccion vacia) tambien se lanza la excepcion.
 *   3. CAMINO DE SIEMPRE: si el rectangulo esta dentro de la imagen, o se pasa hasta
 *      TOLERANCIA_PX pixeles por cualquier borde, se recorta contra los bordes de la imagen y se
 *      llama a `crop()` como se hacia antes de este helper. La tolerancia existe porque el SPA
 *      redondea `left` y `width` por separado y a veces sobra 1 pixel; sin ella esa sobra dejaba
 *      una linea negra en el borde de la foto.
 *   4. CAMINO CON RELLENO: si el rectangulo se pasa mas que la tolerancia, se arma un lienzo del
 *      tamano del rectangulo pedido, pintado de COLOR_RELLENO, y sobre el se pega la parte de la
 *      imagen que cae adentro del rectangulo. Si el lienzo pasa MAX_LADO_CON_RELLENO de lado, se
 *      achica todo proporcionalmente.
 *
 * Quien lo llama es ImageController::setImage(); la excepcion se traduce ahi a un 422.
 *
 * PHP 7.4: este archivo no usa nada de la sintaxis ni de las funciones nuevas de PHP 8.
 */
class ImageCropHelper
{
    /**
     * Cuantos pixeles puede pasarse el rectangulo de los bordes de la imagen y seguir yendo por el
     * camino de siempre (recorte contra los bordes, sin relleno).
     *
     * El redondeo del recorte que hace el SPA puede sobrar 1 pixel por un borde; con 2 hay margen
     * para el redondeo de los dos extremos. Mas que eso ya es un recorte hecho a proposito con el
     * marco afuera de la imagen.
     *
     * @var int
     */
    const TOLERANCIA_PX = 2;

    /**
     * Lado maximo, en pixeles, del lienzo del camino con relleno.
     *
     * Es un tope de memoria: GD guarda 4 bytes por pixel, asi que un lienzo de 2500 x 2500 son
     * ~25 MB (el memory_limit del CLI es de 128 M). Solo achica cuando hace falta; casi nunca, porque
     * el SPA limita el alejamiento a 2 veces el punto en que la imagen entra entera.
     *
     * @var int
     */
    const MAX_LADO_CON_RELLENO = 2500;

    /**
     * Color del espacio vacio, en hexadecimal (formato que entiende ImageManager::canvas()).
     *
     * Blanco porque es el estandar de la foto de producto (Tienda Nube y Mercado Libre convierten
     * o rechazan el fondo transparente). Cambiar esta constante cambia el relleno de todo el
     * sistema; el SPA lo dibuja en blanco en el modal, asi que si se cambia hay que avisarle.
     *
     * @var string
     */
    const COLOR_RELLENO = '#ffffff';

    /**
     * Cota de cordura para cada uno de los cuatro numeros del rectangulo, en pixeles.
     *
     * No es una regla de negocio: evita que un valor absurdo (1e20) desborde el entero al convertirlo
     * o al sumarlo. Una imagen real no llega ni cerca (el SPA manda como mucho el doble del lado de
     * la imagen mas el desplazamiento).
     *
     * @var int
     */
    const MAX_VALOR_ENTRADA = 1000000;

    /**
     * Margen sobre el tamano de la imagen decodificada (4 bytes por pixel) que se exige tener libre en
     * memoria antes de enderezarla: girar 90 grados necesita, mientras dura, la imagen original y su
     * copia girada. El 30 % cubre el resto de lo que el proceso tiene en vuelo.
     *
     * @var float
     */
    const FACTOR_MEMORIA_AL_ENDEREZAR = 1.3;

    /**
     * Cuantos bytes del principio de un JPEG se le dan a exif_read_data para leer la orientacion.
     *
     * El EXIF vive en el segmento APP1, que va pegado al inicio del archivo y mide como mucho 64 KB;
     * con 256 KB queda margen de sobra para un JPEG que traiga otros segmentos antes.
     *
     * @var int
     */
    const BYTES_PARA_LEER_EXIF = 262144;

    /**
     * Recorta la imagen con el rectangulo dado; si el rectangulo se sale de la imagen, la parte
     * sin imagen se rellena con COLOR_RELLENO.
     *
     * OJO: `$image` se modifica en el lugar (es la semantica de `Image::crop()` de Intervention) y,
     * en el camino con relleno, se descarta despues de copiarla al lienzo. Quien llama tiene que
     * quedarse con lo que devuelve este metodo y no volver a usar la imagen original.
     *
     * @param  \Intervention\Image\ImageManager $manager Manager con el driver GD; se usa para crear el lienzo.
     * @param  \Intervention\Image\Image        $image   Imagen de origen ya cargada.
     * @param  mixed $left   Borde izquierdo del rectangulo, en pixeles de la imagen (puede ser negativo). Entero, decimal o string numerico.
     * @param  mixed $top    Borde superior del rectangulo, en pixeles de la imagen (puede ser negativo).
     * @param  mixed $width  Ancho del rectangulo en pixeles (minimo 1; puede pasarse del ancho de la imagen).
     * @param  mixed $height Alto del rectangulo en pixeles (minimo 1; puede pasarse del alto de la imagen).
     * @return \Intervention\Image\Image La imagen recortada: la misma instancia de `$image` en el camino de siempre, un lienzo nuevo en el camino con relleno.
     * @throws \InvalidArgumentException Si los numeros no sirven o el rectangulo no toca la imagen. El mensaje esta escrito para mostrarse tal cual al usuario.
     */
    public static function crop(ImageManager $manager, Image $image, $left, $top, $width, $height)
    {
        // Los cuatro numeros como enteros (lanza la excepcion si alguno no es un numero usable).
        $rect_left   = self::normalize_number($left);
        $rect_top    = self::normalize_number($top);
        $rect_width  = self::normalize_number($width);
        $rect_height = self::normalize_number($height);

        // Un rectangulo sin ancho o sin alto no es un recorte.
        if ($rect_width < 1 || $rect_height < 1) {
            throw new \InvalidArgumentException(
                'El recorte que se envió no es válido: el ancho y el alto tienen que ser de al menos 1 píxel. Volvé a marcar el recorte e intentá de nuevo.'
            );
        }

        // Medidas de la imagen de origen. Cada width() / height() ejecuta un comando de Intervention, por eso se piden una sola vez.
        $image_width  = $image->width();
        $image_height = $image->height();

        // Los cuatro bordes del rectangulo pedido, en pixeles de la imagen (el borde derecho e inferior son exclusivos).
        $rect_right  = $rect_left + $rect_width;
        $rect_bottom = $rect_top + $rect_height;

        // Interseccion del rectangulo con la imagen: la parte de la imagen que realmente entra en el recorte.
        $visible = [
            'left'   => max($rect_left, 0),
            'top'    => max($rect_top, 0),
            'right'  => min($rect_right, $image_width),
            'bottom' => min($rect_bottom, $image_height),
        ];

        // Si la interseccion esta vacia el recorte cae afuera de la imagen: no hay nada que guardar.
        if ($visible['right'] <= $visible['left'] || $visible['bottom'] <= $visible['top']) {
            throw new \InvalidArgumentException(
                'El recorte no incluye ninguna parte de la imagen. Movela hasta que el marco quede sobre la imagen e intentá de nuevo.'
            );
        }

        // Cuantos pixeles se pasa el rectangulo por el borde que mas se pasa (0 si esta todo adentro).
        $overflow = max(
            $visible['left'] - $rect_left,
            $visible['top'] - $rect_top,
            $rect_right - $visible['right'],
            $rect_bottom - $visible['bottom']
        );

        // CAMINO DE SIEMPRE: adentro o pasandose solo por redondeo. Se recorta contra los bordes de la imagen.
        if ($overflow <= self::TOLERANCIA_PX) {
            return $image->crop(
                $visible['right'] - $visible['left'],
                $visible['bottom'] - $visible['top'],
                $visible['left'],
                $visible['top']
            );
        }

        // CAMINO CON RELLENO: el marco se sale de la imagen a proposito.
        return self::crop_with_fill($manager, $image, $rect_left, $rect_top, $rect_width, $rect_height, $visible);
    }

    /**
     * Convierte un valor que llego del request en un entero, o lanza la excepcion si no es un numero usable.
     *
     * Acepta enteros, decimales y strings numericos ('120', '12.5'). Rechaza lo que no es numero
     * (null, texto, arrays, booleanos), lo infinito / NaN y lo que pasa MAX_VALOR_ENTRADA en valor absoluto.
     * Los decimales se redondean al entero mas cercano: `Image::crop()` solo acepta enteros y el
     * navegador puede mandar decimales.
     *
     * @param  mixed $value Valor crudo del request.
     * @return int
     * @throws \InvalidArgumentException Con mensaje listo para mostrar.
     */
    private static function normalize_number($value)
    {
        // Solo numeros: null, '', 'abc', arrays y booleanos no lo son (is_numeric los rechaza).
        if (!is_numeric($value)) {
            throw self::invalid_number_exception();
        }

        // Valor como decimal, para poder mirar si es finito y que tan grande es antes de convertirlo a entero.
        $as_float = (float) $value;

        if (!is_finite($as_float) || abs($as_float) > self::MAX_VALOR_ENTRADA) {
            throw self::invalid_number_exception();
        }

        return (int) round($as_float);
    }

    /**
     * Excepcion de "alguna de las medidas no sirve" (siempre con el mismo mensaje, listo para mostrar).
     *
     * @return \InvalidArgumentException
     */
    private static function invalid_number_exception()
    {
        return new \InvalidArgumentException(
            'El recorte que se envió no es válido: alguna de las medidas no es un número o es demasiado grande. Volvé a marcar el recorte e intentá de nuevo.'
        );
    }

    /**
     * Arma el recorte cuando el rectangulo se sale de la imagen: un lienzo del tamano del rectangulo,
     * pintado de COLOR_RELLENO, con la parte visible de la imagen pegada en su lugar.
     *
     * Si el lienzo pasa MAX_LADO_CON_RELLENO de lado se achica todo por el mismo factor (nunca se agranda).
     * Los bordes de la parte visible se redondean uno por uno, no por tamano: asi la parte queda
     * pegada justo contra el borde del lienzo y no sobra 1 pixel de relleno entre las dos.
     *
     * @param  \Intervention\Image\ImageManager $manager     Para crear el lienzo.
     * @param  \Intervention\Image\Image        $image       Imagen de origen (se modifica en el lugar y se descarta).
     * @param  int   $rect_left   Borde izquierdo del rectangulo pedido, en pixeles de la imagen.
     * @param  int   $rect_top    Borde superior del rectangulo pedido.
     * @param  int   $rect_width  Ancho del rectangulo pedido (>= 1).
     * @param  int   $rect_height Alto del rectangulo pedido (>= 1).
     * @param  array $visible     Interseccion rectangulo / imagen, en pixeles de la imagen: claves left, top, right, bottom (right y bottom exclusivos, no vacia).
     * @return \Intervention\Image\Image Lienzo nuevo, ya con la imagen pegada.
     */
    private static function crop_with_fill(ImageManager $manager, Image $image, $rect_left, $rect_top, $rect_width, $rect_height, array $visible)
    {
        // Factor de escala: 1 (sin achicar) salvo que el lado mayor del rectangulo pase el tope de memoria.
        $scale = min(1, self::MAX_LADO_CON_RELLENO / max($rect_width, $rect_height));

        // Tamano del lienzo final, en pixeles (al menos 1 en cada eje).
        $canvas_width  = max(1, (int) round($rect_width * $scale));
        $canvas_height = max(1, (int) round($rect_height * $scale));

        // Bordes de la parte visible dentro del lienzo: se mide desde la esquina del rectangulo pedido y se redondea borde por borde.
        $part_left   = (int) round(($visible['left'] - $rect_left) * $scale);
        $part_right  = (int) round(($visible['right'] - $rect_left) * $scale);
        $part_top    = (int) round(($visible['top'] - $rect_top) * $scale);
        $part_bottom = (int) round(($visible['bottom'] - $rect_top) * $scale);

        // Tamano de la parte visible ya escalada. Nunca menos de 1: una franja finisima de imagen no tiene que desaparecer ni romper el resize.
        $part_width  = max(1, $part_right - $part_left);
        $part_height = max(1, $part_bottom - $part_top);

        // Que la parte entre siempre en el lienzo aunque el redondeo se haya pasado (solo puede pasar con escala < 1 y franjas de 1 pixel).
        $part_left = min($part_left, $canvas_width - $part_width);
        $part_top  = min($part_top, $canvas_height - $part_height);

        // Se deja solo la parte de la imagen que cae adentro del rectangulo; si ya es la imagen entera no hace falta copiarla.
        $is_whole_image = $visible['left'] === 0
            && $visible['top'] === 0
            && $visible['right'] === $image->width()
            && $visible['bottom'] === $image->height();

        if (!$is_whole_image) {
            $image->crop(
                $visible['right'] - $visible['left'],
                $visible['bottom'] - $visible['top'],
                $visible['left'],
                $visible['top']
            );
        }

        // Si hubo que achicar, la parte se achica al tamano que le toca en el lienzo.
        if ($image->width() !== $part_width || $image->height() !== $part_height) {
            $image->resize($part_width, $part_height);
        }

        // Lienzo con el color de relleno; `insert()` compone con mezcla de alfa, asi que un PNG con transparencia queda apoyado sobre el blanco.
        $canvas = $manager->canvas($canvas_width, $canvas_height, self::COLOR_RELLENO);
        $canvas->insert($image, 'top-left', $part_left, $part_top);

        return $canvas;
    }

    /**
     * Endereza la imagen segun la orientacion EXIF del archivo original (la foto de un celular sacada
     * de costado o boca abajo trae los pixeles "acostados" y una etiqueta que dice como girarlos al
     * mostrarla).
     *
     * Por que hace falta. El SPA le muestra la foto al usuario YA enderezada (el navegador la gira solo
     * y la libreria de recorte tambien) y le manda `left / top / width / height` en pixeles de la foto
     * enderezada. Este servidor recortaba sobre los pixeles CRUDOS: otra region (de costado) y, ahora
     * que el marco puede salirse, con franjas de relleno donde no correspondian. Ademas, la foto sin
     * recortar se guardaba acostada.
     *
     * Por que no se usa `Image::orientate()` de Intervention: sobre una imagen cargada desde binario
     * (que es como llega aca: bytes descargados o data URI) no hace nada, porque para leer el EXIF
     * vuelve a codificar la imagen con GD, que lo descarta (medido el 24/9/2026: 1600 x 1200 antes y
     * despues de llamarlo). Por eso la orientacion se lee de los bytes originales y se aplica el mismo
     * giro que aplica `orientate()`.
     *
     * Nunca lanza una excepcion: si no hay EXIF, si no se puede leer o si no hay memoria para girar,
     * devuelve la imagen tal como vino, que es lo que pasaba antes de existir este metodo.
     *
     * @param  \Intervention\Image\Image $image  Imagen ya cargada (se modifica en el lugar).
     * @param  mixed $source Lo mismo que se le paso a `ImageManager::make()`: los bytes de la imagen o un data URI.
     * @return \Intervention\Image\Image La imagen derecha (la misma instancia que se recibio).
     */
    public static function orient_by_exif(Image $image, $source)
    {
        try {
            $orientation = self::read_exif_orientation($source);

            // 1 = ya esta derecha; lo que queda fuera de 2..8 no es una orientacion valida.
            if ($orientation < 2 || $orientation > 8) {
                return $image;
            }

            // Sin memoria para la copia girada se deja como esta, antes que arriesgar un error fatal.
            if (!self::has_memory_to_copy($image)) {
                return $image;
            }

            // Los mismos giros que `Image::orientate()` (la tabla de la especificacion EXIF).
            switch ($orientation) {
                case 2:
                    $image->flip();
                    break;
                case 3:
                    $image->rotate(180);
                    break;
                case 4:
                    $image->rotate(180)->flip();
                    break;
                case 5:
                    $image->rotate(270)->flip();
                    break;
                case 6:
                    $image->rotate(270);
                    break;
                case 7:
                    $image->rotate(90)->flip();
                    break;
                case 8:
                    $image->rotate(90);
                    break;
            }
        } catch (\Throwable $e) {
            // No se pudo enderezar: se sigue con la imagen como venia (lo mismo que antes de este metodo).
        }

        return $image;
    }

    /**
     * Lee la orientacion EXIF (1 a 8) de los bytes originales de una imagen.
     *
     * Solo mira JPEG, que es donde los celulares guardan la orientacion: bytes que empiezan con la
     * marca de JPEG (FF D8) o un data URI base64 `data:image/jpeg`. No lee rutas de archivo a
     * proposito: el origen puede venir del cliente y no hay razon para abrir archivos del servidor
     * por este camino.
     *
     * Solo se le dan a exif_read_data los primeros BYTES_PARA_LEER_EXIF bytes, desde memoria: el EXIF
     * esta en los primeros segmentos del archivo, no hace falta copiar una foto de 6 MB para leer una
     * etiqueta. Y el data URI se decodifica a mano (no con el wrapper data://) para no depender de
     * que el servidor tenga allow_url_fopen prendido.
     *
     * @param  mixed $source Bytes de la imagen o data URI.
     * @return int Orientacion 1..8; 1 (derecha) si no hay EXIF, si no es un JPEG o si no se pudo leer.
     */
    private static function read_exif_orientation($source)
    {
        // Sin la extension exif, o con algo que no son bytes ni texto, no hay nada que leer.
        if (!function_exists('exif_read_data') || !is_string($source) || $source === '') {
            return 1;
        }

        // Los primeros bytes del JPEG, que es donde vive el EXIF (null si no es un JPEG que se pueda leer).
        $head = null;

        if (strncmp($source, "\xFF\xD8", 2) === 0) {
            // Bytes de un JPEG.
            $head = substr($source, 0, self::BYTES_PARA_LEER_EXIF);
        } elseif (preg_match('#^data:image/(?:jpeg|jpg|pjpeg);base64,#i', substr($source, 0, 40), $matches)) {
            // data URI base64 de un JPEG: se decodifica solo el principio (cada 4 caracteres son 3 bytes).
            $base64_head = substr($source, strlen($matches[0]), (int) (self::BYTES_PARA_LEER_EXIF / 3) * 4);
            $decoded     = base64_decode($base64_head, true);

            if ($decoded !== false) {
                $head = $decoded;
            }
        }

        if ($head === null || strncmp($head, "\xFF\xD8", 2) !== 0) {
            return 1;
        }

        // exif_read_data lee de un stream: uno de memoria con esos primeros bytes.
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return 1;
        }

        fwrite($stream, $head);
        rewind($stream);

        $data = @exif_read_data($stream);

        fclose($stream);

        if (!is_array($data) || !isset($data['Orientation'])) {
            return 1;
        }

        return (int) $data['Orientation'];
    }

    /**
     * Indica si hay memoria para hacer UNA copia mas de la imagen (lo que necesita un giro).
     *
     * GD guarda 4 bytes por pixel. Se compara lo que el proceso ya usa mas una copia (con el margen de
     * FACTOR_MEMORIA_AL_ENDEREZAR) contra el memory_limit; sin limite (-1) siempre hay.
     *
     * @param  \Intervention\Image\Image $image Imagen que se quiere girar.
     * @return bool
     */
    private static function has_memory_to_copy(Image $image)
    {
        $limit = self::memory_limit_bytes();

        if ($limit < 0) {
            return true;
        }

        // Lo que ocupa una copia de la imagen decodificada, con el margen.
        $copy_bytes = $image->width() * $image->height() * 4 * self::FACTOR_MEMORIA_AL_ENDEREZAR;

        return memory_get_usage(true) + $copy_bytes <= $limit;
    }

    /**
     * memory_limit de PHP en bytes.
     *
     * @return int Bytes; -1 si no hay limite ("-1", "0" o vacio).
     */
    private static function memory_limit_bytes()
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $number = (float) $raw;
        $unit   = strtolower(substr($raw, -1));

        if ($unit === 'g') {
            $number *= 1024 * 1024 * 1024;
        } elseif ($unit === 'm') {
            $number *= 1024 * 1024;
        } elseif ($unit === 'k') {
            $number *= 1024;
        }

        return $number > 0 ? (int) $number : -1;
    }
}
