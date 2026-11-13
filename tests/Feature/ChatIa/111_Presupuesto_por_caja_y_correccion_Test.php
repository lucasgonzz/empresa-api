<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AltaBebidasIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B7 — F9 del inventario.
 *
 * EL CASO REAL. Fernando pidió un presupuesto para Adriana Orietta Bonzano con «una caja de Perro
 * Callejero Malbec, una caja de Fabre Montmayou…» y el asistente cargó 6 unidades de cada uno (las
 * botellas de la caja). En Truvari el artículo ES la caja: con el módulo Vinoteca el precio final se
 * multiplica por la presentación (VinotecaPriceHelper), así que «una caja» es cantidad 1. Después
 * dijo «Corrijo el presupuesto 25» y creó el N° 26 nuevo, con el 25 todavía vivo.
 *
 * Lo que se fija:
 *  - la tarjeta dice «1 × Perro Callejero Malbec (caja de 6)» y el payload va con amount 1;
 *  - proponer un presupuesto para un cliente que ya tiene uno creado en esta conversación lo avisa en
 *    la tarjeta y en la respuesta: es uno NUEVO y el N° anterior sigue existiendo (y se puede borrar);
 *  - la instrucción del alta de bebidas (cajas × botellas) dice que vale solo para el alta.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class Presupuesto_por_caja_y_correccion_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var array */
    protected $extensiones = [];

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-p111']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        foreach (['agentes_ia', PropuestaPresupuestoIaHelper::EXTENSION, 'vinoteca'] as $slug) {
            $extencion = ExtencionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
            $this->extensiones[] = $extencion->id;
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

    protected function vino($nombre, $precio_de_la_caja)
    {
        return Article::create([
            'name'         => $nombre,
            'user_id'      => $this->dueno->id,
            'status'       => 'active',
            'final_price'  => $precio_de_la_caja,
            'cost'         => $precio_de_la_caja / 2,
            'presentacion' => 6,
            'stock'        => 10,
            'iva_id'       => 2,
        ]);
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        return [$conversation, $this->turno($conversation, $pedido)];
    }

    protected function turno($conversation, $pedido)
    {
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        return AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    /**
     * 🔴 «Una caja de Perro Callejero» = 1 unidad del artículo, y la tarjeta lo dice.
     *
     * @test
     */
    public function una_caja_es_una_unidad_y_la_tarjeta_lo_muestra()
    {
        $this->vino('zz-p111 Perro Callejero Malbec', 41000);

        list($conversation, $assistant) = $this->conversacion('Presupuesto para Bonzano: una caja de Perro Callejero Malbec');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p111 Perro Callejero Malbec', 'cantidad' => 1]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame(1.0, (float) $accion->datos['payload']['articles'][0]['amount']);
        $this->assertSame('1 × zz-p111 Perro Callejero Malbec (caja de 6) — $ 41.000 = $ 41.000', $accion->presentacion['renglones'][0]['valor']);

        // La descripción de la cantidad le dice al modelo que no multiplique por botellas.
        $definiciones = [];
        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $definiciones[$definicion['name']] = $definicion;
        }

        foreach (['proponer_presupuesto', 'proponer_venta'] as $nombre) {
            $cantidad = $definiciones[$nombre]['input_schema']['properties']['items']['items']['properties']['cantidad']['description'];
            $this->assertStringContainsString('nunca la multipliques', $cantidad, $nombre);
        }
    }

    /**
     * 🔴 «Corrijo el presupuesto 25» y salió el N° 26: proponer otro presupuesto para el mismo cliente
     * en la misma conversación avisa que es uno NUEVO y que el anterior sigue existiendo.
     *
     * @test
     */
    public function un_segundo_presupuesto_para_el_mismo_cliente_avisa_que_el_anterior_sigue()
    {
        $this->vino('zz-p111 Fabre Montmayou Malbec', 38000);

        list($conversation, $assistant) = $this->conversacion('Presupuesto de 6 Fabre Montmayou');

        $primera = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p111 Fabre Montmayou Malbec', 'cantidad' => 6]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);
        $this->assertTrue(!empty($primera['ok']), json_encode($primera));
        $this->assertArrayNotHasKey('presupuesto_anterior', $primera);

        $this->confirmar($conversation, $assistant, $primera['tarjeta_id'])->assertStatus(200);

        $creado = AiMessageAction::find($primera['tarjeta_id']);
        $numero = (int) $creado->resultado->numero;
        $this->assertGreaterThan(0, $numero);

        $assistant = $this->turno($conversation, 'Te equivocaste: una caja es una unidad, corregilo');

        $segunda = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p111 Fabre Montmayou Malbec', 'cantidad' => 1]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $this->assertTrue(!empty($segunda['ok']), json_encode($segunda));
        $this->assertSame($numero, $segunda['presupuesto_anterior']['numero']);
        $this->assertStringContainsString('NUEVO', $segunda['presupuesto_anterior']['nota']);
        $this->assertStringContainsString('proponer_borrado_por_pantalla', $segunda['presupuesto_anterior']['nota']);

        $tarjeta = AiMessageAction::find($segunda['tarjeta_id']);
        $this->assertStringContainsString('N° ' . $numero . ' sigue existiendo', $tarjeta->presentacion['aviso']);

        // Si el anterior ya no existe (lo borraron), no se avisa.
        Budget::where('id', (int) $creado->resultado->presupuesto_id)->delete();

        $assistant = $this->turno($conversation, 'Otro más');

        $tercera = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p111 Fabre Montmayou Malbec', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);
        $this->assertArrayNotHasKey('presupuesto_anterior', $tercera);
    }

    /**
     * Sin Vinoteca, un artículo con presentación no dice "caja": el precio no está multiplicado.
     *
     * @test
     */
    public function sin_vinoteca_la_tarjeta_no_habla_de_cajas()
    {
        $this->dueno->extencions()->detach(ExtencionEmpresa::where('slug', 'vinoteca')->value('id'));
        $this->dueno->load('extencions');

        $this->vino('zz-p111 Sin vinoteca', 5000);

        list($conversation, $assistant) = $this->conversacion('Presupuesto');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_presupuesto', [
            'items'   => [['articulo' => 'zz-p111 Sin vinoteca', 'cantidad' => 2]],
            'cliente' => TestingFerreteriaSeeder::CLIENTE_CONTADO,
        ]);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertStringNotContainsString('caja', $accion->presentacion['renglones'][0]['valor']);
    }

    /**
     * La instrucción del alta de bebidas (cajas × botellas) queda acotada al alta.
     *
     * @test
     */
    public function la_instruccion_de_cajas_del_alta_no_aplica_a_ventas_ni_presupuestos()
    {
        $texto = AltaBebidasIaHelper::instrucciones();

        $this->assertStringContainsString('SOLO al alta', $texto);
        $this->assertStringContainsString('NUNCA a ventas, presupuestos ni pedidos', $texto);
    }
}
