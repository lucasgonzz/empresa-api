<?php

namespace App\Services\Importacion;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\NotaCreditoHelper;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Imports\ClientImport;
use App\Imports\ProviderImport;
use App\Jobs\AvanzarImportacionDeSoporte;
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
 * mismo dueño activo, el mismo `CONFIRMAR <código>` atado a la huella del resumen y los avisos por WhatsApp por el
 * mismo camino (`importacion_lista` con el ticket).
 *
 * El recorrido:
 *   analizar            → el analizador de IA de siempre arma el mapeo y PlanDeContactosDeSoporte lo previsto fila
 *                         por fila. Si alguna fila es ambigua → `requiere_correccion` (no se pide código).
 *   confirmacion        → espera el código del dueño. Pregunta por WhatsApp con el resumen.
 *   importar            → con el dueño y la corrida bloqueados vuelve a armar lo previsto; si cambió, pregunta de nuevo
 *                         con otro código. Si es lo mismo, importa en tandas de 100 filas, cada una en su transacción:
 *                         los importadores de siempre, el registro de TODO lo que hicieron y el control contra lo
 *                         previsto. Si una tanda no coincide, se deshace entera y la corrida queda en `error`
 *                         (`no_coincide_con_el_resumen`), con lo de las tandas anteriores revertible.
 *   terminada           → revertible.
 *   revirtiendo         → deshace tanda por tanda, de la última a la primera (cada una en su transacción).
 *   revertida.
 *
 * Al revertir: los creados se borran (si no tienen vida propia: movimientos, ventas, compras, artículos); el que se
 * conserva igual pierde el saldo que le cargó la importación (contraasiento: decisión del coordinador, 11/11/2026).
 * Los actualizados vuelven a sus valores (solo los campos que nadie cambió después). Los movimientos de saldo se
 * borran, o se anulan con un contraasiento si la cuenta ya tiene movimientos posteriores o la nota está facturada.
 *
 * 🔴 Todo por dueño (`user_id`): hay bases compartidas entre comercios.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains, sin argumentos nombrados.
 */
class ImportacionDeSoporteService
{
    /** Texto de los contraasientos de una reversión. */
    const OBSERVACION_DE_LA_REVERSION = 'Reversión de importación de Excel desde soporte.';

    /** Minutos sin novedad para que la consulta del admin vuelva a despachar una corrida en curso. */
    const MINUTOS_PARA_REANUDAR = 10;

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
        if ($corrida->estado === 'revirtiendo') {
            $this->revertir_tandas($corrida);
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

    /**
     * Lo que hace la consulta del admin con una corrida en curso que no da señales hace MINUTOS_PARA_REANUDAR:
     * la vuelve a despachar, hasta MAXIMO_DE_RELANZAMIENTOS veces; después (o si es una importación confirmada hace
     * más que un timeout completo del job) la da por cortada. Nunca entra en ciclo.
     */
    public function reanudar_si_hace_falta(ImportacionDeSoporte $corrida): void
    {
        if (!in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)
            || $corrida->updated_at->gte(Carbon::now()->subMinutes(self::MINUTOS_PARA_REANUDAR))) {
            return;
        }
        $vencida = $corrida->estado === 'importar' && !is_null($corrida->confirmada_at)
            && $corrida->confirmada_at->lt(Carbon::now()->subSeconds((new AvanzarImportacionDeSoporte(0))->timeout));
        if ((int) $corrida->relanzamientos >= ImportacionDeSoporte::MAXIMO_DE_RELANZAMIENTOS || $vencida) {
            $revirtiendo = $corrida->estado === 'revirtiendo';
            $this->fallar($corrida, $revirtiendo
                ? 'No pude terminar de deshacer la importación de «' . $this->archivo($corrida) . '». Avisame por acá y lo revisamos.'
                : 'La importación de «' . $this->archivo($corrida) . '» se cortó y no la pude terminar. Avisame por acá y lo revisamos.',
                $revirtiendo ? 'reversion_incompleta' : 'se_corto');
            return;
        }
        $corrida->relanzamientos = (int) $corrida->relanzamientos + 1;
        $corrida->save();
        AvanzarImportacionDeSoporte::dispatch((int) $corrida->id);
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
     * Deja la corrida esperando el código (nueva revisión, código nuevo y la pregunta por WhatsApp), o pidiendo que se
     * corrija el Excel si alguna fila es ambigua (sin código ni aviso: Martín se lo explica al dueño).
     */
    private function preguntar(ImportacionDeSoporte $corrida, array $plan): void
    {
        $corrida->retirar_pregunta();
        if ($plan['ambiguas'] !== []) {
            $corrida->fill([
                'estado' => 'requiere_correccion',
                'motivo' => 'filas_ambiguas',
                'codigo' => null,
                'huella' => $plan['huella'],
                'resumen' => $plan['resumen'],
                'error' => $this->texto_de_ambiguas($corrida, $plan['ambiguas']),
            ]);
            $corrida->save();
            return;
        }

        $revision = (int) $corrida->revision + 1;
        $codigo = strtoupper(substr(hash('sha256', $corrida->uuid . ':' . $revision), 0, 8));
        $corrida->fill([
            'estado' => 'confirmacion',
            'motivo' => null,
            'revision' => $revision,
            'codigo' => $codigo,
            'huella' => $plan['huella'],
            'resumen' => $plan['resumen'],
        ]);
        $corrida->save();
        $corrida->aviso('pregunta-' . $revision, $this->texto_de_confirmacion($corrida));
    }

    /**
     * Importa la corrida confirmada, tanda por tanda. La primera vez vuelve a armar lo previsto con el dueño y la
     * corrida bloqueados: si cambió desde que el dueño lo aprobó, vuelve a preguntar.
     */
    private function importar(ImportacionDeSoporte $corrida): void
    {
        $owner = User::findOrFail((int) $corrida->user_id);

        if ((int) $corrida->tandas_total === 0) {
            $seguir = $this->como_dueno($owner, function () use ($corrida, $owner) {
                return DB::transaction(function () use ($corrida, $owner) {
                    User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                    if ($corrida->estado !== 'importar' || (int) $corrida->tandas_total > 0) {
                        return (int) $corrida->tandas_total > 0;
                    }
                    $plan = PlanDeContactosDeSoporte::armar($corrida);
                    if ($plan['huella'] !== $corrida->huella || $plan['ambiguas'] !== []) {
                        $this->preguntar($corrida, $plan);
                        return false;
                    }
                    $filas = max(1, (int) $plan['ultima_fila'] - (int) $corrida->fila_inicio + 1);
                    $corrida->previsto = $plan['previsto'];
                    $corrida->tandas_total = (int) ceil($filas / ImportacionDeSoporte::FILAS_POR_TANDA);
                    $corrida->save();

                    return true;
                });
            });
            if (!$seguir) {
                return;
            }
        }

        $corrida->refresh();
        while ($corrida->estado === 'importar' && (int) $corrida->tandas_hechas < (int) $corrida->tandas_total) {
            try {
                $hecha = $this->como_dueno($owner, function () use ($corrida, $owner) {
                    return DB::transaction(function () use ($corrida, $owner) {
                        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                        $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                        if ($corrida->estado !== 'importar' || (int) $corrida->tandas_hechas >= (int) $corrida->tandas_total) {
                            return false;
                        }
                        $this->ejecutar_tanda($corrida, (int) $corrida->tandas_hechas + 1);
                        $corrida->tandas_hechas = (int) $corrida->tandas_hechas + 1;
                        $corrida->save();

                        return true;
                    });
                });
            } catch (ImportacionNoCoincideConElResumen $error) {
                Log::warning('ImportacionDeSoporteService: una tanda no coincidió con el resumen', [
                    'corrida_id' => (int) $corrida->id, 'detalle' => $error->getMessage(),
                ]);
                $corrida->refresh();
                $this->fallar($corrida, 'Frené la importación de «' . $this->archivo($corrida) . '»: el sistema iba a hacer algo distinto de lo que te mostré en el resumen'
                    . ((int) $corrida->tandas_hechas > 0 ? ', así que paré a mitad de camino. Lo que alcancé a cargar se puede deshacer.' : ', así que no cargué nada.')
                    . ' Avisame por acá y lo revisamos.', 'no_coincide_con_el_resumen');
                return;
            }
            if (!$hecha) {
                break;
            }
            $corrida->refresh();
        }

        $terminada = DB::transaction(function () use ($corrida) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            if ($corrida->estado !== 'importar' || (int) $corrida->tandas_hechas < (int) $corrida->tandas_total) {
                return false;
            }
            $corrida->fill(['estado' => 'terminada', 'terminada_at' => Carbon::now(), 'resultado' => $this->resultado($corrida)]);
            $corrida->save();

            return true;
        });
        if ($terminada) {
            $corrida->refresh();
            $corrida->aviso('resultado', $this->texto_del_resultado($corrida));
        }
    }

    /**
     * Una tanda, adentro de su transacción: el importador de siempre sobre sus filas, el registro de todo lo que hizo
     * y el control contra lo previsto (que lanza ImportacionNoCoincideConElResumen y deshace la tanda).
     */
    private function ejecutar_tanda(ImportacionDeSoporte $corrida, int $tanda): void
    {
        $es_cliente = $corrida->modelo === 'client';
        $clase = $es_cliente ? Client::class : Provider::class;
        $columna = $es_cliente ? 'client_id' : 'provider_id';
        $campos = self::CAMPOS[$corrida->modelo];
        $owner_id = (int) $corrida->user_id;
        $desde = (int) $corrida->fila_inicio + ($tanda - 1) * ImportacionDeSoporte::FILAS_POR_TANDA;
        $hasta = $desde + ImportacionDeSoporte::FILAS_POR_TANDA - 1;
        $previstos = array_values(array_filter((array) $corrida->previsto, function ($fila) use ($desde, $hasta) {
            return $fila['fila'] >= $desde && $fila['fila'] <= $hasta;
        }));

        $existentes = [];
        foreach ($previstos as $fila) {
            if (!is_null($fila['id'])) {
                $existentes[] = (int) $fila['id'];
            }
        }
        $antes = [];
        foreach (array_chunk($existentes, 500) as $lote) {
            foreach ($clase::where('user_id', $owner_id)->whereIn('id', $lote)->get() as $modelo) {
                $antes[(int) $modelo->id] = $this->valores($modelo, $campos);
            }
        }
        $ultimo_modelo = (int) $clase::withTrashed()->max('id');
        $ultimo_movimiento = (int) CurrentAcount::max('id');
        $inicio = Carbon::now()->startOfSecond();
        // Los que alguien tocó en este mismo segundo, antes de importar: no cuentan como «tocados por la tanda».
        $recientes = $clase::where('user_id', $owner_id)->where('updated_at', '>=', $inicio)->pluck('updated_at', 'id')
            ->map(function ($fecha) {
                return (string) $fecha;
            })->all();

        $columnas = $corrida->columnas ?? [];
        $importador = $es_cliente
            ? new ClientImport($columnas, true, $desde, $hasta, (int) $corrida->hoja, $corrida->hoja_nombre, false)
            : new ProviderImport($columnas, true, $desde, $hasta, null, (int) $corrida->hoja, $corrida->hoja_nombre, false);
        Excel::import($importador, storage_path('app/' . $corrida->archivo));

        $creados = $clase::where('user_id', $owner_id)->where('id', '>', $ultimo_modelo)->orderBy('id')->get();
        $ids_creados = $creados->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $this->sin_saldos_en_cero($columna, $ids_creados, $ultimo_movimiento);

        $actualizados = [];
        foreach (array_chunk(array_keys($antes), 500) as $lote) {
            foreach ($clase::where('user_id', $owner_id)->whereIn('id', $lote)->get() as $modelo) {
                $despues = $this->valores($modelo, $campos);
                $cambiaron = [];
                foreach ($despues as $campo => $valor) {
                    $previo = $antes[(int) $modelo->id][$campo];
                    if ((string) $valor !== (string) $previo || is_null($valor) !== is_null($previo)) {
                        $cambiaron[] = $campo;
                    }
                }
                if ($cambiaron !== []) {
                    $actualizados[(int) $modelo->id] = [
                        array_intersect_key($antes[(int) $modelo->id], array_flip($cambiaron)),
                        array_intersect_key($despues, array_flip($cambiaron)),
                    ];
                }
            }
        }

        // 🔴 TODO movimiento nuevo de cualquier contacto del dueño, no solo de los previstos: lo que la importación
        // haya generado tiene que quedar registrado (y si no estaba previsto, el control de abajo frena la tanda).
        $movimientos = CurrentAcount::where('id', '>', $ultimo_movimiento)
            ->whereIn($columna, $clase::withTrashed()->where('user_id', $owner_id)->select('id'))
            ->orderBy('id')->get();

        $this->controlar_tanda($corrida, $clase, $previstos, $creados, $actualizados, $movimientos, $columna, $inicio, $recientes);

        $ahora = Carbon::now();
        $filas = [];
        foreach ($ids_creados as $id) {
            $filas[] = $this->fila_de_cambio($corrida, $tanda, ImportacionDeSoporteCambio::TIPO_CREADO, $id, null, null, null, $ahora);
        }
        foreach ($actualizados as $id => $par) {
            $filas[] = $this->fila_de_cambio($corrida, $tanda, ImportacionDeSoporteCambio::TIPO_ACTUALIZADO, $id, null, $par[0], $par[1], $ahora);
        }
        foreach ($movimientos as $movimiento) {
            list($tipo, $monto) = $this->tipo_y_monto($movimiento);
            $filas[] = $this->fila_de_cambio($corrida, $tanda, ImportacionDeSoporteCambio::TIPO_MOVIMIENTO, (int) $movimiento->{$columna},
                (int) $movimiento->id, null, ['tipo' => $tipo, 'monto' => $monto, 'credit_account_id' => (int) $movimiento->credit_account_id], $ahora);
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('importacion_de_soporte_cambios')->insert($lote);
        }
    }

    /**
     * ClientImport le carga a todo cliente nuevo con la columna de saldo un «Saldo inicial», aunque sea de $0. Ese
     * movimiento vacío no se deja: el resumen no lo cuenta y la cuenta queda igual sin él.
     */
    private function sin_saldos_en_cero(string $columna, array $ids_creados, int $ultimo_movimiento): void
    {
        if ($ids_creados === []) {
            return;
        }
        CurrentAcount::where('id', '>', $ultimo_movimiento)->whereIn($columna, $ids_creados)->where('detalle', 'Saldo inicial')
            ->where(function ($consulta) {
                $consulta->whereNull('debe')->orWhere('debe', '<', 0.005);
            })->where(function ($consulta) {
                $consulta->whereNull('haber')->orWhere('haber', '<', 0.005);
            })->delete();
    }

    /**
     * Lo que pasó contra lo que el resumen previó para estas filas. Cualquier diferencia lanza la excepción que
     * deshace la tanda: quién se creó, quién se actualizó, que no se haya tocado a nadie más, y cada movimiento de
     * saldo de cada contacto (tipo y monto, con signo para el saldo inicial).
     */
    private function controlar_tanda(ImportacionDeSoporte $corrida, string $clase, array $previstos, $creados, array $actualizados, $movimientos, string $columna, Carbon $inicio, array $recientes): void
    {
        $esperados = [];
        $existentes = [];
        $seguros = [];
        $esperado_por_contacto = [];
        foreach ($previstos as $fila) {
            $clave = $fila['nuevo'] ? 'nuevo:' . $fila['clave'] : 'id:' . $fila['id'];
            if ($fila['nuevo']) {
                $esperados[] = $fila['clave'];
            } else {
                $existentes[(int) $fila['id']] = true;
                if ($fila['cambia_seguro']) {
                    $seguros[] = (int) $fila['id'];
                }
            }
            foreach ($fila['movimientos'] as $movimiento) {
                $esperado_por_contacto[$clave][] = $movimiento[0] . ':' . number_format((float) $movimiento[1], 2, '.', '');
            }
        }

        $hechos = [];
        $clave_de_creado = [];
        foreach ($creados as $modelo) {
            $clave = PlanDeContactosDeSoporte::nombre_comparable($modelo->name);
            $hechos[] = $clave;
            $clave_de_creado[(int) $modelo->id] = 'nuevo:' . $clave;
        }
        sort($esperados);
        sort($hechos);
        if ($esperados !== $hechos) {
            throw new ImportacionNoCoincideConElResumen('Se crearon ' . count($hechos) . ' y se esperaban ' . count($esperados) . '.');
        }
        foreach (array_keys($actualizados) as $id) {
            if (!isset($existentes[$id])) {
                throw new ImportacionNoCoincideConElResumen('Se actualizó el contacto ' . $id . ', que no estaba en el resumen.');
            }
        }
        foreach ($seguros as $id) {
            if (!isset($actualizados[$id])) {
                throw new ImportacionNoCoincideConElResumen('No se actualizó el contacto ' . $id . ', que el resumen daba por actualizado.');
            }
        }

        $de_la_corrida = array_keys($existentes);
        foreach ((array) $corrida->previsto as $fila) {
            if (!is_null($fila['id'])) {
                $de_la_corrida[] = (int) $fila['id'];
            }
        }
        $de_la_corrida = array_merge($de_la_corrida, array_keys($clave_de_creado),
            $corrida->cambios()->where('tipo', ImportacionDeSoporteCambio::TIPO_CREADO)->pluck('modelo_id')->all());
        $otros = $clase::where('user_id', (int) $corrida->user_id)->where('updated_at', '>=', $inicio)
            ->whereNotIn('id', $de_la_corrida === [] ? [0] : $de_la_corrida)->get(['id', 'updated_at'])
            ->filter(function ($modelo) use ($recientes) {
                return !isset($recientes[(int) $modelo->id]) || $recientes[(int) $modelo->id] !== (string) $modelo->updated_at;
            });
        if ($otros->isNotEmpty()) {
            throw new ImportacionNoCoincideConElResumen('Se modificó un contacto que no estaba en el resumen.');
        }

        $hecho_por_contacto = [];
        foreach ($movimientos as $movimiento) {
            $id = (int) $movimiento->{$columna};
            $clave = isset($clave_de_creado[$id]) ? $clave_de_creado[$id] : 'id:' . $id;
            list($tipo, $monto) = $this->tipo_y_monto($movimiento);
            $hecho_por_contacto[$clave][] = $tipo . ':' . number_format($monto, 2, '.', '');
        }
        $esperado_por_contacto = $this->ordenado($esperado_por_contacto);
        $hecho_por_contacto = $this->ordenado($hecho_por_contacto);
        if ($esperado_por_contacto !== $hecho_por_contacto) {
            throw new ImportacionNoCoincideConElResumen('Los movimientos de saldo no son los del resumen: se esperaban '
                . json_encode($esperado_por_contacto) . ' y se hicieron ' . json_encode($hecho_por_contacto) . '.');
        }
    }

    /** Los movimientos por contacto, ordenados para comparar (contactos por clave y movimientos entre sí). */
    private function ordenado(array $por_contacto): array
    {
        foreach ($por_contacto as $clave => $items) {
            sort($items);
            $por_contacto[$clave] = $items;
        }
        ksort($por_contacto);

        return $por_contacto;
    }

    /**
     * El tipo y el monto de un movimiento como los cuenta el resumen: el saldo inicial con signo (negativo = a
     * favor), las notas por su monto.
     */
    private function tipo_y_monto(CurrentAcount $movimiento): array
    {
        if ($movimiento->detalle === 'Saldo inicial') {
            return ['saldo_inicial', round(!is_null($movimiento->debe) ? (float) $movimiento->debe : -1 * (float) $movimiento->haber, 2)];
        }
        if ($movimiento->status === 'nota_credito') {
            return ['nota_de_credito', round((float) $movimiento->haber, 2)];
        }
        if ($movimiento->detalle === 'Nota de debito') {
            return ['nota_de_debito', round((float) $movimiento->debe, 2)];
        }

        return ['otro', round((float) $movimiento->debe - (float) $movimiento->haber, 2)];
    }

    /** El resultado de la corrida, contado desde su registro. */
    private function resultado(ImportacionDeSoporte $corrida): array
    {
        $cambios = $corrida->cambios()->get(['tipo', 'despues']);
        $por_tipo = ['saldo_inicial' => 0, 'nota_de_credito' => 0, 'nota_de_debito' => 0];
        foreach ($cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_MOVIMIENTO) as $cambio) {
            $tipo = isset($cambio->despues['tipo']) ? $cambio->despues['tipo'] : 'otro';
            $por_tipo[$tipo] = (isset($por_tipo[$tipo]) ? $por_tipo[$tipo] : 0) + 1;
        }

        return [
            'creados' => $cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_CREADO)->count(),
            'actualizados' => $cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_ACTUALIZADO)->count(),
            'movimientos' => $por_tipo,
            'saldos_no_cargados' => (int) (((array) $corrida->resumen)['saldos_no_cargados'] ?? 0),
        ];
    }

    /* ------------------------------------------------------------------------------------------------------
     * Confirmar, cancelar y revertir (los llama el controlador)
     * ---------------------------------------------------------------------------------------------------- */

    /**
     * El dueño mandó el código. Devuelve [status, motivo|null, corrida]: 202 confirmada, 200 cancelada (o el mismo
     * pedido repetido), 409 si la corrida no espera código o el código no es el de la revisión vigente.
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
     * Pide deshacer la corrida: queda `revirtiendo` y el job la deshace tanda por tanda. Devuelve [status, motivo,
     * corrida]: 202 (en curso), 200 (ya revertida), 409 `no_revertible`.
     */
    public function revertir(ImportacionDeSoporte $corrida): array
    {
        return DB::transaction(function () use ($corrida) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            if ($corrida->estado === 'revertida') {
                return [200, null, $corrida];
            }
            if ($corrida->estado === 'revirtiendo') {
                return [202, null, $corrida];
            }
            if (!$corrida->revertible()) {
                return [409, 'no_revertible', $corrida];
            }
            $corrida->fill(['estado' => 'revirtiendo', 'motivo' => null, 'relanzamientos' => 0]);
            $corrida->save();

            return [202, null, $corrida];
        });
    }

    private function revertir_tandas(ImportacionDeSoporte $corrida): void
    {
        $owner = User::findOrFail((int) $corrida->user_id);
        while ($corrida->estado === 'revirtiendo' && (int) $corrida->tandas_revertidas < (int) $corrida->tandas_hechas) {
            try {
                $hecha = $this->como_dueno($owner, function () use ($corrida, $owner) {
                    return DB::transaction(function () use ($corrida, $owner) {
                        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                        $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
                        if ($corrida->estado !== 'revirtiendo' || (int) $corrida->tandas_revertidas >= (int) $corrida->tandas_hechas) {
                            return false;
                        }
                        $cuenta = $this->deshacer_tanda($corrida, (int) $corrida->tandas_hechas - (int) $corrida->tandas_revertidas);
                        $resultado = (array) $corrida->resultado;
                        $acumulado = isset($resultado['reversion']) ? (array) $resultado['reversion'] : [];
                        foreach ($cuenta as $clave => $valor) {
                            $acumulado[$clave] = (isset($acumulado[$clave]) ? (int) $acumulado[$clave] : 0) + (int) $valor;
                        }
                        $resultado['reversion'] = $acumulado;
                        $corrida->resultado = $resultado;
                        $corrida->tandas_revertidas = (int) $corrida->tandas_revertidas + 1;
                        $corrida->save();

                        return true;
                    });
                });
            } catch (Throwable $error) {
                $corrida->refresh();
                $this->fallar($corrida, 'No pude terminar de deshacer la importación de «' . $this->archivo($corrida)
                    . '». Avisame por acá y lo revisamos.', 'reversion_incompleta');
                return;
            }
            if (!$hecha) {
                break;
            }
            $corrida->refresh();
        }

        $revertida = DB::transaction(function () use ($corrida) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            if ($corrida->estado !== 'revirtiendo' || (int) $corrida->tandas_revertidas < (int) $corrida->tandas_hechas) {
                return false;
            }
            $corrida->fill(['estado' => 'revertida', 'revertida_at' => Carbon::now()]);
            $corrida->save();

            return true;
        });
        if ($revertida) {
            $corrida->refresh();
            $corrida->aviso('reversion', $this->texto_de_la_reversion($corrida));
        }
    }

    private function deshacer_tanda(ImportacionDeSoporte $corrida, int $tanda): array
    {
        $es_cliente = $corrida->modelo === 'client';
        $modelo_nombre = $es_cliente ? 'client' : 'provider';
        $clase = $es_cliente ? Client::class : Provider::class;
        $owner_id = (int) $corrida->user_id;
        $cambios = $corrida->cambios()->where('tanda', $tanda)->orderBy('id')->get();
        $movimientos = $cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_MOVIMIENTO);
        $de_la_corrida = $corrida->cambios()->where('tipo', ImportacionDeSoporteCambio::TIPO_MOVIMIENTO)
            ->pluck('current_acount_id')->map(function ($id) {
                return (int) $id;
            })->all();
        $cuentas = [];
        $cuenta = ['creados_borrados' => 0, 'creados_conservados' => 0, 'saldos_anulados_de_conservados' => 0,
            'actualizados_restaurados' => 0, 'actualizados_conservados' => 0, 'movimientos_borrados' => 0, 'contraasientos' => 0];
        $conservados = [];

        foreach ($cambios->where('tipo', ImportacionDeSoporteCambio::TIPO_CREADO) as $cambio) {
            $modelo = $clase::where('user_id', $owner_id)->find($cambio->modelo_id);
            if (is_null($modelo)) {
                $this->marcar($cambio, 'revertido', 'ya no existía');
                continue;
            }
            $motivo = $this->motivo_para_conservar($modelo, $es_cliente, $de_la_corrida);
            if (!is_null($motivo)) {
                $conservados[(int) $modelo->id] = true;
                $cuenta['creados_conservados']++;
                $this->marcar($cambio, 'conservado', $motivo);
                continue;
            }
            foreach ($movimientos->where('modelo_id', $cambio->modelo_id) as $del_creado) {
                $movimiento = CurrentAcount::find($del_creado->current_acount_id);
                if (!is_null($movimiento)) {
                    // La cuenta queda vacía y sin dueño visible: no hay saldo que recalcular.
                    $movimiento->delete();
                    $cuenta['movimientos_borrados']++;
                }
                $this->marcar($del_creado, 'revertido', 'se borró con el ' . ($es_cliente ? 'cliente' : 'proveedor'));
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
                    $restaurar[$campo] = isset($cambio->antes[$campo]) ? $cambio->antes[$campo] : null;
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
            if (!is_null($cambio->reversion)) {
                continue;
            }
            $movimiento = CurrentAcount::find($cambio->current_acount_id);
            if (is_null($movimiento)) {
                $this->marcar($cambio, 'revertido', 'ya no existía');
                continue;
            }
            $cuentas[(int) $movimiento->credit_account_id] = true;
            $conservado = isset($conservados[(int) $cambio->modelo_id]);
            $posteriores = CurrentAcount::where('credit_account_id', $movimiento->credit_account_id)
                ->where('id', '>', $movimiento->id)->whereNotIn('id', $de_la_corrida)->exists();
            $facturada = $movimiento->status === 'nota_credito'
                && !is_null(NotaCreditoHelper::motivo_por_el_que_no_se_puede_eliminar($movimiento));
            if (!$posteriores && !$facturada && !$conservado) {
                if ($movimiento->status === 'nota_credito') {
                    AfipTicket::where('nota_credito_id', $movimiento->id)->delete();
                }
                $movimiento->delete();
                $cuenta['movimientos_borrados']++;
                $this->marcar($cambio, 'revertido', null);
                continue;
            }
            // Decisión del coordinador (11/11/2026): un creado que se conserva igual pierde el saldo importado.
            $this->contraasiento($movimiento, $modelo_nombre, (int) $cambio->modelo_id);
            $cuenta['contraasientos']++;
            if ($conservado) {
                $cuenta['saldos_anulados_de_conservados']++;
            }
            $this->marcar($cambio, 'contraasiento', $conservado ? 'el contacto se conservó: se anuló el saldo importado'
                : ($facturada ? 'la nota está facturada' : 'la cuenta tiene movimientos posteriores'));
        }

        foreach (array_keys($cuentas) as $credit_account_id) {
            CurrentAcountHelper::checkSaldos($credit_account_id);
            CurrentAcountHelper::checkPagos($credit_account_id);
            CurrentAcountHelper::update_credit_account_saldo($credit_account_id);
        }

        return $cuenta;
    }

    /**
     * Por qué un creado por la importación no se borra al revertir (o null si se puede borrar): ya tiene vida
     * propia en el sistema.
     */
    private function motivo_para_conservar($modelo, bool $es_cliente, array $ids_de_movimientos)
    {
        $columna = $es_cliente ? 'client_id' : 'provider_id';
        if (CurrentAcount::where($columna, $modelo->id)->whereNotIn('id', $ids_de_movimientos === [] ? [0] : $ids_de_movimientos)->exists()) {
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
            'motivo' => $corrida->motivo,
            'archivo' => (string) $corrida->nombre,
            'support_ticket_id' => (int) $corrida->support_ticket_id,
            'revision' => (int) $corrida->revision,
            'resumen' => $corrida->resumen,
            'codigo_requerido' => $esperando,
            'codigo_confirmacion' => $esperando ? (string) $corrida->codigo : null,
            'texto_confirmacion' => $esperando ? (new self())->texto_de_confirmacion($corrida) : null,
            'resultado' => $corrida->resultado,
            'error' => $corrida->error,
            'revertible' => $corrida->revertible(),
            'tandas' => ['total' => (int) $corrida->tandas_total, 'hechas' => (int) $corrida->tandas_hechas,
                'revertidas' => (int) $corrida->tandas_revertidas],
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
        $sin_cambios = (int) ($resumen['sin_cambios'] ?? 0);
        $texto = 'Preparé «' . $this->archivo($corrida) . '» para importar ' . $plural . ': se crean ' . $crear
            . ' y se actualizan ' . $actualizar . ' que ya estaban cargados (mismo número o mismo nombre).';
        if ($sin_cambios > 0) {
            $texto .= ' Otros ' . $sin_cambios . ' ya están igual que en el Excel.';
        }
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

    /** Lo que Martín le explica al dueño cuando el Excel tiene filas que no se pueden importar sin adivinar. */
    private function texto_de_ambiguas(ImportacionDeSoporte $corrida, array $ambiguas): string
    {
        $motivos = [
            'numero_no_valido' => 'el número no es un número',
            'nombre_repetido_en_el_archivo' => 'el nombre está repetido en el archivo',
            'numero_repetido_en_el_archivo' => 'el número está repetido en el archivo',
            'varios_contactos_coinciden' => 'coincide con más de uno de los ya cargados',
            'contacto_en_varias_filas' => 'el mismo ' . ($corrida->modelo === 'provider' ? 'proveedor' : 'cliente') . ' aparece en más de una fila',
            'renombra_con_el_nombre_de_otro' => 'le cambiaría el nombre por el de otro ya cargado',
            'ya_existe_con_otro_numero' => 'ya hay uno cargado con ese nombre y otro número',
            'busca_un_nombre_que_otra_fila_cambia' => 'otra fila le cambia el nombre',
        ];
        $lineas = [];
        foreach (array_slice($ambiguas, 0, 10) as $fila) {
            $lineas[] = 'fila ' . $fila['fila'] . ' (' . mb_substr($fila['nombre'], 0, 60) . '): '
                . (isset($motivos[$fila['motivo']]) ? $motivos[$fila['motivo']] : $fila['motivo']);
        }

        return 'No importé «' . $this->archivo($corrida) . '» porque ' . count($ambiguas)
            . (count($ambiguas) === 1 ? ' fila no se puede importar' : ' filas no se pueden importar') . ' sin adivinar: '
            . implode('; ', $lineas) . (count($ambiguas) > 10 ? '; y ' . (count($ambiguas) - 10) . ' más' : '') . '.';
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
        $creados_conservados = (int) ($reversion['creados_conservados'] ?? 0);
        if ($creados_conservados > 0) {
            $texto .= ' ' . $creados_conservados . ($creados_conservados === 1 ? ' nuevo no lo borré porque ya tenía' : ' nuevos no los borré porque ya tenían')
                . ' movimientos propios' . ((int) ($reversion['saldos_anulados_de_conservados'] ?? 0) > 0
                    ? ', pero les anulé el saldo que había cargado la importación' : '') . '.';
        }
        $actualizados_conservados = (int) ($reversion['actualizados_conservados'] ?? 0);
        if ($actualizados_conservados > 0) {
            $texto .= ' A ' . $actualizados_conservados . ' no les volví los datos que se cambiaron a mano después.';
        }
        $contraasientos = (int) ($reversion['contraasientos'] ?? 0) - (int) ($reversion['saldos_anulados_de_conservados'] ?? 0);
        if ($contraasientos > 0) {
            $texto .= ' Anulé ' . $contraasientos . ' ajustes de saldo con una nota opuesta porque la cuenta ya tenía movimientos posteriores.';
        }

        return $texto;
    }

    public function fallar(ImportacionDeSoporte $corrida, string $detalle = 'No pude terminar de procesar el archivo. Avisame por acá y lo revisamos.', $motivo = null): void
    {
        DB::transaction(function () use ($corrida, $detalle, $motivo) {
            $corrida = ImportacionDeSoporte::whereKey($corrida->id)->lockForUpdate()->firstOrFail();
            if (!in_array($corrida->estado, ImportacionDeSoporte::EN_CURSO, true)) {
                return;
            }
            $corrida->update(['estado' => 'error', 'motivo' => $motivo, 'error' => $detalle]);
            $corrida->aviso('error-' . ($motivo ?: 'general') . '-' . (int) $corrida->tandas_revertidas, $detalle);
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
            Log::warning('ImportacionDeSoporteService: falló una tanda de importación o reversión', [
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

    private function fila_de_cambio(ImportacionDeSoporte $corrida, int $tanda, string $tipo, int $modelo_id, $current_acount_id, $antes, $despues, $ahora): array
    {
        return [
            'importacion_de_soporte_id' => (int) $corrida->id,
            'tanda' => $tanda,
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
