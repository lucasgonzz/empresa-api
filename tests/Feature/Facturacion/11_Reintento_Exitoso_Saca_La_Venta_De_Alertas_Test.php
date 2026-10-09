<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Http\Controllers\Helpers\Afip\IntentosDeFacturaFallidosHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Article;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\EmpresaTestCase;

/**
 * Misión facturas-reintentadas-salen-de-alertas (9/10/2026) — una venta que se reemitió con éxito
 * seguía en Alertas → "Facturacion" por el intento fallido viejo.
 *
 * ─── El defecto ───────────────────────────────────────────────────────────────────────────────
 *
 * `AfipTicketController::problemas_al_facturar()` lista toda venta con ALGÚN `afip_ticket` sin CAE.
 * Reemitir (`MakeAfipTicket::make_afip_ticket()`) crea siempre un ticket nuevo y no toca el
 * fallido: la venta quedaba facturada **y** en Alertas hasta que alguien usaba el tacho.
 *
 * ─── El criterio (Lucas, (a)+(b)+(c)) ─────────────────────────────────────────────────────────
 *
 * Cuando una factura de la venta queda autorizada, se descartan (borrado suave) los otros tickets
 * de esa venta que se puede PROBAR que nunca se autorizaron: (a) sin número, (b) mismo comprobante
 * que la factura autorizada, (c) `resultado = 'R'`. 🔴 Un fallido con OTRO número y sin rechazo
 * explícito se queda: puede ser una factura autorizada cuya respuesta se perdió (test 4). Ver
 * `IntentosDeFacturaFallidosHelper`.
 *
 * ─── Cómo se prueba ───────────────────────────────────────────────────────────────────────────
 *
 * Sin red, con el mismo patrón que `Sales/29_Consultar_Comprobante_Escribe_El_Iva_Test`:
 * `AfipWsfeHelper` instanciado sin constructor (el constructor abre el webservice y lee el ticket de
 * acceso del disco) y un doble de WSFE que contesta `FECompUltimoAutorizado`, `FECAESolicitar` y
 * `FECompConsultar` con la respuesta transcripta. La emisión entra por `procesar()`, que es lo que
 * llama `AfipWsController::init()`. Cada intento se crea con los mismos campos que
 * `MakeAfipTicket::make_afip_ticket()`, y lo que ve el usuario se mira por el endpoint real
 * `GET api/afip-ticket/problemas-al-facturar`.
 *
 * Contra el código anterior fallan los tests 1, 2, 3, 5 (por su intento de control), 6 y 9
 * —verificado el 9/10/2026 desactivando a mano la limpieza y el guardado de la `R`—, y el 8 porque
 * el comando no existía. El 4 y el 7 son guards puros: pasan en las dos versiones y tienen que
 * seguir pasando.
 *
 * Los tests 10 a 13 cubren la invariante que esta misma limpieza pone en juego: **un comprobante
 * con CAE nunca queda borrado**, ni aunque dos emisiones de la misma venta corran en paralelo. El
 * 14 es el cuarto camino de éxito (Consultar una Factura E).
 *
 * @group facturacion
 * @group afip
 */
class Reintento_Exitoso_Saca_La_Venta_De_Alertas_Test extends EmpresaTestCase
{
    /** Código de ARCA de la Factura A. */
    const FACTURA_A = 1;

    /** Código de ARCA de la Factura B. */
    const FACTURA_B = 6;

    /** Código de ARCA de la Factura de Exportación. */
    const FACTURA_E = 19;

    /**
     * Ids de los artículos creados por este archivo, para borrarlos en el tearDown.
     *
     * @var array<int,int>
     */
    protected $articulos_creados = [];

    /**
     * Precio de la próxima venta. Cada venta lleva un total distinto: `SaleController::store()`
     * descarta una venta idéntica de los últimos 5 segundos (candado antiduplicados, 5/9/2026).
     *
     * @var float
     */
    protected $proximo_precio = 121.00;

    /**
     * Borra los artículos que creó el test antes del rollback de `DatabaseTransactions`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->articulos_creados) >= 1) {
            Article::whereIn('id', $this->articulos_creados)->forceDelete();
        }

        parent::tearDown();
    }

    /**
     * Test 1 — El caso del pedido: el primer intento no consiguió ni el número (falló
     * `FECompUltimoAutorizado`), la venta aparece en Alertas, se reemite con éxito y sale.
     *
     * El intento viejo queda con `deleted_at` (mismo borrado suave que el tacho), la factura nueva
     * vivita y con CAE, y el `afip_error` del intento viejo se conserva como historia.
     *
     * @test
     */
    public function el_reintento_autorizado_saca_la_venta_de_alertas_y_descarta_el_intento_sin_numero()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $fallido = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => null,
        ]);

        $this->assertNull($fallido->cbte_numero, 'El primer intento no tenía que llegar a tener número.');
        $this->assertTrue(empty($fallido->cae));
        $this->assertGreaterThanOrEqual(
            1,
            AfipError::where('afip_ticket_id', $fallido->id)->count(),
            'El primer intento tiene que haber dejado su afip_error; si no, el escenario no es el del pedido.'
        );
        $this->assertContains($venta->id, $this->ventas_en_alertas(), 'Con el intento fallido la venta tiene que estar en Alertas.');

        $reintento = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $this->assertNotEmpty($reintento->cae, 'El reintento tiene que haber quedado autorizado.');
        $this->assertEquals('A', $reintento->resultado);
        $this->assertNull($reintento->deleted_at, 'La factura autorizada no se toca nunca.');

        $this->assertSoftDeleted('afip_tickets', ['id' => $fallido->id]);

        $this->assertNotContains(
            $venta->id,
            $this->ventas_en_alertas(),
            'La venta ya está facturada: no puede seguir en Alertas por un intento que nunca llegó a ARCA.'
        );

        $this->assertGreaterThanOrEqual(
            1,
            AfipError::where('afip_ticket_id', $fallido->id)->count(),
            'El afip_error del intento descartado se conserva: es la historia de por qué falló.'
        );
    }

    /**
     * Test 2 — Un rechazo explícito de ARCA ahora queda asentado (`resultado = 'R'`), y cuando el
     * reintento sale autorizado con el MISMO número la venta deja Alertas (regla b).
     *
     * Un rechazo no consume número: el reintento pide el último autorizado y recibe el mismo N.
     *
     * @test
     */
    public function un_rechazo_queda_con_resultado_r_y_el_reintento_con_el_mismo_numero_lo_supera()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $fallido = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::RECHAZADA,
        ]);

        $this->assertEquals(
            'R',
            $fallido->resultado,
            'Un rechazo explícito de ARCA tiene que quedar asentado: sin la R es indistinguible de un error de red.'
        );
        $this->assertEquals((string) $numero, (string) $fallido->cbte_numero);
        $this->assertTrue(empty($fallido->cae));
        $this->assertContains($venta->id, $this->ventas_en_alertas());

        $reintento = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $this->assertNotEmpty($reintento->cae);
        $this->assertEquals((string) $numero, (string) $reintento->cbte_numero, 'El reintento recibió el mismo número.');

        $this->assertSoftDeleted('afip_tickets', ['id' => $fallido->id]);
        $this->assertEquals(
            IntentosDeFacturaFallidosHelper::MOTIVO_MISMO_NUMERO,
            IntentosDeFacturaFallidosHelper::motivo_de_descarte(AfipTicket::withTrashed()->find($fallido->id), [$reintento]),
            'Con el mismo número la regla que aplica primero es la (b).'
        );
        $this->assertNotContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 3 — Rechazo de una Factura A con número N; la venta se factura después como Factura B
     * (otro tipo, otro número) y sale de Alertas por la regla (c): ARCA ya dijo que la A no.
     *
     * @test
     */
    public function un_rechazo_de_otro_tipo_se_descarta_cuando_la_venta_se_factura_con_otro_comprobante()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $fallido = $this->emitir($this->nuevo_intento($venta, self::FACTURA_A), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::RECHAZADA,
        ]);

        $this->assertEquals('R', $fallido->resultado);
        $this->assertContains($venta->id, $this->ventas_en_alertas());

        $reintento = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero + 49,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $this->assertNotEmpty($reintento->cae);
        $this->assertNotEquals((string) $fallido->cbte_numero, (string) $reintento->cbte_numero);

        $this->assertSoftDeleted('afip_tickets', ['id' => $fallido->id]);
        $this->assertEquals(
            IntentosDeFacturaFallidosHelper::MOTIVO_RECHAZADO,
            IntentosDeFacturaFallidosHelper::motivo_de_descarte(AfipTicket::withTrashed()->find($fallido->id), [$reintento])
        );
        $this->assertNotContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 4 — 🔴 GUARD: un fallido CON número N y SIN rechazo (error de red, `resultado` nulo) no
     * se toca cuando el reintento sale autorizado con N+1.
     *
     * Que el reintento haya recibido N+1 dice que N YA estaba autorizado en ARCA: el primer intento
     * puede ser una factura autorizada cuya respuesta se perdió, o sea una posible factura
     * DUPLICADA. La venta tiene que seguir en Alertas para que alguien apriete Consultar.
     *
     * @test
     */
    public function un_fallido_con_otro_numero_y_sin_rechazo_no_se_toca_y_la_venta_sigue_en_alertas()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $fallido = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo'   => $numero - 1,
            'fecae'    => DobleDeWsfeParaReintentos::ERROR_DE_RED,
            'consulta' => DobleDeWsfeParaReintentos::ERROR_DE_RED,
        ]);

        $this->assertEquals((string) $numero, (string) $fallido->cbte_numero);
        $this->assertNull($fallido->resultado, 'Un error de red no deja resultado: no se sabe qué pasó en ARCA.');
        $this->assertTrue(empty($fallido->cae));

        $reintento = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $this->assertNotEmpty($reintento->cae);
        $this->assertEquals((string) ($numero + 1), (string) $reintento->cbte_numero);

        $this->assertNotSoftDeleted('afip_tickets', ['id' => $fallido->id]);
        $this->assertNull(IntentosDeFacturaFallidosHelper::motivo_de_descarte(AfipTicket::find($fallido->id), [$reintento]));
        $this->assertContains(
            $venta->id,
            $this->ventas_en_alertas(),
            'El fallido puede estar autorizado en ARCA (posible duplicada): la venta tiene que seguir en Alertas.'
        );
    }

    /**
     * Test 5 — GUARD: una nota de crédito sin CAE de la misma venta no se toca, y un ticket con CAE
     * nunca se borra.
     *
     * Las notas de crédito se arman a propósito con todo lo que las haría descartables si fueran
     * facturas (sin número, con `R`, y una hasta con `sale_id`). El ticket con CAE también trae una
     * `R` y no tiene número —una combinación que no debería existir—: lo que lo protege es el CAE.
     * El intento sin número de la factura es el control: ese sí tiene que irse, para que el test
     * pruebe que la limpieza corrió.
     *
     * @test
     */
    public function una_nota_de_credito_sin_cae_y_un_ticket_con_cae_nunca_se_descartan()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $factura_vieja = $this->ticket_directo($venta, self::FACTURA_B, [
            'cbte_numero' => (string) ($numero - 10),
            'cae'         => $this->cae(),
            'resultado'   => 'A',
        ]);

        $con_cae_y_r = $this->ticket_directo($venta, self::FACTURA_B, [
            'cbte_numero' => null,
            'cae'         => $this->cae(),
            'resultado'   => 'R',
        ]);

        $nc_de_la_venta = $this->ticket_directo($venta, self::FACTURA_B, [
            'sale_id'              => null,
            'sale_nota_credito_id' => $venta->id,
            'cbte_numero'          => null,
            'resultado'            => 'R',
        ]);

        $nc_con_sale_id = $this->ticket_directo($venta, self::FACTURA_B, [
            'sale_nota_credito_id' => $venta->id,
            'nota_credito_id'      => 999999999,
            'cbte_numero'          => null,
            'resultado'            => 'R',
        ]);

        $control = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => null,
        ]);

        $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $this->assertSoftDeleted('afip_tickets', ['id' => $control->id]);

        foreach ([$factura_vieja, $con_cae_y_r, $nc_de_la_venta, $nc_con_sale_id] as $intocable) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $intocable->id]);
            $this->assertNull(IntentosDeFacturaFallidosHelper::motivo_de_descarte(AfipTicket::find($intocable->id), [$factura_vieja]));
        }
    }

    /**
     * Test 6 — Consultar: un intento sin número y otro numerado que tiró error de red. Consultar el
     * numerado le da el CAE (estaba autorizado en ARCA) y el sin número se descarta.
     *
     * @test
     */
    public function consultar_el_intento_numerado_le_da_el_cae_y_descarta_el_intento_sin_numero()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $sin_numero = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => null,
        ]);

        $numerado = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo'   => $numero - 1,
            'fecae'    => DobleDeWsfeParaReintentos::ERROR_DE_RED,
            'consulta' => DobleDeWsfeParaReintentos::ERROR_DE_RED,
        ]);

        $this->assertTrue(empty($numerado->cae));
        $this->assertContains($venta->id, $this->ventas_en_alertas());

        $cae = $this->cae();

        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = AfipTicket::find($numerado->id);
        $helper->wsfe = new DobleDeWsfeParaReintentos([
            'consulta'          => DobleDeWsfeParaReintentos::AUTORIZADA,
            'cae'               => $cae,
            'consulta_imp_total' => (float) $numerado->imp_total_enviado,
        ]);

        $helper->consultar_comprobante();

        $this->assertEquals($cae, AfipTicket::find($numerado->id)->cae, 'La consulta tiene que haber adoptado el comprobante.');
        $this->assertSoftDeleted('afip_tickets', ['id' => $sin_numero->id]);
        $this->assertNotContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 7 — GUARD: una venta con varios intentos fallidos y NINGUNA factura autorizada no pierde
     * nada. Sin factura no hay nada que supere a los intentos.
     *
     * @test
     */
    public function sin_ninguna_factura_autorizada_no_se_descarta_nada()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $sin_numero = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => null,
        ]);

        $rechazada_a = $this->emitir($this->nuevo_intento($venta, self::FACTURA_A), [
            'ultimo' => $numero - 1,
            'fecae'  => DobleDeWsfeParaReintentos::RECHAZADA,
        ]);

        $rechazada_b = $this->emitir($this->nuevo_intento($venta, self::FACTURA_B), [
            'ultimo' => $numero + 49,
            'fecae'  => DobleDeWsfeParaReintentos::RECHAZADA,
        ]);

        foreach ([$sin_numero, $rechazada_a, $rechazada_b] as $intento) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $intento->id]);
        }

        $this->assertEquals(0, IntentosDeFacturaFallidosHelper::descartar_superados($rechazada_b), 'Un ticket sin CAE no supera a nadie.');
        $this->assertNull(IntentosDeFacturaFallidosHelper::motivo_de_descarte($sin_numero, [$rechazada_a, $rechazada_b]));
        $this->assertContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 8 — El comando `afip:descartar-intentos-fallidos` para lo que quedó de antes.
     *
     * Los tickets se cargan DIRECTO, como los dejó el código anterior (sin la limpieza en vivo):
     *
     *  - Venta A, con una factura autorizada: un intento sin número (a), uno con el mismo
     *    comprobante (b), uno rechazado de otro tipo (c), uno con otro número sin rechazo (se
     *    queda) y una nota de crédito sin CAE (se queda).
     *  - Venta B, sin ninguna factura autorizada: dos intentos que se quedan.
     *
     * Sin `--aplicar` lista y no escribe; con `--aplicar` para OTRO comercio no toca nada; con
     * `--aplicar` descarta (a)+(b)+(c) y deja el resto; siempre exit 0; la segunda corrida no
     * encuentra nada.
     *
     * @test
     */
    public function el_comando_lista_sin_aplicar_y_descarta_solo_los_probadamente_no_autorizados()
    {
        $venta_a = $this->crear_venta();
        $numero = $this->numero_base($venta_a);

        $autorizada = $this->ticket_directo($venta_a, self::FACTURA_B, [
            'cbte_numero' => (string) $numero,
            'cae'         => $this->cae(),
            'resultado'   => 'A',
        ]);

        $a_sin_numero = $this->ticket_directo($venta_a, self::FACTURA_B, ['cbte_numero' => null]);
        $a_mismo_numero = $this->ticket_directo($venta_a, self::FACTURA_B, ['cbte_numero' => (string) $numero]);
        $a_rechazado = $this->ticket_directo($venta_a, self::FACTURA_A, ['cbte_numero' => (string) ($numero + 7), 'resultado' => 'R']);
        $a_otro_numero = $this->ticket_directo($venta_a, self::FACTURA_B, ['cbte_numero' => (string) ($numero - 1)]);
        $a_nota_de_credito = $this->ticket_directo($venta_a, self::FACTURA_B, [
            'sale_id'              => null,
            'sale_nota_credito_id' => $venta_a->id,
            'cbte_numero'          => null,
        ]);

        $venta_b = $this->crear_venta();

        $b_sin_numero = $this->ticket_directo($venta_b, self::FACTURA_B, ['cbte_numero' => null]);
        $b_rechazado = $this->ticket_directo($venta_b, self::FACTURA_B, ['cbte_numero' => '1', 'resultado' => 'R']);

        $descartables = [$a_sin_numero, $a_mismo_numero, $a_rechazado];
        $se_quedan = [$autorizada, $a_otro_numero, $a_nota_de_credito, $b_sin_numero, $b_rechazado];
        $todos = array_merge($descartables, $se_quedan);

        // 1. Sin --aplicar: lista y no escribe.
        $exit = Artisan::call('afip:descartar-intentos-fallidos', ['user_id' => $this->user_id()]);
        $salida = Artisan::output();

        $this->assertEquals(0, $exit, 'El comando sale siempre con 0. Salida: '.$salida);
        $this->assertStringContainsString('(sin --aplicar: no se escribió nada)', $salida);

        $linea_de_la_venta_a = 'Venta '.$venta_a->id.' (comercio '.$this->user_id().'): ticket #';

        foreach ($descartables as $ticket) {
            $this->assertStringContainsString(
                'Descartable '.$linea_de_la_venta_a.$ticket->id.' ',
                $salida,
                'El listado tiene que nombrar al descartable #'.$ticket->id.'. Salida: '.$salida
            );
        }

        $this->assertStringContainsString(
            'Queda '.$linea_de_la_venta_a.$a_otro_numero->id.' ',
            $salida,
            'El de otro número tiene que listarse como "queda": es el que hay que Consultar.'
        );
        $this->assertStringNotContainsString('ticket #'.$b_sin_numero->id.' ', $salida, 'La venta B no tiene factura autorizada: ni se revisa.');

        foreach ($todos as $ticket) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        }

        // 2. Con --aplicar para OTRO comercio: no toca nada de este.
        $exit = Artisan::call('afip:descartar-intentos-fallidos', ['user_id' => (int) User::max('id') + 1000, '--aplicar' => true]);

        $this->assertEquals(0, $exit);

        foreach ($todos as $ticket) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        }

        // 3. Con --aplicar: (a)+(b)+(c) afuera, el resto intacto.
        $exit = Artisan::call('afip:descartar-intentos-fallidos', ['user_id' => $this->user_id(), '--aplicar' => true]);
        $salida = Artisan::output();

        $this->assertEquals(0, $exit, 'Salida: '.$salida);

        foreach ($descartables as $ticket) {
            $this->assertSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        }

        foreach ($se_quedan as $ticket) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        }

        $alertas = $this->ventas_en_alertas();

        $this->assertContains($venta_a->id, $alertas, 'La venta A sigue en Alertas por el intento de otro número: hay que Consultarlo.');
        $this->assertContains($venta_b->id, $alertas, 'La venta B no está facturada: sigue en Alertas.');

        // 4. Segunda corrida: no encuentra nada que descartar.
        $exit = Artisan::call('afip:descartar-intentos-fallidos', ['user_id' => $this->user_id(), '--aplicar' => true]);
        $salida = Artisan::output();

        $this->assertEquals(0, $exit);
        $this->assertStringContainsString('Descartables: 0 ', $salida, 'Es idempotente. Salida: '.$salida);

        foreach ($se_quedan as $ticket) {
            $this->assertNotSoftDeleted('afip_tickets', ['id' => $ticket->id]);
        }
    }

    /**
     * Test 9 — Factura E (`AfipFexHelper::update_afip_ticket()`, el tercer camino de éxito).
     *
     * La venta trae un intento de E sin número y otro que ARCA rechazó (`resultado = 'R'` sin CAE,
     * como lo deja `AfipFexHelper`; se carga directo porque el repo no tiene transcripta una
     * respuesta real de rechazo de WSFEX y no se inventa una). Cuando la E sale autorizada, se van
     * los dos.
     *
     * @test
     */
    public function la_factura_e_autorizada_tambien_descarta_los_intentos_superados()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $sin_numero = $this->ticket_directo($venta, self::FACTURA_E, ['cbte_numero' => null]);

        $rechazada = $this->ticket_directo($venta, self::FACTURA_E, [
            'cbte_numero' => (string) $numero,
            'resultado'   => 'R',
            'cae'         => '',
        ]);

        $this->assertContains($venta->id, $this->ventas_en_alertas());

        $autorizada = $this->ticket_directo($venta, self::FACTURA_E, ['cbte_numero' => (string) ($numero + 1)]);

        $this->autorizar_factura_e($autorizada, $this->cae());

        $this->assertNotEmpty(AfipTicket::find($autorizada->id)->cae);
        $this->assertSoftDeleted('afip_tickets', ['id' => $sin_numero->id]);
        $this->assertSoftDeleted('afip_tickets', ['id' => $rechazada->id]);
        $this->assertNotContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 10 — 🔴 La carrera de la regla (a), del lado de la emisión: la emisión A queda autorizada
     * y descarta "sin número" al intento B, que en ese momento estaba EN VUELO (recién creado, sin
     * número todavía). B sigue su camino, pide su número y sale autorizado: tiene que terminar vivo.
     *
     * La carrera se arma con el código real: B se lee como lo tiene en memoria su proceso, y el
     * descarte lo hace `descartar_superados()` de una factura autorizada de la misma venta. Después
     * B se emite con esa MISMA instancia, que es lo que pasa en producción.
     *
     * @test
     */
    public function un_intento_en_vuelo_descartado_sin_numero_que_despues_sale_autorizado_queda_vivo()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $en_vuelo = AfipTicket::find($this->nuevo_intento($venta, self::FACTURA_B)->id);

        $autorizada_por_a = $this->ticket_directo($venta, self::FACTURA_B, [
            'cbte_numero' => (string) $numero,
            'cae'         => $this->cae(),
            'resultado'   => 'A',
        ]);

        $this->assertEquals(1, IntentosDeFacturaFallidosHelper::descartar_superados($autorizada_por_a));
        $this->assertSoftDeleted('afip_tickets', ['id' => $en_vuelo->id]);

        $this->emitir_instancia($en_vuelo, [
            'ultimo' => $numero,
            'fecae'  => DobleDeWsfeParaReintentos::AUTORIZADA,
        ]);

        $b = AfipTicket::withTrashed()->find($en_vuelo->id);

        $this->assertNotEmpty($b->cae, 'B tiene que haber salido autorizado; si no, el escenario no prueba nada.');
        $this->assertNull(
            $b->deleted_at,
            'Un comprobante con CAE nunca puede quedar borrado: quedaría fuera del Libro IVA y de los TXT.'
        );
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $autorizada_por_a->id]);
    }

    /**
     * Test 11 — 🔴 La otra mitad: un ticket que recibe su CAE estando borrado se restaura.
     *
     * Acá el borrado ocurre DESPUÉS de numerar, mientras el pedido está en ARCA (el doble lo borra
     * dentro de `FECAESolicitar`), así que lo único que lo puede salvar es
     * `restaurar_si_quedo_borrado()`, que corre apenas se escribe el CAE. Y el guard: un intento
     * descartado SIN CAE no se restaura.
     *
     * @test
     */
    public function un_ticket_que_recibe_el_cae_estando_borrado_se_restaura()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $intento = $this->nuevo_intento($venta, self::FACTURA_B);
        $id = $intento->id;

        $this->emitir($intento, [
            'ultimo'       => $numero - 1,
            'fecae'        => DobleDeWsfeParaReintentos::AUTORIZADA,
            'al_solicitar' => function () use ($id) {
                AfipTicket::where('id', $id)->delete();
            },
        ]);

        $ticket = AfipTicket::withTrashed()->find($id);

        $this->assertNotEmpty($ticket->cae);
        $this->assertNull($ticket->deleted_at, 'Recibió el CAE estando borrado: tenía que restaurarse.');

        // Guard: un descartado sin CAE se queda borrado.
        $descartado = $this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => null]);
        $descartado->delete();

        $this->assertFalse(IntentosDeFacturaFallidosHelper::restaurar_si_quedo_borrado($descartado));
        $this->assertSoftDeleted('afip_tickets', ['id' => $descartado->id]);
    }

    /**
     * Test 12 — La carrera de la regla (a) cuando B NO recibe respuesta: B se descartó sin número
     * mientras estaba en vuelo, pidió su número y ARCA no contestó (error de red, también en la
     * consulta automática). B puede estar autorizado en ARCA con la respuesta perdida: tiene que
     * quedar vivo y la venta en Alertas para Consultarlo. Lo cubre `restaurar_si_va_a_arca()`, que
     * corre apenas B tiene número.
     *
     * @test
     */
    public function un_intento_en_vuelo_descartado_que_despues_tira_error_de_red_queda_visible_en_alertas()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $en_vuelo = AfipTicket::find($this->nuevo_intento($venta, self::FACTURA_B)->id);

        $autorizada_por_a = $this->ticket_directo($venta, self::FACTURA_B, [
            'cbte_numero' => (string) $numero,
            'cae'         => $this->cae(),
            'resultado'   => 'A',
        ]);

        IntentosDeFacturaFallidosHelper::descartar_superados($autorizada_por_a);

        $this->assertSoftDeleted('afip_tickets', ['id' => $en_vuelo->id]);

        $this->emitir_instancia($en_vuelo, [
            'ultimo'   => $numero,
            'fecae'    => DobleDeWsfeParaReintentos::ERROR_DE_RED,
            'consulta' => DobleDeWsfeParaReintentos::ERROR_DE_RED,
        ]);

        $b = AfipTicket::withTrashed()->find($en_vuelo->id);

        $this->assertEquals((string) ($numero + 1), (string) $b->cbte_numero);
        $this->assertNull($b->deleted_at, 'B fue a ARCA y no se sabe qué pasó: no puede quedar escondido.');
        $this->assertContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 13 — 🔴 El borrado es condicional y atómico: `descartar()` no borra un ticket que cambió
     * entre la lectura y el borrado.
     *
     * `descartar_superados()` y el comando leen los intentos y llaman a `descartar()` con la
     * instancia que leyeron. Acá se simula la carrera sin tocar el código: se lee el ticket, otro
     * "proceso" le escribe derecho en la base (el CAE, el número, otro resultado) y recién después
     * se le pasa a `descartar()` la instancia vieja. El `UPDATE` vuelve a exigir el motivo y no
     * encuentra la fila. El último caso es el control: sin cambios, sí borra.
     *
     * @test
     */
    public function descartar_no_borra_un_ticket_que_cambio_entre_la_lectura_y_el_borrado()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        // Ya tiene CAE cuando llega el borrado (regla a). Se escribe SOLO el CAE, para que lo único
        // que frene el borrado sea la condición del CAE y no la del número.
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => null])->id);
        AfipTicket::where('id', $leido->id)->update(['cae' => $this->cae()]);

        $this->assertFalse(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_SIN_NUMERO, 'test'));
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $leido->id]);

        // Ya tiene CAE, leído como rechazado (regla c).
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => (string) ($numero + 4), 'resultado' => 'R'])->id);
        AfipTicket::where('id', $leido->id)->update(['cae' => $this->cae()]);

        $this->assertFalse(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_RECHAZADO, 'test'));
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $leido->id]);

        // Sin CAE, pero ya pidió su número (regla a): va camino a ARCA.
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => null])->id);
        AfipTicket::where('id', $leido->id)->update(['cbte_numero' => (string) ($numero + 5)]);

        $this->assertFalse(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_SIN_NUMERO, 'test'));
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $leido->id]);

        // Sin CAE, pero ya no dice R (regla c).
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => (string) ($numero + 6), 'resultado' => 'R'])->id);
        AfipTicket::where('id', $leido->id)->update(['resultado' => null]);

        $this->assertFalse(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_RECHAZADO, 'test'));
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $leido->id]);

        // Sin CAE, pero su comprobante ya no es el que se comparó (regla b).
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => (string) ($numero + 7)])->id);
        AfipTicket::where('id', $leido->id)->update(['cbte_numero' => (string) ($numero + 8)]);

        $this->assertFalse(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_MISMO_NUMERO, 'test'));
        $this->assertNotSoftDeleted('afip_tickets', ['id' => $leido->id]);

        // Control: sin cambios entre medio, borra.
        $leido = AfipTicket::find($this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => null])->id);

        $this->assertTrue(IntentosDeFacturaFallidosHelper::descartar($leido, IntentosDeFacturaFallidosHelper::MOTIVO_SIN_NUMERO, 'test'));
        $this->assertSoftDeleted('afip_tickets', ['id' => $leido->id]);
    }

    /**
     * Test 14 — El cuarto camino de éxito: Consultar una Factura E
     * (`AfipFexHelper::consultar_comprobante()`) que recupera el CAE descarta el intento sin número
     * de la venta, y restaura el consultado si estaba borrado.
     *
     * @test
     */
    public function consultar_una_factura_e_que_recupera_el_cae_descarta_los_intentos_superados()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $sin_numero = $this->ticket_directo($venta, self::FACTURA_E, ['cbte_numero' => null]);
        $consultada = $this->ticket_directo($venta, self::FACTURA_E, ['cbte_numero' => (string) $numero]);

        // Borrada mientras estaba en vuelo: la consulta que le da el CAE la tiene que restaurar.
        $en_memoria = AfipTicket::find($consultada->id);
        AfipTicket::where('id', $consultada->id)->delete();

        $this->assertContains($venta->id, $this->ventas_en_alertas());

        $cae = $this->cae();

        $helper = (new ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $en_memoria;
        $helper->sale = Sale::find($venta->id);
        $helper->wsfex = new DobleDeWsfexQueConsulta([
            'Id'           => 62,
            'Cbte_tipo'    => self::FACTURA_E,
            'Punto_vta'    => (int) $consultada->punto_venta,
            'Cbte_nro'     => $numero,
            'Imp_total'    => (float) $venta->total,
            'Cae'          => $cae,
            'Fch_venc_Cae' => '20261231',
            'Resultado'    => 'A',
        ]);

        $helper->consultar_comprobante();

        $guardada = AfipTicket::withTrashed()->find($consultada->id);

        $this->assertEquals($cae, $guardada->cae, 'La consulta tiene que haber escrito el CAE.');
        $this->assertNull($guardada->deleted_at, 'Con CAE no puede quedar borrada.');
        $this->assertSoftDeleted('afip_tickets', ['id' => $sin_numero->id]);
        $this->assertNotContains($venta->id, $this->ventas_en_alertas());
    }

    /**
     * Test 15 — El comando, cuando un intento cambia entre el listado y el borrado: lo informa como
     * "no se tocó", lo cuenta aparte y NO lo cuenta como error.
     *
     * La carrera se arma sin tocar el código: apenas el comando lee el lote de intentos (la consulta
     * con el alias `autorizadas`), "otra emisión" le escribe el CAE a uno de ellos directo en la
     * base. El comando sigue con la instancia que leyó, y el `UPDATE` condicional no lo encuentra.
     *
     * @test
     */
    public function el_comando_informa_aparte_el_intento_que_cambio_entre_el_listado_y_el_borrado()
    {
        $venta = $this->crear_venta();
        $numero = $this->numero_base($venta);

        $this->ticket_directo($venta, self::FACTURA_B, [
            'cbte_numero' => (string) $numero,
            'cae'         => $this->cae(),
            'resultado'   => 'A',
        ]);

        $en_vuelo = $this->ticket_directo($venta, self::FACTURA_B, ['cbte_numero' => null]);

        $cae_de_la_otra_emision = $this->cae();
        $ya_escribio = false;

        DB::listen(function ($consulta) use ($en_vuelo, $cae_de_la_otra_emision, &$ya_escribio) {
            if ($ya_escribio || strpos($consulta->sql, '`autorizadas`') === false) {
                return;
            }

            $ya_escribio = true;

            DB::table('afip_tickets')->where('id', $en_vuelo->id)->update(['cae' => $cae_de_la_otra_emision]);
        });

        $exit = Artisan::call('afip:descartar-intentos-fallidos', ['user_id' => $this->user_id(), '--aplicar' => true]);
        $salida = Artisan::output();

        $this->assertTrue($ya_escribio, 'La carrera no se armó: la consulta del comando no pasó por el listener. Salida: '.$salida);
        $this->assertEquals(0, $exit, 'Salida: '.$salida);
        $this->assertStringContainsString('No se tocó el ticket #'.$en_vuelo->id.':', $salida, 'Salida: '.$salida);
        $this->assertStringContainsString('Cambiaron entre medio (no se tocaron): 1.', $salida, 'Salida: '.$salida);
        $this->assertStringContainsString('Sin descartar por error: 0.', $salida, 'Cambiar entre medio no es un error. Salida: '.$salida);
        $this->assertStringNotContainsString('No se pudo descartar', $salida);

        $this->assertNotSoftDeleted('afip_tickets', ['id' => $en_vuelo->id]);
        $this->assertEquals($cae_de_la_otra_emision, AfipTicket::find($en_vuelo->id)->cae);
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Corre la emisión real (`AfipWsfeHelper::procesar()`) de un intento contra el doble de WSFE.
     *
     * @param  \App\Models\AfipTicket $intento
     * @param  array $guion Ver `DobleDeWsfeParaReintentos`.
     * @return \App\Models\AfipTicket El intento recargado de la base (con los borrados).
     */
    protected function emitir($intento, array $guion)
    {
        if (!isset($guion['cae'])) {
            $guion['cae'] = $this->cae();
        }

        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = AfipTicket::find($intento->id);
        $helper->wsfe = new DobleDeWsfeParaReintentos($guion);

        $helper->procesar();

        return AfipTicket::withTrashed()->find($intento->id);
    }

    /**
     * Como `emitir()`, pero con la instancia que el proceso de la emisión ya tiene en memoria, sin
     * releerla: es lo que hace falta para la carrera, porque un ticket borrado no se vuelve a
     * encontrar con `find()` y su proceso igual sigue adelante con él.
     *
     * @param  \App\Models\AfipTicket $en_memoria
     * @param  array $guion Ver `DobleDeWsfeParaReintentos`.
     * @return void
     */
    protected function emitir_instancia($en_memoria, array $guion)
    {
        if (!isset($guion['cae'])) {
            $guion['cae'] = $this->cae();
        }

        $helper = (new ReflectionClass(AfipWsfeHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = $en_memoria;
        $helper->wsfe = new DobleDeWsfeParaReintentos($guion);

        $helper->procesar();
    }

    /**
     * Pasa una Factura E autorizada por `AfipFexHelper::update_afip_ticket()` con la respuesta de
     * WSFEX transcripta (la forma está documentada en el propio AfipFexHelper).
     *
     * @param  \App\Models\AfipTicket $ticket
     * @param  string $cae
     * @return void
     */
    protected function autorizar_factura_e($ticket, $cae)
    {
        $respuesta = [
            'request'  => '<request/>',
            'response' => '<response/>',
            'result'   => (object) [
                'FEXAuthorizeResult' => (object) [
                    'FEXResultAuth' => (object) [
                        'Id'           => 62,
                        'Cuit'         => 20423548984,
                        'Cbte_tipo'    => self::FACTURA_E,
                        'Punto_vta'    => (int) $ticket->punto_venta,
                        'Cbte_nro'     => (int) $ticket->cbte_numero,
                        'Cae'          => $cae,
                        'Fch_venc_Cae' => '20261231',
                        'Fch_cbte'     => '20261009',
                        'Resultado'    => 'A',
                        'Reproceso'    => 'N',
                        'Motivos_Obs'  => '',
                    ],
                ],
            ],
        ];

        $helper = (new ReflectionClass(AfipFexHelper::class))->newInstanceWithoutConstructor();
        $helper->afip_ticket = AfipTicket::find($ticket->id);
        $helper->sale = Sale::find($ticket->sale_id);

        $helper->update_afip_ticket($respuesta, 'PES', 1);
    }

    /**
     * Crea un intento con los MISMOS campos que `MakeAfipTicket::make_afip_ticket()`.
     *
     * @param  \App\Models\Sale $venta
     * @param  int $codigo Código de ARCA del comprobante.
     * @return \App\Models\AfipTicket
     */
    protected function nuevo_intento($venta, $codigo)
    {
        $afip_information = $this->punto_de_venta();
        $sale = Sale::find($venta->id);

        return AfipTicket::create([
            'cuit_negocio'                      => $afip_information->cuit,
            'iva_negocio'                       => $afip_information->iva_condition->name,
            'punto_venta'                       => $afip_information->punto_venta,
            'iva_cliente'                       => !is_null($sale->client) && !is_null($sale->client->iva_condition) ? $sale->client->iva_condition->name : '',
            'sale_id'                           => $sale->id,
            'afip_information_id'               => $afip_information->id,
            'afip_tipo_comprobante_id'          => $this->tipo_de_comprobante($codigo)->id,
            'afip_fecha_emision'                => date('Y-m-d'),
            'facturar_importe_personalizado'    => null,
            'importe_personalizado_ivas_json'   => null,
            'forma_de_pago'                     => null,
            'permiso_existente'                 => 'N',
        ]);
    }

    /**
     * Crea un ticket DIRECTO en la base, como lo dejó el código anterior (para el comando) o con
     * combinaciones que la emisión no produce (para los guards).
     *
     * @param  \App\Models\Sale $venta
     * @param  int $codigo
     * @param  array $campos Lo que el escenario quiere fijar.
     * @return \App\Models\AfipTicket
     */
    protected function ticket_directo($venta, $codigo, array $campos)
    {
        $afip_information = $this->punto_de_venta();

        return AfipTicket::create(array_merge([
            'cuit_negocio'              => $afip_information->cuit,
            'iva_negocio'               => $afip_information->iva_condition->name,
            'punto_venta'               => $afip_information->punto_venta,
            'iva_cliente'               => '',
            'sale_id'                   => $venta->id,
            'afip_information_id'       => $afip_information->id,
            'afip_tipo_comprobante_id'  => $this->tipo_de_comprobante($codigo)->id,
            'afip_fecha_emision'        => date('Y-m-d'),
            'cbte_tipo'                 => (string) $codigo,
            'permiso_existente'         => 'N',
        ], $campos));
    }

    /**
     * Ids de las ventas que hoy muestra Alertas → "Facturacion", por el endpoint real.
     *
     * @return array<int,int>
     */
    protected function ventas_en_alertas()
    {
        $response = $this->getJson('api/afip-ticket/problemas-al-facturar');

        $response->assertStatus(200);

        return array_map('intval', array_column($response->json('models'), 'id'));
    }

    /**
     * El punto de venta del fixture (Responsable inscripto, homologación). Si la base no lo
     * tuviera, se crea dentro de la transacción del test.
     *
     * @return \App\Models\AfipInformation
     */
    protected function punto_de_venta()
    {
        $afip_information = AfipInformation::with('iva_condition')
                                ->where('user_id', $this->user_id())
                                ->where('punto_venta', TestingFerreteriaSeeder::PUNTO_VENTA)
                                ->first();

        if (!is_null($afip_information) && !is_null($afip_information->iva_condition)) {
            return $afip_information;
        }

        $condicion = IvaCondition::firstOrCreate(['name' => 'Responsable inscripto']);

        return AfipInformation::with('iva_condition')->find(AfipInformation::create([
            'user_id'                => $this->user_id(),
            'punto_venta'            => TestingFerreteriaSeeder::PUNTO_VENTA,
            'iva_condition_id'       => $condicion->id,
            'razon_social'           => 'zz Test reintentos',
            'cuit'                   => '20423548984',
            'afip_ticket_production' => 0,
        ])->id);
    }

    /**
     * El tipo de comprobante con ese código (el fixture los siembra; si faltara, se crea).
     *
     * @param  int $codigo
     * @return \App\Models\AfipTipoComprobante
     */
    protected function tipo_de_comprobante($codigo)
    {
        return AfipTipoComprobante::firstOrCreate(['codigo' => $codigo], ['name' => 'Comprobante '.$codigo]);
    }

    /**
     * Número de comprobante base del escenario, distinto por venta: la consulta del test 6 rechaza
     * adoptar un número que otro ticket vivo ya tenga con CAE.
     *
     * @param  \App\Models\Sale $venta
     * @return int
     */
    protected function numero_base($venta)
    {
        return 900000 + (int) $venta->id * 100;
    }

    /**
     * CAE con forma real (14 dígitos) y distinto en cada llamada.
     *
     * @return string
     */
    protected function cae()
    {
        return '7'.str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT).str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Id del usuario dueño del fixture de testing.
     *
     * @return int
     */
    protected function user_id()
    {
        return (int) User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail()->id;
    }

    /**
     * Crea una venta de una sola línea (costo 100) por el endpoint real. Cada una con otro total,
     * por el candado antiduplicados de `SaleController::store()`.
     *
     * @return \App\Models\Sale
     */
    protected function crear_venta()
    {
        $precio = $this->proximo_precio;
        $this->proximo_precio += 10;

        $articulo = Article::create([
            'name'       => 'zz Test reintento de factura '.uniqid(),
            'user_id'    => $this->user_id(),
            'costo_real' => 100,
        ]);

        $this->articulos_creados[] = $articulo->id;

        $response = $this->postJson('api/sale', [
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 1,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $precio,
            'total'                            => $precio,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [[
                'is_article'   => true,
                'id'           => $articulo->id,
                'price_vender' => $precio,
                'amount'       => 1,
                'costo_real'   => 100,
            ]],
        ]);

        if ($response->getStatusCode() !== 201) {
            $this->fail('POST api/sale devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return Sale::find($response->json('model.id'));
    }
}

/**
 * Doble de WSFE que contesta según un guion y no abre ninguna conexión.
 *
 * Guion (todas las claves opcionales):
 *  - `ultimo`: último número autorizado que contesta `FECompUltimoAutorizado`; null = ese pedido
 *    falla por red (el intento queda sin número y nunca llega a `FECAESolicitar`).
 *  - `fecae`: qué contesta `FECAESolicitar` (AUTORIZADA, RECHAZADA o ERROR_DE_RED).
 *  - `consulta`: qué contesta `FECompConsultar` (AUTORIZADA o ERROR_DE_RED).
 *  - `cae`: el CAE que se devuelve cuando autoriza.
 *  - `consulta_imp_total`: el `ImpTotal` que devuelve la consulta (tiene que coincidir con el
 *    `imp_total_enviado` del ticket para que se adopte).
 *  - `al_solicitar`: callable que corre al entrar a `FECAESolicitar`, para simular lo que hace otro
 *    proceso mientras el pedido está en ARCA.
 *
 * Nombre propio para que pueda cargarse en la misma corrida que los dobles de `Tests\Feature\Sales`.
 */
class DobleDeWsfeParaReintentos
{
    const AUTORIZADA = 'autorizada';
    const RECHAZADA = 'rechazada';
    const ERROR_DE_RED = 'error_de_red';

    /** @var array */
    private $guion;

    /**
     * @param  array $guion
     */
    public function __construct(array $guion)
    {
        $this->guion = $guion;
    }

    /**
     * @param  array $pto_vta ['PtoVta' => ..., 'CbteTipo' => ...]
     * @return array
     */
    public function FECompUltimoAutorizado($pto_vta)
    {
        if (!array_key_exists('ultimo', $this->guion) || is_null($this->guion['ultimo'])) {
            return $this->error_de_red();
        }

        return [
            'hubo_un_error' => false,
            'request'       => '<request/>',
            'response'      => '<response/>',
            'result'        => (object) [
                'FECompUltimoAutorizadoResult' => (object) [
                    'PtoVta'   => $pto_vta['PtoVta'],
                    'CbteTipo' => $pto_vta['CbteTipo'],
                    'CbteNro'  => $this->guion['ultimo'],
                ],
            ],
        ];
    }

    /**
     * @param  array $invoice Payload armado por `solicitar_cae()`.
     * @return array
     */
    public function FECAESolicitar($invoice)
    {
        // Lo que hace "otro proceso" mientras el pedido está en ARCA (test 11).
        if (isset($this->guion['al_solicitar'])) {
            call_user_func($this->guion['al_solicitar']);
        }

        $modo = isset($this->guion['fecae']) ? $this->guion['fecae'] : self::ERROR_DE_RED;

        if ($modo == self::ERROR_DE_RED) {
            return $this->error_de_red();
        }

        $cabecera = $invoice['FeCAEReq']['FeCabReq'];
        $detalle = $invoice['FeCAEReq']['FeDetReq']['FECAEDetRequest'];

        $autorizada = $modo == self::AUTORIZADA;

        $respuesta_detalle = [
            'Concepto'   => $detalle['Concepto'],
            'DocTipo'    => $detalle['DocTipo'],
            'DocNro'     => $detalle['DocNro'] == 'NR' ? 0 : $detalle['DocNro'],
            'CbteDesde'  => $detalle['CbteDesde'],
            'CbteHasta'  => $detalle['CbteHasta'],
            'CbteFch'    => $detalle['CbteFch'],
            'Resultado'  => $autorizada ? 'A' : 'R',
            'CAE'        => $autorizada ? $this->guion['cae'] : '',
            'CAEFchVto'  => $autorizada ? '20261231' : '',
        ];

        if (!$autorizada) {
            // Un rechazo de detalle: ARCA lo informa como observación del comprobante.
            $respuesta_detalle['Observaciones'] = (object) [
                'Obs' => (object) [
                    'Code' => 10013,
                    'Msg'  => 'Para comprobantes clase A el campo DocTipo debe ser igual a 80 (CUIT).',
                ],
            ];
        }

        return [
            'hubo_un_error' => false,
            'request'       => '<request/>',
            'response'      => '<response/>',
            'result'        => (object) [
                'FECAESolicitarResult' => (object) [
                    'FeCabResp' => (object) [
                        'Cuit'       => 20423548984,
                        'PtoVta'     => $cabecera['PtoVta'],
                        'CbteTipo'   => $cabecera['CbteTipo'],
                        'FchProceso' => '20261009120000',
                        'CantReg'    => 1,
                        'Resultado'  => $autorizada ? 'A' : 'R',
                        'Reproceso'  => 'N',
                    ],
                    'FeDetResp' => (object) [
                        'FECAEDetResponse' => (object) $respuesta_detalle,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array $invoice ['FeCompConsReq' => [CbteTipo, CbteNro, PtoVta]]
     * @return array
     */
    public function FECompConsultar($invoice)
    {
        $modo = isset($this->guion['consulta']) ? $this->guion['consulta'] : self::ERROR_DE_RED;

        if ($modo != self::AUTORIZADA) {
            return $this->error_de_red();
        }

        $pedido = $invoice['FeCompConsReq'];

        return [
            'hubo_un_error' => false,
            'request'       => '<request/>',
            'response'      => '<response/>',
            'result'        => (object) [
                'FECompConsultarResult' => (object) [
                    'ResultGet' => (object) [
                        'PtoVta'          => $pedido['PtoVta'],
                        'CbteTipo'        => $pedido['CbteTipo'],
                        'CbteDesde'       => $pedido['CbteNro'],
                        'ImpTotal'        => isset($this->guion['consulta_imp_total']) ? $this->guion['consulta_imp_total'] : 0,
                        'MonId'           => 'PES',
                        'Resultado'       => 'A',
                        'CodAutorizacion' => $this->guion['cae'],
                        'FchVto'          => '20261231',
                    ],
                ],
            ],
        ];
    }

    /**
     * Lo que devuelve `WS::__call()` cuando la llamada no llega: `hubo_un_error` con el mensaje de
     * un corte de conexión (que `AfipWsfeHelper::is_network_error()` reconoce).
     *
     * @return array
     */
    private function error_de_red()
    {
        return [
            'hubo_un_error' => true,
            'result'        => null,
            'error'         => 'Could not connect to host',
            'request'       => '<request/>',
            'response'      => null,
        ];
    }
}

/**
 * Doble de WSFEX para `AfipFexHelper::consultar_comprobante()`: contesta `FEXGetCMP` con el
 * `FEXResultGet` que le pasaron y no abre ninguna conexión.
 */
class DobleDeWsfexQueConsulta
{
    /** @var array */
    private $resultado;

    /**
     * @param  array $resultado Campos de `FEXResultGet`.
     */
    public function __construct(array $resultado)
    {
        $this->resultado = $resultado;
    }

    /**
     * @param  object $params `Cmp` con Id, tipo, punto de venta y número; el doble los ignora.
     * @return array
     */
    public function FEXGetCMP($params)
    {
        return [
            'hubo_un_error' => false,
            'request'       => '<request/>',
            'response'      => '<response/>',
            'result'        => (object) [
                'FEXGetCMPResult' => (object) [
                    'FEXResultGet' => (object) $this->resultado,
                ],
            ],
        ];
    }

    /**
     * @return string
     */
    public function getLastRequest()
    {
        return '<request/>';
    }

    /**
     * @return string
     */
    public function getLastResponse()
    {
        return '<response/>';
    }
}
