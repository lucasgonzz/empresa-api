<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaStockIaHelper;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026), B2 — F3a del inventario.
 *
 * EL CASO REAL. Fernando (Truvari) tiene UNA sucursal y UN depósito. Pidió «ponele 6 unidades al
 * aceite Coratina» y el asistente le preguntó «¿los 6 van a Florida?»: Florida no existe en
 * Truvari, salió del ejemplo de la descripción de la herramienta («Sumale 10 a Florida»), el modelo
 * lo mandó como `deposito` y, como el nombre no coincidía con el único depósito, la herramienta
 * contestó «No encontré ningún depósito…». Con una sola sucursal no hay a qué depósito preguntar:
 * va a la tarjeta del stock total (la misma de D12, sucursal-siempre), llegue o no `deposito`.
 *
 * 🔴 Sin red. PHP 7.4.
 *
 * @group chat-ia
 */
class Stock_con_un_solo_deposito_Test extends EmpresaTestCase
{
    const DELTA = 0.01;

    /** @var User */
    protected $dueno;

    /** @var string|null */
    protected $confianza_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba-p108']);

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $this->confianza_original = $this->dueno->agente_confianza;

        $extencion = ExtencionEmpresa::firstOrCreate(['slug' => 'agentes_ia'], ['name' => 'agentes_ia']);
        $this->dueno->extencions()->syncWithoutDetaching([$extencion->id]);

        User::where('id', $this->dueno->id)->update(['agente_confianza' => 'cauteloso']);

        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        Catalogo::olvidar();
    }

    protected function tearDown(): void
    {
        User::where('id', $this->dueno->id)->update(['agente_confianza' => $this->confianza_original]);

        parent::tearDown();
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

    protected function confirmar($conversation, $assistant, $tarjeta_id)
    {
        $assistant->contenido = 'Te dejé la tarjeta para confirmar.';
        $assistant->estado = 'listo';
        $assistant->save();

        Auth::forgetGuards();
        $this->actingAs($this->dueno, 'web');

        return $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $tarjeta_id . '/confirmar');
    }

    protected function unica()
    {
        $unica = Address::unica_sucursal_de($this->dueno->id);

        $this->assertNotNull($unica, 'El fixture tiene que dejar al dueño con UNA sola sucursal.');

        return $unica;
    }

    /**
     * 🔴 «Ponele 6 al Coratina» con `deposito: Florida` (un nombre que no existe) en un negocio de una
     * sola sucursal: tarjeta del total, sin error y sin preguntar; al confirmar queda en 6.
     *
     * @test
     */
    public function con_una_sola_sucursal_un_deposito_que_no_existe_no_corta()
    {
        $this->unica();

        $coratina = Article::create([
            'name'        => 'zz-p108 Aceite de Oliva Familia Zuccardi Coratina 500 ml',
            'user_id'     => $this->dueno->id,
            'status'      => 'active',
            'final_price' => 17000,
            'cost'        => 9000,
            'stock'       => null,
            'iva_id'      => 2,
        ]);

        list($conversation, $assistant) = $this->conversacion('Ponele 6 unidades de stock al aceite Coratina');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p108 Aceite de Oliva Familia Zuccardi Coratina',
            'deposito' => 'Florida',
            'cantidad' => 6,
            'modo'     => 'fijar',
        ]);

        $this->assertTrue(!empty($respuesta['ok']), 'Con una sola sucursal no tiene que cortar por el depósito: ' . json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(0, (int) $accion->datos['esperado']['address_id'], 'Es la tarjeta del stock total.');
        $this->assertSame('Stock del artículo', $accion->presentacion['titulo']);
        $this->assertStringNotContainsString('Florida', json_encode($accion->presentacion));

        $this->confirmar($conversation, $assistant, $respuesta['tarjeta_id'])->assertStatus(200);

        $this->assertEqualsWithDelta(6, (float) DB::table('articles')->where('id', $coratina->id)->value('stock'), self::DELTA);
    }

    /**
     * El alta de bebidas en cajas, con una sola sucursal, no pide el depósito: va a la única.
     *
     * @test
     */
    public function el_alta_de_bebidas_en_cajas_con_una_sola_sucursal_no_pide_deposito()
    {
        $unica = $this->unica();

        $vinoteca = ExtencionEmpresa::firstOrCreate(['slug' => 'vinoteca'], ['name' => 'vinoteca']);
        $this->dueno->extencions()->syncWithoutDetaching([$vinoteca->id]);
        User::where('id', $this->dueno->id)->update(['agente_alta_bebidas' => 1, 'listas_de_precio' => 0]);
        $this->dueno = User::find($this->dueno->id);
        $this->dueno->load('extencions');

        list($conversation, $assistant) = $this->conversacion('Dalo de alta con 2 cajas de 6');

        $datos = [
            'name'            => 'zz-p108 Malbec de alta',
            'cost'            => 5000,
            'percentage_gain' => 30,
            'presentacion'    => 1,
            'aplicar_iva'     => false,
            'provider_id'     => DB::table('providers')->insertGetId(['name' => 'zz-p108 Proveedor', 'user_id' => $this->dueno->id]),
            'category_id'     => DB::table('categories')->insertGetId(['name' => 'zz-p108 Vinos', 'user_id' => $this->dueno->id]),
            'bodega_id'       => DB::table('bodegas')->insertGetId(['name' => 'zz-p108 Bodega', 'user_id' => $this->dueno->id]),
            'cepa_id'         => DB::table('cepas')->insertGetId(['name' => 'zz-p108 Malbec', 'user_id' => $this->dueno->id]),
            'bebida'          => ['es_vino' => true, 'botellas_por_caja' => 6, 'cajas' => 2, 'cajas_confirmadas' => true, 'sin_imagen' => true],
        ];

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_alta', ['entidad' => 'article', 'datos' => $datos]);

        $this->assertTrue(!empty($respuesta['ok']), 'Con una sola sucursal no tiene que pedir el depósito: ' . json_encode($respuesta));

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame((int) $unica->id, (int) $accion->datos['extras']['stock_inicial']['address_id']);
        $this->assertEquals(12, $accion->datos['extras']['stock_inicial']['cantidad']);
    }

    /**
     * Con dos sucursales sigue mandando el nombre: uno que no existe es error con las opciones.
     *
     * @test
     */
    public function con_dos_sucursales_un_deposito_que_no_existe_sigue_siendo_error()
    {
        $this->unica();
        Address::create(['street' => 'zz-p108 Segunda', 'user_id' => $this->dueno->id]);

        Article::create(['name' => 'zz-p108 Aceite dos', 'user_id' => $this->dueno->id, 'status' => 'active', 'final_price' => 100, 'stock' => 5, 'iva_id' => 2]);

        list($conversation, $assistant) = $this->conversacion('Sumale 10');

        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_stock_en_deposito', [
            'articulo' => 'zz-p108 Aceite dos',
            'deposito' => 'Florida',
            'cantidad' => 10,
            'modo'     => 'sumar',
        ]);

        $this->assertFalse($respuesta['ok']);
        $this->assertStringContainsString('No encontré ningún depósito', $respuesta['error']);
    }

    /**
     * La descripción de la herramienta ya no le sugiere un nombre concreto de depósito.
     *
     * @test
     */
    public function la_descripcion_no_trae_nombres_de_deposito_de_ejemplo()
    {
        $definiciones = [];

        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            $definiciones[$definicion['name']] = $definicion;
        }

        $texto = json_encode($definiciones['proponer_stock_en_deposito'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Florida', $texto);
        $this->assertStringContainsString('una sola sucursal', $texto);
    }
}
