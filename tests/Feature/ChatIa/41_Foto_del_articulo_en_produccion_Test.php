<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — arreglo del 🔴 1 del chequeo adversarial: la foto del artículo
 * salía rota EN PRODUCCIÓN y en ningún otro lado.
 *
 * 🔴 ESTE ARCHIVO CORRE CON `APP_ENV` EN 'production', QUE ES LA RAMA QUE NO SE PROBABA NUNCA.
 * El test 38 siembra `https://fotos.test/martillo.jpg` y corre en 'testing', así que
 * `ArticleHelper::getFirstImage()` le devolvía la URL tal cual y todo daba verde — mientras en
 * producción esa misma URL volvía como `"public/https://fotos.test/martillo.jpg"` y el asistente
 * le decía al dueño "no tiene foto cargada" teniendo la foto. Es la clase "el test que siembra lo
 * que producción no tiene".
 *
 * Lo que protege: que `ApiUrlHelper::url_publica_de_imagen()` y `ArticleHelper::
 * primera_imagen_publica()` devuelvan una URL absoluta y correcta en las TRES formas que existen
 * de verdad en el parque —absoluta de esta instalación, absoluta de otro host y ruta relativa—,
 * en hosting compartido (con `/public`) y en VPS (sin él); y que los dos consumidores del
 * asistente (los adjuntos y la ficha de mención) la usen.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 *
 * @group chat-ia
 */
class Foto_del_articulo_en_produccion_Test extends TestCase
{
    use DatabaseTransactions;

    /** El dominio de la API de un cliente, como está cargado en su `.env` (sin `/public`). */
    const API = 'https://api-cliente.comerciocity.com';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio fotos produccion',
            'company_name' => 'Ferreteria fotos produccion',
            'email'        => 'fotos-prod-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Pone la config de un cliente real: `APP_ENV` en producción y `APP_URL` sin `/public` (es el
     * contrato de ApiUrlHelper), en hosting compartido o en VPS.
     *
     * @param  bool  $vps
     * @return void
     */
    protected function como_en_produccion($vps = false)
    {
        config([
            'app.APP_ENV' => 'production',
            'app.APP_URL' => self::API,
            'app.VPS'     => $vps,
        ]);
    }

    /**
     * Un artículo con una sola foto, con la URL tal como quedaría guardada en `images.hosting_url`.
     *
     * @param  string  $nombre
     * @param  string  $hosting_url
     * @return Article
     */
    protected function articulo_con_foto($nombre, $hosting_url)
    {
        $article = Article::create(['name' => $nombre, 'user_id' => $this->comercio->id, 'status' => 'active']);

        Image::create(['hosting_url' => $hosting_url, 'imageable_type' => 'article', 'imageable_id' => $article->id]);

        return $article;
    }

    /**
     * 🔴 HOSTING COMPARTIDO: las tres formas terminan en la misma URL, con UN SOLO `/public`.
     *
     * - La fila vieja se guardó sin `/public` (antes de que ApiUrlHelper centralizara la regla):
     *   hay que agregárselo, que es para lo que existía la rama de producción de getFirstImage().
     * - La fila de hoy ya lo trae (`ImageController::setImage` llama a `ApiUrlHelper::storage()`):
     *   hay que dejarla como está, y es justo lo que getFirstImage() rompía duplicándolo.
     * - La fila con `/public/public` es el bug del 27/7/2026 ya guardado en la base: se colapsa.
     *
     * @test
     */
    public function en_hosting_compartido_las_tres_formas_dan_la_misma_url()
    {
        $this->como_en_produccion(false);

        $esperada = self::API . '/public/storage/1758.webp';

        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen(self::API . '/storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen(self::API . '/public/storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen(self::API . '/public/public/storage/1758.webp'));

        // Y la ruta relativa, que es la otra forma en la que se puede guardar el valor.
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen('storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen('/storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen('1758.webp'));
    }

    /**
     * EN VPS la API vive en la raíz, así que la URL va SIN `/public` — incluidas las filas que
     * quedaron con el segmento de cuando ese cliente estaba en hosting compartido.
     *
     * @test
     */
    public function en_vps_la_url_va_sin_public()
    {
        $this->como_en_produccion(true);

        $esperada = self::API . '/storage/1758.webp';

        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen(self::API . '/storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen(self::API . '/public/storage/1758.webp'));
        $this->assertSame($esperada, ApiUrlHelper::url_publica_de_imagen('storage/1758.webp'));
    }

    /**
     * 🔴 UNA URL DE OTRO HOST SE DEVUELVE TAL CUAL. Son las de R2, las del `APP_IMAGES_URL` de la
     * demo y las que InventoryLinkageHelper copió desde otra cuenta: de un dominio ajeno no
     * sabemos si sirve desde `/public` o desde la raíz.
     *
     * Y acá queda asentado lo que hacía getFirstImage() con ese mismo valor, que es el defecto que
     * este arreglo cierra: `strpos($url, 'storage')` da false, `substr($url, 0, false)` da '' y el
     * retorno es la URL con `public/` pegado adelante.
     *
     * @test
     */
    public function una_url_de_otro_host_no_se_toca_y_la_funcion_vieja_la_rompia()
    {
        $this->como_en_produccion(false);

        $externa = 'https://fotos.test/martillo.jpg';

        $this->assertSame($externa, ApiUrlHelper::url_publica_de_imagen($externa));

        $articulo = $this->articulo_con_foto('zz-c41 Martillo externo', $externa);

        $this->assertSame($externa, ArticleHelper::primera_imagen_publica($articulo));

        /*
         * ⚠️ Esta aserción decía que `getFirstImage()` devolvía `"public/<url>"` — el defecto que
         * motivó escribir `primera_imagen_publica()`. El mismo 21/9, en paralelo, la misión del
         * logo del Ticket 2.0 sacó ese reprocesado de `getFirstImage()`, así que para una URL ya
         * absoluta las dos devuelven lo mismo. Se afirma eso, que es lo verdadero hoy; lo que
         * sigue justificando a `primera_imagen_publica()` son las formas que la vieja NO
         * normaliza, y que este archivo cubre en los otros tests (relativa, data:, vacía).
         */
        $this->assertSame($externa, ArticleHelper::getFirstImage($articulo));
    }

    /**
     * Lo que no da para armar una URL devuelve null, y no una cadena rota: el llamador lo cuenta
     * como "sin foto", que es la verdad.
     *
     * @test
     */
    public function lo_que_no_es_una_url_devuelve_null()
    {
        $this->como_en_produccion(false);

        $this->assertNull(ApiUrlHelper::url_publica_de_imagen(''));
        $this->assertNull(ApiUrlHelper::url_publica_de_imagen(null));
        $this->assertNull(ApiUrlHelper::url_publica_de_imagen('data:image/png;base64,iVBORw0KGgo='));
        $this->assertNull(ApiUrlHelper::url_publica_de_imagen('//cdn.ajeno.com/foto.webp'));
    }

    /**
     * Con varias fotos se elige la misma que elegía getFirstImage().
     *
     * ⚠️ La tabla `images` NO tiene columna `first` (medido el 21/9/2026 sobre el esquema: id,
     * hosting_url, imageable_id, imageable_type, color_id, temporal_id, tiendanube_image_id,
     * tienda_nube_image_id y los timestamps; ninguna migración la agrega). O sea que el `foreach`
     * de getFirstImage() que busca `$image->first != 0` nunca entra —`null != 0` es false— y la
     * foto que sale es siempre la primera de la relación. primera_imagen_publica() copia ese
     * `foreach` igual, para no cambiar la elección si algún cliente viejo sí tiene la columna.
     *
     * @test
     */
    public function con_varias_fotos_elige_la_primera_igual_que_la_funcion_vieja()
    {
        $this->como_en_produccion(false);

        $articulo = $this->articulo_con_foto('zz-c41 Martillo con dos fotos', self::API . '/storage/primera.webp');

        Image::create([
            'hosting_url'    => self::API . '/storage/segunda.webp',
            'imageable_type' => 'article',
            'imageable_id'   => $articulo->id,
        ]);

        $articulo->load('images');

        $this->assertSame(self::API . '/public/storage/primera.webp', ArticleHelper::primera_imagen_publica($articulo));
    }

    /**
     * 🔴 DE PUNTA A PUNTA, EN PRODUCCIÓN: el artículo con la foto guardada como la guarda hoy
     * ImageController sale como adjunto con su URL absoluta, y no en `sin_imagen`.
     *
     * Antes de este arreglo el mismo caso salía `.../public/public/storage/...`: pasaba el filtro
     * de "absoluta" y terminaba en una imagen rota en el chat y en un 404 mandado a Meta.
     *
     * @test
     */
    public function el_adjunto_sale_con_la_url_correcta_en_produccion()
    {
        $this->como_en_produccion(false);

        // La URL se siembra con la MISMA llamada que usa ImageController::setImage() al guardar.
        $con = $this->articulo_con_foto('zz-c41 Martillo con foto', ApiUrlHelper::storage('1758.webp'));

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, [$con->id]);

        $this->assertSame([], $resultado['sin_imagen']);
        $this->assertTrue($resultado['articulos'][0]['tiene_imagen']);

        $this->assertSame([
            [
                'tipo'        => 'imagen',
                'url'         => self::API . '/public/storage/1758.webp',
                'texto'       => 'zz-c41 Martillo con foto',
                'articulo_id' => $con->id,
            ],
        ], $resultado['adjuntos_de_la_respuesta']);
    }

    /**
     * 🔴 Y el caso que dejaba al dueño sin ver su foto: una URL sin la palabra `storage` adentro.
     * Antes volvía como `"public/https://..."`, no pasaba el filtro de absoluta y el artículo caía
     * en `sin_imagen` — el asistente le contestaba "no tiene foto cargada" teniendo la foto.
     *
     * @test
     */
    public function una_url_sin_la_palabra_storage_ya_no_cae_en_sin_imagen()
    {
        $this->como_en_produccion(false);

        $con = $this->articulo_con_foto('zz-c41 Martillo de R2', 'https://fotos.comerciocity.com/martillo.jpg');

        $resultado = AdjuntosIaHelper::imagenes_de_articulos($this->comercio->id, [$con->id]);

        $this->assertSame([], $resultado['sin_imagen']);
        $this->assertSame('https://fotos.comerciocity.com/martillo.jpg', $resultado['adjuntos_de_la_respuesta'][0]['url']);
    }

    /**
     * La ficha de una mención pinta la misma foto por el mismo camino.
     *
     * @test
     */
    public function la_ficha_de_mencion_usa_la_url_resuelta()
    {
        $this->como_en_produccion(false);

        $articulo = $this->articulo_con_foto('zz-c41 Martillo de ficha', self::API . '/storage/1758.webp');

        $ficha = FichaArticuloIaHelper::ficha($articulo->id, $this->comercio->id, $this->comercio);

        $this->assertSame(self::API . '/public/storage/1758.webp', $ficha['imagen_url']);
    }
}
