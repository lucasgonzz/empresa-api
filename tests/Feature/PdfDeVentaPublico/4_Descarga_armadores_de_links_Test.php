<?php

namespace Tests\Feature\PdfDeVentaPublico;

use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Http\Controllers\Helpers\PdfLinkHelper;
use App\Http\Controllers\Helpers\asistente_ia\LinkDePdfIaHelper;
use App\Mail\ComercioCityMail;
use App\Models\AiConversation;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\PdfLink;
use App\Models\Sale;
use App\Services\RecordatorioCobroSenderService;
use App\Services\SaleWhatsappSenderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Los cuatro armadores de links que mandan un PDF fuera del sistema llevan un `t=` que abre ESE
 * recurso (misión pdf-de-venta-publico, 10/10/2026): WhatsApp (envío del comprobante), recordatorio
 * de cobro (venta y cuenta corriente), mail de venta (venta y cuenta corriente) y asistente (venta y
 * presupuesto).
 *
 * Los dos servicios de WhatsApp arman la URL en métodos privados (no salen a Kapso desde acá): se
 * llaman por reflexión, que es exactamente lo que los dos caminos de envío usan.
 */
class Descarga_armadores_de_links_Test extends DescargaTestCase
{
    /**
     * Saca el `t` del query de una URL.
     *
     * @param  string  $url
     * @return string|null
     */
    protected function token_de($url)
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $parametros);

        return isset($parametros['t']) ? $parametros['t'] : null;
    }

    /**
     * Llama a un método privado.
     *
     * @param  object  $objeto
     * @param  string  $metodo
     * @param  array   $argumentos
     * @return mixed
     */
    protected function llamar_privado($objeto, $metodo, array $argumentos)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * El comprobante por WhatsApp (send_document o header DOCUMENT de `cc_cli_comprobante`): Meta lo
     * baja desde su servidor, sin sesión, así que el link tiene que traer el token de la venta.
     *
     * @test
     */
    public function el_comprobante_por_whatsapp_lleva_el_token_de_la_venta()
    {
        $venta = Sale::find($this->venta_de($this->dueno->id));

        $url = $this->llamar_privado(new SaleWhatsappSenderService(), 'build_pdf_url', [$venta]);

        $this->assertStringStartsWith(rtrim($this->dueno->api_url, '/') . '/sale/pdf/' . $venta->id . '?t=', $url);
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta->id, $this->token_de($url)));
    }

    /**
     * El recordatorio de cobro: el link de cada venta (texto libre) y el de la cuenta corriente
     * (texto libre y header DOCUMENT de la plantilla) llevan su token, del tipo que corresponde.
     *
     * @test
     */
    public function el_recordatorio_de_cobro_lleva_tokens_de_venta_y_de_cuenta()
    {
        $servicio = new RecordatorioCobroSenderService();

        $venta = Sale::find($this->venta_de($this->dueno->id));

        $url_venta = $this->llamar_privado($servicio, 'build_sale_pdf_url', [$venta, 'https://api-x.comerciocity.com']);

        $this->assertStringStartsWith('https://api-x.comerciocity.com/sale/pdf/' . $venta->id . '?t=', $url_venta);
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta->id, $this->token_de($url_venta)));

        $cc = $this->cuenta_corriente_de($this->dueno->id);
        $cuenta = CreditAccount::find($cc['credit_account_id']);

        $url_cuenta = $this->llamar_privado($servicio, 'build_current_acount_pdf_url', [$cuenta, 'https://api-x.comerciocity.com']);

        $this->assertStringStartsWith('https://api-x.comerciocity.com/current-acount/pdf/' . $cuenta->id . '/60/simple?t=', $url_cuenta);
        $this->assertTrue(PdfLinkHelper::token_valido('credit_account', $cuenta->id, $this->token_de($url_cuenta)));

        // Sin api_url no hay link (y tampoco token emitido).
        $this->assertNull($this->llamar_privado($servicio, 'build_sale_pdf_url', [$venta, '']));
    }

    /**
     * La pregunta del masivo ("¿este cliente tiene un resumen para adjuntar?") NO emite tokens: un
     * repaso de la cartera no tiene que dejar filas de links que nadie mandó.
     *
     * @test
     */
    public function preguntar_si_hay_resumen_no_emite_tokens()
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['api_url' => 'https://api-x.comerciocity.com']);

        $cc = $this->cuenta_corriente_de($this->dueno->id);

        $antes = PdfLink::count();

        $hay = (new RecordatorioCobroSenderService())->hay_resumen_de_cuenta($this->dueno->id, Client::find($cc['client_id']), null);

        $this->assertTrue($hay);
        $this->assertSame($antes, PdfLink::count());
    }

    /**
     * El mail de venta: "Ver comprobante" (con su diseño) y "Ver mi cuenta corriente" llevan su
     * token, sumado con & al query que ya traían.
     *
     * @test
     */
    public function el_mail_de_venta_lleva_tokens()
    {
        Mail::fake();

        $this->assertNotNull(DB::table('pdf_column_options')->orderBy('id')->first(), 'El link del comprobante sale solo si hay opciones de PDF.');

        $cc = $this->cuenta_corriente_de($this->dueno->id);

        $venta_id = $this->venta_de($this->dueno->id);
        DB::table('sales')->where('id', $venta_id)->update(['client_id' => $cc['client_id'], 'send_mail' => 1]);

        ComercioCityMailHelper::new_sale(Sale::find($venta_id), false, true);

        $links = null;

        Mail::assertQueued(ComercioCityMail::class, function ($mail) use (&$links) {
            $links = $mail->payload->links;
            return true;
        });

        $this->assertCount(2, $links);

        $this->assertStringContainsString('/sale/pdf/' . $venta_id . '?pdf_column_profile_id=', $links[0]['url']);
        $this->assertStringContainsString('&t=', $links[0]['url']);
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta_id, $this->token_de($links[0]['url'])));

        $this->assertStringContainsString('/current-acount/pdf/' . $cc['credit_account_id'] . '/30/simple?t=', $links[1]['url']);
        $this->assertTrue(PdfLinkHelper::token_valido('credit_account', $cc['credit_account_id'], $this->token_de($links[1]['url'])));
    }

    /**
     * El asistente: el link de la venta y el del presupuesto llevan su token, después de chequear
     * la pertenencia como siempre.
     *
     * @test
     */
    public function el_link_del_asistente_lleva_el_token()
    {
        DB::table('users')->where('id', $this->dueno->id)->update(['api_url' => 'https://api-p53.comerciocity.com']);

        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        $venta_id = $this->venta_de($this->dueno->id);
        $numero_venta = DB::table('sales')->where('id', $venta_id)->value('num');

        $respuesta = LinkDePdfIaHelper::link($this->dueno->id, $conversation, 'venta', $numero_venta);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('/sale/pdf/' . $venta_id . '?t=', $respuesta['link']);
        $this->assertTrue(PdfLinkHelper::token_valido('sale', $venta_id, $this->token_de($respuesta['link'])));

        $presupuesto_id = $this->presupuesto_de($this->dueno->id);
        $numero_presupuesto = DB::table('budgets')->where('id', $presupuesto_id)->value('num');

        $respuesta = LinkDePdfIaHelper::link($this->dueno->id, $conversation, 'presupuesto', $numero_presupuesto);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertStringContainsString('/budget/pdf/' . $presupuesto_id . '/1/0', $respuesta['link']);
        $this->assertTrue(PdfLinkHelper::token_valido('budget', $presupuesto_id, $this->token_de($respuesta['link'])));
    }
}
