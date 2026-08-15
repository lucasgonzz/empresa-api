<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Jobs\GenerarResumenSugerenciaOfertaJob;
use App\Jobs\GenerateOfferSuggestionChunksJob;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Client;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 5: 🔴 EL RECORTE DE LA DECISIÓN DE LA IA, el
 * mínimo obligatorio nº 1 de la misión.
 *
 * Todo el diseño se apoya en una sola garantía: el techo es DETERMINISTA y sale del margen del
 * artículo, así que ningún número que devuelva la IA puede dejar una oferta por debajo del costo.
 * Acá se le manda 999, se le manda -40, se le manda "mucho" y se le manda un tramo de 80%, y en
 * todos los casos lo que queda guardado está adentro del rango. 🔴 Ningún número esperado se
 * hardcodea: sale del techo y del piso que calculó el motor para esa línea.
 */
class Recorte_de_la_ia_Test extends TestCase
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
        $this->comercio = User::find(500);
        if (is_null($this->comercio)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        config(['app.USER_ID' => $this->comercio->id]);
    }

    protected function tearDown(): void
    {
        ArticlePricesHelper::$sale_taxes_cache = [];
        parent::tearDown();
    }

    /**
     * Corrida ya terminada con una línea por artículo. $cantidad decide el tipo de descuento: con
     * promedio de venta > 1 el motor propone 'cantidad' (y sus tramos), si no 'unidad'.
     * @return OfferSuggestion
     */
    protected function corrida_terminada($cantidad = 1)
    {
        $cliente = Client::create(['name' => 'Cliente recorte-' . uniqid(), 'user_id' => $this->comercio->id]);
        $article = Article::create([
            'name' => 'zz-recorte-' . uniqid(), 'user_id' => $this->comercio->id,
            'cost' => 1000, 'percentage_gain' => 20, 'aplicar_iva' => 1, 'iva_id' => self::IVA_21,
        ]);
        ArticleHelper::setFinalPrice($article, null, $this->comercio, null, true);
        foreach ([10, 30] as $dias) {
            $fecha = now()->subDays($dias);
            $sale  = Sale::create(['user_id' => $this->comercio->id, 'client_id' => $cliente->id, 'created_at' => $fecha]);
            ArticlePurchase::create(['sale_id' => $sale->id, 'client_id' => $cliente->id,
                'article_id' => $article->id, 'amount' => $cantidad, 'created_at' => $fecha]);
        }

        $suggestion = OfferSuggestion::create([
            'user_id' => $this->comercio->id, 'status' => 'pendiente', 'origen_generacion' => 'manual',
            'dias_historial_afinidad' => 180, 'dias_inactividad_reactivacion' => 60,
            'dias_carrito_abandonado' => 7, 'max_ofertas_por_cliente' => 3, 'dias_vigencia_sugerida' => 15,
        ]);

        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();

        return $suggestion->fresh();
    }

    /** La línea del artículo recién creado (la del cliente del escenario). */
    protected function linea_del_escenario($suggestion, $tipo = null)
    {
        $q = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id);
        if (!is_null($tipo)) {
            $q->where('tipo_descuento', $tipo);
        }
        $linea = $q->orderBy('id', 'DESC')->first();
        if (is_null($linea)) {
            $this->markTestSkipped('La corrida no produjo ninguna línea del tipo esperado en esta base.');
        }

        return $linea;
    }

    /**
     * Corre el job del resumen con la respuesta que se le indica. $lineas son las decisiones que la
     * IA "devuelve"; $texto_crudo permite mandar algo que ni siquiera es JSON.
     */
    protected function correr_el_job($suggestion, array $lineas = [], $texto_crudo = null)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        $texto = is_null($texto_crudo)
            ? json_encode(['resumen' => 'Aprovechá estas ofertas.', 'lineas' => $lineas])
            : $texto_crudo;
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage'   => ['input_tokens' => 300, 'output_tokens' => 60],
        ], 200)]);
        (new GenerarResumenSugerenciaOfertaJob($suggestion->id, false))->handle();
    }

    /**
     * 🔴 EL TEST MÁS IMPORTANTE DE LA MISIÓN: la IA devuelve 999 y la línea queda EXACTAMENTE en su
     * techo. Si alguien saca el recorte —o lo cambia por "confiar en el prompt"— acá se rompe.
     * @group motor-de-ofertas
     * @test
     */
    public function un_porcentaje_absurdo_de_la_ia_queda_exactamente_en_el_techo()
    {
        $suggestion = $this->corrida_terminada();
        $linea      = $this->linea_del_escenario($suggestion, 'unidad');
        $this->correr_el_job($suggestion, [['id' => $linea->id, 'porcentaje' => 999, 'dias_vigencia' => 10, 'motivo' => 'todo']]);

        $linea->refresh();
        $this->assertEquals((float) $linea->porcentaje_techo, (float) $linea->porcentaje_sugerido);
        $this->assertEquals('todo', $linea->motivo_ia);
        $this->assertEquals('listo', $suggestion->fresh()->resumen_ia_estado);
    }

    /**
     * Y para el otro lado: por debajo del piso queda en el piso. Un 2% no mueve a nadie.
     * @group motor-de-ofertas
     * @test
     */
    public function un_porcentaje_negativo_queda_en_el_piso()
    {
        $suggestion = $this->corrida_terminada();
        $linea      = $this->linea_del_escenario($suggestion, 'unidad');
        $this->correr_el_job($suggestion, [['id' => $linea->id, 'porcentaje' => -40]]);

        $linea->refresh();
        $this->assertEquals((float) $linea->porcentaje_piso, (float) $linea->porcentaje_sugerido);
    }

    /**
     * Un valor no numérico cae al DETERMINISTA que ya tenía la línea: nunca queda sin número.
     * @group motor-de-ofertas
     * @test
     */
    public function un_porcentaje_no_numerico_cae_al_determinista()
    {
        $suggestion  = $this->corrida_terminada();
        $linea       = $this->linea_del_escenario($suggestion, 'unidad');
        $determinista = (float) $linea->porcentaje_sugerido;
        $this->correr_el_job($suggestion, [['id' => $linea->id, 'porcentaje' => 'mucho']]);

        $this->assertEquals($determinista, (float) $linea->fresh()->porcentaje_sugerido);
    }

    /**
     * La vigencia recibe el mismo tratamiento: se recorta a [3, dias_vigencia_sugerida * 2].
     * @group motor-de-ofertas
     * @test
     */
    public function una_vigencia_absurda_se_recorta_al_doble_de_la_de_la_corrida()
    {
        $suggestion = $this->corrida_terminada();
        $linea      = $this->linea_del_escenario($suggestion, 'unidad');
        $this->correr_el_job($suggestion, [['id' => $linea->id, 'porcentaje' => 10, 'dias_vigencia' => 9999]]);

        $tope = (int) $suggestion->dias_vigencia_sugerida * 2;
        $this->assertEquals(now()->addDays($tope)->toDateString(), $linea->fresh()->fecha_vencimiento_sugerida);
    }

    /**
     * 🔴 Los tramos de una oferta 'cantidad' pasan por EL MISMO recorte, uno por uno: ahí el número
     * que se le cobra al cliente está en los tramos y no en la columna, así que recortar solo la
     * columna dejaría el agujero abierto.
     * @group motor-de-ofertas
     * @test
     */
    public function todos_los_tramos_por_cantidad_pasan_por_el_recorte()
    {
        $suggestion = $this->corrida_terminada(5);
        $linea      = $this->linea_del_escenario($suggestion, 'cantidad');
        $techo      = (float) $linea->porcentaje_techo;
        $this->correr_el_job($suggestion, [[
            'id' => $linea->id, 'porcentaje' => 12,
            'tramos' => [
                ['min' => 1, 'max' => 4, 'porcentaje' => 8],
                ['min' => 5, 'max' => 9, 'porcentaje' => 80],
                ['min' => 10, 'max' => 50, 'porcentaje' => 999],
            ],
        ]]);
        $tramos = json_decode($linea->fresh()->tramos_sugeridos, true);
        $this->assertCount(3, $tramos);
        foreach ($tramos as $tramo) {
            $this->assertLessThanOrEqual($techo, (float) $tramo['porcentaje'], 'ningún tramo puede pasar el techo');
            $this->assertGreaterThanOrEqual((float) $linea->porcentaje_piso, (float) $tramo['porcentaje']);
        }

        $this->assertEquals($techo, (float) $tramos[1]['porcentaje'], 'el tramo de 80% queda clavado en el techo');
        $this->assertNull($tramos[2]['max'], 'el último tramo nunca tiene techo de cantidad');
    }

    /**
     * Un JSON roto es una falla del RESUMEN, no de la corrida: la tabla sigue 'terminado' con los
     * porcentajes deterministas y el resumen queda en 'error' con el detalle.
     * @group motor-de-ofertas
     * @test
     */
    public function un_json_roto_deja_la_corrida_terminada_con_los_deterministas()
    {
        $suggestion  = $this->corrida_terminada();
        $linea       = $this->linea_del_escenario($suggestion, 'unidad');
        $determinista = (float) $linea->porcentaje_sugerido;

        $this->correr_el_job($suggestion, [], 'Che, te paso el resumen: conviene ofrecerle el taladro.');

        $suggestion->refresh();
        $this->assertEquals('terminado', $suggestion->status, 'una falla del resumen no voltea la corrida');
        $this->assertEquals('error', $suggestion->resumen_ia_estado);
        $this->assertNotEmpty($suggestion->resumen_ia_error);
        $this->assertEquals($determinista, (float) $linea->fresh()->porcentaje_sugerido);
    }

    /**
     * 🔴 El id que devuelve la IA es texto que vino de afuera: la actualización va scopeada a la
     * corrida, así que una alucinación con el id de una línea de otra corrida no la toca.
     * @group motor-de-ofertas
     * @test
     */
    public function una_linea_de_otra_corrida_no_se_toca_aunque_la_ia_mande_su_id()
    {
        $ajena  = $this->corrida_terminada();
        $linea_ajena = $this->linea_del_escenario($ajena, 'unidad');
        $antes  = (float) $linea_ajena->porcentaje_sugerido;

        $suggestion = $this->corrida_terminada();
        $this->correr_el_job($suggestion, [['id' => $linea_ajena->id, 'porcentaje' => 999]]);

        $this->assertEquals($antes, (float) $linea_ajena->fresh()->porcentaje_sugerido);
    }

    /**
     * 🔴 El invariante final, afirmado sobre TODAS las líneas de la corrida y no de a una: después
     * de que la IA opinó, ninguna quedó por encima de su techo.
     * @group motor-de-ofertas
     * @test
     */
    public function ninguna_linea_de_la_corrida_supera_su_techo_despues_de_la_ia()
    {
        $suggestion = $this->corrida_terminada();

        $decisiones = [];
        foreach (OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)->get() as $linea) {
            $decisiones[] = ['id' => $linea->id, 'porcentaje' => 999, 'dias_vigencia' => 9999];
        }
        $this->correr_el_job($suggestion, $decisiones);

        $lineas = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)->get();
        $this->assertGreaterThan(0, $lineas->count());
        foreach ($lineas as $linea) {
            $this->assertLessThanOrEqual((float) $linea->porcentaje_techo, (float) $linea->porcentaje_sugerido,
                'la línea ' . $linea->id . ' superó su techo');
        }
    }
}
