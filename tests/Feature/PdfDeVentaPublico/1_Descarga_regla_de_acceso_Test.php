<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Models\PdfLink;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La regla de acceso del middleware `descarga.comercio` sobre el PDF de la venta
 * (`GET sale/pdf/{id}`), caso por caso (misión pdf-de-venta-publico, 10/10/2026):
 *
 *   1. sesión del dueño → se sirve;
 *   2. token del link válido para ESA venta → se sirve, aunque haya sesión de otro comercio;
 *   3. `origin=tienda` → sigue al controlador, que exige su SalePdfAccessToken;
 *   4. ventana de transición del dueño abierta → se sirve y queda un Log::info;
 *   5. si no → 404, el mismo que para una venta que no existe.
 */
class Descarga_regla_de_acceso_Test extends DescargaTestCase
{
    /**
     * Sin sesión, sin token y con la ventana cerrada, el PDF de una venta que existe da 404.
     * Es el hueco que cierra esta misión: hasta acá `sale/pdf/{id}` se servía a cualquiera.
     *
     * @test
     */
    public function sin_sesion_ni_token_con_la_ventana_cerrada_da_404()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id)->assertStatus(404);
    }

    /**
     * Con la ventana del dueño abierta, el link viejo sin token sigue andando (links ya mandados por
     * WhatsApp o mail) y queda registrado: tipo, id, sin sesión, host del Referer. Nunca el token ni
     * la IP.
     *
     * @test
     */
    public function con_la_ventana_abierta_se_sirve_y_queda_registrado()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $this->abrir_ventana($this->dueno->id);

        $this->sin_sesion();

        Log::spy();

        $this->get('sale/pdf/' . $venta_id . '?t=token-que-no-existe', ['Referer' => 'https://ferreteria.comerciocity.com/ventas?x=1'])
            ->assertStatus(200)
            ->assertSee(self::SERVIDA);

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto = []) use ($venta_id) {

            if (strpos($mensaje, 'ventana de transición') === false) {
                return false;
            }

            // Nada del contexto puede ser el token que vino ni una IP.
            foreach ($contexto as $clave => $valor) {
                if ($valor === 'token-que-no-existe' || $clave === 'ip') {
                    return false;
                }
            }

            return $contexto['tipo'] === 'sale'
                && (string) $contexto['id'] === (string) $venta_id
                && $contexto['con_sesion'] === false
                && $contexto['trajo_token'] === true
                && $contexto['referer_host'] === 'ferreteria.comerciocity.com'
                && $contexto['ruta'] === 'sale/pdf/{id}';
        })->once();
    }

    /**
     * Una ventana vencida (fecha pasada) es lo mismo que cerrada.
     *
     * @test
     */
    public function una_ventana_vencida_no_abre()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        DB::table('users')->where('id', $this->dueno->id)->update(['pdf_links_legacy_until' => Carbon::now()->subMinute()]);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id)->assertStatus(404);
    }

    /**
     * Con el token del link de esa venta, se sirve sin sesión y con la ventana cerrada: es lo que
     * abre el cliente final desde WhatsApp, el mail o la plantilla de Meta.
     *
     * @test
     */
    public function con_token_valido_se_sirve_sin_sesion()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $token = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id . '?t=' . $token)->assertStatus(200)->assertSee(self::SERVIDA);

        // Y con el resto del query que ya llevaba el link (diseño, factura), también.
        $this->get('sale/pdf/' . $venta_id . '?pdf_column_profile_id=3&afip_ticket_id=9&t=' . $token)
            ->assertStatus(200)
            ->assertSee(self::SERVIDA);
    }

    /**
     * Un token NO es una llave maestra: el de otra venta, el de otro tipo con el mismo id, uno
     * revocado, uno inventado o el correcto con otras mayúsculas dan 404.
     *
     * @test
     */
    public function los_tokens_que_no_son_de_esa_venta_dan_404()
    {
        $venta_id = $this->venta_de($this->dueno->id);
        $otra_venta_id = $this->venta_de($this->dueno->id);

        $token_de_otra_venta = PdfLinkHelper::token_para('sale', $otra_venta_id, $this->dueno->id);

        // Un presupuesto con token y el MISMO id que la venta: el tipo tiene que cortar.
        $token_de_otro_tipo = PdfLink::create([
            'user_id'  => $this->dueno->id,
            'tipo'     => 'budget',
            'model_id' => $venta_id,
            'token'    => str_repeat('B', 48),
        ])->token;

        $token_revocado = PdfLink::create([
            'user_id'    => $this->dueno->id,
            'tipo'       => 'sale',
            'model_id'   => $venta_id,
            'token'      => str_repeat('R', 48),
            'revoked_at' => Carbon::now()->subDay(),
        ])->token;

        $token_valido = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id . '?t=' . $token_de_otra_venta)->assertStatus(404);
        $this->get('sale/pdf/' . $venta_id . '?t=' . $token_de_otro_tipo)->assertStatus(404);
        $this->get('sale/pdf/' . $venta_id . '?t=' . $token_revocado)->assertStatus(404);
        $this->get('sale/pdf/' . $venta_id . '?t=inventado123')->assertStatus(404);
        $this->get('sale/pdf/' . $venta_id . '?t=')->assertStatus(404);
        $this->get('sale/pdf/' . $venta_id . '?t[]=' . $token_valido)->assertStatus(404);

        // La collation de MySQL compara sin mayúsculas: el hash_equals del helper es el que corta.
        $this->assertNotSame(strtolower($token_valido), $token_valido, 'El token tiene que traer mayúsculas para que esto mida algo.');
        $this->get('sale/pdf/' . $venta_id . '?t=' . strtolower($token_valido))->assertStatus(404);
    }

    /**
     * El dueño logueado (y su empleado) abren el PDF sin token y con la ventana cerrada: es todo lo
     * que abre la SPA, que no cambió ningún botón.
     *
     * @test
     */
    public function la_sesion_del_dueno_y_de_su_empleado_abren_sin_token()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        // EmpresaTestCase deja la sesión del dueño.
        $this->get('sale/pdf/' . $venta_id)->assertStatus(200)->assertSee(self::SERVIDA);

        $this->sin_sesion();
        $this->actingAs($this->crear_empleado_del_dueno(), 'web');

        $this->get('sale/pdf/' . $venta_id)->assertStatus(200)->assertSee(self::SERVIDA);
    }

    /**
     * La sesión de OTRO comercio no abre la venta sin token (ventana cerrada): en una base compartida
     * es exactamente el caso de enumerar ids desde el frente propio.
     *
     * @test
     */
    public function la_sesion_de_otro_comercio_sin_token_da_404()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $otro = $this->crear_otro_comercio();

        $this->sin_sesion();
        $this->actingAs($otro, 'web');

        $this->get('sale/pdf/' . $venta_id)->assertStatus(404);
    }

    /**
     * Pero con el token sí: un comercio le manda el link a otro comercio de la misma base, y la
     * sesión ajena no tiene que cortar lo que el token habilita.
     *
     * @test
     */
    public function la_sesion_de_otro_comercio_con_token_se_sirve()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $token = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->sin_sesion();
        $this->actingAs($this->crear_otro_comercio(), 'web');

        $this->get('sale/pdf/' . $venta_id . '?t=' . $token)->assertStatus(200)->assertSee(self::SERVIDA);
    }

    /**
     * Una venta que no existe da EXACTAMENTE la misma respuesta que una ajena: un 403 o un cuerpo
     * distinto le diría a quien enumera ids cuáles existen.
     *
     * @test
     */
    public function un_id_inexistente_da_el_mismo_404_que_sin_permiso()
    {
        $venta_ajena_id = $this->venta_de($this->crear_otro_comercio()->id);

        $inexistente = (int) DB::table('sales')->max('id') + 1000;

        $sin_permiso = $this->get('sale/pdf/' . $venta_ajena_id);
        $no_existe = $this->get('sale/pdf/' . $inexistente);

        $sin_permiso->assertStatus(404);
        $no_existe->assertStatus(404);

        $this->assertSame($sin_permiso->getContent(), $no_existe->getContent());

        // Un id que no es un id tampoco: '12abc' encontraría la venta 12 en MySQL.
        $this->get('sale/pdf/' . $venta_ajena_id . 'abc')->assertStatus(404);
    }

    /**
     * `origin=tienda` sigue exigiendo el SalePdfAccessToken de un solo uso que emite tienda-api: el
     * middleware lo deja pasar al controlador (real, sin fingir) y es el controlador el que corta con
     * su 403 de siempre si no hay token de la tienda. Sin sesión y con la ventana cerrada.
     *
     * @test
     */
    public function origin_tienda_sigue_exigiendo_su_token_de_un_solo_uso()
    {
        $this->usar_el_sale_controller_real();

        $venta_id = $this->venta_de($this->dueno->id);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id . '?origin=tienda')->assertStatus(403);
        $this->get('sale/pdf/' . $venta_id . '?origin=tienda&token=no-es-de-la-tienda')->assertStatus(403);

        // Un token de la tienda vencido tampoco: el candado del controlador está intacto.
        DB::table('sale_pdf_access_tokens')->insert([
            'sale_id'    => $venta_id,
            'token'      => 'token-tienda-vencido',
            'expires_at' => Carbon::now()->subMinute(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->get('sale/pdf/' . $venta_id . '?origin=tienda&token=token-tienda-vencido')->assertStatus(403);
    }

    /**
     * 🔴 `origin=tienda` abre camino SOLO en `sale/pdf/{id}`: es el único controlador que después
     * exige el SalePdfAccessToken. En las rutas hermanas (ticket, artículos entregados, ticket-raw,
     * etiqueta de envío) el controlador ni mira ese parámetro, así que el middleware no puede
     * soltarlas: sin sesión dan el mismo 404 que una venta inexistente. Hallazgo de la verificación
     * del 10/10/2026: medido en vivo, `sale/sale-ticket-pdf/1?origin=tienda` devolvía el PDF.
     *
     * Con los controladores fingidos: si el middleware las soltara, contestarían 200 y el test falla
     * (en vez de que el PDF real mate el proceso).
     *
     * @test
     */
    public function origin_tienda_no_abre_las_rutas_hermanas_de_la_venta()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $inexistente = (int) DB::table('sales')->max('id') + 1000;

        $this->sin_sesion();

        $no_existe = $this->get('sale/sale-ticket-pdf/' . $inexistente . '?origin=tienda');
        $no_existe->assertStatus(404);

        $hermanas = [
            'sale/sale-ticket-pdf/',
            'sale/delivered-articles-pdf/',
            'sale/ticket-raw/',
            'sale/etiqueta-envio/pdf/',
            'sale/ticket-pdf/',
        ];

        foreach ($hermanas as $ruta) {

            $respuesta = $this->get($ruta . $venta_id . '?origin=tienda');

            $respuesta->assertStatus(404);

            $this->assertSame($no_existe->getContent(), $respuesta->getContent(), $ruta . ' con origin=tienda tiene que dar el mismo 404 que una venta inexistente.');

            // Con un token de la tienda pegado, tampoco: esas rutas no lo validan.
            $this->get($ruta . $venta_id . '?origin=tienda&token=cualquiera')->assertStatus(404);
        }

        // Y `sale/pdf/{id}` con origin=tienda sigue llegando al controlador (acá, el fingido).
        $this->get('sale/pdf/' . $venta_id . '?origin=tienda')->assertStatus(200)->assertSee(self::SERVIDA);
    }

    /**
     * El token de un solo uso de la tienda (`token=`) no reemplaza al del link (`t=`) fuera del
     * camino `origin=tienda`: sin origin, es un parámetro más y la regla corta.
     *
     * @test
     */
    public function el_token_de_la_tienda_no_sirve_sin_origin_tienda()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        DB::table('sale_pdf_access_tokens')->insert([
            'sale_id'    => $venta_id,
            'token'      => 'token-tienda-vigente',
            'expires_at' => Carbon::now()->addMinutes(5),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->sin_sesion();

        $this->get('sale/pdf/' . $venta_id . '?token=token-tienda-vigente')->assertStatus(404);
    }

    /**
     * Las variantes de la venta (ticket, artículos entregados) siguen la misma regla con el mismo
     * token: es la misma venta.
     *
     * @test
     */
    public function las_variantes_de_la_venta_siguen_la_misma_regla()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $token = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->sin_sesion();

        $this->get('sale/sale-ticket-pdf/' . $venta_id)->assertStatus(404);
        $this->get('sale/delivered-articles-pdf/' . $venta_id)->assertStatus(404);
        $this->get('sale/ticket-raw/' . $venta_id)->assertStatus(404);
        $this->get('sale/etiqueta-envio/pdf/' . $venta_id)->assertStatus(404);

        $this->get('sale/sale-ticket-pdf/' . $venta_id . '?t=' . $token)->assertStatus(200)->assertSee(self::SERVIDA);
        $this->get('sale/delivered-articles-pdf/' . $venta_id . '?t=' . $token)->assertStatus(200)->assertSee(self::SERVIDA);
    }
}
