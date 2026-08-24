<?php

namespace Tests\Unit\Pdf;

use PHPUnit\Framework\TestCase;

/**
 * Escanea los 42 archivos de app/Http/Controllers/Pdf/ buscando la familia de error que rompio
 * produccion el 4/8/2026 (grupo 324): una propiedad dinamica de $this leida antes de su primera
 * asignacion. PHP permite crear propiedades sobre la marcha, asi que la clase "funciona" mientras
 * el unico camino que la lee corra despues del que la escribe -- hasta que aparece un camino nuevo
 * que la lee antes, y ahi el notice se convierte en ErrorException (via HandleExceptions) y corta
 * la respuesta entera. Paso a paso del caso real: total_bruto en NewSalePdf.php.
 *
 * Extiende PHPUnit\Framework\TestCase directamente, NO Tests\TestCase: solo lee archivos con el
 * tokenizador de PHP (token_get_all), no necesita la aplicacion levantada ni base de datos, y
 * Tests\TestCase arrastra el guard de base de datos del grupo 269.
 *
 * @group pdf-estructura
 */
class PropiedadesDePdfInicializadasTest extends TestCase
{
    private const PDF_DIR = __DIR__ . '/../../../app/Http/Controllers/Pdf';
    private const FPDF_FILE = __DIR__ . '/../../../app/Http/Controllers/CommonLaravel/fpdf/fpdf.php';

    /**
     * Linea base de propiedades leidas-sin-inicializar, ya conocidas al 4/8/2026 (grupo 324),
     * generada corriendo este mismo escaner sobre los 41 archivos de Pdf/ que no son
     * NewSalePdf.php, con el prompt 01 ya aplicado. Casi todas son inofensivas: se asignan en un
     * metodo que siempre corre antes de leerse (por ejemplo, un metodo "build" o "generate" que el
     * constructor llama al final). El test de abajo NO exige que esta lista este vacia -- exige que
     * no CREZCA (regresion nueva) ni se ACHIQUE en silencio (linea base desactualizada). No
     * "arreglar" estas entradas tocando los 41 archivos: ese barrido es otra conversacion con
     * Lucas, este grupo solo corrige NewSalePdf y deja instalada la deteccion.
     *
     * @var array<string, string[]>
     */
    private const LINEA_BASE = [
        'AcopioArticleDeliveryPdf.php' => [],
        'Afip/AfipPdfHelper.php' => [],
        'Afip/LibroIvaCompraPdf.php' => [],
        'Afip/LibroIvaVentaPdf.php' => [],
        'Afip/TicketInfoHelper.php' => [],
        'AfipQrPdf.php' => [],
        'AfipTicketPdf.php' => [],
        'ArticleBarCodePdf.php' => ['articles', 'column_count', 'x_inicial'],
        'ArticleListImagePdf.php' => ['articles'],
        'ArticleListPdf.php' => ['articles'],
        'ArticleOfferSheetPdf.php' => [],
        'ArticlePdf/TruvariArticleListPdf.php' => [],
        'ArticleTablePdf.php' => [],
        'ArticleTicket/2rBarCodeEtiquetas.php' => [],
        'ArticleTicket/ArticleBarCodeEtiquetasPdf.php' => ['articles'],
        'ArticleTicket/Golonorte.php' => ['start_x', 'start_y'],
        'ArticleTicketPdf.php' => ['articles', 'start_x', 'start_y'],
        'BudgetPdf.php' => [],
        'ClientsPdf.php' => [],
        'CurrentAcount/NewPagoPdf.php' => [],
        'CurrentAcountPdf.php' => ['y_final_commerce_line'],
        'DepositMovementPdf.php' => [],
        'EtiquetaEnvioPdf.php' => [],
        'NotaCreditoPdf.php' => [],
        'OrderPdf.php' => [],
        'OrderProductionArticlesPdf.php' => [],
        'OrderProductionPdf.php' => [],
        'PagoPdf.php' => [],
        'ProviderOrderPdf.php' => [],
        // Alta del 21/8/2026 (sistema de puntos). Entra con [] a proposito: es la entrada mas
        // estricta posible, o sea que el escaner no encuentra NINGUNA propiedad leida sin
        // inicializar. Si alguna vez aparece una, este mismo test se pone rojo.
        'Puntos/PuntosComprobanteHelper.php' => [],
        'ResumenCajaPdf.php' => ['sale'],
        'RoadMapPdf.php' => [],
        'SaleAfipTicketPdf.php' => ['afip_information', 'for_commerce', 'total_pages'],
        'SaleDeliveredArticlesPdf.php' => [],
        'SalePdf.php' => ['afip_helper'],
        'SaleTicketPdf.php' => ['total_sale'],
        'SaleTicketRaw.php' => [],
        'SuperBudgetPdf.php' => [],
        '__base.php' => ['budget', 'with_prices'],
        'reportes/ClientesPdf.php' => [],
        'reportes/InventarioPdf.php' => [],
        'reportes/ReportePdf.php' => ['colors', 'data', 'from_date', 'until_date'],
    ];

    /** @test */
    public function new_sale_pdf_no_lee_ninguna_propiedad_sin_inicializar()
    {
        $encontradas = $this->scan_property_reads_without_init(self::PDF_DIR . '/NewSalePdf.php');

        $this->assertSame(
            [],
            $encontradas,
            'NewSalePdf.php lee sin inicializar: ' . implode(', ', $encontradas) . '. '
            . 'Esta es la clase que rompio produccion el 4/8/2026 (Undefined property: total_bruto) '
            . '-- inicializar la propiedad en el constructor, junto a sus hermanas. No envolver la '
            . 'lectura en isset()/property_exists(): eso tapa la asimetria en vez de corregirla.'
        );
    }

    /** @test */
    public function ningun_pdf_suma_propiedades_sin_inicializar_respecto_de_la_linea_base()
    {
        $actual = [];
        foreach ($this->pdf_files() as $file) {
            if (basename($file) === 'NewSalePdf.php') {
                // NewSalePdf tiene su propio test, con asercion dura de lista vacia.
                continue;
            }
            $relative = $this->relative_name($file);
            $actual[$relative] = $this->scan_property_reads_without_init($file);
        }

        foreach (self::LINEA_BASE as $file => $baselineProps) {
            $actualProps = $actual[$file] ?? [];

            $nuevas = array_values(array_diff($actualProps, $baselineProps));
            $this->assertSame(
                [],
                $nuevas,
                "$file suma propiedad(es) leida(s) sin inicializar respecto de la linea base: "
                . implode(', ', $nuevas) . '. Familia de error: propiedad dinamica de $this leida '
                . 'antes de su primera asignacion (rompio produccion el 4/8/2026 con total_bruto en '
                . 'NewSalePdf). Inicializar la propiedad en el constructor, no envolver la lectura '
                . 'en isset()/property_exists().'
            );

            $desaparecidas = array_values(array_diff($baselineProps, $actualProps));
            $this->assertSame(
                [],
                $desaparecidas,
                "$file bajo su cantidad de propiedades sin inicializar (ya no aparece: "
                . implode(', ', $desaparecidas) . '). Mejor para el codigo, pero la linea base de '
                . 'este test tiene que bajarse para que siga midiendo: actualizar self::LINEA_BASE '
                . 'en ' . __CLASS__ . '.'
            );
        }

        $archivosNuevos = array_diff(array_keys($actual), array_keys(self::LINEA_BASE));
        $this->assertSame(
            [],
            array_values($archivosNuevos),
            'Aparecieron archivos nuevos bajo app/Http/Controllers/Pdf/ que no estan en la linea '
            . 'base de este test: ' . implode(', ', $archivosNuevos) . '. Agregarlos a self::LINEA_BASE '
            . '(con [] si el escaner no encuentra nada, o con la lista real si encuentra algo).'
        );
    }

    /**
     * Devuelve los nombres de propiedad que $file lee via $this-> en algun lugar del archivo, sin
     * que: (a) esten declaradas como propiedad de la clase, (b) se les asigne algo dentro de
     * __construct(), o (c) sean heredadas de FPDF.
     *
     * @return string[]
     */
    private function scan_property_reads_without_init(string $file): array
    {
        $tokens = token_get_all(file_get_contents($file));
        $accesses = $this->this_property_accesses($tokens);

        $reads = [];
        foreach ($accesses as $access) {
            if ($access['reads']) {
                $reads[] = $access['name'];
            }
        }
        $reads = array_unique($reads);

        $declared = $this->declared_properties($tokens);
        $constructorWrites = $this->constructor_writes($tokens, $accesses);
        $fpdfInherited = $this->fpdf_declared_properties();

        $result = array_values(array_diff($reads, $declared, $constructorWrites, $fpdfInherited));
        sort($result);
        return $result;
    }

    /**
     * Recorre los tokens buscando la secuencia $this -> nombre y clasifica cada aparicion segun el
     * token que sigue: '(' es llamada a metodo (se ignora), '=' simple (ni '==', '===' ni '=>', que
     * son tokens distintos) es escritura, un operador compuesto (+=, -=, *=, /=, .=) es lectura y
     * escritura a la vez, y cualquier otra cosa es lectura.
     *
     * @return array<int, array{name: string, index: int, reads: bool, writes: bool}>
     */
    private function this_property_accesses(array $tokens): array
    {
        $accesses = [];
        $count = count($tokens);
        $compoundOps = [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$this') {
                continue;
            }

            $j = $this->next_significant($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $k = $this->next_significant($tokens, $j + 1);
            if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
                continue;
            }

            $name = $tokens[$k][1];
            $m = $this->next_significant($tokens, $k + 1);
            if ($m === null) {
                continue;
            }
            $next = $tokens[$m];

            if ($next === '(') {
                continue;
            }

            if (is_array($next) && in_array($next[0], $compoundOps, true)) {
                $accesses[] = ['name' => $name, 'index' => $k, 'reads' => true, 'writes' => true];
                continue;
            }

            if ($next === '=') {
                $accesses[] = ['name' => $name, 'index' => $k, 'reads' => false, 'writes' => true];
                continue;
            }

            $accesses[] = ['name' => $name, 'index' => $k, 'reads' => true, 'writes' => false];
        }

        return $accesses;
    }

    /**
     * Nombres de propiedad escritas dentro del cuerpo de __construct() (sea escritura simple o
     * compuesta). Solo estas cuentan como "inicializadas" para este escaner -- una asignacion en
     * otro metodo no alcanza, porque es exactamente el patron que rompio produccion.
     *
     * @param array<int, array{name: string, index: int, reads: bool, writes: bool}> $accesses
     * @return string[]
     */
    private function constructor_writes(array $tokens, array $accesses): array
    {
        $range = $this->method_body_range($tokens, '__construct');
        if ($range === null) {
            return [];
        }
        [$start, $end] = $range;

        $names = [];
        foreach ($accesses as $access) {
            if ($access['writes'] && $access['index'] >= $start && $access['index'] <= $end) {
                $names[] = $access['name'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Indices [inicio, fin] (ambos inclusive) de los tokens '{' y '}' que delimitan el cuerpo del
     * primer metodo con nombre $methodName. null si no existe o no tiene cuerpo (interfaz/abstracto).
     *
     * @return array{0:int,1:int}|null
     */
    private function method_body_range(array $tokens, string $methodName): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $j = $this->next_significant($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING
                || $tokens[$j][1] !== $methodName) {
                continue;
            }

            // Saltear la lista de parametros (todo lo que hay entre parentesis) para llegar a la
            // '{' de apertura del cuerpo sin confundirla con un '{' de un default de parametro.
            $depth = 0;
            $bodyStart = null;
            for ($p = $j; $p < $count; $p++) {
                $t = $tokens[$p];
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    $depth--;
                } elseif ($depth === 0 && $t === '{') {
                    $bodyStart = $p;
                    break;
                } elseif ($depth === 0 && $t === ';') {
                    return null;
                }
            }
            if ($bodyStart === null) {
                return null;
            }

            $braceDepth = 0;
            for ($p = $bodyStart; $p < $count; $p++) {
                if ($tokens[$p] === '{') {
                    $braceDepth++;
                } elseif ($tokens[$p] === '}') {
                    $braceDepth--;
                    if ($braceDepth === 0) {
                        return [$bodyStart, $p];
                    }
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Nombres de propiedades declaradas a nivel de clase (public/protected/private/var, con o sin
     * static, incluyendo listas "public $a, $b;"). Ignora metodos y constantes: si despues del
     * modificador (y de un eventual "static") no viene una T_VARIABLE, no es una declaracion de
     * propiedad.
     *
     * @return string[]
     */
    private function declared_properties(array $tokens): array
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR];
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], $modifiers, true)) {
                continue;
            }

            $j = $this->next_significant($tokens, $i + 1);
            while ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STATIC) {
                $j = $this->next_significant($tokens, $j + 1);
            }

            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_VARIABLE) {
                continue;
            }

            $depth = 0;
            $expectVar = true;
            for ($p = $j; $p < $count; $p++) {
                $t = $tokens[$p];
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    continue;
                }
                if ($depth > 0) {
                    continue;
                }
                if ($t === ';') {
                    break;
                }
                if ($t === ',') {
                    $expectVar = true;
                    continue;
                }
                if ($expectVar && is_array($t) && $t[0] === T_VARIABLE) {
                    $names[] = ltrim($t[1], '$');
                    $expectVar = false;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Propiedades declaradas en FPDF, la clase base de todos los archivos de Pdf/. Se cachea en un
     * static porque los 42 archivos del escaneo general la consultan una vez cada uno.
     *
     * @return string[]
     */
    private function fpdf_declared_properties(): array
    {
        static $cache = null;
        if ($cache === null) {
            $tokens = token_get_all(file_get_contents(self::FPDF_FILE));
            $cache = $this->declared_properties($tokens);
        }
        return $cache;
    }

    /** Indice del proximo token que no sea whitespace ni comentario, o null si no hay. */
    private function next_significant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], $skip, true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** @return string[] rutas absolutas de todos los .php bajo Pdf/, recursivo, orden estable */
    private function pdf_files(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->pdf_dir_real(), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function pdf_dir_real(): string
    {
        static $real = null;
        if ($real === null) {
            $real = realpath(self::PDF_DIR);
        }
        return $real;
    }

    private function relative_name(string $file): string
    {
        $prefix = $this->pdf_dir_real() . DIRECTORY_SEPARATOR;
        return str_replace('\\', '/', str_replace($prefix, '', $file));
    }
}
