<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\Afip\CandadoDeFacturaHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Operación puntual del ticket 134. Nunca se agenda ni se ejecuta durante el despliegue. */
class RepararFechaVentaSoporte134 extends Command
{
    protected $signature = 'soporte:reparar-fecha-venta-134
        {--aplicar : Escribir únicamente la fecha, con respaldo verificado y dentro de la ventana autorizada}
        {--revertir : Simular la reversión; combinar con aplicar para ejecutarla}
        {--respaldo= : JSON local; por defecto storage/app/soporte/134-fecha-venta.json}';

    protected $description = 'Simula la corrección exclusiva de la fecha de la venta interna 13691 de 3DTisk (ticket 134)';
    private const ANTERIOR = '2026-11-15 10:27:13';
    private const NUEVA = '2026-11-16 10:27:13';
    private const ACTUALIZADA = '2026-11-16 10:27:16';

    public function handle()
    {
        $ruta = $this->option('respaldo') ?: storage_path('app/soporte/134-fecha-venta.json');
        try {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);
            if ($host !== 'api-3dtisk.comerciocity.com') {
                throw new RuntimeException('Esta operación corresponde exclusivamente a la API de 3DTisk. Revisá la instalación antes de continuar.');
            }
            CandadoDeFacturaHelper::ventas([13691], function () use ($ruta) {
                DB::transaction(function () use ($ruta) {
                    $antes = $this->leer_estado();
                    $revertir = (bool) $this->option('revertir');
                    $origen = $revertir ? self::NUEVA : self::ANTERIOR;
                    $destino = $revertir ? self::ANTERIOR : self::NUEVA;
                    $respaldo = is_file($ruta) ? $this->leer_respaldo($ruta) : null;
                    if ($revertir && !$respaldo) {
                        throw new RuntimeException('Para revertir necesitás el respaldo original verificado. No se modificó nada.');
                    }
                    if (!in_array($antes['venta']['created_at'], [$origen, $destino], true)) {
                        throw new RuntimeException('La fecha ya no coincide con la diagnosticada ni con la reparada. Releé el caso antes de continuar.');
                    }
                    if ($respaldo && $this->sin_fecha($antes) !== $this->sin_fecha($respaldo['estado'])) {
                        throw new RuntimeException('La venta o sus movimientos cambiaron después del respaldo. Volvé a verificar el caso; no se escribió nada.');
                    }
                    $despues = $antes;
                    $despues['venta']['created_at'] = $destino;
                    $this->line(json_encode(['ticket' => 134, 'cliente_admin' => 38, 'api_admin' => 51,
                        'venta_id' => 13691, 'numero_visible' => 13684,
                        'modo' => $this->option('aplicar') ? 'aplicar' : 'simulacion', 'revertir' => $revertir,
                        'fecha_anterior' => $antes['venta']['created_at'], 'fecha_destino' => $destino,
                        'caja_y_stock' => 'Se conservan exactamente: el ingreso ya existe el 16/11.',
                        'reportes' => 'La venta y su comprobante interno pasan al día '.substr($destino, 0, 10).'; el comprobante fiscal, caja y stock conservan sus fechas.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if (!$this->option('aplicar')) {
                        $this->info('Simulación: no se escribieron datos ni archivos.');
                        return;
                    }
                    if ($antes === $despues) {
                        if (!$respaldo) {
                            throw new RuntimeException('La fecha coincide con el destino pero falta el respaldo original. No se atribuye la reparación.');
                        }
                        $this->info('La fecha ya coincide con el destino. No hay cambios.');
                        return;
                    }
                    if (!$respaldo) {
                        $this->guardar_respaldo($ruta, ['ticket' => 134, 'base' => DB::connection()->getDatabaseName(),
                            'host' => 'api-3dtisk.comerciocity.com', 'estado' => $antes]);
                        $respaldo = $this->leer_respaldo($ruta);
                    }
                    $this->registrar($ruta, ['evento' => 'preparado', 'revertir' => $revertir, 'desde' => $origen, 'hasta' => $destino]);
                    // Query Builder evita observers y no cambia updated_at, caja, stock, saldos ni comprobantes fiscales.
                    $afectadas = DB::table('sales')->where('id', 13691)->where('user_id', $antes['venta']['user_id'])
                        ->where('created_at', $origen)->where('updated_at', self::ACTUALIZADA)->update(['created_at' => $destino]);
                    if ($afectadas !== 1 || $this->leer_estado() !== $despues) {
                        throw new RuntimeException('La verificación posterior no coincide. Se revierte la transacción completa.');
                    }
                    $this->registrar($ruta, ['evento' => 'verificado_en_transaccion', 'revertir' => $revertir, 'fecha' => $destino]);
                });
            });
            $this->info('Verificación terminada. El ticket requiere verificación posterior en el negocio.');
            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function leer_estado(): array
    {
        $venta = DB::table('sales')->where('id', 13691)->lockForUpdate()->first();
        if (!$venta || (int) $venta->user_id !== 1200 || (int) $venta->num !== 13684 || $venta->client_id !== null
            || number_format((float) $venta->total, 2, '.', '') !== '61970.00'
            || $venta->updated_at !== self::ACTUALIZADA || $venta->deleted_at !== null || !empty($venta->actualizandose_por_id)
            || !empty($venta->is_consolidacion_facturacion) || !empty($venta->consolidacion_facturacion_id)) {
            throw new RuntimeException('La identidad o el estado de la venta no coincide con el ticket 134. Requiere un nuevo diagnóstico.');
        }
        $dueno = DB::table('users')->where('id', $venta->user_id)->first();
        if (!$dueno || $dueno->owner_id !== null || $dueno->deleted_at !== null) {
            throw new RuntimeException('El dueño de la venta no coincide con 3DTisk. No se modificó nada.');
        }
        $facturas = DB::table('afip_tickets')->where(function ($q) {
            $q->where('sale_id', 13691)->orWhere('sale_nota_credito_id', 13691);
        })->orderBy('id')->lockForUpdate()->get()->map(function ($fila) { return (array) $fila; })->all();
        if (count($facturas) !== 1 || (int) $facturas[0]['id'] !== 8424
            || $facturas[0]['afip_fecha_emision'] !== '2026-11-16' || $facturas[0]['created_at'] !== '2026-11-16 10:27:14'
            || number_format((float) $facturas[0]['importe_total'], 2, '.', '') !== '61970.00') {
            throw new RuntimeException('El comprobante fiscal no coincide con el comprobado para este caso. Requiere revisión, sin reemitirlo.');
        }
        foreach ($facturas as $factura) {
            if (empty($factura['cae']) || $factura['resultado'] !== 'A' || $factura['deleted_at'] !== null
                || !empty($factura['nota_credito_id']) || !empty($factura['sale_nota_credito_id'])) {
                throw new RuntimeException('Hay un intento fiscal pendiente, rechazado, eliminado o una nota de crédito. Revisalo antes de corregir la fecha.');
            }
        }
        if (DB::table('sale_modifications')->where('sale_id', 13691)->lockForUpdate()->first()) {
            throw new RuntimeException('Hay modificaciones de la venta posteriores al diagnóstico. Revisalas antes de corregir la fecha.');
        }
        if (DB::table('current_acounts')->where('sale_id', 13691)->lockForUpdate()->first()) {
            throw new RuntimeException('La venta tiene movimientos de cuenta corriente. Requiere verificar su fecha y orden antes de aplicar esta reparación.');
        }
        $stock = $this->filas('stock_movements');
        if (array_map('intval', array_column($stock, 'id')) !== [31801, 31802, 31803]) {
            throw new RuntimeException('Los movimientos de stock no coinciden exactamente con los tres diagnosticados.');
        }
        $articulos = [31801 => 10, 31802 => 41, 31803 => 57];
        foreach ($stock as $fila) {
            if ($fila['created_at'] !== self::NUEVA || $fila['updated_at'] !== self::NUEVA || (int) $fila['user_id'] !== (int) $venta->user_id
                || (int) $fila['article_id'] !== $articulos[(int) $fila['id']] || (float) $fila['amount'] !== -1.0) {
                throw new RuntimeException('La fecha o el dueño de los movimientos de stock cambiaron. Requiere revisión.');
            }
        }
        $caja = $this->filas('movimiento_cajas');
        if (count($caja) !== 1 || (int) $caja[0]['id'] !== 13236 || (int) $caja[0]['caja_id'] !== 5
            || $caja[0]['created_at'] !== self::NUEVA || $caja[0]['updated_at'] !== self::NUEVA
            || $caja[0]['current_acount_id'] !== null || number_format((float) $caja[0]['ingreso'], 2, '.', '') !== '61970.00'
            || (float) $caja[0]['egreso'] !== 0.0) {
            throw new RuntimeException('El ingreso de caja no coincide con el pago comprobado. No se duplica ni se mueve: requiere revisión.');
        }
        $caja_dueno = DB::table('cajas')->where('id', 5)->first();
        if (!$caja_dueno || (int) $caja_dueno->user_id !== (int) $venta->user_id) {
            throw new RuntimeException('La caja del ingreso no pertenece al dueño de la venta.');
        }
        return ['venta' => (array) $venta, 'stock' => $stock, 'caja' => $caja, 'comprobantes_fiscales' => $facturas,
            'articulos' => $this->filas('article_sale'), 'metodos_de_pago' => $this->filas('current_acount_payment_method_sale')];
    }

    private function filas(string $tabla): array
    {
        return DB::table($tabla)->where('sale_id', 13691)->orderBy('id')->lockForUpdate()->get()
            ->map(function ($fila) { return (array) $fila; })->all();
    }

    private function sin_fecha(array $estado): array
    {
        unset($estado['venta']['created_at']);
        return $estado;
    }

    private function leer_respaldo(string $ruta): array
    {
        $datos = json_decode(file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        if (($datos['ticket'] ?? null) !== 134 || ($datos['base'] ?? null) !== DB::connection()->getDatabaseName()
            || ($datos['host'] ?? null) !== 'api-3dtisk.comerciocity.com'
            || ($datos['estado']['venta']['created_at'] ?? null) !== self::ANTERIOR
            || ($datos['estado']['venta']['id'] ?? null) !== 13691
            || ($datos['sha256'] ?? null) !== hash('sha256', json_encode($datos['estado'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('El respaldo está incompleto o no corresponde a esta base y reparación. No se escribió nada.');
        }
        return $datos;
    }

    private function guardar_respaldo(string $ruta, array $datos): void
    {
        $datos['sha256'] = hash('sha256', json_encode($datos['estado'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $contenido = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_dir(dirname($ruta)) && !mkdir(dirname($ruta), 0700, true) && !is_dir(dirname($ruta))) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldo.');
        }
        $archivo = fopen($ruta, 'x');
        if (!$archivo) {
            throw new RuntimeException('No se pudo crear el respaldo exclusivo. No se escribió en la base.');
        }
        try {
            if (fwrite($archivo, $contenido) !== strlen($contenido) || !fflush($archivo)) {
                throw new RuntimeException('El respaldo no se escribió completo.');
            }
        } finally {
            fclose($archivo);
        }
        @chmod($ruta, 0600);
        if (file_get_contents($ruta) !== $contenido) {
            throw new RuntimeException('El respaldo no superó la relectura.');
        }
    }

    private function registrar(string $ruta, array $evento): void
    {
        $linea = json_encode(array_merge(['instante' => gmdate('c'), 'ticket' => 134], $evento), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($ruta.'.auditoria.jsonl', $linea, FILE_APPEND | LOCK_EX) !== strlen($linea)) {
            throw new RuntimeException('No se pudo registrar la auditoría. No se confirma la transacción.');
        }
        @chmod($ruta.'.auditoria.jsonl', 0600);
    }
}
