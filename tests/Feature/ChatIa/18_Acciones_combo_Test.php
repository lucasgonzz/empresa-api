<?php

namespace Tests\Feature\ChatIa;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Combo;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión agente-ia-mano-derecha — el combo que propone el asistente y lo que pasa al confirmarlo.
 *
 * Lo que protege:
 *
 * - Que la tarjeta se arme con lo que la persona va a leer antes de confirmar: el nombre, cada
 *   artículo con su cantidad y el precio.
 * - Que lo que falta se pregunte y NO deje tarjeta: un combo a medias no se puede confirmar.
 * - Que la validación que la pantalla no tiene (al menos un artículo, cantidades enteras mayores a
 *   0, artículos del dueño) la ponga este camino, que es el que recibe datos dictados en palabras.
 * - Que confirmar cree el combo por ComboAltaHelper::crear con sus artículos, sus cantidades y su
 *   correlativo — el MISMO camino que `POST api/combo`.
 * - Que el segundo clic avise que la tarjeta ya se resolvió y no cree un segundo combo.
 * - Que sin la extensión `combos` la herramienta corte con el motivo, igual que la pantalla, que
 *   directamente no dibuja el botón.
 *
 * @group chat-ia
 */
class Acciones_combo_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red (no llama a la API).
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->extencion('asistente_ia', 'Asistente IA');
        $this->extencion('combos', 'Combos');

        $this->dueno->load('extencions');

        $this->service = new AsistenteIaService();
    }

    /**
     * Le asegura la extensión al dueño (el rollback de la transacción la saca al terminar).
     *
     * @param string $slug
     * @param string $nombre
     * @return ExtencionEmpresa
     */
    protected function extencion($slug, $nombre)
    {
        // forceCreate y no firstOrCreate: ExtencionEmpresa no declara $fillable.
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $nombre]);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        return $extencion;
    }

    /**
     * Conversación de una persona de la cuenta con su assistant pendiente y las acciones habilitadas.
     *
     * @param User|null $persona  Default: el dueño del fixture.
     * @param User|null $owner  Default: el dueño del fixture.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null, $owner = null)
    {
        $persona = is_null($persona) ? $this->dueno : $persona;
        $owner = is_null($owner) ? $this->dueno : $owner;

        $conversation = AiConversation::create([
            'user_id'      => $owner->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Armame un combo',
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
     * Llama a una herramienta por el mismo camino que el loop del servicio y devuelve su respuesta
     * decodificada.
     *
     * @param AiConversation $conversation
     * @param AiMessage $assistant
     * @param string $herramienta
     * @param array $input
     * @return array
     */
    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => $herramienta,
            'input' => $input,
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * Deja el mensaje del assistant en 'listo': la SPA recién ahí muestra la tarjeta y el ejecutor
     * recién ahí la deja confirmar.
     *
     * @param AiMessage $assistant
     * @return void
     */
    protected function mensaje_listo($assistant)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();
    }

    /**
     * Artículo nuevo del dueño, con nombre único. Se llama `articulo_nuevo` y no `articulo` porque
     * EmpresaTestCase::articulo($nombre) ya existe y busca uno del fixture por nombre.
     *
     * @param array $atributos
     * @return Article
     */
    protected function articulo_nuevo(array $atributos = [])
    {
        return Article::create(array_merge([
            'name'    => 'zz-combo-' . uniqid(),
            'user_id' => $this->dueno->id,
            'cost'    => 100,
            'status'  => 'active',
        ], $atributos));
    }

    /**
     * Todos los renglones de la presentación con esa etiqueta, en orden.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return array<int,string>
     */
    protected function renglones(array $presentacion, $etiqueta)
    {
        $valores = [];

        foreach ($presentacion['renglones'] as $renglon) {
            if ($renglon['etiqueta'] === $etiqueta) {
                $valores[] = $renglon['valor'];
            }
        }

        return $valores;
    }

    /**
     * El primer renglón con esa etiqueta.
     *
     * @param array $presentacion
     * @param string $etiqueta
     * @return string|null
     */
    protected function renglon(array $presentacion, $etiqueta)
    {
        $valores = $this->renglones($presentacion, $etiqueta);

        return count($valores) ? $valores[0] : null;
    }

    /**
     * @test
     */
    public function una_propuesta_completa_deja_la_tarjeta_con_los_articulos_las_cantidades_y_el_precio()
    {
        $martillo = $this->articulo_nuevo(['name' => 'zz-combo Martillo ' . uniqid()]);
        $pinza = $this->articulo_nuevo(['name' => 'zz-combo Pinza ' . uniqid()]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack ferretero',
            'articulos' => [
                ['articulo_id' => $martillo->id, 'cantidad' => 2],
                ['articulo_id' => $pinza->id, 'cantidad' => 1],
            ],
            'precio'    => 5000,
        ]);

        $this->assertTrue($respuesta['ok'], 'La propuesta tenía que quedar armada: ' . json_encode($respuesta));
        $this->assertEquals('combo', $respuesta['tipo']);
        $this->assertStringContainsString('Pack ferretero', $respuesta['resumen']);
        $this->assertStringContainsString('2 artículos', $respuesta['resumen']);
        $this->assertStringContainsString('$ 5.000', $respuesta['resumen']);
        $this->assertEquals([], $respuesta['reemplazo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertEquals('propuesta', $tarjeta->estado);
        $this->assertEquals('combo:pack ferretero', $tarjeta->clave);
        $this->assertEquals($assistant->id, $tarjeta->ai_message_id);

        $presentacion = $tarjeta->presentacion;

        $this->assertEquals('Combo', $presentacion['titulo']);
        $this->assertEquals('Pack ferretero', $this->renglon($presentacion, 'Nombre'));
        $this->assertEquals(
            [$martillo->name . ' × 2', $pinza->name . ' × 1'],
            $this->renglones($presentacion, 'Artículo')
        );
        $this->assertEquals('$ 5.000', $this->renglon($presentacion, 'Precio'));
        // Sin costo dictado no se inventa ninguno: el renglón directamente no está.
        $this->assertNull($this->renglon($presentacion, 'Costo'));
        $this->assertNull($presentacion['aviso']);

        // 🔴 Los datos van con la forma que espera attachModels: `id` + `pivot.amount`, la MISMA
        // que manda la pantalla.
        $this->assertEquals(
            [
                ['id' => $martillo->id, 'pivot' => ['amount' => 2]],
                ['id' => $pinza->id, 'pivot' => ['amount' => 1]],
            ],
            $tarjeta->datos['articles']
        );
        $this->assertEquals(5000.0, $tarjeta->datos['price']);
        $this->assertNull($tarjeta->datos['cost']);

        // Nada se creó todavía: la tarjeta es una propuesta.
        $this->assertEquals(0, Combo::where('user_id', $this->dueno->id)->where('name', 'Pack ferretero')->count());
    }

    /**
     * @test
     */
    public function sin_precio_y_sin_articulos_pregunta_las_dos_cosas_y_no_crea_tarjeta()
    {
        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre' => 'Pack sin datos',
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals(
            ['qué artículos lleva el combo y cuántos de cada uno', 'a qué precio se vende el combo'],
            $respuesta['faltan']
        );
        $this->assertNull($respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * 🔴 La validación que la pantalla NO tiene: un artículo de otro comercio no entra. Desde la
     * pantalla el buscador ya está scopeado por dueño, pero acá el id lo dicta un modelo de lenguaje.
     *
     * @test
     */
    public function un_articulo_de_otro_comercio_devuelve_error_y_no_crea_tarjeta()
    {
        $otro = User::create([
            'name'     => 'Comercio ajeno combos',
            'email'    => 'zz-combos-ajeno-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajeno = Article::create([
            'name'    => 'zz-combo ajeno ' . uniqid(),
            'user_id' => $otro->id,
            'cost'    => 100,
            'status'  => 'active',
        ]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack ajeno',
            'articulos' => [['articulo_id' => $ajeno->id, 'cantidad' => 1]],
            'precio'    => 5000,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('Alguno de los artículos del combo no está entre los tuyos.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Las cantidades van a un INTEGER no nulo del pivot: media unidad se guardaría truncada sin que
     * nada avise, y el combo diría otra cosa que la que la persona pidió.
     *
     * @test
     */
    public function una_cantidad_que_no_es_entera_positiva_devuelve_error()
    {
        $articulo = $this->articulo_nuevo();

        list($conversation, $assistant) = $this->conversacion();

        $cero = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack en cero',
            'articulos' => [['articulo_id' => $articulo->id, 'cantidad' => 0]],
            'precio'    => 5000,
        ]);

        $this->assertFalse($cero['ok']);
        $this->assertEquals('La cantidad de cada artículo del combo tiene que ser un número mayor a 0.', $cero['error']);

        $media = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack partido',
            'articulos' => [['articulo_id' => $articulo->id, 'cantidad' => 1.5]],
            'precio'    => 5000,
        ]);

        $this->assertFalse($media['ok']);
        $this->assertEquals('Las cantidades de un combo van en unidades enteras.', $media['error']);

        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * El camino completo: confirmar crea el combo por ComboAltaHelper::crear, con sus artículos, sus
     * cantidades y su correlativo.
     *
     * @test
     */
    public function confirmar_crea_el_combo_con_sus_articulos_y_su_correlativo()
    {
        $martillo = $this->articulo_nuevo();
        $pinza = $this->articulo_nuevo();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack confirmado ' . uniqid(),
            'articulos' => [
                ['articulo_id' => $martillo->id, 'cantidad' => 3],
                ['articulo_id' => $pinza->id, 'cantidad' => 1],
            ],
            'precio'    => 7200.5,
            'costo'     => 4000,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->mensaje_listo($assistant);

        $correlativo_anterior = (int) Combo::where('user_id', $this->dueno->id)->max('num');

        $confirmar = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmar->assertStatus(200);
        $this->assertEquals('confirmada', $confirmar->json('model.estado'));
        $this->assertEquals('article', $confirmar->json('model.resultado.ruta.name'));
        $this->assertEquals('Ver en el Listado', $confirmar->json('model.resultado.ruta.texto'));

        $combo = Combo::where('user_id', $this->dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertEquals('Combo ' . $combo->name . ' creado', $confirmar->json('model.resultado.texto'));
        $this->assertEquals($correlativo_anterior + 1, (int) $combo->num, 'El correlativo sale de num(), como en la pantalla.');
        $this->assertEqualsWithDelta(7200.5, (float) $combo->price, 0.01);
        $this->assertEqualsWithDelta(4000, (float) $combo->cost, 0.01);

        $combo->load('articles');

        $this->assertCount(2, $combo->articles);

        $cantidades = [];

        foreach ($combo->articles as $articulo) {
            $cantidades[(int) $articulo->id] = (int) $articulo->pivot->amount;
        }

        $this->assertEquals(3, $cantidades[$martillo->id]);
        $this->assertEquals(1, $cantidades[$pinza->id]);
    }

    /**
     * 🔴 El candado contra el segundo clic: la segunda confirmación avisa que la tarjeta ya se
     * resolvió y NO crea un segundo combo.
     *
     * @test
     */
    public function confirmar_dos_veces_avisa_que_ya_se_resolvio_y_no_crea_dos_combos()
    {
        $articulo = $this->articulo_nuevo();
        $nombre = 'Pack doble clic ' . uniqid();

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => $nombre,
            'articulos' => [['articulo_id' => $articulo->id, 'cantidad' => 1]],
            'precio'    => 3000,
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->mensaje_listo($assistant);

        $ruta = 'api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar';

        $this->postJson($ruta)->assertStatus(200);

        $segunda = $this->postJson($ruta);

        $segunda->assertStatus(409);
        $this->assertEquals('accion_resuelta', $segunda->json('code'));
        $this->assertEquals('confirmada', $segunda->json('model.estado'));

        $this->assertEquals(1, Combo::where('user_id', $this->dueno->id)->where('name', $nombre)->count(),
            'el segundo clic no puede dejar dos combos iguales');
    }

    /**
     * Sin la extensión `combos` la pantalla directamente no dibuja el botón
     * (src/components/listado/components/combos/Index.vue:3). El asistente espeja ese gate y lo
     * cuenta con el motivo, en vez de armar una tarjeta de un módulo que el comercio no tiene.
     *
     * @test
     */
    public function sin_la_extencion_de_combos_la_herramienta_corta_con_el_motivo()
    {
        $articulo = $this->articulo_nuevo();

        $combos = ExtencionEmpresa::where('slug', 'combos')->first();
        $this->dueno->extencions()->detach($combos->id);
        $this->dueno->load('extencions');

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_combo', [
            'nombre'    => 'Pack sin modulo',
            'articulos' => [['articulo_id' => $articulo->id, 'cantidad' => 1]],
            'precio'    => 5000,
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertEquals('Tu cuenta no tiene activado el módulo de Combos.', $respuesta['error']);
        $this->assertEquals(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }
}
