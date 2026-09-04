<?php

namespace Tests\Feature\Semilla;

use App\Models\Article;
use App\Models\Image;
use App\Models\Provider;
use App\Models\User;
use App\Observers\ArticleObserver;
use Database\Seeders\FerreteriaArticlesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión seeder-articulos-imagenes-nuevas — el catálogo usa las fotos que Lucas eligió a
 * mano en vez de las que bajaba `semilla:imagenes` de Wikimedia Commons.
 *
 * Protege el mapa nuevo (`database/seeders/data/ferreteria_imagenes.php`, indexado por
 * `name`) contra dos formas silenciosas de romperse: un artículo que debería tener foto y no
 * la recibe, y un artículo sin entrada en el mapa que igual queda con una asignada. Ninguna de
 * las dos tira excepción — el seeder está escrito para no reventar por una foto — así que sin
 * este test las dos quedan invisibles hasta que alguien mira la ficha a mano.
 */
class ImagenesDeArticulosNuevasTest extends TestCase
{
    use DatabaseTransactions;

    /** Nombre exacto de un artículo del catálogo CON entrada en el mapa nuevo. */
    const ARTICULO_CON_FOTO = 'CESTO DE BASURA CON PORTA PAPEL GRIS STOLF';

    /** Archivo que el mapa nuevo le asigna a ARTICULO_CON_FOTO. */
    const ARCHIVO_DE_ARTICULO_CON_FOTO = '178768453523817.webp';

    /** Nombre exacto de un artículo del catálogo SIN entrada en el mapa nuevo. */
    const ARTICULO_SIN_FOTO = 'ESCOBILLON 375 X 45MM CERDA RIGIDA GARDEX';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        ArticleObserver::resetear_cache_gate();

        $this->comercio = User::create([
            'name'         => 'Comercio imagenes semilla',
            'company_name' => 'Ferreteria semilla imagenes',
            'email'        => 'imagenes-semilla-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);

        for ($i = 1; $i <= 3; $i++) {
            Provider::create([
                'name'    => 'Proveedor imagenes ' . $i,
                'user_id' => $this->comercio->id,
            ]);
        }
    }

    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    /**
     * Imagen del artículo, o null si no tiene ninguna.
     *
     * @param \App\Models\Article $articulo
     * @return \App\Models\Image|null
     */
    protected function imagen_de($articulo)
    {
        return Image::where('imageable_type', 'article')
            ->where('imageable_id', $articulo->id)
            ->first();
    }

    /**
     * @test
     */
    public function un_articulo_con_entrada_en_el_mapa_queda_con_la_foto_de_article_images_2()
    {
        Storage::fake('public');
        Storage::disk('public')->put(
            FerreteriaArticlesSeeder::CARPETA_IMAGENES . '/' . self::ARCHIVO_DE_ARTICULO_CON_FOTO,
            'contenido-de-prueba'
        );

        (new FerreteriaArticlesSeeder())->run();

        $articulo = Article::where('user_id', $this->comercio->id)
            ->where('name', self::ARTICULO_CON_FOTO)
            ->firstOrFail();

        $imagen = $this->imagen_de($articulo);

        $this->assertNotNull($imagen);
        $this->assertStringContainsString(
            'article-images-2/' . self::ARCHIVO_DE_ARTICULO_CON_FOTO,
            $imagen->hosting_url
        );
    }

    /**
     * @test
     */
    public function un_articulo_sin_entrada_en_el_mapa_queda_sin_foto()
    {
        Storage::fake('public');

        (new FerreteriaArticlesSeeder())->run();

        $articulo = Article::where('user_id', $this->comercio->id)
            ->where('name', self::ARTICULO_SIN_FOTO)
            ->firstOrFail();

        $this->assertNull($this->imagen_de($articulo));
    }

    /**
     * @test
     */
    public function un_articulo_con_entrada_en_el_mapa_pero_sin_el_archivo_en_disco_queda_sin_foto_y_no_revienta()
    {
        // El disco fake queda vacío a propósito: el mapa tiene la entrada, el archivo no está.
        Storage::fake('public');

        (new FerreteriaArticlesSeeder())->run();

        $articulo = Article::where('user_id', $this->comercio->id)
            ->where('name', self::ARTICULO_CON_FOTO)
            ->firstOrFail();

        $this->assertNull($this->imagen_de($articulo));
    }
}
