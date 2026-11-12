<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * El chat de Soporte acepta la lista de precios del negocio (misión prueba-e2e-campana-imperio, 11/11/2026).
 *
 * Hasta hoy el compositor solo dejaba elegir audios e imágenes. La API nunca restringió el tipo, pero
 * guardaba el archivo con un nombre al azar, con el mime que adivinara `finfo`, y sin el nombre original.
 *
 *  1. Un .xlsx adjunto se guarda con su extensión, su mime y su nombre original, y se reenvía al admin con
 *     el binario (`attachments_files[]`) y la metadata `attachments`, que suma `original_name`.
 *  2. Un .csv y un .pdf, igual; el mime sale de la extensión, no de `finfo`.
 *  3. Audios e imágenes siguen exactamente como antes (ruta y mime de siempre), y ahora también llevan nombre.
 *  4. Un documento de más de 10 MB, o un archivo que PHP no terminó de recibir, contesta 422 con un mensaje
 *     claro y no guarda nada (antes el mensaje se guardaba sin el adjunto, en silencio).
 *  5. Un archivo que manda Soporte desde el admin también queda con su nombre.
 */
class AdjuntoDeDocumentoEnSoporteTest extends EmpresaTestCase
{
    const ADMIN = 'https://admin.test';
    const CLAVE = 'clave-del-test-de-adjuntos-9d27';
    const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        config([
            'services.admin_api.url'             => self::ADMIN,
            'services.admin_api.client_uuid'     => (string) Str::uuid(),
            'services.admin_api.inbound_key'     => self::CLAVE,
            'services.admin_api.api_key'         => self::CLAVE,
            'services.admin_api.require_api_key' => false,
            'broadcasting.default'               => 'null',
        ]);

        Storage::fake('public');
        SupportMessageAttachment::olvidar_esquema();
        Http::fake([self::ADMIN . '/api/inbound/support/messages' => Http::response(['ok' => true], 201)]);
    }

    protected function tearDown(): void
    {
        SupportMessageAttachment::olvidar_esquema();
        parent::tearDown();
    }

    /**
     * Manda un mensaje con adjunto por el endpoint real del chat, como lo hace el SPA (multipart).
     *
     * @param \Illuminate\Http\UploadedFile $archivo
     * @param array $campos
     * @return \Illuminate\Testing\TestResponse
     */
    private function mandar($archivo, array $campos = [])
    {
        return $this->post('/api/support-message', array_merge([
            'body'       => 'Te paso mi lista de precios',
            'kind'       => 'text',
            'attachment' => $archivo,
        ], $campos), ['Accept' => 'application/json']);
    }

    /**
     * Las partes del multipart que salió hacia el admin, por nombre de campo.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function partes_enviadas_al_admin()
    {
        $partes = [];

        Http::assertSent(function ($request) use (&$partes) {
            if ($request->url() !== self::ADMIN . '/api/inbound/support/messages') {
                return false;
            }
            $this->assertTrue($request->isMultipart(), 'Con adjunto, el envío al admin es multipart.');
            foreach ($request->data() as $parte) {
                $partes[$parte['name']][] = $parte;
            }

            return true;
        });

        return $partes;
    }

    public function test_un_xlsx_se_guarda_con_su_nombre_y_su_mime_y_se_reenvia_al_admin()
    {
        // `finfo` no reconoce este contenido como planilla (a un .xlsx real lo suele ver como zip): el mime que
        // se guarda tiene que salir de la extensión. Con contenido de verdad, porque un adjunto vacío no viaja.
        $contenido = str_repeat('precio;costo;', 500);
        $archivo = UploadedFile::fake()->createWithContent('Lista de precios Marzo.xlsx', $contenido);

        $respuesta = $this->mandar($archivo);
        $this->assertSame(201, $respuesta->getStatusCode(), $respuesta->getContent());

        $adjunto = SupportMessageAttachment::where('support_message_id', $respuesta->json('model.id'))->first();
        $this->assertNotNull($adjunto);
        $this->assertSame('Lista de precios Marzo.xlsx', $adjunto->original_name);
        $this->assertSame(self::XLSX, $adjunto->mime);
        $this->assertSame(strlen($contenido), (int) $adjunto->size);
        $this->assertMatchesRegularExpression('#^support_messages/\d+/[A-Za-z0-9]{40}\.xlsx$#', $adjunto->path);
        Storage::disk('public')->assertExists($adjunto->path);

        // Lo que recibe el SPA para dibujar la burbuja.
        $respuesta->assertJsonPath('model.attachments.0.original_name', 'Lista de precios Marzo.xlsx')
            ->assertJsonPath('model.attachments.0.mime', self::XLSX)
            ->assertJsonPath('model.kind', 'text');

        // Lo que recibe el admin.
        $partes = $this->partes_enviadas_al_admin();

        $this->assertCount(1, $partes['attachments_files[]']);
        $binario = $partes['attachments_files[]'][0];
        $this->assertSame(basename($adjunto->path), $binario['filename']);
        $this->assertSame($contenido, $binario['contents']);
        $this->assertSame(self::XLSX, $binario['headers']['Content-Type']);

        $meta = json_decode($partes['attachments'][0]['contents'], true);
        $this->assertSame([[
            'disk'          => 'public',
            'path'          => $adjunto->path,
            'mime'          => self::XLSX,
            'size'          => strlen($contenido),
            'original_name' => 'Lista de precios Marzo.xlsx',
        ]], $meta);

        $this->assertSame('text', $partes['kind'][0]['contents']);
        $this->assertSame('Te paso mi lista de precios', $partes['body'][0]['contents']);
        $this->assertNotNull(SupportMessage::find($respuesta->json('model.id'))->synced_to_admin_at);
    }

    public function test_un_csv_y_un_pdf_tambien_y_el_nombre_llega_sin_carpetas()
    {
        $casos = [
            ['precios.CSV', 'text/plain', 'csv', 'text/csv', 'precios.CSV'],
            ['C:\\Users\\dueno\\Catalogo 2026.pdf', 'application/pdf', 'pdf', 'application/pdf', 'Catalogo 2026.pdf'],
            ['viejo.xls', 'application/octet-stream', 'xls', 'application/vnd.ms-excel', 'viejo.xls'],
        ];

        foreach ($casos as $caso) {
            $respuesta = $this->mandar(UploadedFile::fake()->create($caso[0], 30, $caso[1]));
            $this->assertSame(201, $respuesta->getStatusCode(), $caso[0] . ': ' . $respuesta->getContent());

            $adjunto = SupportMessageAttachment::where('support_message_id', $respuesta->json('model.id'))->first();
            $this->assertStringEndsWith('.' . $caso[2], $adjunto->path, $caso[0]);
            $this->assertSame($caso[3], $adjunto->mime, $caso[0]);
            $this->assertSame($caso[4], $adjunto->original_name, $caso[0]);
        }
    }

    public function test_una_imagen_y_un_audio_siguen_como_antes()
    {
        $imagen = $this->mandar(UploadedFile::fake()->image('captura.png', 40, 40), ['kind' => 'image']);
        $this->assertSame(201, $imagen->getStatusCode(), $imagen->getContent());
        $adjunto = SupportMessageAttachment::where('support_message_id', $imagen->json('model.id'))->first();
        $this->assertSame('image/png', $adjunto->mime);
        $this->assertStringEndsWith('.png', $adjunto->path);
        $this->assertSame('captura.png', $adjunto->original_name);

        $audio = $this->mandar(UploadedFile::fake()->create('audio_1.webm', 50, 'audio/webm'), ['kind' => 'audio']);
        $this->assertSame(201, $audio->getStatusCode(), $audio->getContent());
        $adjunto = SupportMessageAttachment::where('support_message_id', $audio->json('model.id'))->first();
        $this->assertSame('audio/webm', $adjunto->mime);

        // Una imagen renombrada a .pdf no pasa por documento: conserva su mime real. Con un PNG de verdad,
        // porque el archivo de mentira de Laravel saca el mime del nombre y acá importa el del contenido.
        $png = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(4, 4), $png);
        $disfrazada = $this->mandar(new UploadedFile($png, 'foto.pdf', null, null, true));
        $this->assertSame(201, $disfrazada->getStatusCode(), $disfrazada->getContent());
        $adjunto = SupportMessageAttachment::where('support_message_id', $disfrazada->json('model.id'))->first();
        $this->assertSame('image/png', $adjunto->mime);
        $this->assertStringEndsNotWith('.pdf', $adjunto->path);
        @unlink($png);
    }

    public function test_un_documento_de_mas_de_10_mb_contesta_422_y_no_guarda_nada()
    {
        $mensajes_antes = SupportMessage::count();

        $this->mandar(UploadedFile::fake()->create('lista enorme.pdf', 10241, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'adjunto_demasiado_grande')
            ->assertJsonPath('message', 'El archivo pesa más de 10 MB. Achicalo o mandalo en partes.');

        $this->assertSame($mensajes_antes, SupportMessage::count());
        Http::assertNothingSent();

        // Justo en el tope, pasa.
        $this->mandar(UploadedFile::fake()->create('lista justa.pdf', 10240, 'application/pdf'))->assertStatus(201);
    }

    public function test_un_archivo_que_php_no_termino_de_recibir_contesta_422_y_no_guarda_el_mensaje_sin_adjunto()
    {
        $mensajes_antes = SupportMessage::count();
        $temporal = tempnam(sys_get_temp_dir(), 'adj');
        // UPLOAD_ERR_INI_SIZE: lo que arma PHP cuando el archivo pasó `upload_max_filesize`.
        $cortado = new UploadedFile($temporal, 'lista.xlsx', self::XLSX, UPLOAD_ERR_INI_SIZE, true);

        $this->mandar($cortado)
            ->assertStatus(422)
            ->assertJsonPath('code', 'adjunto_no_recibido');

        $this->assertSame($mensajes_antes, SupportMessage::count(), 'Antes se guardaba el texto solo, sin avisar que el archivo se perdió.');
        @unlink($temporal);
    }

    public function test_sin_la_columna_el_adjunto_se_guarda_igual_sin_nombre()
    {
        // La ventana de un deploy: el código ya está y la migración todavía no corrió.
        $esquema = new \ReflectionProperty(SupportMessageAttachment::class, 'guarda_nombre_original');
        $esquema->setAccessible(true);
        $esquema->setValue(null, false);

        $datos = SupportMessageAttachment::con_nombre_original(['path' => 'x'], 'lista.xlsx');

        $this->assertSame(['path' => 'x'], $datos);
    }

    public function test_un_adjunto_rechazado_no_traslada_al_sistema_una_conversacion_de_whatsapp()
    {
        // Sesión web propia con chat de Soporte: la que puede seguir en el sistema un ticket de WhatsApp.
        $extension = \App\Models\ExtencionEmpresa::where('slug', 'support_chat')->first()
            ?: \App\Models\ExtencionEmpresa::forceCreate(['slug' => 'support_chat', 'name' => 'support_chat']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
        // Cualquier salida al admin (leer la conversación, mandar el mensaje) quedaría registrada.
        Http::fake(['*' => Http::response([], 500)]);

        $ticket = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open', 'origen' => SupportTicket::ORIGEN_WHATSAPP]);
        $mensajes_antes = SupportMessage::count();

        $this->mandar(UploadedFile::fake()->create('lista enorme.pdf', 10241, 'application/pdf'), [
            'support_ticket_id'   => $ticket->id,
            'agentes_version'     => 1,
            'traspasar_a_sistema' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 'adjunto_demasiado_grande');

        // El rechazo corta ANTES del traspaso: no se consultó ni se avisó nada al admin, y el ticket sigue en WhatsApp.
        Http::assertNothingSent();
        $this->assertNull($ticket->fresh()->sistema_exclusivo_at);
        $this->assertSame($mensajes_antes, SupportMessage::count());
    }

    public function test_un_documento_que_manda_soporte_desde_el_admin_queda_con_su_nombre()
    {
        $ticket = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open']);
        $mensaje_uuid = (string) Str::uuid();

        $this->post('/api/admin-sync/support/messages', [
            'ticket_uuid'       => $ticket->uuid,
            'message_uuid'      => $mensaje_uuid,
            'kind'              => 'text',
            'body'              => 'Te devuelvo la planilla corregida',
            'attachments_files' => [UploadedFile::fake()->create('Planilla corregida.xlsx', 40, 'application/zip')],
        ], ['Accept' => 'application/json', 'X-Admin-Api-Key' => self::CLAVE])->assertStatus(201);

        $adjunto = SupportMessageAttachment::where('support_message_id', SupportMessage::where('uuid', $mensaje_uuid)->value('id'))->first();
        $this->assertNotNull($adjunto);
        $this->assertSame('Planilla corregida.xlsx', $adjunto->original_name);
        $this->assertSame(self::XLSX, $adjunto->mime);
        $this->assertStringEndsWith('.xlsx', $adjunto->path);
    }
}
