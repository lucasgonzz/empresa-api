<?php

namespace App\Http\Controllers\Helpers;

use App\Models\OnlineConfiguration;
use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * La tienda online de un negocio en PRUEBA GRATUITA es la del negocio y deja hacer un pedido de prueba
 * (misión prueba-e2e-campana-imperio, ronda 3, E2, 12/11/2026).
 *
 * En el ensayo del 12/11 la tienda de la prueba seguía siendo la de la marca de ejemplo del rubro: logo y
 * banner con «PUNTO FIERRO · DEMO» impreso, el menú «Conocé Punto Fierro · Demo» y la Presentación «Este
 * negocio es una demostración de ComercioCity…» (los pone `DemoIndustriaService::preparar`, que en la prueba
 * ya respetaba el nombre del negocio en el sistema pero no en la tienda). Y no se podía comprar: la tienda
 * nace con «Registrarse para comprar» prendido y los artículos que importa el dueño no tienen stock, así que
 * las fichas solo mostraban «Compartir» y «Consultar», mientras el seguimiento le pedía «hacé un pedido de
 * prueba en tu tienda».
 *
 * De dónde lee cada cosa la tienda (tienda-spa / tienda-api, que leen esta misma base; no se tocan):
 *  - logo del encabezado, de los correos y del ingreso: `online_configurations.logo_url` (si está vacío,
 *    `users.image_url`);
 *  - imagen de un artículo sin foto: `online_configurations.default_article_image_url`;
 *  - banner de la portada: `titles.image_url` (escritorio) y `titles.crop_image_url` (celular);
 *  - menú y página de presentación: `online_configurations.titulo_quienes_somos` y `quienes_somos`;
 *  - controles del carrito: `puede_comprar_sin_login` = `!register_to_buy`, y `hasStock` es verdadero
 *    siempre con `ignorar_stock` prendido.
 *
 * Qué hace, solo en una prueba activa (`PruebaHelper::activa`, se fija adentro y no confía en quien lo llama):
 *  1. Pedidos de prueba: `register_to_buy` = 0 e `ignorar_stock` = 1. Son dos opciones visibles en
 *     Configuración Online: el dueño las puede volver a cambiar.
 *  2. Identidad: con el nombre del negocio del alta (`users.company_name`), un logo y un banner generados con
 *     ese nombre y la presentación sin el texto de demostración. Solo reemplaza lo que todavía es de la
 *     marca de ejemplo (o lo que generó este mismo helper): si el dueño ya subió su logo o escribió su
 *     presentación, eso no se toca. Sin nombre propio (el pool de pruebas armado antes de tener lead, que
 *     todavía lleva el de la marca de ejemplo) no cambia la identidad.
 *
 * `users.image_url` NO se toca: lo usan también los PDF del sistema (FPDF), que no leen SVG.
 *
 * Lo llaman el demo setup cuando arma una prueba y `prueba:lista-para-vender` para las ya armadas.
 * Idempotente. Nunca rompe a quien lo llama: un fallo se loguea y la tienda queda como estaba.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin operador nullsafe.
 */
class TiendaDeLaPruebaHelper
{
    /** Carpeta del disco `public` donde quedan el logo y los banners generados. */
    const CARPETA = 'prueba-tienda';

    /** Lo que la marca de ejemplo deja en las URLs de sus imágenes (`public/demo-industrias/<rubro>/…`). */
    const MARCA_DE_EJEMPLO_EN_URL = 'demo-industrias/';

    /** El texto que `DemoIndustriaService::preparar` pone en la presentación de la tienda de ejemplo. */
    const TEXTO_DE_DEMOSTRACION = 'demostración de ComercioCity';

    /** Así empieza la presentación que pone este helper (para reconocerla y poder rehacerla). */
    const INICIO_DE_LA_PRESENTACION = 'Bienvenido a la tienda online de ';

    /** Color de fondo de los banners: el `secondary_color` con el que nace la tienda de la prueba. */
    const FONDO_DEL_BANNER = '#252A32';

    /** Tamaños de las imágenes que reemplazan, iguales a las de la marca de ejemplo. */
    const LOGO = [600, 600];
    const BANNER_ESCRITORIO = [1774, 887];
    const BANNER_CELULAR = [1122, 1402];

    /**
     * Deja la tienda de la prueba con el nombre del negocio y lista para un pedido de prueba.
     *
     * @param int $owner_id
     * @return array{pedidos: bool, identidad: bool} qué se dejó listo (para el log, el comando y los tests).
     */
    public static function dejar_tienda_del_negocio($owner_id)
    {
        $resultado = ['pedidos' => false, 'identidad' => false];

        // Se protege solo: fuera de una prueba activa no toca nada.
        if (!PruebaHelper::activa($owner_id)) {
            return $resultado;
        }

        try {
            $owner = User::find((int) $owner_id);
            $configuracion = is_null($owner) ? null : OnlineConfiguration::where('user_id', $owner->id)->first();

            if (is_null($configuracion)) {
                return $resultado;
            }

            self::abrir_pedidos_de_prueba($configuracion);
            $resultado['pedidos'] = true;

            $nombre = trim((string) $owner->company_name);
            if ($nombre !== '' && !self::es_nombre_de_ejemplo($nombre)) {
                self::poner_identidad_del_negocio($owner, $configuracion, $nombre);
                $resultado['identidad'] = true;
            }

            $configuracion->save();

            Log::info('TiendaDeLaPruebaHelper: la tienda del dueño ' . $owner->id . ' quedó del negocio y lista para pedidos.', $resultado);
        } catch (Throwable $e) {
            Log::error('TiendaDeLaPruebaHelper: no se pudo dejar lista la tienda del dueño ' . $owner_id . ': ' . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Un visitante compra sin registrarse y aunque el artículo no tenga stock cargado.
     *
     * @param \App\Models\OnlineConfiguration $configuracion
     * @return void
     */
    private static function abrir_pedidos_de_prueba(OnlineConfiguration $configuracion)
    {
        $configuracion->register_to_buy = 0;

        // La columna la crea una migración de 2026: una base vieja puede no tenerla.
        if (Schema::hasColumn('online_configurations', 'ignorar_stock')) {
            $configuracion->ignorar_stock = 1;
        }
    }

    /**
     * ¿Es el nombre de una marca de ejemplo («Punto Fierro · Demo»)?
     *
     * @param string $nombre
     * @return bool
     */
    private static function es_nombre_de_ejemplo($nombre)
    {
        $nombres = array_filter(array_map(function ($perfil) {
            return is_array($perfil) && isset($perfil['nombre_demo']) ? (string) $perfil['nombre_demo'] : null;
        }, (array) config('demo_industrias', [])));

        return in_array($nombre, $nombres, true) || (bool) preg_match('/·\s*demo$/iu', $nombre);
    }

    /**
     * Logo, imagen por defecto, banners y presentación con el nombre del negocio, donde todavía hay algo de
     * la marca de ejemplo o de una pasada anterior de este helper.
     *
     * @param \App\Models\User $owner
     * @param \App\Models\OnlineConfiguration $configuracion
     * @param string $nombre
     * @return void
     */
    private static function poner_identidad_del_negocio(User $owner, OnlineConfiguration $configuracion, $nombre)
    {
        $color = self::color_valido($configuracion->primary_color, '#F05A00');

        $reemplazar_logo = self::es_reemplazable($configuracion->logo_url);
        $reemplazar_imagen = self::es_reemplazable($configuracion->default_article_image_url);
        if ($reemplazar_logo || $reemplazar_imagen) {
            $logo = self::generar_imagen($owner->id, 'logo', $nombre, $color, self::LOGO);
            if ($reemplazar_logo) {
                $configuracion->logo_url = $logo;
            }
            if ($reemplazar_imagen) {
                $configuracion->default_article_image_url = $logo;
            }
        }

        $banners = Title::where('user_id', $owner->id)->orderBy('num')->get()->filter(function ($title) {
            return self::es_de_ejemplo_o_generado($title->image_url) || self::es_de_ejemplo_o_generado($title->crop_image_url);
        });
        if ($banners->isNotEmpty()) {
            $escritorio = self::generar_imagen($owner->id, 'banner-escritorio', $nombre, $color, self::BANNER_ESCRITORIO);
            $celular = self::generar_imagen($owner->id, 'banner-celular', $nombre, $color, self::BANNER_CELULAR);
            foreach ($banners as $title) {
                $title->image_url = $escritorio;
                $title->crop_image_url = $celular;
                $title->hosting_image_url = null;
                $title->header = null;
                $title->lead = null;
                $title->save();
            }
        }

        $presentacion = (string) $configuracion->quienes_somos;
        if (trim(strip_tags($presentacion)) === '' || strpos($presentacion, self::TEXTO_DE_DEMOSTRACION) !== false
            || strpos($presentacion, self::INICIO_DE_LA_PRESENTACION) !== false) {
            $configuracion->titulo_quienes_somos = 'Conocé ' . $nombre;
            $configuracion->quienes_somos = '<h2>' . e($nombre) . '</h2><p>' . self::INICIO_DE_LA_PRESENTACION . e($nombre)
                . '. Recorré el catálogo y hacé tu pedido desde acá.</p>';
        }
    }

    /**
     * ¿La URL está vacía, es de la marca de ejemplo o la generó este helper?
     *
     * @param string|null $url
     * @return bool
     */
    private static function es_reemplazable($url)
    {
        return trim((string) $url) === '' || self::es_de_ejemplo_o_generado($url);
    }

    /**
     * @param string|null $url
     * @return bool
     */
    private static function es_de_ejemplo_o_generado($url)
    {
        $url = (string) $url;

        return strpos($url, self::MARCA_DE_EJEMPLO_EN_URL) !== false || strpos($url, '/' . self::CARPETA . '/') !== false;
    }

    /**
     * Genera la imagen con el nombre del negocio en el disco `public` y devuelve su URL pública.
     *
     * PNG con GD y la tipografía Inter que ya trae el repo (la de los PDF), porque el logo viaja también en
     * los correos de los pedidos y los clientes de correo no muestran SVG. Si el servidor no tiene GD con
     * FreeType, cae a un SVG, que la tienda sí muestra.
     *
     * El nombre del archivo lleva un resumen del texto y el color: si el nombre del negocio cambia, la URL
     * cambia y el navegador no sigue mostrando la vieja.
     *
     * @param int $owner_id
     * @param string $tipo 'logo', 'banner-escritorio' o 'banner-celular'
     * @param string $nombre
     * @param string $color
     * @param int[] $tamano [ancho, alto]
     * @return string
     */
    private static function generar_imagen($owner_id, $tipo, $nombre, $color, array $tamano)
    {
        $resumen = substr(md5($tipo . '|' . $nombre . '|' . $color), 0, 10);
        $base = self::CARPETA . '/' . (int) $owner_id . '-' . $tipo . '-' . $resumen;

        $png = self::png($tipo, $nombre, $color, $tamano);
        if (!is_null($png)) {
            Storage::disk('public')->put($base . '.png', $png);

            return ApiUrlHelper::storage($base . '.png');
        }

        Storage::disk('public')->put($base . '.svg', self::svg($tipo, $nombre, $color, $tamano));

        return ApiUrlHelper::storage($base . '.svg');
    }

    /**
     * @param string $tipo
     * @param string $nombre
     * @param string $color
     * @param int[] $tamano
     * @return string|null el binario del PNG, o null si no hay GD con FreeType o la tipografía.
     */
    private static function png($tipo, $nombre, $color, array $tamano)
    {
        $fuente = self::fuente();
        if (is_null($fuente) || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')
            || !function_exists('imagettfbbox') || !function_exists('imagepng')) {
            return null;
        }

        list($ancho, $alto) = $tamano;
        $imagen = imagecreatetruecolor($ancho, $alto);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        list($r, $g, $b) = self::rgb($color);
        $acento = imagecolorallocate($imagen, $r, $g, $b);

        if ($tipo === 'logo') {
            // El encabezado de la tienda lo muestra en un círculo de 80 px: van las iniciales, grandes.
            imagefilledrectangle($imagen, 0, 0, $ancho, $alto, $acento);
            self::escribir_centrado($imagen, $fuente, self::iniciales($nombre), $blanco, $ancho / 2, $alto / 2, $ancho * 0.62, $alto * 0.42);
        } else {
            list($fr, $fg, $fb) = self::rgb(self::FONDO_DEL_BANNER);
            imagefilledrectangle($imagen, 0, 0, $ancho, $alto, imagecolorallocate($imagen, $fr, $fg, $fb));
            // Una franja del color de la tienda abajo, como cierre.
            imagefilledrectangle($imagen, 0, (int) round($alto * 0.94), $ancho, $alto, $acento);

            $lineas = self::lineas_del_nombre($nombre, $ancho >= $alto ? 2 : 3);
            $alto_de_linea = $alto * ($ancho >= $alto ? 0.17 : 0.11);
            $arranque = $alto * 0.42 - (count($lineas) - 1) * $alto_de_linea / 2;
            foreach ($lineas as $indice => $linea) {
                self::escribir_centrado($imagen, $fuente, $linea, $blanco, $ancho / 2, $arranque + $indice * $alto_de_linea, $ancho * 0.84, $alto_de_linea * 0.8);
            }
            self::escribir_centrado($imagen, $fuente, 'Tienda online', $acento, $ancho / 2, $arranque + count($lineas) * $alto_de_linea + $alto * 0.02, $ancho * 0.5, $alto * 0.05);
        }

        ob_start();
        imagepng($imagen);
        $binario = (string) ob_get_clean();
        imagedestroy($imagen);

        return $binario === '' ? null : $binario;
    }

    /**
     * Escribe un texto centrado en (x, y) con el tamaño más grande que entra en la caja dada.
     *
     * @param resource $imagen
     * @param string $fuente
     * @param string $texto
     * @param int $color
     * @param float $x
     * @param float $y
     * @param float $ancho_maximo
     * @param float $alto_maximo
     * @return void
     */
    private static function escribir_centrado($imagen, $fuente, $texto, $color, $x, $y, $ancho_maximo, $alto_maximo)
    {
        $tamano = max(8.0, $alto_maximo);
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);
        $ancho = abs($caja[2] - $caja[0]);
        if ($ancho > $ancho_maximo && $ancho > 0) {
            $tamano = max(8.0, $tamano * $ancho_maximo / $ancho);
            $caja = imagettfbbox($tamano, 0, $fuente, $texto);
        }
        $ancho = abs($caja[2] - $caja[0]);
        $alto = abs($caja[7] - $caja[1]);

        imagettftext($imagen, $tamano, 0, (int) round($x - $ancho / 2 - $caja[0]), (int) round($y + $alto / 2), $color, $fuente, $texto);
    }

    /**
     * @param string $tipo
     * @param string $nombre
     * @param string $color
     * @param int[] $tamano
     * @return string
     */
    private static function svg($tipo, $nombre, $color, array $tamano)
    {
        list($ancho, $alto) = $tamano;
        $fuente = 'font-family="Inter, Arial, Helvetica, sans-serif" font-weight="700" text-anchor="middle" dominant-baseline="middle"';

        if ($tipo === 'logo') {
            $cuerpo = '<rect width="100%" height="100%" fill="' . $color . '"/>'
                . '<text x="50%" y="50%" fill="#FFFFFF" font-size="' . round($alto * 0.4) . '" ' . $fuente . '>' . e(self::iniciales($nombre)) . '</text>';
        } else {
            $largo = max(1, mb_strlen($nombre));
            $tamano_letra = min($alto * 0.16, $ancho * 1.6 / $largo);
            $cuerpo = '<rect width="100%" height="100%" fill="' . self::FONDO_DEL_BANNER . '"/>'
                . '<rect y="' . round($alto * 0.94) . '" width="100%" height="' . round($alto * 0.06 + 1) . '" fill="' . $color . '"/>'
                . '<text x="50%" y="44%" fill="#FFFFFF" font-size="' . round($tamano_letra) . '" ' . $fuente . '>' . e($nombre) . '</text>'
                . '<text x="50%" y="60%" fill="' . $color . '" font-size="' . round($alto * 0.05) . '" ' . $fuente . '>Tienda online</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $ancho . '" height="' . $alto . '" viewBox="0 0 ' . $ancho . ' ' . $alto . '">' . $cuerpo . '</svg>';
    }

    /**
     * Las iniciales de las dos primeras palabras con letras («Ferretería Los Aromos» → «FL»).
     *
     * @param string $nombre
     * @return string
     */
    private static function iniciales($nombre)
    {
        $palabras = preg_split('/[^\p{L}\p{N}]+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY);
        $iniciales = '';
        foreach (array_slice($palabras, 0, 2) as $palabra) {
            $iniciales .= mb_strtoupper(mb_substr($palabra, 0, 1));
        }

        return $iniciales !== '' ? $iniciales : mb_strtoupper(mb_substr($nombre, 0, 1));
    }

    /**
     * Parte un nombre largo en renglones parejos (como mucho `$maximo`), para que la letra no quede chica.
     *
     * @param string $nombre
     * @param int $maximo
     * @return string[]
     */
    private static function lineas_del_nombre($nombre, $maximo)
    {
        $palabras = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strlen($nombre) <= 16 || count($palabras) < 2) {
            return [$nombre];
        }

        $renglones = min($maximo, count($palabras), (int) ceil(mb_strlen($nombre) / 16));
        $objetivo = mb_strlen($nombre) / $renglones;
        $lineas = [];
        $actual = '';
        foreach ($palabras as $palabra) {
            $candidata = $actual === '' ? $palabra : $actual . ' ' . $palabra;
            if ($actual !== '' && mb_strlen($candidata) > $objetivo && count($lineas) < $renglones - 1) {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $candidata;
            }
        }
        $lineas[] = $actual;

        return $lineas;
    }

    /**
     * @return string|null
     */
    private static function fuente()
    {
        $ruta = app_path('Http/Controllers/CommonLaravel/fpdf/font/Inter-Bold.ttf');

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * @param string|null $color
     * @param string $por_defecto
     * @return string
     */
    private static function color_valido($color, $por_defecto)
    {
        $color = trim((string) $color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $por_defecto;
    }

    /**
     * @param string $color '#RRGGBB'
     * @return int[]
     */
    private static function rgb($color)
    {
        $hex = ltrim($color, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
