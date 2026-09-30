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

    /**
     * 🔴 El caso del 29/9/2026: en una corrida completa de ChatIa en s8 fallaron los dos primeros
     * tests de esta clase, y en la corrida siguiente pasaron sin que nadie tocara nada.
     *
     * La causa: ese día apareció la tabla número 16 con `price_type_id` (`article_ticket_designs`),
     * y el helper cuenta sólo TOPE_DE_TABLAS_A_CONTAR (15), ordenadas por la estimación de filas de
     * InnoDB (`information_schema.tables.table_rows`, cacheada 86400 s). `clients` y `sales` eran
     * las dos más grandes y estaban pegadas: cuando la estimación de `clients` quedó por encima de
     * la de `sales`, `clients` quedó 16ª, no se contó, y el aviso dejó de decir "3 clientes".
     *
     * Este test no depende de la base: arma a mano las 16 candidatas reales, con `clients` como la
     * de MÁS filas estimadas —el escenario que rompía—, y le pregunta al orden directamente. No
     * crea filas.
     *
     * @test
     */
    public function clientes_se_cuentan_aunque_la_estimacion_de_mysql_los_ponga_ultimos()
    {
        $etiqueta_de_tabla = new \ReflectionMethod(Catalogo::class, 'etiqueta_de_tabla');
        $etiqueta_de_tabla->setAccessible(true);

        $ordenar_para_contar = new \ReflectionMethod(Catalogo::class, 'ordenar_para_contar');
        $ordenar_para_contar->setAccessible(true);

        // Las 16 tablas con `price_type_id` del esquema al 29/9/2026, con filas estimadas donde
        // `clients` (95) supera a `sales` (89).
        $filas_por_tabla = [
            'article_price_type_monedas'     => 0,
            'article_prices'                 => 0,
            'category_price_type'            => 0,
            'category_price_type_ranges'     => 0,
            'movimiento_puntos'              => 0,
            'offer_suggestion_lines'         => 0,
            'price_type_sistema_de_puntos'   => 0,
            'price_type_sub_category'        => 0,
            'price_type_surchages'           => 0,
            'article_ticket_designs'         => 5,
            'budgets'                        => 23,
            'company_performance_price_type' => 32,
            'article_price_type'             => 40,
            'price_change_price_type'        => 40,
            'clients'                        => 95,
            'sales'                          => 89,
        ];

        $candidatas = [];

        foreach ($filas_por_tabla as $tabla => $filas) {
            $candidatas[] = [
                'tabla'            => $tabla,
                'indexada'         => false,
                'filas'            => $filas,
                'tiene_user_id'    => true,
                'tiene_deleted_at' => false,
                // Lo mismo que calcula tablas_que_referencian(), no un valor inventado.
                'nombrable'        => $etiqueta_de_tabla->invoke(null, $tabla) !== Catalogo::ETIQUETA_INNOMBRABLE,
            ];
        }

        // La premisa: `clients` tiene nombre en el aviso. Si deja de tenerlo, este test no prueba nada.
        $this->assertNotSame(Catalogo::ETIQUETA_INNOMBRABLE, $etiqueta_de_tabla->invoke(null, 'clients'), 'clients tiene que ser una entidad del catálogo con etiqueta propia');

        $ordenadas = $ordenar_para_contar->invoke(null, $candidatas);

        $orden = array_column($ordenadas, 'tabla');

        $this->assertCount(count($candidatas), $orden, 'Ordenar no puede agregar ni sacar tablas');

        $contadas = array_slice($orden, 0, Catalogo::TOPE_DE_TABLAS_A_CONTAR);

        $this->assertContains('clients', $contadas, 'clients tiene que estar entre las primeras ' . Catalogo::TOPE_DE_TABLAS_A_CONTAR . ' tablas que se cuentan aunque sea la de más filas estimadas. Orden: ' . implode(', ', $orden));

        // Lo que el tope deja afuera tiene que ser de lo que se suma en "vínculos internos".
        foreach (array_slice($ordenadas, Catalogo::TOPE_DE_TABLAS_A_CONTAR) as $afuera) {
            $this->assertFalse($afuera['nombrable'], 'El tope dejó afuera una tabla con nombre en el aviso: ' . $afuera['tabla'] . '. Orden: ' . implode(', ', $orden));
        }

        // Determinismo: el mismo conjunto, entrando en otro orden, sale igual. usort no es estable
        // en PHP 7.4 y hay nueve tablas con 0 filas y dos con 40.
        $invertidas = array_reverse($candidatas);

        $this->assertSame($orden, array_column($ordenar_para_contar->invoke(null, $invertidas), 'tabla'), 'El orden cambió al invertir la entrada');

        $mezcladas = $candidatas;

        mt_srand(43);
        shuffle($mezcladas);
        // Se vuelve a sembrar al azar para no dejarle una semilla fija al resto de la suite.
        mt_srand();

        $this->assertSame($orden, array_column($ordenar_para_contar->invoke(null, $mezcladas), 'tabla'), 'El orden cambió al mezclar la entrada');
    }
}
