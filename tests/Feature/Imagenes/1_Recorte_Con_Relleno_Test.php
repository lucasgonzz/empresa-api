<?php

namespace Tests\Feature\Imagenes;

use App\Http\Controllers\Helpers\ImageCropHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Misión recorte-imagen-zoom-scroll (24/9/2026): recorte de imágenes con un marco que SE SALE de la
 * imagen.
 *
 * El modal de recorte del SPA tiene un marco fijo y deja alejar la imagen: una imagen apaisada (3:1)
 * dentro de un marco 1:1 se puede alejar hasta que entre entera, y lo que sobra arriba y abajo del
 * marco queda vacío. Al guardar, el SPA manda `left / top / width / height` en píxeles de la imagen
 * original y esos números pueden ser negativos o pasarse del ancho / alto. `Image::crop()` de
 * Intervention (GD) no valida los bordes: con un rectángulo que se sale rellena de NEGRO. Lo que se
 * protege acá es que ImageCropHelper (y el endpoint set-image que lo usa) rellene con COLOR_RELLENO y
 * que el recorte de siempre —el rectángulo adentro de la imagen— siga dando exactamente lo mismo.
 *
 * Cómo están armados los tests:
 *
 *  - Las imágenes de prueba se dibujan con GD y cada píxel DICE DE DÓNDE SALIÓ: R = x % 256,
 *    G = y % 256 y B = 200 (imagen_con_coordenadas). El azul fijo en 200 las distingue del relleno
 *    (blanco), del negro que ponía GD y de cualquier otro color, así que se puede afirmar píxel por
 *    píxel qué parte de la imagen cayó en qué lugar del resultado.
 *  - El color del relleno se lee de ImageCropHelper::COLOR_RELLENO: si algún día se cambia la
 *    constante, los tests siguen midiendo lo que importa (que el vacío sea ese color y no negro).
 *  - Los tests del endpoint (`i_*`) le pegan a POST api/set-image/{prop} con un usuario autenticado y
 *    la imagen como data URI base64, y borran del disco los archivos que el endpoint escribe en
 *    storage/app/public. Con `user` el archivo sale PNG (sin pérdida: se comparan píxeles exactos);
 *    con `article` sale webp (con pérdida: solo dimensiones y colores con tolerancia).
 *
 * PHP 7.4: este archivo no usa nada de la sintaxis ni de las funciones nuevas de PHP 8.
 *
 * @group recorte-imagen
 */
class Recorte_Con_Relleno_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Tolerancia por canal (0-255) al comparar colores de un webp: el formato guarda con pérdida
     * (calidad 90, YUV 4:2:0), así que un color plano vuelve con unos puntos de diferencia.
     */
    const TOLERANCIA_WEBP = 12;

    /** @var \Intervention\Image\ImageManager Manager GD, el mismo que arma el controlador. */
    protected $manager;

    /** @var \App\Models\User|null Dueño con el que se autentican los tests del endpoint (se crea recién cuando hace falta). */
    protected $comercio = null;

    /** @var array Rutas de los archivos que el endpoint dejó en storage/app/public y tearDown tiene que borrar. */
    protected $archivos_a_limpiar = [];

    /**
     * Arma el manager GD (el mismo que usa ImageController::setImage) antes de cada test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ImageManager();
    }

    /**
     * Borra del disco lo que el endpoint escribió. Se borran solo las rutas que este test registró
     * (nunca un comodín): en la misma carpeta pueden estar las imágenes de otra sesión.
     */
    protected function tearDown(): void
    {
        foreach ($this->archivos_a_limpiar as $ruta) {
            if ($ruta !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Imágenes de prueba y lectura de píxeles
    // ------------------------------------------------------------------------------------------

    /**
     * Imagen donde cada píxel dice de dónde salió: R = x % 256, G = y % 256, B = 200 (opaco).
     *
     * @param  int $ancho
     * @param  int $alto
     * @return \Intervention\Image\Image
     */
    protected function imagen_con_coordenadas($ancho, $alto)
    {
        $recurso = imagecreatetruecolor($ancho, $alto);

        for ($y = 0; $y < $alto; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                imagesetpixel($recurso, $x, $y, (($x % 256) << 16) | (($y % 256) << 8) | 200);
            }
        }

        return $this->manager->make($recurso);
    }

    /**
     * Imagen de dos colores planos: mitad izquierda y mitad derecha.
     *
     * @param  int   $ancho
     * @param  int   $alto
     * @param  array $color_izquierda [r, g, b]
     * @param  array $color_derecha   [r, g, b]
     * @return \Intervention\Image\Image
     */
    protected function imagen_de_dos_colores($ancho, $alto, array $color_izquierda, array $color_derecha)
    {
        $recurso = imagecreatetruecolor($ancho, $alto);

        $izquierda = imagecolorallocate($recurso, $color_izquierda[0], $color_izquierda[1], $color_izquierda[2]);
        $derecha   = imagecolorallocate($recurso, $color_derecha[0], $color_derecha[1], $color_derecha[2]);
        $mitad     = (int) ($ancho / 2);

        imagefilledrectangle($recurso, 0, 0, $mitad - 1, $alto - 1, $izquierda);
        imagefilledrectangle($recurso, $mitad, 0, $ancho - 1, $alto - 1, $derecha);

        return $this->manager->make($recurso);
    }

    /**
     * Color [r, g, b] del relleno, leído de la constante del helper (hoy es blanco).
     *
     * @return array
     */
    protected function color_de_relleno()
    {
        sscanf(ImageCropHelper::COLOR_RELLENO, '#%02x%02x%02x', $rojo, $verde, $azul);

        return [$rojo, $verde, $azul];
    }

    /**
     * Valor entero ARGB de GD (alfa 0 = opaco) de un color [r, g, b].
     *
     * @param  array $rgb
     * @return int
     */
    protected function entero_gd(array $rgb)
    {
        return ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
    }

    /**
     * Píxel (x, y) de una imagen como [r, g, b, alfa], con el alfa en la escala de GD
     * (0 = opaco, 127 = transparente).
     *
     * @param  \Intervention\Image\Image $imagen
     * @param  int $x
     * @param  int $y
     * @return array
     */
    protected function pixel(Image $imagen, $x, $y)
    {
        $argb = imagecolorat($imagen->getCore(), $x, $y);

        return [($argb >> 16) & 0xFF, ($argb >> 8) & 0xFF, $argb & 0xFF, ($argb >> 24) & 0x7F];
    }

    /**
     * Cuenta los píxeles de la región (x0, y0, ancho x alto) de `$imagen` que NO son la copia del
     * píxel de origen que les toca, suponiendo que la región es un pedazo de imagen_con_coordenadas()
     * cuyo primer píxel salió de (origen_x, origen_y). Compara el valor ARGB entero, o sea que un píxel
     * transparente cuenta como distinto aunque el RGB coincida.
     *
     * @return int Cantidad de píxeles distintos (0 = la región es exactamente el pedazo esperado).
     */
    protected function contar_no_de_origen(Image $imagen, $x0, $y0, $ancho, $alto, $origen_x, $origen_y)
    {
        $recurso   = $imagen->getCore();
        $distintos = 0;

        for ($y = 0; $y < $alto; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                $esperado = ((($origen_x + $x) % 256) << 16) | ((($origen_y + $y) % 256) << 8) | 200;

                if (imagecolorat($recurso, $x0 + $x, $y0 + $y) !== $esperado) {
                    $distintos++;
                }
            }
        }

        return $distintos;
    }

    /**
     * Cuenta los píxeles de la región (x0, y0, ancho x alto) que NO son el color de relleno opaco.
     *
     * @return int Cantidad de píxeles distintos (0 = toda la región es relleno).
     */
    protected function contar_no_relleno(Image $imagen, $x0, $y0, $ancho, $alto)
    {
        $recurso   = $imagen->getCore();
        $relleno   = $this->entero_gd($this->color_de_relleno());
        $distintos = 0;

        for ($y = 0; $y < $alto; $y++) {
            for ($x = 0; $x < $ancho; $x++) {
                if (imagecolorat($recurso, $x0 + $x, $y0 + $y) !== $relleno) {
                    $distintos++;
                }
            }
        }

        return $distintos;
    }

    /**
     * Cuenta los píxeles de dos imágenes del mismo tamaño que difieren (comparando el valor ARGB).
     *
     * @return int
     */
    protected function contar_diferencias(Image $a, Image $b)
    {
        $distintos = 0;

        for ($y = 0; $y < $a->height(); $y++) {
            for ($x = 0; $x < $a->width(); $x++) {
                if (imagecolorat($a->getCore(), $x, $y) !== imagecolorat($b->getCore(), $x, $y)) {
                    $distintos++;
                }
            }
        }

        return $distintos;
    }

    /**
     * Afirma que el píxel está a menos de `$tolerancia` puntos por canal del color esperado.
     *
     * @param  array  $esperado   [r, g, b]
     * @param  array  $pixel      [r, g, b, alfa]
     * @param  int    $tolerancia
     * @param  string $mensaje
     * @return void
     */
    protected function assert_color_cercano(array $esperado, array $pixel, $tolerancia, $mensaje)
    {
        for ($canal = 0; $canal < 3; $canal++) {
            $this->assertLessThanOrEqual(
                $tolerancia,
                abs($esperado[$canal] - $pixel[$canal]),
                $mensaje . ' — canal ' . $canal . ': esperado ' . $esperado[$canal] . ', salió ' . $pixel[$canal]
            );
        }
    }

    // ------------------------------------------------------------------------------------------
    // (a) Adentro de la imagen: el recorte de siempre
    // ------------------------------------------------------------------------------------------

    /**
     * (a) Con el rectángulo adentro de la imagen el resultado es EL MISMO que daba el `crop()` directo
     * de antes de este cambio: mismas dimensiones y los mismos píxeles. Es la garantía de "SPA viejo +
     * API nuevo": el SPA viejo siempre manda rectángulos adentro.
     *
     * @test
     */
    public function a_adentro_de_la_imagen_da_lo_mismo_que_el_recorte_de_siempre()
    {
        $recorte_de_siempre = $this->imagen_con_coordenadas(300, 200)->crop(120, 90, 40, 30);

        $con_el_helper = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 200), 40, 30, 120, 90);

        $this->assertSame(120, $con_el_helper->width());
        $this->assertSame(90, $con_el_helper->height());
        $this->assertSame($recorte_de_siempre->width(), $con_el_helper->width());
        $this->assertSame($recorte_de_siempre->height(), $con_el_helper->height());
        $this->assertSame(0, $this->contar_diferencias($recorte_de_siempre, $con_el_helper), 'El recorte de siempre y el del helper no son idénticos.');
        $this->assertSame(0, $this->contar_no_de_origen($con_el_helper, 0, 0, 120, 90, 40, 30), 'Los píxeles no son los de la zona (40,30) de la imagen.');
    }

    /**
     * (a) La imagen entera como rectángulo (el caso `cover` inicial del SPA cuando la imagen ya tiene
     * la proporción del marco) también va por el camino de siempre: sale idéntica a la original.
     *
     * @test
     */
    public function a_el_rectangulo_igual_a_la_imagen_entera_no_cambia_nada()
    {
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 120), 0, 0, 200, 120);

        $this->assertSame(200, $resultado->width());
        $this->assertSame(120, $resultado->height());
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 0, 0, 200, 120, 0, 0));
    }

    /**
     * (a) Los números pueden llegar como string (form-data) o con decimales (el navegador): se
     * normalizan a enteros y el recorte es el mismo que con enteros.
     *
     * @test
     */
    public function a_los_numeros_como_string_o_con_decimales_se_normalizan_a_enteros()
    {
        $con_strings = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 200), '40', '30', '120', '90');
        $con_decimales = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 200), 40.4, 29.6, 119.6, 90.4);

        foreach ([$con_strings, $con_decimales] as $resultado) {
            $this->assertSame(120, $resultado->width());
            $this->assertSame(90, $resultado->height());
            $this->assertSame(0, $this->contar_no_de_origen($resultado, 0, 0, 120, 90, 40, 30));
        }
    }

    // ------------------------------------------------------------------------------------------
    // (b) Imagen apaisada en marco cuadrado
    // ------------------------------------------------------------------------------------------

    /**
     * (b) El caso que pidió Lucas: una imagen 3:1 con el marco fijado en 1:1, alejada hasta que entra
     * entera. Sale un lienzo CUADRADO, con la imagen al centro y franjas de relleno arriba y abajo.
     * Y el relleno no es negro (lo que hacía GD antes).
     *
     * @test
     */
    public function b_imagen_3_a_1_en_marco_1_a_1_queda_centrada_con_franjas_de_relleno()
    {
        // Imagen de 300 x 100; marco de 300 x 300 con la imagen justo en el medio (100 px de vacío arriba y 100 abajo).
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 100), 0, -100, 300, 300);

        $this->assertSame(300, $resultado->width());
        $this->assertSame(300, $resultado->height());

        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, 300, 100), 'La franja de arriba tiene que ser toda relleno.');
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 0, 100, 300, 100, 0, 0), 'La imagen tiene que quedar entera en el medio.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 200, 300, 100), 'La franja de abajo tiene que ser toda relleno.');

        $esquina = $this->pixel($resultado, 0, 0);
        $this->assertNotSame([0, 0, 0], [$esquina[0], $esquina[1], $esquina[2]], 'El vacío no puede quedar negro.');
    }

    /**
     * (b) El alejamiento máximo que permite el SPA: el doble del punto en que la imagen entra entera.
     * Imagen 300 x 100 en marco 1:1: entra entera con un marco de 300; el máximo es 600. El marco
     * queda centrado en la imagen, o sea rodeándola por los cuatro lados.
     *
     * @test
     */
    public function b_el_alejamiento_maximo_del_spa_deja_la_imagen_en_el_centro_rodeada_de_relleno()
    {
        // Centro de la imagen = (150, 50); marco de 600 x 600 centrado ahí => esquina en (150 - 300, 50 - 300).
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 100), -150, -250, 600, 600);

        $this->assertSame(600, $resultado->width());
        $this->assertSame(600, $resultado->height());

        // La imagen entera, sin achicar, en (150, 250).
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 150, 250, 300, 100, 0, 0));

        // Las cuatro franjas de relleno que la rodean.
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, 600, 250), 'Franja de arriba.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 350, 600, 250), 'Franja de abajo.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 250, 150, 100), 'Franja de la izquierda.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 450, 250, 150, 100), 'Franja de la derecha.');
    }

    // ------------------------------------------------------------------------------------------
    // (c) Salida parcial
    // ------------------------------------------------------------------------------------------

    /**
     * (c) El marco se sale por la izquierda y por arriba: esas dos franjas son relleno y el resto es
     * la parte de la imagen que cae adentro, pegada en su lugar.
     *
     * @test
     */
    public function c_salida_parcial_por_izquierda_y_arriba_rellena_esas_franjas()
    {
        // Marco de 200 x 200 con la esquina en (-50, -30): la imagen empieza 50 px a la derecha y 30 px abajo del borde del marco.
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 200), -50, -30, 200, 200);

        $this->assertSame(200, $resultado->width());
        $this->assertSame(200, $resultado->height());

        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, 50, 200), 'Franja de la izquierda.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, 200, 30), 'Franja de arriba.');
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 50, 30, 150, 170, 0, 0), 'La parte visible tiene que ser la esquina (0,0) - (150,170) de la imagen.');
    }

    /**
     * (c) El marco se sale por la derecha y por abajo: la parte visible es la esquina inferior derecha
     * de la imagen, pegada arriba a la izquierda del lienzo, y el resto es relleno.
     *
     * @test
     */
    public function c_salida_parcial_por_derecha_y_abajo_rellena_esas_franjas()
    {
        // Marco de 200 x 200 con la esquina en (100, 120) sobre una imagen de 200 x 200: solo entran 100 x 80 px de imagen.
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 200), 100, 120, 200, 200);

        $this->assertSame(200, $resultado->width());
        $this->assertSame(200, $resultado->height());

        $this->assertSame(0, $this->contar_no_de_origen($resultado, 0, 0, 100, 80, 100, 120), 'La parte visible tiene que ser la zona (100,120) - (200,200).');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 100, 0, 100, 200), 'Franja de la derecha.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 80, 200, 120), 'Franja de abajo.');
    }

    // ------------------------------------------------------------------------------------------
    // (d) El marco rodea a la imagen
    // ------------------------------------------------------------------------------------------

    /**
     * (d) El marco es más grande que la imagen y la rodea por los cuatro lados: la imagen entra
     * ENTERA (sin perder ni un píxel) y alrededor queda un anillo de relleno.
     *
     * @test
     */
    public function d_marco_que_rodea_la_imagen_por_los_cuatro_lados()
    {
        // Imagen de 100 x 60 dentro de un marco de 200 x 200, en (40, 70).
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(100, 60), -40, -70, 200, 200);

        $this->assertSame(200, $resultado->width());
        $this->assertSame(200, $resultado->height());

        $this->assertSame(0, $this->contar_no_de_origen($resultado, 40, 70, 100, 60, 0, 0), 'La imagen tiene que estar entera.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, 200, 70), 'Arriba.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 130, 200, 70), 'Abajo.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 70, 40, 60), 'Izquierda.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 140, 70, 60, 60), 'Derecha.');
    }

    // ------------------------------------------------------------------------------------------
    // (e) Tolerancia
    // ------------------------------------------------------------------------------------------

    /**
     * Casos de sobra de hasta TOLERANCIA_PX píxeles, sobre una imagen de 200 x 100:
     * [left, top, width, height, ancho esperado, alto esperado, origen x, origen y].
     * Todos se resuelven recortando contra los bordes de la imagen: el resultado es un pedazo de la
     * imagen, sin una sola línea de relleno.
     *
     * @return array
     */
    public static function sobras_dentro_de_la_tolerancia()
    {
        return [
            'sobra 1 px por la derecha'  => [100, 0, 101, 100, 100, 100, 100, 0],
            'sobra 2 px por la derecha'  => [100, 0, 102, 100, 100, 100, 100, 0],
            'sobra 1 px por la izquierda' => [-1, 0, 101, 100, 100, 100, 0, 0],
            'sobra 2 px por la izquierda' => [-2, 0, 102, 100, 100, 100, 0, 0],
            'sobra 1 px por arriba'      => [0, -1, 100, 101, 100, 100, 0, 0],
            'sobra 2 px por arriba'      => [0, -2, 100, 102, 100, 100, 0, 0],
            'sobra 1 px por abajo'       => [50, 0, 100, 101, 100, 100, 50, 0],
            'sobra 2 px por abajo'       => [50, 0, 100, 102, 100, 100, 50, 0],
            '2 px por los cuatro lados'  => [-2, -2, 204, 104, 200, 100, 0, 0],
        ];
    }

    /**
     * (e) Una sobra de 1 o 2 píxeles (el redondeo del SPA) NO dispara el relleno: sin esto quedaba
     * una línea negra en el borde de la foto. El resultado es un pedazo de la imagen y ni un píxel
     * del borde es relleno.
     *
     * @test
     * @dataProvider sobras_dentro_de_la_tolerancia
     */
    public function e_una_sobra_de_uno_o_dos_pixeles_no_deja_linea_de_relleno($left, $top, $width, $height, $ancho_esperado, $alto_esperado, $origen_x, $origen_y)
    {
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 100), $left, $top, $width, $height);

        $this->assertSame($ancho_esperado, $resultado->width());
        $this->assertSame($alto_esperado, $resultado->height());
        $this->assertSame(
            0,
            $this->contar_no_de_origen($resultado, 0, 0, $ancho_esperado, $alto_esperado, $origen_x, $origen_y),
            'Todos los píxeles tienen que salir de la imagen: sobró una línea de relleno (o de negro).'
        );
    }

    /**
     * (e) El límite de la tolerancia: con UN píxel más que TOLERANCIA_PX ya es un recorte con marco
     * afuera de la imagen. El resultado mide lo pedido y esas columnas son relleno.
     *
     * @test
     */
    public function e_pasarse_un_pixel_mas_que_la_tolerancia_ya_usa_el_relleno()
    {
        $sobra = ImageCropHelper::TOLERANCIA_PX + 1;

        // Imagen de 200 x 100; el marco arranca en x = 100 y mide 100 + sobra: se pasa `sobra` píxeles por la derecha.
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 100), 100, 0, 100 + $sobra, 100);

        $this->assertSame(100 + $sobra, $resultado->width());
        $this->assertSame(100, $resultado->height());
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 0, 0, 100, 100, 100, 0), 'La parte visible tiene que ser la mitad derecha de la imagen.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 100, 0, $sobra, 100), 'Las columnas que se pasan tienen que ser relleno.');
    }

    // ------------------------------------------------------------------------------------------
    // (f) Recortes que no sirven
    // ------------------------------------------------------------------------------------------

    /**
     * Rectángulos que no tocan una imagen de 200 x 100: [left, top, width, height].
     *
     * @return array
     */
    public static function rectangulos_que_no_tocan_la_imagen()
    {
        return [
            'a la derecha'                    => [300, 0, 100, 100],
            'abajo'                           => [0, 200, 100, 100],
            'arriba'                          => [0, -150, 100, 100],
            'a la izquierda'                  => [-150, 0, 100, 100],
            'apenas tocando el borde derecho' => [200, 0, 50, 50],
            'apenas tocando el borde inferior' => [0, 100, 50, 50],
            'afuera pero a menos de la tolerancia' => [-2, 0, 2, 100],
        ];
    }

    /**
     * (f) Si el marco no toca la imagen (intersección vacía) no hay nada que guardar: el helper lanza
     * \InvalidArgumentException con un mensaje listo para mostrarle al usuario. Incluye el caso de un
     * marco que está afuera pero a menos de la tolerancia: la tolerancia no puede inventar una imagen.
     *
     * @test
     * @dataProvider rectangulos_que_no_tocan_la_imagen
     */
    public function f_el_marco_que_no_toca_la_imagen_lanza_la_excepcion($left, $top, $width, $height)
    {
        try {
            ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(200, 100), $left, $top, $width, $height);
            $this->fail('Tendría que haber lanzado InvalidArgumentException: el marco no toca la imagen.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no incluye ninguna parte de la imagen', $e->getMessage());
        }
    }

    /**
     * Medidas que no sirven: [left, top, width, height].
     *
     * @return array
     */
    public static function medidas_que_no_sirven()
    {
        return [
            'left null'                 => [null, 0, 10, 10],
            'left texto'                => ['abc', 0, 10, 10],
            'top vacío'                 => [0, '', 10, 10],
            'width es un array'         => [0, 0, [], 10],
            'height es un booleano'     => [0, 0, 10, true],
            'width infinito'            => [0, 0, INF, 10],
            'height NAN'                => [0, 0, 10, NAN],
            'left absurdamente grande'  => [1e12, 0, 10, 10],
            'width 0'                   => [0, 0, 0, 10],
            'height 0'                  => [0, 0, 10, 0],
            'width negativo'            => [0, 0, -5, 10],
            'width que redondea a 0'    => [0, 0, 0.4, 10],
        ];
    }

    /**
     * (f) Números que no son números, o un ancho / alto menor a 1 píxel: mismo tratamiento, excepción
     * con mensaje listo para mostrar (y nunca un error de Intervention en inglés ni un recorte raro).
     *
     * @test
     * @dataProvider medidas_que_no_sirven
     */
    public function f_las_medidas_que_no_sirven_lanzan_la_excepcion($left, $top, $width, $height)
    {
        try {
            ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(50, 50), $left, $top, $width, $height);
            $this->fail('Tendría que haber lanzado InvalidArgumentException: las medidas no sirven.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('El recorte', $e->getMessage());
            $this->assertStringContainsString('intentá de nuevo', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------
    // (j) Valores fijados a mano
    //
    // Los demás tests leen TOLERANCIA_PX y COLOR_RELLENO de las constantes del helper, así que
    // cambiar esas constantes deja todo en verde. Estos tres los fijan con números literales: quien
    // cambie la tolerancia, el color del relleno o el tope de valores absurdos tiene que tocar estos
    // tests a propósito. El SPA dibuja el vacío en blanco y cuenta con que una sobra de 1 o 2 píxeles
    // por redondeo no deje ninguna línea, así que esos números son parte del contrato con el SPA.
    // ------------------------------------------------------------------------------------------

    /**
     * (j) La tolerancia es de 2 píxeles y no de 3: con 2 de sobra el recorte va por el camino de
     * siempre (mide lo que entra en la imagen, sin relleno) y con 3 ya es un marco afuera de la imagen
     * (mide lo pedido y las columnas que se pasan son blancas).
     *
     * @test
     */
    public function j_la_tolerancia_es_de_dos_pixeles_y_no_de_tres()
    {
        // Imagen de 100 x 100 y 2 píxeles de sobra por la izquierda: un pedazo de la imagen, sin relleno.
        $con_dos = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(100, 100), -2, 0, 102, 100);

        $this->assertSame(100, $con_dos->width());
        $this->assertSame(100, $con_dos->height());
        $this->assertSame(0, $this->contar_no_de_origen($con_dos, 0, 0, 100, 100, 0, 0), 'Con 2 píxeles de sobra no puede haber relleno.');

        // 3 píxeles de sobra por la izquierda: mide lo pedido (103) y las tres primeras columnas son blancas.
        $con_tres = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(100, 100), -3, 0, 103, 100);

        $this->assertSame(103, $con_tres->width());
        $this->assertSame(100, $con_tres->height());
        $this->assertSame([255, 255, 255, 0], $this->pixel($con_tres, 0, 50), 'La primera columna tiene que ser blanca.');
        $this->assertSame([255, 255, 255, 0], $this->pixel($con_tres, 2, 50), 'La tercera columna tiene que ser blanca.');
        $this->assertSame(0, $this->contar_no_de_origen($con_tres, 3, 0, 100, 100, 0, 0), 'La imagen tiene que estar entera a partir de la columna 3.');
    }

    /**
     * (j) El espacio vacío se guarda en BLANCO exacto (#ffffff, opaco): es el color con el que el
     * modal del SPA dibuja lo vacío dentro del marco, así que lo que el usuario ve es lo que se guarda.
     *
     * @test
     */
    public function j_el_relleno_es_blanco_exacto()
    {
        $this->assertSame('#ffffff', ImageCropHelper::COLOR_RELLENO);

        // Imagen de 300 x 100 abajo de un marco cuadrado de 300 x 300: arriba quedan 200 filas de relleno.
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 100), 0, -200, 300, 300);

        $this->assertSame(300, $resultado->width());
        $this->assertSame(300, $resultado->height());
        $this->assertSame([255, 255, 255, 0], $this->pixel($resultado, 0, 0), 'Esquina superior izquierda.');
        $this->assertSame([255, 255, 255, 0], $this->pixel($resultado, 299, 199), 'Último píxel de relleno, justo arriba de la imagen.');
        $this->assertSame([0, 0, 200, 0], $this->pixel($resultado, 0, 200), 'Primer píxel de la imagen (origen 0,0).');
    }

    /**
     * (j) Un ancho, un alto o una posición absurdos (1e12) se rechazan por "demasiado grande" y no
     * por otra vía: sin ese tope el entero podría desbordar al convertirlo o al sumarlo, y el marco de
     * 1e12 de ancho terminaba en un lienzo de 2500 x 1 en vez de en un error claro.
     *
     * @test
     */
    public function j_un_ancho_un_alto_o_una_posicion_gigante_se_rechaza_por_demasiado_grande()
    {
        $casos = [
            'ancho gigante'    => [0, 0, 1e12, 10],
            'alto gigante'     => [0, 0, 10, 1e12],
            'left gigante'     => [1e12, 0, 10, 10],
            'top gigante'      => [0, -1e12, 10, 10],
        ];

        foreach ($casos as $nombre => $medidas) {
            try {
                ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(50, 50), $medidas[0], $medidas[1], $medidas[2], $medidas[3]);
                $this->fail('Tendría que haber lanzado InvalidArgumentException: ' . $nombre . '.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('demasiado grande', $e->getMessage(), $nombre);
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // (g) Tope de lado
    // ------------------------------------------------------------------------------------------

    /**
     * (g) Un lienzo que pasa MAX_LADO_CON_RELLENO de lado se achica proporcionalmente (el lado mayor
     * queda en el tope y se conserva la proporción del marco), y la imagen se achica con él y queda
     * en el mismo lugar relativo.
     *
     * @test
     */
    public function g_el_lienzo_que_pasa_el_tope_de_lado_se_achica_proporcionalmente()
    {
        $tope = ImageCropHelper::MAX_LADO_CON_RELLENO;

        // Marco del doble del tope de ancho y el tope de alto (2:1), con una imagen roja de 300 x 100 justo en el centro.
        $marco_ancho = $tope * 2;
        $marco_alto  = $tope;
        $left        = (int) ((300 - $marco_ancho) / 2);
        $top         = (int) ((100 - $marco_alto) / 2);

        $roja = $this->imagen_de_dos_colores(300, 100, [255, 0, 0], [255, 0, 0]);

        $resultado = ImageCropHelper::crop($this->manager, $roja, $left, $top, $marco_ancho, $marco_alto);

        // Escala 0,5: el lado mayor queda en el tope y la proporción 2:1 se mantiene.
        $this->assertSame($tope, $resultado->width());
        $this->assertSame((int) ($tope / 2), $resultado->height());

        // La imagen (ahora de 150 x 50) queda centrada: centro del lienzo = (tope / 2, tope / 4).
        $centro_x = (int) ($tope / 2);
        $centro_y = (int) ($tope / 4);

        $this->assertSame([255, 0, 0], array_slice($this->pixel($resultado, $centro_x, $centro_y), 0, 3), 'El centro del lienzo tiene que ser la imagen.');
        $this->assertSame([255, 0, 0], array_slice($this->pixel($resultado, $centro_x - 70, $centro_y), 0, 3), 'La imagen achicada mide 150 px de ancho: 70 px a la izquierda del centro sigue siendo imagen.');
        $this->assertSame([255, 0, 0], array_slice($this->pixel($resultado, $centro_x + 70, $centro_y), 0, 3), '70 px a la derecha del centro sigue siendo imagen.');

        // Y afuera de la imagen achicada (150 x 50 en el centro) es relleno.
        $this->assertSame($this->entero_gd($this->color_de_relleno()), imagecolorat($resultado->getCore(), $centro_x - 80, $centro_y), '80 px a la izquierda del centro ya es relleno.');
        $this->assertSame($this->entero_gd($this->color_de_relleno()), imagecolorat($resultado->getCore(), $centro_x + 80, $centro_y), '80 px a la derecha del centro ya es relleno.');
        $this->assertSame($this->entero_gd($this->color_de_relleno()), imagecolorat($resultado->getCore(), $centro_x, $centro_y - 30), '30 px arriba del centro ya es relleno.');
        $this->assertSame($this->entero_gd($this->color_de_relleno()), imagecolorat($resultado->getCore(), $centro_x, $centro_y + 30), '30 px abajo del centro ya es relleno.');
        $this->assertSame($this->entero_gd($this->color_de_relleno()), imagecolorat($resultado->getCore(), 0, 0), 'La esquina es relleno.');
    }

    /**
     * (g) Cuando el lado mayor es el ALTO, el tope se aplica igual: el marco de 1000 x 4000 sale de
     * 625 x 2500 (escala 0,625).
     *
     * @test
     */
    public function g_el_tope_tambien_se_aplica_cuando_el_lado_mayor_es_el_alto()
    {
        $tope = ImageCropHelper::MAX_LADO_CON_RELLENO;

        // Marco de 1000 x (tope * 1,6): el alto es el lado mayor y pasa el tope; la imagen (300 x 100) queda adentro.
        $marco_alto = (int) ($tope * 1.6);

        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 100), -300, -1000, 1000, $marco_alto);

        $escala = $tope / $marco_alto;

        $this->assertSame($tope, $resultado->height());
        $this->assertSame((int) round(1000 * $escala), $resultado->width());
    }

    /**
     * (g) Un lienzo justo del tamaño del tope NO se achica: la imagen se pega 1:1, sin remuestrear.
     *
     * @test
     */
    public function g_un_lienzo_justo_en_el_tope_no_se_achica()
    {
        $tope = ImageCropHelper::MAX_LADO_CON_RELLENO;

        // Imagen de 300 x 100 pegada en (1000, 1200) de un marco de tope x tope.
        $resultado = ImageCropHelper::crop($this->manager, $this->imagen_con_coordenadas(300, 100), -1000, -1200, $tope, $tope);

        $this->assertSame($tope, $resultado->width());
        $this->assertSame($tope, $resultado->height());
        $this->assertSame(0, $this->contar_no_de_origen($resultado, 1000, 1200, 300, 100, 0, 0), 'Sin achicar, la imagen tiene que estar pegada píxel por píxel.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 0, $tope, 100), 'Las primeras filas de la franja de arriba.');
        $this->assertSame(0, $this->contar_no_relleno($resultado, 0, 1300, 1000, 100), 'Las filas de abajo de la imagen, hasta su borde izquierdo.');
    }

    // ------------------------------------------------------------------------------------------
    // (h) PNG con transparencia
    // ------------------------------------------------------------------------------------------

    /**
     * PNG de 100 x 100 con alfa real, ya decodificado como lo decodifica el controlador:
     * mitad izquierda azul opaca; mitad derecha totalmente transparente, salvo una franja arriba que
     * es roja al ~50 % de opacidad.
     *
     * @return \Intervention\Image\Image
     */
    protected function png_con_transparencia()
    {
        $recurso = imagecreatetruecolor(100, 100);
        imagealphablending($recurso, false);
        imagesavealpha($recurso, true);

        imagefill($recurso, 0, 0, imagecolorallocatealpha($recurso, 0, 0, 0, 127));
        imagefilledrectangle($recurso, 0, 0, 49, 99, imagecolorallocatealpha($recurso, 0, 0, 255, 0));
        imagefilledrectangle($recurso, 50, 0, 99, 19, imagecolorallocatealpha($recurso, 255, 0, 0, 64));

        ob_start();
        imagepng($recurso);
        $binario = ob_get_clean();
        imagedestroy($recurso);

        // Pasa por un PNG de verdad (bytes), igual que la imagen que sube un usuario.
        return $this->manager->make($binario);
    }

    /**
     * (h) Un PNG con transparencia que cae en el camino con relleno queda COMPUESTO sobre el color de
     * relleno: lo transparente se ve del color del relleno, lo semitransparente se mezcla con él, y
     * ningún píxel del resultado queda transparente (en jpg o webp sin alfa habría salido negro).
     *
     * @test
     */
    public function h_un_png_con_transparencia_queda_compuesto_sobre_el_relleno()
    {
        $relleno = $this->color_de_relleno();

        // Marco de 140 x 140 con la imagen (100 x 100) en (20, 20).
        $resultado = ImageCropHelper::crop($this->manager, $this->png_con_transparencia(), -20, -20, 140, 140);

        $this->assertSame(140, $resultado->width());
        $this->assertSame(140, $resultado->height());

        // Relleno de afuera: color de relleno, opaco.
        $this->assertSame([$relleno[0], $relleno[1], $relleno[2], 0], $this->pixel($resultado, 5, 5));

        // Mitad azul opaca: sigue azul.
        $this->assertSame([0, 0, 255, 0], $this->pixel($resultado, 20 + 25, 20 + 60));

        // Mitad totalmente transparente: se ve el relleno, y opaco.
        $this->assertSame([$relleno[0], $relleno[1], $relleno[2], 0], $this->pixel($resultado, 20 + 75, 20 + 60), 'Lo transparente tiene que quedar del color del relleno.');

        // Franja roja al ~50 %: mezcla de rojo y relleno. Alfa GD 64 de 127 => el rojo pesa 63/127 y el relleno 64/127.
        $mezcla = $this->pixel($resultado, 20 + 75, 20 + 10);
        $this->assertSame(0, $mezcla[3], 'La franja semitransparente tiene que quedar opaca.');
        $this->assert_color_cercano(
            [
                (int) round((255 * 63 + $relleno[0] * 64) / 127),
                (int) round((0 * 63 + $relleno[1] * 64) / 127),
                (int) round((0 * 63 + $relleno[2] * 64) / 127),
            ],
            $mezcla,
            3,
            'La franja roja semitransparente tiene que mezclarse con el relleno'
        );

        // Y ningún píxel del resultado quedó transparente.
        $transparentes = 0;
        for ($y = 0; $y < 140; $y += 7) {
            for ($x = 0; $x < 140; $x += 7) {
                $pixel = $this->pixel($resultado, $x, $y);
                if ($pixel[3] !== 0) {
                    $transparentes++;
                }
            }
        }
        $this->assertSame(0, $transparentes, 'El resultado no puede tener píxeles transparentes.');
    }

    /**
     * (h) El camino de siempre NO cambia con la transparencia: un recorte adentro de la imagen deja el
     * PNG tan transparente como estaba (el relleno solo aplica cuando el marco se sale).
     *
     * @test
     */
    public function h_adentro_de_la_imagen_la_transparencia_se_conserva_como_siempre()
    {
        // Recorte adentro que abarca la mitad transparente (columnas 50 a 99).
        $resultado = ImageCropHelper::crop($this->manager, $this->png_con_transparencia(), 50, 30, 50, 60);

        $this->assertSame(50, $resultado->width());
        $this->assertSame(60, $resultado->height());
        $this->assertSame(127, $this->pixel($resultado, 25, 30)[3], 'La transparencia de la imagen no se toca si el marco no se sale.');
    }

    // ------------------------------------------------------------------------------------------
    // (i) Contra el endpoint real: POST api/set-image/{prop}
    // ------------------------------------------------------------------------------------------

    /**
     * Crea (una sola vez por test) el dueño con el que se autentican los tests del endpoint.
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        if (is_null($this->comercio)) {
            $this->comercio = User::create([
                'name'         => 'Comercio recorte con relleno',
                'company_name' => 'Ferreteria recorte',
                'email'        => 'recorte-relleno-' . uniqid() . '@test.local',
                'password'     => Hash::make('secret'),
            ]);
        }

        return $this->comercio;
    }

    /**
     * Data URI base64 (PNG) de una imagen, como la manda el SPA cuando el usuario sube un archivo.
     *
     * @param  \Intervention\Image\Image $imagen
     * @return string
     */
    protected function data_uri_png(Image $imagen)
    {
        return 'data:image/png;base64,' . base64_encode((string) $imagen->encode('png'));
    }

    /**
     * Le pega a POST api/set-image/{prop} como el dueño autenticado. Si el endpoint guardó un archivo
     * lo registra para que tearDown lo borre, ANTES de que el test haga ninguna aserción (así se
     * limpia aunque el test falle).
     *
     * @param  string $prop    `has_many` o el nombre de la propiedad (por ejemplo `image_url`).
     * @param  array  $payload Cuerpo del request.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function subir_por_el_endpoint($prop, array $payload)
    {
        $respuesta = $this->actingAs($this->comercio(), 'sanctum')->postJson('api/set-image/' . $prop, $payload);

        if ($respuesta->getStatusCode() === 200) {
            $url = $respuesta->json('image_url');

            if (is_string($url) && $url !== '') {
                $this->archivos_a_limpiar[] = storage_path('app/public/' . basename((string) parse_url($url, PHP_URL_PATH)));
            }
        }

        return $respuesta;
    }

    /**
     * Ruta en disco del archivo que guardó el endpoint, a partir de la `image_url` de la respuesta.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @return string
     */
    protected function ruta_del_archivo_guardado($respuesta)
    {
        return storage_path('app/public/' . basename((string) parse_url($respuesta->json('image_url'), PHP_URL_PATH)));
    }

    /**
     * (i) Contra el endpoint real, con `user` (el archivo sale PNG, sin pérdida): una imagen 3:1 con el
     * marco 1:1 alejado hasta que entra entera. Se lee el archivo del disco y se comparan píxeles
     * exactos: lienzo cuadrado, imagen en el medio, franjas de relleno arriba y abajo.
     *
     * @test
     */
    public function i_endpoint_recorte_con_relleno_de_un_usuario_sale_png_sin_perdida()
    {
        $imagen = $this->imagen_con_coordenadas(300, 100);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 0,
            'top'        => -100,
            'width'      => 300,
            'height'     => 300,
        ]);

        $respuesta->assertStatus(200);

        $url = $respuesta->json('image_url');
        $this->assertStringEndsWith('.png', $url, 'El logo del negocio se guarda siempre como png.');

        $archivo = $this->ruta_del_archivo_guardado($respuesta);
        $this->assertFileExists($archivo);

        $guardada = $this->manager->make($archivo);

        $this->assertSame(300, $guardada->width());
        $this->assertSame(300, $guardada->height());
        $this->assertSame(0, $this->contar_no_relleno($guardada, 0, 0, 300, 100), 'Franja de arriba.');
        $this->assertSame(0, $this->contar_no_de_origen($guardada, 0, 100, 300, 100, 0, 0), 'La imagen entera en el medio, píxel por píxel.');
        $this->assertSame(0, $this->contar_no_relleno($guardada, 0, 200, 300, 100), 'Franja de abajo.');
    }

    /**
     * (i) Contra el endpoint real, con `article` (el archivo sale webp, con pérdida): se verifican las
     * dimensiones exactas y los colores con tolerancia. Es el flujo de una foto de un artículo nuevo
     * (`has_many`, sin id todavía).
     *
     * @test
     */
    public function i_endpoint_recorte_con_relleno_de_un_articulo_sale_webp()
    {
        // Imagen de 300 x 100: mitad izquierda roja, mitad derecha azul.
        $imagen = $this->imagen_de_dos_colores(300, 100, [255, 0, 0], [0, 0, 255]);

        $respuesta = $this->subir_por_el_endpoint('has_many', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'article',
            'model_id'   => null,
            'left'       => 0,
            'top'        => -100,
            'width'      => 300,
            'height'     => 300,
        ]);

        $respuesta->assertStatus(200);

        $url = $respuesta->json('image_url');
        $this->assertStringEndsWith('.webp', $url, 'Las fotos de artículos se guardan como webp.');

        $archivo = $this->ruta_del_archivo_guardado($respuesta);
        $this->assertFileExists($archivo);

        $guardada = $this->manager->make($archivo);
        $relleno  = $this->color_de_relleno();

        $this->assertSame(300, $guardada->width());
        $this->assertSame(300, $guardada->height());

        // Franjas de relleno (centros de las zonas planas, lejos de los bordes).
        $this->assert_color_cercano($relleno, $this->pixel($guardada, 150, 40), self::TOLERANCIA_WEBP, 'Franja de arriba');
        $this->assert_color_cercano($relleno, $this->pixel($guardada, 150, 260), self::TOLERANCIA_WEBP, 'Franja de abajo');

        // La imagen en el medio (ocupa las filas 100 a 199): rojo a la izquierda, azul a la derecha.
        $this->assert_color_cercano([255, 0, 0], $this->pixel($guardada, 75, 150), self::TOLERANCIA_WEBP, 'Mitad roja de la imagen');
        $this->assert_color_cercano([0, 0, 255], $this->pixel($guardada, 225, 150), self::TOLERANCIA_WEBP, 'Mitad azul de la imagen');
    }

    /**
     * (i) Contra el endpoint real, el contrato con el SPA viejo: un rectángulo ADENTRO de la imagen (lo
     * único que mandaba el SPA antes) da exactamente el mismo archivo que el `crop()` de siempre.
     *
     * @test
     */
    public function i_endpoint_recorte_adentro_de_la_imagen_da_lo_mismo_que_antes()
    {
        $imagen = $this->imagen_con_coordenadas(300, 200);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 40,
            'top'        => 30,
            'width'      => 120,
            'height'     => 90,
        ]);

        $respuesta->assertStatus(200);

        $guardada = $this->manager->make($this->ruta_del_archivo_guardado($respuesta));

        $this->assertSame(120, $guardada->width());
        $this->assertSame(90, $guardada->height());
        $this->assertSame(0, $this->contar_no_de_origen($guardada, 0, 0, 120, 90, 40, 30));
    }

    /**
     * (i) "Guardar SIN Recortar" no cambia: sin `top` el endpoint guarda la imagen entera, tal cual.
     *
     * @test
     */
    public function i_endpoint_sin_recorte_guarda_la_imagen_entera()
    {
        $imagen = $this->imagen_con_coordenadas(300, 100);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
        ]);

        $respuesta->assertStatus(200);

        $guardada = $this->manager->make($this->ruta_del_archivo_guardado($respuesta));

        $this->assertSame(300, $guardada->width());
        $this->assertSame(100, $guardada->height());
        $this->assertSame(0, $this->contar_no_de_origen($guardada, 0, 0, 300, 100, 0, 0));
    }

    /**
     * (f) Contra el endpoint real: un marco que no toca la imagen responde 422 con
     * `image_error = invalid_crop`, un `message` listo para mostrar y SIN la clave `errors` (el
     * interceptor del SPA le arma otro toast a cualquier 422 que la traiga). No guarda nada.
     *
     * @test
     */
    public function f_endpoint_un_marco_que_no_toca_la_imagen_responde_422_sin_errors()
    {
        $imagen = $this->imagen_con_coordenadas(300, 100);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 1000,
            'top'        => 1000,
            'width'      => 100,
            'height'     => 100,
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['image_error' => 'invalid_crop']);
        $this->assertArrayNotHasKey('errors', $respuesta->json(), 'Con `errors` el interceptor del SPA arma otro toast.');
        $this->assertArrayNotHasKey('image_url', $respuesta->json(), 'No se guardó nada: no hay image_url.');

        $mensaje = $respuesta->json('message');
        $this->assertIsString($mensaje);
        $this->assertStringContainsString('no incluye ninguna parte de la imagen', $mensaje);
    }

    /**
     * (f) Contra el endpoint real: medidas que no son números, o un ancho de 0, también son 422 con
     * `invalid_crop` y sin `errors`; antes eran un 500 con el error de Intervention en inglés.
     *
     * @test
     */
    public function f_endpoint_las_medidas_que_no_sirven_responden_422_sin_errors()
    {
        $imagen = $this->imagen_con_coordenadas(300, 100);

        $con_texto = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 'abc',
            'top'        => 0,
            'width'      => 100,
            'height'     => 100,
        ]);

        $con_ancho_cero = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => $this->data_uri_png($imagen),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 0,
            'top'        => 0,
            'width'      => 0,
            'height'     => 100,
        ]);

        foreach ([$con_texto, $con_ancho_cero] as $respuesta) {
            $respuesta->assertStatus(422);
            $respuesta->assertJson(['image_error' => 'invalid_crop']);
            $this->assertArrayNotHasKey('errors', $respuesta->json(), 'Con `errors` el interceptor del SPA arma otro toast.');
            $this->assertStringContainsString('El recorte que se envió no es válido', $respuesta->json('message'));
        }
    }

    // ------------------------------------------------------------------------------------------
    // (k) Orientación EXIF: la foto de un celular se endereza ANTES de recortar
    //
    // El SPA muestra y recorta la foto YA enderezada (el navegador y la librería de recorte aplican
    // el EXIF) y manda las coordenadas sobre esa foto; el servidor recortaba sobre los píxeles
    // crudos. `Image::orientate()` de Intervention no sirve acá: sobre una imagen cargada desde
    // binario no hace nada (ver el docblock de ImageCropHelper::orient_by_exif).
    // ------------------------------------------------------------------------------------------

    /**
     * Bytes de un JPEG de `$ancho` x `$alto` con la mitad izquierda ROJA y la derecha AZUL (los
     * píxeles CRUDOS) y la etiqueta EXIF de orientación `$orientacion` (o sin EXIF si es null). Es lo
     * que guarda un celular: píxeles "acostados" más una etiqueta que dice cómo girarlos al mostrarlos.
     *
     * @param  int      $ancho
     * @param  int      $alto
     * @param  int|null $orientacion 1 a 8, o null para un JPEG sin EXIF.
     * @return string Bytes del JPEG.
     */
    protected function jpeg_con_orientacion($ancho, $alto, $orientacion = null)
    {
        $recurso = imagecreatetruecolor($ancho, $alto);
        $rojo    = imagecolorallocate($recurso, 255, 0, 0);
        $azul    = imagecolorallocate($recurso, 0, 0, 255);
        $mitad   = (int) ($ancho / 2);

        imagefilledrectangle($recurso, 0, 0, $mitad - 1, $alto - 1, $rojo);
        imagefilledrectangle($recurso, $mitad, 0, $ancho - 1, $alto - 1, $azul);

        ob_start();
        imagejpeg($recurso, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($recurso);

        if ($orientacion === null) {
            return $jpeg;
        }

        // APP1 EXIF mínimo: "Exif\0\0" + cabecera TIFF (big-endian) + un solo tag, Orientation (0x0112, SHORT).
        $tiff = "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', $orientacion) . "\x00\x00"
            . pack('N', 0);
        $app1 = "Exif\x00\x00" . $tiff;

        // El segmento APP1 va justo después de la marca de inicio (FF D8).
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /**
     * Orientaciones EXIF sobre un JPEG crudo de 200 x 100 (izquierda roja, derecha azul):
     * [orientación, ancho enderezado, alto enderezado, [x, y] que tiene que ser ROJO, [x, y] que tiene que ser AZUL].
     *
     * @return array
     */
    public static function orientaciones_exif()
    {
        return [
            '2 espejo horizontal'     => [2, 200, 100, [175, 50], [25, 50]],
            '3 boca abajo'            => [3, 200, 100, [175, 50], [25, 50]],
            '6 vertical (de celular)' => [6, 100, 200, [50, 25], [50, 175]],
            '8 vertical al revés'     => [8, 100, 200, [50, 175], [50, 25]],
        ];
    }

    /**
     * (k) Cada orientación se endereza: cambian las dimensiones cuando corresponde (6 y 8 pasan de
     * 200 x 100 a 100 x 200) y la mitad roja / azul cae del lado que le toca al mostrar la foto.
     *
     * @test
     * @dataProvider orientaciones_exif
     */
    public function k_la_orientacion_exif_se_endereza($orientacion, $ancho_esperado, $alto_esperado, array $rojo, array $azul)
    {
        $bytes = $this->jpeg_con_orientacion(200, 100, $orientacion);

        $imagen = ImageCropHelper::orient_by_exif($this->manager->make($bytes), $bytes);

        $this->assertSame($ancho_esperado, $imagen->width());
        $this->assertSame($alto_esperado, $imagen->height());
        $this->assert_color_cercano([255, 0, 0], $this->pixel($imagen, $rojo[0], $rojo[1]), 40, 'Donde tiene que quedar la mitad roja');
        $this->assert_color_cercano([0, 0, 255], $this->pixel($imagen, $azul[0], $azul[1]), 40, 'Donde tiene que quedar la mitad azul');
    }

    /**
     * (k) Un JPEG sin EXIF, o con orientación 1 (ya derecha), no se gira: sigue de 200 x 100 con la
     * mitad roja a la izquierda.
     *
     * @test
     */
    public function k_sin_exif_o_con_orientacion_uno_no_se_gira_nada()
    {
        foreach ([null, 1] as $orientacion) {
            $bytes = $this->jpeg_con_orientacion(200, 100, $orientacion);

            $imagen = ImageCropHelper::orient_by_exif($this->manager->make($bytes), $bytes);

            $this->assertSame(200, $imagen->width());
            $this->assertSame(100, $imagen->height());
            $this->assert_color_cercano([255, 0, 0], $this->pixel($imagen, 25, 50), 40, 'Izquierda roja');
            $this->assert_color_cercano([0, 0, 255], $this->pixel($imagen, 175, 50), 40, 'Derecha azul');
        }
    }

    /**
     * (k) Lo que no es un JPEG con EXIF (un PNG, texto, null, un array, vacío) se devuelve intacto y
     * sin lanzar nada: enderezar nunca puede romper un guardado que antes andaba.
     *
     * @test
     */
    public function k_lo_que_no_es_un_jpeg_con_exif_se_devuelve_intacto_y_sin_excepciones()
    {
        $png = (string) $this->imagen_con_coordenadas(60, 30)->encode('png');

        $casos = [
            'un PNG'   => $png,
            'texto'    => 'esto no es una imagen',
            'null'     => null,
            'un array' => [],
            'vacío'    => '',
        ];

        foreach ($casos as $nombre => $origen) {
            $imagen = $this->imagen_con_coordenadas(60, 30);

            $resultado = ImageCropHelper::orient_by_exif($imagen, $origen);

            $this->assertSame($imagen, $resultado, $nombre . ': tiene que devolver la misma instancia.');
            $this->assertSame(60, $resultado->width(), $nombre);
            $this->assertSame(30, $resultado->height(), $nombre);
        }
    }

    /**
     * (k) El origen también puede ser un data URI (así llega una foto subida desde el navegador).
     *
     * @test
     */
    public function k_un_data_uri_jpeg_tambien_se_endereza()
    {
        $bytes = $this->jpeg_con_orientacion(200, 100, 6);
        $uri   = 'data:image/jpeg;base64,' . base64_encode($bytes);

        $imagen = ImageCropHelper::orient_by_exif($this->manager->make($bytes), $uri);

        $this->assertSame(100, $imagen->width());
        $this->assertSame(200, $imagen->height());
    }

    /**
     * (k) Contra el endpoint real: una foto vertical de celular (crudo 200 x 100 con orientación 6,
     * enderezada 100 x 200: arriba roja y abajo azul). El SPA marca la mitad de ABAJO de la foto
     * enderezada (left 0, top 100, 100 x 100): el archivo guardado tiene que ser azul de punta a
     * punta. Sin enderezar, ese rectángulo cae afuera de los píxeles crudos (solo tienen 100 de alto)
     * y sale casi todo relleno blanco.
     *
     * @test
     */
    public function k_endpoint_el_recorte_cae_sobre_la_foto_enderezada()
    {
        $bytes = $this->jpeg_con_orientacion(200, 100, 6);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => 'data:image/jpeg;base64,' . base64_encode($bytes),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
            'left'       => 0,
            'top'        => 100,
            'width'      => 100,
            'height'     => 100,
        ]);

        $respuesta->assertStatus(200);

        $guardada = $this->manager->make($this->ruta_del_archivo_guardado($respuesta));

        $this->assertSame(100, $guardada->width());
        $this->assertSame(100, $guardada->height());
        $this->assert_color_cercano([0, 0, 255], $this->pixel($guardada, 50, 50), 40, 'Centro del recorte: la mitad de abajo de la foto enderezada es azul');
        $this->assert_color_cercano([0, 0, 255], $this->pixel($guardada, 5, 95), 40, 'Esquina inferior izquierda: no puede haber relleno, el recorte estaba adentro de la foto enderezada');
    }

    /**
     * (k) Contra el endpoint real, "Guardar SIN Recortar" (sin coordenadas): la foto de celular se
     * guarda enderezada (100 x 200, arriba roja y abajo azul) y no acostada (200 x 100).
     *
     * @test
     */
    public function k_endpoint_sin_recortar_guarda_la_foto_enderezada()
    {
        $bytes = $this->jpeg_con_orientacion(200, 100, 6);

        $respuesta = $this->subir_por_el_endpoint('image_url', [
            'image_url'  => 'data:image/jpeg;base64,' . base64_encode($bytes),
            'model_name' => 'user',
            'model_id'   => $this->comercio()->id,
        ]);

        $respuesta->assertStatus(200);

        $guardada = $this->manager->make($this->ruta_del_archivo_guardado($respuesta));

        $this->assertSame(100, $guardada->width());
        $this->assertSame(200, $guardada->height());
        $this->assert_color_cercano([255, 0, 0], $this->pixel($guardada, 50, 25), 40, 'Arriba roja');
        $this->assert_color_cercano([0, 0, 255], $this->pixel($guardada, 50, 175), 40, 'Abajo azul');
    }
}
