<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

/**
 * Una planilla PEGADA COMO TEXTO en el mensaje del dueño (misión agente-venta-por-planilla, 02/11/2026).
 *
 * 🔴 EL TEXTO PEGADO NO NECESITA NINGÚN MODELO. El caso real: el dueño pegó los once renglones de su
 * Excel con tabuladores, perfectos, y con ese texto el error no tenía que existir; igual el modelo los
 * fue reescribiendo de memoria hasta cruzar clientes y artículos. Un parser determinista saca las
 * columnas, y desde ahí vale lo mismo que para una foto: la tabla manda (PlanillaIaHelper) y el modelo no
 * vuelve a ver esas líneas.
 *
 * Solo toma el texto si PARECE una planilla de ventas (tiene un importe, algo que se vende y a quién, o
 * el N° de operación). Otra tabla pegada —una lista de precios para actualizar— sigue llegándole al
 * modelo como siempre, que es quien sabe qué hacer con ella.
 *
 * 🔴 NINGUNA LÍNEA DESAPARECE EN SILENCIO. Una línea que no encaja es un renglón con lo que le falta a la vista, o el texto no
 * es una planilla. Y una tabla separada por espacios (incluso uno solo: no sabemos si WhatsApp conserva los tabuladores)
 * no se puede partir por código: se detecta acá y la lee LecturaDePlanillaIaHelper::leer_texto(), con las mismas lecturas de
 * una foto.
 *
 * PHP 7.4: sin match, sin str_contains, sin argumentos nombrados, sin union types.
 */
class PlanillaDeTextoIaHelper
{
    /** Cuántas columnas tiene que tener, como mínimo, una línea para entrar en la tabla. */
    const MIN_COLUMNAS = 3;

    /**
     * La tabla del texto, o null si no hay una planilla de ventas.
     *
     * @param  string|null  $contenido
     * @param  bool  $con_planilla_activa  true si ya hay una planilla: una tabla con solo documento y nombre
     *                                     también vale, porque completa a la que está (como la foto `299`).
     * @param  bool  $sin_mirar_si_parece  true para reconocer la tabla de un mensaje ya aceptado (ver
     *                                     contenido_sin_la_tabla()).
     * @return array{encabezado: array|null, filas: array, lineas: array, roles: array}|null
     */
    public static function tabla_del_texto($contenido, $con_planilla_activa = false, $sin_mirar_si_parece = false)
    {
        $contenido = (string) $contenido;

        if (trim($contenido) === '') {

            return null;
        }

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        $tabla = self::con_tabuladores($lineas);

        if (is_null($tabla)) {

            $tabla = self::con_otro_separador($lineas);
        }

        if (is_null($tabla)) {

            return null;
        }

        $separada = RolesDePlanillaIaHelper::separar_encabezado($tabla['filas']);

        if (!count($separada['filas'])) {

            return null;
        }

        /* «DNI 14000002» en una sola celda: ver RolesDePlanillaIaHelper::documento_con_tipo(). */
        $separada = RolesDePlanillaIaHelper::separar_tipo_de_documento($separada['encabezado'], $separada['filas']);

        $separada['filas'] = RolesDePlanillaIaHelper::sin_filas_de_totales($separada['encabezado'], $separada['filas']);

        /*
         * Los roles salen de las filas enteras: una línea suelta entre medio (un renglón al que le faltan celdas) entra como
         * renglón, pero no puede cambiar lo que es cada columna.
         */
        $enteras = array_values(array_filter($separada['filas'], function ($fila) {

            return count(array_filter($fila, function ($celda) {

                return trim((string) $celda) !== '';

            })) >= self::MIN_COLUMNAS;
        }));

        $roles = RolesDePlanillaIaHelper::roles_de($separada['encabezado'], count($enteras) ? $enteras : $separada['filas']);

        if (!$sin_mirar_si_parece && !self::parece_de_ventas($roles, $con_planilla_activa, $separada['encabezado'], $separada['filas'])) {

            return null;
        }

        return [
            'encabezado' => $separada['encabezado'],
            'filas'      => $separada['filas'],
            'lineas'     => $tabla['lineas'],
            'roles'      => $roles,
        ];
    }

    /**
     * El contenido del mensaje con las líneas de la tabla cambiadas por el texto de reemplazo: lo que ve el
     * modelo. Lo que la persona escribió alrededor («cargame estas ventas») se conserva.
     *
     * @param  string|null  $contenido
     * @param  string  $reemplazo
     * @return string
     */
    public static function contenido_sin_la_tabla($contenido, $reemplazo): string
    {
        $contenido = (string) $contenido;

        $tabla = self::tabla_del_texto($contenido, true, true);

        /* Sin un separador que se pueda partir: lo leyó Claude, y lo que sale son las líneas que parecían ventas. */
        $de_la_tabla = is_null($tabla) ? array_keys(self::lineas_de_ventas_con_espacios($contenido)) : $tabla['lineas'];

        if (!count($de_la_tabla)) {

            return trim($contenido) === '' ? (string) $reemplazo : rtrim($contenido) . "\n" . $reemplazo;
        }

        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        $quedan = [];

        foreach ($lineas as $i => $linea) {

            if (!in_array($i, $de_la_tabla, true) && trim($linea) !== '') {

                $quedan[] = $linea;
            }
        }

        $quedan[] = (string) $reemplazo;

        return implode("\n", $quedan);
    }

    /**
     * Las líneas con tabulador. Una sola alcanza (el dueño puede pegar un solo renglón), con tal de que
     * tenga varias columnas: pegar del Excel siempre deja tabuladores. Todas las líneas con tabulador entran,
     * rellenas hasta la que más columnas tiene: ninguna venta se queda afuera en silencio por tener una
     * celda de menos.
     *
     * 🔴 Y TAMBIÉN LAS QUE NO ENCAJAN: una línea con dos celdas, o una suelta entre dos filas, entra como renglón. Le va a
     * faltar lo que le falta, y el dueño lo va a ver en lo que le pregunta la planilla, en vez de perder esa venta sin saberlo.
     *
     * @param  array<int, string>  $lineas
     * @return array{filas: array, lineas: array}|null
     */
    protected static function con_tabuladores(array $lineas)
    {
        $primera = null;
        $ultima = null;

        foreach ($lineas as $i => $linea) {

            if (strpos($linea, "\t") !== false && count(explode("\t", $linea)) >= self::MIN_COLUMNAS) {

                $primera = is_null($primera) ? $i : $primera;
                $ultima = $i;
            }
        }

        if (is_null($primera)) {

            return null;
        }

        $filas = [];
        $indices = [];
        $columnas = 0;

        foreach ($lineas as $i => $linea) {

            $con_tabulador = strpos($linea, "\t") !== false;

            if (trim($linea) === '' || (!$con_tabulador && ($i < $primera || $i > $ultima))) {
                continue;
            }

            $celdas = array_map('trim', explode("\t", $linea));

            $filas[] = $celdas;
            $indices[] = $i;
            $columnas = max($columnas, count($celdas));
        }

        foreach ($filas as $k => $celdas) {

            $filas[$k] = array_pad($celdas, $columnas, '');
        }

        return ['filas' => $filas, 'lineas' => $indices];
    }

    /**
     * Otro separador («;», «|» o dos o más espacios). Son más fáciles de encontrar por casualidad en un texto
     * común, así que se exigen al menos dos líneas con exactamente las mismas columnas.
     *
     * 🔴 Una línea que no encaja entre las primeras y las últimas filas: el texto no es una planilla que se pueda partir. Con
     * estos separadores no hay cómo saber qué es esa línea, y dejarla afuera sería perder una venta.
     *
     * @param  array<int, string>  $lineas
     * @return array{filas: array, lineas: array}|null
     */
    protected static function con_otro_separador(array $lineas)
    {
        foreach (['/\s*;\s*/', '/\s*\|\s*/', '/\s{2,}/'] as $separador) {

            $por_columnas = [];

            foreach ($lineas as $i => $linea) {

                $linea = trim($linea);

                if ($linea === '') {
                    continue;
                }

                $celdas = preg_split($separador, $linea);

                if (count($celdas) >= self::MIN_COLUMNAS) {

                    $por_columnas[count($celdas)][$i] = $celdas;
                }
            }

            foreach ($por_columnas as $grupo) {

                if (count($grupo) < 2) {
                    continue;
                }

                $indices = array_keys($grupo);

                for ($i = $indices[0]; $i <= $indices[count($indices) - 1]; $i++) {

                    if (!isset($grupo[$i]) && trim((string) $lineas[$i]) !== '') {

                        return null;
                    }
                }

                return ['filas' => array_values($grupo), 'lineas' => $indices];
            }
        }

        return null;
    }

    /**
     * Las tablas del texto que ArchivosAdjuntosIaHelper sacó de un .xlsx, .xls o .csv, sin ningún modelo: una por hoja, con sus
     * celdas separadas por « | ». Solo las que parecen de ventas ($con_planilla_activa como en tabla_del_texto()). Los títulos de
     * arriba del Excel («Ventas de octubre»), que no son una fila, se saltean.
     *
     * @param  string|null  $texto
     * @param  bool  $con_planilla_activa
     * @return array<int, array>
     */
    public static function tablas_de_un_archivo($texto, $con_planilla_activa = false): array
    {
        $tablas = [];

        foreach (preg_split('/\n\s*\n/', (string) $texto) as $hoja) {

            $filas = [];
            $columnas = 0;

            foreach (preg_split('/\r\n|\r|\n/', $hoja) as $linea) {

                $linea = trim($linea);

                if ($linea === '' || mb_strpos($linea, '|') === false) {
                    continue;
                }

                $celdas = array_map('trim', explode('|', $linea));

                $con_algo = count(array_filter($celdas, function ($celda) {

                    return $celda !== '';
                }));

                if (!count($filas) && $con_algo < self::MIN_COLUMNAS) {
                    continue;
                }

                $filas[] = $celdas;
                $columnas = max($columnas, count($celdas));
            }

            if (!count($filas)) {
                continue;
            }

            foreach ($filas as $k => $celdas) {

                $filas[$k] = array_pad($celdas, $columnas, '');
            }

            $separada = RolesDePlanillaIaHelper::separar_encabezado($filas);

            if (!count($separada['filas'])) {
                continue;
            }

            $separada = RolesDePlanillaIaHelper::separar_tipo_de_documento($separada['encabezado'], $separada['filas']);

            $separada['filas'] = RolesDePlanillaIaHelper::sin_filas_de_totales($separada['encabezado'], $separada['filas']);

            $roles = RolesDePlanillaIaHelper::roles_de($separada['encabezado'], $separada['filas']);

            if (self::parece_de_ventas($roles, $con_planilla_activa, $separada['encabezado'], $separada['filas'])) {

                $tablas[] = ['encabezado' => $separada['encabezado'], 'filas' => $separada['filas'], 'roles' => $roles];
            }
        }

        return $tablas;
    }

    /**
     * Las líneas que parecen ventas aunque no tengan un separador que se pueda partir: [indice => línea]. Dos o más líneas con
     * plata y con un documento, una operación o un comprobante; o una sola que tenga las tres cosas: un mensaje común
     * («vendele a Juan por $ 5.000») no llega. Esto solo decide si vale pagar una lectura: lo demás lo dicen las lecturas.
     *
     * @param  string|null  $contenido
     * @return array<int, string>
     */
    public static function lineas_de_ventas_con_espacios($contenido): array
    {
        $con_venta = [];
        $con_todo = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $contenido) as $i => $linea) {

            $normal = RolesDePlanillaIaHelper::normalizar_texto($linea);

            $plata = (bool) preg_match('/\$\s?\d|\b\d{1,3}(\.\d{3})+(,\d{1,2})?\b|\b\d+,\d{2}\b/', $normal);

            /* Sin el número de un comprobante: «0001-00012345» trae ocho dígitos y no es un documento. */
            $documento = (bool) preg_match('/(?<![\d\-])\d{7,8}(?![\d\-])|(?<![\d\-])\d{11}(?![\d\-])|\b\d{2}-\d{7,8}-\d\b|\b(dni|cuit|cuil)\b/', $normal);

            $operacion = (bool) preg_match('/#\s?\d{4,}|\b\d{14,}\b/', $normal);

            $comprobante = (bool) preg_match('/\b(c\.? ?final|consumidor final|factura( [abc])?)\b/', $normal);

            /* El comprobante solo no alcanza: una lista de facturas de compra lo tiene. */
            if ($plata && ($documento || $operacion)) {

                $con_venta[$i] = $linea;
            }

            if ($plata && $documento && ($operacion || $comprobante)) {

                $con_todo[$i] = $linea;
            }
        }

        if (count($con_venta) >= 2) {

            return $con_venta;
        }

        return $con_todo;
    }

    /**
     * true si las columnas alcanzan para ser ventas: lo que vale, lo que se vendió y a quién.
     *
     * 🔴 «A quién» sin operación solo vale si se sostiene. Una columna de texto suelta puede ser una marca o un rubro: sin
     * esto, una lista de precios pegada (código, nombre, marca, precio) se tomaba por una planilla y el modelo dejaba de
     * verla, cuando lo que le pedían era actualizar esos precios. Y 🔴 7 u 8 dígitos no alcanzan para ser un documento: un
     * Excel de stock tiene códigos de barras de 8. Hace falta que lo diga un título, una columna de tipo de documento, una
     * de comprobante o una de operación; o que sean 11 dígitos con el verificador bien.
     *
     * Una columna con C FINAL o FACTURA dice que son ventas si además hay un documento, una operación o un título de ventas: 🔴 el
     * comprobante solo no alcanza, porque una lista de facturas de compra («Factura A, 0001-00012345, Proveedor Uno, $ 50.000») lo tiene,
     * y el modelo dejaría de ver esas líneas. Con un título de proveedor o de compra no son ventas.
     *
     * @param  array<int, string>  $roles
     * @param  bool  $con_planilla_activa  true: también alcanza con documento y nombre, u operación, y quien llama mira
     *                                     que crucen con la planilla (PlanillaIaHelper::la_tabla_cruza()).
     * @param  array<int, string>|null  $encabezado
     * @param  array  $filas
     * @return bool
     */
    public static function parece_de_ventas(array $roles, $con_planilla_activa, $encabezado = null, array $filas = []): bool
    {
        $tiene = function ($rol) use ($roles) {

            return in_array($rol, $roles, true);
        };

        $titulos = is_array($encabezado) ? RolesDePlanillaIaHelper::normalizar_texto(implode(' ', array_map('strval', $encabezado))) : '';

        if (preg_match('/\b(proveedor|proveedores|compra|compras)\b/', $titulos)) {

            return false;
        }

        if ($tiene(RolesDePlanillaIaHelper::COMPROBANTE) && ($tiene(RolesDePlanillaIaHelper::DOCUMENTO) || $tiene(RolesDePlanillaIaHelper::OPERACION)
            || preg_match('/\b(venta|ventas|cliente|clientes|comprador|compradores)\b/', $titulos))) {

            return true;
        }

        if ($con_planilla_activa && ($tiene(RolesDePlanillaIaHelper::OPERACION)
            || ($tiene(RolesDePlanillaIaHelper::DOCUMENTO) && $tiene(RolesDePlanillaIaHelper::NOMBRE)))) {

            return true;
        }

        $por_titulo = function ($rol) use ($roles, $encabezado) {

            $columna = array_search($rol, $roles, true);

            return $columna !== false && is_array($encabezado) && isset($encabezado[$columna])
                && RolesDePlanillaIaHelper::rol_por_titulo($encabezado[$columna]) === $rol;
        };

        $que = $tiene(RolesDePlanillaIaHelper::CODIGO) || $tiene(RolesDePlanillaIaHelper::DESCRIPCION);

        $documento_firme = $tiene(RolesDePlanillaIaHelper::DOCUMENTO) && ($por_titulo(RolesDePlanillaIaHelper::DOCUMENTO)
            || $tiene(RolesDePlanillaIaHelper::TIPO_DOCUMENTO)
            || self::hay_cuit_valido(array_search(RolesDePlanillaIaHelper::DOCUMENTO, $roles, true), $filas));

        $a_quien = $documento_firme || $tiene(RolesDePlanillaIaHelper::OPERACION) || $por_titulo(RolesDePlanillaIaHelper::NOMBRE);

        /* Una columna que puede ser el importe y no se decidió sigue siendo una planilla: lo que falta son sus títulos. */
        return ($tiene(RolesDePlanillaIaHelper::IMPORTE) || $tiene(RolesDePlanillaIaHelper::SIN_DECIDIR)) && $que && $a_quien;
    }

    /**
     * true si en esa columna hay un número de 11 dígitos con el verificador bien.
     *
     * @param  int  $columna
     * @param  array  $filas
     * @return bool
     */
    protected static function hay_cuit_valido($columna, array $filas): bool
    {
        foreach ($filas as $fila) {

            $digitos = isset($fila[$columna]) ? RolesDePlanillaIaHelper::solo_digitos($fila[$columna]) : '';

            if (strlen($digitos) === CuitIaHelper::LARGO_DE_CUIT && is_null(CuitIaHelper::motivo_si_no_sirve($digitos))) {

                return true;
            }
        }

        return false;
    }
}
