<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Si las columnas y tablas nuevas de la app de IA ya existen en la base (misión app-ia-y-memoria,
 * corrección de la revisión, 29/10/2026).
 *
 * 🔴 POR QUÉ. El chat del sistema y el de WhatsApp pasan por código que la misión tocó (el listado de
 * conversaciones, el cierre de huérfanos, el historial que se le manda al modelo, la línea de progreso).
 * Un frente que ya tiene el código nuevo pero todavía no corrió las migraciones —un upgrade a mitad de
 * camino, un frente nuevo de una base compartida— no puede quedarse sin chat por una columna que no
 * existe: donde se lee o se escribe una columna nueva fuera de la app, se pregunta acá primero.
 *
 * La respuesta se cachea POR PROCESO (static): una consulta al esquema por columna y por worker, no por
 * pedido. Una migración que corre con el worker prendido se ve al reiniciarlo (queue:restart), que es
 * lo que hace el despliegue igual.
 */
class ColumnasDeLaAppIaHelper
{
    /** @var array<string, bool> */
    protected static $columnas = [];

    /** @var array<string, bool> */
    protected static $tablas = [];

    /**
     * true si la tabla tiene la columna. Ante una falla al consultar el esquema, false (el camino viejo).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return bool
     */
    public static function hay_columna($tabla, $columna): bool
    {
        $clave = $tabla . '.' . $columna;

        if (!array_key_exists($clave, self::$columnas)) {

            try {

                self::$columnas[$clave] = Schema::hasColumn($tabla, $columna);

            } catch (\Throwable $e) {

                Log::warning('ColumnasDeLaAppIaHelper: no se pudo consultar el esquema -- ' . $e->getMessage());

                return false;
            }
        }

        return self::$columnas[$clave];
    }

    /**
     * true si la tabla existe. Mismo criterio y mismo caché que hay_columna().
     *
     * @param  string  $tabla
     * @return bool
     */
    public static function hay_tabla($tabla): bool
    {
        if (!array_key_exists($tabla, self::$tablas)) {

            try {

                self::$tablas[$tabla] = Schema::hasTable($tabla);

            } catch (\Throwable $e) {

                Log::warning('ColumnasDeLaAppIaHelper: no se pudo consultar el esquema -- ' . $e->getMessage());

                return false;
            }
        }

        return self::$tablas[$tabla];
    }

    /**
     * Para los tests: hace de cuenta que una columna (o una tabla, con $columna null) existe o no, sin
     * tocar la base (un ALTER en MySQL confirma la transacción del test). Con $existe null se olvida lo
     * fingido y se vuelve a consultar.
     *
     * @param  string  $tabla
     * @param  string|null  $columna
     * @param  bool|null  $existe
     * @return void
     */
    public static function fingir($tabla, $columna, $existe)
    {
        if (is_null($columna)) {

            if (is_null($existe)) {
                unset(self::$tablas[$tabla]);
            } else {
                self::$tablas[$tabla] = (bool) $existe;
            }

            return;
        }

        if (is_null($existe)) {
            unset(self::$columnas[$tabla . '.' . $columna]);
        } else {
            self::$columnas[$tabla . '.' . $columna] = (bool) $existe;
        }
    }

    /**
     * Para los tests: olvida todo lo cacheado y lo fingido.
     *
     * @return void
     */
    public static function olvidar()
    {
        self::$columnas = [];
        self::$tablas   = [];
    }
}
