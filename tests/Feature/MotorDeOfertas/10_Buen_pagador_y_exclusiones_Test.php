<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Jobs\GenerateOfferSuggestionChunksJob;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\OfferSuggestion;
use App\Models\Sale;
use App\Models\User;
use App\Services\OfertasClientes\OfertaSugeridaService;
use App\Services\OfertasClientes\PorcentajeSugeridoService;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use App\Services\OfertasClientes\TechoDeDescuentoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 10: las dos mitades del motor que estaban escritas
 * pero no hacían nada.
 *
 * 1. EL BUEN PAGADOR COMO MODIFICADOR. Lucas pidió "al que paga bien se le habilita más descuento".
 *    `es_buen_pagador` se calculaba, se guardaba en la línea y se le mostraba a la IA, pero no
 *    entraba ni en el score, ni en el techo, ni en el porcentaje: dos clientes idénticos —uno que
 *    paga al día y otro sin datos— recibían exactamente el mismo descuento. Acá se prueba que ahora
 *    sí lo mueve, 🔴 y que el invariante que hace que eso sea seguro sigue en pie: el descuento
 *    NUNCA supera el techo, porque el premio se mueve adentro del rango y el techo no se toca.
 *
 * 2. LOS MOTIVOS DE EXCLUSIÓN. `TechoDeDescuentoService::evaluar()` devuelve por qué descartó cada
 *    línea y el pipeline lo tiraba a la basura en un `continue`: un artículo sin costo cargado
 *    desaparecía sin que nada lo dijera, y medio evaluar() era camino muerto. Acá se prueba que se
 *    cuenta, que se guarda en la corrida, que llega a la vista ya explicado y que llega al bloque de
 *    datos de la IA.
 */
class Buen_pagador_y_exclusiones_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // 🔴 Sin esto el pipeline sale a la API real de Anthropic: la clave vive en el .env.testing.
        config(['services.anthropic.api_key' => null]);
        // El usuario 500 es el de .env.testing: el único con la config fiscal sembrada que hace que
        // la cadena de precios devuelva un precio real (sin precio no hay techo y no hay línea).
        $this->comercio = User::find(500);

        if (is_null($this->comercio)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        config(['app.USER_ID' => $this->comercio->id]);
    }

    protected function tearDown(): void
    {
        // El objeto en memoria y la cache estática del helper no participan de la transacción.
        ArticlePricesHelper::$sale_taxes_cache = [];
        parent::tearDown();
    }

    /**
     * Artículo pasado por el camino REAL del sistema, para que costo_real y final_price queden
     * persistidos como en producción. Ningún precio se hardcodea: los esperados salen del techo que
     * calculó el propio motor.
     *
     * @return Article
     */
    protected function articulo($nombre, $percentage_gain = 100)
    {
        $article = Article::create([
            'name' => $nombre . '-' . uniqid(), 'user_id' => $this->comercio->id,
            'cost' => 1000, 'percentage_gain' => $percentage_gain,
            'aplicar_iva' => 1, 'iva_id' => self::IVA_21,
        ]);
        ArticleHelper::setFinalPrice($article, null, $this->comercio, null, true);

        return Article::find($article->id);
    }

    /**
     * 🔴 Con buyer vinculado: el punto A exige buyer en afinidad(), y las_exclusiones_... /
     * una_corrida_sin_exclusiones_... corren el job entero (pasan por los criterios). El bonus del
     * buen pagador arma sus candidatos a mano y no pasa por acá, así que no le afecta.
     *
     * @return Client
     */
    protected function cliente($nombre)
    {
        $cliente = Client::create(['name' => $nombre . '-' . uniqid(), 'user_id' => $this->comercio->id]);
        Buyer::create(['name' => 'Comprador ' . uniqid(), 'email' => 'buyer-' . uniqid() . '@test.local',
            'user_id' => $this->comercio->id, 'comercio_city_client_id' => $cliente->id]);

        return $cliente;
    }

    /** Una compra real: la venta y su línea de article_purchases, con la misma fecha. */
    protected function comprar($client, $article, $dias_atras)
    {
        $fecha = now()->subDays($dias_atras);
        $sale  = Sale::create(['user_id' => $this->comercio->id, 'client_id' => $client->id, 'created_at' => $fecha]);
        ArticlePurchase::create(['sale_id' => $sale->id, 'client_id' => $client->id,
            'article_id' => $article->id, 'amount' => 1, 'created_at' => $fecha]);
    }

    /** @return OfferSuggestion */
    protected function corrida(array $overrides = [])
    {
        return OfferSuggestion::create(array_merge([
            'user_id' => $this->comercio->id, 'status' => 'pendiente', 'origen_generacion' => 'manual',
            'dias_historial_afinidad' => 180, 'dias_inactividad_reactivacion' => 60,
            'dias_carrito_abandonado' => 7, 'max_ofertas_por_cliente' => 3, 'dias_vigencia_sugerida' => 15,
        ], $overrides));
    }

    /**
     * 🔴 LA ARITMÉTICA DEL PREMIO, EXACTA, Y EL INVARIANTE EN SUS DOS BORDES.
     *
     * Con piso 5, techo 25 y vendibilidad 0,4 el determinista es floor(5 + 0,4 × 20) = 13. El buen
     * pagador se lleva un cuarto de lo que le falta para el techo: floor(13 + (25 − 13) × 0,25) = 16.
     * Los números están escritos a mano a propósito: si alguien cambia la fórmula, el test lo dice.
     *
     * Y los dos bordes que prueban que el techo es intocable: con el determinista YA pegado al techo
     * el premio no puede empujarlo ni un punto, y con el rango degenerado (techo == piso) tampoco.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_buen_pagador_se_acerca_al_techo_y_no_puede_pasarlo_nunca()
    {
        $servicio = new PorcentajeSugeridoService($this->comercio->id);

        $this->assertSame(13, $servicio->porcentaje(5, 25, 0.4), 'el determinista de siempre no cambia');
        $this->assertSame(16, $servicio->porcentaje(5, 25, 0.4, true), 'el que paga al día se acerca al techo');

        // 🔴 null es "sin datos de cuenta corriente" y NO se premia: no saber no es un buen
        // antecedente. false (mal pagador) ni siquiera llega hasta acá — se excluye entero antes —,
        // pero si llegara tampoco cobraría.
        $this->assertSame(13, $servicio->porcentaje(5, 25, 0.4, null));
        $this->assertSame(13, $servicio->porcentaje(5, 25, 0.4, false));

        // Borde 1: el determinista ya está en el techo. El premio suma cero, no 25% de nada.
        $this->assertSame(25, $servicio->porcentaje(5, 25, 1.0, true));

        // Borde 2: rango degenerado. Sigue devolviendo el techo y no lo pasa.
        $this->assertSame(5, $servicio->porcentaje(5, 5, 1.0, true));

        /*
         * 🔴 EL INVARIANTE, BARRIDO: para cualquier rango y cualquier vendibilidad, el porcentaje del
         * buen pagador queda entre el piso y el techo. Es lo único que hace que "al que paga bien se
         * le habilita más descuento" pueda convivir con "el descuento nunca supera el margen".
         */
        foreach ([[5, 6], [5, 25], [10, 60], [5, 5], [40, 41]] as $rango) {
            foreach ([0.0, 0.15, 0.5, 0.87, 1.0] as $vendibilidad) {
                $premiado = $servicio->porcentaje($rango[0], $rango[1], $vendibilidad, true);

                $this->assertLessThanOrEqual($rango[1], $premiado,
                    'el premio del buen pagador NUNCA puede pasar el techo (rango ' . $rango[0] . '-' . $rango[1] . ')');
                $this->assertGreaterThanOrEqual($rango[0], $premiado);
                $this->assertGreaterThanOrEqual(
                    $servicio->porcentaje($rango[0], $rango[1], $vendibilidad),
                    $premiado,
                    'y nunca puede dar MENOS que el determinista: es un premio, no un castigo'
                );
            }
        }
    }

    /**
     * El pedido de Lucas, por el camino real del motor: dos clientes, el MISMO artículo, y el que
     * paga al día recibe más descuento — pero sigue por debajo del techo que le fija el margen.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function dos_clientes_iguales_con_el_mismo_articulo_reciben_distinto_descuento_segun_como_pagan()
    {
        $al_dia    = $this->cliente('zz-paga-al-dia');
        $sin_datos = $this->cliente('zz-sin-datos-de-cuenta');
        $article   = $this->articulo('zz-articulo-del-buen-pagador');

        // Ventas recientes para que la vendibilidad no quede en 1 (artículo del todo parado): con el
        // determinista pegado al techo no queda lugar para el premio y el test no probaría nada.
        // La precondición de abajo lo verifica en vez de darlo por hecho.
        $this->comprar($al_dia, $article, 1);
        $this->comprar($sin_datos, $article, 2);

        $servicio   = new OfertaSugeridaService($this->corrida(), $this->comercio);
        $candidatos = [];

        foreach ([$al_dia, $sin_datos] as $cliente) {
            $candidatos[] = [
                'client_id' => $cliente->id, 'article_id' => $article->id,
                'criterio' => 'afinidad', 'score' => 1.0, 'compras' => 1, 'detalle' => 'Compró este artículo',
            ];
        }

        $lineas = $servicio->calcular_para_candidatos($candidatos, [
            $al_dia->id    => true,
            $sin_datos->id => null,
        ]);

        $this->assertCount(2, $lineas, 'los dos clientes tienen que dejar línea: el artículo es el mismo');

        $por_cliente = [];

        foreach ($lineas as $linea) {
            $por_cliente[$linea['client_id']] = $linea;
        }

        $techo    = (int) $por_cliente[$al_dia->id]['porcentaje_techo'];
        $premiado = (int) $por_cliente[$al_dia->id]['porcentaje_sugerido'];
        $normal   = (int) $por_cliente[$sin_datos->id]['porcentaje_sugerido'];

        // El techo es el mismo para los dos: 🔴 el crédito NO lo toca, ese es todo el punto.
        $this->assertSame($techo, (int) $por_cliente[$sin_datos->id]['porcentaje_techo'],
            'el techo sale del margen del artículo y no del crédito del cliente');

        // Precondición explícita: si el determinista ya estuviera en el techo no habría lugar para el
        // premio y las aserciones de abajo pasarían por el motivo equivocado.
        $this->assertLessThan($techo, $normal, 'precondición: tiene que sobrar rango entre el determinista y el techo');

        $this->assertGreaterThan($normal, $premiado, 'al que paga al día se le habilita más descuento');
        $this->assertLessThanOrEqual($techo, $premiado, '🔴 y aun así jamás supera el techo del margen');

        // Y la señal queda persistida tal cual en la línea, sin colapsar null contra false.
        $this->assertTrue($por_cliente[$al_dia->id]['es_buen_pagador']);
        $this->assertNull($por_cliente[$sin_datos->id]['es_buen_pagador']);
    }

    /**
     * 🔴 "No encontré nada" contra "miré 800 artículos y 200 no tienen el costo cargado". El motivo
     * de exclusión que evaluar() calcula se cuenta, se guarda en la corrida, llega a la vista ya
     * explicado y llega al bloque de datos de la IA.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function las_exclusiones_se_cuentan_por_motivo_y_llegan_a_la_corrida_a_la_vista_y_a_la_ia()
    {
        $cliente = $this->cliente('zz-cliente-de-exclusiones');
        $bueno   = $this->articulo('zz-articulo-con-costo');

        // Sin costo_real: TechoDeDescuentoService lo excluye por EXCLUIDO_SIN_COSTO. Se crea sin
        // pasar por setFinalPrice justamente para que costo_real quede sin cargar, que es el estado
        // real de un catálogo importado a medias.
        $sin_costo = Article::create([
            'name' => 'zz-articulo-sin-costo-' . uniqid(), 'user_id' => $this->comercio->id,
        ]);

        $this->comprar($cliente, $bueno, 5);
        $this->comprar($cliente, $sin_costo, 5);

        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertSame('terminado', $suggestion->status);

        $exclusiones = $suggestion->exclusiones_por_motivo;

        $this->assertIsArray($exclusiones, 'el desglose se guarda como array (la columna tiene cast)');
        $this->assertArrayHasKey(TechoDeDescuentoService::EXCLUIDO_SIN_COSTO, $exclusiones,
            'el artículo sin costo tiene que quedar contado por su motivo, no desaparecer en silencio');
        $this->assertSame(1, (int) $exclusiones[TechoDeDescuentoService::EXCLUIDO_SIN_COSTO]);

        // La corrida igual dio resultado: excluir no es fallar.
        $this->assertGreaterThanOrEqual(1, (int) $suggestion->total_lineas);

        /*
         * 🔴 Llega a la vista YA EXPLICADO, y se comprueba sobre el JSON SERIALIZADO y no sobre el
         * accessor: el accessor anda igual sin $appends, así que un test que lea
         * $suggestion->exclusiones_explicadas pasa en verde mientras la SPA no recibe nada. Esto es
         * literalmente lo que arma response()->json(['model' => ...]) en el controller.
         */
        $serializado = json_decode(json_encode($suggestion), true);
        $this->assertArrayHasKey('exclusiones_explicadas', $serializado,
            'sin $appends el desglose no viaja en la respuesta del endpoint y la pantalla no lo puede mostrar');
        $this->assertSame(
            TechoDeDescuentoService::EXCLUIDO_SIN_COSTO,
            $serializado['exclusiones_explicadas'][0]['motivo']
        );

        $explicadas = $suggestion->exclusiones_explicadas;
        $this->assertNotEmpty($explicadas);
        $this->assertSame(TechoDeDescuentoService::EXCLUIDO_SIN_COSTO, $explicadas[0]['motivo']);
        $this->assertSame(1, $explicadas[0]['cantidad']);
        $this->assertSame(
            OfertaSugeridaService::texto_de_exclusion(TechoDeDescuentoService::EXCLUIDO_SIN_COSTO),
            $explicadas[0]['texto'],
            'el texto del motivo vive en UN solo lugar: la vista y la IA leen el mismo'
        );

        // Y llega al bloque de datos de la IA, que es lo que le permite decir "de 800 que miré, 200
        // no tienen el costo cargado" en vez de "encontré 12 ofertas".
        $datos = (new ResumenIaOfertasService())->armar_datos($suggestion);
        $this->assertStringContainsString(
            OfertaSugeridaService::texto_de_exclusion(TechoDeDescuentoService::EXCLUIDO_SIN_COSTO),
            $datos
        );

        /*
         * 🔴 Y el desglose se RESETEA al reprocesar. Se acumula lote a lote contra la cabecera, así
         * que sin el reset del job padre un reintento sumaría las exclusiones de la corrida anterior
         * a las de esta y el número saldría al doble: el comerciante vería 400 artículos sin costo
         * donde hay 200.
         */
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertSame(
            1,
            (int) $suggestion->exclusiones_por_motivo[TechoDeDescuentoService::EXCLUIDO_SIN_COSTO],
            'reprocesar la corrida no puede duplicar el conteo de exclusiones'
        );
    }

    /**
     * Una corrida sin ninguna exclusión no inventa un bloque vacío: ni en la vista ni en el prompt.
     * Una línea de relleno del estilo "0 artículos quedaron afuera" es una línea que la IA puede
     * terminar contando en el resumen.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_corrida_sin_exclusiones_no_agrega_ningun_bloque()
    {
        $cliente = $this->cliente('zz-cliente-sin-exclusiones');
        $article = $this->articulo('zz-articulo-sano');
        $this->comprar($cliente, $article, 5);

        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertSame([], $suggestion->exclusiones_explicadas);
        $this->assertStringNotContainsString(
            'quedaron afuera porque no se les puede calcular',
            (new ResumenIaOfertasService())->armar_datos($suggestion)
        );
    }
}
