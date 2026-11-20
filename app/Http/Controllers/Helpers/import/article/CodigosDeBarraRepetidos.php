<?php

namespace App\Http\Controllers\Helpers\import\article;

/**
 * Códigos de barras repetidos dentro de un mismo Excel, y la regla para resolverlos por código
 * de proveedor (misión importacion-formula-precio, 20/11/2026).
 *
 * Hay planillas donde el código de barras está mal cargado: varios artículos distintos comparten
 * el mismo («111111111», o el código interno del producto padre). Si se identifica por código de
 * barras, esos artículos se pisan entre sí y queda uno solo. La regla que se le ofrece al usuario:
 *
 *  1. Código de barras único en el archivo → se identifica por código de barras, como siempre.
 *  2. Código de barras repetido y la fila tiene un código de proveedor que NO se repite dentro de
 *     ese grupo → se identifica por código de proveedor y el código de barras NO se carga.
 *  3. Código de barras repetido sin código de proveedor, o con un código de proveedor que también
 *     se repite dentro del grupo → se identifica por código de barras: la última fila pisa a las
 *     anteriores (comportamiento de siempre).
 *
 * Se calcula recorriendo el archivo completo y no por lote, porque un repetido puede caer en
 * lotes distintos de la importación.
 */
class CodigosDeBarraRepetidos
{
    /**
     * Por código de barras: [código de proveedor => cantidad de filas] (clave '' = sin código).
     * @var array<string, array<string, int>>
     */
    protected $grupos = [];

    /**
     * Primeras filas de cada código de barras, para los ejemplos que se le muestran al usuario.
     * @var array<string, array>
     */
    protected $ejemplos = [];

    /**
     * Cantidad de códigos de barras descartados por ser de relleno («0», «111111111»).
     * @var int
     */
    protected $de_relleno = 0;

    /**
     * Valores de relleno distintos encontrados, para mostrarlos.
     * @var array<string, int>
     */
    protected $valores_de_relleno = [];

    /**
     * Agrega una fila del Excel al análisis.
     *
     * @param  mixed       $codigo_de_barras
     * @param  mixed       $codigo_de_proveedor
     * @param  int|null    $fila     Número de fila del Excel (para los ejemplos).
     * @param  string|null $nombre   Nombre del artículo (para los ejemplos).
     * @return void
     */
    public function agregar($codigo_de_barras, $codigo_de_proveedor, $fila = null, $nombre = null)
    {
        $crudo = is_null($codigo_de_barras) ? '' : trim((string) $codigo_de_barras);
        $barras = self::normalizar_codigo_de_barras($codigo_de_barras);

        if (is_null($barras)) {
            // Distingue «no trae código» de «trae un código de relleno».
            if ($crudo !== '') {
                $this->de_relleno++;
                $this->valores_de_relleno[$crudo] = ($this->valores_de_relleno[$crudo] ?? 0) + 1;
            }
            return;
        }

        $proveedor = IdentifierNormalizer::normalize($codigo_de_proveedor);
        $clave_proveedor = is_null($proveedor) ? '' : $proveedor;

        $this->grupos[$barras][$clave_proveedor] = ($this->grupos[$barras][$clave_proveedor] ?? 0) + 1;

        if (count($this->ejemplos[$barras] ?? []) < 4) {
            $this->ejemplos[$barras][] = [
                'fila'                => $fila,
                'nombre'              => $nombre,
                'codigo_de_proveedor' => $proveedor,
            ];
        }
    }

    /**
     * Normaliza un código de barras: además de los placeholders de IdentifierNormalizer, descarta
     * los códigos de relleno formados por un mismo dígito repetido («1111», «111111111», «0000»).
     * Ningún EAN/UPC real es un mismo dígito repetido, y un código así compartido por cientos de
     * artículos solo sirve para pisarlos entre sí.
     *
     * @param  mixed $valor
     * @return string|null
     */
    public static function normalizar_codigo_de_barras($valor)
    {
        $limpio = IdentifierNormalizer::normalize($valor);

        if (is_null($limpio)) {
            return null;
        }

        if (preg_match('/^(\d)\1{3,}$/', $limpio)) {
            return null;
        }

        return $limpio;
    }

    /**
     * Mapa de las filas cuyo código de barras se omite y se identifican por código de proveedor
     * (caso 2 de la regla): [código de barras => [código de proveedor => true]].
     * Es lo que viaja a cada lote de la importación.
     *
     * @return array<string, array<string, bool>>
     */
    public function mapa_de_omision()
    {
        $mapa = [];

        foreach ($this->grupos as $barras => $proveedores) {
            if (array_sum($proveedores) < 2) {
                continue;
            }

            foreach ($proveedores as $proveedor => $cantidad) {
                // Sin código de proveedor, o repetido dentro del grupo: no hay con qué distinguirlo.
                if ($proveedor === '' || $cantidad > 1) {
                    continue;
                }
                $mapa[(string) $barras][(string) $proveedor] = true;
            }
        }

        return $mapa;
    }

    /**
     * Resumen para la pantalla de importación.
     *
     * @return array{
     *   grupos: int, filas: int, filas_por_codigo_de_proveedor: int, filas_que_se_pisan: int,
     *   codigos_de_relleno: int, valores_de_relleno: array, ejemplos: array
     * }
     */
    public function resumen()
    {
        $grupos = 0;
        $filas = 0;
        $por_proveedor = 0;
        $ejemplos = [];

        foreach ($this->grupos as $barras => $proveedores) {
            $total = array_sum($proveedores);
            if ($total < 2) {
                continue;
            }

            $grupos++;
            $filas += $total;

            foreach ($proveedores as $proveedor => $cantidad) {
                if ($proveedor !== '' && $cantidad === 1) {
                    $por_proveedor++;
                }
            }

            if (count($ejemplos) < 5) {
                $ejemplos[] = [
                    'codigo_de_barras' => (string) $barras,
                    'cantidad'         => $total,
                    'filas'            => $this->ejemplos[$barras] ?? [],
                ];
            }
        }

        // Los rellenos más frecuentes primero.
        arsort($this->valores_de_relleno);

        return [
            'grupos'                        => $grupos,
            'filas'                         => $filas,
            'filas_por_codigo_de_proveedor' => $por_proveedor,
            'filas_que_se_pisan'            => $filas - $por_proveedor,
            'codigos_de_relleno'            => $this->de_relleno,
            'valores_de_relleno'            => array_slice($this->valores_de_relleno, 0, 5, true),
            'ejemplos'                      => $ejemplos,
        ];
    }

    /**
     * ¿Esta fila es del caso 2 (se identifica por código de proveedor y no carga el de barras)?
     *
     * @param  array       $mapa                 Resultado de mapa_de_omision().
     * @param  string|null $codigo_de_barras     Ya normalizado.
     * @param  string|null $codigo_de_proveedor  Ya normalizado.
     * @return bool
     */
    public static function se_omite($mapa, $codigo_de_barras, $codigo_de_proveedor)
    {
        if (empty($mapa) || is_null($codigo_de_barras) || is_null($codigo_de_proveedor)) {
            return false;
        }

        return isset($mapa[(string) $codigo_de_barras][(string) $codigo_de_proveedor]);
    }
}
