<?php

namespace App\Services\ImageAssignment;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\ImageCropHelper;
use App\Services\ArticleImageValidationService;
use App\Services\BusquedaPorCodigoDeBarrasService;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Psr\Http\Message\ResponseInterface;

/**
 * Procesa las candidatas que devolvió una búsqueda de imágenes, para el motor de las asignaciones
 * inteligentes (misión imagenes-catalogo-completo, 27/9/2026).
 *
 * Lo que hace, en el orden en que gasta (de lo gratis a lo caro):
 *   1. Descartes SIN bajar nada: la misma URL que ya apareció (`duplicada`), el prefiltro de texto
 *      de siempre (listas de precios, catálogos, PDF: `descartada_por_texto`) y las que el propio
 *      proveedor informa chicas (lado menor < 400 px: `chica`). Un thumbnail nunca pasa este piso,
 *      por eso tampoco hay "fallback al thumbnail" como en el job viejo.
 *   2. Descarga EN PARALELO de hasta 5 candidatas (las de mayor tamaño informado), con la guarda
 *      SSRF de BusquedaPorCodigoDeBarrasService en cada salto de redirección.
 *   3. Medición real (getimagesizefromstring) antes de decodificar: lado menor >= 400 y como mucho
 *      25 megapíxeles.
 *   4. Fondo blanco MEDIDO EN CÓDIGO (no se le pregunta a la IA): proporción de píxeles casi blancos
 *      en la franja del borde. Es el segundo criterio de Lucas ("primero tamaño, segundo fondo
 *      blanco") y tiene que ser un número estable, no una opinión.
 *   5. Miniatura para la IA (lado mayor 512, webp, base64: el mismo criterio de
 *      ArticleImageValidationService::resize_and_encode_base64()).
 *
 * Y guarda la imagen final elegida: cuadrada, SIN recortarle nada a un producto alargado (una
 * botella se rellena con blanco a los costados en vez de perder la tapa), lado máximo 1000 px sin
 * agrandar, webp calidad 85.
 *
 * 🔴 Todo lo del disco va por Storage::disk('public'), que en producción es storage/app/public (el
 * mismo lugar donde escribe el resto del sistema) y en los tests se puede falsear.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados.
 */
class CandidateImageProcessor
{
    use GoogleSearchHelpers;

    /**
     * Lado menor mínimo de una candidata, en píxeles. Pedido de Lucas: "tamaño razonable para el
     * e-commerce, que no se vea pixelada, que no sea pequeña". Las tiendas online muestran la foto
     * de producto en 600-1000 px; debajo de 400 se ve pixelada en cualquier ficha.
     */
    const LADO_MINIMO = 400;

    /** Cuántas candidatas se bajan por búsqueda (las de mayor tamaño informado). */
    const MAX_DESCARGAS = 5;

    /** Segundos de espera de cada descarga (son en paralelo: el tramo espera el más lento). */
    const TIMEOUT_DESCARGA = 8;

    /** Peso máximo de una candidata: una foto de producto nunca pesa más. */
    const MAX_BYTES = 8388608;

    /**
     * Techo de megapíxeles ANTES de decodificar: GD guarda 4 bytes por píxel, así que 25 MP son
     * ~100 MB en memoria. Una foto de catálogo no necesita más.
     */
    const MAX_MEGAPIXELES = 25;

    /** Saltos de redirección que se siguen a mano, validando cada destino. */
    const MAX_REDIRECCIONES = 3;

    /** Lado de la muestra reducida sobre la que se mide el fondo. */
    const LADO_MUESTRA_FONDO = 64;

    /** Ancho, en píxeles de la muestra, de la franja del borde que se mide. */
    const ANCHO_FRANJA_FONDO = 4;

    /** Un píxel es "blanco" si su canal más oscuro llega a esto... */
    const MINIMO_CANAL_BLANCO = 235;

    /** ...y sus canales no se separan más que esto (un gris claro o un crema no son blancos). */
    const MAXIMA_DIFERENCIA_BLANCO = 20;

    /** Proporción de la franja que tiene que ser blanca para decir "fondo blanco". */
    const PROPORCION_FONDO_BLANCO = 0.85;

    /** Lado máximo de la imagen final (sin agrandar). */
    const LADO_MAXIMO_FINAL = 1000;

    /** Calidad webp de la imagen final. */
    const CALIDAD_FINAL = 85;

    /** Calidad webp (o jpeg) de la miniatura para la IA. */
    const CALIDAD_MINIATURA = 80;

    /**
     * Proporción ancho/alto dentro de la cual la imagen se recorta centrada a cuadrado. Fuera de
     * este rango se rellena: recortar una foto 1:3 le cortaría la mitad al producto.
     */
    const PROPORCION_MINIMA_PARA_RECORTAR = 0.9;
    const PROPORCION_MAXIMA_PARA_RECORTAR = 1.1;

    /**
     * User-Agent de navegador: muchas tiendas le devuelven 403 a un pedido que no parece un
     * navegador (mismo criterio que ImageController::get_image_source()).
     */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** @var \App\Services\ArticleImageValidationService Para el prefiltro de texto (gratis). */
    protected $validador;

    /** @var \App\Services\BusquedaPorCodigoDeBarrasService|null La guarda SSRF (se crea al primer uso). */
    protected $guarda_ssrf = null;

    /**
     * @param \App\Services\ArticleImageValidationService|null $validador
     */
    public function __construct(ArticleImageValidationService $validador = null)
    {
        $this->validador = is_null($validador) ? new ArticleImageValidationService() : $validador;
    }

    /**
     * Procesa las candidatas de UNA búsqueda.
     *
     * @param  array $resultados    Candidatas normalizadas del proveedor (ImageSearchProvider::buscar()).
     * @param  array $urls_vistas   URLs ya vistas en este artículo (de la búsqueda anterior), como claves.
     * @return array {
     *     candidatas:  array,  una por resultado, en el orden del proveedor, con el shape del
     *                          diagnóstico (posicion, url, miniatura, pagina, dominio, ancho, alto,
     *                          resultado, fondo_blanco_ratio, motivo) más `clave` (interna).
     *                          `resultado` queda null en las que están listas para la IA.
     *     listas:      array,  las listas para la IA, de la más grande a la más chica, con
     *                          clave, posicion, ancho, alto, fondo_blanco, fondo_blanco_ratio,
     *                          base64, media_type y binario (el original descargado).
     *     urls_vistas: array,  las URLs vistas, sumadas las de esta búsqueda.
     * }
     */
    public function preparar(array $resultados, array $urls_vistas = [])
    {
        $candidatas = [];

        foreach (array_values($resultados) as $indice => $resultado) {
            $candidata = [
                'clave'              => $indice,
                'posicion'           => isset($resultado['posicion']) ? (int) $resultado['posicion'] : ($indice + 1),
                'url'                => $this->recortar(isset($resultado['url']) ? $resultado['url'] : ''),
                'miniatura'          => $this->recortar(isset($resultado['miniatura']) ? $resultado['miniatura'] : ''),
                'pagina'             => $this->recortar(isset($resultado['pagina']) ? $resultado['pagina'] : ''),
                'dominio'            => $this->recortar(isset($resultado['dominio']) ? $resultado['dominio'] : '', 120),
                'titulo'             => isset($resultado['titulo']) ? (string) $resultado['titulo'] : '',
                'ancho'              => isset($resultado['ancho']) && (int) $resultado['ancho'] > 0 ? (int) $resultado['ancho'] : null,
                'alto'               => isset($resultado['alto']) && (int) $resultado['alto'] > 0 ? (int) $resultado['alto'] : null,
                'resultado'          => null,
                'fondo_blanco_ratio' => null,
                'motivo'             => null,
            ];

            // La URL completa (sin recortar) es la que se descarga y la que identifica duplicados.
            $url_completa = isset($resultado['url']) ? trim((string) $resultado['url']) : '';

            if (isset($urls_vistas[$url_completa])) {
                $candidata['resultado'] = 'duplicada';
                $candidata['motivo']    = 'La misma imagen ya había aparecido antes.';
            } else {
                $urls_vistas[$url_completa] = true;

                // Prefiltro de texto de siempre, con la candidata en la forma de un item de Google
                // (title / displayLink / link), que es lo que ese método sabe leer.
                $prefiltro = $this->validador->prefilter([
                    'title'       => $candidata['titulo'],
                    'displayLink' => $candidata['dominio'],
                    'link'        => $url_completa,
                ]);

                if ($prefiltro['rejected']) {
                    $candidata['resultado'] = 'descartada_por_texto';
                    $candidata['motivo']    = (string) $prefiltro['reason'];
                } elseif (!is_null($candidata['ancho']) && !is_null($candidata['alto'])
                    && min($candidata['ancho'], $candidata['alto']) < self::LADO_MINIMO) {
                    // Gratis: el proveedor ya dijo que es chica. No se baja ni se le pregunta a la IA.
                    $candidata['resultado'] = 'chica';
                    $candidata['motivo']    = 'Muy chica: '.$candidata['ancho'].'×'.$candidata['alto'].' px (el mínimo es '.self::LADO_MINIMO.' px de lado).';
                }
            }

            $candidata['url_completa'] = $url_completa;
            $candidatas[$indice] = $candidata;
        }

        // Las que siguen en carrera se bajan de la más grande a la más chica según lo que informa el
        // proveedor (las que no informan tamaño, al final), hasta MAX_DESCARGAS.
        $en_carrera = [];

        foreach ($candidatas as $indice => $candidata) {
            if (is_null($candidata['resultado'])) {
                $en_carrera[] = $indice;
            }
        }

        usort($en_carrera, function ($a, $b) use ($candidatas) {
            $lado_a = is_null($candidatas[$a]['ancho']) || is_null($candidatas[$a]['alto']) ? -1 : min($candidatas[$a]['ancho'], $candidatas[$a]['alto']);
            $lado_b = is_null($candidatas[$b]['ancho']) || is_null($candidatas[$b]['alto']) ? -1 : min($candidatas[$b]['ancho'], $candidatas[$b]['alto']);

            if ($lado_a !== $lado_b) {
                return $lado_a > $lado_b ? -1 : 1;
            }

            return $candidatas[$a]['posicion'] - $candidatas[$b]['posicion'];
        });

        $a_bajar = array_slice($en_carrera, 0, self::MAX_DESCARGAS);

        foreach (array_slice($en_carrera, self::MAX_DESCARGAS) as $indice) {
            $candidatas[$indice]['resultado'] = 'no_evaluada';
            $candidatas[$indice]['motivo']    = 'No se llegó a descargar: ya había '.self::MAX_DESCARGAS.' candidatas más grandes.';
        }

        $urls = [];

        foreach ($a_bajar as $indice) {
            $urls[$indice] = $candidatas[$indice]['url_completa'];
        }

        $descargas = empty($urls) ? [] : $this->descargar_en_paralelo($urls);

        $listas = [];

        foreach ($a_bajar as $indice) {
            $descarga = isset($descargas[$indice]) ? $descargas[$indice] : ['ok' => false, 'resultado' => 'no_descargable', 'motivo' => 'No se pudo descargar.'];

            if (!$descarga['ok']) {
                $candidatas[$indice]['resultado'] = $descarga['resultado'];
                $candidatas[$indice]['motivo']    = $descarga['motivo'];
                continue;
            }

            $analisis = $this->analizar($descarga['binario']);

            if (!is_null($analisis['medida_ancho'])) {
                // Lo medido reemplaza lo informado: es lo que se muestra en el diagnóstico.
                $candidatas[$indice]['ancho'] = $analisis['medida_ancho'];
                $candidatas[$indice]['alto']  = $analisis['medida_alto'];
            }

            if (!$analisis['ok']) {
                $candidatas[$indice]['resultado'] = $analisis['resultado'];
                $candidatas[$indice]['motivo']    = $analisis['motivo'];
                continue;
            }

            $candidatas[$indice]['fondo_blanco_ratio'] = $analisis['fondo_blanco_ratio'];

            $listas[] = [
                'clave'              => $indice,
                'posicion'           => $candidatas[$indice]['posicion'],
                'ancho'              => $analisis['ancho'],
                'alto'               => $analisis['alto'],
                'fondo_blanco'       => $analisis['fondo_blanco'],
                'fondo_blanco_ratio' => $analisis['fondo_blanco_ratio'],
                'base64'             => $analisis['base64'],
                'media_type'         => $analisis['media_type'],
                'binario'            => $descarga['binario'],
            ];
        }

        // Para la IA, de la más grande a la más chica (medido), y a igual tamaño por posición.
        usort($listas, function ($a, $b) {
            $lado_a = min($a['ancho'], $a['alto']);
            $lado_b = min($b['ancho'], $b['alto']);

            if ($lado_a !== $lado_b) {
                return $lado_a > $lado_b ? -1 : 1;
            }

            return $a['posicion'] - $b['posicion'];
        });

        foreach ($candidatas as $indice => $candidata) {
            unset($candidatas[$indice]['url_completa'], $candidatas[$indice]['titulo']);
        }

        return [
            'candidatas'  => $candidatas,
            'listas'      => $listas,
            'urls_vistas' => $urls_vistas,
        ];
    }

    /**
     * Mide una imagen descargada: tamaño real, fondo blanco y miniatura para la IA.
     *
     * @param  string $binario
     * @return array {ok, resultado, motivo, ancho, alto, medida_ancho, medida_alto, fondo_blanco, fondo_blanco_ratio, base64, media_type}
     */
    public function analizar($binario)
    {
        $fallo = [
            'ok'                 => false,
            'resultado'          => 'no_es_imagen',
            'motivo'             => 'Lo que devolvió el sitio no es una imagen.',
            'ancho'              => null,
            'alto'               => null,
            'medida_ancho'       => null,
            'medida_alto'        => null,
            'fondo_blanco'       => null,
            'fondo_blanco_ratio' => null,
            'base64'             => null,
            'media_type'         => null,
        ];

        // Medida ANTES de decodificar: getimagesizefromstring solo lee la cabecera.
        $info = @getimagesizefromstring((string) $binario);

        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return $fallo;
        }

        $ancho = (int) $info[0];
        $alto  = (int) $info[1];

        $fallo['medida_ancho'] = $ancho;
        $fallo['medida_alto']  = $alto;

        if ($ancho * $alto > self::MAX_MEGAPIXELES * 1000000) {
            $fallo['resultado'] = 'no_descargable';
            $fallo['motivo']    = 'Es demasiado grande para procesarla ('.round($ancho * $alto / 1000000).' megapíxeles; el máximo es '.self::MAX_MEGAPIXELES.').';

            return $fallo;
        }

        if (min($ancho, $alto) < self::LADO_MINIMO) {
            $fallo['resultado'] = 'chica';
            $fallo['motivo']    = 'Muy chica: '.$ancho.'×'.$alto.' px (el mínimo es '.self::LADO_MINIMO.' px de lado).';

            return $fallo;
        }

        if (!$this->hay_memoria_para($ancho, $alto)) {
            $fallo['resultado'] = 'no_descargable';
            $fallo['motivo']    = 'No hay memoria suficiente en el servidor para procesar una imagen de '.$ancho.'×'.$alto.' px.';

            return $fallo;
        }

        try {
            $manager = new ImageManager();
            $imagen  = $manager->make($binario);

            // Una foto con orientación EXIF se ve derecha en el navegador pero GD la lee acostada.
            $imagen = ImageCropHelper::orient_by_exif($imagen, $binario);

            $nucleo = $imagen->getCore();

            $ancho = (int) imagesx($nucleo);
            $alto  = (int) imagesy($nucleo);

            $proporcion = $this->medir_fondo_blanco($nucleo);
            $miniatura  = $this->miniatura_para_ia($nucleo);

            $imagen->destroy();
        } catch (\Throwable $e) {
            $fallo['motivo'] = 'El sistema no pudo leer esa imagen (formato no soportado o archivo dañado).';

            return $fallo;
        }

        if (is_null($miniatura)) {
            $fallo['motivo'] = 'El sistema no pudo preparar esa imagen para revisarla.';

            return $fallo;
        }

        return [
            'ok'                 => true,
            'resultado'          => null,
            'motivo'             => null,
            'ancho'              => $ancho,
            'alto'               => $alto,
            'medida_ancho'       => $ancho,
            'medida_alto'        => $alto,
            'fondo_blanco'       => $proporcion >= self::PROPORCION_FONDO_BLANCO,
            'fondo_blanco_ratio' => $proporcion,
            'base64'             => $miniatura['base64'],
            'media_type'         => $miniatura['media_type'],
        ];
    }

    /**
     * Proporción (0 a 1) de la franja del borde que es blanca.
     *
     * La imagen se reduce a una muestra de 64×64 apoyada sobre blanco (así un PNG con transparencia
     * cuenta como fondo blanco, que es como se va a ver en la tienda) y se miden los píxeles de los 4
     * bordes, 4 px hacia adentro. Un píxel es blanco si su canal más oscuro llega a 235 y sus canales
     * no se separan más de 20 (descarta cremas, grises y celestes muy claros).
     *
     * @param  resource|\GdImage $nucleo  La imagen GD ya decodificada.
     * @return float
     */
    public function medir_fondo_blanco($nucleo)
    {
        $lado = self::LADO_MUESTRA_FONDO;

        $muestra = imagecreatetruecolor($lado, $lado);
        $blanco  = imagecolorallocate($muestra, 255, 255, 255);
        imagefilledrectangle($muestra, 0, 0, $lado - 1, $lado - 1, $blanco);

        // Con la mezcla de alfa prendida, lo transparente del original queda sobre el blanco.
        imagealphablending($muestra, true);
        imagecopyresampled($muestra, $nucleo, 0, 0, 0, 0, $lado, $lado, imagesx($nucleo), imagesy($nucleo));

        $franja  = self::ANCHO_FRANJA_FONDO;
        $total   = 0;
        $blancos = 0;

        for ($y = 0; $y < $lado; $y++) {
            for ($x = 0; $x < $lado; $x++) {
                $adentro = $x >= $franja && $x < $lado - $franja && $y >= $franja && $y < $lado - $franja;

                if ($adentro) {
                    continue;
                }

                $color = imagecolorat($muestra, $x, $y);
                $rojo  = ($color >> 16) & 0xFF;
                $verde = ($color >> 8) & 0xFF;
                $azul  = $color & 0xFF;

                $total++;

                $minimo = min($rojo, $verde, $azul);
                $maximo = max($rojo, $verde, $azul);

                if ($minimo >= self::MINIMO_CANAL_BLANCO && ($maximo - $minimo) <= self::MAXIMA_DIFERENCIA_BLANCO) {
                    $blancos++;
                }
            }
        }

        imagedestroy($muestra);

        return $total > 0 ? round($blancos / $total, 4) : 0.0;
    }

    /**
     * La miniatura que ve la IA: lado mayor al de config (512 por defecto) sin agrandar, apoyada
     * sobre blanco, en webp (o jpeg si el GD del servidor no tiene webp), en base64. Mismo criterio
     * que ArticleImageValidationService::resize_and_encode_base64(): achicar baja el costo en tokens
     * sin perder lo que la IA necesita ver.
     *
     * @param  resource|\GdImage $nucleo
     * @return array|null  ['base64' => string, 'media_type' => string]
     */
    public function miniatura_para_ia($nucleo)
    {
        $lado_maximo = (int) config('services.article_image_validation.max_side');
        $lado_maximo = $lado_maximo > 0 ? $lado_maximo : 512;

        $ancho  = (int) imagesx($nucleo);
        $alto   = (int) imagesy($nucleo);
        $escala = min(1, $lado_maximo / max($ancho, $alto));

        $ancho_mini = max(1, (int) round($ancho * $escala));
        $alto_mini  = max(1, (int) round($alto * $escala));

        $mini   = imagecreatetruecolor($ancho_mini, $alto_mini);
        $blanco = imagecolorallocate($mini, 255, 255, 255);
        imagefilledrectangle($mini, 0, 0, $ancho_mini - 1, $alto_mini - 1, $blanco);
        imagealphablending($mini, true);
        imagecopyresampled($mini, $nucleo, 0, 0, 0, 0, $ancho_mini, $alto_mini, $ancho, $alto);

        ob_start();

        if (function_exists('imagewebp')) {
            $ok         = @imagewebp($mini, null, self::CALIDAD_MINIATURA);
            $media_type = 'image/webp';
        } else {
            $ok         = @imagejpeg($mini, null, self::CALIDAD_MINIATURA);
            $media_type = 'image/jpeg';
        }

        $datos = ob_get_clean();

        imagedestroy($mini);

        if (!$ok || !is_string($datos) || $datos === '') {
            return null;
        }

        return ['base64' => base64_encode($datos), 'media_type' => $media_type];
    }

    /**
     * Guarda la imagen elegida como archivo final en el disco público.
     *
     * - Proporción entre 0,9 y 1,1: recorte centrado a cuadrado (no se pierde nada importante).
     * - Fuera de ese rango: cuadrado con relleno BLANCO del lado mayor, con ImageCropHelper::crop()
     *   (un rectángulo más grande que la imagen se rellena con su COLOR_RELLENO). Así una botella
     *   1:3 conserva la tapa y la base; recortarla a cuadrado le cortaba dos tercios.
     * - Lado máximo 1000 px sin agrandar, apoyada sobre blanco (un PNG transparente queda con el
     *   fondo blanco que va a tener en la tienda), webp calidad 85.
     *
     * @param  string $binario  La imagen original descargada.
     * @param  string $archivo  Nombre del archivo a crear (`<time><rand>.webp` o `imgcand_<uuid>.webp`).
     * @return array  ['archivo' => string, 'url' => string, 'lado' => int]
     * @throws \Throwable  Si no se pudo decodificar o guardar (el motor lo registra).
     */
    public function guardar_final($binario, $archivo)
    {
        $manager = new ImageManager();
        $imagen  = $manager->make($binario);
        $imagen  = ImageCropHelper::orient_by_exif($imagen, $binario);

        $ancho      = $imagen->width();
        $alto       = $imagen->height();
        $proporcion = $ancho / max(1, $alto);

        if ($proporcion >= self::PROPORCION_MINIMA_PARA_RECORTAR && $proporcion <= self::PROPORCION_MAXIMA_PARA_RECORTAR) {
            $lado = min($ancho, $alto);
            $imagen->crop($lado, $lado, (int) floor(($ancho - $lado) / 2), (int) floor(($alto - $lado) / 2));
        } else {
            // Rectángulo cuadrado del lado MAYOR, centrado: se sale de la imagen por los costados
            // cortos y ImageCropHelper rellena eso con blanco.
            $lado   = max($ancho, $alto);
            $imagen = ImageCropHelper::crop(
                $manager,
                $imagen,
                (int) floor(($ancho - $lado) / 2),
                (int) floor(($alto - $lado) / 2),
                $lado,
                $lado
            );
        }

        if ($imagen->width() > self::LADO_MAXIMO_FINAL) {
            $imagen->resize(self::LADO_MAXIMO_FINAL, self::LADO_MAXIMO_FINAL, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // Apoyada sobre blanco: aplana cualquier transparencia que haya quedado.
        $final = $manager->canvas($imagen->width(), $imagen->height(), ImageCropHelper::COLOR_RELLENO);
        $final->insert($imagen, 'top-left', 0, 0);
        $imagen->destroy();

        $lado_final = (int) $final->width();

        $final->save(Storage::disk('public')->path($archivo), self::CALIDAD_FINAL, 'webp');
        $final->destroy();

        return [
            'archivo' => $archivo,
            'url'     => ApiUrlHelper::storage($archivo),
            'lado'    => $lado_final,
        ];
    }

    /**
     * Descarga varias URLs a la vez (Http::pool), con las guardas de siempre en cada salto.
     *
     * 🔴 SSRF. Estas URLs salen de un buscador: las eligió el dueño de cada página. Sin guardas,
     * una "imagen" en http://169.254.169.254/... (la metadata del VPS) o en un servicio interno haría
     * que ESTE servidor le pegue a su propia red. El criterio es el de
     * BusquedaPorCodigoDeBarrasService::url_permitida() (solo http/https en 80/443, sin usuario en
     * la URL, host que resuelve SOLO a IPs públicas) y se llama tal cual, sin copiarlo. En cada salto:
     *   - se valida el destino y se fija la conexión a la IP validada con CURLOPT_RESOLVE (un DNS que
     *     cambia entre la validación y el GET no la lleva a otro lado);
     *   - las redirecciones NO las sigue Guzzle: se siguen a mano, hasta MAX_REDIRECCIONES, y cada
     *     Location pasa por la misma validación.
     *
     * Sin `stream => true` A PROPÓSITO, aunque la búsqueda por código de barras lo use: con esa
     * opción Guzzle manda el pedido por su StreamHandler (fopen), que IGNORA las opciones `curl` —
     * o sea el pin de CURLOPT_RESOLVE — y además es sincrónico: el pool dejaría de ser paralelo. El
     * tope de peso se controla con `on_headers` (corta apenas llega un Content-Length de más o un
     * Content-Type que no es imagen), con CURLOPT_MAXFILESIZE y midiendo el cuerpo al final.
     *
     * Sin `Referer` (google_http() y no google_api_http()): muchos sitios bloquean el hotlink cuando
     * el Referer es de otro dominio. Ver GoogleSearchHelpers::google_api_http().
     *
     * @param  array $urls  clave => url.
     * @return array  clave => ['ok' => bool, 'binario' => string|null, 'resultado' => string|null, 'motivo' => string|null]
     */
    protected function descargar_en_paralelo(array $urls)
    {
        $resultados = [];
        $pendientes = [];

        foreach ($urls as $clave => $url) {
            $pendientes[$clave] = ['url' => (string) $url, 'saltos' => 0];
        }

        while (!empty($pendientes)) {
            $a_pedir = [];

            foreach ($pendientes as $clave => $pendiente) {
                $url = $this->sin_punto_final_en_el_host($pendiente['url']);
                $ip  = $this->guarda_ssrf()->url_permitida($url);

                if (is_null($ip)) {
                    $resultados[$clave] = $this->descarga_fallida('no_descargable', 'La dirección de la imagen no es un sitio público permitido.');
                    continue;
                }

                $a_pedir[$clave] = ['url' => $url, 'ip' => $ip, 'saltos' => $pendiente['saltos']];
            }

            if (empty($a_pedir)) {
                break;
            }

            $respuestas = $this->pedir_en_paralelo($a_pedir);

            $siguientes = [];

            foreach ($a_pedir as $clave => $pedido) {
                $respuesta = isset($respuestas['c'.$clave]) ? $respuestas['c'.$clave] : null;

                try {
                    $evaluada = $this->evaluar_respuesta($respuesta, $pedido);
                } catch (\Throwable $e) {
                    $evaluada = ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio no respondió bien a la descarga.'];
                }

                if ($evaluada['estado'] === 'redireccion') {
                    $siguientes[$clave] = ['url' => $evaluada['destino'], 'saltos' => $pedido['saltos'] + 1];
                    continue;
                }

                if ($evaluada['estado'] === 'ok') {
                    $resultados[$clave] = ['ok' => true, 'binario' => $evaluada['binario'], 'resultado' => null, 'motivo' => null];
                    continue;
                }

                $resultados[$clave] = $this->descarga_fallida($evaluada['resultado'], $evaluada['motivo']);
            }

            $pendientes = $siguientes;
        }

        return $resultados;
    }

    /**
     * Lanza los pedidos de una vuelta en paralelo. Las claves del pool son 'c'.clave (Pool::as()
     * pide string).
     *
     * @param  array $a_pedir  clave => ['url', 'ip', 'saltos']
     * @return array  'c'.clave => Response|\Throwable
     */
    protected function pedir_en_paralelo(array $a_pedir)
    {
        $opciones_tls = $this->google_http_options();
        $max_bytes    = self::MAX_BYTES;

        try {
            return Http::pool(function (Pool $pool) use ($a_pedir, $opciones_tls, $max_bytes) {
                foreach ($a_pedir as $clave => $pedido) {
                    $pool->as('c'.$clave)
                        ->withOptions(array_merge($opciones_tls, [
                            'allow_redirects' => false,
                            // Corta la descarga apenas llegan las cabeceras si anuncia más peso del
                            // permitido o si no es una imagen: no se trae a memoria lo que se va a tirar.
                            'on_headers'      => function (ResponseInterface $cabeceras) use ($max_bytes) {
                                $largo = (int) $cabeceras->getHeaderLine('Content-Length');

                                if ($largo > $max_bytes) {
                                    throw new \RuntimeException('La imagen pesa más de lo permitido.');
                                }

                                $tipo = strtolower($cabeceras->getHeaderLine('Content-Type'));

                                if ($cabeceras->getStatusCode() === 200 && $tipo !== '' && strpos($tipo, 'image/') !== 0) {
                                    throw new \RuntimeException('El sitio no devolvió una imagen.');
                                }
                            },
                            'curl'            => [
                                CURLOPT_RESOLVE     => [$this->regla_de_resolucion($pedido['url'], $pedido['ip'])],
                                CURLOPT_MAXFILESIZE => $max_bytes,
                                CURLOPT_PROTOCOLS   => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                            ],
                        ]))
                        ->timeout(self::TIMEOUT_DESCARGA)
                        ->withHeaders([
                            'User-Agent'      => self::USER_AGENT,
                            // 🔴 SIN image/avif, a diferencia del Accept de un navegador: los CDN que
                            // negocian el formato devuelven AVIF si se lo anuncia, y el GD de PHP 7.4
                            // no lo sabe leer (getimagesizefromstring da false y la candidata quedaría
                            // como "no es una imagen"). webp, jpeg y png los lee todos.
                            'Accept'          => 'image/webp,image/jpeg,image/png,image/gif;q=0.9,*/*;q=0.5',
                            'Accept-Language' => 'es-AR,es;q=0.9,en;q=0.5',
                        ])
                        ->get($pedido['url']);
                }
            });
        } catch (\Throwable $e) {
            Log::info('[ImagenesInteligentes] Falló el pool de descargas: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Qué hacer con la respuesta de una descarga: seguir una redirección, aceptarla o descartarla.
     *
     * @param  mixed $respuesta  Response, un Throwable (conexión) o null.
     * @param  array $pedido     ['url', 'ip', 'saltos']
     * @return array  ['estado' => 'ok'|'redireccion'|'fallo', ...]
     */
    protected function evaluar_respuesta($respuesta, array $pedido)
    {
        if (!($respuesta instanceof Response)) {
            return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio no respondió a tiempo o cortó la conexión.'];
        }

        $estado = (int) $respuesta->status();

        if (in_array($estado, [301, 302, 303, 307, 308], true)) {
            if ($pedido['saltos'] >= self::MAX_REDIRECCIONES) {
                return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio redirigió demasiadas veces.'];
            }

            $destino = $this->url_absoluta(trim((string) $respuesta->header('Location')), $pedido['url']);

            if (is_null($destino)) {
                return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio redirigió a una dirección inválida.'];
            }

            return ['estado' => 'redireccion', 'destino' => $destino];
        }

        if ($estado < 200 || $estado >= 300) {
            return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio no dejó descargar la imagen (HTTP '.$estado.').'];
        }

        if ((int) $respuesta->header('Content-Length') > self::MAX_BYTES) {
            return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'La imagen pesa más de '.round(self::MAX_BYTES / 1048576).' MB.'];
        }

        $tipo = strtolower((string) $respuesta->header('Content-Type'));

        if ($tipo !== '' && strpos($tipo, 'image/') !== 0) {
            return ['estado' => 'fallo', 'resultado' => 'no_es_imagen', 'motivo' => 'El sitio devolvió una página ('.$tipo.'), no una imagen.'];
        }

        $cuerpo = (string) $respuesta->body();

        if ($cuerpo === '') {
            return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'El sitio devolvió un archivo vacío.'];
        }

        if (strlen($cuerpo) > self::MAX_BYTES) {
            return ['estado' => 'fallo', 'resultado' => 'no_descargable', 'motivo' => 'La imagen pesa más de '.round(self::MAX_BYTES / 1048576).' MB.'];
        }

        return ['estado' => 'ok', 'binario' => $cuerpo];
    }

    /**
     * La guarda SSRF: la de la búsqueda por código de barras, resuelta por el contenedor (así los
     * tests la reemplazan por una con DNS de prueba, igual que en Busqueda_por_codigo_de_barras_Test).
     *
     * @return \App\Services\BusquedaPorCodigoDeBarrasService
     */
    protected function guarda_ssrf()
    {
        if (is_null($this->guarda_ssrf)) {
            $this->guarda_ssrf = app()->makeWith(BusquedaPorCodigoDeBarrasService::class, ['owner' => null]);
        }

        return $this->guarda_ssrf;
    }

    /**
     * ¿Alcanza la memoria para decodificar una imagen de este tamaño?
     *
     * GD guarda 4 bytes por píxel. Se pide la imagen decodificada con un 30 % de margen (el mismo
     * FACTOR_MEMORIA_AL_ENDEREZAR de ImageCropHelper) más 30 MB, que cubren la muestra del fondo,
     * la miniatura y el lienzo del recorte con relleno (ImageCropHelper lo topea en 2500 px de lado:
     * ~25 MB). Sin límite de memoria (-1) siempre alcanza.
     *
     * El job sube el memory_limit a 512 MB al arrancar (InstrumentaMemoria): con 128 MB, el valor
     * por defecto del CLI, una foto de 12 megapíxeles ya no entraría y se descartaría por esto.
     *
     * @param  int $ancho
     * @param  int $alto
     * @return bool
     */
    protected function hay_memoria_para($ancho, $alto)
    {
        $limite = $this->limite_de_memoria();

        if ($limite < 0) {
            return true;
        }

        $necesaria = (int) ($ancho * $alto * 4 * 1.3) + (30 * 1048576);

        return memory_get_usage(true) + $necesaria <= $limite;
    }

    /**
     * memory_limit de PHP en bytes (-1 = sin límite).
     *
     * @return int
     */
    protected function limite_de_memoria()
    {
        $crudo = trim((string) ini_get('memory_limit'));

        if ($crudo === '' || $crudo === '-1') {
            return -1;
        }

        $numero = (float) $crudo;
        $unidad = strtolower(substr($crudo, -1));

        if ($unidad === 'g') {
            $numero *= 1073741824;
        } elseif ($unidad === 'm') {
            $numero *= 1048576;
        } elseif ($unidad === 'k') {
            $numero *= 1024;
        }

        return $numero > 0 ? (int) $numero : -1;
    }

    /**
     * La URL con el host sin punto final: `tienda.com.` resuelve igual que `tienda.com`, pero para
     * curl son dos nombres, y si la regla de CURLOPT_RESOLVE dijera uno y la URL el otro el pin no
     * aplicaría (mismo criterio que la búsqueda por código de barras).
     *
     * @param  string $url
     * @return string
     */
    protected function sin_punto_final_en_el_host($url)
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        if ($host === '' || substr($host, -1) !== '.') {
            return (string) $url;
        }

        $posicion = strpos((string) $url, '//'.$host);

        if ($posicion === false) {
            return (string) $url;
        }

        return substr((string) $url, 0, $posicion + 2).rtrim($host, '.').substr((string) $url, $posicion + 2 + strlen($host));
    }

    /**
     * La entrada de CURLOPT_RESOLVE ("host:puerto:ip") que ata la conexión a la IP ya validada.
     *
     * @param  string $url
     * @param  string $ip
     * @return string
     */
    protected function regla_de_resolucion($url, $ip)
    {
        $partes = parse_url((string) $url);
        $host   = rtrim(strtolower(trim(isset($partes['host']) ? (string) $partes['host'] : '', '[]')), '.');
        $puerto = isset($partes['port'])
            ? (int) $partes['port']
            : (isset($partes['scheme']) && strtolower((string) $partes['scheme']) === 'https' ? 443 : 80);

        $ip = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip;

        return $host.':'.$puerto.':'.$ip;
    }

    /**
     * Una Location relativa ("/img/a.jpg", "//cdn/a.jpg") pasada a absoluta contra la URL pedida.
     *
     * @param  string $destino
     * @param  string $origen
     * @return string|null
     */
    protected function url_absoluta($destino, $origen)
    {
        if ($destino === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $destino)) {
            return $destino;
        }

        $esquema = (string) parse_url($origen, PHP_URL_SCHEME);
        $host    = (string) parse_url($origen, PHP_URL_HOST);

        if ($esquema === '' || $host === '') {
            return null;
        }

        if (substr($destino, 0, 2) === '//') {
            return $esquema.':'.$destino;
        }

        if (substr($destino, 0, 1) === '/') {
            return $esquema.'://'.$host.$destino;
        }

        return null;
    }

    /**
     * @param  string $resultado
     * @param  string $motivo
     * @return array
     */
    protected function descarga_fallida($resultado, $motivo)
    {
        return ['ok' => false, 'binario' => null, 'resultado' => $resultado, 'motivo' => $motivo];
    }

    /**
     * Recorta un texto para el diagnóstico (las URLs de algunos CDN pasan los mil caracteres).
     *
     * @param  mixed $texto
     * @param  int   $largo
     * @return string
     */
    protected function recortar($texto, $largo = 500)
    {
        $texto = trim((string) $texto);

        return strlen($texto) > $largo ? substr($texto, 0, $largo) : $texto;
    }
}
