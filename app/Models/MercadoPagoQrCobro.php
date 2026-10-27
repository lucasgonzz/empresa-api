<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un cobro con el QR de Mercado Pago en Vender (misión cobro-qr-mercado-pago, 26/10/2026).
 *
 * Cada vez que el cajero toca "Cobrar con QR" nace una fila: se le carga el total de la venta al
 * QR del mostrador como una orden de la API Orders de Mercado Pago (`mp_order_id`), y mientras el
 * cliente no paga queda `pendiente`. El SPA consulta el estado cada pocos segundos y el comando
 * `mercadopago:qr-conciliar` concilia lo que quedó colgado.
 *
 * 🔴 Un pendiente solo cambia de estado con una respuesta válida de MP (ver
 * `MercadoPagoQrService`): nunca se vence ni se cancela "por tiempo" sin preguntarle a MP.
 *
 * Estados (`estado`):
 * - `pendiente`: la orden está cargada en el QR y nadie la pagó todavía. También el cobro cuya
 *   orden MP no confirmó al crearla (sin `mp_order_id`, con "Mercado Pago no respondió,
 *   reintentando…"): se recupera reenviando el mismo pedido con `mp_idempotency_key`.
 * - `pagado`: MP la dio por procesada. Recién ahí el SPA guarda la venta, que queda atada al
 *   cobro por `sale_id`. Un cobro pagado sirve para UNA sola venta.
 * - `vencido`: MP dijo que la orden expiró (vence a los 10 minutos).
 * - `cancelado`: MP confirmó la cancelación: la pidió el cajero, la reemplazó un cobro nuevo en el
 *   mismo QR, o la canceló el comando porque nadie la esperaba más (`error_message` dice cuál).
 * - `error`: MP rechazó la orden de forma definitiva (validación del cuerpo), o se agotaron los
 *   plazos sin poder confirmarla ("No se pudo confirmar con Mercado Pago": 20 horas sin orden, 30
 *   días con orden). `error_message` dice por qué en castellano.
 *
 * `error_message` viaja al SPA: siempre un texto legible, nunca SQL, una excepción cruda ni la
 * respuesta entera de MP.
 */
class MercadoPagoQrCobro extends Model
{
    /** La orden está cargada en el QR y todavía no se pagó. */
    const ESTADO_PENDIENTE = 'pendiente';

    /** Mercado Pago dio la orden por procesada: el dinero entró. */
    const ESTADO_PAGADO = 'pagado';

    /** La orden expiró sin pago. */
    const ESTADO_VENCIDO = 'vencido';

    /** La canceló el cajero o la reemplazó otro cobro del mismo QR. */
    const ESTADO_CANCELADO = 'cancelado';

    /** MP rechazó la orden de forma definitiva, o no se la pudo confirmar dentro de los plazos. */
    const ESTADO_ERROR = 'error';

    protected $table = 'mercado_pago_qr_cobros';

    protected $guarded = [];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    /**
     * Scope estándar del proyecto: el QR (con sus datos para el modal) y la venta (para el
     * `sale_num` de la forma `Cobro`).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        return $query->with('qr', 'sale');
    }

    /**
     * QR al que se le cargó el monto. Con `withTrashed()`: un cobro viejo de un QR que después se
     * borró igual tiene que poder mostrar de qué QR fue.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function qr()
    {
        return $this->belongsTo(MercadoPagoQrCaja::class, 'mercado_pago_qr_caja_id')->withTrashed();
    }

    /**
     * Venta que usó este cobro. `withTrashed()` por el mismo motivo: si la venta se borró, el
     * cobro sigue diciendo a qué número estuvo atado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id')->withTrashed();
    }

    /**
     * Indica si el cobro todavía espera el pago.
     *
     * @return bool
     */
    public function esta_pendiente()
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }
}
