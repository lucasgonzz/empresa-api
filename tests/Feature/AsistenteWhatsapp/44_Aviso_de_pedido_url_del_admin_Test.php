<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\RecordatoriosDelAsistenteHelper;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026) — TESTS DE ACEPTACIÓN, parte 5 (ronda 2): la URL del admin.
 *
 * Hay clientes con ADMIN_API_URL cargada CON el `/api` del final y otros sin él (con o sin barra final). El empuje
 * inmediato tiene que pegarle SIEMPRE a `{base sin /api}/api/inbound/asistente/pedido-nuevo`: con `…/api` el
 * resultado `…/api/api/…` daba 404 y el aviso salía recién por la consulta de respaldo de 5 minutos.
 *
 * Sin red: Http::fake con un comodín que contesta 599. PHP 7.4: sin match, sin ?->, sin str_contains.
 */
class Aviso_de_pedido_url_del_admin_Test extends RecordatoriosTestCase
{
    /** La URL a la que tiene que llegar el empuje, sea cual sea la forma de ADMIN_API_URL. */
    const URL_ESPERADA = 'https://admin.test/api/inbound/asistente/pedido-nuevo';

    /**
     * Prepara un pedido recién creado, el canal conectado y la ADMIN_API_URL dada, empuja y devuelve las URL a las
     * que salió algún pedido HTTP.
     *
     * @param  string  $admin_api_url  Valor de `services.admin_api.url`.
     * @return array<int,string>
     */
    protected function urls_del_empuje($admin_api_url)
    {
        $comprador = Buyer::create(['name' => 'Gabriel', 'surname' => 'Domínguez', 'email' => 'url-' . uniqid() . '@testing.local', 'user_id' => $this->comercio->id]);
        OnlineConfiguration::create(['user_id' => $this->comercio->id]);
        RecordatoriosDelAsistenteHelper::anunciar($this->comercio->id, '1');

        config(['services.admin_api.url' => $admin_api_url, 'services.admin_api.inbound_key' => 'clave-saliente']);

        Http::fake(['*' => Http::response(['encolado' => true], 202)]);
        Log::spy();

        Carbon::setTestNow(Carbon::parse('2026-10-16 10:25:00'));
        $estado = OrderStatus::first();
        $pedido = Order::create([
            'num' => 120, 'status' => 'unconfirmed', 'deliver' => 0, 'buyer_id' => $comprador->id,
            'order_status_id' => is_null($estado) ? 1 : $estado->id, 'user_id' => $this->comercio->id, 'total' => 0,
        ]);
        $articulo = Article::create(['name' => 'Malbec Reserva', 'user_id' => $this->comercio->id, 'status' => 'active']);
        $pedido->articles()->attach($articulo->id, ['price' => 94000, 'amount' => 2]);
        Carbon::setTestNow(Carbon::parse(self::AHORA));

        $respuesta = $this->postJson('api/tienda/pedidos/' . $pedido->id . '/aviso-al-asistente');

        $respuesta->assertStatus(202);
        $this->assertSame('se_avisa_al_admin', $respuesta->json('motivo'));

        $urls = [];

        foreach (Http::recorded()->all() as $par) {
            $urls[] = $par[0]->url();
        }

        return $urls;
    }

    /**
     * 🔴 Sin `/api` y sin barra: la forma de siempre.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_api_y_sin_barra()
    {
        $this->assertSame([self::URL_ESPERADA], $this->urls_del_empuje('https://admin.test'));
    }

    /**
     * 🔴 Sin `/api` y con barra final.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function sin_api_y_con_barra()
    {
        $this->assertSame([self::URL_ESPERADA], $this->urls_del_empuje('https://admin.test/'));
    }

    /**
     * 🔴 Con `/api` y sin barra final: no puede quedar `/api/api/`.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_api_y_sin_barra()
    {
        $this->assertSame([self::URL_ESPERADA], $this->urls_del_empuje('https://admin.test/api'));
    }

    /**
     * 🔴 Con `/api` y con barra final.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function con_api_y_con_barra()
    {
        $this->assertSame([self::URL_ESPERADA], $this->urls_del_empuje('https://admin.test/api/'));
    }
}
