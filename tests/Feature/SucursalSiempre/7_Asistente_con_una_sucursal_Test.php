<?php

namespace Tests\Feature\SucursalSiempre;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\FichaArticuloIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * D12 de la misión sucursal-siempre: con UNA sola sucursal el asistente no le pregunta al dueño "¿a
 * qué depósito?": propone el stock total y, al confirmar, si el artículo reparte lo escribe en esa
 * sucursal. La ficha sigue diciendo "tiene depósitos" desde la segunda sucursal (el mismo criterio
 * de la interfaz, D1). Mismo arnés que 61_Stock_inicial_y_stock_sin_depositos_Test (sin red).
 */
class Asistente_con_una_sucursal_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var array */
    protected $extensiones_enganchadas = [];

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-sucursal-siempre']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->confianza_original = $this->dueno->agente_confianza;

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'agentes_ia']);
        }

        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);
        $this->extensiones_enganchadas[] = $extencion;

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        $this->actuar_como($this->dueno);

        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        foreach ($this->extensiones_enganchadas as $extencion) {
            $this->dueno->extencions()->detach($extencion->id);
        }

        parent::tearDown();
    }

    protected function actuar_como(User $persona)
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        $this->actingAs($persona, 'web');
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

        $this->assertFalse($resultado['is_error'], 'La herramienta devolvió una falla técnica: '.$resultado['content']);

        return json_decode($resultado['content'], true);
    }

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        $this->actuar_como($this->dueno);

        return $this->postJson('api/ai-conversations/'.$conversation->id.'/acciones/'.$tarjeta_id.'/confirmar');
    }

    protected function articulo_propio($nombre, $stock = null)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 1000,
            'cost'        => 500,
            'stock'       => $stock,
            'iva_id'      => 2,
        ]);
    }

    protected function unica()
    {
        $unica = Address::unica_sucursal_de($this->dueno->id);

        $this->assertNotNull($unica, 'El fixture tiene que dejar al dueño con UNA sola sucursal.');

        return $unica;
    }

    /**
     * Artículo que reparte, una sola sucursal, sin nombrar depósito: tarjeta del total (sin
     * preguntar) y al confirmar la fila de la única sucursal queda en el número pedido.
     *
     * @test
     */
    public function con_una_sola_sucursal_no_pregunta_el_deposito_y_escribe_en_ella()
    {
        $unica = $this->unica();

        $articulo = $this->articulo_propio('zz-ss Yerba una sucursal', 0);
        $articulo->addresses()->attach($unica->id, ['amount' => 5]);
        DB::table('articles')->where('id', $articulo->id)->update(['stock' => 5]);

        list($conversation, $assistant) = $this->conversacion('Sumale 10 a la yerba');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-ss Yerba una sucursal',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), 'Con una sola sucursal no tiene que preguntar: '.json_encode($respuesta));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $fila = (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $unica->id)->value('amount');

        $this->assertEqualsWithDelta(15, $fila, self::DELTA);
        $this->assertEqualsWithDelta(15, (float) DB::table('articles')->where('id', $articulo->id)->value('stock'), self::DELTA);
    }

    /**
     * Con dos sucursales y sin nombrar depósito: pregunta, como siempre.
     *
     * @test
     */
    public function con_dos_sucursales_sigue_preguntando()
    {
        $this->unica();
        Address::create(['street' => 'zz-ss Segunda', 'user_id' => $this->dueno->id]);

        $this->articulo_propio('zz-ss Yerba dos sucursales', 5);

        list($conversation, $assistant) = $this->conversacion('Sumale 10 a la yerba');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-ss Yerba dos sucursales',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertNotEmpty($respuesta['faltan']);
    }

    /**
     * La ficha: "tiene depósitos" desde la segunda sucursal; un domicilio de comprador no cuenta.
     *
     * @test
     */
    public function la_ficha_cuenta_solo_sucursales_de_verdad()
    {
        $this->unica();

        Address::create(['street' => 'zz-ss Domicilio', 'user_id' => $this->dueno->id, 'buyer_id' => 4321]);

        $this->assertFalse(FichaArticuloIaHelper::tiene_depositos($this->dueno->id), 'Una sucursal y un domicilio de comprador: no hay reparto que mostrar.');

        Address::create(['street' => 'zz-ss Segunda ficha', 'user_id' => $this->dueno->id]);

        $this->assertTrue(FichaArticuloIaHelper::tiene_depositos($this->dueno->id));
    }
}
