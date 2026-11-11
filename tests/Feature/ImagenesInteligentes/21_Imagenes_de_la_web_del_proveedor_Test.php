<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\AiAvisoDelAsistente;
use App\Models\Article;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Las fotos que la tarea de soporte trae de la web de un proveedor (misión importaciones-de-martin, 11/11/2026):
 * POST admin-sync/catalogo/imagenes/proveedor y GET admin-sync/catalogo/articulos?con_imagenes=1.
 */
class Imagenes_de_la_web_del_proveedor_Test extends ImagenesInteligentesTestCase
{
    const CLAVE = 'admin-imagenes-proveedor-web-test';
    const RUTA = '/api/admin-sync/catalogo/imagenes/proveedor';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.USER_ID' => $this->owner->id, 'services.admin_api.api_key' => self::CLAVE]);
    }

    public function test_la_exacta_se_asigna_sola_normalizada_y_la_que_no_se_puede_comprobar_baja_a_revisar(): void
    {
        $ean = $this->con_verificador('779123456701');
        $por_barras = $this->nuevo_articulo('Taladro con barras', $ean);
        $por_codigo = $this->nuevo_articulo('Amoladora con código', null, ['provider_code' => 'AB-12']);
        $parecido = $this->nuevo_articulo('Sierra con código parecido', null, ['provider_code' => 'SR-77']);
        $con_foto = $this->nuevo_articulo('Martillo con foto', $this->con_verificador('779123456702'));
        Image::create(['hosting_url' => 'https://ejemplo.test/martillo.webp', 'imageable_id' => $con_foto->id, 'imageable_type' => 'article']);

        // Una sugerencia vieja del motor para el taladro: la exacta la reemplaza.
        $vieja = $this->a_revisar_en_otra_asignacion($por_barras);

        $respuesta = $this->subir('https://www.Proveedor-Uno.com.ar/catalogo', [
            ['article_id' => $por_barras->id, 'tipo' => 'exacto', 'codigo_web' => $ean, 'url_origen' => 'https://www.proveedor-uno.com.ar/p/taladro', 'imagen' => $this->png(1500, 1200)],
            ['article_id' => $por_codigo->id, 'tipo' => 'exacto', 'codigo_web' => 'AB-12', 'imagen' => $this->png(800, 800, 'azul')],
            ['article_id' => $parecido->id, 'tipo' => 'exacto', 'codigo_web' => 'sr77', 'imagen' => $this->png(800, 800, 'verde')],
            ['article_id' => $con_foto->id, 'tipo' => 'exacto', 'codigo_web' => $con_foto->bar_code, 'imagen' => $this->png(800, 800)],
        ])->assertOk()->assertJsonPath('asignadas', 2)->assertJsonPath('a_revisar', 1)
            ->assertJsonPath('salteadas', [['article_id' => $con_foto->id, 'motivo' => 'ya_tiene_imagen']])
            ->assertJsonPath('exactas_a_revisar', [$parecido->id]);

        $run = ImageAssignmentRun::findOrFail($respuesta->json('run_id'));
        $this->assertSame(ImageAssignmentRun::ORIGEN_PROVEEDOR_WEB, $run->origen);
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertSame('www.proveedor-uno.com.ar', $run->fuente);
        $this->assertSame(90, (int) $run->support_ticket_id);
        $this->assertSame(3, (int) $run->total_articulos);

        $item = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $por_barras->id)->firstOrFail();
        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('codigo_de_barras', $item->criterio_usado);
        $this->assertSame('https://www.proveedor-uno.com.ar/p/taladro', $item->imagen_meta['url_origen']);
        $this->assertSame('www.proveedor-uno.com.ar', $item->imagen_meta['dominio']);
        $imagen = Image::findOrFail($item->image_id);
        $this->assertSame($por_barras->id, (int) $imagen->imageable_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}\.webp$/', $item->imagen_archivo);
        $medidas = getimagesizefromstring(Storage::disk('public')->get($item->imagen_archivo));
        $this->assertSame([1000, 1000], [$medidas[0], $medidas[1]]);
        $this->assertSame('image/webp', $medidas['mime']);
        $this->assertTrue((bool) $por_barras->fresh()->needs_sync_with_tn);
        $this->assertSame(ImageAssignmentItem::STATUS_RECHAZADA, $vieja->fresh()->status);
        $this->assertStringContainsString('web del proveedor', $vieja->fresh()->motivo_detalle);

        $this->assertSame('codigo_de_proveedor', ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $por_codigo->id)->value('criterio_usado'));
        $revisar = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $parecido->id)->firstOrFail();
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $revisar->status);
        $this->assertNull($revisar->image_id);
        $this->assertFalse($parecido->images()->exists());
        $this->assertFalse(ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $con_foto->id)->exists());
        $this->assertSame(1, $con_foto->images()->count());
    }

    public function test_la_dudosa_queda_a_revisar_se_saltea_si_ya_hay_otra_y_se_puede_aprobar(): void
    {
        $articulo = $this->nuevo_articulo('Llave francesa 10"');
        $otro = $this->nuevo_articulo('Destornillador');
        $this->a_revisar_en_otra_asignacion($otro);

        $respuesta = $this->subir('proveedor-dos.com', [
            ['article_id' => $articulo->id, 'tipo' => 'dudosa', 'codigo_web' => 'LL-10', 'url_origen' => 'https://proveedor-dos.com/llave', 'imagen' => $this->png(900, 600)],
            ['article_id' => $otro->id, 'tipo' => 'dudosa', 'imagen' => $this->png(900, 600)],
        ])->assertOk()->assertJsonPath('asignadas', 0)->assertJsonPath('a_revisar', 1)
            ->assertJsonPath('salteadas', [['article_id' => $otro->id, 'motivo' => 'ya_tiene_a_revisar']]);

        $item = ImageAssignmentItem::where('run_id', $respuesta->json('run_id'))->where('article_id', $articulo->id)->firstOrFail();
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('confianza_media', $item->motivo);
        $this->assertMatchesRegularExpression('/^imgcand_[a-f0-9-]{36}\.webp$/', $item->imagen_archivo);
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo));
        $this->assertSame('https://proveedor-dos.com/llave', $item->imagen_meta['pagina']);
        $this->assertFalse($articulo->images()->exists());

        $this->assertSame(200, ImageAssignmentRunHelper::aprobar($this->owner->id, $item->id, $this->owner->id)['status']);
        $this->assertSame(1, $articulo->images()->count());
        $payload = ImageAssignmentRunHelper::payload_de_item($item->fresh());
        $this->assertSame('proveedor-dos.com', $payload['imagen']['dominio']);
    }

    public function test_idempotente_por_ticket_fuente_y_articulo_y_solo_del_dueno(): void
    {
        $articulo = $this->nuevo_articulo('Pinza', $this->con_verificador('779123456703'));
        $inactivo = $this->nuevo_articulo('Pinza vieja', null, ['status' => 'inactive']);
        $ajeno_dueno = User::create(['name' => 'Otro', 'company_name' => 'Otro', 'password' => Hash::make('x'), 'email' => 'otro-web-' . uniqid() . '@test.local']);
        $ajeno = Article::create(['user_id' => $ajeno_dueno->id, 'name' => 'Pinza ajena', 'status' => 'active', 'bar_code' => $articulo->bar_code]);
        $items = [
            ['article_id' => $articulo->id, 'tipo' => 'exacto', 'codigo_web' => $articulo->bar_code, 'imagen' => $this->png(800, 800)],
            ['article_id' => $ajeno->id, 'tipo' => 'exacto', 'codigo_web' => $articulo->bar_code, 'imagen' => $this->png(800, 800)],
            ['article_id' => $inactivo->id, 'tipo' => 'dudosa', 'imagen' => $this->png(800, 800)],
        ];

        $primera = $this->subir('proveedor-tres.com', $items)->assertOk()->assertJsonPath('asignadas', 1)
            ->assertJsonPath('salteadas', [
                ['article_id' => $ajeno->id, 'motivo' => 'articulo_no_encontrado'],
                ['article_id' => $inactivo->id, 'motivo' => 'articulo_inactivo'],
            ]);
        $this->assertFalse($ajeno->images()->exists());
        $segunda = $this->subir('https://proveedor-tres.com/', array_slice($items, 0, 1))->assertOk()
            ->assertJsonPath('asignadas', 0)->assertJsonPath('salteadas', [['article_id' => $articulo->id, 'motivo' => 'ya_procesado']]);
        $this->assertSame($primera->json('run_id'), $segunda->json('run_id'));
        $this->assertSame(1, $articulo->images()->count());
        $this->assertSame(1, ImageAssignmentItem::where('run_id', $primera->json('run_id'))->count());

        // Otro ticket con la misma fuente es otra asignación.
        $this->subir('proveedor-tres.com', array_slice($items, 0, 1), 91)->assertOk()
            ->assertJsonPath('salteadas', [['article_id' => $articulo->id, 'motivo' => 'ya_tiene_imagen']]);
        $this->assertSame(2, ImageAssignmentRun::where('user_id', $this->owner->id)->where('origen', ImageAssignmentRun::ORIGEN_PROVEEDOR_WEB)->count());
    }

    public function test_tope_motivos_de_imagen_clave_y_dueno(): void
    {
        $articulo = $this->nuevo_articulo('Cinta métrica');
        $repetido = $this->con_verificador('779123456704');
        $uno = $this->nuevo_articulo('Cinta uno', $repetido);
        $dos = $this->nuevo_articulo('Cinta dos', $repetido);

        $muchos = [];
        for ($i = 0; $i < 26; $i++) {
            $muchos[] = ['article_id' => $articulo->id, 'tipo' => 'dudosa', 'imagen' => $this->png(300, 300)];
        }
        $this->subir('proveedor.com', $muchos)->assertStatus(422)->assertJsonPath('motivo', 'demasiados_items');

        $this->subir('proveedor.com', [
            ['article_id' => $articulo->id, 'tipo' => 'dudosa', 'imagen' => $this->png(120, 120)],
            ['article_id' => $articulo->id, 'tipo' => 'dudosa', 'imagen' => UploadedFile::fake()->createWithContent('no.png', 'no soy una imagen')],
            ['article_id' => $uno->id, 'tipo' => 'exacto', 'codigo_web' => $repetido, 'imagen' => $this->png(500, 500)],
        ])->assertOk()->assertJsonPath('salteadas', [
            ['article_id' => $articulo->id, 'motivo' => 'imagen_chica'],
            ['article_id' => $articulo->id, 'motivo' => 'imagen_invalida'],
        ])->assertJsonPath('exactas_a_revisar', [$uno->id]);
        $this->assertFalse($uno->images()->exists());
        $this->assertFalse($dos->images()->exists());

        $this->subir('no es un dominio', [['article_id' => $articulo->id, 'tipo' => 'dudosa', 'imagen' => $this->png(500, 500)]])
            ->assertStatus(422)->assertJsonPath('motivo', 'fuente_invalida');
        $this->subir('proveedor.com', [['article_id' => $articulo->id, 'tipo' => 'otro', 'imagen' => $this->png(500, 500)]])->assertStatus(422);
        $this->post(self::RUTA, ['user_id' => $this->owner->id, 'support_ticket_id' => 90, 'fuente' => 'proveedor.com',
            'items' => [['article_id' => $articulo->id, 'tipo' => 'dudosa']]], ['Accept' => 'application/json'])->assertStatus(401);
        $this->subir('proveedor.com', [['article_id' => $articulo->id, 'tipo' => 'dudosa', 'imagen' => $this->png(500, 500)]], 90, $this->owner->id + 999)
            ->assertStatus(409)->assertJsonPath('motivo', 'dueno_distinto');
    }

    public function test_quitar_una_asignada_deshace_y_el_aviso_sale_una_vez_en_la_ultima_tanda(): void
    {
        $articulo = $this->nuevo_articulo('Nivel de burbuja', $this->con_verificador('779123456705'));
        $dudoso = $this->nuevo_articulo('Nivel láser');
        $respuesta = $this->subir('proveedor-cuatro.com', [
            ['article_id' => $articulo->id, 'tipo' => 'exacto', 'codigo_web' => $articulo->bar_code, 'imagen' => $this->png(800, 800)],
            ['article_id' => $dudoso->id, 'tipo' => 'dudosa', 'imagen' => $this->png(800, 800)],
        ], 92, null, true)->assertOk()->assertJsonPath('aviso_dejado', true)
            ->assertJsonPath('totales.asignadas', 1)->assertJsonPath('totales.a_revisar', 1);

        $aviso = AiAvisoDelAsistente::where('owner_id', $this->owner->id)->where('support_ticket_id', 92)->firstOrFail();
        $this->assertTrue((bool) $aviso->modo_soporte);
        $this->assertSame(AiAvisoDelAsistente::TIPO_IMPORTACION_LISTA, $aviso->tipo);
        $this->assertStringContainsString('Te cargué 1 foto', $aviso->texto);
        $this->assertStringContainsString('Alertas → Catálogo → Imágenes', $aviso->texto);
        $this->subir('proveedor-cuatro.com', [
            ['article_id' => $articulo->id, 'tipo' => 'exacto', 'codigo_web' => $articulo->bar_code, 'imagen' => $this->png(800, 800)],
        ], 92, null, true)->assertOk();
        $this->assertSame(1, AiAvisoDelAsistente::where('owner_id', $this->owner->id)->where('support_ticket_id', 92)->count());

        $item = ImageAssignmentItem::where('run_id', $respuesta->json('run_id'))->where('article_id', $articulo->id)->firstOrFail();
        $quitada = ImageAssignmentRunHelper::quitar($this->owner->id, $item->id, $this->owner->id);
        $this->assertSame(200, $quitada['status']);
        $this->assertSame(ImageAssignmentItem::STATUS_QUITADA, $item->fresh()->status);
        $this->assertFalse($articulo->images()->exists());
    }

    public function test_catalogo_articulos_con_imagenes_suma_foto_codigos_del_pivot_y_a_revisar(): void
    {
        $con_foto = $this->nuevo_articulo('Con foto');
        Image::create(['hosting_url' => 'https://ejemplo.test/a.webp', 'imageable_id' => $con_foto->id, 'imageable_type' => 'article']);
        $con_pivot = $this->nuevo_articulo('Con pivot', null, ['provider_code' => 'PRO-1']);
        $proveedor = Provider::create(['user_id' => $this->owner->id, 'name' => 'Proveedor pivot', 'num' => 1]);
        $con_pivot->providers()->attach($proveedor->id, ['provider_code' => 'PIV-9']);
        $revisando = $this->nuevo_articulo('Revisando');
        $this->a_revisar_en_otra_asignacion($revisando);

        $url = '/api/admin-sync/catalogo/articulos?limite=1000';
        $cabeceras = ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json'];
        $sin = collect($this->getJson($url, $cabeceras)->assertOk()->json('articulos'))->keyBy('id');
        $this->assertArrayNotHasKey('tiene_imagen', $sin[$con_foto->id]);

        $con = collect($this->getJson($url . '&con_imagenes=1', $cabeceras)->assertOk()->json('articulos'))->keyBy('id');
        $this->assertTrue($con[$con_foto->id]['tiene_imagen']);
        $this->assertSame([], $con[$con_foto->id]['codigos_pivot']);
        $this->assertFalse($con[$con_foto->id]['a_revisar']);
        $this->assertFalse($con[$con_pivot->id]['tiene_imagen']);
        $this->assertSame(['PIV-9'], $con[$con_pivot->id]['codigos_pivot']);
        $this->assertSame('PRO-1', $con[$con_pivot->id]['codigo_de_proveedor']);
        $this->assertTrue($con[$revisando->id]['a_revisar']);
        $this->getJson($url . '&con_imagenes=1', ['Accept' => 'application/json'])->assertStatus(401);
    }

    /* -------------------------------------------------------------------------------------------------- */

    private function subir(string $fuente, array $items, int $ticket = 90, $user_id = null, bool $ultima = false)
    {
        foreach ($items as &$item) {
            if (isset($item['imagen']) && is_string($item['imagen'])) {
                $item['imagen'] = UploadedFile::fake()->createWithContent(Str::random(6) . '.png', $item['imagen']);
            }
        }
        unset($item);

        return $this->post(self::RUTA, [
            'user_id' => $user_id ?: $this->owner->id,
            'support_ticket_id' => $ticket,
            'fuente' => $fuente,
            'ultima_tanda' => $ultima ? 1 : 0,
            'items' => $items,
        ], ['X-Admin-Api-Key' => self::CLAVE, 'Accept' => 'application/json']);
    }

    private function a_revisar_en_otra_asignacion(Article $articulo): ImageAssignmentItem
    {
        $run = $this->asignacion([$articulo]);
        $item = ImageAssignmentItem::where('run_id', $run->id)->firstOrFail();
        $archivo = ImageAssignmentItem::PREFIJO_CANDIDATA . (string) Str::uuid() . '.webp';
        Storage::disk('public')->put($archivo, $this->png(600, 600));
        $item->update([
            'status' => ImageAssignmentItem::STATUS_A_REVISAR,
            'motivo' => 'confianza_media',
            'imagen_archivo' => $archivo,
            'imagen_url' => 'https://ejemplo.test/storage/' . $archivo,
            'imagen_meta' => ['ancho' => 600, 'alto' => 600, 'fondo_blanco' => true, 'avisos' => []],
        ]);

        return $item->fresh();
    }
}
