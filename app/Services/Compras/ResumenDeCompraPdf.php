<?php

namespace App\Services\Compras;

use App\Models\Iva;
use App\Models\Moneda;
use Illuminate\Validation\ValidationException;

/** Proyecta los importes registrados: imprimir nunca recalcula ni guarda una compra. */
class ResumenDeCompraPdf
{
    public static function crear($compra)
    {
        $compra->loadMissing('articles', 'provider_order_discounts', 'provider_order_afip_tickets.provider_order_afip_ticket_ivas', 'provider_order_extra_costs');
        foreach ($compra->articles as $articulo) {
            self::numero($articulo->pivot->amount, 'cantidad pedida');
            if (!is_null($articulo->pivot->received) && $articulo->pivot->received !== '') {
                self::numero($articulo->pivot->received, 'cantidad recibida');
            }
            $costo = $articulo->pivot->received_cost;
            if (is_null($costo) || $costo === '') {
                $costo = $articulo->pivot->cost;
            }
            if (is_null($costo) || $costo === '') {
                $costo = $articulo->pivot->price;
            }
            self::numero($costo, 'costo del artículo '.$articulo->name);
        }
        $moneda = Moneda::find($compra->moneda_id);
        if (!$moneda) {
            self::rechazar('La compra no tiene una moneda válida. Revisá la moneda de la compra antes de imprimir.');
        }
        $filas = [];
        $de_facturas = (bool) $compra->total_from_provider_order_afip_tickets;
        $filas[] = ['Fuente del total', $de_facturas ? 'Comprobantes registrados' : 'Artículos de la compra'];
        $total_facturas = 0;
        $iva_facturas = 0;
        $ivas = Iva::all()->keyBy('id');
        if ($de_facturas && $compra->provider_order_afip_tickets->isEmpty()) {
            self::rechazar('El total se toma de facturas, pero no hay comprobantes registrados. Revisá las facturas de la compra antes de imprimir.');
        }
        foreach ($compra->provider_order_afip_tickets as $ticket) {
            $total_ticket = self::numero($ticket->total, 'total del comprobante');
            $iva_ticket = is_null($ticket->total_iva) ? 0 : self::numero($ticket->total_iva, 'IVA del comprobante');
            $filas[] = ['Comprobante '.($ticket->code ?: '#'.$ticket->id), null];
            $neto = 0;
            $iva = 0;
            foreach ($ticket->provider_order_afip_ticket_ivas as $linea) {
                $alicuota = $ivas->get($linea->iva_id);
                if (!$alicuota) {
                    self::rechazar('Un comprobante tiene una alícuota de IVA que no existe. Revisá su desglose de IVA antes de imprimir.');
                }
                $importe_neto = self::numero($linea->neto, 'neto del comprobante');
                $importe_iva = self::numero($linea->iva_importe, 'importe de IVA');
                $nombre = is_numeric($alicuota->percentage) ? $alicuota->percentage.'%' : $alicuota->percentage;
                $filas[] = ['Neto '.$nombre, $importe_neto];
                $filas[] = ['IVA '.$nombre, $importe_iva];
                $neto += $importe_neto;
                $iva += $importe_iva;
            }
            $percepciones = 0;
            foreach (['percepcion_iibb' => 'Percepción IIBB', 'percepcion_iva' => 'Percepción IVA'] as $campo => $texto) {
                $valor = is_null($ticket->$campo) ? 0 : self::numero($ticket->$campo, $texto);
                $percepciones += $valor;
                if ($valor != 0) {
                    $filas[] = [$texto, $valor];
                }
            }
            if ($ticket->provider_order_afip_ticket_ivas->isNotEmpty()) {
                self::conciliar($neto + $iva + $percepciones, $total_ticket, 'El neto, IVA y percepciones no coinciden con el total de un comprobante. Revisá sus importes y desglose antes de imprimir.');
                self::conciliar($iva, $iva_ticket, 'El IVA del comprobante no coincide con sus alícuotas. Revisá el desglose de IVA antes de imprimir.');
            } else {
                if ($iva_ticket != 0) {
                    self::rechazar('El comprobante tiene IVA registrado pero falta su desglose. Revisá las alícuotas del comprobante con soporte antes de imprimir.');
                }
                $filas[] = ['Importe sin desglose de IVA', $total_ticket - $percepciones];
            }
            $filas[] = ['Total comprobante (IVA incluido si corresponde)', $total_ticket];
            $total_facturas += $total_ticket;
            $iva_facturas += $iva_ticket;
        }

        $extras = 0;
        foreach ($compra->provider_order_extra_costs as $extra) {
            $valor = self::numero($extra->value, 'costo extra');
            $extras += $valor;
            $filas[] = ['Costo extra: '.$extra->description, $valor];
        }
        if ($extras < 0) {
            self::rechazar('Los costos extra suman un importe negativo que no se aplica al total de la compra. Revisá esos costos con soporte antes de imprimir.');
        }
        foreach ($compra->provider_order_discounts as $descuento) {
            $descripcion = 'Descuento registrado: '.$descuento->description;
            if (!is_null($descuento->percentage) && $descuento->percentage !== '') {
                $filas[] = [$descripcion, self::numero($descuento->percentage, 'porcentaje de descuento').'%'];
            } elseif (!is_null($descuento->monto) && $descuento->monto !== '') {
                $filas[] = [$descripcion, self::numero($descuento->monto, 'monto del descuento')];
            }
        }
        $total = self::numero($compra->total, 'total registrado de la compra');
        $iva_adicional = 0;
        if ($de_facturas) {
            $base = $total_facturas;
            $filas[] = ['Total de comprobantes', $base];
        } else {
            $subtotal = self::numero($compra->sub_total, 'subtotal registrado de artículos');
            $descuento = self::numero($compra->total_descuento, 'descuentos registrados');
            $filas[] = ['Subtotal registrado (cantidades efectivas)', $subtotal];
            $filas[] = ['Descuentos registrados de artículos y compra', -$descuento];
            $base = $subtotal - $descuento;
            // La condición fiscal y la cotización actuales pueden haber cambiado desde la compra.
            // Se reconstruye sólo el IVA efectivamente agregado al importe histórico guardado.
            $iva_adicional = round($total - $base - $extras, 2);
            if ($iva_adicional != 0) {
                self::conciliar(self::numero($compra->total_iva, 'IVA registrado de la compra'), $iva_adicional, 'El total contiene un ajuste que no coincide con el IVA registrado. Revisá los importes de la compra con soporte antes de imprimir.');
                self::conciliar($iva_facturas, $iva_adicional, 'El IVA registrado de la compra no coincide con sus comprobantes. Revisá el IVA con soporte antes de imprimir.');
                $filas[] = ['IVA agregado al total de artículos', $iva_adicional];
            }
        }
        self::conciliar($base + $extras + $iva_adicional, $total, 'El total registrado no coincide con la fuente elegida y los costos extra. Revisá la compra y sus comprobantes con soporte antes de imprimir; no modifiques los importes solo para hacerlos coincidir.');
        return ['filas' => $filas, 'total' => $total, 'moneda' => $moneda->name, 'de_facturas' => $de_facturas];
    }

    private static function numero($valor, $campo)
    {
        if (!is_numeric($valor) || !is_finite((float) $valor)) {
            self::rechazar('Falta un importe válido en '.$campo.'. Revisá ese dato de la compra con soporte antes de imprimir.');
        }
        return (float) $valor;
    }

    private static function conciliar($esperado, $registrado, $mensaje)
    {
        if (abs(round($esperado, 2) - round($registrado, 2)) > 0.000001) {
            self::rechazar($mensaje);
        }
    }

    private static function rechazar($mensaje)
    {
        throw ValidationException::withMessages(['compra' => [$mensaje]]);
    }
}
