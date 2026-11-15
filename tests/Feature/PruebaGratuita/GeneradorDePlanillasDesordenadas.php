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
    // Notas de una celda: texto, y desde E8.6 también con números, importes y fechas solas.
    const NOTAS = ['Actualizó: Mirta 12/11', 'Precios sujetos a cambio sin previo aviso', 'Consultar stock antes de vender',
        'Lista vigente al 14/11/2026', 'Firma: Carlos', 'Stock valorizado al costo: $ 1.234.567', '14/11/2026', '$ 1.234.567',
        '1.234.567,50', '12-11-26', 'Revisado 12/11 por Hugo'];
    const NOMBRES = ['Constructora Del Litoral SRL', 'Gómez, Jorge (albañil)', 'Plomería El Caño', 'Municipalidad - Obras',
        'Rosa Fernández', 'Taller Mecánico Ruta 14', 'Sandra Ojeda', 'Ferretería López', 'Juan Pérez', 'Corralón Sur',
        'Marta Giménez', 'Electricidad Gutiérrez', 'Pinturería Colón', 'Hugo Benítez', 'Distribuidora Paraná'];
    const TITULOS_DE_SECCION = ['Zona norte:', 'Zona centro', 'Morosos', 'Clientes del interior', 'AL DÍA:', 'Deudores', 'Lista mayoristas',
        'Proveedores de obra:', 'Cuentas viejas'];
    /*
     * Contactos de verdad que se parecen a un título (E8.4): razones sociales, clientes genéricos de mostrador,
     * negocios cuyo nombre empieza con una palabra de sección y siglas cortas. Van con solo el nombre y tienen que
     * entrar.
     */
    const CONTACTOS_QUE_PARECEN_TITULOS = ['Zona Sur Materiales', 'Lista Blanca SRL', 'Clientes Varios', 'Sucursal Centro', 'Grupo Lopez',
        'Cuentas Claras SA', 'Rubro Hogar', 'YPF', 'ACA', 'Consumidor final', 'Mostrador', 'Pérez Hnos', 'Gómez y Cía', 'López e Hijos',
        'Público en general'];
    const NOTAS_DE_CONTACTOS = ['Actualizado el 14/11', 'Pedir a Marta los que faltan', 'Revisar con el contador'];
    /*
     * Títulos de grupo SIN señal (ni dos puntos, ni mayúsculas, ni palabra de sección): «sueltas» (ronda 8, A1). Solo
     * quedan afuera en una lista donde todos los contactos traen algún dato (LocalImportHelper::es_fila_suelta()).
     */
    const SUELTAS_SIN_SENAL = ['Mayoristas', 'Minoristas', 'Particulares', 'Gremio de la construcción', 'Revendedores',
        'Vecinos del barrio', 'Otros', 'Mayoristas y distribuidores', 'Del barrio', 'Clientes del taller', 'Empresas'];
    /*
     * Contactos REALES de solo nombre sin ninguna señal de grupo (segunda pasada de A1): una palabra, minúsculas, un
     * apellido con preposición. Van en listas con datos y tienen que entrar.
     */
    const CONTACTOS_REALES_DE_SOLO_NOMBRE = ['Gómez', 'Carla', 'Martín', 'juan perez', 'Juan pérez', 'Kiosco', 'Panadería',
        'la esquina', 'Pirulo', 'Del Valle', 'De la Fuente', 'Obras Sanitarias', 'Doña Rosa', 'Rotisería El Gallo', 'Del Río'];

    private static function al_azar(array $opciones)
    {
        return $opciones[mt_rand(0, count($opciones) - 1)];
    }

    private static function moneda(): bool
    {
        return mt_rand(0, 1) === 1;
    }

    /**
     * Una planilla de artículos. Columnas: A código (o nada), B nombre, C precio (o nada: hoja de stock), D stock,
     * E «Obs» sin mapear y, a veces, F rubro (categoría) o marca.
     *
     * Tres tipos de hoja (E8.4):
     *  - 'con': los artículos traen código (3 de cada 4 o más, contando la hoja entera), con algunos artículos SIN
     *    código intercalados, en la segunda mitad o al final (con precio, o sin precio pero con stock o marca);
     *  - 'medio': a medio codificar (10 a 60 % con código): NO es una hoja con códigos;
     *  - 'sin': sin columna de código.
     *
     * @return array{filas: array, inicio: int, mapeo: array, titulos: array, datos: int[], no_datos: int[], con_codigos: bool, cabecera: int}
     */
    public static function articulos(int $caso): array
    {
        $tipo = self::al_azar(['con', 'con', 'con', 'medio', 'sin']);
        $con_codigo = $tipo !== 'sin';
        $con_precio = mt_rand(0, 3) > 0;
        $quinta = mt_rand(0, 2); // 0 nada, 1 rubro como columna, 2 marca
        $cabecera = [$con_codigo ? 'Código' : null, $con_codigo ? 'Descripción' : 'Producto', $con_precio ? 'Precio de venta' : null, 'Stock', 'Obs'];
        $mapeo = ['nombre' => 1, 'stock_actual' => 3];
        if ($con_codigo) {
            $mapeo['codigo_de_proveedor'] = 0;
        }
        if ($con_precio) {
            $mapeo['precio'] = 2;
        }
        if ($quinta === 1) {
            $cabecera[] = 'Rubro';
            $mapeo['categoria'] = 5;
        } elseif ($quinta === 2) {
            $cabecera[] = 'Marca';
            $mapeo['marca'] = 5;
        }
        $vacia = array_fill(0, count($cabecera), null);

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

        $rubros = mt_rand(0, 4);
        $notas = mt_rand(0, 3);
        if ($tipo === 'con') {
            /*
             * Densidad variable (E8.5): de 12 a 60 artículos con código, 0 a 3 artículos con datos sin código, un rubro
             * cada 3 a 8 artículos y 0 a 3 notas. EXCLUIDO: la combinación que, por la definición de
             * PlanillaDePrueba::resumir_la_hoja(), no es una hoja «con códigos» (C ≥ 10, 4·C ≥ 3·D, 2·S ≤ D, con D las
             * filas con datos, C las que traen código y S las sueltas: rubros y notas). Ahí un rubro en mayúsculas sin
             * decorar o un subtotal del medio no se distinguen de un artículo: esas hojas son las de tipo 'medio'.
             */
            do {
                $con_codigo_n = mt_rand(12, 60);
                $sin_codigo = mt_rand(0, 3);
                $notas = mt_rand(0, 3);
                $rubros = (int) floor(($con_codigo_n + $sin_codigo) / mt_rand(3, 8));
                $d = $con_codigo_n + $sin_codigo;
            } while (4 * $con_codigo_n < 3 * $d || 2 * ($rubros + $notas) > $d);
            $items = array_fill(0, $con_codigo_n, 'codigo');
            foreach (range(1, $sin_codigo) as $k) {
                if ($sin_codigo === 0) {
                    break;
                }
                $donde = mt_rand(0, 2); // 0 intercalado, 1 en la segunda mitad, 2 al final
                $posicion = $donde === 0 ? mt_rand(0, count($items)) : ($donde === 1 ? mt_rand((int) (count($items) / 2), count($items)) : count($items));
                array_splice($items, $posicion, 0, ['sin_codigo']);
            }
        } elseif ($tipo === 'medio') {
            $total_n = mt_rand(10, 30);
            $con_codigo_n = (int) round($total_n * mt_rand(10, 60) / 100);
            $items = array_merge(array_fill(0, $con_codigo_n, 'codigo'), array_fill(0, $total_n - $con_codigo_n, 'sin_codigo'));
            shuffle($items);
        } else {
            $items = array_fill(0, mt_rand(6, 30), 'sin_codigo');
        }
        $cuantos = count($items);
        $cada = (int) max(1, floor($cuantos / max(1, $rubros)));
        $codigo = 11;
        $stock_del_rubro = 0;
        $rubro_actual = self::al_azar(self::RUBROS);
        $repetir_cabecera_en = mt_rand(0, 3) === 0 ? mt_rand(2, $cuantos - 1) : -1;
        $con_subtotales = $tipo === 'con' && mt_rand(0, 1) === 1;
        $articulos_en_el_rubro = 0;

        foreach ($items as $i => $item) {
            if ($rubros > 0 && $i % $cada === 0 && $i / $cada < $rubros) {
                if ($con_subtotales && $articulos_en_el_rubro > 0) {
                    self::subtotal($agregar, $rubro_actual, $stock_del_rubro);
                }
                $rubro_actual = self::al_azar(self::RUBROS);
                $stock_del_rubro = 0;
                $articulos_en_el_rubro = 0;
                self::rubro($agregar, $rubro_actual, $tipo);
            }
            if ($i === $repetir_cabecera_en) {
                $agregar($cabecera, false);
            }
            if (mt_rand(0, 6) === 0) {
                $agregar([], null);
            }

            $fila = [1 => self::al_azar(self::ARTICULOS) . ' ' . ($i + 1)];
            $precio = round(500 + mt_rand(0, 90000) / 7, 2);
            $precio = self::moneda() ? $precio : '$ ' . number_format($precio, 2, ',', '.');
            if ($item === 'codigo') {
                $codigo += mt_rand(1, 3);
                $forma = mt_rand(0, 2);
                $fila[0] = $forma === 0 ? 'A-' . str_pad((string) $codigo, 4, '0', STR_PAD_LEFT)
                    : ($forma === 1 ? str_pad((string) $codigo, 4, '0', STR_PAD_LEFT) : 1000 + $codigo);
                if ($con_precio) {
                    $fila[2] = $precio;
                }
                $stock_tipo = mt_rand(0, 5);
                $fila[3] = $stock_tipo === 0 ? null : ($stock_tipo === 1 ? '-' : mt_rand(-2, 120));
            } else {
                /*
                 * Un artículo sin código: el nombre y AL MENOS otro dato (precio, stock o marca). EXCLUIDO en las hojas
                 * 'con': el nombre suelto sin ningún otro dato, que ahí no se distingue de un rubro o una nota y se deja
                 * afuera (nombrado). En 'medio' y 'sin' un nombre suelto antes del total es un artículo y va.
                 */
                $opciones = [];
                if ($con_precio) {
                    $opciones[] = 'precio';
                }
                $opciones[] = 'stock';
                if ($quinta === 2) {
                    $opciones[] = 'marca';
                }
                // EXCLUIDO en 'medio': con nombres pelados la hoja puede pasar a ser «con códigos» (no están en D) y ahí
                // un nombre suelto es indecidible. Van solo en 'sin'.
                if ($tipo === 'sin') {
                    $opciones[] = 'solo_nombre';
                }
                $otro = self::al_azar($opciones);
                if ($otro === 'precio') {
                    $fila[2] = $precio;
                    $fila[3] = mt_rand(0, 1) ? mt_rand(0, 60) : null;
                } elseif ($otro === 'stock') {
                    $fila[3] = mt_rand(-2, 120);
                } elseif ($otro === 'marca') {
                    $fila[5] = self::al_azar(['Tigre', 'Acindar', 'Alba', 'Fischer', 'Loma Negra']);
                }
            }
            if ($quinta === 1) {
                $fila[5] = ucfirst(mb_strtolower($rubro_actual));
            }
            $stock_del_rubro += isset($fila[3]) && is_int($fila[3]) ? $fila[3] : 0;
            $agregar($fila, true);
            $articulos_en_el_rubro++;
        }
        if ($con_subtotales && $rubros > 0 && $articulos_en_el_rubro > 0) {
            self::subtotal($agregar, $rubro_actual, $stock_del_rubro);
        }

        /*
         * EXCLUIDO: en una hoja que no es «con códigos» ('medio' y 'sin'), notas al pie sin un total arriba: ahí una
         * fila con un solo texto al final no se distingue de un artículo sin precio; solo el total abre el pie.
         */
        $con_total = mt_rand(0, 3) > 0;
        if ($con_total) {
            $rotulo = self::al_azar(['TOTAL', 'TOTALES', 'TOTAL GENERAL', 'Total artículos: ' . $cuantos, 'Son ' . $cuantos . ' artículos']);
            $columna = $con_codigo && mt_rand(0, 3) === 0 ? 0 : 1;
            $agregar([$columna => $rotulo, 3 => mt_rand(0, 1) === 1 ? mt_rand(100, 900) : null], false);
        }
        $notas = ($tipo === 'con' || $con_total) ? $notas : 0;
        for ($i = 0; $i < $notas; $i++) {
            if (mt_rand(0, 2) === 0) {
                $agregar([], null);
            }
            $columna = $con_codigo && mt_rand(0, 1) === 1 ? 0 : 1;
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
            'no_datos' => $no_datos, 'con_codigos' => $tipo === 'con', 'tipo' => $tipo, 'cabecera' => $fila_de_cabecera, 'caso' => $caso,
        ];
    }

    private static function rubro(callable $agregar, string $rubro, string $tipo): void
    {
        $bonito = ucfirst(mb_strtolower($rubro));
        /*
         * EXCLUIDO fuera de las hojas 'con': el rubro en mayúsculas sin decorar en la columna del NOMBRE (forma 2): no
         * se distingue de un artículo sin precio. En la columna del código (forma 3) sí se reconoce (un texto con
         * espacios o sin números no es un código), así que va en 'medio'.
         */
        $formas = $tipo === 'con' ? [0, 1, 2, 3, 4] : ($tipo === 'medio' ? [0, 1, 3, 4] : [0, 1, 4]);
        $forma = self::al_azar($formas);
        $en_el_codigo = $tipo !== 'sin' && mt_rand(0, 1) === 1;
        if ($forma === 0) {
            $agregar([$en_el_codigo ? 0 : 1 => '--- ' . $rubro . ' ---'], false);
        } elseif ($forma === 1) {
            $agregar([1 => '*** ' . $bonito . ' ***'], false);
        } elseif ($forma === 2) {
            $agregar([1 => $rubro], false);
        } elseif ($forma === 3) {
            $agregar([0 => $rubro], false);
        } else {
            $agregar([$en_el_codigo ? 0 : 1 => 'Rubro: ' . $bonito], false);
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
        // Una de cada cuatro listas, todos los contactos con algún dato: donde se juzgan las filas sueltas (ronda 8, A1).
        $solo_nombre = $caso % 4 === 3 ? 0.0 : $solo_nombre;
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
        /*
         * Ronda 8, A1: en una lista donde TODOS los contactos traen algún dato, una o dos filas sueltas (un título de
         * grupo sin señal, en cualquier lugar, también en la primera fila de datos) son no-datos. Con contactos de
         * solo nombre en la lista no se agregan: ahí la regla duda a propósito y una suelta es un contacto más.
         */
        /*
         * Segunda pasada: también contactos REALES de solo nombre (sin señal de grupo), que entran siempre. En una lista
         * donde todos traen datos, entre reales y sueltas hay 2 como mucho y 4 contactos con datos por cada una (si no,
         * la regla duda y una suelta entraría: ese caso no se genera). En las demás listas, a lo sumo un real.
         */
        $sueltas_en = [];
        $libres = range(0, $cuantos - 1);
        $poner = function (string $que) use (&$sueltas_en, &$libres) {
            $clave = mt_rand(0, count($libres) - 1);
            $sueltas_en[$libres[$clave]] = $que;
            array_splice($libres, $clave, 1);
        };
        if (!in_array('solo_nombre', $tipos, true) && $cuantos >= 5) {
            $cupo = min(2, intdiv($cuantos, 4));
            $sueltas = mt_rand(0, $cupo);
            $reales = mt_rand(0, $cupo - $sueltas);
            for ($s = 0; $s < $reales; $s++) {
                $poner('real');
            }
            for ($s = 0; $s < $sueltas; $s++) {
                $poner('suelta');
            }
        } elseif ($cuantos >= 5 && mt_rand(0, 2) === 0) {
            $poner('real');
        }
        $total = 0;
        foreach ($tipos as $i => $tipo) {
            if (isset($sueltas_en[$i])) {
                $agregar([0 => $sueltas_en[$i] === 'suelta' ? self::al_azar(self::SUELTAS_SIN_SENAL)
                    : self::al_azar(self::CONTACTOS_REALES_DE_SOLO_NOMBRE)], $sueltas_en[$i] === 'real');
            }
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
             * EXCLUIDO: contactos de solo nombre escritos todo en mayúsculas con más de una palabra («FERRETERIA
             * GOMEZ»), o que empiezan con una palabra de sección en minúscula y con hasta tres palabras («Zona centro»):
             * son justo la forma de un título (señal débil o fuerte) y no se pueden distinguir por la fila. Los que
             * se parecen pero se pueden distinguir (CONTACTOS_QUE_PARECEN_TITULOS) sí van.
             */
            $nombre = $tipo === 'solo_nombre' && mt_rand(0, 2) === 0
                ? self::al_azar(self::CONTACTOS_QUE_PARECEN_TITULOS) : self::al_azar(self::NOMBRES) . ' ' . ($i + 1);
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
            'datos' => $datos, 'no_datos' => $no_datos, 'caso' => $caso, 'sueltas' => count(array_keys($sueltas_en, 'suelta', true)),
            'reales_de_solo_nombre' => count(array_keys($sueltas_en, 'real', true)),
        ];
    }
}
