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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * El resumen de una importación de clientes o proveedores ANTES de hacerla, fila por fila (misión
 * importaciones-de-martin, 11/11/2026): qué contacto toca cada fila, si se crea o se actualiza, y qué movimiento
 * de cuenta corriente le va a generar el saldo del Excel. Lo previsto se guarda en la corrida y cada tanda
 * importada se controla contra eso (ImportacionDeSoporteService::controlar_tanda).
 *
 * 🔴 Reproduce las reglas de los importadores de siempre, sin tocarlos:
 *  - Emparejamiento (ClientImport / ProviderImport::collection): dentro del dueño, por `num` si la fila trae número
 *    (la columna es entera: «007» = «7») y si no por `name`, con la colación de la base (utf8mb4_unicode_ci: sin
 *    mayúsculas ni acentos). Por eso la búsqueda se hace en la base y no en PHP.
 *  - Saldo de un CLIENTE (LocalImportHelper::procesarSaldoImportacion): al nuevo, un «Saldo inicial»; al existente,
 *    una nota de crédito (si el Excel dice menos) o de débito (si dice más) por la diferencia contra el saldo vivo
 *    de su cuenta en pesos, con el mismo umbral (0,009) y sin redondear antes de restar.
 *  - Saldo de un PROVEEDOR (LocalImportHelper::setSaldoInicial): solo un saldo inicial en una cuenta vacía; si la
 *    cuenta ya tiene movimientos y otro saldo, NO se carga (`no_se_carga`); menos de medio centavo no carga nada.
 *
 * 🔴 LO QUE NO SE PUEDE PREDECIR NO SE IMPORTA. Una fila es ambigua (`filas_ambiguas`) si el resultado depende del
 * orden en que el importador recorre el archivo o de cuál de varios contactos encuentra primero: el mismo nombre o
 * número en más de una fila, una fila que coincide con varios contactos, un número que no es un número, una fila
 * que crea un contacto con el nombre de otro que ya existe, una que renombra a un contacto con el nombre de otro, o
 * una que busca por nombre a un contacto que otra fila renombra. Con una sola ambigua la corrida no pide código:
 * queda `requiere_correccion` para que el dueño corrija el Excel. Sin ambiguas, cada contacto lo toca UNA fila, y
 * por eso las tandas son independientes entre sí.
 *
 * Solo lee. PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class PlanDeContactosDeSoporte
{
    /** Cuántos nombres de ejemplo, saldos y filas ambiguas se muestran en el resumen (el total va aparte). */
    const MAXIMO_DE_EJEMPLOS = 10;
    const MAXIMO_DE_SALDOS = 60;
    const MAXIMO_DE_AMBIGUAS = 100;

    /**
     * Filas con nombre que entran en una importación desde soporte. Medido el 11/11/2026 en la base de testing de
     * s18: ~0,05 s por fila importada; con el tope de 900 s del job y margen para releer y controlar, más de esto es
     * una importación que puede quedar a medias. El resumen lo frena antes de pedir el código
     * (`archivo_demasiado_grande`) y sugiere partir el archivo.
     */
    const MAXIMO_DE_FILAS = 5000;

    /** El techo vigente (config `importacion_soporte.maximo_de_filas`, para poder probarlo; default MAXIMO_DE_FILAS). */
    public static function maximo_de_filas(): int
    {
        return max(1, (int) config('importacion_soporte.maximo_de_filas', self::MAXIMO_DE_FILAS));
    }

    /** Propiedades que entiende cada importador (las de AiClientAnalyzer / AiProviderAnalyzer). */
    const PROPIEDADES = [
        'client' => ['nombre', 'telefono', 'email', 'direccion', 'localidad', 'provincia', 'cuit', 'cuil', 'dni',
            'razon_social', 'numero', 'vendedor', 'sucursal', 'condicion_frente_al_iva', 'tipo_de_precio',
            'saldo_actual', 'descripcion'],
        'provider' => ['nombre', 'telefono', 'email', 'direccion', 'localidad', 'cuit', 'razon_social', 'numero',
            'observaciones', 'condicion_frente_al_iva', 'saldo_actual'],
    ];

    /**
     * Los campos que compara el `isDataUpdated()` de cada importador: columna del contacto => columna del Excel.
     * (El `num` del proveedor no está: el importador lo escribe pero no lo mira para decidir si actualiza.)
     */
    const CAMPOS_QUE_DECIDEN = [
        'client' => ['name' => 'nombre', 'phone' => 'telefono', 'address' => 'direccion', 'email' => 'email',
            'razon_social' => 'razon_social', 'cuit' => 'cuit', 'cuil' => 'cuil', 'dni' => 'dni', 'description' => 'descripcion'],
        'provider' => ['name' => 'nombre', 'phone' => 'telefono', 'address' => 'direccion', 'email' => 'email',
            'razon_social' => 'razon_social', 'cuit' => 'cuit', 'observations' => 'observaciones'],
    ];

    /** Relaciones que el importador resuelve por nombre: columna del Excel => [columna del contacto, tabla, campo]. */
    const RELACIONES = [
        'client' => ['provincia' => ['provincia_id', 'provincias', 'name'], 'localidad' => ['location_id', 'locations', 'name'],
            'vendedor' => ['seller_id', 'sellers', 'name'], 'tipo_de_precio' => ['price_type_id', 'price_types', 'name'],
            'sucursal' => ['address_id', 'addresses', 'street']],
        'provider' => ['localidad' => ['location_id', 'locations', 'name']],
    ];

    /**
     * El `columns` que esperan ClientImport / ProviderImport (propiedad => índice de columna, 0-based) a partir del
     * `column_mapping` del analizador. Mismo armado que el panel de implementación del admin.
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
     * Un nombre como lo compara la base (utf8mb4_unicode_ci): sin mayúsculas, sin acentos (la ñ es n) y con los
     * espacios colapsados. Colapsar espacios es más estricto que la base: puede marcar de más, nunca de menos.
     */
    public static function nombre_comparable($nombre): string
    {
        // Str::ascii cubre todos los acentos (ã, õ, ý, ñ…), no solo los del castellano.
        $nombre = strtolower(Str::ascii(mb_strtolower(trim((string) $nombre), 'UTF-8')));

        return trim((string) preg_replace('/\s+/u', ' ', $nombre));
    }

    /**
     * Arma lo previsto contra los datos de AHORA.
     *
     * @param  \App\Models\ImportacionDeSoporte $corrida
     * @return array [
     *   'resumen'    => lo que se le muestra al dueño (y al admin),
     *   'previsto'   => [{fila, nombre, clave, id|null, nuevo, cambia_seguro, cambia_posible, movimientos: [[tipo, monto]]}],
     *   'ambiguas'   => [{fila, nombre, motivo}],
     *   'ultima_fila'=> int (para partir en tandas),
     *   'filas_crudas' => [fila => celdas] desde fila_inicio, tal como las lee el importador (para no releer el Excel),
     *   'demasiado_grande' => bool (más de MAXIMO_DE_FILAS filas con nombre),
     *   'huella'     => string,
     * ]
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

        $modelo_nombre = (string) $corrida->modelo;
        $es_cliente = $modelo_nombre === 'client';
        $clase = $es_cliente ? Client::class : Provider::class;
        $owner_id = (int) $corrida->user_id;

        // 1) Las filas con nombre (las que el importador no saltea), como las recorre él.
        $filas = [];
        $crudas = [];
        $sin_nombre = 0;
        $num_row = 1;
        foreach ($lector->filas as $row) {
            if ($num_row >= (int) $corrida->fila_inicio) {
                $crudas[$num_row] = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
                $nombre = ImportHelper::getColumnValue($row, 'nombre', $columnas);
                if (!is_null($nombre)) {
                    $numero = ImportHelper::getColumnValue($row, 'numero', $columnas);
                    $filas[] = ['fila' => $num_row, 'row' => $row, 'nombre' => $nombre, 'clave' => self::nombre_comparable($nombre),
                        'numero' => $numero, 'num' => is_null($numero) || !preg_match('/^\d{1,9}$/', trim($numero)) ? null : (int) trim($numero)];
                } elseif (self::fila_con_datos($row)) {
                    $sin_nombre++;
                }
            }
            $num_row++;
        }
        $ultima_fila = $num_row - 1;

        // 2) Ambigüedades dentro del archivo.
        $por_nombre = [];
        $por_numero = [];
        foreach ($filas as $fila) {
            $por_nombre[$fila['clave']] = (isset($por_nombre[$fila['clave']]) ? $por_nombre[$fila['clave']] : 0) + 1;
            if (!is_null($fila['num'])) {
                $por_numero[$fila['num']] = (isset($por_numero[$fila['num']]) ? $por_numero[$fila['num']] : 0) + 1;
            }
        }
        $ambiguas = [];
        $marcar = function (array $fila, string $motivo) use (&$ambiguas) {
            if (!isset($ambiguas[$fila['fila']])) {
                $ambiguas[$fila['fila']] = ['fila' => $fila['fila'], 'nombre' => (string) $fila['nombre'], 'motivo' => $motivo];
            }
        };
        foreach ($filas as $fila) {
            if (!is_null($fila['numero']) && is_null($fila['num'])) {
                $marcar($fila, 'numero_no_valido');
            } elseif ($por_nombre[$fila['clave']] > 1) {
                $marcar($fila, 'nombre_repetido_en_el_archivo');
            } elseif (!is_null($fila['num']) && $por_numero[$fila['num']] > 1) {
                $marcar($fila, 'numero_repetido_en_el_archivo');
            }
        }

        // 3) Con qué contacto de la base cae cada fila, y las ambigüedades contra la base.
        $por_contacto = [];
        $renombrados = []; // clave del nombre viejo => fila que lo cambia
        foreach ($filas as $indice => $fila) {
            if (!is_null($fila['numero']) && is_null($fila['num'])) {
                $filas[$indice]['modelo'] = null;
                continue;
            }
            $consulta = $clase::where('user_id', $owner_id);
            $coinciden = is_null($fila['num'])
                ? $consulta->where('name', $fila['nombre'])->orderBy('id')->limit(2)->get()
                : $consulta->where('num', $fila['num'])->orderBy('id')->limit(2)->get();
            if (count($coinciden) > 1) {
                $marcar($fila, 'varios_contactos_coinciden');
            }
            $modelo = count($coinciden) > 0 ? $coinciden[0] : null;
            $filas[$indice]['modelo'] = $modelo;
            if (!is_null($modelo)) {
                if (isset($por_contacto[(int) $modelo->id])) {
                    $marcar($fila, 'contacto_en_varias_filas');
                    $marcar($filas[$por_contacto[(int) $modelo->id]], 'contacto_en_varias_filas');
                }
                $por_contacto[(int) $modelo->id] = $indice;
                if (!is_null($fila['num']) && self::nombre_comparable($modelo->name) !== $fila['clave']) {
                    $renombrados[self::nombre_comparable($modelo->name)] = $indice;
                    if ($clase::where('user_id', $owner_id)->where('name', $fila['nombre'])->where('id', '!=', $modelo->id)->exists()) {
                        $marcar($fila, 'renombra_con_el_nombre_de_otro');
                    }
                }
            } elseif (!is_null($fila['num']) && $clase::where('user_id', $owner_id)->where('name', $fila['nombre'])->exists()) {
                $marcar($fila, 'ya_existe_con_otro_numero');
            }
        }
        foreach ($filas as $indice => $fila) {
            if (is_null($fila['numero']) && isset($renombrados[$fila['clave']]) && $renombrados[$fila['clave']] !== $indice) {
                $marcar($fila, 'busca_un_nombre_que_otra_fila_cambia');
                $marcar($filas[$renombrados[$fila['clave']]], 'busca_un_nombre_que_otra_fila_cambia');
            }
        }
        // Un número del Excel por encima del más alto que ya existe choca con la numeración automática de las filas
        // nuevas sin número (el importador les da el siguiente al máximo, y en el medio puede caer en este).
        $nuevas_sin_numero = 0;
        foreach ($filas as $fila) {
            if (is_null($fila['numero']) && is_null($fila['modelo'])) {
                $nuevas_sin_numero++;
            }
        }
        if ($nuevas_sin_numero > 0) {
            $maximo = (int) DB::table($es_cliente ? 'clients' : 'providers')->where('user_id', $owner_id)->max('num');
            foreach ($filas as $fila) {
                if (!is_null($fila['num']) && is_null($fila['modelo']) && $fila['num'] > $maximo) {
                    $marcar($fila, 'numero_automatico_choca');
                }
            }
        }
        ksort($ambiguas);

        // 4) Lo previsto de cada fila: alta o actualización, y su movimiento de saldo.
        $previsto = [];
        $saldos = [];
        $ilegibles = [];
        $crear = 0;
        $actualizar = 0;
        $sin_cambios = 0;
        $ejemplos_crear = [];
        $ejemplos_actualizar = [];
        foreach ($filas as $fila) {
            $modelo = $fila['modelo'];
            $nuevo = is_null($modelo);
            $seguro = false;
            $posible = false;
            if (!$nuevo) {
                list($seguro, $posible) = self::cambios($modelo_nombre, $modelo, $fila['row'], $columnas);
            }
            $movimientos = [];
            if (isset($columnas['saldo_actual'])) {
                $saldo = self::saldo_de_la_fila($fila, $columnas, $es_cliente, $modelo);
                if (isset($saldo['ilegible'])) {
                    $ilegibles[] = $saldo['ilegible'];
                } elseif (!is_null($saldo)) {
                    $saldos[] = $saldo['linea'];
                    $movimientos = $saldo['movimientos'];
                }
            }
            if ($nuevo) {
                $crear++;
                if (count($ejemplos_crear) < self::MAXIMO_DE_EJEMPLOS) {
                    $ejemplos_crear[] = (string) $fila['nombre'];
                }
            } elseif ($seguro || $posible) {
                $actualizar++;
                if (count($ejemplos_actualizar) < self::MAXIMO_DE_EJEMPLOS) {
                    $ejemplos_actualizar[] = (string) $modelo->name;
                }
            } else {
                $sin_cambios++;
            }
            $previsto[] = [
                'fila' => $fila['fila'], 'nombre' => (string) $fila['nombre'], 'clave' => $fila['clave'],
                'id' => $nuevo ? null : (int) $modelo->id, 'nuevo' => $nuevo,
                'cambia_seguro' => $seguro, 'cambia_posible' => $posible, 'movimientos' => $movimientos,
            ];
        }

        $resumen = [
            'modelo' => $modelo_nombre,
            'filas' => count($filas),
            'crear' => $crear,
            'actualizar' => $actualizar,
            'sin_cambios' => $sin_cambios,
            'ejemplos_crear' => $ejemplos_crear,
            'ejemplos_actualizar' => $ejemplos_actualizar,
            'campos' => array_keys($columnas),
            'saldos_total' => count($saldos),
            'saldos_no_cargados' => count(array_filter($saldos, function ($saldo) {
                return $saldo['accion'] === 'no_se_carga';
            })),
            'saldos' => array_slice($saldos, 0, self::MAXIMO_DE_SALDOS),
            'saldos_ilegibles' => array_slice($ilegibles, 0, self::MAXIMO_DE_SALDOS),
            'filas_sin_nombre' => $sin_nombre,
            'filas_ambiguas_total' => count($ambiguas),
            'filas_ambiguas' => array_slice(array_values($ambiguas), 0, self::MAXIMO_DE_AMBIGUAS),
            'maximo_de_filas' => self::maximo_de_filas(),
        ];

        $huella = hash('sha256', json_encode([
            $modelo_nombre, $columnas, (int) $corrida->hoja, $corrida->hoja_nombre, (int) $corrida->fila_inicio,
            hash_file('sha256', $ruta), $previsto, array_values($ambiguas),
        ]));

        return ['resumen' => $resumen, 'previsto' => $previsto, 'ambiguas' => array_values($ambiguas),
            'ultima_fila' => $ultima_fila, 'filas_crudas' => $crudas, 'demasiado_grande' => count($filas) > self::maximo_de_filas(),
            'huella' => $huella];
    }

    /**
     * Si la fila le cambia algo a un contacto existente: [seguro, posible]. `seguro` replica el isDataUpdated() del
     * importador con su comparación floja (`!=`) sobre los campos de texto y la condición de IVA; `posible` es una
     * relación por nombre (localidad, vendedor…) que no coincide con la actual: el importador puede resolverla a
     * otra (equivalencias recordadas), así que no se exige que pase.
     */
    private static function cambios(string $modelo_nombre, $modelo, $row, array $columnas): array
    {
        $seguro = false;
        foreach (self::CAMPOS_QUE_DECIDEN[$modelo_nombre] as $campo => $columna) {
            $valor = ImportHelper::getColumnValue($row, $columna, $columnas);
            if (is_null($valor)) {
                continue;
            }
            if ($modelo_nombre === 'client' && ($campo === 'cuit' || $campo === 'cuil')) {
                $valor = str_replace('-', '', $valor);
            }
            if ($valor != $modelo->{$campo}) {
                $seguro = true;
            }
        }
        $iva = ImportHelper::getColumnValueByAliases($row, ['condicion_frente_al_iva', 'condicion frente al iva'], $columnas);
        if (!is_null($iva)) {
            $iva_id = LocalImportHelper::getIvaConditionId($iva);
            if (!is_null($iva_id) && $iva_id != $modelo->iva_condition_id) {
                $seguro = true;
            }
        }

        $posible = false;
        foreach (self::RELACIONES[$modelo_nombre] as $columna => $relacion) {
            $valor = $columna === 'tipo_de_precio'
                ? ImportHelper::getColumnValueByAliases($row, ['tipo_de_precio', 'tipo de precio'], $columnas)
                : ImportHelper::getColumnValue($row, $columna, $columnas);
            if (is_null($valor) || $valor === 'Sin especificar') {
                continue;
            }
            $actual = is_null($modelo->{$relacion[0]}) ? null
                : DB::table($relacion[1])->where('id', $modelo->{$relacion[0]})->value($relacion[2]);
            if (is_null($actual) || self::nombre_comparable($actual) !== self::nombre_comparable($valor)) {
                $posible = true;
            }
        }

        return [$seguro, $posible];
    }

    /**
     * El movimiento que genera el saldo de una fila, con la misma cuenta que hace el importador.
     *
     * @return array|null  null = no cambia nada; ['ilegible' => [...]]; ['linea' => resumen, 'movimientos' => [[tipo, monto]]]
     */
    private static function saldo_de_la_fila(array $fila, array $columnas, bool $es_cliente, $modelo)
    {
        $lectura = LocalImportHelper::leerSaldoDeLaFila($fila['row'], $columnas);
        if ($lectura['estado'] === 'vacio') {
            return null;
        }
        if ($lectura['estado'] === 'ilegible') {
            return ['ilegible' => ['fila' => $fila['fila'], 'nombre' => (string) $fila['nombre'], 'texto' => (string) $lectura['texto']]];
        }

        $valor = (float) $lectura['valor'];
        $nuevo = is_null($modelo);
        $linea = ['fila' => $fila['fila'], 'id' => $nuevo ? null : (int) $modelo->id,
            'nombre' => $nuevo ? (string) $fila['nombre'] : (string) $modelo->name, 'nuevo' => $nuevo];
        $cuenta = $nuevo ? null : LocalImportHelper::get_credit_account_pesos($es_cliente ? 'client' : 'provider', $modelo->id);

        if ($es_cliente) {
            if ($nuevo) {
                // ClientImport le carga el «Saldo inicial» aunque sea 0; la importación desde soporte borra el de 0
                // (ver ImportacionDeSoporteService::sin_saldos_en_cero), así que no se cuenta.
                $monto = round($valor, 2);
                return abs($monto) < 0.005 ? null : ['linea' => $linea + ['accion' => 'saldo_inicial', 'saldo_actual' => 0.0,
                    'saldo_resultante' => $monto, 'monto' => abs($monto)], 'movimientos' => [['saldo_inicial', $monto]]];
            }
            $actual = is_null($cuenta) ? 0.0 : (float) CurrentAcountHelper::getSaldo($cuenta->id);
            $diferencia = $valor - $actual;
            if (abs($diferencia) < 0.009) {
                return null;
            }
            $monto = round(abs($diferencia), 2);
            $tipo = $diferencia < 0 ? 'nota_de_credito' : 'nota_de_debito';

            return ['linea' => $linea + ['accion' => $tipo, 'saldo_actual' => round($actual, 2), 'saldo_resultante' => round($valor, 2),
                'monto' => $monto], 'movimientos' => [[$tipo, $monto]]];
        }

        if (abs($valor) < 0.005) {
            return null;
        }
        $monto = round($valor, 2);
        if (is_null($cuenta) || !CurrentAcount::where('credit_account_id', $cuenta->id)->exists()) {
            return ['linea' => $linea + ['accion' => 'saldo_inicial', 'saldo_actual' => 0.0, 'saldo_resultante' => $monto,
                'monto' => abs($monto)], 'movimientos' => [['saldo_inicial', $monto]]];
        }
        $de_la_cuenta = (float) $cuenta->saldo;
        if (abs($valor - $de_la_cuenta) < 0.01) {
            return null;
        }

        return ['linea' => $linea + ['accion' => 'no_se_carga', 'saldo_actual' => round($de_la_cuenta, 2),
            'saldo_resultante' => round($de_la_cuenta, 2), 'saldo_excel' => $monto, 'monto' => 0.0], 'movimientos' => []];
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
