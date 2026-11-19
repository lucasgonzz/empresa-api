<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261119, rama B — A6 (2): presupuesto desde una lista larga con «poné lo que interpretes».
 *
 * 🔴 EL CASO REAL (Ferre Total, conv 62, 18/11/2026). 37 renglones para Fideicomiso El Cantón, «Buscalo por nombre» y
 * «Poné lo que interpretes y yo después lo reviso»: la herramienta cortaba con el primer renglón inexistente y
 * preguntaba cada ambiguo, y el presupuesto nunca se armó, ni con lo que sí había.
 *
 * Se protege: con `con_lo_que_encuentres`, la tarjeta sale con lo encontrado (el ambiguo con la mejor coincidencia y
 * anotado), los no encontrados quedan afuera y listados, sin ninguno encontrado es un error claro, sin la opción el
 * comportamiento de siempre no cambia, y el esquema y el prompt lo dicen.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class U191_Presupuesto_con_lo_que_encuentres_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-u191']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        foreach (['agentes_ia', PropuestaPresupuestoIaHelper::EXTENSION] as $slug) {
            $extencion = (ExtencionEmpresa::where('slug', $slug)->first() ?: ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]));
            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    protected function articulo_con_precio($nombre, $precio)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => $precio,
            'cost'        => $precio / 2,
            'stock'       => 10,
            'iva_id'      => 2,
        ]);
    }

    protected function turno()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Poné lo que interpretes y yo después lo reviso', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function proponer(array $input)
    {
        list($conversation, $assistant) = $this->turno();

        $resultado = HerramientasDeCarga::ejecutar('proponer_presupuesto', $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    /** La lista de Ferre Total, achicada: uno exacto, uno ambiguo y dos que no existen. */
    protected function la_lista()
    {
        return [
            ['articulo' => 'zz-u191 Aceite lubricante WD-40 311g', 'cantidad' => 10],
            ['articulo' => 'zz-u191 Remache 5', 'cantidad' => 500],
            ['articulo' => 'zz-u191 Precintos Dogo negros 250', 'cantidad' => 20],
            ['articulo' => 'zz-u191 Cinta reflectiva amarilla', 'cantidad' => 2],
        ];
    }

    protected function el_catalogo()
    {
        $this->articulo_con_precio('zz-u191 Aceite lubricante WD-40 311g', 9000);
        $this->articulo_con_precio('zz-u191 Remache 5 x 16 caja x500', 20000);
        $this->articulo_con_precio('zz-u191 Remache 5 x 20 caja x500', 22000);
    }

    /**
     * 🔴 Con la autorización, la tarjeta sale con lo encontrado: el exacto, el ambiguo con la mejor coincidencia, y los
     * que no existen afuera y listados (en la respuesta y en la tarjeta).
     *
     * @test
     */
    public function con_lo_que_encuentres_arma_la_tarjeta_y_lista_lo_que_falta()
    {
        $this->el_catalogo();

        $respuesta = $this->proponer([
            'items'                 => $this->la_lista(),
            'cliente'               => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'con_lo_que_encuentres' => true,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta, JSON_UNESCAPED_UNICODE));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::TIPO_PRESUPUESTO, $accion->tipo);
        $this->assertCount(2, $accion->datos['payload']['articles'], 'Entran el exacto y el ambiguo interpretado.');

        $this->assertCount(1, $respuesta['interpretados']);
        $this->assertSame('zz-u191 Remache 5', $respuesta['interpretados'][0]['pedido']);
        $this->assertStringStartsWith('zz-u191 Remache 5 x', $respuesta['interpretados'][0]['elegido']);
        $this->assertCount(1, $respuesta['interpretados'][0]['otras_opciones']);

        $no = array_map(function ($uno) {
            return $uno['pedido'];
        }, $respuesta['no_encontrados']);
        $this->assertSame(['zz-u191 Precintos Dogo negros 250', 'zz-u191 Cinta reflectiva amarilla'], $no);

        $this->assertStringContainsString('No le vuelvas a pedir los códigos', $respuesta['nota_de_lo_interpretado']);

        $renglones = json_encode($accion->presentacion['renglones'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('No encontrados (afuera)', $renglones);
        $this->assertStringContainsString('Interpretados (revisar)', $renglones);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado(), 'Sigue esperando el sí como cualquier presupuesto en cauteloso.');
    }

    /**
     * Sin la autorización, lo de siempre: el renglón inexistente corta con su motivo (no se arma nada a ciegas).
     *
     * @test
     */
    public function sin_la_opcion_el_presupuesto_corta_como_siempre()
    {
        $this->el_catalogo();

        $respuesta = $this->proponer([
            'items'   => $this->la_lista(),
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertSame(0, AiMessageAction::where('tipo', AiMessageAction::TIPO_PRESUPUESTO)->where('clave', 'like', '%zz-u191%')->count());
    }

    /**
     * Si no se encuentra NINGUNO, no hay presupuesto: un error que lo dice y nombra los renglones.
     *
     * @test
     */
    public function sin_ninguno_encontrado_es_un_error_claro()
    {
        $respuesta = $this->proponer([
            'items'                 => [['articulo' => 'zz-u191 Nada de esto existe', 'cantidad' => 1]],
            'cliente'               => TestingFerreteriaSeeder::CLIENTE_CONTADO,
            'con_lo_que_encuentres' => true,
        ]);

        $this->assertTrue(empty($respuesta['ok']));
        $this->assertStringContainsString('No encontré ninguno de los artículos', $respuesta['error']);
        $this->assertStringContainsString('zz-u191 Nada de esto existe', $respuesta['error']);
    }

    /**
     * El esquema ofrece la opción y el prompt dice cuándo usarla.
     *
     * @test
     */
    public function el_esquema_y_el_prompt_lo_dicen()
    {
        $definicion = null;

        foreach (HerramientasDeCarga::definiciones() as $una) {
            if ($una['name'] === 'proponer_presupuesto') {
                $definicion = $una;
            }
        }

        $this->assertNotNull($definicion);
        $this->assertSame('boolean', $definicion['input_schema']['properties']['con_lo_que_encuentres']['type']);

        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $prompt = (new AsistenteIaService())->build_system_prompt($conversation, $this->dueno, true, true);

        $this->assertStringContainsString('con_lo_que_encuentres: true', $prompt);
    }
}
