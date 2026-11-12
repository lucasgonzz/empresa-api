<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\TiendaDeLaPruebaHelper;
use App\Models\OnlineConfiguration;
use App\Models\PruebaConfig;
use App\Models\Title;
use App\Models\User;
use App\Services\DemoIndustriaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\EmpresaTestCase;

/**
 * La tienda online de la prueba gratuita es la del negocio y deja hacer un pedido de prueba (misión
 * prueba-e2e-campana-imperio, ronda 3, E2).
 *
 * En el ensayo del 12/11 la tienda de «Ferretería Los Aromos» seguía siendo «PUNTO FIERRO · DEMO» (logo,
 * banner, menú y la presentación «Este negocio es una demostración de ComercioCity…») y no tenía «agregar al
 * carrito»: «Registrarse para comprar» prendido y los artículos importados sin stock. Acá se arma la tienda
 * como la deja `DemoIndustriaService::preparar` en una prueba y se corre lo que la arregla.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class TiendaDeLaPruebaTest extends EmpresaTestCase
{
    const NOMBRE = 'Ferretería Los Aromos';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config(['app.FOR_USER' => 'prueba']);
        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);

        PruebaConfig::updateOrCreate(['owner_id' => $this->dueno->id], ['dias' => 7]);
        PruebaHelper::olvidar_cache();

        // La tienda como nace en una prueba: la de la marca de ejemplo del rubro, con el nombre del alta.
        OnlineConfiguration::firstOrCreate(['user_id' => $this->dueno->id]);
        OnlineConfiguration::where('user_id', $this->dueno->id)->update(['register_to_buy' => 1, 'ignorar_stock' => 0]);
        $this->assertTrue(DemoIndustriaService::preparar($this->dueno, 'ferreteria', self::NOMBRE));
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        parent::tearDown();
    }

    private function configuracion(): OnlineConfiguration
    {
        return OnlineConfiguration::where('user_id', $this->dueno->id)->firstOrFail();
    }

    /**
     * La ruta del disco `public` de una URL generada por el helper.
     *
     * @param  string $url
     * @return string
     */
    private function archivo_de(string $url): string
    {
        $posicion = strpos($url, '/storage/');
        $this->assertNotFalse($posicion, $url);

        return substr($url, $posicion + strlen('/storage/'));
    }

    public function test_la_tienda_de_la_prueba_lleva_el_nombre_del_negocio_y_deja_pedir_sin_registro_ni_stock(): void
    {
        // El punto de partida es el del ensayo.
        $antes = $this->configuracion();
        $this->assertStringContainsString('demo-industrias/', (string) $antes->logo_url);
        $this->assertStringContainsString('Punto Fierro', (string) $antes->titulo_quienes_somos);
        $this->assertStringContainsString('demostración de ComercioCity', (string) $antes->quienes_somos);
        $this->assertSame(1, (int) $antes->register_to_buy);

        $resultado = TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);
        $this->assertSame(['pedidos' => true, 'identidad' => true], $resultado);

        $configuracion = $this->configuracion();

        // (b) Un visitante compra sin registrarse y aunque el artículo no tenga stock.
        $this->assertSame(0, (int) $configuracion->register_to_buy);
        $this->assertSame(1, (int) $configuracion->ignorar_stock);

        // (a) Ni una huella de la marca de ejemplo en lo que muestra la tienda.
        $visible = json_encode([
            $configuracion->logo_url, $configuracion->default_article_image_url,
            $configuracion->titulo_quienes_somos, $configuracion->quienes_somos,
            Title::where('user_id', $this->dueno->id)->get(['image_url', 'crop_image_url', 'header', 'lead'])->toArray(),
        ], JSON_UNESCAPED_UNICODE);
        foreach (['Punto Fierro', 'Demo', 'demo-industrias/', 'demostración'] as $de_ejemplo) {
            $this->assertStringNotContainsString($de_ejemplo, $visible, $de_ejemplo);
        }

        $this->assertSame('Conocé ' . self::NOMBRE, $configuracion->titulo_quienes_somos);
        $this->assertStringContainsString('<h2>' . e(self::NOMBRE) . '</h2>', $configuracion->quienes_somos);

        // El logo y los banners son imágenes generadas con el nombre, guardadas y servidas desde el storage.
        $this->assertStringContainsString('/storage/' . TiendaDeLaPruebaHelper::CARPETA . '/', $configuracion->logo_url);
        $this->assertSame($configuracion->logo_url, $configuracion->default_article_image_url);
        $logo = Storage::disk('public')->get($this->archivo_de($configuracion->logo_url));
        $this->assertNotSame('', $logo);
        if (function_exists('imagettftext')) {
            $this->assertSame("\x89PNG", substr($logo, 0, 4), 'Con GD y FreeType el logo es un PNG (los correos no muestran SVG).');
            $medidas = getimagesizefromstring($logo);
            $this->assertSame([600, 600], [$medidas[0], $medidas[1]]);
        }

        $banners = Title::where('user_id', $this->dueno->id)->get();
        $this->assertGreaterThan(0, $banners->count());
        foreach ($banners as $banner) {
            $this->assertTrue(Storage::disk('public')->exists($this->archivo_de($banner->image_url)));
            $this->assertTrue(Storage::disk('public')->exists($this->archivo_de($banner->crop_image_url)));
            $this->assertNull($banner->header);
        }
    }

    public function test_dos_corridas_dejan_lo_mismo(): void
    {
        TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);
        $primera = $this->configuracion()->only(['logo_url', 'default_article_image_url', 'titulo_quienes_somos', 'quienes_somos']);
        $banners = Title::where('user_id', $this->dueno->id)->orderBy('id')->pluck('image_url')->all();

        TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);

        $this->assertSame($primera, $this->configuracion()->only(['logo_url', 'default_article_image_url', 'titulo_quienes_somos', 'quienes_somos']));
        $this->assertSame($banners, Title::where('user_id', $this->dueno->id)->orderBy('id')->pluck('image_url')->all());
    }

    public function test_si_el_nombre_del_negocio_cambia_las_imagenes_se_rehacen_con_el_nuevo(): void
    {
        TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);
        $logo_viejo = $this->configuracion()->logo_url;

        $this->dueno->company_name = 'Corralón Los Aromos';
        $this->dueno->save();
        TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);

        $configuracion = $this->configuracion();
        $this->assertNotSame($logo_viejo, $configuracion->logo_url, 'Otra URL: el navegador no sigue mostrando el logo viejo.');
        $this->assertSame('Conocé Corralón Los Aromos', $configuracion->titulo_quienes_somos);
    }

    public function test_lo_que_el_dueno_ya_cambio_en_su_tienda_no_se_toca(): void
    {
        OnlineConfiguration::where('user_id', $this->dueno->id)->update([
            'logo_url' => 'https://cdn.ejemplo.com/mi-logo.png',
            'titulo_quienes_somos' => 'Quiénes somos',
            'quienes_somos' => '<p>Somos una ferretería de barrio desde 1987.</p>',
        ]);
        Title::where('user_id', $this->dueno->id)->update(['image_url' => 'https://cdn.ejemplo.com/mi-banner.jpg', 'crop_image_url' => 'https://cdn.ejemplo.com/mi-banner-cel.jpg']);

        TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);

        $configuracion = $this->configuracion();
        $this->assertSame('https://cdn.ejemplo.com/mi-logo.png', $configuracion->logo_url);
        $this->assertSame('Quiénes somos', $configuracion->titulo_quienes_somos);
        $this->assertSame('<p>Somos una ferretería de barrio desde 1987.</p>', $configuracion->quienes_somos);
        $this->assertSame(['https://cdn.ejemplo.com/mi-banner.jpg'], Title::where('user_id', $this->dueno->id)->pluck('image_url')->unique()->values()->all());
        // La imagen por defecto de los artículos sí era de la marca de ejemplo: esa se reemplaza.
        $this->assertStringNotContainsString('demo-industrias/', (string) $configuracion->default_article_image_url);
    }

    public function test_el_pool_sin_nombre_propio_abre_los_pedidos_pero_no_inventa_identidad(): void
    {
        // Una prueba del pool armada antes de tener lead: lleva el nombre de la marca de ejemplo.
        $this->assertTrue(DemoIndustriaService::preparar($this->dueno, 'ferreteria'));
        $logo = $this->configuracion()->logo_url;

        $resultado = TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id);

        $this->assertSame(['pedidos' => true, 'identidad' => false], $resultado);
        $this->assertSame($logo, $this->configuracion()->logo_url);
        $this->assertSame(0, (int) $this->configuracion()->register_to_buy);
    }

    public function test_fuera_de_una_prueba_no_toca_nada(): void
    {
        $antes = $this->configuracion()->toArray();

        PruebaConfig::where('owner_id', $this->dueno->id)->delete();
        PruebaHelper::olvidar_cache();
        $this->assertSame(['pedidos' => false, 'identidad' => false], TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id));

        PruebaConfig::updateOrCreate(['owner_id' => $this->dueno->id], ['dias' => 7]);
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();
        $this->assertSame(['pedidos' => false, 'identidad' => false], TiendaDeLaPruebaHelper::dejar_tienda_del_negocio($this->dueno->id));

        $this->assertSame($antes, $this->configuracion()->toArray());
        $this->assertSame([], Storage::disk('public')->allFiles(TiendaDeLaPruebaHelper::CARPETA));
    }

    public function test_el_comando_de_las_pruebas_ya_armadas_tambien_deja_la_tienda(): void
    {
        $this->artisan('prueba:lista-para-vender')
            ->expectsOutput('Tienda: pedidos de prueba habilitados · nombre del negocio puesto')
            ->assertExitCode(0);

        $this->assertSame(0, (int) $this->configuracion()->register_to_buy);
        $this->assertSame('Conocé ' . self::NOMBRE, $this->configuracion()->titulo_quienes_somos);
    }
}
