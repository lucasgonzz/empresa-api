<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 9: el chat.
 *
 * 🔴 Blinda que una tool se declara en DOS lugares del mismo archivo
 * (build_tools() y el if/elseif de execute_tool_calls()): con una sola, la IA
 * la ve en el prompt y al usarla recibe "Tool desconocida". Misma clase de
 * error que la periodicidad del archivo 8, así que el test no mira solo la tool
 * nueva: recorre TODAS y exige que cada una tenga su despacho.
 */
class Chat_y_tool_de_ofertas_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Sin esto los tests salen a la API real de Anthropic.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio ofertas chat',
            'company_name' => 'Ferreteria ofertas',
            'email'        => 'ofertas-chat-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio ofertas',
            'email'    => 'ofertas-chat-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
        $this->service = new AsistenteIaService();
    }

    /** Oferta activa lista para leer: acá se prueba la LECTURA, no la escritura
     * (eso es el archivo 6). @param User $owner @param array $o @return ClientOffer */
    protected function oferta($owner, array $overrides = [])
    {
        $client = Client::create(['name' => 'zz-cli-chat-' . uniqid(), 'user_id' => $owner->id]);
        $article = Article::create(['name' => 'zz-art-chat-' . uniqid(), 'user_id' => $owner->id]);

        return ClientOffer::create(array_merge([
            'user_id'        => $owner->id,
            'client_id'      => $client->id,
            'article_id'     => $article->id,
            'tipo_descuento' => 'unidad',
            'porcentaje'     => 15,
            'desde'          => date('Y-m-d', strtotime('-1 day')),
            'hasta'          => date('Y-m-d', strtotime('+10 days')),
            'estado'         => 'activa',
        ], $overrides));
    }

    /**
     * 🔴 EL TEST DE LAS DOS PUNTAS. Toda tool de build_tools() tiene que estar
     * despachada en execute_tool_calls(). Se lee el archivo porque el if/elseif
     * no es introspectable de otra forma.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function toda_tool_declarada_tiene_su_despacho_en_el_mismo_archivo()
    {
        $contenido = file_get_contents(app_path('Services/AsistenteIa/AsistenteIaService.php'));
        $tools = $this->service->build_tools();
        $this->assertNotEmpty($tools);

        foreach ($tools as $tool) {
            $this->assertStringContainsString(
                "\$tool_name === '" . $tool['name'] . "'",
                $contenido,
                'La tool ' . $tool['name'] . ' esta declarada en build_tools() pero no se despacha en execute_tool_calls(): la IA la llama y recibe "Tool desconocida".'
            );
        }
    }

    /**
     * La punta declarativa de la tool nueva.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function build_tools_incluye_consultar_ofertas_activas()
    {
        $this->assertContains('consultar_ofertas_activas', array_column($this->service->build_tools(), 'name'));
    }

    /**
     * La punta ejecutable: la llamada que haría Claude, resuelta contra la base.
     * Sin el elseif, el content vendría con "Tool desconocida" y is_error.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function execute_tool_calls_resuelve_la_tool_de_ofertas_activas()
    {
        $offer = $this->oferta($this->comercio);
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_ofertas_01',
            'name'  => 'consultar_ofertas_activas',
            'input' => ['busqueda' => ''],
        ]], $conversation);

        $this->assertCount(1, $resultados);
        $this->assertEquals('toolu_ofertas_01', $resultados[0]['tool_use_id']);
        $this->assertArrayNotHasKey(
            'is_error',
            $resultados[0],
            'Con el elseif faltante el tool_result viaja con is_error y content "Tool desconocida".'
        );

        $data = json_decode($resultados[0]['content'], true);
        $this->assertCount(1, $data);
        $this->assertEquals($offer->client->name, $data[0]['cliente']);
        $this->assertEquals(15.0, $data[0]['porcentaje']);
    }

    /**
     * 🔴 El scope por comercio. La tool corre desde un job SIN sesión: el único
     * filtro es el owner_id de la conversación.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function las_ofertas_de_otro_comercio_no_aparecen()
    {
        $this->oferta($this->comercio);
        $this->oferta($this->otro_comercio);

        $mias = ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, '');
        $ajenas = ConsultasSistemaIaHelper::ofertas_activas($this->otro_comercio->id, '');
        $this->assertCount(1, $mias);
        $this->assertCount(1, $ajenas);
        $this->assertNotEquals($mias[0]['cliente'], $ajenas[0]['cliente']);
    }

    /**
     * El tope acota el JSON que viaja al prompt. Se siembra uno más que
     * MAX_RESULTS para que se rompa si alguien saca el limit().
     *
     * @group motor-de-ofertas
     * @test
     */
    public function ofertas_activas_respeta_el_tope_de_max_results()
    {
        for ($i = 0; $i < ConsultasSistemaIaHelper::MAX_RESULTS + 1; $i++) {
            $this->oferta($this->comercio);
        }

        $this->assertCount(
            ConsultasSistemaIaHelper::MAX_RESULTS,
            ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, '')
        );
    }

    /**
     * 🔴 La vigencia la da la FECHA, no solo `estado`: una oferta cuyo `hasta`
     * pasó sigue 'activa' hasta que el barrido de ofertas:generar la marque
     * vencida. Filtrando solo por estado, el chat contaría ofertas muertas.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_oferta_vencida_por_fecha_no_se_informa_aunque_su_estado_siga_activa()
    {
        $this->oferta($this->comercio, [
            'desde' => date('Y-m-d', strtotime('-30 days')),
            'hasta' => date('Y-m-d', strtotime('-1 day')),
            'estado' => 'activa',
        ]);
        $this->assertCount(
            0,
            ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, ''),
            'La verdad de la vigencia la da la fecha: el barrido que pone vencida puede tardar hasta un dia.'
        );
    }

    /**
     * Una cancelada tampoco: la fila no se borra, así que sin el filtro de
     * estado reaparecería.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function una_oferta_cancelada_no_se_informa()
    {
        $this->oferta($this->comercio, ['estado' => 'cancelada']);

        $this->assertCount(0, ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, ''));
    }

    /**
     * Con 'cantidad' el porcentaje es NULL por diseño (el número vive en los
     * tramos). Tiene que viajar null y no un 0 casteado, que la IA leería como
     * "sin descuento".
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_porcentaje_de_una_oferta_por_cantidad_viaja_como_null_y_no_como_cero()
    {
        $this->oferta($this->comercio, ['tipo_descuento' => 'cantidad', 'porcentaje' => null]);

        $filas = ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, '');
        $this->assertCount(1, $filas);
        $this->assertEquals('cantidad', $filas[0]['tipo_descuento']);
        $this->assertNull($filas[0]['porcentaje'], 'Un 0 le haria decir a la IA que no hay descuento.');
    }

    /**
     * El filtro busca por cliente y por artículo, que es como pregunta el
     * comerciante ("que le estoy ofreciendo a Fulano").
     *
     * @group motor-de-ofertas
     * @test
     */
    public function la_busqueda_filtra_por_nombre_de_cliente_y_de_articulo()
    {
        $mia = $this->oferta($this->comercio);
        $this->oferta($this->comercio);

        $por_cliente = ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, $mia->client->name);
        $por_articulo = ConsultasSistemaIaHelper::ofertas_activas($this->comercio->id, $mia->article->name);
        $this->assertCount(1, $por_cliente);
        $this->assertCount(1, $por_articulo);
        $this->assertEquals($mia->article->name, $por_cliente[0]['articulo']);
    }

    /**
     * 🔴 `origen` no tiene enum ni constante: el docblock de AiConversation es
     * el único lugar donde el vocabulario está escrito. La misión de compras
     * sumó su origen y no actualizó la lista; esto cierra el olvido.
     *
     * @group motor-de-ofertas
     * @test
     */
    public function el_docblock_de_ai_conversation_documenta_todos_los_origenes()
    {
        $contenido = file_get_contents(app_path('Models/AiConversation.php'));
        foreach (['usuario', 'sugerencia_stock', 'sugerencia_compra', 'sugerencia_oferta'] as $origen) {
            $this->assertStringContainsString(
                "'" . $origen . "'",
                $contenido,
                'El origen ' . $origen . ' se usa en el codigo pero no esta documentado en el docblock de AiConversation.'
            );
        }
    }
}
