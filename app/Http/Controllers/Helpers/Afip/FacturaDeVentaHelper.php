<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\Helpers\asistente_ia\PermisosIaHelper;
use App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper;
use App\Http\Controllers\Helpers\sale\ListadoVentasHelper;
use App\Models\AfipInformation;
use App\Models\AfipTipoComprobante;
use App\Models\AfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Prepara lo que se confirma y valida de nuevo lo que efectivamente se va a facturar. */
class FacturaDeVentaHelper
{
    const CODIGOS = [1, 6, 11, 51, 201, 206, 211, 19];

    public static function intento_incierto($venta_id)
    {
        // El borrado local no deshace un envío fiscal: también se conserva la evidencia histórica oculta.
        return AfipTicket::withTrashed()->where('sale_id', $venta_id)
            ->whereNull('nota_credito_id')->whereNull('sale_nota_credito_id')
            ->where(function ($q) { $q->whereNull('cae')->orWhere('cae', ''); })
            ->whereNotNull('cbte_numero')->whereRaw("TRIM(cbte_numero) != ''")
            ->where(function ($q) { $q->whereNull('resultado')->orWhere('resultado', '!=', 'R'); })
            ->orderBy('id')->first();
    }

    public static function facturas_autorizadas($venta_id)
    {
        return AfipTicket::withTrashed()->where('sale_id', $venta_id)
            ->whereNull('nota_credito_id')->whereNull('sale_nota_credito_id')
            ->whereNotNull('cae')->where('cae', '!=', '');
    }

    public static function datos_del_cuerpo(array $cuerpo): array
    {
        return [
            'sale_id' => $cuerpo['sale_id'] ?? null,
            'afip_information_id' => $cuerpo['ventas_afip_information_id'] ?? null,
            'afip_tipo_comprobante_id' => $cuerpo['afip_tipo_comprobante_id'] ?? null,
            'afip_fecha_emision' => $cuerpo['afip_fecha_emision'] ?? null,
            'facturar_importe_personalizado' => $cuerpo['monto_a_facturar'] ?? null,
            'importe_personalizado_ivas' => $cuerpo['importe_personalizado_ivas'] ?? null,
            'forma_de_pago' => $cuerpo['forma_de_pago'] ?? null,
            'permiso_existente' => $cuerpo['permiso_existente'] ?? null,
            'incoterms' => $cuerpo['incoterms'] ?? null,
        ];
    }

    public static function es_id($valor): bool
    {
        return (is_int($valor) || is_string($valor)) && preg_match('/^[1-9][0-9]*$/D', (string) $valor) === 1;
    }

    public static function venta($id, $owner_id, $persona = null)
    {
        if (!self::es_id($id)) {
            self::rechazar('Elegí una venta de este negocio para facturar.');
        }
        $consulta = Sale::where('user_id', $owner_id)->where('id', $id);
        if ($persona) {
            if (!PermisosIaHelper::puede($persona, 'sale.index') && !PermisosIaHelper::puede($persona, 'sale.store')) {
                self::rechazar('No tenés permiso para facturar ventas desde tu usuario.');
            }
            // El mismo alcance de Ventas; la propuesta en un job no tiene Auth y sólo acepta admin.
            if (!PermisosIaHelper::es_admin($persona) && !PermisosIaHelper::puede($persona, 'sale.index')) {
                // Vender autoriza terminar la factura de la venta propia recién creada, sin abrir
                // por efecto colateral todo el historial de Ventas a quien no puede consultarlo.
                $consulta->where('employee_id', $persona->id)->whereDate('sales.created_at', Carbon::today()->toDateString());
            } else if (Auth::id() && (int) Auth::id() === (int) $persona->id) {
                ListadoVentasHelper::aplicar_alcance($consulta);
                if (ListadoVentasHelper::solo_hoy_permitido()) {
                    $consulta->whereDate('sales.created_at', Carbon::today()->toDateString());
                }
            }
        }
        $venta = $consulta->first();
        if (!$venta) {
            self::rechazar('La venta no existe o no está disponible para tu usuario.');
        }
        $motivo = ConsolidarFacturacionHelper::motivo_por_el_que_no_se_puede_facturar_suelta($venta);
        if ($motivo !== null) {
            self::rechazar($motivo);
        }
        return $venta;
    }

    public static function opciones_emisor($owner_id): array
    {
        return AfipInformation::where('user_id', $owner_id)->orderBy('id')->get()->filter(function ($emisor) {
            return strlen(preg_replace('/\D/', '', (string) $emisor->cuit)) === 11
                && (int) $emisor->punto_venta > 0 && $emisor->iva_condition;
        })->map(function ($emisor) {
            return ['id' => $emisor->id, 'nombre' => self::nombre_emisor($emisor), 'punto_venta' => $emisor->punto_venta];
        })->values()->all();
    }

    public static function opciones_tipo(): array
    {
        return AfipTipoComprobante::whereIn('codigo', self::CODIGOS)->orderBy('id')->get(['id', 'name', 'codigo'])->toArray();
    }

    public static function configuracion($emisor_id, $tipo_id, $owner_id): array
    {
        if (!self::es_id($emisor_id)) {
            self::rechazar('Elegí el emisor y el punto de venta con el que querés facturar.');
        }
        $emisor = AfipInformation::where('user_id', $owner_id)->find($emisor_id);
        if (!$emisor) {
            self::rechazar('El emisor no existe o no está disponible para este negocio. Elegí uno de sus puntos de venta.');
        }
        if (!preg_match('/^[0-9]{11}$/D', preg_replace('/\D/', '', (string) $emisor->cuit))
            || !preg_match('/^[0-9]+$/D', (string) $emisor->punto_venta) || (int) $emisor->punto_venta <= 0 || !$emisor->iva_condition) {
            self::rechazar('La configuración del emisor está incompleta. Revisá su CUIT, punto de venta y condición de IVA antes de facturar.');
        }
        if (!self::es_id($tipo_id)) {
            self::rechazar('Elegí el tipo de comprobante que querés emitir.');
        }
        $tipo = AfipTipoComprobante::find($tipo_id);
        if (!$tipo || !in_array((int) $tipo->codigo, self::CODIGOS, true)) {
            self::rechazar('Elegí un tipo de factura válido.');
        }
        return [$emisor, $tipo];
    }

    public static function preparar(array $data, $owner_id, $persona = null): array
    {
        $data = array_intersect_key($data, array_flip([
            'sale_id', 'afip_information_id', 'afip_tipo_comprobante_id', 'afip_fecha_emision',
            'facturar_importe_personalizado', 'importe_personalizado_ivas', 'forma_de_pago', 'permiso_existente', 'incoterms',
        ])) + ['importe_personalizado_ivas' => null, 'forma_de_pago' => null, 'permiso_existente' => null, 'incoterms' => null];
        foreach (['forma_de_pago', 'permiso_existente', 'incoterms'] as $campo) {
            if ($data[$campo] !== null && (!is_string($data[$campo]) || mb_strlen($data[$campo]) > 255)) {
                self::rechazar('Los datos opcionales de la factura tienen que ser textos válidos: revisá '.$campo.'.');
            }
        }
        if ($data['permiso_existente'] !== null && $data['permiso_existente'] !== '' && !in_array($data['permiso_existente'], ['S', 'N'], true)) {
            self::rechazar('Indicá S o N en el permiso de exportación.');
        }
        $data['permiso_existente'] = $data['permiso_existente'] ?: 'N';
        $data['forma_de_pago'] = $data['forma_de_pago'] === '' ? null : $data['forma_de_pago'];
        $venta = self::venta($data['sale_id'] ?? null, $owner_id, $persona);
        list($emisor, $tipo) = self::configuracion($data['afip_information_id'] ?? null, $data['afip_tipo_comprobante_id'] ?? null, $owner_id);
        $fecha = $data['afip_fecha_emision'] ?? null;
        $fecha = ($fecha === null || $fecha === '') ? Carbon::today()->toDateString() : $fecha;
        if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) {
            self::rechazar('La fecha de emisión tiene que ser una fecha válida (AAAA-MM-DD).');
        }
        $partes = explode('-', $fecha);
        if (!checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0])) {
            self::rechazar('La fecha de emisión no es válida.');
        }
        $codigo = (int) $tipo->codigo;
        $data['incoterms'] = $data['incoterms'] ?: ($venta->incoterms ?: ($codigo === 19 ? 'FOB' : null));
        $cliente = $venta->client;
        if ($venta->client_id && (!$cliente || (int) $cliente->user_id !== (int) $owner_id)) {
            self::rechazar('El cliente de la venta ya no está disponible para este negocio.');
        }
        if (in_array($codigo, [1, 51, 201, 206, 211], true)
            && (!$cliente || strlen(preg_replace('/\D/', '', (string) $cliente->cuit)) !== 11)) {
            self::rechazar('Este tipo de comprobante necesita un cliente con CUIT válido. Revisá el cliente o elegí el tipo que corresponda.');
        }
        if ($codigo === 19 && (!$cliente || !$cliente->pais_exportacion || !$cliente->pais_exportacion->codigo_afip
            || !$cliente->name || !$cliente->address || !$cliente->cuit)) {
            self::rechazar('Para la factura de exportación completá el cliente, su identificación fiscal, domicilio y país de exportación.');
        }
        if ($codigo === 19 && $venta->articles->isEmpty() && $venta->services->isEmpty()) {
            self::rechazar('La factura de exportación necesita renglones de artículos o servicios válidos antes de emitir.');
        }
        if ((int) $venta->moneda_id === 2 && (!is_numeric($venta->valor_dolar) || (float) $venta->valor_dolar <= 0)) {
            self::rechazar('La venta en dólares necesita una cotización válida antes de facturar.');
        }
        $monto = $data['facturar_importe_personalizado'] ?? null;
        if ($monto !== null && $monto !== '' && $monto !== 0 && $monto !== '0'
            && (!is_numeric($monto) || (float) $monto <= 0 || !is_finite((float) $monto))) {
            self::rechazar('El importe a facturar tiene que ser un número positivo.');
        }
        $monto = is_numeric($monto) && (float) $monto > 0 ? round((float) $monto, 2) : null;
        $reparto = MakeAfipTicket::validar_filas_importe_personalizado($data['importe_personalizado_ivas']);
        if ($monto !== null && $reparto['error'] !== null) {
            self::rechazar($reparto['error']);
        }
        $data['importe_personalizado_ivas'] = $monto !== null ? $reparto['filas'] : [];
        if ($monto !== null && count($data['importe_personalizado_ivas']) > 0
            && abs(array_sum(array_column($data['importe_personalizado_ivas'], 'importe')) - $monto) > 0.01) {
            self::rechazar('La suma del reparto por alícuota no coincide con el importe a facturar.');
        }
        if ($codigo === 19 && $monto !== null) {
            self::rechazar('La factura de exportación se emite por el total de la venta. Quitá el importe personalizado y revisá el total antes de confirmar.');
        }
        $metodos_fiscales = \App\Models\AfipSelectedPaymentMethod::where('user_id', $venta->user_id)->orderBy('id')->get();
        $total = $codigo === 19 ? (float) $venta->total : MakeAfipTicket::get_tope_en_pesos($venta, $emisor->id, $tipo->id, $metodos_fiscales);
        $importe = $monto !== null ? $monto : $total;
        if (!is_numeric($importe) || !is_finite((float) $importe) || (float) $importe <= 0) {
            self::rechazar('La venta no tiene un importe válido para facturar. Revisá sus renglones e importes.');
        }
        // También bajo el candado: una edición pudo cambiar el total después del precheck del
        // controller. Se conserva la excepción del recargo global (descuento negativo), cuyo
        // cálculo fiscal todavía no representa ese recargo y no sirve como techo.
        if ($monto !== null && $total > 0 && (float) $venta->descuento >= 0 && $monto > $total + 0.01) {
            self::rechazar('El importe a facturar no puede superar el total de la venta en pesos. Revisá el importe antes de confirmar.');
        }
        // total_facturado se ajusta también al emitir notas de crédito: conserva la refacturación y las porciones.
        $ya_facturado = self::total_facturado_verificado($venta, $codigo);
        if ($ya_facturado > 0 && ($monto === null || $ya_facturado + $importe > $total + 0.01)) {
            self::rechazar('La venta ya está facturada por ese importe. Revisá sus comprobantes y el saldo pendiente antes de emitir otro.');
        }
        $data['sale_id'] = (int) $venta->id;
        $data['afip_information_id'] = (int) $emisor->id;
        $data['afip_tipo_comprobante_id'] = (int) $tipo->id;
        $data['afip_fecha_emision'] = $fecha;
        $data['facturar_importe_personalizado'] = $monto;
        ksort($data);
        $resumen = [
            ['etiqueta' => 'Venta', 'valor' => 'N° '.($venta->num ?? $venta->id)],
            ['etiqueta' => 'Cliente', 'valor' => $cliente ? $cliente->name : 'Consumidor final'],
            ['etiqueta' => 'Emisor', 'valor' => self::nombre_emisor($emisor)],
            ['etiqueta' => 'Punto de venta', 'valor' => (string) $emisor->punto_venta],
            ['etiqueta' => 'Comprobante', 'valor' => $tipo->name],
            ['etiqueta' => 'Fecha de emisión', 'valor' => $fecha],
            ['etiqueta' => 'Importe', 'valor' => ($codigo === 19 && (int) $venta->moneda_id === 2 ? 'USD ' : '$ ').number_format($importe, 2, ',', '.')],
        ];
        $huella = self::firma($venta, $emisor, $tipo, $data, $importe, $metodos_fiscales);
        $firma_contenido = self::firma($venta, $emisor, $tipo, $data, $importe, $metodos_fiscales, true);
        return compact('venta', 'emisor', 'tipo', 'data', 'resumen', 'huella', 'firma_contenido', 'importe', 'metodos_fiscales');
    }

    private static function nombre_emisor($emisor): string
    {
        return (string) ($emisor->razon_social ?: ($emisor->owner_name ?: ($emisor->description ?: 'Emisor '.$emisor->id)));
    }

    public static function reconstruir_intento($ticket, $owner_id): array
    {
        $filas = null;
        if ($ticket->importe_personalizado_ivas_json !== null) {
            $filas = json_decode($ticket->importe_personalizado_ivas_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::rechazar('No se puede verificar el reparto fiscal guardado del intento. Revisalo antes de consultar.');
            }
        }
        $preparada = self::preparar([
            'sale_id' => $ticket->sale_id, 'afip_information_id' => $ticket->afip_information_id,
            'afip_tipo_comprobante_id' => $ticket->afip_tipo_comprobante_id, 'afip_fecha_emision' => $ticket->afip_fecha_emision,
            'facturar_importe_personalizado' => $ticket->facturar_importe_personalizado,
            'importe_personalizado_ivas' => $filas, 'forma_de_pago' => $ticket->forma_de_pago,
            'permiso_existente' => $ticket->permiso_existente,
        ], $owner_id);
        if (!is_string($ticket->firma_datos_fiscales)
            || !hash_equals($ticket->firma_datos_fiscales, $preparada['firma_contenido'])) {
            self::rechazar('Los datos fiscales cambiaron desde el intento pendiente. Revisá ese comprobante antes de consultar o volver a emitir.');
        }
        return $preparada;
    }

    public static function fijar_contexto($ticket, array $preparada): void
    {
        $ticket->setRelation('sale', $preparada['venta']);
        $ticket->setRelation('afip_information', $preparada['emisor']);
        $ticket->setRelation('afip_tipo_comprobante', $preparada['tipo']);
        $ticket->setRelation('metodos_fiscales_confirmados', $preparada['metodos_fiscales']);
        $ticket->setRelation('dueno_fiscal_confirmado', $preparada['venta']->user);
    }

    private static function total_facturado_verificado($venta, $codigo_nuevo): float
    {
        $facturas = self::facturas_autorizadas($venta->id)->with('nota_credito_afip')->get();
        $neto = 0;
        foreach ($facturas as $factura) {
            $moneda = self::moneda_del_comprobante($factura);
            if (!is_numeric($factura->importe_total) || (float) $factura->importe_total <= 0) {
                self::rechazar('La venta tiene un comprobante autorizado cuyo saldo fiscal necesita revisión antes de volver a emitir.');
            }
            $importe = (float) $factura->importe_total;
            foreach ($factura->nota_credito_afip as $nota) {
                if (!IntentosDeFacturaFallidosHelper::tiene_cae($nota)) {
                    continue;
                }
                if (!is_numeric($nota->importe_total) || (float) $nota->importe_total <= 0
                    || self::moneda_del_comprobante($nota) !== $moneda
                    || ($nota->sale_nota_credito_id && (int) $nota->sale_nota_credito_id !== (int) $venta->id)) {
                    self::rechazar('La venta tiene una nota de crédito cuyo vínculo o importe fiscal necesita revisión.');
                }
                $importe -= (float) $nota->importe_total;
            }
            $tipo_anterior = $factura->afip_tipo_comprobante;
            if (!$tipo_anterior || ((int) $tipo_anterior->codigo !== 19 && $moneda !== 'PES')
                || ((int) $tipo_anterior->codigo === 19 && $moneda !== ((int) $venta->moneda_id === 2 ? 'DOL' : 'PES'))
                || $importe < -0.01) {
                self::rechazar('El saldo de los comprobantes de la venta necesita revisión antes de volver a emitir.');
            }
            $moneda_nueva = (int) $codigo_nuevo === 19 && (int) $venta->moneda_id === 2 ? 'DOL' : 'PES';
            if ($importe > 0.01 && $moneda !== $moneda_nueva) {
                self::rechazar('La venta tiene saldo facturado en otra moneda fiscal. Revisá los comprobantes antes de cambiar el tipo de factura.');
            }
            $neto += $importe;
        }
        if (abs($neto - (float) $venta->total_facturado) > 0.01) {
            self::rechazar('El saldo fiscal de la venta no coincide con sus comprobantes autorizados. Revisalos antes de volver a emitir.');
        }
        return max(0, $neto);
    }

    private static function moneda_del_comprobante($ticket): string
    {
        $id = trim((string) $ticket->moneda_id);
        $legacy = trim((string) $ticket->moneda);
        if (($id !== '' && $legacy !== '' && $id !== $legacy)
            || !in_array($id !== '' ? $id : $legacy, ['PES', 'DOL'], true)) {
            self::rechazar('La moneda fiscal de un comprobante necesita revisión antes de volver a emitir.');
        }
        return $id !== '' ? $id : $legacy;
    }

    private static function firma($venta, $emisor, $tipo, array $data, $importe, $metodos_fiscales, bool $solo_contenido = false): string
    {
        unset($data['firma_fiscal_ia']);
        $detalle = [];
        foreach (['articles', 'services', 'combos', 'promocion_vinotecas', 'discounts', 'surchages', 'current_acount_payment_methods'] as $relacion) {
            $detalle[$relacion] = $venta->{$relacion}->map(function ($item) {
                return [$item->getAttributes(), $item->pivot ? $item->pivot->getAttributes() : null,
                    $item instanceof \App\Models\Article && $item->iva ? $item->iva->getAttributes() : null];
            })->all();
        }
        $atributos_venta = $venta->getAttributes();
        // Estos campos son efectos de procesar el intento, no un cambio en lo que se pidió a ARCA.
        foreach (['updated_at', 'ganancia', 'incoterms', 'total_a_facturar', 'total_facturado'] as $campo) {
            unset($atributos_venta[$campo]);
        }
        $datos = [
            $atributos_venta, $venta->client ? $venta->client->getAttributes() : null,
            $emisor->getAttributes(), $emisor->iva_condition->getAttributes(),
            $venta->client && $venta->client->iva_condition ? $venta->client->iva_condition->getAttributes() : null,
            (int) $tipo->codigo === 19 && $venta->client && $venta->client->pais_exportacion ? $venta->client->pais_exportacion->getAttributes() : null,
            $tipo->getAttributes(), $data, $importe, $detalle,
            $metodos_fiscales->toArray(),
        ];
        if (!$solo_contenido) {
            $datos[] = $venta->total_facturado;
            $datos[] = $venta->afip_tickets()->with('nota_credito_afip')->get()->toArray();
        }
        // Sólo se guarda el hash; certificados, datos privados y estructura interna no van a la tarjeta.
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            self::rechazar('No se pudieron verificar los datos fiscales de la propuesta. Revisá los datos de la venta antes de confirmar.');
        }
        return hash_hmac('sha256', $json, config('app.key'));
    }

    public static function verificar_firma(array $preparada, $firma): void
    {
        if (!is_string($firma) || !hash_equals($preparada['huella'], $firma)) {
            self::rechazar('Los datos de la factura cambiaron desde la propuesta. Pedí una nueva tarjeta, revisá el emisor, fecha e importe y volvé a confirmar.');
        }
    }

    public static function rechazar($mensaje): void
    {
        $error = ValidationException::withMessages(['facturacion' => $mensaje]);
        $error->response = response()->json(['message' => $mensaje], 422);
        throw $error;
    }
}
