<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\Afip\CandadoDeFacturaHelper;
use App\Http\Controllers\Helpers\Afip\TotalFacturadoDeVentaHelper;
use App\Models\Sale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Operación manual: jamás se agenda ni se aplica automáticamente durante un upgrade. */
class RepararTotalFacturado extends Command
{
    protected $signature = 'soporte:reparar-total-facturado
        {--negocio= : ID del usuario dueño de este negocio}
        {--ventas= : IDs internos de sales, separados por coma; no números visibles}
        {--huella= : SHA-256 de la simulación revisada, obligatorio para aplicar}
        {--aplicar : Escribir solo total_facturado, en una ventana operativa autorizada}
        {--revertir : Simular la reversión del respaldo; combinar con aplicar para escribir}
        {--respaldo= : Archivo JSON absoluto y privado, obligatorio al aplicar o revertir}';

    protected $description = 'Simula y repara acumulados fiscales verificables con respaldo, selección explícita y reversión';

    public function handle()
    {
        try {
            $negocio = $this->entero($this->option('negocio'), 'negocio');
            $ids = $this->seleccion();
            $dueno = DB::table('users')->where('id', $negocio)->first();
            if (!$dueno || $dueno->owner_id !== null || $dueno->deleted_at !== null
                || (config('app.USER_ID') && (int) config('app.USER_ID') !== $negocio)) {
                throw new RuntimeException('El negocio no coincide con el dueño de esta instalación. Revisá el ID antes de continuar.');
            }
            $aplicar = (bool) $this->option('aplicar');
            $revertir = (bool) $this->option('revertir');
            $ruta = $this->option('respaldo');
            if ($aplicar || $revertir || $ruta) {
                $ruta = $this->ruta_privada($ruta);
            }
            if ($aplicar && !preg_match('/^[a-f0-9]{64}$/D', (string) $this->option('huella'))) {
                throw new RuntimeException('Primero simulá y revisá el resultado. Para aplicar indicá --huella con el SHA-256 informado.');
            }
            CandadoDeFacturaHelper::ventas($ids, function () use ($negocio, $ids, $aplicar, $revertir, $ruta) {
                $esperados = null;
                DB::transaction(function () use ($negocio, $ids, $aplicar, $revertir, $ruta, &$esperados) {
                    $estados = $this->estados($negocio, $ids);
                    $identidad = $this->identidad($negocio, $ids);
                    $huella_actual = $this->hash(['identidad' => $identidad, 'estados' => $estados]);
                    $respaldo = $ruta && is_file($ruta) ? $this->leer_respaldo($ruta, $identidad) : null;
                    if ($revertir && !$respaldo) {
                        throw new RuntimeException('La reversión requiere el respaldo original verificado. Indicá --respaldo; no se modificó nada.');
                    }
                    if ($respaldo) {
                        $this->comparar_respaldo($estados, $respaldo);
                    }
                    $huella_requerida = $respaldo ? $respaldo['huella'] : $huella_actual;
                    if ($aplicar && !hash_equals($huella_requerida, (string) $this->option('huella'))) {
                        throw new RuntimeException('La evidencia cambió o la huella no corresponde a la simulación. Volvé a simular y revisar antes de aplicar.');
                    }
                    $cambios = [];
                    foreach ($estados as $indice => $estado) {
                        if (!$revertir && $estado['analisis']['estado'] !== 'verificable') {
                            $cambios[] = ['venta_id' => $estado['venta_id'], 'numero_visible' => $estado['numero_visible'],
                                'estado' => 'revision', 'motivos' => $estado['analisis']['motivos']];
                            continue;
                        }
                        $destino = $revertir ? $respaldo['estados'][$indice]['anterior'] : $estado['analisis']['total'];
                        $cambios[] = ['venta_id' => $estado['venta_id'], 'numero_visible' => $estado['numero_visible'],
                            'estado' => 'verificable', 'anterior' => $estado['anterior'], 'destino' => $destino,
                            'moneda' => $estado['analisis']['moneda'], 'comprobantes' => $estado['analisis']['comprobantes']];
                    }
                    $this->line($this->json(['modo' => $aplicar ? 'aplicar' : 'simulacion', 'revertir' => $revertir,
                        'negocio' => $negocio, 'huella' => $huella_requerida, 'ventas' => $cambios]));
                    if (count(array_filter($cambios, function ($fila) { return $fila['estado'] === 'revision'; })) > 0) {
                        throw new RuntimeException('Hay comprobantes o importes que requieren revisión. No se normalizó ninguna venta; revisá los motivos informados sin reemitir documentos.');
                    }
                    if (!$aplicar) {
                        $this->info('Simulación: no se escribieron datos ni archivos. La instalación y prueba en el negocio siguen pendientes.');
                        return;
                    }
                    if (!$respaldo) {
                        $respaldo = ['version' => 1, 'identidad' => $identidad, 'huella' => $huella_actual,
                            'creado' => gmdate('c'), 'estados' => $estados];
                        $this->guardar_respaldo($ruta, $respaldo);
                        $respaldo = $this->leer_respaldo($ruta, $identidad);
                    }
                    $this->registrar($ruta, ['evento' => 'preparado', 'revertir' => $revertir, 'cambios' => $cambios]);
                    foreach ($cambios as $fila) {
                        if ($fila['anterior'] === $fila['destino']) {
                            continue;
                        }
                        // Query Builder conserva updated_at y evita observers: solo se repara el acumulado local.
                        $consulta = DB::table('sales')->where('id', $fila['venta_id'])->where('user_id', $negocio);
                        $fila['anterior'] === null ? $consulta->whereNull('total_facturado') : $consulta->where('total_facturado', $fila['anterior']);
                        if ($consulta->update(['total_facturado' => $fila['destino']]) !== 1) {
                            throw new RuntimeException('El acumulado cambió durante la reparación. Se revierte toda la selección; volvé a simular.');
                        }
                    }
                    $posterior = $this->estados($negocio, $ids);
                    foreach ($posterior as $indice => $estado) {
                        $esperado = $estados[$indice];
                        $esperado['anterior'] = $cambios[$indice]['destino'];
                        if ($estado !== $esperado) {
                            throw new RuntimeException('La verificación posterior no coincide. Se revierte toda la selección y se conserva el respaldo.');
                        }
                    }
                    $this->registrar($ruta, ['evento' => 'verificado_en_transaccion', 'revertir' => $revertir, 'huella' => $huella_requerida]);
                    $esperados = $posterior;
                });
                if ($esperados !== null) {
                    // El candado fiscal sigue tomado después del commit: una verificación en la
                    // transacción no demuestra por sí sola qué quedó confirmado en la base.
                    try {
                        if ($this->estados($negocio, $ids) !== $esperados) {
                            throw new RuntimeException('La relectura no coincide con el estado esperado.');
                        }
                        $this->registrar($ruta, ['evento' => 'confirmado_y_releido', 'revertir' => $revertir]);
                    } catch (\Throwable $e) {
                        throw new RuntimeException('La escritura ya fue confirmada, pero falló la verificación posterior: '.$e->getMessage().
                            ' Revisá el estado real y el respaldo antes de volver a ejecutar; no se declara verificada la reparación.');
                    }
                }
            });
            $this->info('Verificación terminada. El ticket sigue pendiente de comprobar el flujo y los datos en el negocio.');
            return 0;
        } catch (ValidationException $e) {
            $this->error(implode(' ', array_merge(...array_values($e->errors()))));
            return 1;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function seleccion(): array
    {
        $valores = explode(',', (string) $this->option('ventas'));
        if (count($valores) > 100) {
            throw new RuntimeException('Seleccioná hasta 100 ventas por operación para poder revisar cada diferencia.');
        }
        $ids = array_map(function ($valor) { return $this->entero(trim($valor), 'ventas (IDs internos)'); }, $valores);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function entero($valor, string $nombre): int
    {
        if (!is_scalar($valor) || !preg_match('/^[1-9][0-9]*$/D', (string) $valor)
            || filter_var($valor, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('Indicá --'.$nombre.' con un ID entero positivo explícito.');
        }
        return (int) $valor;
    }

    private function estados(int $negocio, array $ids): array
    {
        $resultado = [];
        foreach ($ids as $id) {
            $fila = DB::table('sales')->where('id', $id)->lockForUpdate()->first();
            if (!$fila || (int) $fila->user_id !== $negocio || $fila->deleted_at !== null || !empty($fila->actualizandose_por_id)) {
                throw new RuntimeException('La venta interna '.$id.' no pertenece al negocio, está eliminada o se está actualizando. Revisá la selección antes de continuar.');
            }
            $facturas = DB::table('afip_tickets')->where('sale_id', $id)->pluck('id')->all();
            $fiscales = DB::table('afip_tickets')->where(function ($q) use ($id, $facturas) {
                $q->where('sale_id', $id)->orWhere('sale_nota_credito_id', $id);
                if ($facturas) { $q->orWhereIn('sale_afip_ticket_id', $facturas); }
            })->orderBy('id')->lockForUpdate()->get()->map(function ($ticket) { return (array) $ticket; })->all();
            $venta = (array) $fila;
            $anterior = $venta['total_facturado'];
            unset($venta['total_facturado']);
            $analisis = TotalFacturadoDeVentaHelper::analizar(Sale::findOrFail($id));
            if ($analisis['estado'] === 'verificable' && !$analisis['comprobantes']
                && $anterior !== null && (string) $anterior !== '0.00' && (string) $anterior !== '0') {
                // La ausencia de registros no demuestra que una cifra histórica se deba borrar.
                $analisis['estado'] = 'revision';
                $analisis['total'] = null;
                $analisis['motivos'][] = 'La venta conserva un total facturado pero no tiene comprobantes autorizados que permitan verificarlo. Revisá la documentación histórica antes de corregirlo.';
            }
            // La evidencia fiscal cruda no sale a consola ni se guarda con CAE/CUIT: basta su huella para detectar cambios.
            $resultado[] = ['venta_id' => $id, 'numero_visible' => (int) $fila->num,
                'anterior' => $anterior, 'estado_venta_sha256' => $this->hash($venta),
                'estado_fiscal_sha256' => $this->hash($fiscales), 'analisis' => $analisis];
        }
        return $resultado;
    }

    private function identidad(int $negocio, array $ids): array
    {
        return ['negocio' => $negocio, 'ventas' => $ids, 'instalacion_sha256' => $this->hash([
            DB::connection()->getConfig('host'), DB::connection()->getConfig('port'),
            DB::connection()->getDatabaseName(), config('app.url'),
        ])];
    }

    private function comparar_respaldo(array $estados, array $respaldo): void
    {
        foreach ($estados as $indice => $estado) {
            $original = $respaldo['estados'][$indice] ?? null;
            if (!$original) { throw new RuntimeException('El respaldo no contiene toda la selección. No se modificó nada.'); }
            $total = $estado['anterior'];
            $estado['anterior'] = $original['anterior'];
            if ($estado !== $original || !in_array($total, [$original['anterior'], $original['analisis']['total']], true)) {
                throw new RuntimeException('La venta o su evidencia fiscal cambió después del respaldo. Requiere otra revisión; no se pisa el estado posterior.');
            }
        }
    }

    private function ruta_privada($ruta): string
    {
        if (!is_string($ruta) || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $ruta)
            || strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) !== 'json' || !realpath(dirname($ruta))) {
            throw new RuntimeException('Indicá --respaldo con una ruta absoluta .json en una carpeta privada existente fuera de public.');
        }
        $resuelta = realpath(dirname($ruta)).DIRECTORY_SEPARATOR.basename($ruta);
        foreach ([public_path(), storage_path('app/public')] as $publica) {
            $publica = realpath($publica) ?: $publica;
            if (stripos(str_replace('\\', '/', $resuelta), rtrim(str_replace('\\', '/', $publica), '/').'/') === 0) {
                throw new RuntimeException('El respaldo no puede quedar en una carpeta pública. Elegí una carpeta privada.');
            }
        }
        if (is_link($resuelta) || is_dir($resuelta)) {
            throw new RuntimeException('El respaldo debe ser un archivo privado regular, sin enlaces simbólicos.');
        }
        return $resuelta;
    }

    private function leer_respaldo(string $ruta, array $identidad): array
    {
        $datos = json_decode(file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
        $sello = $datos['sha256'] ?? null;
        unset($datos['sha256']);
        if (($datos['version'] ?? null) !== 1 || ($datos['identidad'] ?? null) !== $identidad
            || !isset($datos['estados'], $datos['huella']) || $sello !== $this->hash($datos)
            || $datos['huella'] !== $this->hash(['identidad' => $identidad, 'estados' => $datos['estados']])) {
            throw new RuntimeException('El respaldo está alterado o no corresponde a esta instalación y selección. No se modificó nada.');
        }
        return $datos;
    }

    private function guardar_respaldo(string $ruta, array $datos): void
    {
        $datos['sha256'] = $this->hash($datos);
        $contenido = $this->json($datos);
        $archivo = @fopen($ruta, 'x');
        if (!$archivo) { throw new RuntimeException('No se pudo crear el respaldo exclusivo. Revisá la ruta y sus permisos; no se escribió en la base.'); }
        try {
            @chmod($ruta, 0600);
            if (fwrite($archivo, $contenido) !== strlen($contenido) || !fflush($archivo)) {
                throw new RuntimeException('El respaldo no se escribió completo. No se escribió en la base.');
            }
        } finally { fclose($archivo); }
        if (file_get_contents($ruta) !== $contenido) {
            throw new RuntimeException('El respaldo no superó la relectura. No se escribió en la base.');
        }
    }

    private function registrar(string $ruta, array $evento): void
    {
        $auditoria = $ruta.'.auditoria.jsonl';
        if (is_link($auditoria)) { throw new RuntimeException('La auditoría no puede ser un enlace simbólico. No se confirma la transacción.'); }
        $linea = $this->json(array_merge(['instante' => gmdate('c')], $evento)).PHP_EOL;
        if (file_put_contents($auditoria, $linea, FILE_APPEND | LOCK_EX) !== strlen($linea)) {
            throw new RuntimeException('No se pudo registrar la auditoría. No se confirma la transacción.');
        }
        @chmod($auditoria, 0600);
    }

    private function hash(array $datos): string { return hash('sha256', $this->json($datos)); }
    private function json(array $datos): string { return json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}
