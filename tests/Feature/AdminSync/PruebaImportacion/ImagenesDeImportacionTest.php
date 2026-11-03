<?php

namespace Tests\Feature\AdminSync\PruebaImportacion;

use App\Models\Article;
use App\Models\Image;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\EmpresaTestCase;

/**
 * `POST api/admin-sync/ai-excel-import/imagenes` (misión cruzada prueba-martin-importacion, 3/11/2026): las
 * fotos que la tarea del PDF saca del catálogo del proveedor se asignan a los artículos del dueño por su
 * código, que es el nombre del archivo.
 *
 * Lo que protege:
 *  1. Se asigna por `bar_code` y por `provider_code`, dentro del dueño; un código sin artículo vuelve en
 *     `sin_articulo` y un formato que no es imagen en `rechazadas`, sin cortar el lote.
 *  2. Un artículo que ya tiene foto NO se pisa sin `reemplazar=1`; con `reemplazar=1` queda una sola.
 *
 * @group admin-sync
 */
class ImagenesDeImportacionTest extends EmpresaTestCase
{
    const CLAVE = 'clave-de-admin-del-test-de-imagenes-en-prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.admin_api.api_key'         => self::CLAVE,
            'services.admin_api.require_api_key' => true,
        ]);

        Storage::fake('public');
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
    }

    /**
     * @param  array<string, mixed>  $campos
     * @return Article
     */
    protected function articulo_del_dueno(array $campos): Article
    {
        return Article::create(array_merge([
            'name'    => 'Artículo de prueba ' . uniqid(),
            'user_id' => auth()->user()->id,
            'status'  => 'active',
        ], $campos));
    }

    /** @test */
    public function asigna_por_codigo_de_barras_y_de_proveedor_y_cuenta_lo_que_no_encuentra()
    {
        $dueno       = auth()->user();
        $por_barras  = $this->articulo_del_dueno(['bar_code' => 'ABC-1']);
        $por_prov    = $this->articulo_del_dueno(['provider_code' => 'PRV-9']);

        $respuesta = $this->post('api/admin-sync/ai-excel-import/imagenes', [
            'user_id'  => $dueno->id,
            'imagenes' => [
                UploadedFile::fake()->image('ABC-1.jpg', 40, 40),
                UploadedFile::fake()->image('PRV-9.png', 40, 40),
                UploadedFile::fake()->image('NOEXISTE.jpg', 40, 40),
                UploadedFile::fake()->create('malo.gif', 5, 'image/gif'),
            ],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame(2, $respuesta->json('asignadas'));
        $this->assertSame(['NOEXISTE'], $respuesta->json('sin_articulo'));
        $this->assertSame([], $respuesta->json('ya_tenian'));
        $this->assertSame([['codigo' => 'malo', 'motivo' => 'formato']], $respuesta->json('rechazadas'));

        foreach ([$por_barras, $por_prov] as $article) {
            $imagenes = Image::where('imageable_type', 'article')->where('imageable_id', $article->id)->get();
            $this->assertCount(1, $imagenes, 'Cada artículo encontrado queda con una imagen.');
            $this->assertStringContainsString('/storage/', (string) $imagenes->first()->hosting_url);

            $archivo = basename((string) $imagenes->first()->hosting_url);
            Storage::disk('public')->assertExists($archivo);
        }

        $this->assertTrue((bool) $por_barras->fresh()->needs_sync_with_tn);
    }

    /** @test */
    public function no_pisa_una_foto_existente_salvo_con_reemplazar()
    {
        $dueno   = auth()->user();
        $article = $this->articulo_del_dueno(['bar_code' => 'ABC-2']);

        Image::create([
            'hosting_url'    => 'https://negocio.test/storage/anterior.webp',
            'imageable_id'   => $article->id,
            'imageable_type' => 'article',
        ]);

        $sin = $this->post('api/admin-sync/ai-excel-import/imagenes', [
            'user_id'  => $dueno->id,
            'imagenes' => [UploadedFile::fake()->image('ABC-2.jpg', 40, 40)],
        ], $this->headers());

        $sin->assertStatus(200);
        $this->assertSame(0, $sin->json('asignadas'));
        $this->assertSame(['ABC-2'], $sin->json('ya_tenian'));
        $this->assertSame('https://negocio.test/storage/anterior.webp', Image::where('imageable_id', $article->id)->where('imageable_type', 'article')->first()->hosting_url);

        $con = $this->post('api/admin-sync/ai-excel-import/imagenes', [
            'user_id'    => $dueno->id,
            'reemplazar' => '1',
            'imagenes'   => [UploadedFile::fake()->image('ABC-2.jpg', 40, 40)],
        ], $this->headers());

        $con->assertStatus(200);
        $this->assertSame(1, $con->json('asignadas'));

        $imagenes = Image::where('imageable_id', $article->id)->where('imageable_type', 'article')->get();
        $this->assertCount(1, $imagenes, 'Al reemplazar queda una sola imagen.');
        $this->assertStringNotContainsString('anterior.webp', (string) $imagenes->first()->hosting_url);
    }

    /** @test */
    public function no_asigna_a_un_articulo_de_otro_dueno()
    {
        $dueno = auth()->user();

        $otro = \App\Models\User::create([
            'name'     => 'Otro Dueño',
            'email'    => 'otro-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);

        $ajeno = Article::create(['name' => 'Ajeno', 'user_id' => $otro->id, 'bar_code' => 'AJENO-1', 'status' => 'active']);

        $respuesta = $this->post('api/admin-sync/ai-excel-import/imagenes', [
            'user_id'  => $dueno->id,
            'imagenes' => [UploadedFile::fake()->image('AJENO-1.jpg', 40, 40)],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertSame(['AJENO-1'], $respuesta->json('sin_articulo'));
        $this->assertSame(0, Image::where('imageable_id', $ajeno->id)->where('imageable_type', 'article')->count());
    }

    /** @test */
    public function sin_imagenes_contesta_422()
    {
        $this->postJson('api/admin-sync/ai-excel-import/imagenes', ['user_id' => auth()->user()->id], $this->headers())
            ->assertStatus(422);
    }
}
