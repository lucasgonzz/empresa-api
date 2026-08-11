<?php

namespace Tests\Feature\ImagenesGoogle;

use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests del header `Referer` en las llamadas server-to-server a Google Custom Search
 * (grupo 356, prompt 01). Estrena la suite `imagenes-google`.
 *
 * Protege algo que falla en SILENCIO: si el `Referer` se cae, Google responde "Requests from
 * referer <empty> are blocked", el job sigue corriendo y solo loguea que no encontro imagenes.
 * Nadie relaciona eso con un header.
 *
 * El caso mas importante es `la_descarga_de_la_imagen_no_lleva_referer`: sin ese test, alguien
 * "simplifica" el arreglo moviendo el header adentro de `google_http()` y rompe la descarga de
 * imagenes de sitios de terceros (anti-hotlinking) con el resto de la suite en verde.
 *
 * No toca la base: son llamadas HTTP falseadas con Http::fake(), sin request real a la red.
 */
class Referer_en_llamadas_a_google_Test extends TestCase
{
    /**
     * Objeto minimo que usa el trait, con los dos metodos protected expuestos. Mismo molde que
     * tests/Unit/GoogleSearchHelpersTest.php (clase anonima con el trait), para no inventar otra
     * forma de testear metodos protected.
     *
     * @return object
     */
    protected function helper()
    {
        return new class {
            use GoogleSearchHelpers;

            public function buscar_en_google()
            {
                // Igual que el job: el User-Agent se encadena despues del cliente de la API.
                return $this->google_api_http()
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://www.googleapis.com/customsearch/v1', ['q' => 'martillo']);
            }

            public function descargar_imagen()
            {
                return $this->google_http()->get('https://sitio-de-terceros.test/imagen.jpg');
            }

            public function referer()
            {
                return $this->google_api_referer();
            }
        };
    }

    /**
     * Deja el escenario listo: APP_URL, fallback configurado y todas las requests falseadas
     * (googleapis Y el host de la descarga, para que ningun test toque la red).
     *
     * @param string $app_url
     * @param string $referer_configurado
     * @return void
     */
    protected function escenario($app_url, $referer_configurado = '')
    {
        config(['app.APP_URL' => $app_url]);
        config(['app.url' => $app_url]);
        config(['services.google_custom_search.referer' => $referer_configurado]);

        Http::fake([
            'www.googleapis.com/*' => Http::response(['items' => []], 200),
            '*'                    => Http::response('imagen-binaria', 200),
        ]);
    }

    /**
     * Devuelve el valor del header `Referer` de la ultima request al host indicado, o null si esa
     * request no lo llevaba.
     *
     * @param string $fragmento_url Parte de la URL que identifica la request.
     * @return string|null
     */
    protected function referer_enviado_a($fragmento_url)
    {
        $encontrado = null;

        Http::assertSent(function ($request) use ($fragmento_url, &$encontrado) {
            if (strpos($request->url(), $fragmento_url) !== false && $request->hasHeader('Referer')) {
                $encontrado = $request->header('Referer')[0];
            }

            return true;
        });

        return $encontrado;
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function con_app_url_de_subdominio_manda_ese_host()
    {
        $this->escenario('https://api-ferreteria.comerciocity.com');

        $this->helper()->buscar_en_google();

        $this->assertEquals(
            'https://api-ferreteria.comerciocity.com/',
            $this->referer_enviado_a('googleapis.com')
        );
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function con_dominio_ajeno_y_sin_fallback_configurado_manda_el_default()
    {
        $this->escenario('https://api.otrodominio.com');

        $this->helper()->buscar_en_google();

        $this->assertEquals('https://www.comerciocity.com/', $this->referer_enviado_a('googleapis.com'));
    }

    /**
     * Hosting compartido: el cliente se sirve desde el dominio raiz con subcarpeta. El host es
     * `comerciocity.com` pelado y NO matchea el sufijo `.comerciocity.com`.
     *
     * Sin este test alguien "simplifica" la condicion dejando solo el sufijo y todos los clientes
     * servidos desde subcarpeta vuelven a fallar, con el resto de la suite en verde.
     *
     * @group imagenes-google
     * @test
     */
    public function con_dominio_raiz_y_subcarpeta_manda_el_dominio_raiz_y_no_el_fallback()
    {
        $this->escenario('https://comerciocity.com/grupolimp/api');

        $this->helper()->buscar_en_google();

        $this->assertEquals('https://comerciocity.com/', $this->referer_enviado_a('googleapis.com'));
    }

    /**
     * `APP_URL` cargada sin esquema: parse_url no reconoce host y mete todo en path, asi que el
     * host sale del primer segmento.
     *
     * @group imagenes-google
     * @test
     */
    public function con_app_url_sin_esquema_resuelve_igual()
    {
        $this->escenario('comerciocity.com/mi-cliente');

        $this->helper()->buscar_en_google();

        $this->assertEquals('https://comerciocity.com/', $this->referer_enviado_a('googleapis.com'));
    }

    /**
     * El valor configurado gana sobre el default, y se normaliza con barra final: los patrones de
     * referrer de Google se comparan contra una URL, no contra un host pelado. Se carga a proposito
     * SIN barra final, que es el error de tipeo esperable.
     *
     * @group imagenes-google
     * @test
     */
    public function el_fallback_configurado_gana_y_se_normaliza_con_barra_final()
    {
        $this->escenario('https://api.otrodominio.com', 'https://www.otrodominio.com');

        $this->helper()->buscar_en_google();

        $this->assertEquals('https://www.otrodominio.com/', $this->referer_enviado_a('googleapis.com'));
    }

    /**
     * 🔴 El test que mas importa del grupo: el `Referer` va SOLO en la llamada a la API. La descarga
     * de la imagen le pega a un sitio de terceros cualquiera, y muchos bloquean por anti-hotlinking
     * cuando el `Referer` es de otro dominio. Si alguien mueve el header adentro de `google_http()`,
     * este test se pone rojo; sin el, se arregla la busqueda y se rompe la descarga en silencio.
     *
     * @group imagenes-google
     * @test
     */
    public function la_descarga_de_la_imagen_no_lleva_referer()
    {
        $this->escenario('https://api-ferreteria.comerciocity.com');

        $this->helper()->descargar_imagen();

        $this->assertNull($this->referer_enviado_a('sitio-de-terceros.test'));
    }

    /**
     * Un host que solo CONTIENE `comerciocity.com` sin ser subdominio no matchea: la comparacion es
     * de sufijo, no de "contiene".
     *
     * @group imagenes-google
     * @test
     */
    public function un_host_que_solo_contiene_el_dominio_no_matchea()
    {
        $this->escenario('https://comerciocity.com.atacante.net');

        $this->helper()->buscar_en_google();

        $this->assertEquals('https://www.comerciocity.com/', $this->referer_enviado_a('googleapis.com'));
    }
}
