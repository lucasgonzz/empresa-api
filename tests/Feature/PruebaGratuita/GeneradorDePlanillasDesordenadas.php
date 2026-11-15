<?php

namespace Tests\Feature\PruebaGratuita;

/**
 * Arma, con una semilla fija, planillas de artículos y listas de contactos como las que manda un dueño en la prueba
 * (misión prueba-e2e-campana-imperio, ronda 6, E8.3, 14/11/2026), y dice cuáles filas son datos reales y cuáles no.
 * Lo usan ImportacionDePruebaFilasQueNoSonDatosGenerativoTest (en memoria, cientos de planillas) e
 * ImportacionDePruebaFilasQueNoSonDatosTest (unas pocas, por la carga entera, con membrete y cabecera corrida).
 *
 * Las combinaciones que la regla NO puede decidir por la forma de la fila quedan afuera del generador a propósito,
 * cada una con su porqué en el lugar donde se excluye (buscá «EXCLUIDO»).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class GeneradorDePlanillasDesordenadas
{
    const RUBROS = ['CONSTRUCCION', 'PLOMERIA', 'PINTURERIA', 'BULONERIA', 'ELECTRICIDAD', 'SANITARIOS', 'HERRAMIENTAS MANUALES', 'FERRETERÍA'];
    const ARTICULOS = ['Martillo carpintero', 'Pinza universal', 'Cemento portland 50kg', 'Caño PVC 110 x 4m', 'Látex exterior 20L',
        'Tornillo T1 punta aguja x100', 'Llave de paso 1/2', 'Cable unipolar 2.5mm', 'Codo PVC 110 a 90', 'Arena fina x bolsa',
        'Rodillo lana 22cm', 'Tarugo nylon 8mm x50', 'Disyuntor 2x40A', 'Lija al agua grano 180', 'Clavo punta parís 2" x kg'];
    const NOTAS = ['Actualizó: Mirta 12/11', 'Precios sujetos a cambio sin previo aviso', 'Consultar stock antes de vender',
        'Lista vigente al 14/11/2026', 'Firma: Carlos'];
    const NOMBRES = ['Constructora Del Litoral SRL', 'Gómez, Jorge (albañil)', 'Plomería El Caño', 'Municipalidad - Obras',
        'Rosa Fernández', 'Taller Mecánico Ruta 14', 'Sandra Ojeda', 'Ferretería López', 'Juan Pérez', 'Corralón Sur',
        'Marta Giménez', 'Electricidad Gutiérrez', 'Pinturería Colón', 'Hugo Benítez', 'Distribuidora Paraná'];
    const TITULOS_DE_SECCION = ['Zona norte:', 'Zona centro', 'Morosos', 'Clientes del interior', 'AL DÍA:', 'Deudores', 'Lista mayoristas',
        'Proveedores de obra:', 'Cuentas viejas'];
    const NOTAS_DE_CONTACTOS = ['Actualizado el 14/11', 'Pedir a Marta los que faltan', 'Revisar con el contador'];

    private static function al_azar(array $opciones)
    {
        return $opciones[mt_rand(0, count($opciones) - 1)];
    }

    private static function moneda(): bool
    {
        return mt_rand(0, 1) === 1;
    }

    /**
     * Una planilla de artículos. Columnas: A código (o nada), B nombre, C precio, D stock, E «Obs» sin mapear y,
     * a veces, F rubro como columna (categoría).
     *
     * @return array{filas: array, inicio: int, mapeo: array, titulos: array, datos: int[], no_datos: int[], con_codigos: bool, cabecera: int}
     */
    public static function articulos(int $caso): array
    {
        $con_codigos = mt_rand(0, 3) > 0;
        $con_rubro_en_columna = mt_rand(0, 4) === 0;
        $forma_del_codigo = mt_rand(0, 3); // 0 «A-0012», 1 «0034», 2 número, 3 mezclados
        $cabecera = $con_codigos ? ['Código', 'Descripción', 'Precio de venta', 'Stock', 'Obs'] : [null, 'Producto', 'Precio de venta', 'Stock', 'Obs'];
        if ($con_rubro_en_columna) {
            $cabecera[] = 'Rubro';
        }
        $mapeo = ['nombre' => 1, 'precio' => 2, 'stock_actual' => 3];
        if ($con_codigos) {
            $mapeo['codigo_de_proveedor'] = 0;
        }
        if ($con_rubro_en_columna) {
            $mapeo['categoria'] = 5;
        }
        $ancho = count($cabecera);
        $vacia = array_fill(0, $ancho, null);

        $filas = [];
        $datos = [];
        $no_datos = [];
        $numero = 0;
        $agregar = function (array $fila, $es_dato) use (&$filas, &$datos, &$no_datos, &$numero, $vacia) {
            $numero++;
            $filas[$numero] = array_replace($vacia, $fila);
            if ($es_dato === true) {
                $datos[] = $numero;
            } elseif ($es_dato === false) {
                $no_datos[] = $numero;
            }
        };

        // Membrete: 0 a 6 filas arriba de la cabecera (título, fecha, vacías). No se juzgan: el análisis las saltea.
        $membrete = mt_rand(0, 6);
        for ($i = 0; $i < $membrete; $i++) {
            $agregar($i === 0 ? [0 => 'FERRETERIA EL TORNILLO - LISTA NOVIEMBRE'] : ($i === 1 ? [0 => 'Actualizada al 14/11/2026'] : []), null);
        }
        $agregar($cabecera, null);
        $fila_de_cabecera = $numero;

        /*
         * EXCLUIDO: en una hoja con códigos, menos de 12 artículos. Con menos de 10 la hoja no cuenta como «con
         * códigos» (ver PlanillaDePrueba::resumir_la_hoja()) y un rubro en mayúsculas en la columna del nombre no se
         * distingue de un artículo sin precio. 12 deja margen para 4 rubros en la columna del nombre (3 de cada 4).
         */
        $cuantos = $con_codigos ? mt_rand(12, 30) : mt_rand(6, 30);
        $rubros = mt_rand(0, 4);
        $cada = (int) max(1, floor($cuantos / max(1, $rubros)));
        $codigo = 11;
        $stock_del_rubro = 0;
        $rubro_actual = self::al_azar(self::RUBROS);
        $repetir_cabecera_en = mt_rand(0, 3) === 0 ? mt_rand(2, $cuantos - 1) : -1;
        $con_subtotales = $con_codigos && mt_rand(0, 1) === 1;
        $articulos_en_el_rubro = 0;

        for ($i = 0; $i < $cuantos; $i++) {
            if ($rubros > 0 && $i % $cada === 0 && $i / $cada < $rubros) {
                // Subtotal del rubro anterior, si corresponde.
                if ($con_subtotales && $articulos_en_el_rubro > 0) {
                    self::subtotal($agregar, $rubro_actual, $stock_del_rubro);
                }
                $rubro_actual = self::al_azar(self::RUBROS);
                $stock_del_rubro = 0;
                $articulos_en_el_rubro = 0;
                self::rubro($agregar, $rubro_actual, $con_codigos);
            }
            if ($i === $repetir_cabecera_en) {
                $agregar($cabecera, false);
            }
            if (mt_rand(0, 6) === 0) {
                $agregar([], null);
            }

            $forma = $forma_del_codigo === 3 ? mt_rand(0, 2) : $forma_del_codigo;
            $codigo += mt_rand(1, 3);
            $valor_codigo = $forma === 0 ? 'A-' . str_pad((string) $codigo, 4, '0', STR_PAD_LEFT)
                : ($forma === 1 ? str_pad((string) $codigo, 4, '0', STR_PAD_LEFT) : 1000 + $codigo);
            $precio = round(500 + mt_rand(0, 90000) / 7, 2);
            $stock_tipo = mt_rand(0, 5);
            $stock = $stock_tipo === 0 ? null : ($stock_tipo === 1 ? '-' : mt_rand(-2, 120));
            $stock_del_rubro += is_int($stock) ? $stock : 0;
            $fila = [
                1 => self::al_azar(self::ARTICULOS) . ' ' . ($i + 1),
                2 => self::moneda() ? $precio : '$ ' . number_format($precio, 2, ',', '.'),
                3 => $stock,
            ];
            if ($con_codigos) {
                $fila[0] = $valor_codigo;
            }
            if ($con_rubro_en_columna) {
                $fila[5] = ucfirst(strtolower($rubro_actual));
            }
            $agregar($fila, true);
            $articulos_en_el_rubro++;
        }
        if ($con_subtotales && $rubros > 0 && $articulos_en_el_rubro > 0) {
            self::subtotal($agregar, $rubro_actual, $stock_del_rubro);
        }

        /*
         * EXCLUIDO: en una hoja SIN códigos, notas al pie sin un total arriba. Sin códigos, una fila con un solo texto
         * al final («Consultar stock») no se distingue de un artículo sin precio: solo el total abre el pie. Tampoco
         * hay rubros en mayúsculas sin decorar ni subtotales en el medio de una hoja sin códigos: ahí la falta de
         * código no dice nada (decisión de E8, fijada en ImportacionDePruebaFilasQueNoSonDatosTest).
         */
        $con_total = $con_codigos ? mt_rand(0, 3) > 0 : mt_rand(0, 3) > 0;
        if ($con_total) {
            $rotulo = self::al_azar(['TOTAL', 'TOTALES', 'TOTAL GENERAL', 'Total artículos: ' . $cuantos, 'Son ' . $cuantos . ' artículos']);
            $columna = $con_codigos && mt_rand(0, 3) === 0 ? 0 : 1;
            $agregar([$columna => $rotulo, 3 => mt_rand(0, 1) === 1 ? mt_rand(100, 900) : null], false);
        }
        $notas = ($con_codigos || $con_total) ? mt_rand(0, 3) : 0;
        for ($i = 0; $i < $notas; $i++) {
            if (mt_rand(0, 2) === 0) {
                $agregar([], null);
            }
            $columna = $con_codigos && mt_rand(0, 1) === 1 ? 0 : 1;
            $agregar([$columna => self::al_azar(self::NOTAS)], false);
        }

        $titulos = [];
        foreach ($cabecera as $posicion => $titulo) {
            if ($titulo !== null) {
                $titulos[$posicion] = \App\Services\Importacion\PlanillaDePrueba::titulo_comparable($titulo);
            }
        }

        return [
            'filas' => $filas, 'inicio' => $fila_de_cabecera + 1, 'mapeo' => $mapeo, 'titulos' => $titulos, 'datos' => $datos,
            'no_datos' => $no_datos, 'con_codigos' => $con_codigos, 'cabecera' => $fila_de_cabecera, 'caso' => $caso,
        ];
    }

    private static function rubro(callable $agregar, string $rubro, bool $con_codigos): void
    {
        $bonito = ucfirst(mb_strtolower($rubro));
        /*
         * EXCLUIDO sin códigos: el rubro en mayúsculas sin decorar (forma 2 y 3) — ver arriba. Sin códigos solo van
         * el decorado y «Rubro: X», que se reconocen por cómo están escritos.
         */
        $forma = $con_codigos ? mt_rand(0, 4) : self::al_azar([0, 1, 4]);
        if ($forma === 0) {
            $agregar([($con_codigos && mt_rand(0, 1) === 1 ? 0 : 1) => '--- ' . $rubro . ' ---'], false);
        } elseif ($forma === 1) {
            $agregar([1 => '*** ' . $bonito . ' ***'], false);
        } elseif ($forma === 2) {
            $agregar([1 => $rubro], false);
        } elseif ($forma === 3) {
            $agregar([0 => $rubro], false);
        } else {
            $agregar([($con_codigos && mt_rand(0, 1) === 1 ? 0 : 1) => 'Rubro: ' . $bonito], false);
        }
    }

    private static function subtotal(callable $agregar, string $rubro, int $stock): void
    {
        $palabras = implode(' ', array_slice(explode(' ', ucfirst(mb_strtolower($rubro))), 0, 2));
        $rotulo = self::al_azar(['Subtotal ' . $palabras, 'Subtotal: ' . $palabras, 'Sub Total ' . $palabras, 'SUBTOTAL ' . mb_strtoupper($palabras)]);
        $donde = mt_rand(0, 2); // 0 en Stock, 1 en «Obs» (sin mapear), 2 en las dos
        $fila = [1 => $rotulo];
        if ($donde !== 1) {
            $fila[3] = $stock;
        }
        if ($donde !== 0) {
            $fila[4] = $stock * 10;
        }
        $agregar($fila, false);
    }

    /**
     * Una lista de clientes. Columnas: Cliente, CUIT, Tel, Debe.
     *
     * @return array{filas: array, inicio: int, columnas: array, datos: int[], no_datos: int[]}
     */
    public static function contactos(int $caso): array
    {
        $filas = [];
        $datos = [];
        $no_datos = [];
        $numero = 0;
        $agregar = function (array $fila, $es_dato) use (&$filas, &$datos, &$no_datos, &$numero) {
            $numero++;
            $filas[$numero] = array_replace([null, null, null, null], $fila);
            if ($es_dato === true) {
                $datos[] = $numero;
            } elseif ($es_dato === false) {
                $no_datos[] = $numero;
            }
        };

        $membrete = mt_rand(0, 3);
        for ($i = 0; $i < $membrete; $i++) {
            $agregar($i === 0 ? [0 => 'CLIENTES QUE DEBEN AL 14/11'] : [], null);
        }
        $cabecera = ['Cliente', 'CUIT', 'Tel', 'Debe'];
        $agregar($cabecera, null);
        $inicio = $numero + 1;

        $cuantos = mt_rand(5, 40);
        $solo_nombre = mt_rand(0, 60) / 100;   // proporción de contactos con solo el nombre
        $nombre_y_saldo = mt_rand(0, 50) / 100; // del resto, proporción con solo nombre y saldo
        $tipos = [];
        $con_datos = 0;
        for ($i = 0; $i < $cuantos; $i++) {
            $r = mt_rand(0, 100) / 100;
            $tipo = $r < $solo_nombre ? 'solo_nombre' : (mt_rand(0, 100) / 100 < $nombre_y_saldo ? 'nombre_y_saldo' : 'completo');
            $tipos[] = $tipo;
            $con_datos += $tipo === 'solo_nombre' ? 0 : 1;
        }
        /*
         * EXCLUIDO: títulos de sección en una lista con menos de 3 contactos con algún dato además del nombre. Ahí
         * no hay cómo saber cómo vienen los contactos de esa lista y un «Zona centro» puede ser un cliente.
         * EXCLUIDO: títulos SIN señal («Mostrador», «Varios») y títulos con solo la señal débil (mayúsculas): sin una
         * palabra de sección ni los dos puntos, un nombre solo es indistinguible de un cliente sin datos (la señal
         * débil la fijan los tests de forma, que controlan la proporción).
         */
        $titulos = $con_datos >= 3 ? mt_rand(0, 4) : 0;
        $cada = (int) max(1, floor($cuantos / max(1, $titulos)));
        $repetir_cabecera_en = mt_rand(0, 3) === 0 ? mt_rand(1, $cuantos - 1) : -1;
        $total = 0;
        foreach ($tipos as $i => $tipo) {
            if ($titulos > 0 && $i % $cada === 0 && $i / $cada < $titulos) {
                $agregar([0 => self::al_azar(self::TITULOS_DE_SECCION)], false);
            }
            if ($i === $repetir_cabecera_en) {
                $agregar($cabecera, false);
            }
            if (mt_rand(0, 8) === 0) {
                $agregar([], null);
            }
            /*
             * EXCLUIDO: contactos de solo nombre escritos todo en mayúsculas o que empiezan con una palabra de sección:
             * son justo la forma de un título (señal débil o fuerte) y no se pueden distinguir por la fila.
             */
            $nombre = self::al_azar(self::NOMBRES) . ' ' . ($i + 1);
            $saldo = mt_rand(0, 4) === 0 ? 0 : (mt_rand(0, 5) === 0 ? -mt_rand(100, 5000) : mt_rand(1000, 400000) + 0.5);
            if ($tipo === 'solo_nombre') {
                $agregar([0 => $nombre], true);
            } elseif ($tipo === 'nombre_y_saldo') {
                $agregar([0 => $nombre, 3 => self::moneda() ? $saldo : '$ ' . number_format($saldo, 2, ',', '.')], true);
                $total += $saldo;
            } else {
                $agregar([0 => $nombre, 1 => mt_rand(0, 1) ? '20-' . mt_rand(10000000, 39999999) . '-' . mt_rand(0, 9) : null,
                    2 => '3442 40' . mt_rand(1000, 9999), 3 => $saldo], true);
                $total += $saldo;
            }
        }

        /*
         * EXCLUIDO: notas al pie sin un total arriba: una fila de solo nombre al final, sin total, es un cliente sin
         * datos tanto como una nota.
         */
        $con_total = mt_rand(0, 2) > 0;
        if ($con_total) {
            $rotulo = self::al_azar(['TOTAL', 'Total', 'Total: ' . $cuantos . ' clientes', 'Son ' . $cuantos . ' clientes', 'TOTAL GENERAL']);
            $agregar([0 => $rotulo, 3 => mt_rand(0, 2) > 0 ? $total : null], false);
            $notas = mt_rand(0, 2);
            for ($i = 0; $i < $notas; $i++) {
                $agregar([0 => self::al_azar(self::NOTAS_DE_CONTACTOS)], false);
            }
        }

        return [
            'filas' => $filas, 'inicio' => $inicio, 'columnas' => ['nombre' => 0, 'cuit' => 1, 'telefono' => 2, 'saldo_actual' => 3],
            'datos' => $datos, 'no_datos' => $no_datos, 'caso' => $caso,
        ];
    }
}
