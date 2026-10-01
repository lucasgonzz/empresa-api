<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Models\Caja;
use App\Models\SaleStatus;
use Carbon\Carbon;

/**
 * Valores de los campos del catálogo de VENTA (`CatalogoDeCamposPdf::campos('sale')`) para una
 * venta dibujada con un diseño de página (misión diseno-pdf-configurable, 1/10/2026).
 *
 * 🔴 Con la etiqueta del catálogo, cada renglón tiene que decir EXACTAMENTE lo mismo que el PDF de
 * siempre (`NewSalePdf` + `AfipPdfHelper`): mismas funciones de formato y mismos argumentos de
 * `Numbers::price()`. Cada caso nombra de dónde copia el formato. La plata del pie sale de
 * `TotalesDeVentaPdf`, que es la copia fiel de la cuenta de `NewSalePdf`.
 *
 * Diferencias a propósito con el PDF de siempre, porque en el diseño con cajas lo que se imprime
 * lo deciden las cajas y no los flags del perfil:
 * - la cuenta corriente sale aunque el perfil tenga apagado "total en el pie" (el remito de
 *   siempre la ataba a ese flag);
 * - "Atendido por" se resuelve con el DUEÑO de la venta y no con el usuario logueado: las rutas de
 *   PDF son públicas por id (`PdfHelper::buildCurrentAcountData()` usa `UserHelper::getFullModel()`,
 *   que en una ruta pública no es necesariamente el comercio de la venta).
 */
class CamposDeVentaPdf implements FuenteDeCamposPdf
{
    /** @var \App\Models\Sale */
    private $sale;

    /** @var \App\Models\User|null Dueño de la venta (`sales.user_id`). */
    private $user;

    /** @var bool "Imprimir con fecha actual" del perfil. */
    private $use_current_date;

    /** @var TotalesDeVentaPdf La plata del pie. */
    private $totales;

    /** @var bool Ya se calculó la cuenta corriente (memo: tiene una consulta del saldo detrás). */
    private $cuenta_corriente_calculada;

    /** @var array<string, mixed>|null Saldo anterior, compra actual y saldo, o null si no corresponde. */
    private $cuenta_corriente;

    /**
     * @param \App\Models\Sale      $sale
     * @param \App\Models\User|null $user                  Dueño de la venta.
     * @param bool                  $use_current_date      "Imprimir con fecha actual".
     * @param string                $discount_display_mode 'descriptivo' | 'simple' (el del perfil).
     */
    public function __construct($sale, $user, $use_current_date, $discount_display_mode)
    {
        $this->sale = $sale;
        $this->user = $user;
        $this->use_current_date = (bool) $use_current_date;
        $this->totales = new TotalesDeVentaPdf($sale, $user, $discount_display_mode);
        $this->cuenta_corriente_calculada = false;
        $this->cuenta_corriente = null;
    }

    /** @return string */
    public function model_name()
    {
        return 'sale';
    }

    /**
     * La plata del pie (la usa también quien necesite los totales sin pasar por un campo).
     *
     * @return TotalesDeVentaPdf
     */
    public function totales()
    {
        return $this->totales;
    }

    /**
     * @param string $key
     * @param array  $campo
     * @return string|array|null
     */
    public function valor($key, $campo)
    {
        if (strpos($key, 'cliente_') === 0) {
            return self::valor_de_cliente($key, $this->sale->client);
        }

        switch ($key) {
            case 'venta_numero':
                return self::texto($this->sale->num);
            case 'venta_fecha':
                return $this->fecha_de_impresion()->format('d/m/Y');
            case 'venta_hora':
                return $this->fecha_de_impresion()->format('H:i');
            case 'venta_tipo':
                return $this->sale->sale_type ? self::texto($this->sale->sale_type->name) : null;
            case 'venta_estado':
                return $this->estado();
            case 'venta_condicion':
                /** La misma regla que el receptor de la factura (`AfipPdfHelper::print_receptor_block()`). */
                return $this->sale->current_acount ? 'Cuenta corriente' : 'Contado';
            case 'venta_vendedor':
                return $this->sale->seller ? self::texto($this->sale->seller->name) : null;
            case 'venta_empleado':
                return $this->sale->employee ? self::texto($this->sale->employee->name) : null;
            case 'venta_atendido_por':
                return $this->atendido_por();
            case 'venta_sucursal':
                return self::sucursal($this->sale->address);
            case 'venta_lista_de_precios':
                return $this->sale->price_type ? self::texto($this->sale->price_type->name) : null;
            case 'venta_metodos_de_pago':
                return $this->metodos_de_pago();
            case 'venta_cajas':
                return $this->cajas();
            case 'venta_moneda':
                return self::moneda($this->sale->moneda_id);
            case 'venta_cotizacion':
                return self::cotizacion($this->sale->moneda_id, $this->sale->valor_dolar);
            case 'venta_cuotas':
                return (int) $this->sale->cantidad_cuotas > 0 ? (string) (int) $this->sale->cantidad_cuotas : null;
            case 'venta_fecha_entrega':
                return self::fecha($this->sale->fecha_entrega);
            case 'venta_orden_de_compra':
                return self::texto($this->sale->numero_orden_de_compra);
            case 'venta_cantidad_de_unidades':
                return $this->cantidad_de_unidades();
            case 'venta_observaciones':
                return self::texto_largo($this->sale->observations);

            case 'cc_saldo_anterior':
            case 'cc_compra_actual':
            case 'cc_saldo':
                return $this->renglon_de_cuenta_corriente($key);

            case 'tot_subtotal':
                return $this->totales->texto_sub_total();
            case 'tot_descuentos':
                return self::lista($this->totales->renglones_de_descuentos());
            case 'tot_recargos':
                return self::lista($this->totales->renglones_de_recargos());
            case 'tot_canje_de_puntos':
                return $this->totales->renglon_canje();
            case 'tot_ajuste_del_total':
                return $this->totales->hay_diferencia() ? $this->totales->renglon_ajuste_del_total() : null;
            case 'tot_total':
                return $this->totales->texto_total();
            case 'tot_puntos':
                return self::lista($this->totales->renglones_de_puntos());
            case 'tot_comisiones':
                return self::lista($this->totales->renglones_de_comisiones());
            case 'tot_total_menos_comisiones':
                return $this->totales->texto_total_menos_comisiones();
            case 'tot_costos':
                return $this->totales->texto_costos();
            case 'tot_ganancia':
                /** `sales.ganancia` tal cual la muestra el sistema (dato conocido como roto en algunos clientes). */
                return is_null($this->sale->ganancia) ? null : '$'.Numbers::price($this->sale->ganancia);

            case CatalogoDeCamposPdf::KEY_TEXTO_LIBRE:
                return self::texto_libre($campo);
        }

        throw new \InvalidArgumentException('Campo del PDF de venta sin resolver: '.$key);
    }

    // ── Cliente (lo comparte el presupuesto) ─────────────────────────────────────────────────

    /**
     * Valor de un campo `cliente_*` del catálogo para un cliente (venta o presupuesto).
     *
     * @param string                  $key
     * @param \App\Models\Client|null $client
     * @return string|null
     * @throws \InvalidArgumentException si la key no es de cliente.
     */
    public static function valor_de_cliente($key, $client)
    {
        if (is_null($client)) {
            /** Sin cliente no hay nada que imprimir; igual se valida la key (un typo no pasa en silencio). */
            if (! in_array($key, self::keys_de_cliente(), true)) {
                throw new \InvalidArgumentException('Campo de cliente sin resolver: '.$key);
            }

            return null;
        }

        switch ($key) {
            case 'cliente_nombre':
                return self::texto($client->name);
            case 'cliente_razon_social':
                return self::texto($client->razon_social);
            case 'cliente_documento':
                /** La regla del receptor de la factura (`AfipPdfHelper::print_receptor_block()`): el CUIT y, si no hay, el DNI. */
                $cuit = trim((string) $client->cuit);
                if ($cuit !== '') {
                    return $cuit;
                }

                return self::texto($client->dni);
            case 'cliente_cuit':
                return self::texto($client->cuit);
            case 'cliente_dni':
                return self::texto($client->dni);
            case 'cliente_condicion_iva':
                return $client->iva_condition ? self::texto($client->iva_condition->name) : null;
            case 'cliente_telefono':
                return self::texto($client->phone);
            case 'cliente_email':
                return self::texto($client->email);
            case 'cliente_direccion':
                /** La columna de texto `clients.address` (el domicilio), no la relación con la sucursal. */
                return self::texto($client->address);
            case 'cliente_localidad':
                return $client->location ? self::texto($client->location->name) : null;
            case 'cliente_provincia':
                if ($client->provincia) {
                    return self::texto($client->provincia->name);
                }

                return ($client->location && $client->location->provincia) ? self::texto($client->location->provincia->name) : null;
            case 'cliente_numero':
                return self::texto($client->num);
            case 'cliente_lista_de_precios':
                return $client->price_type ? self::texto($client->price_type->name) : null;
            case 'cliente_vendedor':
                return $client->seller ? self::texto($client->seller->name) : null;
            case 'cliente_observaciones':
                return self::texto_largo($client->description);
        }

        throw new \InvalidArgumentException('Campo de cliente sin resolver: '.$key);
    }

    /**
     * Las keys de cliente que sabe resolver valor_de_cliente().
     *
     * @return array<int, string>
     */
    public static function keys_de_cliente()
    {
        return [
            'cliente_nombre', 'cliente_razon_social', 'cliente_documento', 'cliente_cuit', 'cliente_dni',
            'cliente_condicion_iva', 'cliente_telefono', 'cliente_email', 'cliente_direccion',
            'cliente_localidad', 'cliente_provincia', 'cliente_numero', 'cliente_lista_de_precios',
            'cliente_vendedor', 'cliente_observaciones',
        ];
    }

    // ── Formatos compartidos (los usan también el presupuesto y el pedido) ─────────────────

    /**
     * Texto de un renglón: sin espacios a los costados y null si queda vacío (un campo vacío no
     * imprime ni su rótulo). Adentro no se toca nada: el PDF de siempre imprime el dato tal cual.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function texto($valor)
    {
        if (is_null($valor) || is_array($valor) || is_object($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Texto que puede ocupar varios renglones (observaciones): conserva los saltos de línea.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function texto_largo($valor)
    {
        if (is_null($valor) || is_array($valor) || is_object($valor)) {
            return null;
        }

        $texto = trim(str_replace("\r", '', (string) $valor));

        return $texto === '' ? null : $texto;
    }

    /**
     * El texto libre que escribió el usuario en el diseño (null si lo dejó vacío).
     *
     * @param array $campo
     * @return string|null
     */
    public static function texto_libre($campo)
    {
        return self::texto_largo(isset($campo['texto']) ? $campo['texto'] : null);
    }

    /**
     * Una lista: null si no tiene elementos (no se imprime ni el rótulo).
     *
     * @param array $renglones
     * @return array<int, string>|null
     */
    public static function lista($renglones)
    {
        $limpios = [];
        foreach ((array) $renglones as $renglon) {
            $texto = self::texto($renglon);
            if (! is_null($texto)) {
                $limpios[] = $texto;
            }
        }

        return count($limpios) > 0 ? $limpios : null;
    }

    /**
     * Fecha corta (d/m/Y) de un Carbon, un DateTime o un string; null si no hay.
     *
     * @param mixed $fecha
     * @return string|null
     */
    public static function fecha($fecha)
    {
        if (is_null($fecha) || $fecha === '') {
            return null;
        }

        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format('d/m/Y');
        }

        return Carbon::parse((string) $fecha)->format('d/m/Y');
    }

    /**
     * La sucursal como la lee una persona: "Belgrano 450, Rosario" (calle y número, y la ciudad).
     *
     * @param \App\Models\Address|null $address
     * @return string|null
     */
    public static function sucursal($address)
    {
        if (is_null($address)) {
            return null;
        }

        $calle = trim(trim((string) $address->street).' '.trim((string) $address->street_number));
        $partes = [];
        if ($calle !== '') {
            $partes[] = $calle;
        }
        if (trim((string) $address->city) !== '') {
            $partes[] = trim((string) $address->city);
        }

        return count($partes) > 0 ? implode(', ', $partes) : null;
    }

    /**
     * Moneda del comprobante. NULL o cualquier cosa que no sea 2 es pesos (decisión de Lucas del
     * 30/9/2026, la misma de `Sale::EXPRESION_EN_PESOS`).
     *
     * @param mixed $moneda_id
     * @return string
     */
    public static function moneda($moneda_id)
    {
        return (int) $moneda_id === 2 ? 'Dólares' : 'Pesos';
    }

    /**
     * Cotización del dólar, solo en comprobantes en dólares con una cotización cargada.
     *
     * @param mixed $moneda_id
     * @param mixed $valor_dolar
     * @return string|null
     */
    public static function cotizacion($moneda_id, $valor_dolar)
    {
        if ((int) $moneda_id !== 2 || (float) $valor_dolar <= 0) {
            return null;
        }

        return Numbers::price($valor_dolar, true);
    }

    // ── Venta ──────────────────────────────────────────────────────────────────────────────

    /**
     * La fecha que imprime el comprobante: la de hoy con "Imprimir con fecha actual", si no la de
     * la venta. Misma regla que el encabezado (`AfipPdfHelper::header_comercial()`).
     *
     * @return \Carbon\Carbon
     */
    private function fecha_de_impresion()
    {
        if ($this->use_current_date || is_null($this->sale->created_at)) {
            return now();
        }

        return Carbon::parse($this->sale->created_at);
    }

    /**
     * Estado de la venta (`sales.sale_status_id`; el modelo no declara la relación).
     *
     * @return string|null
     */
    private function estado()
    {
        if (empty($this->sale->sale_status_id)) {
            return null;
        }

        $estado = SaleStatus::find($this->sale->sale_status_id);

        return $estado ? self::texto($estado->name) : null;
    }

    /**
     * El empleado que cargó la venta y, si no hay, el dueño. Regla de
     * `PdfHelper::buildCurrentAcountData()` (renglón "Vendedor" de la cuenta corriente del remito).
     *
     * @return string|null
     */
    private function atendido_por()
    {
        if (! is_null($this->sale->employee)) {
            return self::texto($this->sale->employee->name);
        }

        return $this->user ? self::texto($this->user->name) : null;
    }

    /**
     * Métodos de pago con lo que se pagó con cada uno ("Efectivo: $10.000"), desde el pivote
     * `current_acount_payment_method_sale`. Una venta vieja sin pivotes cae al método único de la
     * venta, sin monto.
     *
     * @return array<int, string>|null
     */
    private function metodos_de_pago()
    {
        $renglones = [];

        foreach ($this->sale->current_acount_payment_methods as $metodo) {
            $nombre = trim((string) $metodo->name);

            if (is_null($metodo->pivot->amount) || $metodo->pivot->amount === '') {
                $renglones[] = $nombre;
                continue;
            }

            $renglones[] = $nombre.': '.Numbers::price($metodo->pivot->amount, true, $this->sale->moneda_id);
        }

        if (count($renglones) === 0 && $this->sale->current_acount_payment_method) {
            $renglones[] = (string) $this->sale->current_acount_payment_method->name;
        }

        return self::lista($renglones);
    }

    /**
     * Las cajas donde entró la plata: las de los pivotes de pago y la de la venta, sin repetir y
     * en ese orden.
     *
     * @return array<int, string>|null
     */
    private function cajas()
    {
        $ids = [];

        foreach ($this->sale->current_acount_payment_methods as $metodo) {
            if (! empty($metodo->pivot->caja_id)) {
                $ids[] = (int) $metodo->pivot->caja_id;
            }
        }

        if (! empty($this->sale->caja_id)) {
            $ids[] = (int) $this->sale->caja_id;
        }

        $ids = array_values(array_unique($ids));

        if (count($ids) === 0) {
            return null;
        }

        $cajas = Caja::whereIn('id', $ids)->get()->keyBy('id');

        $nombres = [];
        foreach ($ids as $id) {
            if (isset($cajas[$id])) {
                $nombres[] = (string) $cajas[$id]->name;
            }
        }

        return self::lista($nombres);
    }

    /**
     * Unidades de todos los renglones de la tabla (artículos, combos, promociones y servicios),
     * con el mismo formato que la columna "Cantidad" (`item_amount` usa `Numbers::price()`).
     *
     * @return string
     */
    private function cantidad_de_unidades()
    {
        $total = 0;

        foreach ($this->totales->renglones() as $renglon) {
            $total += (float) $renglon[1]->pivot->amount;
        }

        return Numbers::price($total);
    }

    /**
     * Uno de los tres renglones de cuenta corriente, con el formato del cuadrante derecho del
     * remito (`AfipPdfHelper::print_receptor_block_comercial()`: '$'.Numbers::price(...)).
     *
     * @param string $key cc_saldo_anterior | cc_compra_actual | cc_saldo
     * @return string|null
     */
    private function renglon_de_cuenta_corriente($key)
    {
        $datos = $this->cuenta_corriente();

        if (is_null($datos)) {
            return null;
        }

        $mapa = [
            'cc_saldo_anterior' => 'saldo_anterior',
            'cc_compra_actual' => 'compra_actual',
            'cc_saldo' => 'saldo',
        ];

        return '$'.Numbers::price($datos[$mapa[$key]]);
    }

    /**
     * Saldo anterior, compra actual y saldo: la MISMA regla y los MISMOS números que
     * `PdfHelper::buildCurrentAcountData()` (venta con cliente, que va a la cuenta corriente y que
     * tiene su movimiento), con la misma función del saldo (`CurrentAcountHelper::getSaldo()`).
     * Memoizado: es una consulta, y el pie se puede dibujar en cada hoja.
     *
     * @return array<string, mixed>|null
     */
    private function cuenta_corriente()
    {
        if ($this->cuenta_corriente_calculada) {
            return $this->cuenta_corriente;
        }

        $this->cuenta_corriente_calculada = true;

        if (
            ! is_null($this->sale->client)
            && $this->sale->save_current_acount
            && ! is_null($this->sale->current_acount)
        ) {
            $current_acount = $this->sale->current_acount;
            $saldo_anterior = CurrentAcountHelper::getSaldo($current_acount->credit_account_id, $current_acount);
            $compra_actual = $this->sale->total;

            $this->cuenta_corriente = [
                'saldo_anterior' => $saldo_anterior,
                'compra_actual' => $compra_actual,
                'saldo' => $saldo_anterior + $compra_actual,
            ];
        }

        return $this->cuenta_corriente;
    }
}
