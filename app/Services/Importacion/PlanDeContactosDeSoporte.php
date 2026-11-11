<?php

namespace App\Services\Importacion;

use App\Http\Controllers\CommonLaravel\Helpers\ImportHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\LocalImportHelper;
use App\Imports\FilasDeUnaHoja;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\Provider;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * El resumen de una importación de clientes o proveedores ANTES de hacerla (misión importaciones-de-martin,
 * 11/11/2026): cuántos se crean, cuántos ya existen (y se actualizan si cambian sus datos) y qué saldos cambian,
 * con el movimiento de cuenta corriente que va a generar cada uno.
 *
 * 🔴 Reproduce las reglas de los importadores de siempre, sin tocarlos:
 *  - Emparejamiento (ClientImport / ProviderImport::collection): dentro del dueño, por `num` si la fila trae
 *    número y si no por `name`, con la colación de la base (por eso se consulta la base fila por fila y no se
 *    compara en PHP). Una fila repetida en el archivo cae sobre el que creó la fila anterior.
 *  - Saldo de un CLIENTE (LocalImportHelper::procesarSaldoImportacion): al nuevo se le carga un «Saldo inicial»;
 *    al existente se le ajusta con una nota de crédito (si el Excel dice menos) o de débito (si dice más) por la
 *    diferencia contra el saldo vivo de su cuenta en pesos.
 *  - Saldo de un PROVEEDOR (LocalImportHelper::setSaldoInicial): solo un saldo inicial en una cuenta vacía; si
 *    la cuenta ya tiene movimientos y otro saldo, NO se carga (`no_se_carga`), y un saldo 0 no carga nada.
 *  - Un saldo que no se puede leer no toca nada y se avisa (`saldos_ilegibles`).
 *
 * Solo lee. PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class PlanDeContactosDeSoporte
{
    /** Cuántos nombres de ejemplo y cuántos saldos se muestran en el resumen (el total va aparte). */
    const MAXIMO_DE_EJEMPLOS = 10;
    const MAXIMO_DE_SALDOS = 60;

    /** Propiedades que entiende cada importador (las de AiClientAnalyzer / AiProviderAnalyzer). */
    const PROPIEDADES = [
        'client' => ['nombre', 'telefono', 'email', 'direccion', 'localidad', 'provincia', 'cuit', 'cuil', 'dni',
            'razon_social', 'numero', 'vendedor', 'sucursal', 'condicion_frente_al_iva', 'tipo_de_precio',
            'saldo_actual', 'descripcion'],
        'provider' => ['nombre', 'telefono', 'email', 'direccion', 'localidad', 'cuit', 'razon_social', 'numero',
            'observaciones', 'condicion_frente_al_iva', 'saldo_actual'],
    ];

    /**
     * El `columns` que esperan ClientImport / ProviderImport (propiedad => índice de columna, 0-based) a partir
     * del `column_mapping` del analizador. Mismo armado que el panel de implementación del admin
     * (ImplementationImportService::build_columns_payload): toda propiedad que la IA reconoció, con su índice.
     *
     * @param  string $modelo
     * @param  array  $column_mapping
     * @return array
     */
    public static function columnas_desde_mapeo(string $modelo, array $column_mapping): array
    {
        $validas = self::PROPIEDADES[$modelo];
        $columnas = [];
        foreach ($column_mapping as $posicion => $item) {
            if (!is_array($item) || !isset($item['system_property']) || !is_string($item['system_property'])) {
                continue;
            }
            $propiedad = $item['system_property'];
            if (!in_array($propiedad, $validas, true) || isset($columnas[$propiedad])) {
                continue;
            }
            $columnas[$propiedad] = isset($item['excel_column_index']) && is_numeric($item['excel_column_index'])
                ? (int) $item['excel_column_index'] : (int) $posicion;
        }
        if (!isset($columnas['nombre']) && isset($columnas['descripcion'])) {
            $columnas['nombre'] = $columnas['descripcion'];
            unset($columnas['descripcion']);
        }

        return $columnas;
    }

    /**
     * Arma el resumen de la corrida contra los datos de AHORA.
     *
     * @param  \App\Models\ImportacionDeSoporte $corrida
     * @return array ['resumen' => array, 'existentes' => int[] (ids que la importación va a tocar), 'huella' => string]
     */
    public static function armar(ImportacionDeSoporte $corrida): array
    {
        $columnas = $corrida->columnas ?? [];
        if (!isset($columnas['nombre'])) {
            throw new RuntimeException('No encontré en el archivo una columna con los nombres.');
        }

        $ruta = storage_path('app/' . $corrida->archivo);
        $lector = new FilasDeUnaHoja($corrida->hoja, $corrida->hoja_nombre);
        Excel::import($lector, $ruta);

        $es_cliente = $corrida->modelo === 'client';
        $clase = $es_cliente ? Client::class : Provider::class;
        $owner_id = (int) $corrida->user_id;
        $con_saldo = isset($columnas['saldo_actual']);

        $estados = [];   // clave de la fila => índice en $contactos
        $contactos = []; // [{id|null, nombre, existe, saldo|null, tiene_movimientos|null}]
        $crear = 0;
        $existentes = [];
        $ejemplos_crear = [];
        $ejemplos_actualizar = [];
        $saldos = [];
        $ilegibles = [];
        $filas = 0;
        $sin_nombre = 0;

        $num_row = 1;
        foreach ($lector->filas as $row) {
            if ($num_row < (int) $corrida->fila_inicio) {
                $num_row++;
                continue;
            }
            $nombre = ImportHelper::getColumnValue($row, 'nombre', $columnas);
            if (is_null($nombre)) {
                if (self::fila_con_datos($row)) {
                    $sin_nombre++;
                }
                $num_row++;
                continue;
            }
            $filas++;
            $numero = ImportHelper::getColumnValue($row, 'numero', $columnas);
            $clave = is_null($numero) ? 'name:' . mb_strtolower(trim($nombre)) : 'num:' . mb_strtolower(trim($numero));

            if (isset($estados[$clave])) {
                $indice = $estados[$clave];
            } else {
                $consulta = $clase::where('user_id', $owner_id);
                $modelo = is_null($numero) ? $consulta->where('name', $nombre)->first() : $consulta->where('num', $numero)->first();
                if (!is_null($modelo) && isset($existentes[(int) $modelo->id])) {
                    $indice = $existentes[(int) $modelo->id];
                } elseif (!is_null($modelo)) {
                    $contactos[] = ['id' => (int) $modelo->id, 'nombre' => (string) $modelo->name, 'existe' => true,
                        'saldo' => null, 'tiene_movimientos' => null];
                    $indice = count($contactos) - 1;
                    $existentes[(int) $modelo->id] = $indice;
                    if (count($ejemplos_actualizar) < self::MAXIMO_DE_EJEMPLOS) {
                        $ejemplos_actualizar[] = (string) $modelo->name;
                    }
                } else {
                    $contactos[] = ['id' => null, 'nombre' => (string) $nombre, 'existe' => false,
                        'saldo' => 0.0, 'tiene_movimientos' => false];
                    $indice = count($contactos) - 1;
                    $crear++;
                    if (count($ejemplos_crear) < self::MAXIMO_DE_EJEMPLOS) {
                        $ejemplos_crear[] = (string) $nombre;
                    }
                    // El que se crea queda con el número de la fila (o uno nuevo) y con su nombre: una fila
                    // posterior sin número que lo nombre cae sobre él.
                    $estados['name:' . mb_strtolower(trim($nombre))] = $indice;
                }
                $estados[$clave] = $indice;
            }

            if ($con_saldo) {
                $saldo = self::saldo_de_la_fila($row, $columnas, $es_cliente, $contactos[$indice], $num_row);
                if (isset($saldo['ilegible'])) {
                    $ilegibles[] = $saldo['ilegible'];
                } elseif (!is_null($saldo)) {
                    $saldos[] = $saldo;
                }
            }
            // Después de su primera fila, el que se crea ya existe: la siguiente fila suya lo actualiza (y, si es
            // un cliente, le ajusta el saldo en vez de cargarle otro saldo inicial).
            $contactos[$indice]['existe'] = true;
            $num_row++;
        }

        $resumen = [
            'modelo' => $corrida->modelo,
            'filas' => $filas,
            'crear' => $crear,
            'actualizar' => count($existentes),
            'ejemplos_crear' => $ejemplos_crear,
            'ejemplos_actualizar' => $ejemplos_actualizar,
            'campos' => array_keys($columnas),
            'saldos_total' => count($saldos),
            'saldos' => array_slice($saldos, 0, self::MAXIMO_DE_SALDOS),
            'saldos_ilegibles' => array_slice($ilegibles, 0, self::MAXIMO_DE_SALDOS),
            'filas_sin_nombre' => $sin_nombre,
        ];

        $huella = hash('sha256', json_encode([
            $corrida->modelo, $columnas, (int) $corrida->hoja, $corrida->hoja_nombre, (int) $corrida->fila_inicio,
            hash_file('sha256', $ruta), $resumen, $saldos, array_keys($existentes),
        ]));

        return ['resumen' => $resumen, 'existentes' => array_keys($existentes), 'huella' => $huella];
    }

    /**
     * Lo que hace el saldo de una fila, con el estado que fueron dejando las filas anteriores del mismo contacto.
     *
     * @param  mixed $row
     * @param  array $columnas
     * @param  bool  $es_cliente
     * @param  array $contacto  (por referencia) se actualiza con el saldo resultante
     * @param  int   $fila
     * @return array|null  null = no cambia nada; ['ilegible' => [...]] = no se pudo leer
     */
    private static function saldo_de_la_fila($row, array $columnas, bool $es_cliente, array &$contacto, int $fila)
    {
        $lectura = LocalImportHelper::leerSaldoDeLaFila($row, $columnas);
        if ($lectura['estado'] === 'vacio') {
            return null;
        }
        if ($lectura['estado'] === 'ilegible') {
            return ['ilegible' => ['fila' => $fila, 'nombre' => $contacto['nombre'], 'texto' => (string) $lectura['texto']]];
        }

        $valor = round((float) $lectura['valor'], 2);
        self::cargar_saldo_actual($contacto, $es_cliente);
        $actual = round((float) $contacto['saldo'], 2);
        $linea = ['fila' => $fila, 'id' => $contacto['id'], 'nombre' => $contacto['nombre'], 'nuevo' => !$contacto['existe']];

        if ($es_cliente) {
            if (!$contacto['existe'] && !$contacto['tiene_movimientos']) {
                $contacto['tiene_movimientos'] = true;
                $contacto['saldo'] = $valor;
                return abs($valor) < 0.01 ? null
                    : $linea + ['accion' => 'saldo_inicial', 'saldo_actual' => 0.0, 'saldo_resultante' => $valor, 'monto' => abs($valor)];
            }
            $diferencia = round($valor - $actual, 2);
            if (abs($diferencia) < 0.009) {
                return null;
            }
            $contacto['saldo'] = $valor;
            $contacto['tiene_movimientos'] = true;

            return $linea + ['accion' => $diferencia < 0 ? 'nota_de_credito' : 'nota_de_debito',
                'saldo_actual' => $actual, 'saldo_resultante' => $valor, 'monto' => abs($diferencia)];
        }

        if (abs($valor) < 0.005) {
            return null;
        }
        if (!$contacto['tiene_movimientos']) {
            $contacto['tiene_movimientos'] = true;
            $contacto['saldo'] = $valor;
            return $linea + ['accion' => 'saldo_inicial', 'saldo_actual' => $actual, 'saldo_resultante' => $valor, 'monto' => abs($valor)];
        }
        if (abs($valor - $actual) < 0.01) {
            return null;
        }

        return $linea + ['accion' => 'no_se_carga', 'saldo_actual' => $actual, 'saldo_resultante' => $actual, 'saldo_excel' => $valor, 'monto' => 0.0];
    }

    /**
     * El saldo vivo de la cuenta en pesos de un contacto existente (una sola vez por contacto).
     *
     * Cliente: el que usa ajustarSaldoPorImportacion() (el último movimiento). Proveedor: el `saldo` de la cuenta,
     * que es contra lo que compara setSaldoInicial(), y si la cuenta tiene movimientos.
     *
     * @param  array $contacto
     * @param  bool  $es_cliente
     * @return void
     */
    private static function cargar_saldo_actual(array &$contacto, bool $es_cliente): void
    {
        if (!is_null($contacto['saldo']) || is_null($contacto['id'])) {
            return;
        }
        $cuenta = LocalImportHelper::get_credit_account_pesos($es_cliente ? 'client' : 'provider', $contacto['id']);
        if (is_null($cuenta)) {
            $contacto['saldo'] = 0.0;
            $contacto['tiene_movimientos'] = false;
            return;
        }
        $contacto['tiene_movimientos'] = CurrentAcount::where('credit_account_id', $cuenta->id)->exists();
        $contacto['saldo'] = $es_cliente ? (float) CurrentAcountHelper::getSaldo($cuenta->id) : (float) $cuenta->saldo;
    }

    private static function fila_con_datos($row): bool
    {
        foreach ($row as $celda) {
            if (!is_null($celda) && trim((string) $celda) !== '') {
                return true;
            }
        }

        return false;
    }
}
