<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPresupuestoIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B8 — F10 del inventario.
 *
 * EL CASO REAL. «Borrá los presupuestos 25 y 26» → «no tengo una ruta de borrado». La ruta existe
 * (`DELETE api/budget/{budget}`, BudgetController::destroy) y está en el catálogo de acciones de
 * pantalla, pero el modelo la buscó en español («presupuesto», «borrar») y las rutas están en inglés.
 * Además el «25» que dice el dueño es el N° del presupuesto, no su id.
 *
 * Lo que se fija:
 *  - que_acciones_de_pantalla_hay encuentra el borrado buscando «borrar presupuesto»;
 *  - proponer_borrado_por_pantalla sobre esa ruta resuelve por N° (o por id), arma la tarjeta
 *    «Borrar el presupuesto N° X de <cliente> ($ total)» y, confirmada, lo borra;
 *  - un presupuesto confirmado no se borra: el error dice que se anula;
 *  - BudgetController::destroy filtra por dueño (404) y no borra un confirmado (422), también para
 *    la pantalla.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class Borrar_presupuesto_por_pantalla_Test extends EmpresaTestCase
{
    /** @var User */
    protected $dueno;

    /** @var Client */
    protected $cliente;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-p112']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        foreach (['agentes_ia', PropuestaPresupuestoIaHelper::EXTENSION] as $slug) {
            $extencion = ExtencionEmpresa::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        }

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'directo']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        $this->cliente = Client::create(['name' => 'zz-p112 Adriana Orietta Bonzano', 'user_id' => $this->dueno->id]);

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
    }

    protected function presupuesto($numero, $estado = BudgetController::ESTADO_SIN_CONFIRMAR, $user_id = null)
    {
        return Budget::create([
            'num'              => $numero,
            'client_id'        => $this->cliente->id,
            'user_id'          => is_null($user_id) ? $this->dueno->id : $user_id,
            'budget_status_id' => $estado,
            'total'            => 331000,
        ]);
    }

    protected function conversacion($pedido)
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    protected function herramienta($conversation, $assistant, $herramienta, array $input)
    {
        $resultado = HerramientasDeCarga::ejecutar($herramienta, $input, $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: ' . $resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function borrar($conversation, $assistant, array $parametros)
    {
        return $this->herramienta($conversation, $assistant, 'proponer_borrado_por_pantalla', [
            'ruta'        => 'api/budget/{budget}',
            'parametros'  => $parametros,
            'descripcion' => 'Borrar el presupuesto',
        ]);
    }

    /**
     * El modelo busca en español y encuentra la ruta.
     *
     * @test
     */
    public function buscar_borrar_presupuesto_encuentra_la_ruta()
    {
        $lista = CatalogoDeAccionesDePantallaIaHelper::lista('borrar presupuesto', 'DELETE');

        $rutas = array_column($lista['acciones'], 'ruta');

        $this->assertContains('api/budget/{budget}', $rutas);

        $this->assertContains('api/budget/{budget}', array_column(CatalogoDeAccionesDePantallaIaHelper::lista('eliminar presupuestos')['acciones'], 'ruta'));
    }

    /**
     * 🔴 «Borrá el presupuesto 990125»: por N°, tarjeta con el número, el cliente y el total, SIEMPRE
     * con tarjeta (también en "directo"); confirmada, borra.
     *
     * @test
     */
    public function borrar_por_numero_deja_tarjeta_legible_y_confirmada_borra()
    {
        $presupuesto = $this->presupuesto(990125);

        list($conversation, $assistant) = $this->conversacion('Borrá el presupuesto 990125');

        $respuesta = $this->borrar($conversation, $assistant, ['numero' => 990125]);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_BORRADO_PANTALLA, $respuesta['tipo']);

        $tarjeta = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());
        $this->assertSame('Borrar el presupuesto N° 990125 de zz-p112 Adriana Orietta Bonzano ($ 331.000)', $tarjeta->presentacion['titulo']);
        $this->assertSame((int) $presupuesto->id, (int) $tarjeta->datos['parametros']['budget']);

        $assistant->contenido = 'Te dejé la tarjeta.';
        $assistant->estado = 'listo';
        $assistant->save();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')->assertStatus(200);

        $this->assertNull(Budget::find($presupuesto->id));
    }

    /**
     * El número que dijo la persona en `budget` también se acepta; si ese número es a la vez el N° de
     * uno y el id de OTRO, se pregunta (nunca se adivina cuál se borra).
     *
     * @test
     */
    public function un_numero_ambiguo_entre_id_y_numero_se_pregunta()
    {
        $solo = $this->presupuesto(990126);

        list($conversation, $assistant) = $this->conversacion('Borrá el 990126');

        $respuesta = $this->borrar($conversation, $assistant, ['budget' => 990126]);
        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));
        $this->assertSame((int) $solo->id, (int) AiMessageAction::find($respuesta['tarjeta_id'])->datos['parametros']['budget']);

        // Uno cuyo N° es el id de otro.
        $otro = $this->presupuesto(990127);
        $choca = $this->presupuesto((int) $otro->id);

        list($conversation, $assistant) = $this->conversacion('Borrá el ' . $otro->id);

        $ambiguo = $this->borrar($conversation, $assistant, ['budget' => (int) $otro->id]);

        $this->assertFalse($ambiguo['ok']);
        $this->assertStringContainsString('numero', $ambiguo['error']);
        $this->assertNotNull(Budget::find($otro->id));
        $this->assertNotNull(Budget::find($choca->id));
    }

    /**
     * 🔴 Un presupuesto confirmado no se borra: la herramienta dice que se anula, sin tarjeta.
     *
     * @test
     */
    public function un_confirmado_no_se_borra_y_se_dice_que_se_anula()
    {
        $this->presupuesto(990128, BudgetController::ESTADO_CONFIRMADO);

        list($conversation, $assistant) = $this->conversacion('Borrá el 990128');

        $respuesta = $this->borrar($conversation, $assistant, ['numero' => 990128]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('anula', $respuesta['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());

        $ninguno = $this->borrar($conversation, $assistant, ['numero' => 990199]);
        $this->assertFalse($ninguno['ok']);
        $this->assertStringContainsString('990199', $ninguno['error']);
    }

    /**
     * BudgetController::destroy: el de otro dueño da 404 y no se toca; un confirmado da 422.
     *
     * @test
     */
    public function destroy_filtra_por_dueno_y_no_borra_un_confirmado()
    {
        $otro = User::create(['name' => 'Otro p112', 'email' => 'otro-p112-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);

        $ajeno = $this->presupuesto(990129, BudgetController::ESTADO_SIN_CONFIRMAR, $otro->id);
        $confirmado = $this->presupuesto(990130, BudgetController::ESTADO_CONFIRMADO);
        $propio = $this->presupuesto(990131);

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'sanctum');

        $this->deleteJson('api/budget/' . $ajeno->id)->assertStatus(404);
        $this->assertNotNull(Budget::find($ajeno->id));

        $this->deleteJson('api/budget/' . $confirmado->id)->assertStatus(422);
        $this->assertNotNull(Budget::find($confirmado->id));

        $this->deleteJson('api/budget/' . $propio->id)->assertStatus(200);
        $this->assertNull(Budget::find($propio->id));
    }
}
