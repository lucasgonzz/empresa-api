<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\Helpers\PdfLinkHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Qué rutas protege `descarga.comercio` y cómo resuelve el dueño de cada tipo de recurso (misión
 * pdf-de-venta-publico, 10/10/2026).
 *
 * La lista de rutas está fijada acá a propósito: el comentario de `routes/web.php` le dice a quien
 * agregue una ruta de PDF o export que va adentro de un grupo protegido, y
 * `ninguna_ruta_de_pdf_o_export_queda_publica_sin_saberlo()` es el que lo hace cumplir.
 */
class Descarga_rutas_protegidas_Test extends DescargaTestCase
{
    /**
     * Cada ruta de PDF y export de `routes/web.php` con su tipo y su parámetro, tal como los pide el
     * plan de la misión (secciones 2 y 3).
     */
    const RUTAS_PROTEGIDAS = [
        'sale/pdf/{id}'                                               => 'descarga.comercio:sale,id,tienda',
        'sale/ticket-pdf/{id}'                                        => 'descarga.comercio:sale,id',
        'sale/ticket-raw/{id}'                                        => 'descarga.comercio:sale,id',
        'sale/sale-ticket-pdf/{id}'                                   => 'descarga.comercio:sale,id',
        'sale/afip-ticket-pdf/{id}'                                   => 'descarga.comercio:afip_ticket,id',
        'sale/afip-ticket-a4-pdf/{id}'                                => 'descarga.comercio:afip_ticket,id',
        'sale/delivered-articles-pdf/{id}'                            => 'descarga.comercio:sale,id',
        'sale/etiqueta-envio/pdf/{sale_id}'                           => 'descarga.comercio:sale,sale_id',
        'road-map/pdf/{id}'                                           => 'descarga.comercio:road_map,id',
        'deposit-movement/pdf/{id}'                                   => 'descarga.comercio:deposit_movement,id',
        'article/pdf/{ids}/{moneda_id?}'                              => 'descarga.comercio:articles,ids',
        'article/tickets-pdf/{ids}'                                   => 'descarga.comercio:articles,ids',
        'article/bar-codes-pdf/{ids}'                                 => 'descarga.comercio:articles,ids',
        'article/bar-codes-etiquetas-pdf/{ids}'                       => 'descarga.comercio:articles,ids',
        'article/list-pdf/{ids}'                                      => 'descarga.comercio:articles,ids',
        'article/table-pdf'                                           => 'descarga.comercio:sesion',
        'article/article-offer-pdf/{article_pdf_id}/{ids}'            => 'descarga.comercio:articles,ids',
        'article/pdf-personalizado'                                   => 'descarga.comercio:sesion',
        'articles-stock-minimo/excel'                                 => 'descarga.comercio:sesion',
        'budget/pdf/{id}/{with_prices}/{with_images}'                 => 'descarga.comercio:budget,id',
        'order-production/pdf/{id}/{with_prices}'                     => 'descarga.comercio:order_production,id',
        'order-production/articles-pdf/{id}'                          => 'descarga.comercio:order_production,id',
        'current-acount/pdf/{credit_account_id}/{months_ago}/{type?}' => 'descarga.comercio:cuenta_corriente,credit_account_id,months_ago',
        'current-acount/pdf/{id}'                                     => 'descarga.comercio:current_acount,id',
        'order/pdf/{id}'                                              => 'descarga.comercio:order,id',
        'provider-order/pdf/{id}'                                     => 'descarga.comercio:provider_order,id',
        'article-clients/excel/export/{price_type_id?}'               => 'descarga.comercio:sesion',
        'article-base/excel/export'                                   => 'descarga.comercio:sesion',
        'client/excel/export'                                         => 'descarga.comercio:sesion',
        'provider/excel/export'                                       => 'descarga.comercio:sesion',
        'apertura-caja/excel/export/{id}'                             => 'descarga.comercio:apertura_caja,id',
        'provider-orders/export/{id}'                                 => 'descarga.comercio:provider_order,id',
        'sales/excel/export/{from_date}/{until_date?}'                => 'descarga.comercio:sesion',
        'sales/excel/breakdown-export/{from_date}/{until_date?}'      => 'descarga.comercio:sesion',
        'nota-credito/excel/export/{from_date}/{until_date?}'         => 'descarga.comercio:sesion',
        'cheque/excel/export'                                         => 'descarga.comercio:sesion',
        'sale/charts/{from}/{to}'                                     => 'descarga.comercio:sesion',
        'afip-txt/{mes_inicio}/{mes_fin}'                             => 'descarga.comercio:sesion',
        'afip-txt-alicuotas/{mes_inicio}/{mes_fin}'                   => 'descarga.comercio:sesion',
        'afip-iva-compras/{mes_inicio}/{mes_fin}'                     => 'descarga.comercio:sesion',
        'afip-iva-ventas/{mes_inicio}/{mes_fin}'                      => 'descarga.comercio:sesion',
        'acopio-article-delivery/{id}'                                => 'descarga.comercio:acopio_article_delivery,id',
        'resumen-caja/pdf/{id}'                                       => 'descarga.comercio:resumen_caja,id',
        'reportes/inventario/{company_name}/{periodo}'                => 'descarga.comercio:comercio,company_name',
        'reportes/clientes/{company_name}/{periodo}'                  => 'descarga.comercio:comercio,company_name',
        'reportes/excel-articulos/{company_name}/{mes}'               => 'descarga.comercio:comercio,company_name',
    ];

    /**
     * Rutas de `web.php` que parecen de PDF o export y quedan SIN el middleware, cada una con su
     * motivo. Agregar una acá es una decisión, no un trámite.
     */
    const AFUERA_A_PROPOSITO = [
        // Exige sesión en su propio controlador desde el 5/10/2026 (responde 401 sin sesión).
        'client/pdf',
        // Fuera del alcance de la misión (decisión del plan).
        'super-budget',
        // Confinado por StoragePathHelper; lo sirve la notificación global de exportaciones.
        'exported-files/{path}',
    ];

    /**
     * Las rutas de mapa (uri => middleware) del grupo `web`.
     *
     * @return array<string, array<int, string>>
     */
    protected function rutas_web()
    {
        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {

            $middleware = $ruta->middleware();

            if (!in_array('web', $middleware, true)) {
                continue;
            }

            $rutas[$ruta->uri()] = $middleware;
        }

        return $rutas;
    }

    /**
     * Cada ruta de la lista tiene el middleware con su tipo y su parámetro exactos.
     *
     * @test
     */
    public function cada_ruta_de_pdf_y_export_tiene_su_regla()
    {
        $rutas = $this->rutas_web();

        foreach (self::RUTAS_PROTEGIDAS as $uri => $esperado) {

            $this->assertArrayHasKey($uri, $rutas, 'La ruta ' . $uri . ' no existe en web.php.');

            $this->assertContains($esperado, $rutas[$uri], 'La ruta ' . $uri . ' no tiene ' . $esperado . '.');
        }
    }

    /**
     * La opción `tienda` (que deja pasar `?origin=tienda` al controlador) está SOLO en
     * `sale/pdf/{id}`: es el único controlador que exige el SalePdfAccessToken. En cualquier otra
     * ruta abriría el PDF a quien agregue ese parámetro.
     *
     * @test
     */
    public function la_opcion_tienda_esta_solo_en_el_pdf_de_la_venta()
    {
        $con_la_opcion = [];

        foreach ($this->rutas_web() as $uri => $middleware) {

            foreach ($middleware as $nombre) {
                if (strpos($nombre, 'descarga.comercio:') === 0 && in_array('tienda', explode(',', substr($nombre, strlen('descarga.comercio:'))), true)) {
                    $con_la_opcion[] = $uri;
                }
            }
        }

        $this->assertSame(['sale/pdf/{id}'], $con_la_opcion);
    }

    /**
     * Ninguna ruta web cuya URI dice pdf, excel o export queda sin `descarga.comercio`, salvo las
     * que están afuera a propósito. Es el guardián de la próxima ruta de PDF que alguien agregue.
     *
     * @test
     */
    public function ninguna_ruta_de_pdf_o_export_queda_publica_sin_saberlo()
    {
        foreach ($this->rutas_web() as $uri => $middleware) {

            if (!preg_match('/pdf|excel|export/i', $uri) || in_array($uri, self::AFUERA_A_PROPOSITO, true)) {
                continue;
            }

            $protegida = false;

            foreach ($middleware as $nombre) {
                if (strpos($nombre, 'descarga.comercio') === 0) {
                    $protegida = true;
                }
            }

            $this->assertTrue($protegida, 'La ruta ' . $uri . ' parece de PDF o export y no pasa por descarga.comercio.');
        }
    }

    /**
     * Presupuesto: sin sesión 404; con su token o con la sesión del dueño, se sirve.
     *
     * @test
     */
    public function presupuesto_sesion_o_token()
    {
        $presupuesto_id = $this->presupuesto_de($this->dueno->id);

        $this->get('budget/pdf/' . $presupuesto_id . '/1/0')->assertStatus(200)->assertSee(self::SERVIDA);

        $token = PdfLinkHelper::token_para('budget', $presupuesto_id, $this->dueno->id);

        $this->sin_sesion();

        $this->get('budget/pdf/' . $presupuesto_id . '/1/0')->assertStatus(404);
        $this->get('budget/pdf/' . $presupuesto_id . '/1/0?pdf_column_profile_id=4&t=' . $token)->assertStatus(200)->assertSee(self::SERVIDA);
    }

    /**
     * `current-acount/pdf/{x}/{cantidad}/{type}`: con cantidad > 0 el {x} es una CUENTA corriente y
     * abre con un token `credit_account`; con 0 es UN movimiento y abre con un token
     * `current_acount`. Un token del otro tipo no sirve, aunque el id coincida.
     *
     * @test
     */
    public function cuenta_corriente_el_tipo_lo_decide_la_cantidad()
    {
        $cc = $this->cuenta_corriente_de($this->dueno->id);

        $token_cuenta = PdfLinkHelper::token_para('credit_account', $cc['credit_account_id'], $this->dueno->id);
        $token_movimiento = PdfLinkHelper::token_para('current_acount', $cc['current_acount_id'], $this->dueno->id);

        $this->sin_sesion();

        // Cuenta: 60 movimientos, como el recordatorio de cobro.
        $this->get('current-acount/pdf/' . $cc['credit_account_id'] . '/60/simple')->assertStatus(404);
        $this->get('current-acount/pdf/' . $cc['credit_account_id'] . '/60/simple?t=' . $token_cuenta)->assertStatus(200)->assertSee(self::SERVIDA);

        // Con cantidad 0 el mismo número se lee como movimiento: el token de la cuenta ya no sirve.
        $this->get('current-acount/pdf/' . $cc['current_acount_id'] . '/0/simple?t=' . $token_cuenta)->assertStatus(404);
        $this->get('current-acount/pdf/' . $cc['current_acount_id'] . '/0/simple?t=' . $token_movimiento)->assertStatus(200)->assertSee(self::SERVIDA);

        // El PDF del movimiento suelto (pago / nota de crédito).
        $this->get('current-acount/pdf/' . $cc['current_acount_id'])->assertStatus(404);
        $this->get('current-acount/pdf/' . $cc['current_acount_id'] . '?t=' . $token_movimiento)->assertStatus(200)->assertSee(self::SERVIDA);
    }

    /**
     * Un movimiento viejo sin `user_id` (la columna es nullable desde 2021) sigue siendo del
     * comercio de su cuenta corriente: el dueño logueado lo abre, otro comercio no.
     *
     * @test
     */
    public function un_movimiento_sin_user_id_es_del_dueno_de_su_cuenta()
    {
        $cc = $this->cuenta_corriente_de($this->dueno->id, null);

        $this->get('current-acount/pdf/' . $cc['current_acount_id'])->assertStatus(200)->assertSee(self::SERVIDA);

        $this->sin_sesion();
        $this->actingAs($this->crear_otro_comercio(), 'web');

        $this->get('current-acount/pdf/' . $cc['current_acount_id'])->assertStatus(404);
    }

    /**
     * El comprobante de ARCA (`afip_tickets`, sin `user_id`) es del dueño de su venta. No admite
     * token: el de la venta no lo abre (por WhatsApp va `sale/pdf/{id}?afip_ticket_id=`).
     *
     * @test
     */
    public function comprobante_de_arca_es_del_dueno_de_su_venta()
    {
        $venta_id = $this->venta_de($this->dueno->id);

        $ticket_id = DB::table('afip_tickets')->insertGetId([
            'sale_id'    => $venta_id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->get('sale/afip-ticket-a4-pdf/' . $ticket_id)->assertStatus(200)->assertSee(self::SERVIDA);

        $token_de_la_venta = PdfLinkHelper::token_para('sale', $venta_id, $this->dueno->id);

        $this->sin_sesion();
        $this->actingAs($this->crear_otro_comercio(), 'web');

        $this->get('sale/afip-ticket-a4-pdf/' . $ticket_id)->assertStatus(404);
        $this->get('sale/afip-ticket-a4-pdf/' . $ticket_id . '?t=' . $token_de_la_venta)->assertStatus(404);
    }

    /**
     * Etiquetas y catálogos por `{ids}`: la sesión tiene que ser dueña de TODOS los artículos. Uno
     * ajeno en la lista corta todo; un segmento que no es un id también; un `-` de más al final no.
     *
     * @test
     */
    public function articulos_por_ids_tienen_que_ser_todos_del_dueno()
    {
        $propios = DB::table('articles')->where('user_id', $this->dueno->id)->orderBy('id')->limit(2)->pluck('id')->all();

        $this->assertCount(2, $propios, 'El fixture tiene que traer artículos del dueño.');

        $ajeno = DB::table('articles')->insertGetId([
            'name'       => 'Articulo de otro comercio',
            'user_id'    => $this->crear_otro_comercio()->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $lista = implode('-', $propios);

        $this->get('article/list-pdf/' . $lista)->assertStatus(200)->assertSee(self::SERVIDA);
        $this->get('article/list-pdf/' . $lista . '-')->assertStatus(200)->assertSee(self::SERVIDA);

        $this->get('article/list-pdf/' . $lista . '-' . $ajeno)->assertStatus(404);
        $this->get('article/list-pdf/' . $lista . '-abc')->assertStatus(404);

        $this->sin_sesion();

        $this->get('article/list-pdf/' . $lista)->assertStatus(404);
    }

    /**
     * Una ruta sin id (gráficos de ventas, JSON real): sin sesión 404; con sesión, se sirve.
     *
     * @test
     */
    public function una_ruta_sin_id_pide_sesion()
    {
        $this->get('sale/charts/2026-01-01/2026-01-31')->assertStatus(200)->assertJsonStructure(['charts']);

        $this->sin_sesion();

        $this->get('sale/charts/2026-01-01/2026-01-31')->assertStatus(404);
    }

    /**
     * Sin sesión, una ruta sin id se sirve solo con la ventana abierta del dueño de la instalación
     * (`config('app.USER_ID')`, que es a quien hoy caen esas rutas), y queda registrada.
     *
     * @test
     */
    public function una_ruta_sin_id_usa_la_ventana_del_dueno_de_la_instalacion()
    {
        $this->assertSame($this->dueno->id, (int) config('app.USER_ID'), 'El .env.testing tiene que tener USER_ID=500.');

        $this->sin_sesion();

        $this->get('sales/excel/export/2026-01-01/2026-01-31')->assertStatus(404);

        $this->abrir_ventana($this->dueno->id);

        Log::spy();

        $this->get('sales/excel/export/2026-01-01/2026-01-31')->assertStatus(200)->assertSee(self::SERVIDA);

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'ventana de transición') !== false
                && $contexto['tipo'] === 'sesion'
                && $contexto['con_sesion'] === false;
        })->once();
    }

    /**
     * La ventana de transición es del DUEÑO DEL RECURSO: la de otro comercio no abre lo mío, y la
     * mía abre lo mío aunque haya sesión de otro comercio (queda registrado con sesión).
     *
     * @test
     */
    public function la_ventana_es_del_dueno_del_recurso()
    {
        $otro = $this->crear_otro_comercio();

        $venta_id = $this->venta_de($this->dueno->id);

        $this->abrir_ventana($otro->id);

        $this->sin_sesion();
        $this->actingAs($otro, 'web');

        $this->get('sale/pdf/' . $venta_id)->assertStatus(404);

        $this->abrir_ventana($this->dueno->id);

        Log::spy();

        $this->get('sale/pdf/' . $venta_id)->assertStatus(200)->assertSee(self::SERVIDA);

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'ventana de transición') !== false && $contexto['con_sesion'] === true;
        })->once();
    }
}
