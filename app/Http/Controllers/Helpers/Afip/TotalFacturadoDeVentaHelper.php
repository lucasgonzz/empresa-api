<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Models\AfipTicket;
use App\Models\AfipInformation;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** El saldo es una proyección de comprobantes, nunca un efecto acumulativo de consultar ARCA. */
class TotalFacturadoDeVentaHelper
{
    const FACTURAS = [1, 6, 11, 19, 51, 201, 206, 211];
    const NOTAS = [3, 8, 13, 21, 53, 203, 208, 213];

    /** Lectura pura para el flujo fiscal y para la reparación operativa. No consulta ARCA. */
    public static function analizar(Sale $venta, $excluir_ticket_id = null): array
    {
        $facturas_ids = AfipTicket::withTrashed()->where('sale_id', $venta->id)->pluck('id')->all();
        $tickets = AfipTicket::withTrashed()->where(function ($q) use ($venta, $facturas_ids) {
            $q->where('sale_id', $venta->id)->orWhere('sale_nota_credito_id', $venta->id);
            if ($facturas_ids) {
                $q->orWhereIn('sale_afip_ticket_id', $facturas_ids);
            }
        })->orderBy('id')->get();
        $motivos = [];
        $comprobantes = [];
        $facturas = [];
        $notas = [];
        $monedas = [];
        foreach ($tickets as $ticket) {
            if ($excluir_ticket_id !== null && (int) $ticket->id === (int) $excluir_ticket_id) {
                continue;
            }
            $cae = trim((string) $ticket->cae);
            if ($cae === '' && $ticket->resultado !== 'A') {
                if (trim((string) $ticket->cbte_numero) !== '' && $ticket->resultado !== 'R') {
                    $motivos[] = 'El intento '.$ticket->id.' tiene numeración fiscal y respuesta pendiente; consultalo antes de revisar el acumulado.';
                }
                continue;
            }
            $id = (int) $ticket->id;
            $codigo = (int) $ticket->cbte_tipo;
            $es_nota = in_array($codigo, self::NOTAS, true);
            $centavos = self::centavos($ticket->importe_total);
            $moneda = trim((string) $ticket->moneda_id);
            $legacy = trim((string) $ticket->moneda);
            if ($moneda === '') {
                $moneda = $legacy;
            }
            $emisor = preg_replace('/\D/', '', (string) $ticket->cuit_negocio);
            if ($ticket->afip_information_id) {
                $configuracion = AfipInformation::find($ticket->afip_information_id);
                if ($configuracion && (int) $configuracion->user_id !== (int) $venta->user_id) {
                    $motivos[] = 'El emisor asociado al comprobante '.$id.' pertenece a otro negocio; necesita revisión.';
                }
            }
            $identidad = $emisor.':'.(int) $ticket->punto_venta.':'.$codigo.':'.(int) $ticket->cbte_numero;
            $comprobantes[] = [
                'id' => $id, 'sale_id' => $ticket->sale_id, 'sale_nota_credito_id' => $ticket->sale_nota_credito_id,
                'sale_afip_ticket_id' => $ticket->sale_afip_ticket_id, 'tipo' => $codigo,
                'numero' => $ticket->cbte_numero, 'punto_venta' => $ticket->punto_venta,
                'importe' => $ticket->importe_total, 'moneda' => $moneda, 'resultado' => $ticket->resultado,
                'autorizado' => $cae !== '', 'eliminado' => $ticket->deleted_at !== null,
                // La firma detecta cambios sin exponer CAE, CUIT ni los XML de la operación.
                'identidad' => hash('sha256', $identidad.':'.$cae),
            ];
            if ($cae === '' || $ticket->resultado !== 'A') {
                $motivos[] = 'El comprobante '.$id.' tiene una autorización incompleta o contradictoria.';
            }
            if ($ticket->deleted_at !== null) {
                $motivos[] = 'El comprobante autorizado '.$id.' está eliminado localmente; necesita revisión fiscal.';
            }
            if ($centavos === null || $centavos <= 0) {
                $motivos[] = 'El comprobante '.$id.' no tiene un importe fiscal positivo verificable.';
            }
            if (!in_array($moneda, ['PES', 'DOL'], true) || ($legacy !== '' && $legacy !== $moneda)
                || ($codigo !== 19 && $codigo !== 21 && $moneda !== 'PES')) {
                $motivos[] = 'La moneda fiscal del comprobante '.$id.' necesita revisión.';
            }
            if (strlen($emisor) !== 11 || !self::numero_fiscal($ticket->punto_venta, 5)
                || !self::numero_fiscal($ticket->cbte_numero, 8) || !self::numero_fiscal($ticket->cbte_tipo, 3)) {
                $motivos[] = 'La identidad fiscal del comprobante '.$id.' está incompleta.';
            } else {
                // Un CAE puede corresponder a un lote; la identidad fiscal incluye emisor y número.
                $duplicado = AfipTicket::withTrashed()->where('id', '<>', $id)
                    ->whereNotNull('cae')->where('cae', '<>', '')
                    ->whereRaw('CAST(punto_venta AS UNSIGNED) = ?', [(int) $ticket->punto_venta])
                    ->whereRaw('CAST(cbte_tipo AS UNSIGNED) = ?', [$codigo])
                    ->whereRaw('CAST(cbte_numero AS UNSIGNED) = ?', [(int) $ticket->cbte_numero])
                    ->get()->contains(function ($otro) use ($emisor) {
                        return preg_replace('/\D/', '', (string) $otro->cuit_negocio) === $emisor;
                    });
                if ($duplicado) {
                    $motivos[] = 'El comprobante '.$id.' tiene una identidad o autorización fiscal duplicada.';
                }
            }
            if ($es_nota) {
                $notas[] = [$ticket, $centavos, $moneda];
            } else if (in_array($codigo, self::FACTURAS, true) && (int) $ticket->sale_id === (int) $venta->id
                && !$ticket->sale_nota_credito_id && !$ticket->nota_credito_id && !$ticket->sale_afip_ticket_id) {
                $facturas[$id] = ['ticket' => $ticket, 'neto' => $centavos ?: 0, 'moneda' => $moneda];
            } else {
                $motivos[] = 'El tipo o vínculo del comprobante '.$id.' no corresponde a una factura de esta venta.';
            }
        }
        foreach ($notas as $nota) {
            list($ticket, $centavos, $moneda) = $nota;
            $padre = $facturas[(int) $ticket->sale_afip_ticket_id] ?? null;
            if (!$padre || (int) $ticket->sale_nota_credito_id !== (int) $venta->id || $ticket->sale_id
                || $padre['moneda'] !== $moneda
                || (int) $ticket->cbte_tipo !== (int) $padre['ticket']->cbte_tipo + 2
                || preg_replace('/\D/', '', (string) $padre['ticket']->cuit_negocio) !== preg_replace('/\D/', '', (string) $ticket->cuit_negocio)) {
                $motivos[] = 'La nota de crédito '.$ticket->id.' no tiene un vínculo fiscal verificable con una factura de esta venta.';
                continue;
            }
            $facturas[(int) $ticket->sale_afip_ticket_id]['neto'] -= $centavos ?: 0;
        }
        $neto = 0;
        foreach ($facturas as $factura) {
            if ($factura['neto'] < 0) {
                $motivos[] = 'Las notas de crédito superan el importe de la factura '.$factura['ticket']->id.'.';
            }
            if ($factura['neto'] > 0) {
                $monedas[$factura['moneda']] = true;
            }
            if ($factura['neto'] > 999999999999999 - $neto) {
                $motivos[] = 'El saldo fiscal supera el rango que puede verificarse en centavos; necesita revisión antes de modificarlo.';
                continue;
            }
            $neto += $factura['neto'];
        }
        if (count($monedas) > 1) {
            $motivos[] = 'La venta tiene saldos fiscales en más de una moneda; no se pueden sumar sin una revisión.';
        }
        $motivos = array_values(array_unique($motivos));
        $total = $motivos ? null : intdiv($neto, 100).'.'.str_pad((string) ($neto % 100), 2, '0', STR_PAD_LEFT);
        $moneda = count($monedas) === 1 ? array_keys($monedas)[0] : null;
        return [
            'estado' => $motivos ? 'revision' : 'verificable', 'total' => $total, 'moneda' => $moneda,
            'motivos' => $motivos, 'comprobantes' => $comprobantes,
            'huella' => hash('sha256', json_encode([$venta->id, $venta->user_id, $comprobantes, $motivos], JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    /** Nunca revierte un CAE ya recibido por una discrepancia del acumulado local. */
    public static function actualizar($venta_id, $ticket_id = null, bool $nueva_autorizacion = false): array
    {
        return CandadoDeFacturaHelper::ventas([(int) $venta_id], function () use ($venta_id, $ticket_id, $nueva_autorizacion) {
            return DB::transaction(function () use ($venta_id, $ticket_id, $nueva_autorizacion) {
                $venta = Sale::where('id', $venta_id)->lockForUpdate()->first();
                if (!$venta) {
                    return ['estado' => 'revision', 'total' => null, 'moneda' => null, 'comprobantes' => [],
                        'motivos' => ['La venta del comprobante ya no está disponible.']];
                }
                $analisis = self::analizar($venta);
                if ($analisis['estado'] === 'verificable') {
                    $actual = self::centavos($venta->total_facturado ?? 0);
                    $esperado = self::centavos($analisis['total']);
                    if ($actual !== $esperado) {
                        $anterior = $nueva_autorizacion && $ticket_id ? self::analizar($venta, $ticket_id) : null;
                        if ($anterior && $anterior['estado'] === 'verificable' && $actual === self::centavos($anterior['total'])) {
                            // Sólo el nuevo documento cambia el saldo. Los desvíos históricos requieren
                            // la reparación operativa con respaldo y ventana; consultar no la reemplaza.
                            Sale::where('id', $venta->id)->update(['total_facturado' => $analisis['total']]);
                        } else {
                            $analisis['estado'] = 'revision';
                            $analisis['motivos'][] = 'El total facturado guardado no coincide con los comprobantes autorizados de la venta.';
                        }
                    }
                }
                if ($analisis['estado'] !== 'verificable') {
                    Log::warning('Saldo fiscal pendiente de revisión', ['sale_id' => $venta->id, 'motivos' => $analisis['motivos']]);
                }
                return $analisis;
            });
        });
    }

    public static function exigir_verificable(array $analisis): void
    {
        if ($analisis['estado'] !== 'verificable') {
            FacturaDeVentaHelper::rechazar(implode(' ', $analisis['motivos']).' No vuelvas a emitir para corregir el acumulado. Pedí a soporte revisar los comprobantes y reparar el total facturado.');
        }
    }

    /** Aviso posterior al commit: una factura autorizada conserva su estado aunque el saldo requiera revisión. */
    public static function aviso(Sale $venta): ?string
    {
        $analisis = self::analizar($venta);
        if ($analisis['estado'] === 'verificable' && self::centavos($venta->total_facturado ?? 0) === self::centavos($analisis['total'])) {
            return null;
        }
        $motivos = $analisis['motivos'];
        if (!$motivos) {
            $motivos[] = 'El total facturado guardado no coincide con los comprobantes autorizados.';
        }
        return implode(' ', $motivos).' El comprobante autorizado quedó guardado. No vuelvas a emitir para corregir el acumulado: pedí a soporte revisar y reparar el total facturado.';
    }

    private static function centavos($valor)
    {
        $texto = (string) $valor;
        if (!preg_match('/^([0-9]{1,13})(?:\.([0-9]{1,2}))?$/D', $texto, $partes)) {
            return null;
        }
        return (int) $partes[1] * 100 + (int) str_pad($partes[2] ?? '', 2, '0');
    }

    private static function numero_fiscal($valor, int $longitud): bool
    {
        return preg_match('/^[0-9]{1,'.$longitud.'}$/D', (string) $valor) === 1 && (int) $valor > 0;
    }
}
