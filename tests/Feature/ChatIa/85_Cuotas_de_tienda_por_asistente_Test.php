<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ArchivosAdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaCuotasTiendaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Cuota;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExtencionEmpresa;
use App\Models\PaymentMethod;
use App\Models\PaymentMethodType;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @group chat-ia */
class Cuotas_de_tienda_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    protected $dueno;
    protected $conversation;
    protected $mensaje;
    protected $contexto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringStartsWith('empresa_testing', DB::connection()->getDatabaseName());
        config(['services.anthropic.api_key' => null]);
        $this->dueno = User::create(['name' => 'Cuotas tienda', 'email' => uniqid('cuotas') . '@test.local', 'password' => 'sin-uso']);
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }
        $this->dueno->extencions()->attach($extension->id);
        $this->actingAs($this->dueno, 'web');
        $this->nueva_conversacion();
    }

    protected function nueva_conversacion()
    {
        $this->conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);
        $this->mensaje = AiMessage::create(['ai_conversation_id' => $this->conversation->id, 'rol' => 'assistant', 'estado' => 'listo', 'contenido' => 'Revisá estas cuotas.', 'acciones_habilitadas' => true]);
        $this->contexto = ContextoDeCargaIa::de_la_conversacion($this->conversation);
    }

    protected function proponer(array $cuotas, array $extras = [])
    {
        $resultado = HerramientasDeCarga::ejecutar('proponer_cuotas_tienda', array_merge(['cuotas' => $cuotas], $extras), $this->conversation, $this->mensaje);
        $this->assertFalse($resultado['is_error'], $resultado['content']);
        return json_decode($resultado['content'], true);
    }

    protected function tarjeta()
    {
        return AiMessageAction::where('ai_message_id', $this->mensaje->id)->orderByDesc('id')->firstOrFail();
    }

    protected function confirmar($tarjeta = null)
    {
        $tarjeta = $tarjeta ?: $this->tarjeta();
        return $this->postJson('api/ai-conversations/' . $this->conversation->id . '/acciones/' . $tarjeta->id . '/confirmar');
    }

    protected function metodo_existente()
    {
        $cuenta = CurrentAcountPaymentMethod::create(['name' => 'Mercado Pago', 'c_a_payment_method_type_id' => null]);
        $tipo = PaymentMethodType::firstOrCreate(['name' => 'MercadoPago']);
        $tienda = PaymentMethod::create(['name' => 'MercadoPago', 'payment_method_type_id' => $tipo->id,
            'user_id' => $this->dueno->id, 'discount' => 5, 'public_key' => 'publica-test', 'access_token' => 'secreto-test', 'current_acount_payment_method_id' => $cuenta->id]);
        return [$tienda, $cuenta];
    }

    public function test_coeficientes_del_excel_se_cargan_en_una_tarjeta_y_se_confirman_por_endpoint()
    {
        list($tienda, $cuenta) = $this->metodo_existente();
        $filas = [];
        foreach ([2 => 1.0256, 3 => 1.0417, 6 => 1.087, 9 => 1.1364, 12 => 1.1862, 18 => 1.3004, 24 => 1.3966] as $n => $c) {
            $filas[] = ['cantidad_cuotas' => $n, 'coeficiente' => $c];
        }
        $r = $this->proponer($filas);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(0, Cuota::where('user_id', $this->dueno->id)->count());
        $tarjeta = $this->tarjeta();
        $this->assertCount(10, $tarjeta->presentacion['renglones']);
        $this->assertNotContains(AiMessageAction::TIPO_CUOTAS_TIENDA, HerramientasDeCarga::AUTO_CONFIRMABLES_DIRECTO);
        $this->assertContains(AiMessageAction::TIPO_CUOTAS_TIENDA, HerramientasDeCarga::NUNCA_AUTO_CONFIRMABLES);
        $this->confirmar()->assertOk();
        foreach ([2 => 2.56, 3 => 4.17, 6 => 8.70, 9 => 13.64, 12 => 18.62, 18 => 30.04, 24 => 39.66] as $n => $r) {
            $this->assertDatabaseHas('cuotas', ['user_id' => $this->dueno->id, 'payment_method_id' => $cuenta->id, 'cantidad_cuotas' => $n, 'recargo' => $r, 'descuento' => 0]);
        }
        $tienda->refresh();
        $this->assertSame('secreto-test', $tienda->access_token);
        $this->assertSame('publica-test', $tienda->public_key);
        $this->assertEquals(5, $tienda->discount);
        $this->assertEquals($cuenta->id, $tienda->current_acount_payment_method_id);
        $this->confirmar($tarjeta)->assertStatus(409);
        $this->assertSame(7, Cuota::where('user_id', $this->dueno->id)->count());
        $this->nueva_conversacion();
        $this->proponer($filas);
        $this->confirmar()->assertOk();
        $this->assertSame(7, Cuota::where('user_id', $this->dueno->id)->count());
    }

    public function test_crea_ambos_metodos_cuando_no_existen()
    {
        // El catálogo de CC es global en este sistema, no tiene user_id.
        CurrentAcountPaymentMethod::query()->where('name', 'Mercado Pago')->update(['name' => 'Otro medio de prueba']);
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => '4,17']]);
        $this->confirmar()->assertOk();
        $tienda = PaymentMethod::where('user_id', $this->dueno->id)->firstOrFail();
        $this->assertSame('MercadoPago', $tienda->payment_method_type->name);
        $this->assertSame('Mercado Pago', CurrentAcountPaymentMethod::findOrFail($tienda->current_acount_payment_method_id)->name);
        $this->assertDatabaseHas('cuotas', ['user_id' => $this->dueno->id, 'payment_method_id' => $tienda->current_acount_payment_method_id, 'recargo' => 4.17]);
    }

    public function test_actualiza_la_cantidad_pedida_y_conserva_genericas_ajenas_y_otras_cantidades()
    {
        list($tienda, $cuenta) = $this->metodo_existente();
        $previa = Cuota::create(['user_id' => $this->dueno->id, 'payment_method_id' => $cuenta->id, 'cantidad_cuotas' => 3, 'recargo' => 10, 'descuento' => 1]);
        foreach ([['user_id' => $this->dueno->id, 'payment_method_id' => null, 'cantidad_cuotas' => 3], ['user_id' => $this->dueno->id, 'payment_method_id' => $cuenta->id, 'cantidad_cuotas' => 12], ['user_id' => 999999, 'payment_method_id' => $cuenta->id, 'cantidad_cuotas' => 3]] as $datos) {
            Cuota::create(array_merge($datos, ['recargo' => 20, 'descuento' => 0]));
        }
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 4.17]]);
        $this->assertStringContainsString('9,00% → 4,17%', json_encode($this->tarjeta()->presentacion, JSON_UNESCAPED_UNICODE));
        $this->confirmar()->assertOk();
        $this->assertEquals(4.17, $previa->fresh()->recargo);
        $this->assertEquals(0, $previa->fresh()->descuento);
        $this->assertSame(3, Cuota::where('recargo', 20)->where(function ($q) use ($cuenta) { $q->where('user_id', $this->dueno->id)->orWhere('user_id', 999999); })->count());
    }

    public function test_rechaza_duplicadas_invalidas_y_coeficientes_que_no_coinciden()
    {
        foreach ([[], [['cantidad_cuotas' => 3, 'coeficiente' => 0.99]], [['cantidad_cuotas' => 3, 'recargo' => -1]], [['cantidad_cuotas' => 37, 'recargo' => 5]], [['cantidad_cuotas' => 3, 'coeficiente' => 1.05, 'recargo' => 10]], [['cantidad_cuotas' => 3, 'recargo' => '1.000,00']], [['cantidad_cuotas' => 3, 'recargo' => true]], [['cantidad_cuotas' => [], 'recargo' => 1]], [['cantidad_cuotas' => 3, 'recargo' => 1], ['cantidad_cuotas' => 3, 'recargo' => 2]]] as $filas) {
            $r = $this->proponer($filas);
            $this->assertFalse($r['ok']);
            $this->assertNotEmpty($r['error']);
        }
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversation->id)->count());
    }

    public function test_rechaza_reglas_duplicadas_y_cambio_de_estado_entre_propuesta_y_confirmacion()
    {
        list($tienda, $cuenta) = $this->metodo_existente();
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $primera = $this->tarjeta();
        // Otra conversación confirmó la misma cuota antes que ésta.
        $this->nueva_conversacion();
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $this->confirmar()->assertOk();
        $this->postJson('api/ai-conversations/' . $primera->ai_conversation_id . '/acciones/' . $primera->id . '/confirmar')->assertStatus(422);
        $this->assertSame(1, Cuota::where('user_id', $this->dueno->id)->count());
        Cuota::create(['user_id' => $this->dueno->id, 'payment_method_id' => $cuenta->id, 'cantidad_cuotas' => 3, 'recargo' => 6]);
        $this->assertFalse($this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]])['ok']);
    }

    public function test_no_puede_proponer_un_empleado_sin_permiso_ni_metodo_de_otro_dueno()
    {
        list($tienda) = $this->metodo_existente();
        $ajeno = User::create(['name' => 'Otro dueño', 'email' => uniqid('otro') . '@test.local', 'password' => 'sin-uso']);
        $tienda->update(['user_id' => $ajeno->id]);
        $this->assertFalse($this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]], ['metodo' => (int) $tienda->id])['ok']);
        $empleado = User::create(['name' => 'Sin permiso', 'email' => uniqid('empleado') . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->dueno->id]);
        $this->conversation->update(['auth_user_id' => $empleado->id]);
        $this->assertFalse($this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]])['ok']);
    }

    public function test_esquema_ausente_corta_sin_crear_nada()
    {
        // Schema de Laravel 8 devuelve un objeto como accessor y no admite shouldReceive().
        $db = app('db');
        $builder = \Mockery::mock();
        $builder->shouldReceive('hasColumns')->once()->andReturn(false);
        $conexion = \Mockery::mock();
        $conexion->shouldReceive('getSchemaBuilder')->andReturn($builder);
        $manager = \Mockery::mock();
        $manager->shouldReceive('connection')->andReturn($conexion);
        app()->instance('db', $manager);
        try {
            $r = PropuestaCuotasTiendaIaHelper::proponer($this->contexto, $this->mensaje, ['cuotas' => [['cantidad_cuotas' => 3, 'recargo' => 5]]]);
            $this->assertFalse($r['ok']);
        } finally {
            app()->instance('db', $db);
        }
        $this->assertSame(0, PaymentMethod::where('user_id', $this->dueno->id)->count());
    }
    public function test_reusa_cuenta_corriente_sin_vinculo_y_metodo_tienda_ambiguo_se_rechaza()
    {
        list($tienda, $cuenta) = $this->metodo_existente();
        $tienda->update(['current_acount_payment_method_id' => null]);
        // Un nombre único, sin depender del catálogo sembrado de la base.
        CurrentAcountPaymentMethod::where('id', '<>', $cuenta->id)->where('name', 'Mercado Pago')->update(['name' => 'Otro de prueba']);
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $this->confirmar()->assertOk();
        $this->assertEquals($cuenta->id, $tienda->fresh()->current_acount_payment_method_id);
        PaymentMethod::create(['name' => 'Mercado Pago', 'user_id' => $this->dueno->id, 'payment_method_type_id' => $tienda->payment_method_type_id]);
        $r = $this->proponer([['cantidad_cuotas' => 6, 'recargo' => 10]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('varios', $r['error']);
    }

    public function test_reverifica_permiso_al_confirmar_y_no_persiste_el_lote()
    {
        $empleado = User::create(['name' => 'Encargado', 'email' => uniqid('encargado') . '@test.local', 'password' => 'sin-uso', 'owner_id' => $this->dueno->id, 'admin_access' => 1]);
        $this->conversation->update(['auth_user_id' => $empleado->id]);
        $this->actingAs($empleado, 'web');
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $empleado->update(['admin_access' => 0]);
        $this->actingAs($empleado->fresh(), 'web');
        $this->confirmar()->assertStatus(403);
        $this->assertSame(0, Cuota::where('user_id', $this->dueno->id)->count());
    }

    public function test_lectura_de_excel_xls_conserva_coeficientes_para_la_herramienta()
    {
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getActiveSheet()->fromArray([
            ['CUOTAS', 'COEFICIENTE'], [2, 1.0256], [3, 1.0417], [6, 1.087], [9, 1.1364], [12, 1.1862], [18, 1.3004], [24, 1.3966],
        ]);
        $ruta = tempnam(sys_get_temp_dir(), 'cuotas-xls');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xls($libro))->save($ruta);
            $metodo = new \ReflectionMethod(ArchivosAdjuntosIaHelper::class, 'texto_de_xls');
            $metodo->setAccessible(true);
            $texto = $metodo->invoke(null, $ruta);
            $this->assertStringContainsString('2 | 1.0256', $texto);
            $this->assertStringContainsString('24 | 1.3966', $texto);
        } finally {
            @unlink($ruta);
            $libro->disconnectWorksheets();
        }
    }

    public function test_reemplazar_tarjeta_descarta_la_anterior_sin_escribir_cuotas()
    {
        $this->metodo_existente();
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $anterior = $this->tarjeta();
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 6]], ['reemplaza_a' => $anterior->id]);
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $anterior->fresh()->estado);
        $this->assertSame(0, Cuota::where('user_id', $this->dueno->id)->count());
        $this->confirmar()->assertOk();
        $this->assertDatabaseHas('cuotas', ['user_id' => $this->dueno->id, 'recargo' => 6]);
        $this->confirmar($anterior)->assertStatus(409);
    }

    public function test_no_pisa_el_vinculo_cambiado_desde_la_pantalla()
    {
        list($tienda) = $this->metodo_existente();
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $otro = CurrentAcountPaymentMethod::create(['name' => 'Otro método de prueba']);
        $tienda->update(['current_acount_payment_method_id' => $otro->id]);
        $this->confirmar()->assertStatus(422);
        $this->assertEquals($otro->id, $tienda->fresh()->current_acount_payment_method_id);
        $this->assertSame(0, Cuota::where('user_id', $this->dueno->id)->count());
    }

    public function test_un_metodo_con_nombre_mercado_pago_y_tipo_distinto_no_se_recicla()
    {
        $tipo = PaymentMethodType::create(['name' => 'Transferencia de prueba']);
        $otro = PaymentMethod::create(['name' => 'Mercado Pago', 'payment_method_type_id' => $tipo->id, 'user_id' => $this->dueno->id]);
        CurrentAcountPaymentMethod::where('name', 'Mercado Pago')->update(['name' => 'Otro de prueba']);
        $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $this->confirmar()->assertOk();
        $this->assertEquals($tipo->id, $otro->fresh()->payment_method_type_id);
        $nuevo = PaymentMethod::where('user_id', $this->dueno->id)->where('id', '<>', $otro->id)->firstOrFail();
        $this->assertSame('MercadoPago', $nuevo->payment_method_type->name);
    }

    public function test_tipo_legacy_por_collation_no_se_recicla_ni_se_modifica()
    {
        PaymentMethodType::where('name', 'MercadoPago')->update(['name' => 'mercadopago']);
        if (!PaymentMethodType::where('name', 'mercadopago')->exists()) {
            PaymentMethodType::create(['name' => 'mercadopago']);
        }
        $r = $this->proponer([['cantidad_cuotas' => 3, 'recargo' => 5]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nombre antiguo', $r['error']);
        $this->assertSame(0, PaymentMethod::where('user_id', $this->dueno->id)->count());
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $this->conversation->id)->count());
    }

    public function test_herramienta_se_agrega_al_final_del_registro_con_despacho_y_esquema()
    {
        $nombres = HerramientasDeCarga::nombres();
        // Fichas verificadas agrega una propuesta AL FINAL, sin mover la de cuotas ni sus campos; los avisos
        // del dueño (C2, 13/11/2026) agregan otra detrás.
        $this->assertSame(['proponer_cuotas_tienda', 'proponer_descripcion_verificada'], array_slice($nombres, -9, 2));
        $this->assertSame(['proponer_avisos_por_whatsapp'], array_slice($nombres, -7, 1));
        $this->assertTrue(HerramientasDeCarga::maneja('proponer_cuotas_tienda'));
        $this->assertContains('proponer_cuotas_tienda', HerramientasDeCarga::nombres(true));
        $definiciones = HerramientasDeCarga::definiciones();
        $de_cuotas = $definiciones[count($definiciones) - 9];
        $this->assertSame(['cuotas'], $de_cuotas['input_schema']['required']);
        $this->assertSame(['cantidad_cuotas'], $de_cuotas['input_schema']['properties']['cuotas']['items']['required']);
        $this->assertSame(36, $de_cuotas['input_schema']['properties']['cuotas']['maxItems']);
        $this->assertTrue(HerramientasDeCarga::es_de_carga('proponer_cuotas_tienda'));
    }

}
