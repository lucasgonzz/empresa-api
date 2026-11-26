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
        {--revertir : Simular la reversión; combinar con aplicar para ejecutarla}
        {--vinculos-en-cero : Modo alternativo: quita sólo los cruces de pagado_por con otra cuenta que valen $0}
        {--vinculos-con-importe : Modo alternativo: quita el cruce entre cuentas de UN par débito/haber (con importe y en $0) y recalcula sólo el estado de pago del débito}
        {--debe= : Con --vinculos-con-importe: ID del movimiento débito (D) cuyo estado de pago se recalcula}
        {--haber= : Con --vinculos-con-importe: ID del movimiento haber (H) de la otra cuenta que cruza hacia D}
        {--pivotes= : Con --vinculos-en-cero o --vinculos-con-importe y --aplicar: IDs exactos de pagado_por esperados, separados por coma}';

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

            // Los dos modos de vínculos son excluyentes: cada uno tiene su propia garantía y su propio respaldo.
            $con_importe = (bool) $this->option('vinculos-con-importe');
            if ($con_importe && $this->option('vinculos-en-cero')) {
                throw new RuntimeException('Los modos --vinculos-con-importe y --vinculos-en-cero son distintos: usá uno solo por corrida. No se escribió nada.');
            }
            if (!$con_importe && ($this->option('debe') !== null || $this->option('haber') !== null)) {
                throw new RuntimeException('--debe y --haber sólo se usan junto con --vinculos-con-importe.');
            }
            if ($con_importe) {
                // Rama propia, antes de tiene_creditos()/plan(): una cuenta con notas de crédito no debe caer en el modo de créditos.
                $this->vinculos_con_importe($cuenta, $aplicar, $revertir, $ruta);
                return 0;
            }
            if ($this->option('vinculos-en-cero')) {
                // Rama propia: no pasa por tiene_creditos() ni plan(), que rechazan justamente estos cruces.
                $this->vinculos_en_cero($cuenta, $aplicar, $revertir, $ruta);
                return 0;
            }
            if ($this->option('pivotes') !== null) {
                throw new RuntimeException('--pivotes sólo se usa junto con --vinculos-en-cero o --vinculos-con-importe.');
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
                if (!CreditosHistoricosHelper::tiene_creditos($cuenta->id)) {
                    throw new RuntimeException('Esta cuenta no tiene créditos históricos negativos. Este comando no modifica cuentas sin ese diagnóstico.');
                }
                $plan = CreditosHistoricosHelper::plan($cuenta);
                $this->validar_saldos_guardados($actual, $plan);
                if ($respaldo) {
                    if ($actual !== $respaldo['despues']) {
                        throw new RuntimeException('La cuenta no coincide con la reparación respaldada. Revisá la actividad posterior; no se sobrescribe el respaldo ni se escribe en la cuenta.');
                    }
                    $this->info('La reparación de este respaldo ya está aplicada. No hay cambios.');
                    return;
                }
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

    private function validar_saldos_guardados(array $actual, array $plan): void
    {
        $movimientos = $actual['movimientos'];
        usort($movimientos, function ($a, $b) {
            $fecha = strcmp((string) $a['created_at'], (string) $b['created_at']);
            return $fecha !== 0 ? $fecha : ((int) $a['id'] <=> (int) $b['id']);
        });
        $saldo = 0;
        foreach ($movimientos as $movimiento) {
            if ((int) $movimiento['is_provisorio']) {
                continue;
            }
            $saldo += (int) round((float) $movimiento['debe'] * 100) - (int) round((float) $movimiento['haber'] * 100);
            if (!is_numeric($movimiento['saldo']) || (int) round((float) $movimiento['saldo'] * 100) !== $saldo) {
                throw new RuntimeException('El saldo guardado del movimiento #'.$movimiento['id'].' no coincide con su historial. Pedile a soporte que revise la cadena de saldos antes de reparar estados; este comando no recalcula importes ni saldos.');
            }
        }
        if (!is_numeric($actual['cuenta']['saldo']) || (int) round((float) $actual['cuenta']['saldo'] * 100) !== $saldo
            || (int) round((float) $plan['saldo_economico'] * 100) !== $saldo) {
            throw new RuntimeException('El saldo guardado de la cuenta no coincide con el saldo económico del historial. Pedile a soporte que revise ese descuadre antes de reparar estados; este comando no modifica el saldo.');
        }
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

    // ------------------------------------------------------------------------------------------
    // Modo --vinculos-en-cero (tanda 25/11/2026)
    //
    // 🔴 POR QUÉ EXISTE. La guarda de vínculos (vinculos_ajenos) rechaza cualquier fila de pagado_por que
    // cruza la cuenta con otra. Hay cruces históricos que valen $0: no mueven un peso ni un estado, pero
    // traban la cuenta. Este modo borra SÓLO esas filas, con ids exactos, sin recalcular nada.
    // Por qué no se "simplifica" reusando plan()/aplicar(): aplicar() borra todo pivote de la cuenta y
    // recalcula estados; acá un estado ajeno que cambie es un fallo, no un efecto buscado.
    // ------------------------------------------------------------------------------------------

    /**
     * Simula, aplica o revierte la limpieza de cruces en $0 de una cuenta.
     *
     * @param  \App\Models\CreditAccount $cuenta   Cuenta pedida.
     * @param  bool                      $aplicar  Escribir (con --revertir: ejecutar la reversión).
     * @param  bool                      $revertir Operar sobre el respaldo, deshaciendo la limpieza.
     * @param  string|null               $ruta     Respaldo absoluto ya validado (puede no existir todavía).
     * @return void
     * @throws RuntimeException ante cualquier precondición que falle: nada queda escrito.
     */
    private function vinculos_en_cero($cuenta, bool $aplicar, bool $revertir, $ruta): void
    {
        $pivotes = $this->leer_pivotes_pedidos();
        if ($aplicar && !$revertir && $pivotes === null) {
            throw new RuntimeException('Con --aplicar indicá --pivotes con los IDs exactos de pagado_por que simulaste. No se escribió nada.');
        }
        $respaldo = $ruta && is_file($ruta) ? $this->leer_respaldo_vinculos($ruta, (int) $cuenta->id) : null;
        if ($revertir && !$respaldo) {
            throw new RuntimeException('No se encontró el respaldo original. La reversión no puede reconstruirse por suposición.');
        }

        // Cuentas involucradas y sus dueños, resueltos ANTES de la transacción (el candado debe ser su primera sentencia).
        if ($respaldo) {
            $ids_cuentas = array_map(function ($foto) { return (int) $foto['cuenta']['id']; }, $respaldo['antes']);
        } else {
            $ids_cuentas = $this->cuentas_involucradas((int) $cuenta->id, $this->cruces_de_cuenta((int) $cuenta->id, false));
        }
        $duenios = $this->duenios_de_cuentas($ids_cuentas);

        DB::transaction(function () use ($cuenta, $aplicar, $revertir, $ruta, $pivotes, $respaldo, $ids_cuentas, $duenios) {
            // Orden canónico: todos los clientes (ids ascendentes) y después todos los proveedores. Ferretotal cruza
            // un cliente con un proveedor; un orden fijo evita que dos procesos se esperen en círculo.
            $por_tipo = ['client' => [], 'provider' => []];
            foreach ($duenios as $duenio) {
                $por_tipo[$duenio['model_name']][] = $duenio['model_id'];
            }
            CuentaCorrienteLock::bloquear('client', $por_tipo['client']);
            CuentaCorrienteLock::bloquear('provider', $por_tipo['provider']);
            $filas = DB::table('credit_accounts')->whereIn('id', $ids_cuentas)->orderBy('id')->lockForUpdate()->get();
            foreach ($filas as $fila) {
                $esperado = $duenios[(int) $fila->id];
                if ($fila->model_name !== $esperado['model_name'] || (int) $fila->model_id !== $esperado['model_id']) {
                    throw new RuntimeException('Cambió la identidad de una cuenta involucrada. Volvé a simular antes de operar.');
                }
            }
            if (count($filas) !== count($ids_cuentas)) {
                throw new RuntimeException('Falta una cuenta involucrada. Volvé a simular antes de operar.');
            }

            if ($revertir) {
                $this->revertir_vinculos($ids_cuentas, $duenios, $respaldo, $aplicar);
                return;
            }
            if ($respaldo) {
                $this->confirmar_vinculos_aplicados($ids_cuentas, $duenios, $respaldo, $pivotes);
                return;
            }
            $this->limpiar_vinculos($cuenta, $ids_cuentas, $duenios, $aplicar, $ruta, $pivotes);
        });
    }

    /**
     * Interpreta --pivotes: lista de IDs enteros positivos sin repetir, ordenados. Null si no se indicó.
     *
     * @return int[]|null
     */
    private function leer_pivotes_pedidos(): ?array
    {
        $crudo = $this->option('pivotes');
        if ($crudo === null) {
            return null;
        }
        $ids = [];
        foreach (explode(',', (string) $crudo) as $parte) {
            $parte = trim($parte);
            if (!ctype_digit($parte) || (int) $parte < 1) {
                throw new RuntimeException('--pivotes debe ser una lista de IDs enteros positivos separados por coma.');
            }
            $ids[(int) $parte] = (int) $parte;
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }

    /**
     * Filas de pagado_por con una punta propia (movimiento no provisorio de la cuenta) y la otra punta en OTRA cuenta.
     * Mismo join que vinculos_ajenos() de la guarda, acotado a "otra cuenta": huérfanos (la otra punta ya no existe),
     * provisorios de la misma cuenta y movimientos sin cuenta no son cruces de este modo y no se tocan.
     *
     * @param  int  $cuenta_id
     * @param  bool $bloquear  Tomar candado de fila (dentro de la transacción).
     * @return array<int,array{id:int,debe_id:int,haber_id:int,pagado:mixed,otra_cuenta:int}> ordenado por id
     */
    private function cruces_de_cuenta(int $cuenta_id, bool $bloquear): array
    {
        $filas = [];
        foreach (['debe_id' => 'haber_id', 'haber_id' => 'debe_id'] as $punta_propia => $punta_otra) {
            $consulta = DB::table('pagado_por as pp')
                ->join('current_acounts as propio', 'propio.id', '=', 'pp.'.$punta_propia)
                ->join('current_acounts as otro', 'otro.id', '=', 'pp.'.$punta_otra)
                ->where('propio.credit_account_id', $cuenta_id)
                ->whereRaw('COALESCE(propio.is_provisorio, 0) = 0')
                ->whereNotNull('otro.credit_account_id')
                ->where('otro.credit_account_id', '<>', $cuenta_id)
                ->select('pp.id', 'pp.debe_id', 'pp.haber_id', 'pp.pagado', 'otro.credit_account_id as otra_cuenta')
                ->orderBy('pp.id');
            if ($bloquear) {
                $consulta->lockForUpdate();
            }
            foreach ($consulta->get() as $fila) {
                $filas[(int) $fila->id] = ['id' => (int) $fila->id, 'debe_id' => (int) $fila->debe_id,
                    'haber_id' => (int) $fila->haber_id, 'pagado' => $fila->pagado, 'otra_cuenta' => (int) $fila->otra_cuenta];
            }
        }
        ksort($filas);
        return array_values($filas);
    }

    /**
     * IDs de la cuenta propia más las de sus contrapartes, ordenados.
     *
     * @param  int   $cuenta_id
     * @param  array $cruces    Salida de cruces_de_cuenta().
     * @return int[]
     */
    private function cuentas_involucradas(int $cuenta_id, array $cruces): array
    {
        $ids = [$cuenta_id => $cuenta_id];
        foreach ($cruces as $cruce) {
            $ids[$cruce['otra_cuenta']] = $cruce['otra_cuenta'];
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }

    /**
     * Dueño (cliente o proveedor) de cada cuenta, indexado por ID de cuenta. Lectura común previa a la transacción.
     *
     * @param  int[] $ids_cuentas
     * @return array<int,array{model_name:string,model_id:int}>
     */
    private function duenios_de_cuentas(array $ids_cuentas): array
    {
        $duenios = [];
        foreach (DB::table('credit_accounts')->whereIn('id', $ids_cuentas)->get() as $fila) {
            if (!isset(CuentaCorrienteLock::TABLAS[$fila->model_name])) {
                throw new RuntimeException('La cuenta #'.$fila->id.' no pertenece a un cliente o proveedor. Requiere revisión; no se escribió nada.');
            }
            $duenios[(int) $fila->id] = ['model_name' => $fila->model_name, 'model_id' => (int) $fila->model_id];
        }
        if (count($duenios) !== count($ids_cuentas)) {
            throw new RuntimeException('Hay vínculos hacia una cuenta que no existe. Requiere revisión; no se escribió nada.');
        }
        return $duenios;
    }

    /**
     * Simula o aplica la limpieza (camino sin respaldo previo). Corre dentro de la transacción con los candados tomados.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @param int[]                     $ids_cuentas
     * @param array                     $duenios
     * @param bool                      $aplicar
     * @param string|null               $ruta
     * @param int[]|null                $pivotes     IDs esperados por quien opera.
     */
    private function limpiar_vinculos($cuenta, array $ids_cuentas, array $duenios, bool $aplicar, $ruta, $pivotes): void
    {
        // La foto previa a la transacción no vale: se vuelve a seleccionar con candado de fila.
        $cruces = $this->cruces_de_cuenta((int) $cuenta->id, true);
        if ($this->cuentas_involucradas((int) $cuenta->id, $cruces) !== $ids_cuentas) {
            throw new RuntimeException('Cambiaron las cuentas vinculadas desde que se leyeron. Volvé a simular antes de operar.');
        }
        $cero = [];
        $con_importe = [];
        foreach ($cruces as $cruce) {
            // Importe exacto en centavos; un NULL nunca se asume cero.
            if (!is_null($cruce['pagado']) && is_numeric($cruce['pagado']) && (int) round((float) $cruce['pagado'] * 100) === 0) {
                $cero[] = $cruce;
            } else {
                $con_importe[] = $cruce;
            }
        }
        $fotos_antes = $this->fotos_vinculos($ids_cuentas, $duenios);
        $this->mostrar_vinculos($cuenta, $aplicar ? 'aplicar' : 'simulacion', $cero, $con_importe, $fotos_antes);
        if (!$aplicar) {
            if ($con_importe) {
                $this->warn('Hay cruces con importe distinto de cero: requieren decisión de soporte y este modo no los toca.');
            }
            $this->info('Simulación: no se escribieron datos ni archivos.');
            return;
        }
        if ($con_importe) {
            throw new RuntimeException('La cuenta tiene cruces con importe distinto de cero: este modo no los toca ni deja la cuenta a medias. No se escribió nada; requiere decisión.');
        }
        $ids_cero = array_column($cero, 'id');
        if ($ids_cero !== $pivotes) {
            throw new RuntimeException('La lista de --pivotes ('.implode(',', $pivotes).') no coincide con los cruces en $0 de la cuenta ('.implode(',', $ids_cero).'). Volvé a simular. No se escribió nada.');
        }
        foreach ($ids_cuentas as $id) {
            $this->validar_cadena_vinculos($id);
        }
        $filas_borradas = DB::table('pagado_por')->whereIn('id', $ids_cero)->orderBy('id')->lockForUpdate()->get()
            ->map(function ($fila) { return (array) $fila; })->all();
        $esperadas = $this->fotos_vinculos($ids_cuentas, $duenios, $ids_cero);
        $borradas = DB::table('pagado_por')->whereIn('id', $ids_cero)->where('pagado', 0)->delete();
        if ($borradas !== count($ids_cero)) {
            throw new RuntimeException('Se esperaba borrar '.count($ids_cero).' filas y se borraron '.$borradas.'. Se revierte la transacción.');
        }
        $fotos_despues = $this->fotos_vinculos($ids_cuentas, $duenios);
        if ($fotos_despues !== $esperadas) {
            throw new RuntimeException('Cambió algo más que las filas borradas (saldos, movimientos, estados, dueño, comisiones o puntos). Se revierte la transacción.');
        }
        // El respaldo se verifica antes del commit: un fallo de disco revierte el borrado.
        $datos = ['formato' => 'vinculos-en-cero-v1', 'base' => DB::connection()->getDatabaseName(),
            'instalacion' => (string) config('app.url'), 'cuenta_id' => (int) $cuenta->id,
            'creado_utc' => gmdate('c'), 'pivotes' => $filas_borradas, 'antes' => $fotos_antes, 'despues' => $fotos_despues];
        $this->guardar_respaldo($ruta, $datos);
        if ($this->leer_respaldo_vinculos($ruta, (int) $cuenta->id)['despues'] !== $this->fotos_vinculos($ids_cuentas, $duenios)) {
            throw new RuntimeException('La verificación posterior no coincide con el respaldo. Se revierte la transacción.');
        }
        $this->info('Cruces en $0 eliminados y verificados: '.count($ids_cero).' filas. Se conservaron importes, saldos, estados, fechas, documentos, comisiones y puntos de todas las cuentas involucradas.');
    }

    /**
     * Idempotencia: con respaldo previo, la limpieza ya aplicada no hace nada; cualquier otra foto es un error.
     */
    private function confirmar_vinculos_aplicados(array $ids_cuentas, array $duenios, array $respaldo, $pivotes): void
    {
        if ($this->fotos_vinculos($ids_cuentas, $duenios) !== $respaldo['despues']) {
            throw new RuntimeException('Las cuentas no coinciden con la limpieza respaldada. Revisá la actividad posterior; no se sobrescribe el respaldo ni se escribe en las cuentas.');
        }
        $ids_respaldo = array_column($respaldo['pivotes'], 'id');
        sort($ids_respaldo);
        if ($pivotes !== null && $pivotes !== $ids_respaldo) {
            throw new RuntimeException('La lista de --pivotes no coincide con la del respaldo ('.implode(',', $ids_respaldo).'). No se escribió nada.');
        }
        $this->info('La limpieza de vínculos de este respaldo ya está aplicada. No hay cambios.');
    }

    /**
     * Reversión: reinserta las filas respaldadas, con sus mismos IDs, sólo si todas las cuentas siguen idénticas a la
     * foto "después" del respaldo; verifica que vuelvan a ser idénticas a la foto "antes".
     */
    private function revertir_vinculos(array $ids_cuentas, array $duenios, array $respaldo, bool $aplicar): void
    {
        $actuales = $this->fotos_vinculos($ids_cuentas, $duenios);
        if ($actuales === $respaldo['antes']) {
            $this->info('Las cuentas ya coinciden con el estado anterior del respaldo. No hay cambios.');
            return;
        }
        if ($actuales !== $respaldo['despues']) {
            throw new RuntimeException('Hay cambios posteriores en alguna de las cuentas (movimientos, imputaciones, dueño, puntos o comisiones). Revisalos antes de revertir; no se modificó nada.');
        }
        if (!$aplicar) {
            $this->info('Simulación de reversión verificada: reinsertaría únicamente los '.count($respaldo['pivotes']).' cruces en $0 respaldados. No se escribieron datos ni archivos.');
            return;
        }
        $ids = array_column($respaldo['pivotes'], 'id');
        if (DB::table('pagado_por')->whereIn('id', $ids)->lockForUpdate()->count() !== 0) {
            throw new RuntimeException('Alguno de los IDs a restaurar ya existe en pagado_por. No se escribió nada.');
        }
        DB::table('pagado_por')->insert($respaldo['pivotes']);
        if ($this->fotos_vinculos($ids_cuentas, $duenios) !== $respaldo['antes']) {
            throw new RuntimeException('La reversión no coincide exactamente con el respaldo. Se revierte la transacción.');
        }
        $this->info('Reversión verificada: se restauraron los cruces en $0 con sus IDs originales.');
    }

    /**
     * Salida JSON de una línea del modo; sólo IDs e importes, sin datos personales.
     */
    private function mostrar_vinculos($cuenta, string $modo, array $cero, array $con_importe, array $fotos): void
    {
        $saldos = [];
        foreach ($fotos as $foto) {
            $saldos[(string) $foto['cuenta']['id']] = $foto['cuenta']['saldo'];
        }
        $this->line(json_encode(['cuenta_id' => (int) $cuenta->id, 'modo' => $modo, 'cero' => $cero,
            'con_importe' => $con_importe, 'saldos' => (object) $saldos],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Fotos de varias cuentas, ordenadas por ID de cuenta.
     *
     * @param  int[] $ids_cuentas
     * @param  array $duenios   Indexado por ID de cuenta.
     * @param  int[] $excluir   IDs de pagado_por a omitir (foto "esperada" tras el borrado).
     * @param  array $cambios   Sólo foto "esperada": columnas de movimientos que deben figurar cambiadas (id => [columna => valor]).
     * @return array[]
     */
    private function fotos_vinculos(array $ids_cuentas, array $duenios, array $excluir = [], array $cambios = []): array
    {
        $fotos = [];
        foreach ($ids_cuentas as $id) {
            $fotos[] = $this->foto_vinculos((int) $id, $duenios[(int) $id], $excluir, $cambios);
        }
        return $fotos;
    }

    /**
     * Foto compacta de una cuenta: fila de la cuenta, huellas del dueño, de TODAS las columnas de todos sus movimientos
     * (debe, haber, saldo, status, pagandose, updated_at…), de sus imputaciones y de comisiones/puntos.
     * Se guardan huellas y no las filas para que cuentas de decenas de miles de movimientos no inflen el respaldo; las
     * filas que se borran sí se guardan completas en el respaldo.
     *
     * @param  int   $cuenta_id
     * @param  array $duenio
     * @param  int[] $excluir  IDs de pagado_por que no se cuentan.
     * @param  array $cambios  Sólo foto "esperada": id de movimiento => [columna => valor] aplicado en memoria antes de la huella.
     * @return array
     */
    private function foto_vinculos(int $cuenta_id, array $duenio, array $excluir = [], array $cambios = []): array
    {
        $cuenta = DB::table('credit_accounts')->where('id', $cuenta_id)->lockForUpdate()->first();
        // El dueño ya está bloqueado desde la primera sentencia de la transacción (orden canónico); acá sólo se lee.
        $propietario = DB::table(CuentaCorrienteLock::TABLAS[$duenio['model_name']])->where('id', $duenio['model_id'])->first();
        if (!$cuenta || !$propietario) {
            throw new RuntimeException('No existe la cuenta o su dueño. Requiere revisión antes de operar.');
        }
        $movimientos = DB::table('current_acounts')->where('credit_account_id', $cuenta_id)->orderBy('id')->lockForUpdate()->get()
            ->map(function ($fila) { return (array) $fila; })->all();
        if ($cambios) {
            foreach ($movimientos as $indice => $movimiento) {
                if (isset($cambios[(int) $movimiento['id']])) {
                    $movimientos[$indice] = array_merge($movimiento, $cambios[(int) $movimiento['id']]);
                }
            }
        }
        $imputaciones = [];
        foreach ($this->imputaciones_de_cuenta($cuenta_id) as $fila) {
            if (!in_array((int) $fila['id'], $excluir, true)) {
                $imputaciones[] = $fila;
            }
        }
        return ['cuenta' => (array) $cuenta, 'duenio_sha256' => $this->hash_foto((array) $propietario),
            'movimientos_total' => count($movimientos), 'movimientos_sha256' => $this->hash_foto($movimientos),
            'imputaciones_total' => count($imputaciones), 'imputaciones_sha256' => $this->hash_foto($imputaciones),
            'dependencias_sha256' => $this->huella_dependencias_vinculos($duenio, $movimientos)];
    }

    /**
     * Imputaciones con alguna punta en la cuenta, por join (sin listas de IDs: una cuenta grande superaría los
     * 65.535 marcadores de MariaDB, error 1390).
     *
     * @return array[] ordenadas por ID
     */
    private function imputaciones_de_cuenta(int $cuenta_id): array
    {
        $filas = [];
        foreach (['debe_id', 'haber_id'] as $punta) {
            $consulta = DB::table('pagado_por as pp')->join('current_acounts as ca', 'ca.id', '=', 'pp.'.$punta)
                ->where('ca.credit_account_id', $cuenta_id)->select('pp.*')->orderBy('pp.id');
            foreach ($consulta->get() as $fila) {
                $filas[(int) $fila->id] = (array) $fila;
            }
        }
        ksort($filas);
        return array_values($filas);
    }

    /**
     * Huella de comisiones, puntos y sus consumos ligados a la cuenta (guarda de actividad ajena a pagado_por),
     * con las listas de IDs en lotes por el mismo motivo que imputaciones_de_cuenta().
     */
    private function huella_dependencias_vinculos(array $duenio, array $movimientos): string
    {
        $ids = array_column($movimientos, 'id');
        $ventas = array_values(array_unique(array_filter(array_column($movimientos, 'sale_id'))));
        $unir = function (array $porLotes) {
            $filas = [];
            foreach ($porLotes as $lote) {
                foreach ($lote as $fila) {
                    $filas[(int) $fila->id] = (array) $fila;
                }
            }
            ksort($filas);
            return array_values($filas);
        };
        $lotes = [];
        foreach (array_chunk($ventas, 1000) as $lote) {
            $lotes[] = DB::table('seller_commissions')->whereIn('sale_id', $lote)->get();
        }
        $comisiones = $unir($lotes);
        $lotes = [];
        foreach (array_chunk($ids, 1000) as $lote) {
            $lotes[] = DB::table('current_acount_seller_commission')->whereIn('current_acount_id', $lote)->get();
        }
        foreach (array_chunk(array_column($comisiones, 'id'), 1000) as $lote) {
            $lotes[] = DB::table('current_acount_seller_commission')->whereIn('seller_commission_id', $lote)->get();
        }
        $vinculos = $unir($lotes);
        $lotes = [];
        foreach (array_chunk($ventas, 1000) as $lote) {
            $lotes[] = DB::table('movimiento_puntos')->whereIn('sale_id', $lote)->get();
        }
        if ($duenio['model_name'] === 'client') {
            $lotes[] = DB::table('movimiento_puntos')->where('client_id', $duenio['model_id'])->get();
        }
        $puntos = $unir($lotes);
        $lotes = [];
        foreach (array_chunk(array_column($puntos, 'id'), 1000) as $lote) {
            $lotes[] = DB::table('movimiento_punto_consumos')->whereIn('movimiento_origen_id', $lote)->get();
            $lotes[] = DB::table('movimiento_punto_consumos')->whereIn('movimiento_consumo_id', $lote)->get();
        }
        $consumos = $unir($lotes);
        return $this->hash_foto(['comisiones' => $comisiones, 'vinculos_comision' => $vinculos,
            'puntos' => $puntos, 'consumos' => $consumos]);
    }

    /**
     * Huella de una foto. A diferencia de hash(), sustituye los bytes UTF-8 inválidos en vez de lanzar: una columna de
     * texto vieja con codificación rota no debe impedir verificar que nada cambió.
     */
    private function hash_foto(array $datos): string
    {
        return hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * Misma verificación de cadena que validar_saldos_guardados() (saldo de cada movimiento no provisorio y de la cuenta
     * contra Debe − Haber acumulado en centavos), sin el plan de créditos. Se mantiene aparte para no tocar el modo existente.
     */
    private function validar_cadena_vinculos(int $cuenta_id): void
    {
        $filas = DB::table('current_acounts')->where('credit_account_id', $cuenta_id)
            ->select('id', 'debe', 'haber', 'saldo', 'created_at', 'is_provisorio')
            ->orderBy('created_at')->orderBy('id')->get();
        $saldo = 0;
        foreach ($filas as $fila) {
            if ((int) $fila->is_provisorio) {
                continue;
            }
            $saldo += (int) round((float) $fila->debe * 100) - (int) round((float) $fila->haber * 100);
            if (!is_numeric($fila->saldo) || (int) round((float) $fila->saldo * 100) !== $saldo) {
                throw new RuntimeException('El saldo guardado del movimiento #'.$fila->id.' (cuenta #'.$cuenta_id.') no coincide con su historial. Pedile a soporte que revise la cadena de saldos; este modo no recalcula importes ni saldos. No se escribió nada.');
            }
        }
        $guardado = DB::table('credit_accounts')->where('id', $cuenta_id)->value('saldo');
        if (!is_numeric($guardado) || (int) round((float) $guardado * 100) !== $saldo) {
            throw new RuntimeException('El saldo guardado de la cuenta #'.$cuenta_id.' no coincide con el saldo del historial. Pedile a soporte que revise ese descuadre; este modo no modifica el saldo. No se escribió nada.');
        }
    }

    /**
     * Lee y valida un respaldo vinculos-en-cero-v1 (formato, instalación, cuenta, sha256, fotos y filas completas).
     *
     * @return array Datos del respaldo ya validados.
     * @throws RuntimeException si falta, está alterado o pertenece a otra instalación o cuenta.
     */
    private function leer_respaldo_vinculos(string $ruta, int $cuenta_id): array
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
        if (($datos['formato'] ?? null) !== 'vinculos-en-cero-v1'
            || ($datos['base'] ?? null) !== DB::connection()->getDatabaseName()
            || ($datos['instalacion'] ?? null) !== (string) config('app.url')
            || ($datos['cuenta_id'] ?? null) !== $cuenta_id || !is_string($sha)
            || !hash_equals($this->hash($datos), $sha)
            || !is_array($datos['pivotes'] ?? null) || !$datos['pivotes']
            || !is_array($datos['antes'] ?? null) || !is_array($datos['despues'] ?? null)) {
            throw new RuntimeException('El respaldo está incompleto, alterado o pertenece a otra instalación o cuenta. No se escribió nada.');
        }
        $referenciados = [];
        foreach ($datos['pivotes'] as $fila) {
            if (is_array($fila)) {
                foreach (['debe_id', 'haber_id'] as $punta) {
                    if (isset($fila[$punta]) && ctype_digit((string) $fila[$punta])) {
                        $referenciados[(int) $fila[$punta]] = true;
                    }
                }
            }
        }
        // Reusa las validaciones de campos, importes y fechas; la pertenencia a una cuenta no aplica a un cruce.
        $this->validar_imputaciones($datos['pivotes'], $referenciados);
        $claves = ['cuenta', 'duenio_sha256', 'movimientos_total', 'movimientos_sha256', 'imputaciones_total',
            'imputaciones_sha256', 'dependencias_sha256'];
        sort($claves);
        $cuentas = [];
        foreach (['antes', 'despues'] as $estado) {
            $ids = [];
            foreach ($datos[$estado] as $foto) {
                $presentes = is_array($foto) ? array_keys($foto) : [];
                sort($presentes);
                if ($presentes !== $claves || !is_array($foto['cuenta']) || !isset($foto['cuenta']['id'])
                    || !is_int($foto['movimientos_total']) || !is_int($foto['imputaciones_total'])) {
                    throw new RuntimeException('El respaldo no contiene una foto completa de las cuentas. No se escribió nada.');
                }
                foreach (['duenio_sha256', 'movimientos_sha256', 'imputaciones_sha256', 'dependencias_sha256'] as $huella) {
                    if (!is_string($foto[$huella]) || !preg_match('/^[a-f0-9]{64}$/D', $foto[$huella])) {
                        throw new RuntimeException('El respaldo contiene huellas inválidas. No se escribió nada.');
                    }
                }
                $ids[] = (int) $foto['cuenta']['id'];
            }
            $cuentas[$estado] = $ids;
        }
        $ordenadas = $cuentas['antes'];
        sort($ordenadas);
        if (!$ordenadas || $cuentas['antes'] !== $ordenadas || $cuentas['antes'] !== $cuentas['despues']
            || !in_array($cuenta_id, $ordenadas, true) || count(array_unique($ordenadas)) !== count($ordenadas)) {
            throw new RuntimeException('El respaldo no contiene las mismas cuentas antes y después. No se escribió nada.');
        }
        return $datos;
    }

    // ------------------------------------------------------------------------------------------
    // Modo --vinculos-con-importe (misión cc-cruces-con-importe, 25/11/2026)
    //
    // 🔴 POR QUÉ EXISTE. Hay pagos (H) de una cuenta que "consumieron de más": además de cubrir las deudas propias de su
    // cuenta, quedaron imputados a un débito (D) de OTRA cuenta con importe distinto de cero. Esos cruces traban el
    // recálculo (la guarda de vínculos rechaza cualquier pagado_por que cruza cuentas) y no mueven ningún saldo.
    // CRITERIO FIJADO POR LUCAS Y EL COORDINADOR (no se rediscute): quitar el vínculo cruzado y recalcular SÓLO el estado de
    // pago de D a partir de las imputaciones propias que le quedan. H no se toca. Ningún saldo puede cambiar (aserción).
    //
    // Trabaja con UN par D<->H por corrida, con ids exactos (--debe/--haber/--pivotes), candado de los dos dueños, respaldo
    // verificado antes del commit y reversión. Es el modo hermano de --vinculos-en-cero y sólo usa DB, CuentaCorrienteLock,
    // CreditAccount y métodos privados de este archivo: así el mismo archivo corre idéntico sobre los tags ya publicados
    // (los helpers CreditosHistoricosHelper/CurrentAcountHelper difieren entre tags y develop y no se invocan).
    // ------------------------------------------------------------------------------------------

    /**
     * Simula, aplica o revierte la corrección de UN par D<->H cruzado entre cuentas.
     *
     * @param  \App\Models\CreditAccount $cuenta   Cuenta de D (la que se corrige).
     * @param  bool                      $aplicar  Escribir (con --revertir: ejecutar la reversión).
     * @param  bool                      $revertir Operar sobre el respaldo, deshaciendo la corrección.
     * @param  string|null               $ruta     Respaldo absoluto ya validado (puede no existir todavía).
     * @return void
     * @throws RuntimeException ante cualquier precondición que falle: nada queda escrito.
     */
    private function vinculos_con_importe($cuenta, bool $aplicar, bool $revertir, $ruta): void
    {
        $debe_id = $this->leer_id_movimiento_del_par('debe');
        $haber_id = $this->leer_id_movimiento_del_par('haber');
        $pivotes = $this->leer_pivotes_pedidos();
        if ($aplicar && !$revertir && $pivotes === null) {
            throw new RuntimeException('Con --aplicar indicá --pivotes con los IDs exactos de pagado_por que simulaste. No se escribió nada.');
        }
        $respaldo = $ruta && is_file($ruta) ? $this->leer_respaldo_con_importe($ruta, (int) $cuenta->id) : null;
        if ($revertir && !$respaldo) {
            throw new RuntimeException('No se encontró el respaldo original. La reversión no puede reconstruirse por suposición.');
        }
        if ($respaldo && ((int) $respaldo['debe_id'] !== $debe_id || (int) $respaldo['haber_id'] !== $haber_id)) {
            throw new RuntimeException('El respaldo pertenece a otro par (--debe/--haber). No se escribió nada.');
        }

        // Lectura previa (sin candado) para resolver cuentas y dueños: el candado debe ser la primera sentencia de la
        // transacción. Todo se vuelve a leer y a validar adentro, con candado de fila.
        $debe = DB::table('current_acounts')->where('id', $debe_id)->first();
        $haber = DB::table('current_acounts')->where('id', $haber_id)->first();
        $this->validar_movimientos_del_par($debe, $haber, (int) $cuenta->id);
        $ids_cuentas = [(int) $debe->credit_account_id, (int) $haber->credit_account_id];
        sort($ids_cuentas);
        if ($respaldo) {
            $en_respaldo = array_map(function ($foto) { return (int) $foto['cuenta']['id']; }, $respaldo['antes']);
            if ($en_respaldo !== $ids_cuentas) {
                throw new RuntimeException('Las cuentas del respaldo no son las del par indicado. No se escribió nada.');
            }
        }
        $duenios = $this->duenios_de_cuentas($ids_cuentas);

        DB::transaction(function () use ($cuenta, $debe_id, $haber_id, $aplicar, $revertir, $ruta, $pivotes, $respaldo, $ids_cuentas, $duenios) {
            $this->tomar_candados_de_cuentas($ids_cuentas, $duenios);
            if ($revertir) {
                $this->revertir_con_importe($ids_cuentas, $duenios, $respaldo, $aplicar);
                return;
            }
            if ($respaldo) {
                $this->confirmar_con_importe_aplicado($ids_cuentas, $duenios, $respaldo, $pivotes);
                return;
            }
            $this->corregir_par_con_importe($cuenta, $debe_id, $haber_id, $ids_cuentas, $duenios, $aplicar, $ruta, $pivotes);
        });
    }

    /**
     * Interpreta --debe / --haber: ID entero positivo de un movimiento. Son obligatorios en este modo.
     *
     * @param  string $opcion 'debe' o 'haber'.
     * @return int
     */
    private function leer_id_movimiento_del_par(string $opcion): int
    {
        $crudo = $this->option($opcion);
        if ($crudo === null || !ctype_digit(trim((string) $crudo)) || (int) trim((string) $crudo) < 1) {
            throw new RuntimeException('Con --vinculos-con-importe indicá --debe y --haber con los IDs positivos de los dos movimientos del par. No se escribió nada.');
        }
        return (int) trim((string) $crudo);
    }

    /**
     * Candado de los dos dueños en orden canónico (todos los clientes ascendentes y después los proveedores, como el modo
     * hermano: un orden fijo evita que dos procesos se esperen en círculo) y de las filas de credit_accounts. Debe ser
     * lo primero que ejecuta la transacción.
     *
     * @param int[]  $ids_cuentas
     * @param array  $duenios     Indexado por ID de cuenta.
     */
    private function tomar_candados_de_cuentas(array $ids_cuentas, array $duenios): void
    {
        $por_tipo = ['client' => [], 'provider' => []];
        foreach ($duenios as $duenio) {
            $por_tipo[$duenio['model_name']][] = $duenio['model_id'];
        }
        CuentaCorrienteLock::bloquear('client', $por_tipo['client']);
        CuentaCorrienteLock::bloquear('provider', $por_tipo['provider']);
        $filas = DB::table('credit_accounts')->whereIn('id', $ids_cuentas)->orderBy('id')->lockForUpdate()->get();
        foreach ($filas as $fila) {
            $esperado = $duenios[(int) $fila->id];
            if ($fila->model_name !== $esperado['model_name'] || (int) $fila->model_id !== $esperado['model_id']) {
                throw new RuntimeException('Cambió la identidad de una cuenta involucrada. Volvé a simular antes de operar.');
            }
        }
        if (count($filas) !== count($ids_cuentas)) {
            throw new RuntimeException('Falta una cuenta involucrada. Volvé a simular antes de operar.');
        }
    }

    /**
     * Valida los dos movimientos del par: existen, no son provisorios, D es un débito de la cuenta pedida, H es un haber
     * con importe y viven en cuentas distintas. Se usa antes de la transacción (sin candado) y adentro (con candado).
     *
     * @param  object|null $debe       Fila de current_acounts de D.
     * @param  object|null $haber      Fila de current_acounts de H.
     * @param  int         $cuenta_id  Cuenta pedida en --cuenta: tiene que ser la de D.
     * @return void
     */
    private function validar_movimientos_del_par($debe, $haber, int $cuenta_id): void
    {
        if (!$debe) {
            throw new RuntimeException('El movimiento de --debe no existe. No se escribió nada.');
        }
        if (!$haber) {
            throw new RuntimeException('El movimiento de --haber no existe. No se escribió nada.');
        }
        if ((int) $debe->credit_account_id !== $cuenta_id) {
            throw new RuntimeException('El movimiento #'.$debe->id.' (--debe) no pertenece a la cuenta indicada en --cuenta (#'.$cuenta_id.'). No se escribió nada.');
        }
        if (is_null($debe->debe) || !is_numeric($debe->debe) || (!is_null($debe->haber) && (int) round((float) $debe->haber * 100) !== 0)) {
            throw new RuntimeException('El movimiento #'.$debe->id.' (--debe) no es un débito. No se escribió nada.');
        }
        if (is_null($haber->haber) || !is_numeric($haber->haber) || (int) round((float) $haber->haber * 100) <= 0
            || (!is_null($haber->debe) && (int) round((float) $haber->debe * 100) !== 0)) {
            throw new RuntimeException('El movimiento #'.$haber->id.' (--haber) no es un haber con importe. No se escribió nada.');
        }
        if ((int) ($debe->is_provisorio ?? 0) !== 0 || (int) ($haber->is_provisorio ?? 0) !== 0) {
            throw new RuntimeException('Alguno de los movimientos del par es provisorio. No se escribió nada.');
        }
        if (is_null($debe->credit_account_id) || is_null($haber->credit_account_id)) {
            throw new RuntimeException('Alguno de los movimientos del par no tiene cuenta. No se escribió nada.');
        }
        if ((int) $debe->credit_account_id === (int) $haber->credit_account_id) {
            throw new RuntimeException('Los movimientos #'.$debe->id.' y #'.$haber->id.' son de la misma cuenta: no es un cruce entre cuentas y este modo no corresponde. No se escribió nada.');
        }
    }

    /**
     * Centavos enteros de un importe decimal; null si no es un número finito.
     */
    private function a_centavos($importe): ?int
    {
        return (!is_null($importe) && is_numeric($importe) && is_finite((float) $importe)) ? (int) round((float) $importe * 100) : null;
    }

    /**
     * Estado de pago que le corresponde a D según el sistema (misma regla que CurrentAcountPagoHelper::procesarPago(),
     * reimputar_pagos() y CreditosHistoricosHelper::plan(), que no se llaman a propósito): un crédito (debe < 0) queda
     * pagado en cero; si no, sin imputaciones es "sin_pagar"/0, parcial "pagandose"/suma y completo "pagado"/debe.
     *
     * @param  int $debe_centavos    Importe de D.
     * @param  int $propias_centavos Suma de lo imputado a D por haberes de su propia cuenta.
     * @return array{0:string,1:int} [status, pagandose en centavos]
     */
    private function estado_de_pago_de_debe(int $debe_centavos, int $propias_centavos): array
    {
        if ($debe_centavos < 0) {
            return ['pagado', 0];
        }
        if ($propias_centavos < 0 || $propias_centavos > $debe_centavos) {
            throw new RuntimeException('Las imputaciones propias de D (suman '.number_format($propias_centavos / 100, 2, '.', '').') superan su importe ('.number_format($debe_centavos / 100, 2, '.', '').') o son negativas: no se puede calcular su estado. Requiere decisión; no se escribió nada.');
        }
        if ($propias_centavos === 0) {
            return ['sin_pagar', 0];
        }
        return [$propias_centavos === $debe_centavos ? 'pagado' : 'pagandose', $propias_centavos];
    }

    /**
     * Simula o aplica la corrección del par (camino sin respaldo previo). Corre dentro de la transacción con los candados
     * tomados y vuelve a leer todo: la lectura previa no vale.
     *
     * @param \App\Models\CreditAccount $cuenta
     * @param int                       $debe_id
     * @param int                       $haber_id
     * @param int[]                     $ids_cuentas
     * @param array                     $duenios
     * @param bool                      $aplicar
     * @param string|null               $ruta
     * @param int[]|null                $pivotes     IDs esperados por quien opera.
     */
    private function corregir_par_con_importe($cuenta, int $debe_id, int $haber_id, array $ids_cuentas, array $duenios, bool $aplicar, $ruta, $pivotes): void
    {
        $debe = DB::table('current_acounts')->where('id', $debe_id)->lockForUpdate()->first();
        $haber = DB::table('current_acounts')->where('id', $haber_id)->lockForUpdate()->first();
        $this->validar_movimientos_del_par($debe, $haber, (int) $cuenta->id);
        $actuales = [(int) $debe->credit_account_id, (int) $haber->credit_account_id];
        sort($actuales);
        if ($actuales !== $ids_cuentas) {
            throw new RuntimeException('Cambiaron las cuentas del par desde que se leyeron. Volvé a simular antes de operar.');
        }

        // Filas del par (con importe y en $0), con candado de fila.
        $filas_par = DB::table('pagado_por')->where('debe_id', $debe_id)->where('haber_id', $haber_id)
            ->orderBy('id')->lockForUpdate()->get()->map(function ($fila) { return (array) $fila; })->all();
        if (!$filas_par) {
            throw new RuntimeException('El par #'.$debe_id.'/#'.$haber_id.' está sin filas en pagado_por: ya no hay cruce entre esos movimientos o los IDs no forman un par. No se escribió nada.');
        }
        $suma_par = 0;
        $hay_importe = false;
        foreach ($filas_par as $fila) {
            $centavos = $this->a_centavos($fila['pagado']);
            if ($centavos === null) {
                throw new RuntimeException('La fila #'.$fila['id'].' del par tiene un importe inválido. No se escribió nada.');
            }
            $suma_par += $centavos;
            $hay_importe = $hay_importe || $centavos !== 0;
        }
        if (!$hay_importe) {
            throw new RuntimeException('Todas las filas del par valen $0: usá --vinculos-en-cero. No se escribió nada.');
        }
        if (DB::table('pagado_por')->where('debe_id', $haber_id)->where('haber_id', $debe_id)->lockForUpdate()->count() > 0) {
            throw new RuntimeException('Existen imputaciones en sentido inverso entre los dos movimientos (H como débito de D): forma no prevista, requiere decisión. No se escribió nada.');
        }
        // IDs como enteros: el driver puede devolverlos como texto y la comparación con --pivotes es estricta.
        $ids_par = array_map('intval', array_column($filas_par, 'id'));

        // Imputaciones propias de D: todo lo que le queda debe venir de haberes de su misma cuenta.
        $propias_d = DB::table('pagado_por as pp')->leftJoin('current_acounts as ha', 'ha.id', '=', 'pp.haber_id')
            ->where('pp.debe_id', $debe_id)->whereNotIn('pp.id', $ids_par)
            ->select('pp.id', 'pp.haber_id', 'pp.pagado', 'ha.credit_account_id as cuenta_haber', 'ha.is_provisorio as provisorio_haber')
            ->orderBy('pp.id')->lockForUpdate()->get();
        $suma_propias_d = 0;
        $detalle_propias_d = [];
        foreach ($propias_d as $fila) {
            $centavos = $this->a_centavos($fila->pagado);
            if (is_null($fila->cuenta_haber) || (int) $fila->cuenta_haber !== (int) $debe->credit_account_id
                || (int) ($fila->provisorio_haber ?? 0) !== 0 || $centavos === null) {
                throw new RuntimeException('D tiene otras imputaciones no propias (fila #'.$fila->id.' hacia el haber #'.$fila->haber_id.': otra cuenta, huérfana o provisoria). No es un cruce de consumo en exceso; requiere decisión. No se escribió nada.');
            }
            $suma_propias_d += $centavos;
            $detalle_propias_d[] = ['id' => (int) $fila->id, 'haber_id' => (int) $fila->haber_id, 'pagado' => $fila->pagado];
        }

        // H tiene que quedar cubierto exacto por sus imputaciones propias: si no, el cruce no era un consumo en exceso.
        $propias_h = DB::table('pagado_por as pp')->leftJoin('current_acounts as de', 'de.id', '=', 'pp.debe_id')
            ->where('pp.haber_id', $haber_id)->whereNotIn('pp.id', $ids_par)
            ->select('pp.id', 'pp.debe_id', 'pp.pagado', 'de.credit_account_id as cuenta_debe', 'de.is_provisorio as provisorio_debe')
            ->orderBy('pp.id')->lockForUpdate()->get();
        $suma_propias_h = 0;
        foreach ($propias_h as $fila) {
            $centavos = $this->a_centavos($fila->pagado);
            if (is_null($fila->cuenta_debe) || (int) $fila->cuenta_debe !== (int) $haber->credit_account_id
                || (int) ($fila->provisorio_debe ?? 0) !== 0 || $centavos === null) {
                throw new RuntimeException('H tiene imputaciones no propias (fila #'.$fila->id.' hacia el débito #'.$fila->debe_id.': otra cuenta, huérfana o provisoria). H no quedaría cubierto por sus imputaciones propias; requiere decisión. No se escribió nada.');
            }
            $suma_propias_h += $centavos;
        }
        $haber_centavos = (int) $this->a_centavos($haber->haber);
        if ($suma_propias_h !== $haber_centavos) {
            throw new RuntimeException('H no quedaría cubierto por sus imputaciones propias (suman '.number_format($suma_propias_h / 100, 2, '.', '').' y su haber es '.number_format($haber_centavos / 100, 2, '.', '').'). Requiere decisión; no se escribió nada.');
        }

        $debe_centavos = (int) $this->a_centavos($debe->debe);
        list($status_nuevo, $pagandose_nuevo_centavos) = $this->estado_de_pago_de_debe($debe_centavos, $suma_propias_d);
        $pagandose_nuevo = number_format($pagandose_nuevo_centavos / 100, 2, '.', '');
        $cambia_estado = $debe->status !== $status_nuevo || $this->a_centavos($debe->pagandose) !== $pagandose_nuevo_centavos;

        $error_de_cadena = $this->error_de_cadena($ids_cuentas);
        $fotos_antes = $this->fotos_vinculos($ids_cuentas, $duenios);
        $saldos_antes = $this->saldos_vinculos($ids_cuentas, $duenios);
        $this->mostrar_par_con_importe($cuenta, $aplicar ? 'aplicar' : 'simulacion', $debe, $haber, $filas_par, $suma_par,
            $detalle_propias_d, $suma_propias_h, $status_nuevo, $pagandose_nuevo_centavos, $ids_par, $error_de_cadena === null,
            $ids_cuentas, $duenios, $fotos_antes);
        if (!$aplicar) {
            $this->info('Simulación: no se escribieron datos ni archivos.');
            return;
        }

        if ($pivotes !== $ids_par) {
            throw new RuntimeException('La lista de --pivotes ('.implode(',', $pivotes).') no coincide con las filas del par ('.implode(',', $ids_par).'). Volvé a simular. No se escribió nada.');
        }
        if ($error_de_cadena !== null) {
            throw new RuntimeException($error_de_cadena);
        }

        // Foto "esperada" construida ANTES de escribir, desde la base tal como está: sin las filas del par y con D en su
        // estado nuevo (en memoria). Armarla después compararía la base consigo misma y no detectaría nada.
        $cambios = $cambia_estado ? [$debe_id => ['status' => $status_nuevo, 'pagandose' => $pagandose_nuevo]] : [];
        $fotos_esperadas = $this->fotos_vinculos($ids_cuentas, $duenios, $ids_par, $cambios);

        // Escritura 1: se borran exactamente las filas del par.
        $borradas = DB::table('pagado_por')->whereIn('id', $ids_par)->where('debe_id', $debe_id)->where('haber_id', $haber_id)->delete();
        if ($borradas !== count($ids_par)) {
            throw new RuntimeException('Se esperaba borrar '.count($ids_par).' filas y se borraron '.$borradas.'. Se revierte la transacción.');
        }
        // Escritura 2: sólo status y pagandose de D, con guarda del valor leído. Por DB::table: no toca updated_at, así la
        // reversión queda exacta y la foto sólo difiere en esas dos columnas.
        if ($cambia_estado) {
            $consulta = DB::table('current_acounts')->where('id', $debe_id)->where('status', $debe->status);
            if (is_null($debe->pagandose)) {
                $consulta->whereNull('pagandose');
            } else {
                $consulta->where('pagandose', $debe->pagandose);
            }
            if ($consulta->update(['status' => $status_nuevo, 'pagandose' => $pagandose_nuevo]) !== 1) {
                throw new RuntimeException('No se pudo actualizar el estado de D (cambió mientras se operaba). Se revierte la transacción.');
            }
        } else {
            $this->info('El estado de pago de D ya es el calculado: sólo se quitan las filas del par.');
        }

        // Aserciones en la misma transacción: ningún saldo cambia y lo único distinto es lo que se quiso cambiar.
        if ($this->saldos_vinculos($ids_cuentas, $duenios) !== $saldos_antes) {
            throw new RuntimeException('Cambió un saldo (de un movimiento, de una cuenta o de un dueño). Se revierte la transacción.');
        }
        $fotos_despues = $this->fotos_vinculos($ids_cuentas, $duenios);
        if ($fotos_despues !== $fotos_esperadas) {
            throw new RuntimeException('Cambió algo más que las filas del par y el estado de D (movimientos, saldos, dueño, comisiones o puntos). Se revierte la transacción.');
        }
        $debe_despues = (array) DB::table('current_acounts')->where('id', $debe_id)->first();

        // El respaldo se verifica antes del commit: un fallo de disco revierte todo.
        $datos = ['formato' => 'vinculos-con-importe-v1', 'base' => DB::connection()->getDatabaseName(),
            'instalacion' => (string) config('app.url'), 'cuenta_id' => (int) $cuenta->id,
            'debe_id' => $debe_id, 'haber_id' => $haber_id, 'creado_utc' => gmdate('c'),
            'pivotes' => $filas_par, 'debe_antes' => (array) $debe, 'debe_despues' => $debe_despues,
            'antes' => $fotos_antes, 'despues' => $fotos_despues];
        $this->guardar_respaldo($ruta, $datos);
        if ($this->leer_respaldo_con_importe($ruta, (int) $cuenta->id)['despues'] !== $this->fotos_vinculos($ids_cuentas, $duenios)) {
            throw new RuntimeException('La verificación posterior no coincide con el respaldo. Se revierte la transacción.');
        }
        $this->info('Cruce corregido y verificado: '.count($ids_par).' filas quitadas; D queda '.$status_nuevo.' ('.$pagandose_nuevo.'). Se conservaron saldos, H, fechas, documentos, comisiones y puntos de las cuentas involucradas.');
    }

    /**
     * Idempotencia: con respaldo previo, la corrección ya aplicada no hace nada; cualquier otra foto es un error.
     */
    private function confirmar_con_importe_aplicado(array $ids_cuentas, array $duenios, array $respaldo, $pivotes): void
    {
        if ($this->fotos_vinculos($ids_cuentas, $duenios) !== $respaldo['despues']) {
            throw new RuntimeException('Las cuentas no coinciden con la corrección respaldada. Revisá la actividad posterior; no se sobrescribe el respaldo ni se escribe en las cuentas.');
        }
        $ids_respaldo = array_column($respaldo['pivotes'], 'id');
        sort($ids_respaldo);
        if ($pivotes !== null && $pivotes !== $ids_respaldo) {
            throw new RuntimeException('La lista de --pivotes no coincide con la del respaldo ('.implode(',', $ids_respaldo).'). No se escribió nada.');
        }
        $this->info('La corrección de este respaldo ya está aplicada. No hay cambios.');
    }

    /**
     * Reversión: reinserta las filas del par con sus mismos IDs y devuelve el estado de D, sólo si las dos cuentas siguen
     * idénticas a la foto "después" del respaldo; verifica que vuelvan a ser idénticas a la foto "antes".
     */
    private function revertir_con_importe(array $ids_cuentas, array $duenios, array $respaldo, bool $aplicar): void
    {
        $actuales = $this->fotos_vinculos($ids_cuentas, $duenios);
        if ($actuales === $respaldo['antes']) {
            $this->info('Las cuentas ya coinciden con el estado anterior del respaldo. No hay cambios.');
            return;
        }
        if ($actuales !== $respaldo['despues']) {
            throw new RuntimeException('Hay cambios posteriores en alguna de las cuentas (movimientos, imputaciones, dueño, puntos o comisiones). Revisalos antes de revertir; no se modificó nada.');
        }
        if (!$aplicar) {
            $this->info('Simulación de reversión verificada: reinsertaría únicamente las '.count($respaldo['pivotes']).' filas del par y devolvería el estado anterior de D. No se escribieron datos ni archivos.');
            return;
        }
        $ids = array_column($respaldo['pivotes'], 'id');
        if (DB::table('pagado_por')->whereIn('id', $ids)->lockForUpdate()->count() !== 0) {
            throw new RuntimeException('Alguno de los IDs a restaurar ya existe en pagado_por. No se escribió nada.');
        }
        DB::table('pagado_por')->insert($respaldo['pivotes']);
        $antes = $respaldo['debe_antes'];
        $despues = $respaldo['debe_despues'];
        if ($antes['status'] !== $despues['status'] || $antes['pagandose'] !== $despues['pagandose']) {
            $consulta = DB::table('current_acounts')->where('id', (int) $respaldo['debe_id'])->where('status', $despues['status']);
            if (is_null($despues['pagandose'])) {
                $consulta->whereNull('pagandose');
            } else {
                $consulta->where('pagandose', $despues['pagandose']);
            }
            if ($consulta->update(['status' => $antes['status'], 'pagandose' => $antes['pagandose']]) !== 1) {
                throw new RuntimeException('No se pudo devolver el estado de D (cambió mientras se operaba). Se revierte la transacción.');
            }
        }
        if ($this->fotos_vinculos($ids_cuentas, $duenios) !== $respaldo['antes']) {
            throw new RuntimeException('La reversión no coincide exactamente con el respaldo. Se revierte la transacción.');
        }
        $this->info('Reversión verificada: se restauraron las filas del par con sus IDs originales y el estado de D.');
    }

    /**
     * Mensaje de la primera cadena de saldos descuadrada entre las cuentas, o null si todas cuadran. Permite que la
     * simulación lo informe (cadena_ok) y que aplicar se niegue, sin duplicar la verificación.
     *
     * @param  int[] $ids_cuentas
     * @return string|null
     */
    private function error_de_cadena(array $ids_cuentas): ?string
    {
        foreach ($ids_cuentas as $id) {
            try {
                $this->validar_cadena_vinculos((int) $id);
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        }
        return null;
    }

    /**
     * Todos los saldos en juego: de cada movimiento de las cuentas, de la cuenta y de su dueño. Antes y después de operar
     * deben ser idénticos (criterio: este modo no puede mover un saldo).
     *
     * @param  int[] $ids_cuentas
     * @param  array $duenios      Indexado por ID de cuenta.
     * @return array
     */
    private function saldos_vinculos(array $ids_cuentas, array $duenios): array
    {
        $saldos = [];
        foreach ($ids_cuentas as $id) {
            $duenio = $duenios[(int) $id];
            $propietario = (array) DB::table(CuentaCorrienteLock::TABLAS[$duenio['model_name']])->where('id', $duenio['model_id'])->first();
            $del_duenio = array_filter($propietario, function ($clave) { return strpos((string) $clave, 'saldo') === 0; }, ARRAY_FILTER_USE_KEY);
            $saldos[(string) $id] = ['movimientos' => DB::table('current_acounts')->where('credit_account_id', $id)->orderBy('id')->pluck('saldo', 'id')->all(),
                'cuenta' => DB::table('credit_accounts')->where('id', $id)->value('saldo'), 'duenio' => $del_duenio];
        }
        return $saldos;
    }

    /**
     * Suma de haberes propios de la cuenta de D que ninguna imputación de la propia cuenta consume (informativo: si es
     * positiva, tras quitar el cruce D puede figurar impago aunque la cuenta tenga haber disponible hasta un recálculo).
     *
     * @return float
     */
    private function haber_libre_de_cuenta(int $cuenta_id): float
    {
        $haberes = $this->a_centavos(DB::table('current_acounts')->where('credit_account_id', $cuenta_id)
            ->whereRaw('COALESCE(is_provisorio, 0) = 0')->sum('haber')) ?? 0;
        $consumido = $this->a_centavos(DB::table('pagado_por as pp')
            ->join('current_acounts as de', 'de.id', '=', 'pp.debe_id')->join('current_acounts as ha', 'ha.id', '=', 'pp.haber_id')
            ->where('de.credit_account_id', $cuenta_id)->where('ha.credit_account_id', $cuenta_id)
            ->whereRaw('COALESCE(ha.is_provisorio, 0) = 0')->sum('pp.pagado')) ?? 0;
        return ($haberes - $consumido) / 100;
    }

    /**
     * Cantidad de comisiones de vendedor y de movimientos de puntos ligados a la cuenta de D. No se tocan ni se
     * revierten (si D deja de figurar pagado, un recálculo futuro podría hacerlo: por eso se informan).
     *
     * @return array{comisiones:int,puntos:int}
     */
    private function contar_dependencias_de_cuenta(int $cuenta_id, array $duenio): array
    {
        $comisiones = DB::table('seller_commissions as sc')->join('current_acounts as ca', 'ca.sale_id', '=', 'sc.sale_id')
            ->where('ca.credit_account_id', $cuenta_id)->distinct()->count('sc.id');
        $puntos = DB::table('movimiento_puntos')->where(function ($q) use ($cuenta_id, $duenio) {
            $q->whereIn('sale_id', function ($sub) use ($cuenta_id) {
                $sub->select('sale_id')->from('current_acounts')->where('credit_account_id', $cuenta_id)->whereNotNull('sale_id');
            });
            if ($duenio['model_name'] === 'client') {
                $q->orWhere('client_id', $duenio['model_id']);
            }
        })->count();
        return ['comisiones' => (int) $comisiones, 'puntos' => (int) $puntos];
    }

    /**
     * Salida JSON de una línea del modo; sólo IDs e importes, sin datos personales.
     */
    private function mostrar_par_con_importe($cuenta, string $modo, $debe, $haber, array $filas_par, int $suma_par, array $propias_d,
        int $suma_propias_h, string $status_nuevo, int $pagandose_nuevo_centavos, array $ids_par, bool $cadena_ok,
        array $ids_cuentas, array $duenios, array $fotos): void
    {
        $saldos = [];
        foreach ($fotos as $foto) {
            $saldos[(string) $foto['cuenta']['id']] = $foto['cuenta']['saldo'];
        }
        $otros = [];
        foreach ($this->cruces_de_cuenta((int) $cuenta->id, true) as $cruce) {
            if (!in_array($cruce['id'], $ids_par, true)) {
                $otros[] = $cruce;
            }
        }
        $pivotes = [];
        foreach ($filas_par as $fila) {
            $pivotes[] = ['id' => (int) $fila['id'], 'pagado' => $fila['pagado']];
        }
        $this->line(json_encode(['cuenta_id' => (int) $cuenta->id, 'modo' => $modo,
            'debe' => ['id' => (int) $debe->id, 'cuenta' => (int) $debe->credit_account_id, 'debe' => $debe->debe,
                'status' => $debe->status, 'pagandose' => $debe->pagandose, 'status_nuevo' => $status_nuevo,
                'pagandose_nuevo' => $pagandose_nuevo_centavos / 100, 'propias' => $propias_d],
            'haber' => ['id' => (int) $haber->id, 'cuenta' => (int) $haber->credit_account_id, 'haber' => $haber->haber,
                'propias_suma' => $suma_propias_h / 100, 'to_pay_id' => $haber->to_pay_id],
            'pivotes' => $pivotes, 'pivotes_suma' => $suma_par / 100, 'otros_cruces' => $otros, 'cadena_ok' => $cadena_ok,
            'haber_libre_cuenta_debe' => $this->haber_libre_de_cuenta((int) $cuenta->id),
            'dependencias' => $this->contar_dependencias_de_cuenta((int) $cuenta->id, $duenios[(int) $cuenta->id]),
            'saldos' => (object) $saldos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Lee y valida un respaldo vinculos-con-importe-v1 (formato, instalación, cuenta, sha256, par, filas y fotos).
     *
     * @return array Datos del respaldo ya validados.
     * @throws RuntimeException si falta, está alterado o pertenece a otra instalación, cuenta o par.
     */
    private function leer_respaldo_con_importe(string $ruta, int $cuenta_id): array
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
        if (($datos['formato'] ?? null) !== 'vinculos-con-importe-v1'
            || ($datos['base'] ?? null) !== DB::connection()->getDatabaseName()
            || ($datos['instalacion'] ?? null) !== (string) config('app.url')
            || ($datos['cuenta_id'] ?? null) !== $cuenta_id || !is_string($sha)
            || !hash_equals($this->hash($datos), $sha)
            || !is_int($datos['debe_id'] ?? null) || !is_int($datos['haber_id'] ?? null)
            || !is_array($datos['pivotes'] ?? null) || !$datos['pivotes']
            || !is_array($datos['debe_antes'] ?? null) || !is_array($datos['debe_despues'] ?? null)
            || !is_array($datos['antes'] ?? null) || !is_array($datos['despues'] ?? null)) {
            throw new RuntimeException('El respaldo está incompleto, alterado o pertenece a otra instalación o cuenta. No se escribió nada.');
        }
        // Las dos fotos de D: mismo movimiento, de la cuenta pedida, con las columnas que se restauran.
        foreach (['debe_antes', 'debe_despues'] as $clave) {
            $fila = $datos[$clave];
            if ((int) ($fila['id'] ?? 0) !== $datos['debe_id'] || (int) ($fila['credit_account_id'] ?? 0) !== $cuenta_id
                || !array_key_exists('status', $fila) || !is_string($fila['status'])
                || !array_key_exists('pagandose', $fila) || (!is_null($fila['pagandose']) && !is_string($fila['pagandose']))) {
                throw new RuntimeException('El respaldo no contiene una foto válida del débito. No se escribió nada.');
            }
        }
        $referenciados = [];
        foreach ($datos['pivotes'] as $fila) {
            if (!is_array($fila) || (int) ($fila['debe_id'] ?? 0) !== $datos['debe_id'] || (int) ($fila['haber_id'] ?? 0) !== $datos['haber_id']) {
                throw new RuntimeException('El respaldo contiene filas ajenas al par. No se escribió nada.');
            }
            $referenciados[(int) $fila['debe_id']] = true;
            $referenciados[(int) $fila['haber_id']] = true;
        }
        // Reusa las validaciones de campos, importes y fechas; la pertenencia a una cuenta no aplica a un cruce.
        $this->validar_imputaciones($datos['pivotes'], $referenciados);
        $claves = ['cuenta', 'duenio_sha256', 'movimientos_total', 'movimientos_sha256', 'imputaciones_total',
            'imputaciones_sha256', 'dependencias_sha256'];
        sort($claves);
        $cuentas = [];
        foreach (['antes', 'despues'] as $estado) {
            $ids = [];
            foreach ($datos[$estado] as $foto) {
                $presentes = is_array($foto) ? array_keys($foto) : [];
                sort($presentes);
                if ($presentes !== $claves || !is_array($foto['cuenta']) || !isset($foto['cuenta']['id'])
                    || !is_int($foto['movimientos_total']) || !is_int($foto['imputaciones_total'])) {
                    throw new RuntimeException('El respaldo no contiene una foto completa de las cuentas. No se escribió nada.');
                }
                foreach (['duenio_sha256', 'movimientos_sha256', 'imputaciones_sha256', 'dependencias_sha256'] as $huella) {
                    if (!is_string($foto[$huella]) || !preg_match('/^[a-f0-9]{64}$/D', $foto[$huella])) {
                        throw new RuntimeException('El respaldo contiene huellas inválidas. No se escribió nada.');
                    }
                }
                $ids[] = (int) $foto['cuenta']['id'];
            }
            $cuentas[$estado] = $ids;
        }
        $ordenadas = $cuentas['antes'];
        sort($ordenadas);
        if (count($ordenadas) !== 2 || $cuentas['antes'] !== $ordenadas || $cuentas['antes'] !== $cuentas['despues']
            || !in_array($cuenta_id, $ordenadas, true) || count(array_unique($ordenadas)) !== count($ordenadas)) {
            throw new RuntimeException('El respaldo no contiene las mismas dos cuentas antes y después. No se escribió nada.');
        }
        return $datos;
    }
}
