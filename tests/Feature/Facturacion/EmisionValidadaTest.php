<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipWsHelper;
use App\Http\Controllers\Helpers\Afip\FacturaDeVentaHelper;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeAccionesDePantallaIaHelper as Catalogo;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EjecutorAccionesIaHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\Client;
use App\Models\PaisExportacion;
use App\Models\AfipSelectedPaymentMethod;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\ExtencionEmpresa;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/** Ticket #121: endpoint real y propuesta/confirmación reales, con el borde SOAP reemplazado. */
class EmisionValidadaTest extends EmpresaTestCase
{
    protected $dueno;
    protected $emisor;
    protected $tipo;
    protected $transporte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = Auth::user();
        $this->emisor = $this->emisor(7);
        $this->tipo = AfipTipoComprobante::where('codigo', 11)->firstOrFail();
        $this->transporte = new TransporteFiscalSimulado();
        $this->app->instance(TransporteDeFacturaHelper::class, $this->transporte);
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes IA']);
        }
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        Catalogo::olvidar();
        CatalogoDeEscrituraIaHelper::olvidar();
    }

    protected function emisor($punto, $dueno = null)
    {
        return AfipInformation::create([
            'user_id' => $dueno ? $dueno->id : $this->dueno->id,
            'cuit' => '20111111112', 'punto_venta' => $punto, 'razon_social' => 'Emisor de prueba '.$punto,
            'iva_condition_id' => IvaCondition::where('name', 'Monotributista')->firstOrFail()->id,
        ]);
    }

    protected function venta(array $datos = [])
    {
        return Sale::create($datos + [
            'user_id' => $this->dueno->id, 'num' => 91121, 'client_id' => null,
            'total' => 1000, 'sub_total' => 1000, 'moneda_id' => 1, 'descuento' => 0,
            'terminada' => 1, 'is_cerrada' => 0, 'save_current_acount' => 0,
        ]);
    }

    protected function cuerpo($venta, array $cambios = [])
    {
        return $cambios + [
            'sale_id' => $venta->id, 'ventas_afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $this->tipo->id, 'afip_fecha_emision' => date('Y-m-d'),
        ];
    }

    protected function postear($venta, array $cambios = [])
    {
        return $this->postJson('api/afip-ticket', $this->cuerpo($venta, $cambios));
    }

    public function test_emisor_ausente_cero_inexistente_o_malformado_rechaza_sin_registros_ni_red()
    {
        $venta = $this->venta();
        foreach ([null, 0, 999999999, true, [1], '1abc'] as $id) {
            $respuesta = $this->postear($venta, ['ventas_afip_information_id' => $id]);
            $respuesta->assertStatus(422);
            $this->assertNotEmpty($respuesta->json('message'));
            $this->assertSame(0, $venta->afip_tickets()->count());
        }
        $this->assertSame(0, $this->transporte->emisiones);
    }

    /** @dataProvider emisores_del_incidente */
    public function test_reproduccion_segura_del_incidente_sin_emisor($id)
    {
        $venta = $this->venta();
        if ($id !== null && $id > 0) {
            $this->assertFalse(AfipInformation::whereKey($id)->exists(), 'El centinela del rojo seguro no puede ser un emisor existente.');
        }
        $cuerpo = $this->cuerpo($venta);
        if ($id === null) {
            unset($cuerpo['ventas_afip_information_id']);
        } else {
            $cuerpo['ventas_afip_information_id'] = $id;
        }
        $this->postJson('api/afip-ticket', $cuerpo)->assertStatus(422);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function emisores_del_incidente(): array
    {
        return ['ausente' => [null], 'cero' => [0], 'inexistente' => [999999999]];
    }

    public function test_emisor_y_venta_ajenos_rechazan_sin_filtrar_datos()
    {
        $otro = User::forceCreate(['name' => 'Dueño privado ajeno', 'email' => uniqid().'@ejemplo.test', 'password' => 'hash-inutil']);
        $emisor = $this->emisor(98, $otro);
        $venta = $this->venta();
        $r = $this->postear($venta, ['ventas_afip_information_id' => $emisor->id, 'monto_a_facturar' => 5]);
        $r->assertStatus(422);
        $this->assertStringNotContainsString('98', $r->getContent());
        $ajena = $this->venta(['user_id' => $otro->id]);
        $this->postear($ajena)->assertStatus(422);
        $this->assertSame(0, $this->transporte->emisiones);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $ajena->afip_tickets()->count());
    }

    public function test_tipo_fecha_y_configuracion_incompletos_cortan_antes_de_crear_ticket()
    {
        $venta = $this->venta();
        foreach ([['afip_tipo_comprobante_id' => null], ['afip_tipo_comprobante_id' => 999999999], ['afip_fecha_emision' => '2026-02-30']] as $cambio) {
            $this->postear($venta, $cambio)->assertStatus(422);
        }
        $this->emisor->update(['iva_condition_id' => null]);
        $this->postear($venta, ['monto_a_facturar' => 500])->assertStatus(422);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_emisor_valido_emite_un_comprobante_y_el_reintento_completo_no_duplica()
    {
        $venta = $this->venta();
        $this->postear($venta)->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->postear($venta)->assertStatus(422);
        $ticket = $venta->afip_tickets()->firstOrFail();
        $this->assertSame((int) $this->emisor->id, (int) $ticket->afip_information_id);
        $this->assertSame(1, $venta->afip_tickets()->count());
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_rechazo_y_timeout_no_se_anuncian_emitidos_y_timeout_concilia_sin_nuevo_ticket()
    {
        $venta = $this->venta();
        $this->transporte->modo = 'rechazo';
        $this->postear($venta)->assertStatus(201)->assertJsonPath('estado', 'rechazada');
        $otra = $this->venta(['num' => 91122]);
        $this->transporte->modo = 'timeout';
        $this->postear($otra)->assertStatus(201)->assertJsonPath('estado', 'pendiente_verificacion');
        $this->transporte->modo = 'recuperado';
        $this->postear($otra)->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->assertSame(1, $otra->afip_tickets()->count());
        $this->assertSame(2, $this->transporte->emisiones);
        $this->assertSame(1, $this->transporte->consultas);
    }

    public function test_incertidumbre_no_se_esquiva_con_otro_emisor_tipo_fecha_o_importe()
    {
        $venta = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($venta)->assertStatus(201);
        foreach ([['ventas_afip_information_id' => $this->emisor(3)->id], ['afip_fecha_emision' => '2026-11-01'], ['monto_a_facturar' => 500]] as $cambio) {
            $this->postear($venta, $cambio)->assertStatus(422);
        }
        $this->assertSame(1, $venta->afip_tickets()->count());
        $this->assertSame(1, $this->transporte->emisiones);
        $this->assertSame(0, $this->transporte->consultas);
    }

    public function test_venta_consolidada_y_venta_facturada_con_saldo_inconsistente_rechazan()
    {
        $contenedora = $this->venta(['is_consolidacion_facturacion' => 1]);
        $original = $this->venta(['consolidacion_facturacion_id' => $contenedora->id]);
        $this->postear($original)->assertStatus(422);
        $facturada = $this->venta();
        AfipTicket::create(['sale_id' => $facturada->id, 'cae' => '123', 'importe_total' => 1000,
            'afip_information_id' => $this->emisor->id, 'afip_tipo_comprobante_id' => $this->tipo->id, 'moneda_id' => 'PES']);
        $this->postear($facturada)->assertStatus(422);
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_facturacion_parcial_y_refacturacion_con_nota_de_credito_conservan_saldo()
    {
        $venta = $this->venta();
        $this->postear($venta, ['monto_a_facturar' => 600])->assertStatus(201);
        $this->postear($venta, ['monto_a_facturar' => 400])->assertStatus(201);
        $this->postear($venta, ['monto_a_facturar' => 1])->assertStatus(422);
        $facturas = $venta->afip_tickets()->get();
        foreach ($facturas as $factura) {
            AfipTicket::create(['sale_nota_credito_id' => $venta->id, 'sale_afip_ticket_id' => $factura->id,
                'cae' => 'nc-'.$factura->id, 'importe_total' => $factura->importe_total, 'moneda_id' => 'PES']);
        }
        $venta->update(['total_facturado' => 0]);
        $this->postear($venta)->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->assertSame(3, $this->transporte->emisiones);
    }

    public function test_nota_de_credito_parcial_no_habilita_facturar_de_nuevo_el_total()
    {
        $venta = $this->venta();
        $this->postear($venta)->assertStatus(201);
        $factura = $venta->afip_tickets()->firstOrFail();
        AfipTicket::create(['sale_nota_credito_id' => $venta->id, 'sale_afip_ticket_id' => $factura->id,
            'cae' => 'nc-parcial', 'importe_total' => 200, 'moneda_id' => 'PES']);
        $venta->update(['total_facturado' => 800]);
        $this->postear($venta)->assertStatus(422);
        $this->postear($venta, ['monto_a_facturar' => 201])->assertStatus(422);
        $this->postear($venta, ['monto_a_facturar' => 200])->assertStatus(201);
    }

    protected function proponer($venta, array $cambio = [], $canal = 'sistema', $persona = null)
    {
        $persona = $persona ?: $this->dueno;
        $conversacion = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $persona->id]);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user', 'contenido' => 'Facturá esta venta', 'estado' => 'listo', 'canal' => $canal]);
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => $canal]);
        $resultado = (new AsistenteIaService())->execute_tool_calls([[
            'type' => 'tool_use', 'id' => 'toolu_121', 'name' => 'proponer_accion_de_pantalla',
            'input' => ['metodo' => 'POST', 'ruta' => 'api/afip-ticket', 'cuerpo' => $this->cuerpo($venta, $cambio), 'descripcion' => 'Título inventado por el modelo'],
        ]], $conversacion, $mensaje);
        $this->assertArrayNotHasKey('is_error', $resultado[0], $resultado[0]['content']);
        return [$conversacion, $mensaje, json_decode($resultado[0]['content'], true)];
    }

    public function test_dos_emisores_sin_eleccion_preguntan_y_no_crean_tarjeta()
    {
        $segundo = $this->emisor(3);
        foreach (['sistema', 'whatsapp'] as $canal) {
            list($c, $m, $r) = $this->proponer($this->venta(), ['ventas_afip_information_id' => 0], $canal);
            $this->assertFalse($r['ok']);
            $opciones = $r['opciones']['ventas_afip_information_id'];
            $this->assertContains($segundo->id, array_column($opciones, 'id'));
            $this->assertSame(0, AiMessageAction::where('ai_message_id', $m->id)->count());
            $this->assertStringNotContainsString('20111111112', json_encode($opciones));
            $this->assertSame(['id', 'nombre', 'punto_venta'], array_keys($opciones[0]));
        }
    }

    public function test_chat_y_whatsapp_conservan_eleccion_y_resumen_real_antes_de_confirmar()
    {
        $segundo = $this->emisor(3);
        foreach (['sistema', 'whatsapp'] as $canal) {
            $venta = $this->venta();
            list($c, $m, $r) = $this->proponer($venta, ['ventas_afip_information_id' => $segundo->id], $canal);
            $this->assertTrue($r['ok'], json_encode($r));
            $this->assertTrue($r['requiere_confirmacion']);
            $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);
            $this->assertSame((int) $segundo->id, (int) $tarjeta->datos['cuerpo']['ventas_afip_information_id']);
            $this->assertSame('3', $tarjeta->presentacion['renglones'][3]['valor']);
            $this->assertSame('$ 1.000,00', $tarjeta->presentacion['renglones'][6]['valor']);
            $this->assertSame(0, $venta->afip_tickets()->count());
            $m->update(['estado' => 'listo', 'contenido' => 'Revisá los datos de la factura y confirmá.']);
            if ($canal === 'sistema') {
                $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$tarjeta->id.'/confirmar')->assertStatus(200);
            } else {
                AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'user', 'contenido' => 'Sí, confirmo esa factura', 'estado' => 'listo', 'canal' => 'whatsapp']);
                $siguiente = AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => 'whatsapp']);
                $resultado = ConfirmacionPorTextoIaHelper::confirmar($c, $siguiente, $tarjeta->id);
                $this->assertTrue($resultado['ok'], json_encode($resultado));
                $this->assertSame('emitida', $resultado['estado_fiscal']);
            }
            $this->assertSame(1, $venta->afip_tickets()->count());
            $this->assertSame((int) $segundo->id, (int) $venta->afip_tickets()->first()->afip_information_id);
            $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$tarjeta->id.'/confirmar')->assertStatus(409);
        }
        $this->assertSame(2, $this->transporte->emisiones);
    }

    public function test_cambio_entre_propuesta_y_confirmacion_exige_una_tarjeta_nueva()
    {
        $venta = $this->venta();
        list($c, $m, $r) = $this->proponer($venta);
        $this->assertTrue($r['ok'], json_encode($r));
        $m->update(['estado' => 'listo']);
        $venta->update(['total' => 1200, 'sub_total' => 1200]);
        $respuesta = $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$r['tarjeta_id'].'/confirmar');
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('cambiaron', $respuesta->getContent());
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_candado_de_otra_conexion_rechaza_sin_crear_ticket_y_se_libera()
    {
        $venta = $this->venta();
        config(['database.connections.fiscal_121' => config('database.connections.mysql')]);
        $otra = DB::connection('fiscal_121');
        $nombre = 'cc-fiscal:'.sha1(DB::connection()->getDatabaseName().':venta:'.$venta->id);
        $this->assertSame(1, (int) $otra->select('SELECT GET_LOCK(?, 0) AS tomado', [$nombre])[0]->tomado);
        try {
            $this->postear($venta)->assertStatus(422);
            $this->assertSame(0, $venta->afip_tickets()->count());
        } finally {
            $otra->select('SELECT RELEASE_LOCK(?)', [$nombre]);
            DB::disconnect('fiscal_121');
        }
        $this->postear($venta)->assertStatus(201);
    }

    public function test_opcionales_malformados_no_crean_filas_parciales()
    {
        $venta = $this->venta();
        foreach (['forma_de_pago', 'permiso_existente', 'incoterms'] as $campo) {
            $this->postear($venta, [$campo => ['invalido']])->assertStatus(422);
        }
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_perfil_de_vender_puede_facturar_su_venta_y_sin_permiso_no_puede()
    {
        $empleado = User::forceCreate(['name' => 'Empleado prueba fiscal', 'email' => uniqid().'@ejemplo.test',
            'password' => 'hash-inutil', 'owner_id' => $this->dueno->id, 'admin_access' => 0]);
        $permiso = \App\Models\PermissionEmpresa::where('slug', 'sale.store')->first();
        if (!$permiso) {
            $permiso = \App\Models\PermissionEmpresa::forceCreate(['slug' => 'sale.store', 'name' => 'Usar Vender', 'model_name' => 'Ventas']);
        }
        $this->assertNotNull($permiso, 'El permiso real de Vender tiene que estar sembrado.');
        $empleado->permissions()->sync([$permiso->id]);
        $venta = $this->venta(['employee_id' => $empleado->id]);
        $this->actingAs($empleado, 'web');
        $this->postear($venta)->assertStatus(201);
        $otra = $this->venta(['employee_id' => $empleado->id]);
        $empleado->permissions()->detach();
        $empleado->unsetRelation('permissions');
        $this->postear($otra)->assertStatus(422);
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_cambios_de_emisor_tipo_fecha_detalle_o_configuracion_invalidan_confirmacion()
    {
        foreach (['punto', 'tipo', 'fecha', 'detalle', 'metodos'] as $cambio) {
            $venta = $this->venta();
            list($c, $m, $r) = $this->proponer($venta);
            $this->assertTrue($r['ok'], json_encode($r));
            $m->update(['estado' => 'listo']);
            if ($cambio === 'punto') {
                $this->emisor->update(['punto_venta' => 8]);
            } else if ($cambio === 'tipo' || $cambio === 'fecha') {
                $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);
                $datos = $tarjeta->datos;
                $campo = $cambio === 'tipo' ? 'afip_tipo_comprobante_id' : 'afip_fecha_emision';
                $datos['cuerpo'][$campo] = $cambio === 'tipo' ? AfipTipoComprobante::where('codigo', 6)->firstOrFail()->id : '2026-11-01';
                $tarjeta->update(['datos' => $datos]);
            } else if ($cambio === 'detalle') {
                $articulo = Article::where('user_id', $this->dueno->id)->firstOrFail();
                $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => 1000, 'cost' => 500]);
            } else {
                AfipSelectedPaymentMethod::create(['user_id' => $this->dueno->id, 'current_acount_payment_method_id' => 1]);
            }
            $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$r['tarjeta_id'].'/confirmar')->assertStatus(422);
            $this->assertSame(0, $venta->afip_tickets()->count());
            $this->emisor->update(['punto_venta' => 7]);
            AfipSelectedPaymentMethod::where('user_id', $this->dueno->id)->delete();
        }
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_whatsapp_rechazo_y_timeout_replican_estado_real_sin_nota_de_carga_exitosa()
    {
        foreach (['rechazo' => 'rechazada', 'timeout' => 'pendiente_verificacion'] as $modo => $estado) {
            list($c, $m, $r) = $this->proponer($this->venta(), [], 'whatsapp');
            $this->assertTrue($r['ok'], json_encode($r));
            $m->update(['estado' => 'listo', 'contenido' => 'Revisá emisor, punto, fecha, tipo e importe y confirmá la factura.']);
            AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'user', 'contenido' => 'Sí', 'estado' => 'listo', 'canal' => 'whatsapp']);
            $siguiente = AiMessage::create(['ai_conversation_id' => $c->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true, 'canal' => 'whatsapp']);
            $this->transporte->modo = $modo;
            $respuesta = ConfirmacionPorTextoIaHelper::confirmar($c, $siguiente, $r['tarjeta_id']);
            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertSame($estado, $respuesta['estado_fiscal']);
            $this->assertNotSame(ConfirmacionPorTextoIaHelper::NOTA_EJECUTADA, $respuesta['nota']);
            $this->assertStringNotContainsString('autorizada por ARCA', $respuesta['resultado']);
            $respaldo = ConfirmacionDeterministaIaHelper::texto_de_respaldo(['resultado' => $respuesta]);
            $this->assertSame($respuesta['resultado'], $respaldo);
            $metodo = new \ReflectionMethod(ConfirmacionDeterministaIaHelper::class, 'resultado_si_ya_quedo_confirmada');
            $metodo->setAccessible(true);
            $carrera = $metodo->invoke(null, $r['tarjeta_id'], ['ok' => false]);
            $this->assertSame($estado, $carrera['estado_fiscal']);
            $this->assertSame($respuesta['nota'], $carrera['nota']);
        }
    }

    public function test_tarjeta_vieja_sin_firma_no_se_ejecuta()
    {
        $venta = $this->venta();
        list($c, $m, $r) = $this->proponer($venta);
        $tarjeta = AiMessageAction::findOrFail($r['tarjeta_id']);
        $datos = $tarjeta->datos;
        unset($datos['firma_fiscal_ia']);
        $tarjeta->update(['datos' => $datos]);
        $m->update(['estado' => 'listo']);
        $this->postJson('api/ai-conversations/'.$c->id.'/acciones/'.$r['tarjeta_id'].'/confirmar')->assertStatus(422);
        $this->assertSame(0, $this->transporte->emisiones);
    }

    protected function venta_exportacion()
    {
        $pais = PaisExportacion::first();
        if (!$pais) {
            $pais = PaisExportacion::create(['name' => 'País de prueba', 'codigo_afip' => 242]);
        }
        $cliente = Client::create(['user_id' => $this->dueno->id, 'name' => 'Cliente exterior de prueba',
            'cuit' => 'EX12345', 'address' => 'Domicilio exterior', 'pais_exportacion_id' => $pais->id]);
        $venta = $this->venta(['client_id' => $cliente->id]);
        $articulo = Article::where('user_id', $this->dueno->id)->firstOrFail();
        $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => 1000, 'cost' => 500]);
        return $venta;
    }

    public function test_timeout_exportacion_guarda_firma_y_concilia_mismo_intento_con_incoterms()
    {
        $venta = $this->venta_exportacion();
        $datos = ['afip_tipo_comprobante_id' => AfipTipoComprobante::where('codigo', 19)->firstOrFail()->id, 'incoterms' => 'FOB'];
        $this->transporte->modo = 'timeout';
        $this->postear($venta, $datos)->assertStatus(201)->assertJsonPath('estado', 'pendiente_verificacion');
        $ticket = $venta->afip_tickets()->firstOrFail();
        $this->assertNotEmpty($ticket->firma_datos_fiscales);
        $this->transporte->modo = 'recuperado';
        $this->postear($venta, $datos)->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->assertSame(1, $this->transporte->emisiones);
        $this->assertSame(1, $this->transporte->consultas);
        $this->assertSame(1, $venta->afip_tickets()->count());
    }

    public function test_payload_fex_real_usa_la_fecha_confirmada_sin_contactar_arca()
    {
        $venta = $this->venta_exportacion();
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => AfipTipoComprobante::where('codigo', 19)->firstOrFail()->id,
            'afip_fecha_emision' => '2026-11-01', 'punto_venta' => 7, 'cuit_negocio' => $this->emisor->cuit]);
        $helper = (new \ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->sale = $venta;
        $helper->wsfex = new WsfexQueCapturaSinRed();
        try {
            $helper->procesar();
            $this->fail('El doble debe cortar antes de recibir una respuesta fiscal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Corte simulado después de capturar el pedido', $e->getMessage());
        }
        $this->assertSame('20261101', $helper->wsfex->pedido->Cmp->Fecha_cbte);
        $this->assertEqualsWithDelta(1000, $helper->wsfex->pedido->Cmp->Imp_total, 0.01);
    }

    public function test_timeout_parcial_concilia_decimal_y_legacy_sin_firma_exige_revision()
    {
        $venta = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($venta, ['monto_a_facturar' => 500])->assertStatus(201);
        $this->transporte->modo = 'recuperado';
        $this->postear($venta, ['monto_a_facturar' => 500])->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->assertSame(1, $venta->afip_tickets()->count());
        $legacy = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($legacy)->assertStatus(201);
        $legacy->afip_tickets()->firstOrFail()->update(['firma_datos_fiscales' => null]);
        $this->postear($legacy)->assertStatus(422);
        $this->assertSame(2, $this->transporte->emisiones);
        $this->assertSame(1, $this->transporte->consultas);
        $this->assertSame(1, $legacy->afip_tickets()->count());
    }

    public function test_saldo_exportacion_no_se_mezcla_con_pesos_y_moneda_legacy_completa_se_conserva()
    {
        $venta = $this->venta_exportacion();
        $venta->update(['moneda_id' => 2, 'valor_dolar' => 1200, 'total_facturado' => 1000]);
        $tipo_e = AfipTipoComprobante::where('codigo', 19)->firstOrFail();
        $factura = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $tipo_e->id, 'cae' => 'e-121', 'importe_total' => 1000, 'moneda' => 'DOL']);
        $this->postear($venta, ['monto_a_facturar' => 100])->assertStatus(422);
        AfipTicket::create(['sale_afip_ticket_id' => $factura->id, 'sale_nota_credito_id' => $venta->id,
            'cae' => 'nc-e-121', 'importe_total' => 1000, 'moneda' => 'DOL']);
        $venta->update(['total_facturado' => 0]);
        $this->postear($venta, ['afip_tipo_comprobante_id' => $tipo_e->id])->assertStatus(201);
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_nota_ajena_o_sin_vinculo_no_habilita_un_saldo_cero_inconsistente()
    {
        $venta = $this->venta();
        $factura = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $this->tipo->id, 'cae' => 'fiscal121', 'importe_total' => 1000, 'moneda_id' => 'PES']);
        $ajena = $this->venta();
        AfipTicket::create(['sale_afip_ticket_id' => $factura->id, 'sale_nota_credito_id' => $ajena->id,
            'cae' => 'nota-ajena121', 'importe_total' => 1000, 'moneda_id' => 'PES']);
        $this->postear($venta)->assertStatus(422);
        $this->assertSame(1, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_revocar_administracion_invalida_una_tarjeta_fiscal_previa()
    {
        $persona = User::forceCreate(['name' => 'Admin de prueba', 'email' => uniqid().'@ejemplo.test',
            'password' => 'hash-inutil', 'owner_id' => $this->dueno->id, 'admin_access' => 1]);
        $permiso = \App\Models\PermissionEmpresa::where('slug', 'sale.index')->first();
        if (!$permiso) {
            $permiso = \App\Models\PermissionEmpresa::forceCreate(['slug' => 'sale.index', 'name' => 'Ver ventas', 'model_name' => 'Ventas']);
        }
        $this->assertNotNull($permiso);
        $persona->permissions()->sync([$permiso->id]);
        $venta = $this->venta();
        list($c, $m, $r) = $this->proponer($venta, [], 'sistema', $persona);
        $this->assertTrue($r['ok'], json_encode($r));
        $m->update(['estado' => 'listo']);
        $persona->update(['admin_access' => 0]);
        $this->actingAs($persona, 'web');
        $respuesta = EjecutorAccionesIaHelper::confirmar($c, $r['tarjeta_id'], $persona, function () { return 0; });
        $this->assertSame(422, $respuesta['status']);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_el_tope_se_revalida_bajo_candado_aunque_el_controller_no_se_ejecute()
    {
        $venta = $this->venta();
        $this->postear($venta, ['monto_a_facturar' => 1500])->assertStatus(422);
        try {
            (new \App\Http\Controllers\Helpers\Afip\MakeAfipTicket())->make_afip_ticket(
                FacturaDeVentaHelper::datos_del_cuerpo($this->cuerpo($venta, ['monto_a_facturar' => 1500]))
            );
            $this->fail('El guard compartido debe rechazar el exceso aun sin precheck del controller.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('superar', $e->errors()['facturacion'][0]);
        }
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_conciliacion_fex_real_no_adopta_otra_identidad_y_recupera_el_intento_correcto()
    {
        $venta = $this->venta_exportacion();
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => AfipTipoComprobante::where('codigo', 19)->firstOrFail()->id,
            'afip_id' => 121, 'cbte_tipo' => 19, 'cbte_numero' => 121, 'punto_venta' => 7,
            'cuit_negocio' => $this->emisor->cuit]);
        $respuesta = ['Id' => 122, 'Cbte_tipo' => 19, 'Punto_vta' => 7, 'Cbte_nro' => 121,
            'Imp_total' => 1000, 'Resultado' => 'A', 'Cae' => 'cae-fex-121', 'Fch_venc_Cae' => '20261231'];
        $helper = (new \ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->sale = $venta;
        $helper->exigir_identidad_de_intento = true;
        $helper->wsfex = new WsfexQueConsultaSinRed($respuesta);
        $this->assertFalse($helper->consultar_comprobante());
        $this->assertEmpty($ticket->fresh()->cae);
        $this->assertEqualsWithDelta(0, (float) $venta->fresh()->total_facturado, 0.01);
        $respuesta['Id'] = 121;
        $helper->wsfex = new WsfexQueConsultaSinRed($respuesta);
        $helper->consultar_comprobante();
        $this->assertSame('cae-fex-121', $ticket->fresh()->cae);
        $this->assertSame('PES', $ticket->fresh()->moneda);
        $this->assertEqualsWithDelta(1000, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertSame(1, $venta->afip_tickets()->count());
    }

    public function test_respuesta_fex_rechazada_real_no_consume_saldo_y_permite_corregir_el_intento()
    {
        $venta = $this->venta_exportacion();
        $tipo_e = AfipTipoComprobante::where('codigo', 19)->firstOrFail();
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $tipo_e->id, 'cbte_tipo' => 19]);
        $helper = (new \ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->sale = $venta;
        $helper->update_afip_ticket(['result' => (object) ['FEXAuthorizeResult' => (object) [
            'FEXResultAuth' => (object) ['Resultado' => 'R', 'Cbte_tipo' => 19],
        ]]], 'PES', 1);
        $this->assertSame('R', $ticket->fresh()->resultado);
        $this->assertEmpty($ticket->fresh()->cae);
        $this->assertEqualsWithDelta(0, (float) $venta->fresh()->total_facturado, 0.01);
        $this->postear($venta, ['afip_tipo_comprobante_id' => $tipo_e->id])->assertStatus(201)->assertJsonPath('estado', 'emitida');
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_endpoint_revalida_si_el_precio_baja_entre_precheck_y_candado()
    {
        $venta = $this->venta();
        $articulo = Article::where('user_id', $this->dueno->id)->firstOrFail();
        $venta->articles()->attach($articulo->id, ['amount' => 1, 'price' => 1000, 'cost' => 300]);
        $pivot = $venta->articles()->getTable();
        $candado = 'cc-fiscal:'.sha1(DB::connection()->getDatabaseName().':venta:'.$venta->id);
        $cambio = false;
        // Reproduce el estado que otra operación pudo dejar comiteado después del precheck.
        // El test de dos conexiones cubre por separado la exclusión mientras una escritura está en vuelo.
        DB::listen(function ($consulta) use ($venta, $pivot, $candado, &$cambio) {
            if ($cambio || stripos($consulta->sql, 'GET_LOCK') === false || ($consulta->bindings[0] ?? null) !== $candado) {
                return;
            }
            $cambio = true;
            DB::table($pivot)->where('sale_id', $venta->id)->update(['price' => 500]);
            DB::table('sales')->where('id', $venta->id)->update(['total' => 500, 'sub_total' => 500]);
        });
        $this->postear($venta, ['monto_a_facturar' => 1000])->assertStatus(422);
        $this->assertTrue($cambio, 'El estado debe cambiar después del precheck y antes de preparar bajo el candado.');
        $this->assertEqualsWithDelta(500, (float) $venta->fresh()->total, 0.01);
        $this->assertSame(0, $venta->afip_tickets()->count());
        $this->assertSame(0, $this->transporte->emisiones);
    }

    public function test_legacy_incierto_borrado_no_habilita_facturar_borrar_venta_ni_consolidar()
    {
        $cliente = Client::create(['user_id' => $this->dueno->id, 'name' => 'Cliente de prueba de consolidación']);
        $venta = $this->venta(['client_id' => $cliente->id]);
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $this->tipo->id, 'cbte_numero' => 121, 'cbte_tipo' => 11, 'punto_venta' => 7]);
        $ticket->delete();
        $this->postear($venta)->assertStatus(422);
        $this->deleteJson('api/sale/'.$venta->id)->assertStatus(422);
        $ventas_antes = Sale::count();
        $this->postJson('api/sales/consolidar-facturacion', [
            'sale_ids' => [$venta->id], 'client_id' => $cliente->id,
            'afip_information_id' => $this->emisor->id, 'afip_tipo_comprobante_id' => $this->tipo->id,
        ])->assertStatus(422);
        $this->assertSame($ventas_antes, Sale::count());
        $this->assertSame(1, AfipTicket::withTrashed()->where('sale_id', $venta->id)->count());
        $this->assertSame(0, $this->transporte->emisiones);
        $this->assertSame(0, $this->transporte->consultas);
        $this->assertNotSoftDeleted('sales', ['id' => $venta->id]);
    }

    public function test_legacy_rechazado_borrado_permite_reintentar_y_cae_oculto_no_lo_permite()
    {
        $venta = $this->venta();
        $rechazado = AfipTicket::create(['sale_id' => $venta->id, 'cbte_numero' => 121, 'resultado' => 'R']);
        $rechazado->delete();
        $this->postear($venta)->assertStatus(201);
        $otra = $this->venta(['total_facturado' => 1000]);
        $autorizado = AfipTicket::create(['sale_id' => $otra->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $this->tipo->id, 'cae' => 'cae-oculto121', 'importe_total' => 1000, 'moneda_id' => 'PES']);
        $autorizado->delete();
        $this->postear($otra)->assertStatus(422);
        $this->deleteJson('api/sale/'.$otra->id)->assertStatus(422);
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_delete_ticket_bloquea_incierto_permite_rechazo_y_no_filtra_un_ajeno()
    {
        $venta = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($venta)->assertStatus(201);
        $pendiente = $venta->afip_tickets()->firstOrFail();
        $this->deleteJson('api/afip-ticket/'.$pendiente->id)->assertStatus(422);
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $pendiente->id]);
        $otra = $this->venta();
        $this->transporte->modo = 'rechazo';
        $this->postear($otra)->assertStatus(201);
        $rechazo = $otra->afip_tickets()->firstOrFail();
        $this->deleteJson('api/afip-ticket/'.$rechazo->id)->assertStatus(200);
        $this->assertSoftDeleted('afip_tickets', ['id' => $rechazo->id]);
        $dueno_ajeno = User::forceCreate(['name' => 'Privado', 'email' => uniqid().'@ejemplo.test', 'password' => 'hash-inutil']);
        $ajena = $this->venta(['user_id' => $dueno_ajeno->id]);
        $ticket_ajeno = AfipTicket::create(['sale_id' => $ajena->id, 'cae' => 'dato-privado-ajeno']);
        $r = $this->deleteJson('api/afip-ticket/'.$ticket_ajeno->id);
        $r->assertStatus(404);
        $this->assertStringNotContainsString('dato-privado-ajeno', $r->getContent());
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket_ajeno->id)->assertStatus(404);
    }

    public function test_delete_y_consulta_manual_respetan_el_candado_de_emision()
    {
        $venta = $this->venta();
        $ticket = AfipTicket::create(['sale_id' => $venta->id, 'afip_information_id' => $this->emisor->id,
            'afip_tipo_comprobante_id' => $this->tipo->id, 'resultado' => 'R', 'cbte_numero' => 121]);
        config(['database.connections.fiscal_121_manual' => config('database.connections.mysql')]);
        $conexion = DB::connection('fiscal_121_manual');
        $nombre = 'cc-fiscal:'.sha1(DB::connection()->getDatabaseName().':venta:'.$venta->id);
        $this->assertSame(1, (int) $conexion->select('SELECT GET_LOCK(?, 0) AS tomado', [$nombre])[0]->tomado);
        try {
            $this->deleteJson('api/afip-ticket/'.$ticket->id)->assertStatus(422);
            $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(422);
            $this->assertSame(0, $this->transporte->consultas);
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        } finally {
            $conexion->select('SELECT RELEASE_LOCK(?)', [$nombre]);
            DB::disconnect('fiscal_121_manual');
        }
    }

    public function test_delete_relee_cae_si_llega_entre_primera_lectura_y_candado()
    {
        $venta = $this->venta();
        $ticket = AfipTicket::create(['sale_id' => $venta->id]);
        $nombre = 'cc-fiscal:'.sha1(DB::connection()->getDatabaseName().':venta:'.$venta->id);
        $cambio = false;
        DB::listen(function ($consulta) use ($ticket, $nombre, &$cambio) {
            if ($cambio || stripos($consulta->sql, 'GET_LOCK') === false || ($consulta->bindings[0] ?? null) !== $nombre) {
                return;
            }
            $cambio = true;
            DB::table('afip_tickets')->where('id', $ticket->id)->update(['cae' => 'cae-tardio-121']);
        });
        $respuesta = $this->deleteJson('api/afip-ticket/'.$ticket->id);
        $respuesta->assertStatus(422);
        $this->assertTrue($cambio);
        $this->assertStringContainsString('CAE', $respuesta->getContent());
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
    }

    public function test_consulta_manual_con_cae_no_se_omite_y_no_duplica_saldo()
    {
        $venta = $this->venta();
        $this->postear($venta)->assertStatus(201);
        $ticket = $venta->afip_tickets()->firstOrFail();
        $ticket->update(['importe_iva' => null]);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->assertSame(2, $this->transporte->consultas);
        $this->assertEqualsWithDelta(1000, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertEqualsWithDelta(0, (float) $ticket->fresh()->importe_iva, 0.01);
        $this->assertSame(1, $venta->afip_tickets()->count());
    }

    public function test_endpoint_manual_recupera_timeout_fex_firmado_y_solo_suma_una_vez()
    {
        $venta = $this->venta_exportacion();
        $this->transporte->modo = 'timeout';
        $this->postear($venta, ['afip_tipo_comprobante_id' => AfipTipoComprobante::where('codigo', 19)->firstOrFail()->id])->assertStatus(201);
        $ticket = $venta->afip_tickets()->firstOrFail();
        $ticket->update(['afip_id' => 121]);
        $transporte = new TransporteFexNativoSinRed();
        $this->app->instance(TransporteDeFacturaHelper::class, $transporte);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->assertSame(2, $transporte->consultas);
        $this->assertNotEmpty($ticket->fresh()->cae);
        $this->assertEqualsWithDelta(1000, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertSame(1, $venta->afip_tickets()->count());
        $this->assertSame(1, $this->transporte->emisiones);
    }

    public function test_consulta_manual_reconstruye_firma_del_reparto_normalizado_y_rechaza_cambios()
    {
        $venta = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($venta, ['monto_a_facturar' => 500,
            'importe_personalizado_ivas' => [['key' => '21', 'importe' => '500.000', 'dato_ignorado' => 'no se envía']]])->assertStatus(201);
        $ticket = $venta->afip_tickets()->firstOrFail();
        $this->assertSame([['key' => '21', 'importe' => 500]], json_decode($ticket->importe_personalizado_ivas_json, true));
        $this->transporte->modo = 'recuperado';
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->assertEqualsWithDelta(500, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertSame(2, $this->transporte->consultas);
        $otra = $this->venta();
        $this->transporte->modo = 'timeout';
        $this->postear($otra)->assertStatus(201);
        $pendiente = $otra->afip_tickets()->firstOrFail();
        $this->emisor->update(['punto_venta' => 8]);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$pendiente->id)->assertStatus(422);
        $this->assertSame(2, $this->transporte->consultas, 'El cambio se rechaza antes del transporte.');
        $this->assertSame(1, $otra->afip_tickets()->count());
    }

    public function test_consulta_fex_autorizada_con_nota_de_credito_conserva_importe_y_moneda_originales()
    {
        $venta = $this->venta_exportacion();
        $this->transporte->modo = 'timeout';
        $this->postear($venta, ['afip_tipo_comprobante_id' => AfipTipoComprobante::where('codigo', 19)->firstOrFail()->id])->assertStatus(201);
        $ticket = $venta->afip_tickets()->firstOrFail();
        $ticket->update(['afip_id' => 121]);
        $transporte = new TransporteFexNativoSinRed();
        $this->app->instance(TransporteDeFacturaHelper::class, $transporte);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        AfipTicket::create(['sale_afip_ticket_id' => $ticket->id, 'sale_nota_credito_id' => $venta->id,
            'cae' => 'nc-manual-fex-121', 'importe_total' => 1000, 'moneda' => 'PES']);
        $venta->update(['total_facturado' => 0, 'total' => 800, 'sub_total' => 800, 'moneda_id' => 2, 'valor_dolar' => 1200]);
        $this->getJson('api/afip-ticket/consultar-comprobante/'.$ticket->id)->assertStatus(200);
        $this->assertSame(2, $transporte->consultas);
        $this->assertEqualsWithDelta(0, (float) $venta->fresh()->total_facturado, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $ticket->fresh()->importe_total, 0.01);
        $this->assertSame('PES', $ticket->fresh()->moneda);
    }
}

/** Sólo persistencia simulada: jamás construye un transporte SOAP ni conoce un host fiscal. */
class TransporteFiscalSimulado extends TransporteDeFacturaHelper
{
    public $emisiones = 0;
    public $consultas = 0;
    public $modo = 'ok';

    public function emitir($ticket): void
    {
        $this->emisiones++;
        $ticket->update(['cbte_numero' => $ticket->id, 'cbte_tipo' => $ticket->afip_tipo_comprobante->codigo,
            'imp_total_enviado' => $ticket->facturar_importe_personalizado ?: $ticket->sale->total]);
        if ($this->modo === 'timeout') {
            throw new \RuntimeException('Timeout de prueba, sin salida a la red');
        }
        if ($this->modo === 'rechazo') {
            $ticket->update(['resultado' => 'R']);
            AfipError::create(['sale_id' => $ticket->sale_id, 'afip_ticket_id' => $ticket->id, 'code' => 1, 'message' => 'Rechazo simulado']);
            return;
        }
        $this->autorizar($ticket);
    }

    public function conciliar($ticket): void
    {
        $this->consultas++;
        if ($this->modo === 'recuperado') {
            $this->autorizar($ticket);
        }
    }

    public function consultar($ticket): void
    {
        $this->consultas++;
        if ($ticket->cae) {
            $ticket->update(['importe_iva' => 0]);
        } else if ($this->modo === 'recuperado') {
            $this->autorizar($ticket);
        }
    }

    private function autorizar($ticket): void
    {
        $importe = (float) $ticket->imp_total_enviado;
        $moneda = (int) $ticket->afip_tipo_comprobante->codigo === 19 && (int) $ticket->sale->moneda_id === 2 ? 'DOL' : 'PES';
        $ticket->update(['cae' => 'cae-'.$ticket->id, 'resultado' => 'A', 'importe_total' => $importe, 'importe_iva' => 0, 'moneda_id' => $moneda]);
        AfipWsHelper::update_sale_total_facturado($ticket, $importe);
    }
}

class WsfexQueCapturaSinRed
{
    public $pedido;

    public function FEXGetLast_CMP($datos, $modo)
    {
        return ['hubo_un_error' => false, 'result' => (object) [
            'FEXGetLast_CMPResult' => (object) ['FEXResult_LastCMP' => (object) ['Cbte_nro' => 0]],
        ]];
    }

    public function FEXAuthorize($pedido)
    {
        $this->pedido = $pedido;
        throw new \RuntimeException('Corte simulado después de capturar el pedido');
    }
}

class WsfexQueConsultaSinRed
{
    private $respuesta;

    public function __construct(array $respuesta)
    {
        $this->respuesta = $respuesta;
    }

    public function FEXGetCMP($datos)
    {
        return ['hubo_un_error' => false, 'result' => (object) [
            'FEXGetCMPResult' => (object) ['FEXResultGet' => (object) $this->respuesta],
        ]];
    }
}

/** Corre la política de Transporte y el helper FEX reales; sólo sustituye la construcción de SOAP. */
class TransporteFexNativoSinRed extends TransporteDeFacturaHelper
{
    public $consultas = 0;

    protected function helper_para_consultar($ticket)
    {
        $this->consultas++;
        $helper = (new \ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $ticket;
        $helper->sale = $ticket->sale;
        $helper->wsfex = new WsfexQueConsultaSinRed([
            'Id' => $ticket->afip_id, 'Cbte_tipo' => $ticket->cbte_tipo, 'Punto_vta' => $ticket->punto_venta,
            'Cbte_nro' => $ticket->cbte_numero, 'Imp_total' => $ticket->cae ? (float) $ticket->importe_total : (float) $ticket->sale->total,
            'Resultado' => 'A', 'Cae' => 'cae-fex-manual-'.$ticket->id, 'Fch_venc_Cae' => '20261231',
        ]);
        return $helper;
    }
}
