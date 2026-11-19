<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Http\Controllers\Helpers\asistente_ia\ArchivosDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\LinksDeArchivosIaHelper;
use App\Models\AiArchivo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119 (rama A, A2) — links de Excel que dan 404.
 *
 * 🔴 EL CASO REAL (Ferre Total, 18/11/2026, conv. 72, msgs 697-709): dos links `/asistente/archivo/<uuid>` que
 * el asistente le mandó a Pablo daban 404 («No me abre», «No lo puedo abrir»); ninguna herramienta los había
 * devuelto. Y en 709 dijo «te lo vuelvo a armar» con el MISMO link roto.
 *
 * Lo que se cuida:
 *  - el link de un archivo real, vigente y de este negocio queda tal cual (también repetido);
 *  - un link inventado (uuid que no existe), con el token cambiado, de otro negocio, vencido o cuyo archivo no
 *    está en el disco sale del texto y en su lugar va el aviso;
 *  - por el turno real del servicio (proveedor simulado): la respuesta final que trae un link inventado no
 *    lo deja salir;
 *  - el Excel no devuelve link si el archivo no quedó escrito.
 *
 * 🔴 Ningún test sale a la red: Http::fake, claves e ids de modelo inventados.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 *
 * @group chat-ia
 */
class T181_Links_de_archivos_que_no_abren_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArchivosDelAgenteIaHelper::DISCO);

        config([
            'services.anthropic.api_key'       => null,
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-t181',
            'services.deepseek.model'          => 'deepseek-general-t181',
            'services.deepseek.model_agil'     => 'deepseek-flash-t181',
            'services.deepseek.model_profundo' => 'deepseek-pro-t181',
            'services.deepseek.model_vision'   => 'deepseek-vision-t181',
        ]);

        $this->comercio = $this->negocio('Ferreteria T181');

        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = ConfianzaDelAgenteIaHelper::CAUTELOSO;
        $this->comercio->save();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        Event::fake([ChatIaMensajeActualizado::class]);
    }

    /**
     * El link real queda (también si se repite); los que no abren salen, cada uno por su motivo.
     *
     * @test
     */
    public function saca_los_links_que_no_abren_y_deja_los_reales()
    {
        $real = $this->excel($this->comercio);

        $de_otro = $this->excel($this->negocio('Otro negocio T181'));

        $vencido = $this->excel($this->comercio);
        AiArchivo::where('uuid', $this->uuid($vencido))->update(['expires_at' => now()->subDay()]);

        $sin_archivo = $this->excel($this->comercio);
        Storage::disk(ArchivosDelAgenteIaHelper::DISCO)->delete(AiArchivo::where('uuid', $this->uuid($sin_archivo))->value('path'));

        $inventado = 'https://api-t181.comerciocity.com/asistente/archivo/6dae5055-2130-4e7e-99f8-61c3f6b888b3?t=' . str_repeat('a', 48);

        // El real con el token cambiado en una letra (un token mal copiado).
        $token_cambiado = substr($real, 0, -1) . (substr($real, -1) === 'x' ? 'y' : 'x');

        $texto = "Acá tenés el Excel:\n" . $real . "\nY otra vez el mismo: *" . $real . "*\n"
            . 'Inventado: ' . $inventado . "\nMal copiado: " . $token_cambiado . "\nDe otro: " . $de_otro
            . "\nVencido: " . $vencido . "\nSin archivo: (" . $sin_archivo . ')';

        $r = LinksDeArchivosIaHelper::sin_links_que_no_abren($this->comercio->id, $texto);

        $this->assertSame(2, substr_count($r['texto'], $real), 'El real, las dos veces.');
        $this->assertStringNotContainsString('6dae5055', $r['texto']);
        $this->assertStringNotContainsString($token_cambiado . "\n", $r['texto']);
        $this->assertStringNotContainsString($this->uuid($de_otro), $r['texto']);
        $this->assertStringNotContainsString($this->uuid($vencido), $r['texto']);
        $this->assertStringNotContainsString($this->uuid($sin_archivo), $r['texto']);
        $this->assertSame(5, substr_count($r['texto'], LinksDeArchivosIaHelper::AVISO));
        $this->assertCount(5, $r['quitados']);
    }

    /**
     * Revisión independiente (19/11/2026): un link real en cursiva de WhatsApp (`_link_`) no se toma por roto
     * aunque el patrón se lleve el `_` de cierre como parte del token.
     *
     * @test
     */
    public function el_link_real_en_cursiva_de_whatsapp_queda()
    {
        $real = $this->excel($this->comercio);

        $texto = 'Bajalo acá: _' . $real . '_ y avisame.';

        $r = LinksDeArchivosIaHelper::sin_links_que_no_abren($this->comercio->id, $texto);

        $this->assertSame($texto, $r['texto']);
        $this->assertCount(0, $r['quitados']);
    }

    /**
     * Sin dominio ni token (como lo escribe a veces el modelo) tampoco pasa; un texto sin links sale igual.
     *
     * @test
     */
    public function el_link_sin_dominio_ni_token_tampoco_pasa()
    {
        $r = LinksDeArchivosIaHelper::sin_links_que_no_abren($this->comercio->id, 'Bajalo de asistente/archivo/53cbda62-2c00-454a-bd6f-cfe4af3bca5e y avisame.');

        $this->assertSame('Bajalo de ' . LinksDeArchivosIaHelper::AVISO . ' y avisame.', $r['texto']);

        $sin_nada = 'Son 145 artículos de Pinturas Sudamericanas.';
        $this->assertSame($sin_nada, LinksDeArchivosIaHelper::sin_links_que_no_abren($this->comercio->id, $sin_nada)['texto']);
    }

    /**
     * 🔴 Por el turno real: el modelo contesta (sin llamar a ninguna herramienta) con un link inventado, como en
     * Ferre Total. Lo que se guarda y sale no lo trae.
     *
     * @test
     */
    public function el_turno_no_deja_salir_un_link_que_ninguna_herramienta_devolvio()
    {
        $inventado = 'https://api-t181.comerciocity.com/asistente/archivo/53cbda62-2c00-454a-bd6f-cfe4af3bca5e?t=n3cEaa9XoEey2vw2l9l-qhzs4pOB5Lm5lSthmB0sTUPzKItF';

        Http::fake(function ($request) use ($inventado) {
            if (strpos($request->url(), 'deepseek') === false) {
                return Http::response(['error' => 'host sin stub'], 500);
            }

            return Http::response([
                'model'       => 'deepseek-flash-t181',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => "Acá tenés el Excel con los 145 artículos:\n\n" . $inventado . "\n\nVale hasta el 25/11."]],
                'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
            ], 200);
        });

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id, 'origen' => 'usuario']);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Seguí', 'estado' => 'listo']);

        $assistant = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => false]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('53cbda62', $texto);
        $this->assertStringNotContainsString('qhzs4pOB5', $texto, 'El token inventado (con un «-» que Str::random nunca da) sale entero.');
        $this->assertStringContainsString(LinksDeArchivosIaHelper::AVISO, $texto);

        $guardado = AiMessage::find($assistant->id);
        $this->assertStringNotContainsString('53cbda62', (string) $guardado->contenido, 'Tampoco queda en el historial.');
    }

    /**
     * El Excel no devuelve link si el archivo no quedó en el disco (put() que devuelve false).
     *
     * @test
     */
    public function sin_archivo_escrito_no_hay_link()
    {
        $disco = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disco->shouldReceive('put')->andReturn(false);
        $disco->shouldReceive('exists')->andReturn(false);
        $disco->shouldReceive('delete')->andReturn(true);

        Storage::shouldReceive('disk')->with(ArchivosDelAgenteIaHelper::DISCO)->andReturn($disco);

        $r = ArchivosDelAgenteIaHelper::guardar_tabla_en_excel($this->comercio->id, null, null, $this->tabla());

        $this->assertFalse($r['ok']);
        $this->assertNull($r['link'], 'La respuesta negativa de siempre: link en null.');
        $this->assertSame(0, AiArchivo::where('user_id', $this->comercio->id)->count(), 'Sin archivo, tampoco fila.');
    }

    // ---------------------------------------------------------------------

    /**
     * @param  string  $nombre
     * @return User
     */
    protected function negocio($nombre)
    {
        return User::create([
            'name'         => $nombre,
            'company_name' => $nombre,
            'email'        => 't181-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'api_url'      => 'https://api-t181.comerciocity.com',
        ]);
    }

    /**
     * Un Excel real del negocio, por el mismo camino que exportar_excel. Devuelve el link.
     *
     * @param  User  $negocio
     * @return string
     */
    protected function excel(User $negocio)
    {
        $r = ArchivosDelAgenteIaHelper::guardar_tabla_en_excel($negocio->id, null, null, $this->tabla());

        $this->assertTrue($r['ok'], json_encode($r));

        return $r['link'];
    }

    /**
     * @return array
     */
    protected function tabla()
    {
        return [
            'titulo'   => 'Esmaltes T181',
            'columnas' => [['clave' => 'nombre', 'etiqueta' => 'Artículo', 'formato' => 'texto']],
            'filas'    => [['nombre' => 'ESMALTE AC. MULT. BLANCO']],
        ];
    }

    /**
     * @param  string  $link
     * @return string
     */
    protected function uuid($link)
    {
        return basename((string) parse_url($link, PHP_URL_PATH));
    }
}
