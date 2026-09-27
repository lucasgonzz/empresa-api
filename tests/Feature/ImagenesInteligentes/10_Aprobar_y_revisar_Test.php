<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Models\Image;
use App\Models\ImageAssignmentItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Aprobar, rechazar y quitar después de la revisión independiente (plan §13): B1 (la candidata que
 * quedó en el otro frente), B4 (copiar → base → recién después borrar la candidata), S3 (nombres con
 * uuid) y S2 (nada de mensajes crudos hacia el usuario).
 *
 * Lo que protege, sobre todo: que una propuesta "a revisar" NUNCA se pierda por un problema del
 * disco. Antes, si la candidata no estaba en este frente, el item pasaba a error_interno y la
 * propuesta se perdía (y se volvía a pagar, porque error_interno no cuenta como "ya buscado").
 */
class Aprobar_y_revisar_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    /** El storage del OTRO frente del mismo cliente (cada frente tiene el suyo). */
    const OTRO_FRENTE = 'https://api-ferreteria2.comerciocity.com/storage/';

    /** El patrón de un nombre definitivo (plan §13, S3). */
    const PATRON_DEFINITIVO = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.webp$/';

    /**
     * Un artículo con una imagen "a revisar" cuya candidata NO está en este disco (quedó en el otro
     * frente), salvo que se la ponga después.
     *
     * @param  string $nombre
     * @param  string $url_base  Dónde dice el item que está la imagen.
     * @return \App\Models\ImageAssignmentItem
     */
    protected function a_revisar($nombre = 'Taladro inalámbrico 18 V', $url_base = self::OTRO_FRENTE)
    {
        $articulo = $this->nuevo_articulo($nombre, self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);
        $item     = ImageAssignmentItem::where('run_id', $run->id)->first();
        $archivo  = ImageAssignmentItem::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp';

        $item->update([
            'status'         => ImageAssignmentItem::STATUS_A_REVISAR,
            'motivo'         => 'confianza_media',
            'imagen_archivo' => $archivo,
            'imagen_url'     => $url_base.$archivo,
            'imagen_meta'    => ['ancho' => 800, 'alto' => 800, 'fondo_blanco' => true, 'fondo_blanco_ratio' => 0.97, 'avisos' => ['La IA lo reconoce con confianza media']],
        ]);

        return $item->fresh();
    }

    /**
     * Un Http::fake que sirve estos archivos (y 404 para todo lo demás).
     *
     * @param  array $urls  url => binario webp
     * @return void
     */
    protected function servir(array $urls)
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        Http::fake(function ($request) use ($urls) {
            if (isset($urls[$request->url()])) {
                return Http::response($urls[$request->url()], 200, ['Content-Type' => 'image/webp']);
            }

            return Http::response('no', 404, ['Content-Type' => 'text/plain']);
        });
    }

    /**
     * Lo que no puede cambiar en un item cuando aprobar da 422 (plan §13, B1: "sin tocar el item").
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @return array
     */
    protected function huella(ImageAssignmentItem $item)
    {
        $fresco = $item->fresh();

        return [
            $fresco->status,
            $fresco->motivo,
            $fresco->imagen_archivo,
            $fresco->imagen_url,
            $fresco->imagen_meta,
            $fresco->image_id,
            $fresco->revisado_at,
        ];
    }

    /**
     * B1: la candidata quedó en el storage del otro frente (un upgrade rotó el frente activo): se
     * la trae de su imagen_url y la aprobación sale igual.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_trae_la_candidata_del_otro_frente_si_no_esta_en_este_disco()
    {
        $item = $this->a_revisar();

        $this->assertFalse(Storage::disk('public')->exists($item->imagen_archivo), 'Precondición: no está en este frente.');

        $this->servir([$item->imagen_url => $this->webp(800, 800, 'verde')]);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'aprobada');

        $aprobado = $item->fresh();

        $this->assertMatchesRegularExpression(self::PATRON_DEFINITIVO, $aprobado->imagen_archivo);
        $this->assertTrue(Storage::disk('public')->exists($aprobado->imagen_archivo));
        $this->assertFalse(Storage::disk('public')->exists($item->imagen_archivo), 'La candidata traída se borró después del commit.');
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
        $this->assertSame(1, $this->requests_a($item->imagen_url), 'Se la pidió una sola vez, a su propia dirección.');

        // Y es la imagen que estaba en el otro frente.
        $medidas = getimagesize(Storage::disk('public')->path($aprobado->imagen_archivo));
        $this->assertSame([800, 800], [$medidas[0], $medidas[1]]);
    }

    /**
     * B1: nada que no sea de este sistema, ni otro archivo que la candidata exacta del item. Y si el
     * otro frente tampoco la tiene: 422 SIN tocar el item (sigue a revisar).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_no_trae_nada_de_un_host_ajeno_ni_otro_archivo_y_no_toca_el_item()
    {
        // Un host que no es de este sistema.
        $ajeno = $this->a_revisar('Amoladora 115 mm', 'https://imagenes.otra-tienda.com/storage/');
        $this->servir([$ajeno->imagen_url => $this->webp(800, 800)]);
        $antes = $this->huella($ajeno);

        $this->postJson('api/image-assignment-items/'.$ajeno->id.'/aprobar')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertSame($antes, $this->huella($ajeno));
        $this->assertSame(0, $this->requests_a('otra-tienda.com'), 'Ni siquiera se le pegó.');

        // La URL apunta a OTRA candidata (no la del item).
        $otra = $this->a_revisar('Sierra circular 185 mm');
        $otra->update(['imagen_url' => self::OTRO_FRENTE.ImageAssignmentItem::PREFIJO_CANDIDATA.(string) Str::uuid().'.webp']);
        $this->servir([$otra->fresh()->imagen_url => $this->webp(800, 800)]);
        $antes = $this->huella($otra);

        $this->postJson('api/image-assignment-items/'.$otra->id.'/aprobar')->assertStatus(422);

        $this->assertSame($antes, $this->huella($otra));
        $this->assertSame(0, $this->requests_a('api-ferreteria2.comerciocity.com'));

        // Un nombre que no es el de una candidata.
        $raro = $this->a_revisar('Lijadora orbital');
        $raro->update(['imagen_url' => self::OTRO_FRENTE.'../../.env']);
        $antes = $this->huella($raro);

        $this->postJson('api/image-assignment-items/'.$raro->id.'/aprobar')->assertStatus(422);

        $this->assertSame($antes, $this->huella($raro));

        // Del propio sistema, el nombre justo, pero el otro frente tampoco la tiene (404).
        $perdida = $this->a_revisar('Pistola de calor');
        $this->servir([]);
        $antes = $this->huella($perdida);

        $this->postJson('api/image-assignment-items/'.$perdida->id.'/aprobar')->assertStatus(422);

        $this->assertSame($antes, $this->huella($perdida), 'Sigue a revisar: la propuesta no se pierde.');

        foreach ([$ajeno, $otra, $raro, $perdida] as $item) {
            $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count(), 'Ninguna fila de images.');
        }
    }

    /**
     * B1: rechazar y quitar con el archivo ausente cambian el estado igual (el huérfano queda en el
     * log, no es un error del usuario).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function rechazar_y_quitar_con_el_archivo_ausente_no_fallan()
    {
        $item = $this->a_revisar();

        $this->postJson('api/image-assignment-items/'.$item->id.'/rechazar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'rechazada');

        // Una asignada cuyo archivo quedó en el otro frente.
        $articulo = $this->nuevo_articulo('Destornillador plano', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);
        $asignada = ImageAssignmentItem::where('run_id', $run->id)->first();
        $archivo  = (string) Str::uuid().'.webp';

        $imagen = Image::create([
            'hosting_url'    => self::OTRO_FRENTE.$archivo,
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);

        $asignada->update(['status' => ImageAssignmentItem::STATUS_ASIGNADA, 'image_id' => $imagen->id, 'imagen_archivo' => $archivo, 'imagen_url' => $imagen->hosting_url]);

        $this->postJson('api/image-assignment-items/'.$asignada->id.'/quitar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'quitada');

        $this->assertNull(Image::find($imagen->id));
    }

    /**
     * B4: si algo falla en la base en el medio de la aprobación, la copia se borra y la candidata
     * sigue donde estaba: el item queda a revisar y apuntando a un archivo que existe. Y el mensaje no
     * trae el error crudo (S2).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_la_aprobacion_falla_en_la_base_la_candidata_sigue_y_la_copia_se_borra()
    {
        $item = $this->a_revisar();
        Storage::disk('public')->put($item->imagen_archivo, $this->webp(700, 700));
        $antes = $this->huella($item);

        Image::creating(function () {
            throw new \RuntimeException('SQLSTATE[HY000]: la base no está (prueba).');
        });

        try {
            $respuesta = $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar');
        } finally {
            Image::flushEventListeners();
        }

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', (string) $respuesta->json('message'));

        $this->assertSame($antes, $this->huella($item));
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo), 'La candidata sigue.');
        $this->assertSame([$item->imagen_archivo], Storage::disk('public')->allFiles(), 'No quedó ninguna copia suelta.');
    }

    /**
     * S3: el nombre definitivo es un uuid; si justo existiera un archivo con ese nombre (Flysystem
     * tira FileExistsException), 422 sin tocar nada.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_el_nombre_definitivo_ya_existe_aprobar_da_422_sin_tocar_nada()
    {
        $item = $this->a_revisar();
        Storage::disk('public')->put($item->imagen_archivo, $this->webp(700, 700));
        $antes = $this->huella($item);

        $fijo = '11111111-2222-4333-8444-555555555555';
        Storage::disk('public')->put($fijo.'.webp', 'una imagen que ya estaba');

        Str::createUuidsUsing(function () use ($fijo) {
            return Uuid::fromString($fijo);
        });

        try {
            $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')->assertStatus(422);
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame($antes, $this->huella($item));
        $this->assertSame('una imagen que ya estaba', Storage::disk('public')->get($fijo.'.webp'), 'No se pisó.');
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo));
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
    }

    /**
     * S2: si Tienda Nube (o lo que sea que hace deleteImageModel) falla al quitar, el 422 no trae
     * el cuerpo del error: un texto legible, y el detalle al log.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function quitar_no_muestra_el_error_crudo()
    {
        $articulo = $this->nuevo_articulo('Martillo de goma', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);
        $asignada = ImageAssignmentItem::where('run_id', $run->id)->first();
        $archivo  = (string) Str::uuid().'.webp';

        Storage::disk('public')->put($archivo, $this->webp(700, 700));

        $imagen = Image::create([
            'hosting_url'    => 'http://empresa.local/storage/'.$archivo,
            'imageable_id'   => $articulo->id,
            'imageable_type' => 'article',
        ]);

        $asignada->update(['status' => ImageAssignmentItem::STATUS_ASIGNADA, 'image_id' => $imagen->id, 'imagen_archivo' => $archivo, 'imagen_url' => $imagen->hosting_url]);

        Image::deleting(function () {
            throw new \RuntimeException('{"code":422,"message":"Tienda Nube: token inválido abc123"}');
        });

        try {
            $respuesta = $this->postJson('api/image-assignment-items/'.$asignada->id.'/quitar');
        } finally {
            Image::flushEventListeners();
        }

        $respuesta->assertStatus(422);
        $this->assertSame('No se pudo quitar la imagen. Probá de nuevo en un momento.', $respuesta->json('message'));
        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $asignada->fresh()->status, 'No se marcó como quitada: la imagen sigue.');
        $this->assertNotNull(Image::find($imagen->id));
    }
}
