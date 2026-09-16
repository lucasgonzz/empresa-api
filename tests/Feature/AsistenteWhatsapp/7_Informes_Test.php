<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\MostradorAccesoHelper;
use App\Models\MostradorAcceso;
use App\Models\MostradorReporte;
use Carbon\Carbon;

/**
 * Misión asistente-por-whatsapp — el informe de la mañana por WhatsApp (§3.7 del plan y rutas 3 y 4
 * del contrato).
 *
 * Lucas pidió "resumen + link" y que el link abra el informe en el celular sin pedir usuario y
 * contraseña.
 *
 * 🔴 Los dos tests que más importan de este archivo:
 *   - sin SPA_URL cargada la respuesta trae `url: null` y el admin manda el resumen SIN link. Es
 *     la clase "la URL que un sistema le entrega a otro, armada con APP_URL"
 *     (APRENDER_NO_PARCHEAR.md, 9/9/2026): `app.url` es la URL de la API, y un link armado con eso
 *     da 404 en el teléfono de 40 dueños a las 8:30 de la mañana. Se elige fallar visible.
 *   - el token NUNCA se guarda en claro: en la base solo vive su hash.
 */
class Informes_Test extends AsistenteWhatsappTestCase
{
    /** La URL del sistema del cliente, como la tendría cargada una instancia real. */
    const SPA = 'https://elcliente.comerciocity.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dar_extension();

        config(['services.mostrador.spa_url' => self::SPA]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Un informe del mostrador ya depositado por la skill.
     *
     * @param  array  $overrides
     * @return \App\Models\MostradorReporte
     */
    protected function informe(array $overrides = [])
    {
        return MostradorReporte::create(array_merge([
            'user_id'     => $this->comercio->id,
            'tipo'        => 'dia',
            'fecha'       => Carbon::yesterday()->format('Y-m-d'),
            'titulo'      => 'Ayer vendiste bien',
            'resumen'     => 'Cerraste 38 ventas por $ 420.000.',
            'contenido'   => ['bloques' => [['tipo' => 'parrafo', 'texto' => 'Ayer fue un buen día.']]],
            'estado'      => MostradorReporte::ESTADO_LISTO,
            'generado_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function informes_pendientes_lista_los_de_hoy_sin_avisar_y_emite_el_link()
    {
        $informe = $this->informe();

        $respuesta = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->assertStatus(200);

        $informes = $respuesta->json('informes');

        $this->assertCount(1, $informes);

        $this->assertEquals((int) $informe->id, (int) $informes[0]['id']);
        $this->assertEquals('dia', $informes[0]['tipo']);
        $this->assertEquals('Ayer vendiste bien', $informes[0]['titulo']);
        $this->assertEquals('Cerraste 38 ventas por $ 420.000.', $informes[0]['resumen']);

        $this->assertStringStartsWith(
            self::SPA . '/informe/',
            $informes[0]['url'],
            'El link lo arma este API con la URL del SISTEMA, no con la de la API.'
        );

        $this->assertEquals(
            1,
            MostradorAcceso::where('mostrador_reporte_id', $informe->id)->count(),
            'Pedir los pendientes emite el acceso.'
        );
    }

    /**
     * 🔴 El token en claro no vive en la base: solo su hash.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_token_se_guarda_hasheado_y_nunca_en_claro()
    {
        $informe = $this->informe();

        $url = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->json('informes.0.url');

        $token = substr($url, strrpos($url, '/') + 1);

        $this->assertEquals(MostradorAccesoHelper::LARGO_TOKEN, strlen($token));

        $acceso = MostradorAcceso::where('mostrador_reporte_id', $informe->id)->first();

        $this->assertNotEquals($token, $acceso->token_hash);
        $this->assertEquals(MostradorAcceso::hashear($token), $acceso->token_hash);

        $this->assertEquals(
            0,
            MostradorAcceso::where('token_hash', $token)->count(),
            'Un dump de la base no puede alcanzar para abrir un informe.'
        );
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function un_informe_ya_avisado_y_uno_de_otro_dia_no_vuelven_a_salir()
    {
        $this->informe(['tipo' => 'caja', 'avisado_at' => Carbon::now()]);

        $this->informe(['tipo' => 'tienda', 'generado_at' => Carbon::now()->subDays(2)]);

        $this->informe(['tipo' => 'compras', 'estado' => MostradorReporte::ESTADO_HECHOS, 'contenido' => null]);

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->assertStatus(200)
            ->json('informes');

        $this->assertCount(0, $informes, 'Solo los listos de hoy sin avisar.');
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function el_informe_de_otro_dueno_no_se_lista()
    {
        $otro = $this->otro_dueno();

        $this->informe(['user_id' => $otro->id, 'tipo' => 'caja']);

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->json('informes');

        $this->assertCount(0, $informes);
    }

    /**
     * 🔴 Son dos pasos a propósito: si el WhatsApp falla, el informe no queda marcado y sale en la
     * próxima corrida.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function avisado_marca_el_informe_y_es_idempotente()
    {
        $informe = $this->informe();

        $this->assertNull($informe->avisado_at);

        $this->postJson('api/admin-sync/asistente/informes/' . $informe->id . '/avisado', [], $this->headers())
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $informe->refresh();

        $this->assertNotNull($informe->avisado_at);

        $primera_marca = $informe->avisado_at->toDateTimeString();

        Carbon::setTestNow(Carbon::now()->addHour());

        $this->postJson('api/admin-sync/asistente/informes/' . $informe->id . '/avisado', [], $this->headers())
            ->assertStatus(200);

        $informe->refresh();

        $this->assertEquals(
            $primera_marca,
            $informe->avisado_at->toDateTimeString(),
            'Un segundo aviso no pisa la marca original.'
        );

        Carbon::setTestNow();

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->json('informes');

        $this->assertCount(0, $informes, 'Ya avisado, no vuelve a salir.');
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function avisar_el_informe_de_otro_dueno_es_404()
    {
        $otro = $this->otro_dueno();

        $ajeno = $this->informe(['user_id' => $otro->id]);

        $this->postJson('api/admin-sync/asistente/informes/' . $ajeno->id . '/avisado', [], $this->headers())
            ->assertStatus(404);

        $this->assertNull($ajeno->fresh()->avisado_at);
    }

    /**
     * 🔴 Sin SPA_URL se devuelve url null y el admin manda el resumen sin link: fallar visible
     * antes que mandar un link roto. Y NO se cae a app.url, que es la URL de la API.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_spa_url_el_informe_viaja_sin_link_y_no_se_inventa_ninguno()
    {
        config(['services.mostrador.spa_url' => null]);
        config(['app.url' => 'https://api-elcliente.comerciocity.com']);

        $informe = $this->informe();

        $informes = $this->getJson('api/admin-sync/asistente/informes-pendientes', $this->headers())
            ->assertStatus(200)
            ->json('informes');

        $this->assertCount(1, $informes, 'El informe se manda igual: lo que falta es el link, no el informe.');

        $this->assertNull($informes[0]['url']);

        $this->assertEquals(
            0,
            MostradorAcceso::where('mostrador_reporte_id', $informe->id)->count(),
            'Sin link no hay por qué emitir un token que nadie va a poder usar.'
        );
    }

    /**
     * La ruta pública: el dueño toca el link desde el teléfono y lee el informe sin sesión.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_token_abre_el_informe_sin_sesion_y_solo_lo_que_el_informe_dice()
    {
        $informe = $this->informe();

        $url = MostradorAccesoHelper::emitir($informe);

        $token = substr($url, strrpos($url, '/') + 1);

        $respuesta = $this->getJson('api/informe-compartido/' . $token)->assertStatus(200);

        $respuesta->assertJson([
            'model' => [
                'titulo'  => 'Ayer vendiste bien',
                'resumen' => 'Cerraste 38 ventas por $ 420.000.',
                'fecha'   => Carbon::yesterday()->format('Y-m-d'),
                'tipo'    => 'dia',
            ],
        ]);

        $this->assertNotEmpty($respuesta->json('model.contenido'));

        // Solo lectura: ni los hechos crudos ni nada que no sea el informe.
        $this->assertArrayNotHasKey('hechos', $respuesta->json('model'));
        $this->assertArrayNotHasKey('user_id', $respuesta->json('model'));
        $this->assertArrayNotHasKey('conversation_id', $respuesta->json('model'));

        $this->assertNotNull(
            MostradorAcceso::where('mostrador_reporte_id', $informe->id)->first()->usado_at,
            'La primera apertura se sella.'
        );
    }

    /**
     * El link no es de un solo uso: el dueño abre el informe, lo cierra y lo vuelve a abrir desde
     * el mismo WhatsApp un rato después.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_link_se_puede_abrir_mas_de_una_vez()
    {
        $informe = $this->informe();

        $url = MostradorAccesoHelper::emitir($informe);
        $token = substr($url, strrpos($url, '/') + 1);

        $this->getJson('api/informe-compartido/' . $token)->assertStatus(200);
        $this->getJson('api/informe-compartido/' . $token)->assertStatus(200);
    }

    /**
     * @group asistente-whatsapp
     * @test
     */
    public function un_link_vencido_da_410_y_lo_dice_distinto_de_uno_que_no_existe()
    {
        $informe = $this->informe();

        $url = MostradorAccesoHelper::emitir($informe);
        $token = substr($url, strrpos($url, '/') + 1);

        $acceso = MostradorAcceso::where('mostrador_reporte_id', $informe->id)->first();
        $acceso->expira_at = Carbon::now()->subMinute();
        $acceso->save();

        $this->getJson('api/informe-compartido/' . $token)->assertStatus(410);

        $this->getJson('api/informe-compartido/' . str_repeat('z', 64))->assertStatus(404);
    }

    /**
     * Un informe que dejó de estar 'listo' no se sirve aunque el link siga vigente.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function un_informe_que_ya_no_esta_listo_no_se_abre()
    {
        $informe = $this->informe();

        $url = MostradorAccesoHelper::emitir($informe);
        $token = substr($url, strrpos($url, '/') + 1);

        $informe->estado = MostradorReporte::ESTADO_HECHOS;
        $informe->save();

        $this->getJson('api/informe-compartido/' . $token)->assertStatus(404);
    }

    /**
     * El vencimiento es de una semana: un link a los números del negocio no puede valer para
     * siempre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function el_link_vence_a_los_siete_dias()
    {
        $this->assertEquals(7, MostradorAccesoHelper::DIAS_DE_VIGENCIA);

        $informe = $this->informe();

        MostradorAccesoHelper::emitir($informe);

        $acceso = MostradorAcceso::where('mostrador_reporte_id', $informe->id)->first();

        $this->assertEquals(
            Carbon::now()->addDays(7)->format('Y-m-d'),
            $acceso->expira_at->format('Y-m-d')
        );
    }
}
