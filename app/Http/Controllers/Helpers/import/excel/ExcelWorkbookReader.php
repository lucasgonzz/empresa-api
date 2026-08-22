<?php

namespace App\Http\Controllers\Helpers\import\excel;

use Illuminate\Support\Facades\Log;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Puerta unica de entrada a un libro de Excel para todo el import (articulos,
 * clientes y proveedores).
 *
 * Reemplaza el patron `foreach ($reader->getSheetIterator() as $sheet) { ...; break; }`
 * que estaba copiado en doce lectores: ese `break` es "siempre la primera hoja", y como
 * nadie lo elegia ni lo veia, un libro con la lista de precios en la hoja 2 se importaba
 * vacio sin un solo error en pantalla.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class ExcelWorkbookReader
{
    /**
     * Mensaje unico que ve el usuario cuando el archivo no se puede abrir.
     *
     * NO LLEVA LA RUTA DEL ARCHIVO A PROPOSITO, y no es un detalle de estilo.
     * La IOException de OpenSpout dice "Could not open C:\...\storage\app\excel\xxx.xls
     * for reading!": ese texto subia entero hasta el catch (\Throwable) de
     * RunExcelAnalysisJob, que lo concatenaba al mensaje que se le muestra al usuario, y
     * terminabamos publicando la ruta absoluta del servidor en la pantalla de un cliente.
     * La ruta va al Log::warning de abrir(), que es donde sirve.
     */
    const MENSAJE_ARCHIVO_ILEGIBLE = 'No pudimos abrir el archivo. Si es un Excel viejo (.xls), abrilo en Excel y guardalo como .xlsx ("Guardar como" -> "Libro de Excel (*.xlsx)"). Después volvé a subirlo.';

    /**
     * Hojas del libro, en el mismo orden y con el mismo indice que va a usar despues
     * abrir().
     *
     * Los nombres y los indices los da OPENSPOUT, no otra libreria, y eso es una
     * decision, no una casualidad: quien despues lee las filas es OpenSpout, asi que el
     * indice que le pasamos tiene que salir de la misma fuente que lo consume. Listar con
     * PhpSpreadsheet y leer con OpenSpout desalinea el indice ante cualquier diferencia de
     * criterio (chartsheets, hojas ocultas) y se importa la hoja equivocada EN SILENCIO —
     * la misma familia de defectos que este namespace vino a matar.
     *
     * La cantidad de filas la pone ExcelSheetInspector (ZIP + XMLReader, streaming). Si el
     * inspector no puede leer el zip devuelve null y aca sale 'filas' => 0: listar hojas no
     * rompe por no poder contarlas.
     *
     * @param  string $excel_path
     * @return array  [['indice' => 0, 'nombre' => 'Lista', 'filas' => 1204], ...]
     *
     * @throws \RuntimeException  mensaje limpio si el archivo no abre
     */
    public static function listar_hojas($excel_path)
    {
        $filas_por_hoja = ExcelSheetInspector::filas_por_hoja($excel_path);

        $reader = self::abrir_reader($excel_path, true);

        $hojas = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $indice = $sheet->getIndex();

            $hojas[] = [
                'indice' => $indice,
                'nombre' => $sheet->getName(),
                'filas'  => ($filas_por_hoja !== null && isset($filas_por_hoja[$indice]))
                    ? $filas_por_hoja[$indice]
                    : 0,
            ];
        }

        $reader->close();

        return $hojas;
    }

    /**
     * Resuelve el indice 0-based de hoja a usar.
     *
     * Prioridad: nombre exacto -> indice en rango -> 0. El nombre gana porque el indice
     * puede venir calculado por SheetJS en el navegador y no esta garantizado que coincida
     * con el de OpenSpout cuando el libro tiene chartsheets de por medio (ver T11 del plan).
     * Nunca devuelve un indice fuera de rango.
     *
     * @param  string      $excel_path
     * @param  int|null    $hoja
     * @param  string|null $hoja_nombre
     * @return int
     */
    public static function resolver_indice($excel_path, $hoja, $hoja_nombre)
    {
        $hojas = self::listar_hojas($excel_path);

        if (count($hojas) === 0) {
            return 0;
        }

        if ($hoja_nombre !== null && trim((string) $hoja_nombre) !== '') {
            $buscado = trim((string) $hoja_nombre);

            foreach ($hojas as $candidata) {
                if ($candidata['nombre'] === $buscado) {
                    return $candidata['indice'];
                }
            }
        }

        if ($hoja !== null && is_numeric($hoja)) {
            $indice = (int) $hoja;

            foreach ($hojas as $candidata) {
                if ($candidata['indice'] === $indice) {
                    return $indice;
                }
            }
        }

        return 0;
    }

    /**
     * Abre el libro y devuelve la hoja $indice ya posicionada.
     *
     * @param  string $excel_path
     * @param  int    $indice                  0-based
     * @param  bool   $preservar_filas_vacias  el mismo setShouldPreserveEmptyRows de siempre
     * @return LecturaDeHoja
     *
     * @throws \RuntimeException  mensaje limpio, SIN la ruta del servidor
     */
    public static function abrir($excel_path, $indice = 0, $preservar_filas_vacias = true)
    {
        $indice = (int) $indice;

        if ($indice < 0) {
            $indice = 0;
        }

        $reader = self::abrir_reader($excel_path, $preservar_filas_vacias);

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getIndex() === $indice) {
                /*
                 * Se corta con return, no dejando avanzar el foreach: SheetIterator::next()
                 * llama getRowIterator()->end() sobre la hoja que deja atras, asi que salir
                 * del loop es lo unico que conserva viva la hoja elegida. Saltear las hojas
                 * anteriores con el foreach NO lee sus filas (solo las cierra), verificado
                 * en vendor/openspout/.../SheetIterator.php.
                 */
                return new LecturaDeHoja($reader, $sheet);
            }
        }

        $reader->close();

        if ($indice !== 0) {
            /*
             * Indice fuera de rango: se degrada a la hoja 0 en vez de lanzar. El llamador
             * normal ya paso por resolver_indice(); esto cubre a quien llame directo con un
             * indice viejo guardado en el payload de un analisis anterior, y el resultado
             * es el comportamiento de siempre (primera hoja), no una pantalla de error.
             *
             * Se reabre el libro en vez de reusar la primera hoja del foreach de arriba
             * porque al avanzar el iterador su row iterator ya quedo cerrado.
             */
            return self::abrir($excel_path, 0, $preservar_filas_vacias);
        }

        /* Libro sin hojas: OpenSpout ya deberia haber lanzado, pero no lo damos por hecho. */
        throw new \RuntimeException(self::MENSAJE_ARCHIVO_ILEGIBLE);
    }

    /**
     * Abre el reader de OpenSpout traduciendo cualquier falla al mensaje limpio.
     *
     * Este es el UNICO lugar de todo el namespace que lanza. ExcelSheetInspector nunca
     * lanza (devuelve null), justamente para que el mensaje que llega al usuario salga
     * siempre de aca y sea siempre el mismo.
     *
     * @param  string $excel_path
     * @param  bool   $preservar_filas_vacias
     * @return \OpenSpout\Reader\XLSX\Reader
     *
     * @throws \RuntimeException
     */
    protected static function abrir_reader($excel_path, $preservar_filas_vacias)
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->setShouldPreserveEmptyRows((bool) $preservar_filas_vacias);

        try {
            $reader->open($excel_path);
        } catch (\Throwable $e) {
            Log::warning('ExcelWorkbookReader: no se pudo abrir el archivo', [
                'excel_path' => $excel_path,   // la ruta va al log, NO al usuario
                'message'    => $e->getMessage(),
            ]);

            throw new \RuntimeException(self::MENSAJE_ARCHIVO_ILEGIBLE);
        }

        return $reader;
    }
}
