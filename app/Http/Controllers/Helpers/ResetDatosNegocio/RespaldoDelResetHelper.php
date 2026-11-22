<?php

namespace App\Http\Controllers\Helpers\ResetDatosNegocio;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Respaldo previo OBLIGATORIO del reset de datos de negocio (misión reset-datos-cliente, 21/11/2026).
 *
 * Antes de vaciar nada, el mismo proceso que borra escribe un volcado SQL comprimido de lo que va a tocar: cada
 * tabla a vaciar con filas y cada tabla con ajuste (`users`, `cajas`, `addresses`...), con `DROP TABLE IF EXISTS`,
 * `CREATE TABLE` (de `SHOW CREATE TABLE`) e `INSERT` por lotes. Si el volcado falla o no se puede verificar, se
 * lanza una excepción y el reset NO borra nada (falla cerrado).
 *
 * 🔴 Por qué en PHP puro y no con `mysqldump`: en Hostinger no hay binarios garantizados (mismo motivo que
 * `EsquemaDemoSnapshotHelper`), y esta es la única opción que garantiza el respaldo por cualquier puerta que
 * llegue al reset, porque lo hace el mismo proceso que después borra.
 *
 * El archivo queda en `storage/app/respaldos-reset-datos/<AAAAmmdd-HHiiss>-<base>.sql.gz` (fuera de `public`) y
 * se restaura con `gunzip -c <archivo> | mysql <base>`. Contiene los datos del cliente (también `users` con sus
 * claves hasheadas): es un archivo sensible, nunca se sirve por HTTP.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class RespaldoDelResetHelper
{
    /** Carpeta de los respaldos, relativa a la raíz del proyecto (es lo que se informa; nunca una ruta absoluta). */
    const RUTA_PUBLICA = 'storage/app/respaldos-reset-datos';

    /** Cuántos respaldos de reset se conservan (por separado: los `.sql.gz` y las carpetas `<stamp>-archivos`). */
    const RETENCION = 3;

    /** Filas que se leen de la base por vuelta. */
    const FILAS_POR_LECTURA = 1000;

    /** Filas máximas por sentencia INSERT del volcado. */
    const FILAS_POR_INSERT = 500;

    /** Tamaño máximo (bytes) de una sentencia INSERT: tablas con textos largos no pueden pasar el max_allowed_packet al restaurar. */
    const BYTES_POR_INSERT = 1000000;

    /** Marca que cierra el volcado: si falta, el archivo quedó cortado. */
    const MARCA_FINAL = '-- FIN DEL RESPALDO';

    /**
     * Carpeta absoluta de los respaldos.
     *
     * @return string
     */
    public static function directorio()
    {
        return storage_path('app/respaldos-reset-datos');
    }

    /**
     * Nombre del archivo `.sql.gz` de una corrida.
     *
     * @param string $stamp Sello `AAAAmmdd-HHiiss`.
     * @param string $base Nombre de la base.
     * @return string
     */
    public static function nombre_del_archivo($stamp, $base)
    {
        return $stamp . '-' . $base . '.sql.gz';
    }

    /**
     * Escribe y verifica el volcado de las tablas indicadas.
     *
     * Verificaciones al cerrar: el archivo existe, pesa más de cero, se lee entero como gzip y termina con la marca
     * final, y la cantidad de filas escritas en los INSERT es EXACTAMENTE la suma de los conteos esperados. Cualquier
     * falla borra el archivo parcial y lanza RuntimeException.
     *
     * @param string $stamp Sello `AAAAmmdd-HHiiss` de la corrida.
     * @param array<string, int> $esperadas tabla => filas que tiene que traer el volcado (las que se vacían con
     *        filas, más las tablas con ajuste; una tabla con 0 filas se vuelca sólo con su esquema).
     * @return array{ruta_relativa: string, ruta_absoluta: string, bytes: int, sha256: string}
     * @throws RuntimeException Si el volcado no se pudo escribir o no pasa la verificación.
     */
    public static function crear($stamp, array $esperadas)
    {
        // Un volcado grande tarda; el cliente que pidió el reset puede cortar y el proceso tiene que seguir.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $base = (string) DB::connection()->getDatabaseName();
        $directorio = self::directorio();

        // Si en lugar de la carpeta hay un archivo común (o no se puede crear), no hay donde escribir: se frena.
        if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo crear la carpeta de respaldos ' . self::RUTA_PUBLICA . '.');
        }
        if (!is_writable($directorio)) {
            throw new RuntimeException('La carpeta de respaldos ' . self::RUTA_PUBLICA . ' no es escribible.');
        }

        $ruta = $directorio . DIRECTORY_SEPARATOR . self::nombre_del_archivo($stamp, $base);
        if (file_exists($ruta)) {
            throw new RuntimeException('Ya existe un respaldo con el nombre ' . basename($ruta) . '.');
        }

        // Sin log de consultas: un volcado grande lo llenaría de filas en memoria.
        DB::connection()->disableQueryLog();

        $manija = @gzopen($ruta, 'wb6');
        if ($manija === false) {
            throw new RuntimeException('No se pudo abrir el archivo de respaldo para escribir.');
        }

        $filas_escritas = 0;

        try {
            self::escribir($manija, "-- Respaldo previo al reset de datos de negocio\n"
                . '-- Base: ' . $base . "\n"
                . '-- Fecha: ' . date('Y-m-d H:i:s') . "\n"
                . "-- Restaurar: gunzip -c <este archivo> | mysql <base>\n\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($esperadas as $tabla => $filas_esperadas) {
                $filas_escritas += self::volcar_tabla($manija, $tabla);
            }

            self::escribir($manija, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n" . self::MARCA_FINAL . "\n");
        } catch (\Throwable $e) {
            @gzclose($manija);
            @unlink($ruta);

            throw new RuntimeException('Falló la escritura del respaldo: ' . $e->getMessage());
        }

        if (!@gzclose($manija)) {
            @unlink($ruta);

            throw new RuntimeException('No se pudo cerrar el archivo de respaldo.');
        }

        $total_esperado = (int) array_sum($esperadas);
        if ($filas_escritas !== $total_esperado) {
            @unlink($ruta);

            throw new RuntimeException('El respaldo escribió ' . $filas_escritas . ' filas y se esperaban ' . $total_esperado . ' (cambiaron los datos durante el volcado).');
        }

        try {
            self::verificar_el_archivo($ruta);
        } catch (RuntimeException $e) {
            @unlink($ruta);

            throw $e;
        }

        clearstatcache(true, $ruta);

        return [
            'ruta_relativa' => self::RUTA_PUBLICA . '/' . basename($ruta),
            'ruta_absoluta' => $ruta,
            'bytes' => (int) filesize($ruta),
            'sha256' => (string) hash_file('sha256', $ruta),
        ];
    }

    /**
     * Conserva los RETENCION respaldos más nuevos y borra el resto.
     *
     * Cuenta por separado los `.sql.gz` y las carpetas `<stamp>-archivos`, y sólo toca nombres con exactamente ese
     * formato (nunca otro archivo que alguien deje en la carpeta). Se llama DESPUÉS de un respaldo exitoso.
     *
     * @return void
     */
    public static function aplicar_retencion()
    {
        $directorio = self::directorio();

        if (!is_dir($directorio)) {
            return;
        }

        $archivos_sql = [];
        $carpetas_de_archivos = [];

        foreach (scandir($directorio) as $nombre) {
            if (preg_match('/^\d{8}-\d{6}-.+\.sql\.gz$/', $nombre) === 1 && is_file($directorio . DIRECTORY_SEPARATOR . $nombre)) {
                $archivos_sql[] = $nombre;
            } elseif (preg_match('/^\d{8}-\d{6}-archivos$/', $nombre) === 1 && is_dir($directorio . DIRECTORY_SEPARATOR . $nombre)) {
                $carpetas_de_archivos[] = $nombre;
            }
        }

        // El nombre empieza con el sello (AAAAmmdd-HHiiss): ordenar por nombre es ordenar por fecha.
        rsort($archivos_sql);
        rsort($carpetas_de_archivos);

        foreach (array_slice($archivos_sql, self::RETENCION) as $viejo) {
            @unlink($directorio . DIRECTORY_SEPARATOR . $viejo);
        }

        $sistema_de_archivos = new Filesystem();
        foreach (array_slice($carpetas_de_archivos, self::RETENCION) as $vieja) {
            $sistema_de_archivos->deleteDirectory($directorio . DIRECTORY_SEPARATOR . $vieja);
        }
    }

    /**
     * Escribe un texto en el gz o lanza si no se pudo (disco lleno, cuota...).
     *
     * @param resource $manija Handle de gzopen.
     * @param string $texto Texto a escribir.
     * @return void
     * @throws RuntimeException Si gzwrite no escribió todo.
     */
    protected static function escribir($manija, $texto)
    {
        if ($texto === '') {
            return;
        }

        $escrito = @gzwrite($manija, $texto);

        if ($escrito === false || $escrito < strlen($texto)) {
            throw new RuntimeException('No se pudo escribir en el archivo de respaldo (¿disco lleno?).');
        }
    }

    /**
     * Vuelca una tabla: DROP, CREATE y los INSERT por lotes.
     *
     * @param resource $manija Handle de gzopen.
     * @param string $tabla Nombre de la tabla.
     * @return int Cantidad de filas escritas en INSERT.
     * @throws RuntimeException Si la tabla no se puede leer.
     */
    protected static function volcar_tabla($manija, $tabla)
    {
        $conexion = DB::connection();
        $pdo = $conexion->getPdo();

        $fila_create = (array) $conexion->selectOne('SHOW CREATE TABLE `' . str_replace('`', '``', $tabla) . '`');
        $sql_create = isset($fila_create['Create Table']) ? $fila_create['Create Table'] : null;
        if (!is_string($sql_create) || $sql_create === '') {
            throw new RuntimeException('No se pudo leer el esquema de la tabla ' . $tabla . '.');
        }

        $nombre = '`' . str_replace('`', '``', $tabla) . '`';
        self::escribir($manija, '-- Tabla ' . $tabla . "\nDROP TABLE IF EXISTS " . $nombre . ";\n" . $sql_create . ";\n\n");

        // Columnas que se pueden insertar (las generadas se recalculan solas y un INSERT con ellas falla).
        $columnas = $conexion->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $conexion->getDatabaseName())
            ->where('TABLE_NAME', $tabla)
            ->where(function ($consulta) {
                $consulta->whereNull('GENERATION_EXPRESSION')->orWhere('GENERATION_EXPRESSION', '');
            })
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->all();

        if (count($columnas) === 0) {
            throw new RuntimeException('La tabla ' . $tabla . ' no tiene columnas legibles.');
        }

        $lista_de_columnas = '(`' . implode('`,`', array_map(function ($columna) {
            return str_replace('`', '``', $columna);
        }, $columnas)) . '`)';

        // Clave primaria: con una sola columna se lee por "id mayor que el último" (barato y estable); con varias
        // o sin ninguna, por LIMIT/OFFSET (sin ninguna, sin orden).
        $primaria = $conexion->table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $conexion->getDatabaseName())
            ->where('TABLE_NAME', $tabla)
            ->where('CONSTRAINT_NAME', 'PRIMARY')
            ->orderBy('ORDINAL_POSITION')
            ->pluck('COLUMN_NAME')
            ->all();
        $primaria_simple = count($primaria) === 1 && in_array($primaria[0], $columnas, true) ? $primaria[0] : null;

        $escritas = 0;
        $ultimo = null;
        $desplazamiento = 0;
        $lote = [];
        $bytes_del_lote = 0;

        while (true) {
            $consulta = $conexion->table($tabla)->select($columnas);

            if ($primaria_simple !== null) {
                $consulta->orderBy($primaria_simple);
                if ($ultimo !== null) {
                    $consulta->where($primaria_simple, '>', $ultimo);
                }
                $consulta->limit(self::FILAS_POR_LECTURA);
            } else {
                foreach ($primaria as $columna_primaria) {
                    $consulta->orderBy($columna_primaria);
                }
                $consulta->offset($desplazamiento)->limit(self::FILAS_POR_LECTURA);
            }

            $filas = $consulta->get();

            if ($filas->count() === 0) {
                break;
            }

            foreach ($filas as $fila) {
                $fila = (array) $fila;
                $valores = [];

                foreach ($columnas as $columna) {
                    $valores[] = self::literal($pdo, $fila[$columna]);
                }

                $texto_de_la_fila = '(' . implode(',', $valores) . ')';
                $lote[] = $texto_de_la_fila;
                $bytes_del_lote += strlen($texto_de_la_fila);
                $escritas++;

                if (count($lote) >= self::FILAS_POR_INSERT || $bytes_del_lote >= self::BYTES_POR_INSERT) {
                    self::escribir($manija, 'INSERT INTO ' . $nombre . ' ' . $lista_de_columnas . " VALUES\n" . implode(",\n", $lote) . ";\n");
                    $lote = [];
                    $bytes_del_lote = 0;
                }

                if ($primaria_simple !== null) {
                    $ultimo = $fila[$primaria_simple];
                }
            }

            $desplazamiento += $filas->count();

            if ($filas->count() < self::FILAS_POR_LECTURA) {
                break;
            }
        }

        if (count($lote) > 0) {
            self::escribir($manija, 'INSERT INTO ' . $nombre . ' ' . $lista_de_columnas . " VALUES\n" . implode(",\n", $lote) . ";\n");
        }

        self::escribir($manija, "\n");

        return $escritas;
    }

    /**
     * Valor de una celda como literal SQL.
     *
     * NULL → NULL; enteros y decimales tal cual; texto UTF-8 válido escapado con el propio PDO; cualquier otra cosa
     * (binarios) en hexadecimal, que se restaura byte por byte.
     *
     * @param \PDO $pdo Conexión (para escapar con el juego de caracteres real).
     * @param mixed $valor Valor leído de la base.
     * @return string Literal listo para un INSERT.
     */
    protected static function literal($pdo, $valor)
    {
        if ($valor === null) {
            return 'NULL';
        }

        if (is_int($valor)) {
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_float($valor)) {
            return var_export($valor, true);
        }

        $texto = (string) $valor;

        if ($texto === '') {
            return "''";
        }

        if (!mb_check_encoding($texto, 'UTF-8')) {
            return '0x' . bin2hex($texto);
        }

        return $pdo->quote($texto);
    }

    /**
     * Verifica un respaldo ya cerrado: existe, pesa, se descomprime entero y termina con la marca final.
     *
     * @param string $ruta Ruta absoluta del `.sql.gz`.
     * @return void
     * @throws RuntimeException Si algo no cierra.
     */
    protected static function verificar_el_archivo($ruta)
    {
        clearstatcache(true, $ruta);

        if (!is_file($ruta) || filesize($ruta) <= 0) {
            throw new RuntimeException('El archivo de respaldo no existe o está vacío.');
        }

        $manija = @gzopen($ruta, 'rb');
        if ($manija === false) {
            throw new RuntimeException('El archivo de respaldo no se puede abrir para verificarlo.');
        }

        // Se lee todo el flujo (detecta un gzip cortado o corrupto) guardando sólo la cola.
        $cola = '';
        while (!gzeof($manija)) {
            $trozo = gzread($manija, 1048576);

            if ($trozo === false) {
                gzclose($manija);
                throw new RuntimeException('El archivo de respaldo está corrupto (falló la lectura).');
            }

            $cola = substr($cola . $trozo, -200);
        }
        gzclose($manija);

        if (strpos($cola, self::MARCA_FINAL) === false) {
            throw new RuntimeException('El archivo de respaldo quedó cortado (falta la marca final).');
        }
    }
}
