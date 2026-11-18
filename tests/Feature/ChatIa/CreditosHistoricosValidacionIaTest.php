<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPagoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\Provider;
use App\Models\Sale;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/** Ticket 145: una validación del historial debe conservarse al confirmar una tarjeta real. */
class CreditosHistoricosValidacionIaTest extends EmpresaTestCase
{
    use EscenariosDePlata;

    private $duenio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->duenio = $this->app['auth']->user();
        $extension = ExtencionEmpresa::where('slug', 'agentes_ia')->first();
        if (!$extension) {
            $extension = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }
        $this->duenio->extencions()->syncWithoutDetaching([$extension->id]);
        $this->duenio->load('extencions');
    }

    /** El historial previo es deliberadamente viejo y ambiguo; no se fabrica por el endpoint nuevo. */
    private function cuenta_con_historial_ambiguo($tipo, $credito = 800)
    {
        $clase = $tipo === 'client' ? Client::class : Provider::class;
        $titular = $clase::create(['name' => 'zz-ticket145 titular '.$tipo, 'user_id' => $this->duenio->id,
            'saldo_pesos' => 1000 - $credito]);
        $cuenta = CreditAccount::create(['model_name' => $tipo, 'model_id' => $titular->id,
            'user_id' => $this->duenio->id, 'moneda_id' => 1, 'saldo' => 1000 - $credito]);
        $comun = ['credit_account_id' => $cuenta->id, $tipo.'_id' => $titular->id,
            'user_id' => $this->duenio->id, 'moneda_id' => 1, 'status' => 'sin_pagar',
            'pagandose' => 0, 'is_provisorio' => 0];
        CurrentAcount::create(array_merge($comun, ['detalle' => 'Débito histórico', 'debe' => 1000,
            'saldo' => 1000, 'created_at' => '2026-01-01 10:00:00']));
        CurrentAcount::create(array_merge($comun, ['detalle' => 'Crédito histórico', 'debe' => -$credito,
            'saldo' => 1000 - $credito, 'created_at' => '2026-07-01 10:00:00']));
        $ambiguo = CurrentAcount::create(array_merge($comun, ['detalle' => 'Importes incompatibles previos',
            'debe' => 0, 'haber' => 25, 'saldo' => 1000 - $credito, 'created_at' => '2026-08-01 10:00:00']));
        return [$cuenta, $titular, $ambiguo];
    }

    private function conversacion($pedido)
    {
        $conversacion = AiConversation::create(['user_id' => $this->duenio->id, 'auth_user_id' => $this->duenio->id]);
        AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'user',
            'estado' => 'listo', 'contenido' => $pedido]);
        $mensaje = AiMessage::create(['ai_conversation_id' => $conversacion->id, 'rol' => 'assistant',
            'estado' => 'pendiente', 'acciones_habilitadas' => true]);
        return [$conversacion, $mensaje];
    }

    private function foto($cuenta, $titular, $caja = null)
    {
        $movimientos = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)->orderBy('id')->get();
        $foto = ['cuenta' => (array) DB::table('credit_accounts')->where('id', $cuenta->id)->first(),
            'titular' => (array) DB::table($cuenta->model_name === 'client' ? 'clients' : 'providers')->where('id', $titular->id)->first(),
            'movimientos' => $movimientos->toArray(),
            'imputaciones' => DB::table('pagado_por')->whereIn('debe_id', $movimientos->pluck('id'))->orderBy('id')->get()->toArray(),
            'cantidad_ventas' => Sale::withTrashed()->where('user_id', $this->duenio->id)->count()];
        if ($caja) {
            $foto['caja'] = (array) DB::table('cajas')->where('id', $caja->id)->first();
            $foto['movimientos_caja'] = DB::table('movimiento_cajas')->where('caja_id', $caja->id)->orderBy('id')->get()->toArray();
        }
        return $foto;
    }

    private function confirmar_y_comprobar_rechazo($conversacion, $mensaje, $tarjeta_id, $ambiguo)
    {
        $mensaje->estado = 'listo';
        $mensaje->contenido = 'Te dejé la tarjeta para confirmar.';
        $mensaje->save();
        $respuesta = $this->postJson('api/ai-conversations/'.$conversacion->id.'/acciones/'.$tarjeta_id.'/confirmar');
        $respuesta->assertStatus(422);
        $texto = $respuesta->json('message');
        $this->assertStringContainsString('movimiento #'.$ambiguo->id, $texto);
        $this->assertStringContainsString('Revisá', $texto);
        $this->assertStringNotContainsString('Probá de nuevo en unos segundos', $texto);
        $tarjeta = AiMessageAction::findOrFail($tarjeta_id);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado);
        $this->assertSame($texto, $tarjeta->error_mensaje);
        $this->assertNull($tarjeta->resultado);
    }

    public function casos_de_pago()
    {
        return [['client', false], ['client', true], ['provider', false], ['provider', true]];
    }

    /** @dataProvider casos_de_pago */
    public function test_pago_por_tarjeta_muestra_la_validacion_y_revierte_plata($tipo, $retroactivo)
    {
        list($cuenta, $titular, $ambiguo) = $this->cuenta_con_historial_ambiguo($tipo);
        $caja = $this->resolver_caja_por_nombre(TestingFerreteriaSeeder::CAJA_EFECTIVO);
        $this->asegurar_caja_abierta($caja);
        $metodo = $this->resolver_metodo_pago_por_nombre(TestingFerreteriaSeeder::PAGO_EFECTIVO);
        list($conversacion, $mensaje) = $this->conversacion('Registrá un pago de 50 pesos para '.$titular->name);
        $propuesta = PropuestaPagoIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversacion), $mensaje, [
            'tipo' => $tipo === 'client' ? 'cliente' : 'proveedor', 'id' => $titular->id,
            'moneda' => 'pesos', 'monto' => 50,
            'fecha' => Carbon::today()->subDays($retroactivo ? 1 : 0)->format('Y-m-d'),
            'pagos' => [['metodo_de_pago_id' => $metodo->id, 'caja_id' => $caja->id]],
        ]);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $antes = $this->foto($cuenta, $titular, $caja);
        $this->confirmar_y_comprobar_rechazo($conversacion, $mensaje, $propuesta['tarjeta_id'], $ambiguo);
        $this->assertEquals($antes, $this->foto($cuenta, $titular, $caja), 'El rechazo no puede dejar el pago, sus imputaciones ni un movimiento de caja.');
    }

    public function test_venta_por_tarjeta_en_dos_etapas_devuelve_el_motivo_y_revierte_venta_y_stock()
    {
        // El saldo previo a favor fuerza la imputación de la venta nueva por el camino real.
        list($cuenta, $titular, $ambiguo) = $this->cuenta_con_historial_ambiguo('client', 2000);
        $articulo = Article::create(['name' => 'zz-ticket145 martillo', 'user_id' => $this->duenio->id,
            'status' => 'active', 'final_price' => 2000, 'cost' => 1000, 'stock' => 10, 'iva_id' => 2]);
        list($conversacion, $mensaje) = $this->conversacion('Vendele un zz-ticket145 martillo a '.$titular->name.' a cuenta corriente');
        $propuesta = PropuestaVentaIaHelper::proponer(ContextoDeCargaIa::de_la_conversacion($conversacion), $mensaje, [
            'items' => [['articulo_id' => $articulo->id, 'cantidad' => 1]],
            'cliente' => $titular->name, 'cobro' => 'cuenta_corriente',
        ]);
        $this->assertTrue($propuesta['ok'], json_encode($propuesta));
        $antes = $this->foto($cuenta, $titular);
        $renglones = DB::table('article_sale')->where('article_id', $articulo->id)->count();
        $stock = $articulo->fresh()->stock;
        $libro_stock = DB::table('stock_movements')->where('article_id', $articulo->id)->orderBy('id')->get()->toArray();
        $this->confirmar_y_comprobar_rechazo($conversacion, $mensaje, $propuesta['tarjeta_id'], $ambiguo);
        $this->assertEquals($antes, $this->foto($cuenta, $titular), 'La reserva de la tarjeta no puede dejar una venta ni modificar la cuenta al rechazarla.');
        $this->assertEquals($stock, $articulo->fresh()->stock);
        $this->assertEquals($libro_stock, DB::table('stock_movements')->where('article_id', $articulo->id)->orderBy('id')->get()->toArray());
        $this->assertSame($renglones, DB::table('article_sale')->where('article_id', $articulo->id)->count());
    }
}
