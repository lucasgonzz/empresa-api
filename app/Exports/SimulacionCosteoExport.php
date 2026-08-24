<?php

namespace App\Exports;

use App\Http\Controllers\Helpers\Numbers;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export de 3 hojas (Resumen, Detalle, Ejemplos) para el comando `costeo:simular` (grupo 231,
 * prompt 04). Muestra como quedarian el costo real y el precio final de los articulos de una
 * cuenta si se activara la dinamica de costeo por condicion fiscal, sin haber tocado la base.
 *
 * Mismo patron que `AperturaCajaExport`: una clase orquestadora con `WithMultipleSheets` y una
 * clase por hoja, todas en el mismo archivo.
 */
class SimulacionCosteoExport implements WithMultipleSheets
{
    /**
     * Datos ya calculados por el comando: ['resumen' => array, 'detalle' => array, 'ejemplos' => array].
     *
     * @var array
     */
    protected $datos;

    /**
     * @param array $datos Estructura armada por SimularCosteoCondicionFiscal::handle().
     */
    public function __construct($datos)
    {
        $this->datos = $datos;
    }

    /**
     * Arma las 3 hojas del Excel, en el orden en que las va a mirar el cliente: primero el
     * resumen ejecutivo, despues el detalle articulo por articulo, y por ultimo los ejemplos
     * paso a paso para quien quiera entender de donde salen los numeros.
     *
     * @return array
     */
    public function sheets(): array
    {
        return [
            new SimulacionCosteoResumenSheet($this->datos['resumen']),
            new SimulacionCosteoDetalleSheet($this->datos['detalle']),
            new SimulacionCosteoEjemplosSheet($this->datos['ejemplos']),
        ];
    }
}

/**
 * Hoja "Resumen": lo unico que la mayoria de los clientes va a mirar. Separa a proposito
 * "cambia el costo real" de "cambia el precio final": son dos conversaciones distintas.
 */
class SimulacionCosteoResumenSheet implements FromCollection, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * Estadisticas ya calculadas por SimularCosteoCondicionFiscal::armar_resumen().
     *
     * @var array
     */
    protected $resumen;

    public function __construct($resumen)
    {
        $this->resumen = $resumen;
    }

    /**
     * Una sola "fila" de entrada: se mapea a multiples filas de par [etiqueta, valor] en map().
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return collect([$this->resumen]);
    }

    /**
     * Arma las filas de etiqueta/valor de la hoja. La ultima fila (articulos cuyo precio final NO
     * cambia) es la fila destacada que pide la especificacion: es el dato que mas tranquiliza al
     * cliente antes de migrar.
     *
     * @param array $resumen
     * @return array
     */
    public function map($resumen): array
    {
        return [
            ['Cuenta', $resumen['nombre_cuenta']],
            ['Condición fiscal', $resumen['condicion_fiscal']],
            ['Artículos analizados', $resumen['cantidad_articulos']],
            ['Artículos que cambian el costo real', $resumen['cambian_costo']],
            ['Variación promedio del costo real', $this->formatear_porcentaje($resumen['variacion_promedio_costo'])],
            ['Variación máxima del costo real', $this->formatear_porcentaje($resumen['variacion_maxima_costo'])],
            ['Artículos que cambian el precio final', $resumen['cambian_precio']],
            ['Variación promedio del precio final', $this->formatear_porcentaje($resumen['variacion_promedio_precio'])],
            ['Variación máxima del precio final', $this->formatear_porcentaje($resumen['variacion_maxima_precio'])],
            ['Artículos cuyo precio final NO cambia', $resumen['no_cambian_precio']],
        ];
    }

    /**
     * Formatea una variación porcentual con dos decimales y signo '%'.
     *
     * @param float $valor
     * @return string
     */
    protected function formatear_porcentaje($valor) {
        return number_format((float) $valor, 2, ',', '.').'%';
    }

    /**
     * Negrita en la primera fila y en la fila destacada (conteo de precios que NO cambian).
     *
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1  => ['font' => ['bold' => true]],
            10 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Resumen';
    }
}

/**
 * Hoja "Detalle": una fila por articulo, la que el cliente va a filtrar para buscar sus productos.
 */
class SimulacionCosteoDetalleSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * Filas ya calculadas por SimularCosteoCondicionFiscal::handle() (una por articulo).
     *
     * @var array
     */
    protected $detalle;

    public function __construct($detalle)
    {
        $this->detalle = $detalle;
    }

    public function collection()
    {
        return collect($this->detalle);
    }

    public function headings(): array
    {
        return [
            'Código',
            'Nombre',
            'Costo base',
            'Costo real actual',
            'Costo real nuevo',
            'Variación costo real',
            'Precio final actual',
            'Precio final nuevo',
            'Variación precio final',
        ];
    }

    /**
     * Mapea una fila del array de detalle al orden de columnas de headings(). Montos formateados
     * con Numbers::price(), igual que el resto de los exports del repo.
     *
     * @param array $fila
     * @return array
     */
    public function map($fila): array
    {
        return [
            $fila['codigo'],
            $fila['nombre'],
            !is_null($fila['costo_base']) ? Numbers::price($fila['costo_base'], true) : '',
            !is_null($fila['costo_real_actual']) ? Numbers::price($fila['costo_real_actual'], true) : '',
            !is_null($fila['costo_real_nuevo']) ? Numbers::price($fila['costo_real_nuevo'], true) : '',
            $this->formatear_variacion($fila['variacion_costo']),
            !is_null($fila['final_price_actual']) ? Numbers::price($fila['final_price_actual'], true) : '',
            !is_null($fila['final_price_nuevo']) ? Numbers::price($fila['final_price_nuevo'], true) : '',
            $this->formatear_variacion($fila['variacion_precio']),
        ];
    }

    /**
     * Formatea una variación porcentual, o '' si no se pudo calcular (sin base valida).
     *
     * @param float|null $valor
     * @return string
     */
    protected function formatear_variacion($valor) {
        if (is_null($valor)) {
            return '';
        }
        return number_format((float) $valor, 2, ',', '.').'%';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Detalle';
    }
}

/**
 * Hoja "Ejemplos": para los N articulos de muestra, el calculo paso a paso en las dos dinamicas
 * (actual/legacy y nueva/condicion fiscal), una linea por paso. El texto sale tal cual del array
 * $des que ArticleHelper::setFinalPrice() arma con $return_description = true: no se reescribe a
 * mano para que no se desincronice del calculo real cuando el pipeline cambie.
 */
class SimulacionCosteoEjemplosSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    /**
     * Uno por articulo de muestra: ['articulo' => string, 'des_actual' => array, 'des_nuevo' => array].
     *
     * @var array
     */
    protected $ejemplos;

    public function __construct($ejemplos)
    {
        $this->ejemplos = $ejemplos;
    }

    /**
     * Expande cada ejemplo en filas: una fila por linea de descripcion, de cada una de las dos
     * dinamicas, para que la hoja quede en formato tabular (una linea por paso).
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $filas = collect();

        foreach ($this->ejemplos as $ejemplo) {

            foreach ($ejemplo['des_actual'] as $paso) {
                $filas->push([
                    'articulo'  => $ejemplo['articulo'],
                    'dinamica'  => 'Actual (legacy)',
                    'paso'      => $paso,
                ]);
            }

            foreach ($ejemplo['des_nuevo'] as $paso) {
                $filas->push([
                    'articulo'  => $ejemplo['articulo'],
                    'dinamica'  => 'Nueva (condición fiscal)',
                    'paso'      => $paso,
                ]);
            }
        }

        return $filas;
    }

    public function headings(): array
    {
        return [
            'Artículo',
            'Dinámica',
            'Paso a paso',
        ];
    }

    public function map($fila): array
    {
        return [
            $fila['articulo'],
            $fila['dinamica'],
            $fila['paso'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Ejemplos';
    }
}
