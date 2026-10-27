<?php

namespace Tests\Feature\MercadoPagoQr;

use App\Models\MercadoPagoQrCobro;
use App\Services\MercadoPago\MercadoPagoQrService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * El comando `mercadopago:qr-conciliar` (misión cobro-qr-mercado-pago, 26/10/2026), la red de
 * abajo del polling del modal de Vender.
 *
 * 🔴 Regla que fija (cambió en la revisión independiente del 26/10): un cobro pendiente SOLO
 * cambia de estado con una respuesta válida de Mercado Pago. Antes el comando vencía los
 * pendientes de más de 3 horas aunque no hubiera conexión o MP fallara, y eso es plata: el cliente
 * puede haber pagado igual y el cajero lo volvería a cobrar. La orden vence sola en MP a los 10
 * minutos, así que la consulta devuelve `expired` y recién ahí se vence.
 *
 * Lo que fija:
 * - Con orden: se aplica lo que diga MP (`processed` → pagado, `expired` → vencido, `created` →
 *   sigue pendiente, aunque tenga horas). Si MP falla o no hay conexión, sigue pendiente.
 * - Más de 30 días: se deja de consultar y pasa a `error` "No se pudo confirmar con Mercado Pago".
 * - Sin orden (MP no contestó al crearla): con menos de 15 minutos no se toca (lo recupera el
 *   polling); con más, se recupera con el mismo pedido y la misma clave, y si la orden está sin
 *   pagar se la cancela (`cancelado`), si está pagada queda `pagado`. Pasadas 20 horas, `error`
 *   sin reenviar nada.
 * - Una excepción con un cobro no corta el loop.
 * - Lo que no está pendiente no se consulta.
 *
 * @group mercado_pago_qr
 */
class Conciliar_cobros_Test extends MercadoPagoQrTestCase
{
    /**
     * Pone el `created_at` de un cobro en el pasado.
     *
     * @param MercadoPagoQrCobro $cobro
     * @param int $minutos
     * @return void
     */
    protected function envejecer_minutos(MercadoPagoQrCobro $cobro, $minutos)
    {
        MercadoPagoQrCobro::where('id', $cobro->id)->update(['created_at' => Carbon::now()->subMinutes($minutos)]);
    }

    /**
     * Con orden: aplica lo que diga MP. Un pendiente de horas con la orden `created` sigue
     * pendiente (MP no dijo que venció); con `expired`, vencido.
     *
     * @test
     */
    public function con_orden_aplica_lo_que_diga_mp_y_nada_mas()
    {
        $this->conectar_mp();

        $qr = $this->qr_en_base();

        $pagado_en_mp    = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDPAGO']);
        $sigue_esperando = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDESPERA']);
        $viejo_created   = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDVIEJA']);
        $viejo_expired   = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDEXPIRADA']);
        $ya_cancelado    = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDCANCELADA', 'estado' => 'cancelado']);

        $this->envejecer_minutos($viejo_created, 4 * 60);
        $this->envejecer_minutos($viejo_expired, 4 * 60);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDPAGO$#', $this->orden_mp('ORDPAGO', 'processed', 'processed', 'accredited')],
            ['GET', '#^/v1/orders/ORDESPERA$#', $this->orden_mp('ORDESPERA', 'created')],
            ['GET', '#^/v1/orders/ORDVIEJA$#', $this->orden_mp('ORDVIEJA', 'created')],
            ['GET', '#^/v1/orders/ORDEXPIRADA$#', $this->orden_mp('ORDEXPIRADA', 'expired')],
            ['POST', '#^/v1/orders/ORDVIEJA/cancel$#', [200, ['id' => 'ORDVIEJA', 'status' => 'canceled']]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pagado', $pagado_en_mp->fresh()->estado);
        $this->assertNotNull($pagado_en_mp->fresh()->paid_at);
        $this->assertSame('pendiente', $sigue_esperando->fresh()->estado);
        // Viejo y MP lo sigue dando sin pagar: nadie lo espera, se cancela EN MP (no se vence por
        // tiempo: queda cancelado porque MP confirmó la cancelación).
        $this->assertSame('cancelado', $viejo_created->fresh()->estado, 'Viejo y sin pagar: se cancela en MP.');
        $this->assertSame('El cobro se canceló porque Mercado Pago tardó en confirmarlo.', $viejo_created->fresh()->error_message);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDESPERA/cancel';
        });
        $this->assertSame('vencido', $viejo_expired->fresh()->estado);
        $this->assertSame('cancelado', $ya_cancelado->fresh()->estado);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDCANCELADA';
        });
    }

    /**
     * Si MP falla al consultar, el pendiente viejo sigue pendiente.
     *
     * @test
     */
    public function si_mp_falla_el_pendiente_viejo_sigue_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDCAIDA']);
        $this->envejecer_minutos($cobro, 5 * 60);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDCAIDA$#', [500, ['message' => 'internal_error']]],
            ['POST', '#^/v1/orders/ORDCAIDA/cancel$#', [500, ['message' => 'internal_error']]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $cobro->fresh()->estado);
    }

    /**
     * Un pendiente viejo que en MP figura pagado no se pierde: queda pagado.
     *
     * @test
     */
    public function un_pendiente_viejo_que_se_pago_queda_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDTARDE']);
        $this->envejecer_minutos($cobro, 5 * 60);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDTARDE$#', $this->orden_mp('ORDTARDE', 'processed', 'processed', 'accredited')],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pagado', $cobro->fresh()->estado);
    }

    /**
     * Sin conexión: no se consulta nada y NINGÚN pendiente cambia de estado (antes este test fijaba
     * que el viejo se vencía: era el comportamiento equivocado).
     *
     * @test
     */
    public function sin_conexion_no_consulta_ni_cambia_ningun_estado()
    {
        $this->fake_mp([]);

        $qr = $this->qr_en_base();

        $reciente  = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDR']);
        $viejo     = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDV']);
        $sin_orden = $this->cobro_en_base($qr, ['mp_order_id' => null]);
        $this->envejecer_minutos($viejo, 4 * 60);
        $this->envejecer_minutos($sin_orden, 4 * 60);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $reciente->fresh()->estado);
        $this->assertSame('pendiente', $viejo->fresh()->estado);
        $this->assertSame('pendiente', $sin_orden->fresh()->estado);

        Http::assertNothingSent();
    }

    /**
     * Más de 30 días: se deja de consultar y pasa a error.
     *
     * @test
     */
    public function un_pendiente_de_mas_de_30_dias_pasa_a_error_sin_consultar()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDANTIGUA']);
        $this->envejecer_minutos($cobro, 31 * 24 * 60);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('error', $cobro->fresh()->estado);
        $this->assertSame('No se pudo confirmar con Mercado Pago.', $cobro->fresh()->error_message);

        Http::assertNothingSent();
    }

    /**
     * Sin orden y con menos de 15 minutos: el comando no lo toca (lo está recuperando el polling).
     *
     * @test
     */
    public function sin_orden_y_reciente_no_se_toca()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 5);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $cobro->fresh()->estado);
        Http::assertNothingSent();
    }

    /**
     * Sin orden y con más de 15 minutos: se recupera con la misma clave; la orden está sin pagar,
     * nadie la espera, se la cancela y el cobro queda cancelado.
     *
     * @test
     */
    public function sin_orden_y_viejo_se_recupera_y_si_esta_sin_pagar_se_cancela()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 20);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDHUERFANA')],
            ['GET', '#^/v1/orders/ORDHUERFANA$#', $this->orden_mp('ORDHUERFANA', 'created')],
            ['POST', '#^/v1/orders/ORDHUERFANA/cancel$#', [200, ['id' => 'ORDHUERFANA', 'status' => 'canceled']]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $cobro->refresh();

        $this->assertSame('cancelado', $cobro->estado);
        $this->assertSame('ORDHUERFANA', $cobro->mp_order_id);

        Http::assertSent(function (Request $request) use ($cobro) {
            return $request->method() === 'POST'
                && parse_url($request->url(), PHP_URL_PATH) === '/v1/orders'
                && $request->header('X-Idempotency-Key')[0] === $cobro->mp_idempotency_key;
        });

        Http::assertSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDHUERFANA/cancel';
        });
    }

    /**
     * Sin orden y viejo: la orden recuperada está pagada → pagado (no se cancela).
     *
     * @test
     */
    public function sin_orden_y_viejo_si_la_orden_recuperada_esta_pagada_queda_pagado()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 60);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDPAGADA')],
            ['GET', '#^/v1/orders/ORDPAGADA$#', $this->orden_mp('ORDPAGADA', 'processed', 'processed', 'accredited')],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pagado', $cobro->fresh()->estado);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDPAGADA/cancel';
        });
    }

    /**
     * Sin orden y con más de 20 horas: error, sin reenviar nada (la clave dejaría de ser
     * idempotente y se crearía una orden nueva).
     *
     * @test
     */
    public function sin_orden_y_con_mas_de_20_horas_pasa_a_error_sin_reenviar()
    {
        $this->conectar_mp();
        $this->fake_mp([]);

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 21 * 60);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('error', $cobro->fresh()->estado);
        $this->assertSame('No se pudo confirmar con Mercado Pago.', $cobro->fresh()->error_message);

        Http::assertNothingSent();
    }

    /**
     * N2: tras recuperar una orden huérfana fallan la consulta y la cancelación. El cobro queda
     * pendiente CON orden, y la corrida siguiente (con MP de vuelta) vuelve a intentar cancelarla.
     *
     * @test
     */
    public function si_la_cancelacion_de_una_orden_recuperada_falla_la_corrida_siguiente_la_reintenta()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 30);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', $this->orden_creada_mp('ORDTERCA')],
            ['GET', '#^/v1/orders/ORDTERCA$#', [500, ['message' => 'internal_error']]],
            ['POST', '#^/v1/orders/ORDTERCA/cancel$#', [500, ['message' => 'internal_error']]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $cobro->refresh();
        $this->assertSame('pendiente', $cobro->estado, 'Sin respuesta válida de MP no cambia.');
        $this->assertSame('ORDTERCA', $cobro->mp_order_id, 'La orden quedó recuperada.');

        // MP vuelve: la corrida siguiente consulta, la ve sin pagar y la cancela. Factory nuevo
        // (con el mismo freno de internet) para que las respuestas de arriba no ganen.
        $this->cerrar_la_salida_a_internet();

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDTERCA$#', $this->orden_mp('ORDTERCA', 'created')],
            ['POST', '#^/v1/orders/ORDTERCA/cancel$#', [200, ['id' => 'ORDTERCA', 'status' => 'canceled']]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('cancelado', $cobro->fresh()->estado);
    }

    /**
     * Un pendiente viejo con la orden en curso (`action_required`: el cliente puede estar pagando)
     * no se cancela.
     *
     * @test
     */
    public function un_pendiente_viejo_con_la_orden_en_curso_no_se_cancela()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => 'ORDENCURSO']);
        $this->envejecer_minutos($cobro, 30);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDENCURSO$#', $this->orden_mp('ORDENCURSO', 'action_required')],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $cobro->fresh()->estado);

        Http::assertNotSent(function (Request $request) {
            return parse_url($request->url(), PHP_URL_PATH) === '/v1/orders/ORDENCURSO/cancel';
        });
    }

    /**
     * N1: al recuperar, un 401 (token vencido en el medio) NO prueba que la orden no exista: el
     * cobro sigue pendiente sin orden y se reintenta.
     *
     * @test
     */
    public function un_401_al_recuperar_deja_el_cobro_pendiente()
    {
        $this->conectar_mp();

        $cobro = $this->cobro_en_base($this->qr_en_base(), ['mp_order_id' => null]);
        $this->envejecer_minutos($cobro, 30);

        $this->fake_mp([
            ['POST', '#^/v1/orders$#', [401, ['message' => 'invalid access token', 'error' => 'unauthorized', 'status' => 401]]],
        ]);

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $cobro->refresh();
        $this->assertSame('pendiente', $cobro->estado);
        $this->assertNull($cobro->mp_order_id);
        $this->assertSame('Mercado Pago no respondió, reintentando…', $cobro->error_message);
    }

    /**
     * Una excepción con un cobro no corta el loop: los demás se concilian igual.
     *
     * El servicio se reemplaza en el contenedor por uno cuyo `actualizar_cobro()` explota con un
     * cobro puntual; el resto sigue el camino real.
     *
     * @test
     */
    public function una_excepcion_con_un_cobro_no_corta_el_loop()
    {
        $this->conectar_mp();

        $qr = $this->qr_en_base();

        $explota = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDEXPLOTA']);
        $sigue   = $this->cobro_en_base($qr, ['mp_order_id' => 'ORDSIGUE']);

        $this->fake_mp([
            ['GET', '#^/v1/orders/ORDSIGUE$#', $this->orden_mp('ORDSIGUE', 'processed', 'processed', 'accredited')],
        ]);

        $id_que_explota = $explota->id;

        $this->app->instance(MercadoPagoQrService::class, new class($id_que_explota) extends MercadoPagoQrService {
            /** @var int */
            protected $id_que_explota;

            public function __construct($id_que_explota)
            {
                $this->id_que_explota = $id_que_explota;
            }

            public function actualizar_cobro(MercadoPagoQrCobro $cobro, $conexion = null)
            {
                if ((int) $cobro->id === (int) $this->id_que_explota) {
                    throw new \RuntimeException('falla simulada en un cobro');
                }

                return parent::actualizar_cobro($cobro, $conexion);
            }
        });

        $this->artisan('mercadopago:qr-conciliar')->assertExitCode(0);

        $this->assertSame('pendiente', $explota->fresh()->estado);
        $this->assertSame('pagado', $sigue->fresh()->estado, 'El cobro de después del que explotó se concilió igual.');
    }
}
