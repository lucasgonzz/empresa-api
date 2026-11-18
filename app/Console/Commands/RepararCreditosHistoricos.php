<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\currentAcount\CreditosHistoricosHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Models\CreditAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Reparación manual acotada: nunca se agenda ni se ejecuta durante el despliegue. */
class RepararCreditosHistoricos extends Command
{
    protected $signature = 'cuenta_corriente:reparar_creditos_historicos
        {--cuenta= : ID obligatorio de una sola cuenta corriente}
        {--aplicar : Escribir estados e imputaciones dentro de la ventana autorizada}
        {--respaldo= : Archivo JSON absoluto, fuera del código y de la carpeta pública}
        {--revertir : Simular la reversión; combinar con aplicar para ejecutarla}';

    protected $description = 'Simula, repara o revierte los estados de una cuenta con créditos históricos negativos, sin modificar su saldo ni crear cobros';

    public function handle()
    {
        try {
            $id = (string) $this->option('cuenta');
            if (!ctype_digit($id) || (int) $id < 1) {
                throw new RuntimeException('Indicá --cuenta con el ID positivo de una sola cuenta. No se recorre el resto de las cuentas.');
            }
            $cuenta = CreditAccount::find((int) $id);
            if (!$cuenta || !isset(CuentaCorrienteLock::TABLAS[$cuenta->model_name])) {
                throw new RuntimeException('La cuenta no existe o no pertenece a un cliente o proveedor. Revisá el ID.');
            }
            $aplicar = (bool) $this->option('aplicar');
            $revertir = (bool) $this->option('revertir');
            $ruta = $this->option('respaldo');
            if (($aplicar || $revertir) && !$ruta) {
                throw new RuntimeException('Indicá --respaldo con un archivo absoluto protegido fuera del código y de la carpeta pública.');
            }
            if ($ruta) {
                $ruta = $this->ruta_protegida($ruta);
            }

            // El dueño se resuelve antes de la transacción; el candado debe preceder a la foto SQL.
            $duenio = ['model_name' => $cuenta->model_name, 'model_id' => $cuenta->model_id];
            DB::transaction(function () use ($cuenta, $duenio, $aplicar, $revertir, $ruta) {
                CuentaCorrienteLock::bloquear_duenio($duenio);
                $cuenta = CreditAccount::whereKey($cuenta->id)->lockForUpdate()->first();
                if (!$cuenta || $cuenta->model_name !== $duenio['model_name'] || (int) $cuenta->model_id !== (int) $duenio['model_id']) {
                    throw new RuntimeException('Cambió la identidad de la cuenta. Volvé a diagnosticarla antes de reparar.');
                }
                $actual = $this->snapshot($cuenta);
                $respaldo = $ruta && is_file($ruta) ? $this->leer_respaldo($ruta, $cuenta->id) : null;
                if ($revertir) {
                    $this->revertir($cuenta, $actual, $respaldo, $aplicar);
                    return;
                }
                if ($respaldo) {
                    if ($actual !== $respaldo['despues']) {
                        throw new RuntimeException('La cuenta no coincide con la reparación respaldada. Revisá la actividad posterior; no se sobrescribe el respaldo ni se escribe en la cuenta.');
                    }
                    $this->info('La reparación de este respaldo ya está aplicada. No hay cambios.');
                    return;
                }
                if (!CreditosHistoricosHelper::tiene_creditos($cuenta->id)) {
                    throw new RuntimeException('Esta cuenta no tiene créditos históricos negativos. Este comando no modifica cuentas sin ese diagnóstico.');
                }
                $plan = CreditosHistoricosHelper::plan($cuenta);
                $this->mostrar_plan($cuenta, $plan, $aplicar ? 'aplicar' : 'simulacion');
                if (!$aplicar) {
                    $this->info('Simulación: no se escribieron datos ni archivos.');
                    return;
                }
                CreditosHistoricosHelper::aplicar($cuenta);
                $despues = $this->snapshot($cuenta);
                $this->verificar_alcance($actual, $despues);
                // Se conserva la foto original y se verifica el archivo antes del commit. Un fallo
                // de disco revierte toda la transacción: jamás hay reparación sin respaldo legible.
                $datos = ['formato' => 'creditos-historicos-v1', 'base' => DB::connection()->getDatabaseName(),
                    'instalacion' => (string) config('app.url'), 'cuenta_id' => (int) $cuenta->id,
                    'creado_utc' => gmdate('c'), 'antes' => $actual, 'despues' => $despues];
                $this->guardar_respaldo($ruta, $datos);
                if ($this->leer_respaldo($ruta, $cuenta->id)['despues'] !== $this->snapshot($cuenta)) {
                    throw new RuntimeException('La verificación posterior no coincide con el respaldo. Se revierte la transacción.');
                }
                $this->info('Estados e imputaciones verificados. Se conservaron importes, saldos, fechas, documentos, stock, caja, comisiones y puntos.');
            });
            return 0;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $this->error($mensaje);
                }
            }
            return 1;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function mostrar_plan($cuenta, array $plan, string $modo): void
    {
        $this->line(json_encode(['cuenta_id' => (int) $cuenta->id, 'moneda_id' => (int) $cuenta->moneda_id,
            'modo' => $modo, 'credito_total' => $plan['credito_total'], 'saldo_economico' => $plan['saldo_economico'],
            'pendiente' => $plan['pendiente'], 'remanente' => $plan['remanente'],
            'estados' => $plan['estados'], 'imputaciones' => $plan['imputaciones']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function revertir($cuenta, array $actual, $respaldo, bool $aplicar): void
    {
        if (!$respaldo) {
            throw new RuntimeException('No se encontró el respaldo original. La reversión no puede reconstruirse por suposición.');
        }
        if ($actual === $respaldo['antes']) {
            $this->info('La cuenta ya coincide con el estado anterior del respaldo. No hay cambios.');
            return;
        }
        if ($actual !== $respaldo['despues']) {
            throw new RuntimeException('Hay cambios posteriores en la cuenta, sus movimientos, imputaciones, dueño, puntos o comisiones. Revisalos antes de revertir; no se modificó nada.');
        }
        if (!$aplicar) {
            $this->info('Simulación de reversión verificada: restauraría únicamente estados e imputaciones originales. No se escribieron datos ni archivos.');
            return;
        }
        $this->verificar_alcance($respaldo['antes'], $respaldo['despues']);
        foreach ($respaldo['antes']['movimientos'] as $movimiento) {
            DB::table('current_acounts')->where('id', $movimiento['id'])->where('credit_account_id', $cuenta->id)
                ->update(['status' => $movimiento['status'], 'pagandose' => $movimiento['pagandose']]);
        }
        $ids = array_column($actual['movimientos'], 'id');
        $this->pivots($ids)->delete();
        if ($respaldo['antes']['imputaciones']) {
            DB::table('pagado_por')->insert($respaldo['antes']['imputaciones']);
        }
        if ($this->snapshot($cuenta) !== $respaldo['antes']) {
            throw new RuntimeException('La reversión no coincide exactamente con el respaldo. Se revierte la transacción.');
        }
        $this->info('Reversión verificada: se restauraron los estados e imputaciones originales.');
    }

    private function snapshot($cuenta): array
    {
        $movimientos = DB::table('current_acounts')->where('credit_account_id', $cuenta->id)
            ->orderBy('id')->lockForUpdate()->get()->map(function ($fila) { return (array) $fila; })->all();
        $duenio = DB::table(CuentaCorrienteLock::TABLAS[$cuenta->model_name])->where('id', $cuenta->model_id)
            ->lockForUpdate()->first();
        if (!$duenio) {
            throw new RuntimeException('No existe el dueño de la cuenta. Requiere revisión antes de reparar.');
        }
        return ['cuenta' => (array) DB::table('credit_accounts')->where('id', $cuenta->id)->lockForUpdate()->first(),
            // La huella detecta cambios del dueño sin copiar datos de contacto al archivo de operación.
            'duenio_sha256' => $this->hash((array) $duenio), 'movimientos' => $movimientos,
            'dependencias_sha256' => $this->huella_dependencias($cuenta, $movimientos),
            'imputaciones' => $this->pivots(array_column($movimientos, 'id'))->orderBy('id')->lockForUpdate()->get()
                ->map(function ($fila) { return (array) $fila; })->all()];
    }

    private function huella_dependencias($cuenta, array $movimientos): string
    {
        $ids = array_column($movimientos, 'id');
        $ventas = array_values(array_filter(array_column($movimientos, 'sale_id')));
        $comisiones = DB::table('seller_commissions')->whereIn('sale_id', $ventas)->orderBy('id')->lockForUpdate()->get();
        $vinculos = DB::table('current_acount_seller_commission')->where(function ($q) use ($ids, $comisiones) {
            $q->whereIn('current_acount_id', $ids)->orWhereIn('seller_commission_id', $comisiones->pluck('id'));
        })->orderBy('id')->lockForUpdate()->get();
        $puntos = DB::table('movimiento_puntos')->where(function ($q) use ($cuenta, $ventas) {
            $q->whereIn('sale_id', $ventas);
            if ($cuenta->model_name === 'client') {
                $q->orWhere('client_id', $cuenta->model_id);
            }
        })->orderBy('id')->lockForUpdate()->get();
        $consumos = DB::table('movimiento_punto_consumos')->where(function ($q) use ($puntos) {
            $q->whereIn('movimiento_origen_id', $puntos->pluck('id'))->orWhereIn('movimiento_consumo_id', $puntos->pluck('id'));
        })->orderBy('id')->lockForUpdate()->get();
        // Son guardas de actividad posterior: no se revierten puntos, comisiones ni sus consumos.
        return $this->hash(['comisiones' => $comisiones->toArray(), 'vinculos_comision' => $vinculos->toArray(),
            'puntos' => $puntos->toArray(), 'consumos' => $consumos->toArray()]);
    }

    private function pivots(array $ids)
    {
        return DB::table('pagado_por')->where(function ($q) use ($ids) {
            $q->whereIn('debe_id', $ids)->orWhereIn('haber_id', $ids);
        });
    }

    private function verificar_alcance(array $antes, array $despues): void
    {
        $limpiar = function ($estado) {
            unset($estado['imputaciones']);
            foreach ($estado['movimientos'] as &$movimiento) {
                unset($movimiento['status'], $movimiento['pagandose']);
            }
            unset($movimiento);
            return $estado;
        };
        if ($limpiar($antes) !== $limpiar($despues)) {
            throw new RuntimeException('La reparación intentó modificar datos fuera de estados e imputaciones. Se revierte completa.');
        }
    }

    private function ruta_protegida(string $ruta): string
    {
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $ruta) || !is_dir(dirname($ruta))) {
            throw new RuntimeException('El respaldo necesita una ruta absoluta y una carpeta existente, fuera del código y de la carpeta pública.');
        }
        $carpeta = realpath(dirname($ruta));
        $ruta = $carpeta.DIRECTORY_SEPARATOR.basename($ruta);
        foreach ([base_path(), public_path()] as $prohibida) {
            $prefijo = strtolower(str_replace('\\', '/', realpath($prohibida) ?: $prohibida));
            $comparar = strtolower(str_replace('\\', '/', $ruta));
            if ($comparar === $prefijo || strpos($comparar, rtrim($prefijo, '/').'/') === 0) {
                throw new RuntimeException('El respaldo debe quedar fuera del repositorio y de la carpeta pública. Elegí una carpeta protegida de operaciones.');
            }
        }
        for ($ancestro = $carpeta; $ancestro && dirname($ancestro) !== $ancestro; $ancestro = dirname($ancestro)) {
            if (file_exists($ancestro.DIRECTORY_SEPARATOR.'.git')) {
                throw new RuntimeException('El respaldo no puede guardarse dentro de otro repositorio. Elegí una carpeta protegida de operaciones.');
            }
        }
        if (is_link($ruta)) {
            throw new RuntimeException('El respaldo no puede ser un enlace simbólico. Elegí un archivo local protegido.');
        }
        return $ruta;
    }

    private function hash(array $datos): string
    {
        return hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function leer_respaldo(string $ruta, int $cuenta_id): array
    {
        $contenido = @file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException('No se pudo leer el respaldo. Revisá la ruta y sus permisos antes de continuar.');
        }
        try {
            $datos = json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('El respaldo no es un JSON válido. Recuperá el archivo original verificado antes de continuar.');
        }
        if (!is_array($datos)) {
            throw new RuntimeException('El respaldo no contiene una operación válida. Recuperá el archivo original verificado.');
        }
        $sha = $datos['sha256'] ?? null;
        unset($datos['sha256']);
        if (($datos['formato'] ?? null) !== 'creditos-historicos-v1'
            || ($datos['base'] ?? null) !== DB::connection()->getDatabaseName()
            || ($datos['instalacion'] ?? null) !== (string) config('app.url')
            || ($datos['cuenta_id'] ?? null) !== $cuenta_id || !is_string($sha)
            || !hash_equals($this->hash($datos), $sha) || !isset($datos['antes'], $datos['despues'])) {
            throw new RuntimeException('El respaldo está incompleto, alterado o pertenece a otra instalación o cuenta. No se escribió nada.');
        }
        foreach (['antes', 'despues'] as $estado) {
            if (($datos[$estado]['cuenta']['id'] ?? null) !== $cuenta_id
                || !is_array($datos[$estado]['movimientos'] ?? null) || !is_array($datos[$estado]['imputaciones'] ?? null)
                || !is_string($datos[$estado]['dependencias_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $datos[$estado]['dependencias_sha256'])) {
                throw new RuntimeException('El respaldo no contiene una foto completa de esta cuenta. No se escribió nada.');
            }
            $ids = [];
            foreach ($datos[$estado]['movimientos'] as $fila) {
                if (!is_array($fila) || (int) ($fila['credit_account_id'] ?? 0) !== $cuenta_id || !isset($fila['id'])
                    || !ctype_digit((string) $fila['id']) || (int) $fila['id'] < 1 || isset($ids[(int) $fila['id']])
                    || !array_key_exists('status', $fila) || !array_key_exists('pagandose', $fila)) {
                    throw new RuntimeException('El respaldo contiene movimientos ajenos o incompletos. Revisá su origen.');
                }
                $ids[(int) $fila['id']] = true;
            }
            $this->validar_imputaciones($datos[$estado]['imputaciones'], $ids);
        }
        $this->verificar_alcance($datos['antes'], $datos['despues']);
        return $datos;
    }

    private function validar_imputaciones(array $imputaciones, array $movimientos): void
    {
        $campos = ['id', 'debe_id', 'haber_id', 'pagado', 'total_pago', 'a_cubrir', 'fondos_iniciales',
            'nuevos_fondos', 'remantente', 'created_at', 'updated_at'];
        sort($campos);
        $ids = [];
        foreach ($imputaciones as $fila) {
            $claves = is_array($fila) ? array_keys($fila) : [];
            sort($claves);
            if ($claves !== $campos) {
                throw new RuntimeException('El respaldo contiene imputaciones incompletas o campos ajenos. Recuperá el archivo original.');
            }
            foreach (['id', 'debe_id', 'haber_id'] as $campo) {
                if (!ctype_digit((string) $fila[$campo]) || (int) $fila[$campo] < 1) {
                    throw new RuntimeException('El respaldo contiene IDs de imputación inválidos. Recuperá el archivo original.');
                }
            }
            if (isset($ids[(int) $fila['id']]) || !isset($movimientos[(int) $fila['debe_id']])
                || !isset($movimientos[(int) $fila['haber_id']])) {
                throw new RuntimeException('El respaldo contiene imputaciones repetidas o vinculadas con otra cuenta. No se puede revertir con ese archivo.');
            }
            $ids[(int) $fila['id']] = true;
            foreach (['pagado', 'total_pago', 'a_cubrir', 'fondos_iniciales', 'nuevos_fondos', 'remantente'] as $campo) {
                $nullable = !in_array($campo, ['pagado', 'total_pago'], true);
                if (($fila[$campo] === null && $nullable) || (is_numeric($fila[$campo]) && is_finite((float) $fila[$campo]))) {
                    continue;
                }
                // No se rechaza el signo histórico: la reversión debe conservar el dato original,
                // incluso una imputación defectuosa que precisamente se estaba reparando.
                throw new RuntimeException('El respaldo contiene importes de imputación inválidos. Recuperá el archivo original.');
            }
            foreach (['created_at', 'updated_at'] as $campo) {
                if ($fila[$campo] !== null && (!is_string($fila[$campo])
                    || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fila[$campo]))) {
                    throw new RuntimeException('El respaldo contiene fechas de imputación inválidas. Recuperá el archivo original.');
                }
            }
        }
    }

    private function guardar_respaldo(string $ruta, array $datos): void
    {
        $datos['sha256'] = $this->hash($datos);
        $contenido = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $mascara = umask(0077);
        try {
            $archivo = @fopen($ruta, 'x+b');
        } finally {
            umask($mascara);
        }
        if (!$archivo) {
            throw new RuntimeException('No se pudo crear el respaldo exclusivo. No se sobrescribe un archivo anterior y se revierte la reparación.');
        }
        try {
            if (fwrite($archivo, $contenido) !== strlen($contenido) || !fflush($archivo)) {
                throw new RuntimeException('El respaldo no se escribió completo. Se revierte la reparación.');
            }
        } finally {
            fclose($archivo);
        }
        @chmod($ruta, 0600);
        if (file_get_contents($ruta) !== $contenido) {
            throw new RuntimeException('El respaldo no superó la relectura. Se revierte la reparación.');
        }
    }
}
