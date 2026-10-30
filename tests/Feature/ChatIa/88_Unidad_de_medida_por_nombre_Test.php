<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\UnidadDeMedidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Provider;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E2 punto 2, 29/10/2026) — la unidad de medida por el nombre que dice
 * el dueño.
 *
 * El caso real: Pablo (Ferre Total, CONV 14) pidió «cambiale la unidad de medida a kilos» y el agente
 * contestó que no existía ninguna que se llamara "kilos", con «Kilo» cargada. Lo que protege:
 *  - el resolvedor compartido (UnidadDeMedidaIaHelper) entiende plurales y abreviaturas en las dos
 *    direcciones (kilos, kg, KG., gramos, lts, metros, unidades, rollos, pares);
 *  - sin ninguna coincidencia devuelve `faltan` con TODAS las unidades, nunca un error pelado;
 *  - la actualización masiva y la edición genérica de un artículo resuelven «kilos» a «Kilo».
 *
 * 🔴 Sin red: la clave de Anthropic va en null. Las unidades son un catálogo global: se crean las que
 * siembra UnidadMedidaSeeder si la base del slot no las tiene.
 */
class Unidad_de_medida_por_nombre_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var array<string, UnidadMedida> Las del seeder, por nombre. */
    protected $unidades = [];

    /** @var Article[] */
    protected $clavos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        // Las mismas que siembra UnidadMedidaSeeder.
        foreach (['Unidad', 'Gramo', 'Kilo', 'Litro', 'Centimetro', 'Metro', 'Rollo', 'Par'] as $nombre) {
            $this->unidades[$nombre] = UnidadMedida::firstOrCreate(['name' => $nombre]);
        }

        $this->comercio = User::create([
            'name'             => 'Comercio unidades E2',
            'company_name'     => 'Ferreteria unidades E2',
            'email'            => 'unidades-e2-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $proveedor = Provider::create(['name' => 'Bulonera unidades E2', 'user_id' => $this->comercio->id]);

        foreach (['CLAVO PUNTA PARIS 2"', 'CLAVO PUNTA PARIS 2 1/2"'] as $nombre) {
            $this->clavos[] = Article::create([
                'name'             => $nombre,
                'user_id'          => $this->comercio->id,
                'status'           => 'active',
                'cost'             => 1000,
                'percentage_gain'  => 30,
                'provider_id'      => $proveedor->id,
                'unidad_medida_id' => $this->unidades['Unidad']->id,
            ]);
        }
    }

    /**
     * Conversación del dueño con el assistant pendiente que propone.
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Cambiale la unidad de medida a kilos a todos los clavos punta París',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_resolvedor_entiende_plurales_y_abreviaturas_en_las_dos_direcciones()
    {
        $casos = [
            'kilos'    => 'Kilo',
            'kg'       => 'Kilo',
            'KG.'      => 'Kilo',
            'Kilos'    => 'Kilo',
            'kilogramos' => 'Kilo',
            'gramos'   => 'Gramo',
            'grs'      => 'Gramo',
            'lts'      => 'Litro',
            'litros'   => 'Litro',
            'metros'   => 'Metro',
            'cm'       => 'Centimetro',
            'centímetros' => 'Centimetro',
            'unidades' => 'Unidad',
            'un'       => 'Unidad',
            'rollos'   => 'Rollo',
            'pares'    => 'Par',
            'Kilo'     => 'Kilo',
        ];

        foreach ($casos as $dicho => $esperada) {
            $unidad = UnidadDeMedidaIaHelper::resolver($dicho);

            $this->assertInstanceOf(UnidadMedida::class, $unidad, '"' . $dicho . '" tenía que resolverse: ' . json_encode($unidad));
            $this->assertSame((int) $this->unidades[$esperada]->id, (int) $unidad->id, '"' . $dicho . '" es ' . $esperada);
        }
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_ninguna_coincidencia_devuelve_faltan_con_todas_las_unidades()
    {
        $respuesta = UnidadDeMedidaIaHelper::resolver('toneladas');

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertNull($respuesta['error'], 'No es un error pelado: es una pregunta con opciones.');
        $this->assertCount(1, $respuesta['faltan']);

        $opciones = json_decode(json_encode($respuesta['opciones']), true);
        $nombres = array_column($opciones['unidades_de_medida'], 'nombre');

        $this->assertCount(UnidadMedida::count(), $nombres, 'Viajan TODAS las unidades del sistema.');
        $this->assertContains('Kilo', $nombres);
        $this->assertContains('Unidad', $nombres);
    }

    /**
     * El caso de Pablo: «cambiale la unidad de medida a kilos» por la actualización masiva.
     *
     * @group chat-ia
     * @test
     */
    public function la_masiva_resuelve_kilos_a_kilo_y_arma_la_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'clavo punta paris']],
            'cambios' => [['campo' => 'unidad_de_medida', 'operacion' => 'asignar', 'valor' => 'kilos']],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(2, $respuesta['articulos_alcanzados']);
        $this->assertSame(['Unidad de medida → Kilo'], $respuesta['cambios_legibles']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(
            [['type' => 'select', 'key' => 'unidad_medida_id', 'value' => (int) $this->unidades['Kilo']->id]],
            $accion->datos['update_form']
        );

        // Con una unidad que no existe, `faltan` con todas las opciones (antes: error sin opciones).
        $sin = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'clavo punta paris']],
            'cambios' => [['campo' => 'unidad_de_medida', 'operacion' => 'asignar', 'valor' => 'toneladas']],
        ]);

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($sin));
        $this->assertNotEmpty($sin['faltan']);

        $opciones = json_decode(json_encode($sin['opciones']), true);
        $this->assertContains('Kilo', array_column($opciones['unidades_de_medida'], 'nombre'));
    }

    /**
     * La edición genérica de UN artículo usa el mismo resolvedor.
     *
     * @group chat-ia
     * @test
     */
    public function la_edicion_generica_de_un_articulo_resuelve_kilos_a_kilo()
    {
        list($conversation, $assistant) = $this->conversacion();

        $resultado = HerramientasDeCarga::ejecutar('proponer_edicion', [
            'entidad'  => 'article',
            'registro' => (int) $this->clavos[0]->id,
            'cambios'  => ['unidad_medida_id' => 'kilos'],
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        $contenido = json_decode($resultado['content'], true);

        $this->assertTrue($contenido['ok'], $resultado['content']);

        $accion = AiMessageAction::find($contenido['tarjeta_id']);

        $this->assertSame((int) $this->unidades['Kilo']->id, (int) $accion->datos['cambios']['unidad_medida_id']);

        // Y con una que no existe, la pregunta trae todas las unidades como opciones.
        $sin = HerramientasDeCarga::ejecutar('proponer_edicion', [
            'entidad'  => 'article',
            'registro' => (int) $this->clavos[0]->id,
            'cambios'  => ['unidad_medida_id' => 'toneladas'],
        ], $conversation, $assistant);

        $contenido_sin = json_decode($sin['content'], true);

        $this->assertFalse($contenido_sin['ok']);
        $this->assertNotEmpty($contenido_sin['faltan']);
        $this->assertContains('Kilo', array_column($contenido_sin['opciones']['unidad_medida_id'], 'nombre'));
    }
}
