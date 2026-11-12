<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reparación puntual; no se agenda ni se ejecuta durante el despliegue. */
class RepararPreciosSoporte107 extends Command
{
    protected $signature = 'soporte:reparar-precios-107
        {--aplicar : Escribir los dos importes después de verificar el respaldo}
        {--revertir : Restaurar los importes del respaldo, solo si siguen iguales a los reparados}
        {--respaldo= : Ruta del JSON local; por defecto storage/app/soporte/107-precios.json}';

    protected $description = 'Simula o repara exclusivamente los precios de los artículos 34 y 46 del ticket 107';

    private const TENANT = 46000;
    private const CORRIDA = 'f6585f15-c2cc-431c-9068-5cd7a35744a5';
    private const ARTICULOS = [
        34 => ['nombre' => 'Llave francesa 10', 'anterior' => '313.22', 'nuevo' => '344.54'],
        46 => ['nombre' => 'Disyuntor diferencial 2x40A', 'anterior' => null, 'nuevo' => '54883.47'],
    ];

    public function handle()
    {
        $ruta = $this->option('respaldo') ?: storage_path('app/soporte/107-precios.json');
        $revertir = (bool) $this->option('revertir');
        try {
            DB::transaction(function () use ($ruta, $revertir) {
                $corrida = DB::table('importaciones_de_prueba')->where('uuid', self::CORRIDA)->lockForUpdate()->first();
                if (!$corrida || (int) $corrida->user_id !== self::TENANT || (int) $corrida->support_ticket_id !== 107
                    || (int) $corrida->import_history_id !== 1 || $corrida->estado !== 'terminado') {
                    throw new RuntimeException('No coincide la corrida original del ticket 107 en esta base.');
                }
                $antes = $this->leer_articulos();
                $respaldo = is_file($ruta) ? $this->leer_respaldo($ruta) : null;
                if ($revertir && $respaldo === null) {
                    throw new RuntimeException('La reversión requiere el respaldo original verificado.');
                }
                $origen = $revertir ? 'nuevo' : 'anterior';
                $destino = $revertir ? 'anterior' : 'nuevo';
                $ya_aplicado = $this->tienen_importes($antes, $destino);
                if (!$ya_aplicado && !$this->tienen_importes($antes, $origen)) {
                    throw new RuntimeException('Los importes cambiaron o hay un estado mixto: no se escribe nada.');
                }
                $despues = $antes;
                foreach (self::ARTICULOS as $id => $datos) {
                    $despues[$id]['price'] = $datos[$destino];
                    $despues[$id]['final_price'] = $datos[$destino];
                }
                $this->line(json_encode(['ticket' => 107, 'modo' => $this->option('aplicar') ? 'aplicar' : 'simulacion',
                    'revertir' => $revertir, 'antes' => $antes, 'despues' => $despues], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if (!$this->option('aplicar')) {
                    $this->info('Simulación: no se escribieron datos ni archivos.');
                    return;
                }
                if ($ya_aplicado) {
                    if ($respaldo === null) {
                        throw new RuntimeException('Los importes ya coinciden, pero falta el respaldo original; no se atribuye la reparación.');
                    }
                    $this->info('Los importes ya están en el destino: no hay cambios.');
                    return;
                }
                if ($respaldo === null) {
                    $this->guardar_exclusivo($ruta, ['ticket' => 107, 'tenant' => self::TENANT,
                        'base' => DB::connection()->getDatabaseName(), 'creado' => gmdate('c'), 'articulos' => $antes]);
                    $this->leer_respaldo($ruta);
                }
                // Registro persistente previo a la escritura, incluidos ambos estados.
                $this->registrar($ruta, ['evento' => 'preparado', 'revertir' => $revertir, 'antes' => $antes, 'despues' => $despues]);
                foreach (self::ARTICULOS as $id => $datos) {
                    // Query Builder evita observers, recálculos, stock y cambios de updated_at.
                    DB::table('articles')->where('id', $id)->where('user_id', self::TENANT)
                        ->update(['price' => $datos[$destino], 'final_price' => $datos[$destino]]);
                }
                $verificado = $this->leer_articulos();
                if ($verificado !== $despues) {
                    throw new RuntimeException('La lectura posterior no coincide: se revierte la transacción.');
                }
                $this->registrar($ruta, ['evento' => 'verificado_en_transaccion', 'revertir' => $revertir,
                    'antes' => $antes, 'despues' => $verificado]);
            });
            $this->info('Verificación terminada.');
            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function leer_articulos(): array
    {
        $resultado = [];
        foreach (self::ARTICULOS as $id => $datos) {
            $fila = DB::table('articles')->where('id', $id)->lockForUpdate()->first();
            if (!$fila || (int) $fila->user_id !== self::TENANT || $fila->name !== $datos['nombre'] || $fila->deleted_at !== null) {
                throw new RuntimeException('No coincide la identidad exacta del artículo ' . $id . '.');
            }
            $resultado[$id] = ['id' => $id, 'user_id' => self::TENANT, 'name' => $fila->name,
                'price' => $this->importe($fila->price), 'final_price' => $this->importe($fila->final_price)];
        }
        return $resultado;
    }

    private function importe($valor)
    {
        return $valor === null ? null : number_format((float) $valor, 2, '.', '');
    }

    private function tienen_importes(array $filas, string $estado): bool
    {
        foreach (self::ARTICULOS as $id => $datos) {
            if (!isset($filas[$id]) || !array_key_exists('price', $filas[$id]) || !array_key_exists('final_price', $filas[$id])
                || $filas[$id]['price'] !== $datos[$estado] || $filas[$id]['final_price'] !== $datos[$estado]) {
                return false;
            }
        }
        return true;
    }

    private function leer_respaldo(string $ruta): array
    {
        $contenido = file_get_contents($ruta);
        $respaldo = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        if (($respaldo['ticket'] ?? null) !== 107 || ($respaldo['tenant'] ?? null) !== self::TENANT
            || ($respaldo['base'] ?? null) !== DB::connection()->getDatabaseName()
            || !isset($respaldo['articulos']) || !$this->tienen_importes($respaldo['articulos'], 'anterior')) {
            throw new RuntimeException('El respaldo no corresponde a esta reparación y esta base.');
        }
        foreach (self::ARTICULOS as $id => $datos) {
            $fila = $respaldo['articulos'][$id] ?? [];
            if (($fila['id'] ?? null) !== $id || ($fila['user_id'] ?? null) !== self::TENANT
                || ($fila['name'] ?? null) !== $datos['nombre']) {
                throw new RuntimeException('La identidad del respaldo no coincide.');
            }
        }
        return $respaldo;
    }

    private function guardar_exclusivo(string $ruta, array $datos): void
    {
        $directorio = dirname($ruta);
        if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear el directorio de respaldo.');
        }
        $contenido = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $archivo = fopen($ruta, 'x');
        if (!$archivo) {
            throw new RuntimeException('No se pudo crear el respaldo exclusivo.');
        }
        try {
            if (fwrite($archivo, $contenido) !== strlen($contenido) || !fflush($archivo)) {
                throw new RuntimeException('No se pudo escribir el respaldo completo.');
            }
        } finally {
            fclose($archivo);
        }
        if (file_get_contents($ruta) !== $contenido) {
            throw new RuntimeException('El respaldo no superó la verificación de lectura.');
        }
    }

    private function registrar(string $ruta, array $evento): void
    {
        $linea = json_encode(array_merge(['fecha' => gmdate('c'), 'ticket' => 107], $evento),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($ruta . '.auditoria.jsonl', $linea, FILE_APPEND | LOCK_EX) !== strlen($linea)) {
            throw new RuntimeException('No se pudo registrar la auditoría: no se confirma la transacción.');
        }
    }
}
