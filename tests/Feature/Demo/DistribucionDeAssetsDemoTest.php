<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Models\Article;
use App\Services\DemoIndustriaAssets;
use Database\Seeders\DemoIndustriaArticlesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DistribucionDeAssetsDemoTest extends TestCase
{
    use DatabaseTransactions;

    private $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }
        parent::tearDown();
    }

    public function test_el_manifiesto_cubre_los_378_archivos_reales_de_los_catorce_rubros()
    {
        $cantidad = 0;
        foreach (config('demo_industrias') as $slug => $perfil) {
            $this->assertSame(27, DemoIndustriaAssets::exigir($slug));
            $cantidad += count(DemoIndustriaAssets::rutas($slug, $perfil));
        }
        $this->assertSame(378, $cantidad);
        $this->assertCount($cantidad, $this->manifiesto()['archivos']);
    }

    public function test_un_setup_con_imagen_faltante_frena_antes_de_vaciar_la_base()
    {
        $perfil = config('demo_industrias.ferreteria');
        $perfil['productos'][0]['image_url'] = 'demo-industrias/ferreteria/productos/no-distribuida.webp';
        config(['app.FOR_USER' => 'demo', 'demo_industrias.ferreteria' => $perfil]);
        Artisan::shouldReceive('call')->never();
        $antes = Article::count();
        try {
            DemoSetupHelper::run(['business_type' => 'ferreteria', 'demo_industria' => 'ferreteria']);
            $this->fail('El setup aceptó una foto que no se distribuyó.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Falta la imagen de demo', $error->getMessage());
        }
        $this->assertSame($antes, Article::count());
    }

    public function test_el_seeder_directo_tambien_rechaza_archivos_faltantes_sin_crear_articulos()
    {
        $perfil = config('demo_industrias.ferreteria');
        $perfil['logo_url'] = 'demo-industrias/ferreteria/no-distribuido.webp';
        config(['demo_industria_actual' => 'ferreteria', 'demo_industrias.ferreteria' => $perfil]);
        $antes = Article::count();
        try {
            (new DemoIndustriaArticlesSeeder())->run();
            $this->fail('El seeder aceptó un logo que no se distribuyó.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Falta la imagen de demo', $error->getMessage());
        }
        $this->assertSame($antes, Article::count());
    }

    public function test_el_archivo_presente_pero_alterado_no_pasa_la_verificacion()
    {
        $manifiesto = $this->manifiesto();
        $ruta = 'public/demo-industrias/ferreteria/logo.webp';
        $manifiesto['archivos'][$ruta]['sha256'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La integridad de la imagen');
        DemoIndustriaAssets::verificar_perfil('ferreteria', config('demo_industrias.ferreteria'), public_path(), $manifiesto);
    }

    public function test_un_paquete_con_los_assets_completos_y_sin_uploads_es_valido()
    {
        $this->assertSame(378, DemoIndustriaAssets::verificar_paquete($this->paquete(), $this->manifiesto()));
    }

    public function test_el_paquete_anterior_sin_assets_es_rechazado()
    {
        $archivo = $this->paquete(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El paquete no contiene la imagen de demo íntegra');
        DemoIndustriaAssets::verificar_paquete($archivo, $this->manifiesto());
    }

    /** @dataProvider rutas_a_preservar */
    public function test_el_paquete_no_puede_sobrescribir_archivos_ajenos_de_produccion($ruta)
    {
        $archivo = $this->paquete();
        $zip = new ZipArchive();
        $zip->open($archivo);
        $zip->addFromString($ruta, 'No distribuir');
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('debe preservarse en el destino');
        DemoIndustriaAssets::verificar_paquete($archivo, $this->manifiesto());
    }

    public function rutas_a_preservar()
    {
        return [['public/.htaccess'], ['public/uploads/foto-cliente.webp'], ['storage/app/certificado.pem'], ['.env'], ['../fuera-del-paquete']];
    }

    private function manifiesto()
    {
        return json_decode(file_get_contents(database_path('seeders/data/demo_industrias_assets.json')), true);
    }

    private function paquete($con_assets = true)
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cc-demo-assets-');
        $this->temporales[] = $ruta;
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('artisan', '<?php');
        $zip->addFromString('vendor/autoload.php', '<?php');
        if ($con_assets) {
            foreach ($this->manifiesto()['archivos'] as $archivo => $datos) {
                $zip->addFile(base_path($archivo), $archivo);
            }
        }
        $zip->close();
        return $ruta;
    }
}
