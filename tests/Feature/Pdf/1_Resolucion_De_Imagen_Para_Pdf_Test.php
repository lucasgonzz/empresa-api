<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\GeneralHelper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests de la resolucion de imagenes para PDF (grupo 372).
 * Estrena la suite `pdf-imagenes`.
 *
 * Protege un defecto que fallaba en SILENCIO: pdf_image_path() prometia en su docblock
 * "ruta local usable por FPDF" y en el camino de fallback devolvia la URL http tal cual.
 * El consumidor decide con file_exists()/is_file(), que son SIEMPRE false para un http://
 * porque el wrapper HTTP de PHP no implementa stat(), asi que la celda del PDF quedaba
 * vacia sin excepcion, sin log y sin nada que investigar. Para verlo a mano hay que tener
 * un articulo con imagen JPG remota, acordarse de que ese caso existe, y mirar el PDF.
 *
 * El test que mas importa es `nunca_devuelve_una_url`: si alguien "simplifica" el fallback
 * y vuelve a devolver la URL --que es la forma obvia de escribirlo-- se pone rojo antes de
 * que nadie genere un PDF.
 *
 * No toca la base y no toca la red: los archivos se generan con GD y las descargas van con
 * Http::fake(), igual que la suite `imagenes-google`.
 *
 * @group pdf-imagenes
 */
class Resolucion_De_Imagen_Para_Pdf_Test extends TestCase
{
    /** Prefijo unico de esta corrida, para no pisar nada del storage de trabajo. */
    protected $prefijo;

    /** Archivos creados por el test, para borrarlos en tearDown(). */
    protected $archivos_creados = [];

    /** Directorios creados por el test, para borrarlos en tearDown(). */
    protected $directorios_creados = [];

    /** Archivos que ya estaban en pdf_cache antes del test: esos NO se tocan. */
    protected $cache_previo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefijo = 'test_pdfimg_'.uniqid();
        $this->archivos_creados = [];
        $this->directorios_creados = [];

        // El inventario del cache va ANTES del markTestSkipped(): tearDown() corre igual
        // cuando setUp() se corta, y con la lista vacia el diff borraria todo pdf_cache,
        // que es cache legitimo de la maquina y no nuestro.
        $this->cache_previo = $this->listar_cache();

        // Sin GD la mitad de esto no aplica, y un rojo ahi seria ruido de entorno y no una
        // regresion del codigo.
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('La extension GD no esta disponible en este entorno.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->archivos_creados as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        // Lo que el codigo bajo prueba haya dejado en pdf_cache durante esta corrida.
        foreach ($this->listar_cache() as $archivo) {
            if (! in_array($archivo, $this->cache_previo)) {
                @unlink($archivo);
            }
        }

        foreach (array_reverse($this->directorios_creados) as $directorio) {
            if (is_dir($directorio)) {
                @rmdir($directorio);
            }
        }

        parent::tearDown();
    }

    /**
     * Un JPG que existe en storage se devuelve como la ruta de disco, sin dar vueltas.
     * Es el camino que hoy funciona y que este grupo no puede romper.
     *
     * @test
     */
    public function un_jpg_local_se_devuelve_como_ruta_local()
    {
        $ruta = $this->crear_jpg($this->prefijo.'.jpg');
        $url = 'https://api-cliente.comerciocity.com/storage/'.$this->prefijo.'.jpg';

        $resultado = GeneralHelper::pdf_image_path($url);

        $this->assertSame($ruta, $resultado);
        $this->assertTrue(is_file($resultado));
    }

    /**
     * Protege la regresion del regex: la captura era de UN solo segmento despues de
     * storage/, asi que una URL con subcarpetas resolvia a la primera CARPETA. Un
     * directorio pasa file_exists(), llega hasta FPDF y lo hace reventar sobre una carpeta.
     *
     * @test
     */
    public function una_subcarpeta_de_storage_resuelve_al_archivo_y_no_a_la_carpeta()
    {
        $ruta = $this->crear_jpg($this->prefijo.'_dir/foto.jpg');
        $url = 'https://api-cliente.comerciocity.com/public/storage/'.$this->prefijo.'_dir/foto.jpg';

        $resultado = GeneralHelper::storage_public_path_from_image_url($url);

        $this->assertSame($ruta, $resultado);
        $this->assertFalse(is_dir($resultado));
        $this->assertTrue(is_file($resultado));
    }

    /**
     * El test central del grupo. Una imagen remota que no existe en disco tiene que
     * terminar en un archivo local igual: si vuelve a salir una URL, el PDF la descarta
     * en silencio y nadie se entera.
     *
     * @test
     */
    public function nunca_devuelve_una_url()
    {
        $bytes = $this->bytes_de_un_jpg();
        Http::fake(['*' => Http::response($bytes, 200)]);

        $resultado = GeneralHelper::pdf_image_path('https://cdn-ajeno.example.com/fotos/pieza.jpg');

        $this->assertNotNull($resultado);
        $this->assertStringStartsNotWith('http', $resultado);
        $this->assertTrue(is_file($resultado));

        $info = getimagesize($resultado);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    /**
     * Un catalogo pasa por la misma imagen muchas veces. Sin cache, cada PDF seria una
     * descarga por articulo y por corrida.
     *
     * @test
     */
    public function la_segunda_llamada_no_vuelve_a_la_red()
    {
        $bytes = $this->bytes_de_un_jpg();
        Http::fake(['*' => Http::response($bytes, 200)]);

        $url = 'https://cdn-ajeno.example.com/fotos/pieza-cacheada.jpg';
        $primero = GeneralHelper::pdf_image_path($url);
        $segundo = GeneralHelper::pdf_image_path($url);

        $this->assertSame($primero, $segundo);
        Http::assertSentCount(1);
    }

    /**
     * Cuando no se puede resolver, la respuesta correcta es null. Devolver la URL como
     * consuelo es exactamente el bug que estamos cerrando.
     *
     * @test
     */
    public function una_descarga_fallida_devuelve_null_y_no_la_url()
    {
        Http::fake(['*' => Http::response('', 403)]);

        $resultado = GeneralHelper::pdf_image_path('https://cdn-ajeno.example.com/fotos/no-existe.jpg');

        $this->assertNull($resultado);
    }

    /**
     * ArticleTablePdf extiende fpdf, no AlphaPDF: no dibuja PNG con canal alfa. Todo tiene
     * que llegar como JPEG baseline o no se dibuja.
     *
     * @test
     */
    public function un_png_local_se_materializa_como_jpeg()
    {
        // Red de contencion: este camino lee del disco y no deberia salir a la red nunca.
        Http::fake();

        $ruta_png = $this->crear_png($this->prefijo.'.png');
        $url = 'https://api-cliente.comerciocity.com/storage/'.$this->prefijo.'.png';

        $resultado = GeneralHelper::pdf_image_path($url);

        $this->assertNotNull($resultado);
        $this->assertNotSame($ruta_png, $resultado);
        $this->assertTrue(is_file($resultado));

        $info = getimagesize($resultado);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    /**
     * No-regresion del camino que hoy funciona en produccion: los .webp que ya tienen su
     * .jpg convertido en storage se siguen sirviendo de ahi, sin volver a bajar nada.
     *
     * @test
     */
    public function el_webp_ya_convertido_sigue_funcionando_igual()
    {
        Http::fake();

        $ruta_jpg = $this->crear_jpg($this->prefijo.'_webp.jpg');
        $url = 'https://api-cliente.comerciocity.com/storage/'.$this->prefijo.'_webp.webp';

        $resultado = GeneralHelper::pdf_image_path($url);

        $this->assertSame($ruta_jpg, $resultado);
        Http::assertNothingSent();
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────

    /**
     * Crea un JPG real de 10x10 bajo storage/app/public/ y lo agenda para borrar.
     *
     * @param  string  $ruta_relativa
     * @return string
     */
    protected function crear_jpg($ruta_relativa)
    {
        $ruta = $this->preparar_ruta($ruta_relativa);
        $imagen = imagecreatetruecolor(10, 10);
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);
        $this->archivos_creados[] = $ruta;

        return $ruta;
    }

    /**
     * Crea un PNG real de 10x10 bajo storage/app/public/ y lo agenda para borrar.
     *
     * @param  string  $ruta_relativa
     * @return string
     */
    protected function crear_png($ruta_relativa)
    {
        $ruta = $this->preparar_ruta($ruta_relativa);
        $imagen = imagecreatetruecolor(10, 10);
        imagepng($imagen, $ruta);
        imagedestroy($imagen);
        $this->archivos_creados[] = $ruta;

        return $ruta;
    }

    /**
     * Resuelve la ruta absoluta bajo storage/app/public/ y crea el directorio si hace falta.
     *
     * @param  string  $ruta_relativa
     * @return string
     */
    protected function preparar_ruta($ruta_relativa)
    {
        $ruta = storage_path('app/public/'.$ruta_relativa);
        $directorio = dirname($ruta);

        if (! is_dir($directorio)) {
            mkdir($directorio, 0775, true);
            $this->directorios_creados[] = $directorio;
        }

        return $ruta;
    }

    /**
     * Bytes de un JPEG valido, generados con GD: no se commitean fixtures binarios.
     *
     * @return string
     */
    protected function bytes_de_un_jpg()
    {
        $imagen = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($imagen, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    /**
     * Listado actual de pdf_cache. Se compara antes/despues para borrar solo lo que dejo
     * esta corrida y no el cache legitimo de la maquina.
     *
     * @return array
     */
    protected function listar_cache()
    {
        $directorio = storage_path('app/public/pdf_cache');

        if (! is_dir($directorio)) {
            return [];
        }

        $archivos = glob($directorio.'/*');

        return is_array($archivos) ? $archivos : [];
    }
}
