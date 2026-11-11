<?php

namespace App\Services\Importacion;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\NotaCreditoHelper;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\ImportacionDeSoporteCambio;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

/**
 * Importación de clientes y proveedores pedida desde un ticket de soporte, con resumen, código de confirmación y
 * deshacer (misión importaciones-de-martin, 11/11/2026). Hermana de ImportacionDePruebaService (artículos): el
 * mismo dueño activo, el mismo código `CONFIRMAR <código>` atado a la huella del resumen y los avisos por
 * WhatsApp por el mismo camino (`importacion_lista` con el ticket).
 *
 * El recorrido:
 *   analizar     → el analizador de IA de siempre (AiClientAnalyzer / AiProviderAnalyzer) arma el mapeo, y
 *                  PlanDeContactosDeSoporte el resumen: cuántos se crean, cuántos ya existen y qué saldos cambian.
 *   confirmacion → espera el código del dueño. Pregunta por WhatsApp con el resumen.
 *   importar     → vuelve a armar el resumen; si cambió (alguien cargó un pago, otro cliente…) pregunta de
 *                  nuevo con otro código. Si es el mismo, importa con ClientImport / ProviderImport de siempre,
 *                  todo en una transacción, y deja registrado cada cambio para deshacerlo.
 *   terminada    → revertible: los creados se borran (si no tienen movimientos propios después), los
 *                  actualizados vuelven a sus valores de antes (solo los campos que nadie tocó después) y los
 *                  movimientos de saldo se borran o, si la cuenta ya tiene movimientos posteriores o la nota
 *                  está facturada, se anulan con un contraasiento.
 *
 * 🔴 Todo por dueño (`user_id`): hay bases compartidas entre comercios.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class ImportacionDeSoporteService
{
    /** Texto de los movimientos que genera el ajuste de saldo del importador (LocalImportHelper). */
    const OBSERVACION_DEL_AJUSTE = 'Ajuste por importacion de Excel para actualizar el saldo.';

    /** Texto de los contraasientos de una reversión. */
    const OBSERVACION_DE_LA_REVERSION = 'Reversión de importación de Excel desde soporte.';

    /** Campos que cada importador puede escribir: lo que se guarda para restaurar. */
    const CAMPOS = [
        'client' => ['num', 'name', 'phone', 'address', 'email', 'razon_social', 'cuit', 'cuil', 'dni', 'description',
            'iva_condition_id', 'provincia_id', 'location_id', 'seller_id', 'price_type_id', 'address_id'],
        'provider' => ['num', 'name', 'phone', 'address', 'location_id', 'email', 'razon_social', 'cuit',
            'observations', 'iva_condition_id'],
    ];

    /* ------------------------------------------------------------------------------------------------------
     * Avance (lo llama el job)
     * ---------------------------------------------------------------------------------------------------- */

    public function avanzar(ImportacionDeSoporte $corrida): void
    {
        if (!in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)) {
            return;
        }
        if (!ImportacionDePruebaService::dueno_activo((int) $corrida->user_id)) {
            $this->fallar($corrida, 'El negocio dejó de estar habilitado para importar desde soporte.');
            return;
        }

        if ($corrida->estado === 'analizar') {
            $this->analizar($corrida);
            return;
        }

        $this->importar($corrida);
    }

    private function analizar(ImportacionDeSoporte $corrida): void
    {
        $ruta = storage_path('app/' . $corrida->archivo);
        $analizador = $corrida->modelo === 'provider'
            ? app(AiProviderAnalyzer::class, ['user_id' => (int) $corrida->user_id])
            : app(AiClientAnalyzer::class, ['user_id' => (int) $corrida->user_id]);

        try {
            $analisis = $analizador->analyze($ruta, (string) $corrida->nombre);
        } catch (RuntimeException $error) {
            $this->fallar($corrida, 'No pude leer «' . $this->archivo($corrida) . '»: ' . rtrim($error->getMessage(), '.') . '.');
            return;
        }

        $columnas = PlanDeContactosDeSoporte::columnas_desde_mapeo($corrida->modelo, (array) ($analisis['column_mapping'] ?? []));
        $encabezado = isset($analisis['encabezado_detectado']['fila']) && is_numeric($analisis['encabezado_detectado']['fila'])
            ? max(1, (int) $analisis['encabezado_detectado']['fila']) : 1;
        $corrida->fill([
            'columnas' => $columnas,
            'hoja' => isset($analisis['hoja_elegida']['indice']) ? (int) $analisis['hoja_elegida']['indice'] : 0,
            'hoja_nombre' => isset($analisis['hoja_elegida']['nombre']) && is_string($analisis['hoja_elegida']['nombre'])
                ? mb_substr($analisis['hoja_elegida']['nombre'], 0, 120) : null,
            'fila_inicio' => $encabezado + 1,
        ]);

        try {
            $plan = PlanDeContactosDeSoporte::armar($corrida);
        } catch (RuntimeException $error) {
            $corrida->save();
            $this->fallar($corrida, 'No pude preparar «' . $this->archivo($corrida) . '»: ' . rtrim($error->getMessage(), '.') . '.');
            return;
        }

        if ($plan['resumen']['filas'] === 0) {
            $corrida->save();
            $this->fallar($corrida, 'El archivo «' . $this->archivo($corrida) . '» no tiene filas con ' . $corrida->etiqueta_plural() . ' para importar.');
            return;
        }

        DB::transaction(function () use ($corrida, $plan) {
            $this->preguntar($corrida, $plan);
        });
    }

    /**
     * Deja la corrida esperando el código: nueva revisión, código nuevo y la pregunta por WhatsApp.
     */
    private function preguntar(ImportacionDeSoporte $corrida, array $plan): void
    {
        $corrida->retirar_pregunta();
        $revision = (int) $corrida->revision + 1;
        $codigo = strtoupper(substr(hash('sha256', $corrida->uuid . ':' . $revision), 0, 8));
        $corrida->fill([
            'estado' => 'confirmacion',
            'revision' => $revision,
            'codigo' => $codigo,
            'huella' => $plan['huella'],
            'resumen' => $plan['resumen'],
        ]);
        $corrida->save();
        $corrida->aviso('pregunta-' . $revision, $this->texto_de_confirmacion($corrida));
    }

    /**
     * Importa la corrida confirmada. Si el resumen cambió desde que el dueño lo aprobó, vuelve a preguntar.
     */
    private function importar(ImportacionDeSoporte $corrida): void
    {
        $owner = User::findOrFail((int) $corrida->user_id);
        $plan = PlanDeContactosDeSoporte::armar($corrida);

        if ($plan['huella'] !== $corrida->huella) {
            DB::transaction(function () use ($corrida, $plan) {
                $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                if ($corrida->estado === 'importar') {
                    $this->preguntar($corrida, $plan);
                }
            });
            return;
        }

        $resultado = $this->como_dueno($owner, function () use ($corrida, $owner, $plan) {
            return DB::transaction(function () use ($corrida, $owner, $plan) {
                User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                if ($corrida->estado !== 'importar') {
                    return null;
                }

                return $this->ejecutar($corrida, $plan['existentes']);
            });
        });

        if (is_null($resultado)) {
            return;
        }

        $corrida->refresh();
        $corrida->aviso('resultado', $this->texto_del_resultado($corrida));
    }

    /**
     * La importación en sí, adentro de la transacción: los importadores de siempre y el registro de lo que hicieron.
     *
     * @param  \App\Models\ImportacionDeSoporte $corrida
     * @param  int[] $existentes  ids que el resumen dice que se van a tocar
     * @return array
     */
    private function ejecutar(ImportacionDeSoporte $corrida, array $existentes): array
    {
        $es_cliente = $corrida->modelo === 'client';
        $clase = $es_cliente ? Client::class : Provider::class;
        $campos = self::CAMPOS[$corrida->modelo];
        $owner_id = (int) $corrida->user_id;

        $antes = [];
        foreach (array_chunk($existentes, 500) as $lote) {
            foreach ($clase::where('user_id', $owner_id)->whereIn('id', $lote)->get() as $modelo) {
                $antes[(int) $modelo->id] = $this->valores($modelo, $campos);
            }
        }
        $ultimo_modelo = (int) $clase::withTrashed()->max('id');
        $ultimo_movimiento = (int) CurrentAcount::max('id');

        $columnas = $corrida->columnas ?? [];
        $importador = $es_cliente
            ? new ClientImport($columnas, true, (int) $corrida->fila_inicio, null, (int) $corrida->hoja, $corrida->hoja_nombre, false)
            : new ProviderImport($columnas, true, (int) $corrida->fila_inicio, null, null, (int) $corrida->hoja, $corrida->hoja_nombre, false);
        Excel::import($importador, storage_path('app/' . $corrida->archivo));

        $ahora = Carbon::now();
        $filas = [];
        $creados = $clase::where('user_id', $owner_id)->where('id', '>', $ultimo_modelo)->orderBy('id')->pluck('id')->all();
        foreach ($creados as $id) {
            $filas[] = $this->fila_de_cambio($corrida, ImportacionDeSoporteCambio::TIPO_CREADO, (int) $id, null, null, null, $ahora);
        }

        $actualizados = 0;
        foreach (array_chunk(array_keys($antes), 500) as $lote) {
            foreach ($clase::where('user_id', $owner_id)->whereIn('id', $lote)->get() as $modelo) {
                $despues = $this->valores($modelo, $campos);
                $cambiaron = [];
                foreach ($despues as $campo => $valor) {
                    if ((string) $valor !== (string) $antes[(int) $modelo->id][$campo] || is_null($valor) !== is_null($antes[(int) $modelo->id][$campo])) {
                        $cambiaron[] = $campo;
                    }
                }
                if ($cambiaron === []) {
                    continue;
                }
                $actualizados++;
                $filas[] = $this->fila_de_cambio($corrida, ImportacionDeSoporteCambio::TIPO_ACTUALIZADO, (int) $modelo->id, null,
                    array_intersect_key($antes[(int) $modelo->id], array_flip($cambiaron)), array_intersect_key($despues, array_flip($cambiaron)), $ahora);
            }
        }

        $tocados = array_merge($creados, array_keys($antes));
        $movimientos = [];
        $columna = $es_cliente ? 'client_id' : 'provider_id';
        foreach (array_chunk($tocados, 500) as $lote) {
            $nuevos = CurrentAcount::where('id', '>', $ultimo_movimiento)->whereIn($columna, $lote)
                ->where(function ($consulta) {
                    $consulta->where(function ($inicial) {
                        $inicial->where('detalle', 'Saldo inicial')->whereNull('user_id');
                    })->orWhere('description', self::OBSERVACION_DEL_AJUSTE);
                })->orderBy('id')->get();
            foreach ($nuevos as $movimiento) {
                $movimientos[] = $movimiento;
                $filas[] = $this->fila_de_cambio($corrida, ImportacionDeSoporteCambio::TIPO_MOVIMIENTO, (int) $movimiento->{$columna},
                    (int) $movimiento->id, null, [
                        'tipo' => $this->tipo_de_movimiento($movimiento),
                        'debe' => is_null($movimiento->debe) ? null : (float) $movimiento->debe,
                        'haber' => is_null($movimiento->haber) ? null : (float) $movimiento->haber,
                        'credit_account_id' => (int) $movimiento->credit_account_id,
                    ], $ahora);
            }
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('importacion_de_soporte_cambios')->insert($lote);
        }

        $por_tipo = ['saldo_inicial' => 0, 'nota_de_credito' => 0, 'nota_de_debito' => 0];
        foreach ($movimientos as $movimiento) {
            $por_tipo[$this->tipo_de_movimiento($movimiento)]++;
        }
        $resultado = [
            'creados' => count($creados),
            'actualizados' => $actualizados,
            'movimientos' => $por_tipo,
            'saldos_no_cargados' => count(array_filter($corrida->resumen['saldos'] ?? [], function ($saldo) {
                return ($saldo['accion'] ?? null) === 'no_se_carga';
            })),
        ];
        $corrida->fill([
            'estado' => 'terminada',
            'terminada_at' => $ahora,
            'resultado' => $resultado,
        ]);
        $corrida->save();

        return $resultado;
    }

    /* ------------------------------------------------------------------------------------------------------
     * Confirmar, cancelar y revertir (los llama el controlador)
     * ---------------------------------------------------------------------------------------------------- */

    /**
     * El dueño mandó el código. Devuelve [status, mensaje|null]: 202 confirmada (o cancelada con 200), 409 si la
     * corrida no espera código o el código no es el de la revisión vigente.
     */
    public function confirmar(ImportacionDeSoporte $corrida, string $codigo, string $decision = 'confirmar'): array
    {
        $codigo = strtoupper(trim($codigo));

        return DB::transaction(function () use ($corrida, $codigo, $decision) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            $esperado = $decision === 'cancelar' ? 'cancelada' : 'importar';
            if ($corrida->estado === $esperado && hash_equals((string) $corrida->codigo, $codigo)) {
                return [200, null, $corrida];
            }
            if ($corrida->estado !== 'confirmacion') {
                return [409, 'codigo_vencido', $corrida];
            }
            if ($codigo === '' || !hash_equals((string) $corrida->codigo, $codigo)) {
                return [409, 'codigo_incorrecto', $corrida];
            }

            $corrida->retirar_pregunta();
            $corrida->estado = $esperado;
            if ($decision !== 'cancelar') {
                $corrida->confirmada_at = Carbon::now();
            }
            $corrida->save();

            return [$decision === 'cancelar' ? 200 : 202, null, $corrida];
        });
    }

    /**
     * Deshace una corrida terminada. Devuelve [status, motivo|null, corrida].
     */
    public function revertir(ImportacionDeSoporte $corrida): array
    {
        if ($corrida->estado === 'revertida') {
            return [200, null, $corrida];
        }
        if ($corrida->estado !== 'terminada') {
            return [409, 'no_revertible', $corrida];
        }

        $owner = User::findOrFail((int) $corrida->user_id);
        $resultado = $this->como_dueno($owner, function () use ($corrida, $owner) {
            return DB::transaction(function () use ($corrida, $owner) {
                User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                if ($corrida->estado !== 'terminada') {
                    return null;
                }

                return $this->deshacer($corrida);
            });
        });

        $corrida->refresh();
        if (!is_null($resultado)) {
            $corrida->aviso('reversion', $this->texto_de_la_reversion($corrida));
        }

        return [200, null, $corrida];
    }

    private function deshacer(ImportacionDeSoporte $corrida): array
    {
        $es_cliente = $corrida->modelo === 'client';
        $clase = $es_cliente ? Client::class : Provider::class;
        $owner_id = (int) $corrida->user_id;
        $cambios = $corrida->cambios()->orderBy('id')->get();
        $movimientos = $cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_MOVIMIENTO);
        $ids_de_movimientos = $movimientos->pluck('current_acount_id')->map(function ($id) {
            return (int) $id;
        })->all();
        $cuentas = [];
        $cuenta = ['creados_borrados' => 0, 'creados_conservados' => 0, 'actualizados_restaurados' => 0,
            'actualizados_conservados' => 0, 'movimientos_borrados' => 0, 'contraasientos' => 0];
        $modelos_conservados = [];

        foreach ($cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_CREADO) as $cambio) {
            $modelo = $clase::where('user_id', $owner_id)->find($cambio->modelo_id);
            if (is_null($modelo)) {
                $this->marcar($cambio, 'revertido', 'ya no existía');
                continue;
            }
            $motivo = $this->motivo_para_conservar($modelo, $es_cliente, $ids_de_movimientos);
            if (!is_null($motivo)) {
                $modelos_conservados[(int) $modelo->id] = true;
                $cuenta['creados_conservados']++;
                $this->marcar($cambio, 'conservado', $motivo);
                continue;
            }
            foreach ($movimientos->where('modelo_id', $cambio->modelo_id) as $movimiento_del_creado) {
                $movimiento = CurrentAcount::find($movimiento_del_creado->current_acount_id);
                if (!is_null($movimiento)) {
                    // La cuenta queda vacía y sin dueño visible: no hay saldo que recalcular.
                    $movimiento->delete();
                    $cuenta['movimientos_borrados']++;
                }
                $this->marcar($movimiento_del_creado, 'revertido', 'se borró con el ' . ($es_cliente ? 'cliente' : 'proveedor'));
            }
            $modelo->delete();
            $cuenta['creados_borrados']++;
            $this->marcar($cambio, 'revertido', null);
        }

        foreach ($cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_ACTUALIZADO) as $cambio) {
            $modelo = $clase::where('user_id', $owner_id)->find($cambio->modelo_id);
            if (is_null($modelo)) {
                $cuenta['actualizados_conservados']++;
                $this->marcar($cambio, 'conservado', 'ya no existe');
                continue;
            }
            $restaurar = [];
            $tocados_despues = [];
            foreach ((array) $cambio->despues as $campo => $valor) {
                $actual = $modelo->getAttribute($campo);
                if ((string) $actual === (string) $valor && is_null($actual) === is_null($valor)) {
                    $restaurar[$campo] = $cambio->antes[$campo] ?? null;
                } else {
                    $tocados_despues[] = $campo;
                }
            }
            if ($restaurar !== []) {
                $modelo->forceFill($restaurar)->save();
            }
            if ($tocados_despues === []) {
                $cuenta['actualizados_restaurados']++;
                $this->marcar($cambio, 'revertido', null);
            } else {
                $cuenta['actualizados_conservados']++;
                $this->marcar($cambio, 'conservado', 'cambiaron después: ' . implode(', ', $tocados_despues));
            }
        }

        foreach ($movimientos as $cambio) {
            if (!is_null($cambio->reversion) || isset($modelos_conservados[(int) $cambio->modelo_id])) {
                if (is_null($cambio->reversion)) {
                    $this->marcar($cambio, 'conservado', 'el ' . ($es_cliente ? 'cliente' : 'proveedor') . ' se conservó');
                }
                continue;
            }
            $movimiento = CurrentAcount::find($cambio->current_acount_id);
            if (is_null($movimiento)) {
                $this->marcar($cambio, 'revertido', 'ya no existía');
                continue;
            }
            $cuentas[(int) $movimiento->credit_account_id] = true;
            $posteriores = CurrentAcount::where('credit_account_id', $movimiento->credit_account_id)
                ->where('id', '>', $movimiento->id)->whereNotIn('id', $ids_de_movimientos)->exists();
            $facturada = $movimiento->status === 'nota_credito'
                && !is_null(NotaCreditoHelper::motivo_por_el_que_no_se_puede_eliminar($movimiento));
            if (!$posteriores && !$facturada) {
                if ($movimiento->status === 'nota_credito') {
                    AfipTicket::where('nota_credito_id', $movimiento->id)->delete();
                }
                $movimiento->delete();
                $cuenta['movimientos_borrados']++;
                $this->marcar($cambio, 'revertido', null);
                continue;
            }
            $this->contraasiento($movimiento, $es_cliente ? 'client' : 'provider', (int) $cambio->modelo_id);
            $cuenta['contraasientos']++;
            $this->marcar($cambio, 'contraasiento', $facturada ? 'la nota está facturada' : 'la cuenta tiene movimientos posteriores');
        }

        foreach (array_keys($cuentas) as $credit_account_id) {
            CurrentAcountHelper::checkSaldos($credit_account_id);
            CurrentAcountHelper::checkPagos($credit_account_id);
            CurrentAcountHelper::update_credit_account_saldo($credit_account_id);
        }

        $resultado = (array) $corrida->resultado;
        $resultado['reversion'] = $cuenta;
        $corrida->fill(['estado' => 'revertida', 'revertida_at' => Carbon::now(), 'resultado' => $resultado]);
        $corrida->save();

        return $cuenta;
    }

    /**
     * Por qué un creado por la importación no se borra al revertir (o null si se puede borrar): ya tiene vida
     * propia en el sistema.
     */
    private function motivo_para_conservar($modelo, bool $es_cliente, array $ids_de_movimientos)
    {
        $columna = $es_cliente ? 'client_id' : 'provider_id';
        if (CurrentAcount::where($columna, $modelo->id)->whereNotIn('id', $ids_de_movimientos)->exists()) {
            return 'tiene movimientos en su cuenta corriente';
        }
        if ($es_cliente) {
            return Sale::where('client_id', $modelo->id)->exists() ? 'tiene ventas' : null;
        }
        if (ProviderOrder::where('provider_id', $modelo->id)->exists()) {
            return 'tiene pedidos o compras';
        }
        if (Article::withTrashed()->where('provider_id', $modelo->id)->exists()
            || DB::table('article_provider')->where('provider_id', $modelo->id)->exists()) {
            return 'tiene artículos';
        }

        return null;
    }

    /**
     * Anula un movimiento con el opuesto por el mismo monto: una nota de crédito contra un débito (saldo inicial
     * deudor o nota de débito) y una de débito contra un crédito (nota de crédito o saldo inicial a favor).
     */
    private function contraasiento(CurrentAcount $movimiento, string $modelo_nombre, int $modelo_id): void
    {
        $debe = (float) $movimiento->debe;
        $haber = (float) $movimiento->haber;
        if ($debe > 0.004) {
            CurrentAcountHelper::notaCredito($movimiento->credit_account_id, $debe, self::OBSERVACION_DE_LA_REVERSION, $modelo_nombre, $modelo_id);
            return;
        }
        if ($haber <= 0.004) {
            return;
        }

        // Mismo armado que la nota de débito de LocalImportHelper::ajustarSaldoPorImportacion().
        $nota = CurrentAcount::create([
            'detalle' => 'Nota de debito',
            'description' => self::OBSERVACION_DE_LA_REVERSION,
            'debe' => $haber,
            'status' => 'sin_pagar',
            'client_id' => $modelo_nombre === 'client' ? $modelo_id : null,
            'provider_id' => $modelo_nombre === 'provider' ? $modelo_id : null,
            'user_id' => Auth::id(),
            'credit_account_id' => $movimiento->credit_account_id,
            'moneda_id' => 1,
        ]);
        $nota->saldo = CurrentAcountHelper::getSaldo($movimiento->credit_account_id, $nota) + $haber;
        $nota->save();
    }

    /* ------------------------------------------------------------------------------------------------------
     * Payload, textos y utilidades
     * ---------------------------------------------------------------------------------------------------- */

    /**
     * La forma de la corrida en el contrato con el admin.
     */
    public static function payload(ImportacionDeSoporte $corrida): array
    {
        $esperando = $corrida->estado === 'confirmacion';

        return [
            'corrida_id' => (string) $corrida->uuid,
            'modelo' => (string) $corrida->modelo,
            'estado' => (string) $corrida->estado,
            'archivo' => (string) $corrida->nombre,
            'support_ticket_id' => (int) $corrida->support_ticket_id,
            'revision' => (int) $corrida->revision,
            'resumen' => $corrida->resumen,
            'codigo_requerido' => $esperando,
            'codigo_confirmacion' => $esperando ? (string) $corrida->codigo : null,
            'texto_confirmacion' => $esperando ? (new self())->texto_de_confirmacion($corrida) : null,
            'resultado' => $corrida->resultado,
            'error' => $corrida->error,
            'revertible' => $corrida->estado === 'terminada',
            'confirmada_at' => is_null($corrida->confirmada_at) ? null : $corrida->confirmada_at->toIso8601String(),
            'terminada_at' => is_null($corrida->terminada_at) ? null : $corrida->terminada_at->toIso8601String(),
            'revertida_at' => is_null($corrida->revertida_at) ? null : $corrida->revertida_at->toIso8601String(),
        ];
    }

    public function texto_de_confirmacion(ImportacionDeSoporte $corrida): string
    {
        $resumen = (array) $corrida->resumen;
        $plural = $corrida->etiqueta_plural();
        $crear = (int) ($resumen['crear'] ?? 0);
        $actualizar = (int) ($resumen['actualizar'] ?? 0);
        $texto = 'Preparé «' . $this->archivo($corrida) . '» para importar ' . $plural . ': se crean ' . $crear
            . ' y se actualizan ' . $actualizar . ' que ya estaban cargados (mismo número o mismo nombre).';
        if (!empty($resumen['ejemplos_crear'])) {
            $texto .= "\nNuevos, por ejemplo: " . implode(', ', array_slice($resumen['ejemplos_crear'], 0, 5)) . '.';
        }
        if (!empty($resumen['ejemplos_actualizar'])) {
            $texto .= "\nYa cargados, por ejemplo: " . implode(', ', array_slice($resumen['ejemplos_actualizar'], 0, 5)) . '.';
        }

        $saldos = (array) ($resumen['saldos'] ?? []);
        $total = (int) ($resumen['saldos_total'] ?? count($saldos));
        if ($total > 0) {
            $texto .= "\n\nSaldos que cambian (" . $total . "):";
            foreach (array_slice($saldos, 0, 15) as $saldo) {
                $texto .= "\n- " . $this->linea_de_saldo($saldo);
            }
            if ($total > 15) {
                $texto .= "\n- y " . ($total - 15) . ' más.';
            }
        }
        $ilegibles = (array) ($resumen['saldos_ilegibles'] ?? []);
        if ($ilegibles !== []) {
            $texto .= "\n\n" . count($ilegibles) . (count($ilegibles) === 1 ? ' saldo no se puede leer y no se va a cargar' : ' saldos no se pueden leer y no se van a cargar')
                . ' (por ejemplo, fila ' . $ilegibles[0]['fila'] . ': "' . mb_substr((string) $ilegibles[0]['texto'], 0, 30) . '").';
        }

        return $texto . "\n\nSi algo no queda bien, la importación se puede deshacer."
            . "\n\nPara aprobar la carga respondé CONFIRMAR " . $corrida->codigo . '. Para cancelarla, respondé CANCELAR ' . $corrida->codigo . '.';
    }

    private function linea_de_saldo(array $saldo): string
    {
        $nombre = mb_substr((string) ($saldo['nombre'] ?? ''), 0, 60);
        $accion = (string) ($saldo['accion'] ?? '');
        if ($accion === 'saldo_inicial') {
            return $nombre . ': saldo inicial de ' . $this->plata($saldo['saldo_resultante']);
        }
        if ($accion === 'no_se_carga') {
            return $nombre . ': el Excel dice ' . $this->plata($saldo['saldo_excel'] ?? 0) . ' y la cuenta tiene '
                . $this->plata($saldo['saldo_actual']) . ' con movimientos; no se cambia.';
        }

        return $nombre . ': de ' . $this->plata($saldo['saldo_actual']) . ' a ' . $this->plata($saldo['saldo_resultante'])
            . ' (nota de ' . ($accion === 'nota_de_credito' ? 'crédito' : 'débito') . ' por ' . $this->plata($saldo['monto']) . ')';
    }

    public function texto_del_resultado(ImportacionDeSoporte $corrida): string
    {
        $resultado = (array) $corrida->resultado;
        $movimientos = (array) ($resultado['movimientos'] ?? []);
        $texto = 'Terminé de importar «' . $this->archivo($corrida) . '»: ' . (int) ($resultado['creados'] ?? 0) . ' '
            . $corrida->etiqueta_plural() . ' creados y ' . (int) ($resultado['actualizados'] ?? 0) . ' actualizados.';
        $saldos = (int) ($movimientos['saldo_inicial'] ?? 0) + (int) ($movimientos['nota_de_credito'] ?? 0) + (int) ($movimientos['nota_de_debito'] ?? 0);
        if ($saldos > 0) {
            $texto .= ' Cargué ' . (int) ($movimientos['saldo_inicial'] ?? 0) . ' saldos iniciales, '
                . (int) ($movimientos['nota_de_credito'] ?? 0) . ' notas de crédito y ' . (int) ($movimientos['nota_de_debito'] ?? 0)
                . ' notas de débito en las cuentas corrientes.';
        }

        return $texto . ' Si querés deshacerla, avisame por acá.';
    }

    private function texto_de_la_reversion(ImportacionDeSoporte $corrida): string
    {
        $reversion = (array) (((array) $corrida->resultado)['reversion'] ?? []);
        $plural = $corrida->etiqueta_plural();
        $texto = 'Deshice la importación de «' . $this->archivo($corrida) . '»: borré ' . (int) ($reversion['creados_borrados'] ?? 0)
            . ' ' . $plural . ' nuevos y dejé como estaban ' . (int) ($reversion['actualizados_restaurados'] ?? 0) . ' que se habían actualizado.';
        $conservados = (int) ($reversion['creados_conservados'] ?? 0) + (int) ($reversion['actualizados_conservados'] ?? 0);
        if ($conservados > 0) {
            $texto .= ' ' . $conservados . ' no los toqué porque ya tenían movimientos o cambios posteriores.';
        }
        if ((int) ($reversion['contraasientos'] ?? 0) > 0) {
            $texto .= ' Anulé ' . (int) $reversion['contraasientos'] . ' ajustes de saldo con una nota opuesta porque la cuenta ya tenía movimientos posteriores.';
        }

        return $texto;
    }

    public function fallar(ImportacionDeSoporte $corrida, string $detalle = 'No pude terminar de procesar el archivo. Avisame por acá y lo revisamos.'): void
    {
        DB::transaction(function () use ($corrida, $detalle) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            if (!in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)) {
                return;
            }
            $corrida->update(['estado' => 'error', 'error' => $detalle]);
            $corrida->aviso('error', $detalle);
        });
    }

    /**
     * Corre $trabajo con el dueño como usuario de la sesión: los importadores y la cuenta corriente resuelven el
     * comercio por UserHelper::userId(). Después deja la sesión como estaba.
     */
    private function como_dueno(User $owner, callable $trabajo)
    {
        $previo = Auth::user();
        Auth::setUser($owner);
        try {
            return $trabajo();
        } catch (Throwable $error) {
            Log::warning('ImportacionDeSoporteService: falló una importación o reversión', [
                'user_id' => (int) $owner->id, 'error' => $error->getMessage(),
            ]);
            throw $error;
        } finally {
            if (!is_null($previo)) {
                Auth::setUser($previo);
            } else {
                Auth::forgetGuards();
            }
        }
    }

    private function valores($modelo, array $campos): array
    {
        $valores = [];
        foreach ($campos as $campo) {
            $valor = $modelo->getAttribute($campo);
            $valores[$campo] = is_null($valor) ? null : (is_scalar($valor) ? $valor : (string) $valor);
        }

        return $valores;
    }

    private function fila_de_cambio(ImportacionDeSoporte $corrida, string $tipo, int $modelo_id, $current_acount_id, $antes, $despues, $ahora): array
    {
        return [
            'importacion_de_soporte_id' => (int) $corrida->id,
            'tipo' => $tipo,
            'modelo' => (string) $corrida->modelo,
            'modelo_id' => $modelo_id,
            'current_acount_id' => $current_acount_id,
            'antes' => is_null($antes) ? null : json_encode($antes),
            'despues' => is_null($despues) ? null : json_encode($despues),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    private function marcar(ImportacionDeSoporteCambio $cambio, string $reversion, $motivo): void
    {
        $cambio->reversion = $reversion;
        $cambio->motivo_reversion = is_null($motivo) ? null : mb_substr((string) $motivo, 0, 190);
        $cambio->save();
    }

    private function tipo_de_movimiento(CurrentAcount $movimiento): string
    {
        if ($movimiento->detalle === 'Saldo inicial') {
            return 'saldo_inicial';
        }

        return $movimiento->status === 'nota_credito' ? 'nota_de_credito' : 'nota_de_debito';
    }

    private function archivo(ImportacionDeSoporte $corrida): string
    {
        return mb_substr((string) preg_replace('/\s+/u', ' ', (string) $corrida->nombre), 0, 110);
    }

    private function plata($monto): string
    {
        $monto = round((float) $monto, 2);
        $decimales = abs($monto - round($monto)) < 0.005 ? 0 : 2;

        return ($monto < 0 ? '-' : '') . '$' . number_format(abs($monto), $decimales, ',', '.');
    }
}
