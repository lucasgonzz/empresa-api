<?php

/**
 * Mapa nombre de articulo -> archivo dentro de storage/app/public/article-images-2.
 *
 * Las fotos las eligio Lucas a mano (18/8/2026 en adelante) y las subio con el flujo normal
 * de carga de imagenes de la app contra su base local; este archivo es la foto de esa
 * asignacion en el momento en que se armo (25/8/2026), sacada de la tabla `images` de esa
 * base. Reemplaza a las fotos de Wikimedia que bajaba `semilla:imagenes` a
 * storage/app/public/articles-seeder -- esas ya no se usan.
 *
 * Indexado por el `name` EXACTO de FerreteriaArticlesSeeder::get_catalog(), mismo criterio
 * que ferreteria_descripciones.php: no hay ningun otro campo del catalogo que sea estable y
 * unico para los 46 articulos (bar_code vacio en tres, provider_code con un valor de relleno
 * repetido). Si se retoca un name en get_catalog(), hay que retocarlo aca tambien.
 *
 * Los 12 articulos que no aparecen en este mapa se crean sin imagen (misma logica de
 * degradacion que ya tenia el seeder para archivos faltantes: no revienta, sigue).
 *
 * La carpeta article-images-2 NO se commitea (mismo storage/app/public/.gitignore que ya
 * ignora todo salvo si mismo). Para poblarla, copiar a mano los archivos de abajo desde donde
 * Lucas los tenga subidos.
 *
 * @return array<string,string>
 */
return [
    'CESTO DE BASURA CON PORTA PAPEL GRIS STOLF' => '178768453523817.webp',
    'CESTO DE BASURA CON PORTA PAPEL NEGRO STOLF' => '178768453222031.webp',
    'DRIVER PARA PANEL LED 24W ETHEOS' => '178768452993741.webp',
    'LAMPARA DICROICA LEDS 7W GU10 LUZ DIA NO / DIMERIZABLE CANDELA' => '178768451419845.webp',
    'MODULO HUSQVARNA (BOBINA)  236/235E/240/136/137/ POULAN 295/2600/2750/2775/2900' => '178768451172336.webp',
    'FILTRO OREGON DE AIRE P/ B&S 12.5/13.5 MOD, VIEJOS OVALADO' => '178768449443472.webp',
    'PIEDRA TECOMEC 145 X3.2 X 22.2MM P/ AFILADORA DE CADENA' => '178768449135953.webp',
    'FILTRO OREGON AIRE P/ KOHLER SEMI RECTANGULAR C/ PREFILTRO (32-883-03-S1)' => '178768448734489.webp',
    'WP 230 STIHL BOMBA DE AGUA' => '178768448536515.webp',
    'VOLANTE STIHL MS210 / 250 / 021 / 025' => '178768447824285.webp',
    'W80 LUBRICANTE MULTIUSO CON PTFE 250ML AEROSOL' => '178768447454963.webp',
    'LLAVE TERMICA SICA 1X25A.' => '178768447253418.webp',
    'TUBO DE LED 9W LUZ DIA 6500K 60CM CANDELA' => '17876844693788.webp',
    'PINZA CRIMPEADORA P/CONECTOR COAXIL RG59/6 SNAP-TIPO F' => '178768446434597.webp',
    'LAMPARA MESH BLACK CUBO 4W CALIDA FILAMENTO CANDELA' => '178768446029368.webp',
    'CINTA PASACABLE DE ACERO X 30 METROS KALOP' => '178768445864075.webp',
    'CAJA DERIVACION PVC 16x18X8 GEN-ROD' => '17876844483156.webp',
    'AZADA BELLOTA  S/CABO 2.1/2' => '17876844443957.webp',
    'AZADA TRAMONTINA S/CABO 2.0' => '178768444096705.webp',
    'Fraccionadora de Cinta con Mango Anatomico reforzado' => '178768442816208.webp',
    'LLAVE ALLEN 9 PIERZAS JUSTER CORTO' => '178768442571448.webp',
    'LLAVE TORX 9 PIEZAS JUSTER LARGO' => '178768441748538.webp',
    'LIMPIADOR QUITA OXIDO (FOSFATIZANTE) X 1LT' => '178768441150572.webp',
    'FLEXIBLE PARA DUCHA 1/2 X 1.50MTS CROMO TGFLEX' => '178768440639821.webp',
    'ESPUMA POLIURETANO 300ML DOGO' => '17876844038757.webp',
    'MECHA PARA CERAMICA Y AZULEJOS 10 MM  EXPERT BOSCH' => '178768440085622.webp',
    'ESPATULA ENDUIR 140MM BIASSONI' => '178768439710542.webp',
    'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI' => '17876843956578.webp',
    'LLAVE T 10MM GARDEX' => '178768438956433.webp',
    'SOPORTE D/PARED PARA TV,RECLINABLE 43 X 100,ONEBOX' => '17876843794912.webp',
    'DISCO  KEX FIBRA REMOVEDOR 115x2.2 mm' => '178768437625833.webp',
    'PRECINTO 200 x 4.8' => '178768437311255.webp',
    'CERRADURA PRIVE  DESTRABADOR ELECTRICO 122 8 A 12 VOLTS - 5WATTS' => '178768435793750.webp',
    'ESPATULA 70mm REMACHADO M/MADERA BIASSONI' => '178768434899485.webp',
];
