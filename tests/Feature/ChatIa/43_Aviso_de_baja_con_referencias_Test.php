<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Brand;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente — arreglo del 🔴 4 del chequeo adversarial: una baja que deja
 * referencias colgadas lo dice, con el número, y una baja definitiva lo dice con esas palabras.
 *
 * Lo que había: `PriceType` es el único de su familia que NO usa SoftDeletes, y
 * `PriceTypeController::destroy()` sólo desengancha los artículos. Los clientes que tenían esa
 * lista quedaban con un `price_type_id` que no existe —la misma clase de dato huérfano que ya
 * tumbó el listado de artículos, y que tiene su propia migración de limpieza
 * (`2026_09_18_120000_normalizar_price_type_id_cero_en_clients`)—, mientras la tarjeta decía
 * "Se borra la lista y los artículos dejan de tener precio en ella", como si fuera reversible y
 * como si los clientes no existieran.
 *
 * Y no es un caso suelto: de las 40 entidades del catálogo que admiten baja, 31 la tienen
 * DEFINITIVA (medido el 21/9/2026 con el trait de cada modelo). Por eso la guarda es genérica: se
 * lee el esquema, se buscan las tablas con una columna `<entidad>_id` y se cuentan las filas del
 * dueño que apuntan al registro.
 *
 * @group chat-ia
 */
class Aviso_de_baja_con_referencias_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var AsistenteIaService */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca la clave real del .env.testing: este archivo no sale a la red.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $extencion = ExtencionEmpresa::where('slug', 'asistente_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'asistente_ia', 'name' => 'Asistente IA']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        $this->service = new AsistenteIaService();

        Catalogo::olvidar();
    }

    /**
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Borrame esto',
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
     * @param  AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $entidad
     * @param  mixed  $registro
     * @return array
     */
    protected function proponer_baja($conversation, $assistant, $entidad, $registro)
    {
        $resultados = $this->service->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'proponer_baja',
            'input' => ['entidad' => $entidad, 'registro' => $registro],
        ]], $conversation, $assistant);

        $this->assertArrayNotHasKey('is_error', $resultados[0], 'La herramienta devolvió una falla técnica: ' . $resultados[0]['content']);

        return json_decode($resultados[0]['content'], true);
    }

    /**
     * 🔴 EL CASO DEL CHEQUEO: borrar una lista de precios que tres clientes tienen asignada.
     *
     * El aviso tiene que decir las dos cosas que la tarjeta se callaba: que la baja es definitiva y
     * cuántos clientes quedan sin lista.
     *
     * @test
     */
    public function la_baja_de_una_lista_de_precios_dice_que_es_definitiva_y_cuantos_clientes_quedan()
    {
        $lista = PriceType::create(['name' => 'zz-c43 Mayorista', 'percentage' => 10, 'user_id' => $this->dueno->id, 'num' => 9043]);

        for ($i = 1; $i <= 3; $i++) {
            Client::create(['name' => 'zz-c43 Cliente ' . $i, 'user_id' => $this->dueno->id, 'num' => 9430 + $i, 'price_type_id' => $lista->id]);
        }

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_baja($conversation, $assistant, 'price_type', 'zz-c43 Mayorista');

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = (string) $respuesta['aviso'];

        $this->assertStringContainsString('DEFINITIVA', $aviso, 'La baja de una lista de precios no va a la papelera y tiene que decirlo: ' . $aviso);
        $this->assertStringContainsString('3 clientes', $aviso, 'El aviso tiene que decir cuántos clientes quedan colgados: ' . $aviso);
        $this->assertStringContainsString('van a quedar sin ella', $aviso, $aviso);

        // Y lo mismo queda guardado en la tarjeta, que es lo que ve la persona.
        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame($aviso, $tarjeta->presentacion['aviso']);
        $this->assertSame(AiMessageAction::TIPO_BAJA, $tarjeta->tipo);
    }

    /**
     * La cuenta es de las filas DEL DUEÑO y de las que siguen vivas: un cliente de otro comercio y
     * uno en la papelera no cuentan.
     *
     * @test
     */
    public function la_cuenta_saltea_las_filas_borradas_y_las_de_otro_dueno()
    {
        $otro = User::create([
            'name'     => 'Otro comercio c43',
            'email'    => 'otro-c43-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $lista = PriceType::create(['name' => 'zz-c43 Lista con ruido', 'percentage' => 5, 'user_id' => $this->dueno->id, 'num' => 9044]);

        $vivo = Client::create(['name' => 'zz-c43 Cliente vivo', 'user_id' => $this->dueno->id, 'num' => 9441, 'price_type_id' => $lista->id]);

        $borrado = Client::create(['name' => 'zz-c43 Cliente borrado', 'user_id' => $this->dueno->id, 'num' => 9442, 'price_type_id' => $lista->id]);
        $borrado->delete();

        Client::create(['name' => 'zz-c43 Cliente ajeno', 'user_id' => $otro->id, 'num' => 9443, 'price_type_id' => $lista->id]);

        $declaracion = Catalogo::declaracion('price_type');

        $colgadas = Catalogo::referencias_que_quedan_colgadas($declaracion, (int) $lista->id, (int) $this->dueno->id);

        $clientes = null;

        foreach ($colgadas['referencias'] as $referencia) {
            if ($referencia['tabla'] === 'clients') {
                $clientes = $referencia;
            }
        }

        $this->assertNotNull($clientes, 'La tabla clients tenía que aparecer entre las referencias');
        $this->assertSame(1, $clientes['cantidad'], 'Sólo el cliente vivo del dueño cuenta');
        $this->assertSame('clientes', $clientes['etiqueta']);
        $this->assertNotNull(Client::find($vivo->id));
    }

    /**
     * Una entidad que SÍ va a la papelera no dice que la baja es definitiva ni cuenta nada: la
     * fila sigue existiendo y no deja a nadie colgado.
     *
     * @test
     */
    public function una_entidad_con_papelera_no_avisa_que_la_baja_es_definitiva()
    {
        $proveedor = Provider::create(['name' => 'zz-c43 Proveedor con papelera', 'user_id' => $this->dueno->id, 'num' => 9045]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_baja($conversation, $assistant, 'provider', 'zz-c43 Proveedor con papelera');

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = (string) $respuesta['aviso'];

        $this->assertStringContainsString('papelera', $aviso);
        $this->assertStringNotContainsString('DEFINITIVA', $aviso, $aviso);
        $this->assertStringNotContainsString('van a quedar sin', $aviso, $aviso);
        $this->assertNotNull(Provider::find($proveedor->id), 'Proponer no borra nada');

        $this->assertFalse(Catalogo::declaracion('provider')['baja_definitiva']);
        $this->assertTrue(Catalogo::declaracion('price_type')['baja_definitiva']);
    }

    /**
     * Una entidad definitiva SIN referencias avisa que es definitiva y no inventa ninguna cuenta.
     *
     * @test
     */
    public function una_entidad_definitiva_sin_referencias_no_inventa_la_cuenta()
    {
        $marca = Brand::create(['name' => 'zz-c43 Marca sin uso', 'user_id' => $this->dueno->id, 'num' => 9046]);

        list($conversation, $assistant) = $this->conversacion();

        $respuesta = $this->proponer_baja($conversation, $assistant, 'brand', 'zz-c43 Marca sin uso');

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = (string) $respuesta['aviso'];

        $this->assertStringContainsString('DEFINITIVA', $aviso, $aviso);
        $this->assertStringNotContainsString('van a quedar sin', $aviso, $aviso);
        $this->assertNotNull(Brand::find($marca->id));
    }
}
