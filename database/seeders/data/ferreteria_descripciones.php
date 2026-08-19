<?php

/**
 * Descripciones a medida de los 46 artículos del catálogo de ferretería.
 *
 * ¿Por qué existe este archivo y por qué está escrito así?
 *
 * El texto que se vectoriza para la búsqueda semántica lo arma
 * ArticleEmbeddingService::embedding_for_article(), que ya mete por separado el
 * nombre, la categoría, la marca y el código de barras. La descripción es lo ÚNICO
 * que puede aportar significado que esos campos no tienen: para qué se usa el
 * producto, con qué palabras lo nombra un cliente que no sabe el nombre comercial, y
 * contra qué alternativa se compara.
 *
 * Las descripciones anteriores eran una plantilla de tres variables (name, brand_name,
 * sub_category_name) más un segundo bloque constante, byte a byte idéntico en los 46.
 * O sea: cero información nueva, y 300 caracteres iguales empujando los 46 vectores al
 * mismo punto del espacio. El criterio de calidad concreto es que una consulta como
 * "algo para pintar una pared con humedad" o "necesito colgar un botiquín en el baño
 * sin romper los azulejos" encuentre el artículo correcto. El nombre comercial no dice
 * nada de eso.
 *
 * REGLAS DE REDACCIÓN, todas obligatorias:
 *
 * 1. Prohibido texto derivable del nombre, la marca o la categoría. Ya entran por
 *    separado en el texto vectorizado: repetirlos gasta tokens sin agregar señal.
 * 2. Prohibida cualquier oración de más de 8 palabras compartida entre dos artículos.
 *    Es la regla mecánica que mata la vuelta de la plantilla, y hay un test que la
 *    verifica partiendo los content por ". " y normalizando.
 * 3. Al menos dos sinónimos deliberados por artículo: palabras que usaría el cliente y
 *    que NO están en el nombre ("tapón de la bacha", "agujerear azulejos sin que se
 *    rajen"). Acá está la mitad del valor de todo este archivo.
 * 4. Prohibidos los adjetivos vacíos ("excelente", "confiable", "de calidad",
 *    "resistente" a secas): no discriminan entre productos, o sea que no mueven el
 *    vector. Sí sirve "resiste la humedad del baño".
 * 5. Sin precios ni stock: cambian, y cambiar el texto obliga a rehornear el vector.
 * 6. Español rioplatense de mostrador, sin voseo imperativo: es una ficha, no un chat.
 * 7. Entre 200 y 450 caracteres por bloque, 700 y 1100 por artículo. Muy por debajo del
 *    techo de DESCRIPTIONS_MAX_CHARS.
 * 8. Los pares casi idénticos del catálogo se diferencian EXPLÍCITAMENTE en el tercer
 *    bloque. Si no, la búsqueda devuelve los dos con el mismo score y la respuesta del
 *    agente queda al azar. Los pares son: los dos cestos STOLF, las dos azadas, los dos
 *    filtros OREGON, las cuatro espátulas BIASSONI, los dos precintos, las dos sopapas,
 *    las dos llaves JUSTER, los dos discos KEX y las tres lámparas CANDELA.
 *
 * 🔴 EL ORDEN DE LOS TRES BLOQUES ES PARTE DEL TEXTO VECTORIZADO. create_descriptions()
 * inserta en este orden, el orden de inserción define los id, y descriptions_text()
 * ordena por id. Reordenar los bloques cambia el texto, cambia el sha1 y obliga a
 * rehornear los vectores con `php artisan semilla:embeddings`.
 *
 * 🔴 EL ÍNDICE ES EL name EXACTO DEL CATÁLOGO, con dobles espacios y todo. Si se toca un
 * nombre en get_catalog() hay que tocarlo acá también: el artículo se crea igual, pero
 * sin descripciones y con un warning en el log.
 *
 * @return array<string,array<int,array<string,string>>>
 */

return [

    'CESTO DE BASURA CON PORTA PAPEL GRIS STOLF' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Va apoyado al lado del inodoro en el baño de un local, una oficina o un consultorio: junta los residuos y al mismo tiempo sostiene el rollo de papel higiénico al alcance de la mano. Evita tener que atornillar un portarrollo a la pared, algo que en un lugar alquilado o revestido con cerámica no siempre se puede hacer.',
        ],
        [
            'title' => 'Características',
            'content' => 'Cuerpo de plástico inyectado con tapa rebatible y soporte lateral integrado para el rollo. La base es ancha para que no se voltee de un golpe con el pie, y el interior liso se enjuaga con un trapo húmedo sin que quede suciedad pegada en los rincones. Acepta bolsa chica, de la que se usa en la cocina.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El tono claro combina con sanitarios blancos y revestimientos claros, donde una pieza oscura corta la vista. Como el polvillo y las salpicaduras se notan enseguida sobre este color, rinde mejor en toilettes de poco tránsito o donde alguien pasa un trapo todos los días.',
        ],
    ],

    'CESTO DE BASURA CON PORTA PAPEL NEGRO STOLF' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Resuelve dos cosas con un solo mueble chico: dónde tirar los papeles usados y dónde dejar el rollo sin que se moje. Es el formato típico del toilette de un bar, un kiosco o un taller, donde el espacio libre al costado del inodoro es mínimo y no entra un tacho más otro soporte aparte.',
        ],
        [
            'title' => 'Características',
            'content' => 'Una sola pieza plástica con tapa que acompaña el movimiento de la mano y aro portarrollo del lado de afuera. La superficie exterior es lisa, sin molduras donde se junte grasa, y se limpia con detergente común. El diámetro de boca deja tirar un vaso descartable sin apuntar.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El color oscuro disimula rayones, marcas de dedos y manchas, así que aguanta mejor un lugar de mucho paso donde no se limpia a diario. Es lo que va en gimnasios, depósitos y locales gastronómicos, o cuando los sanitarios y los muebles del ambiente ya son de tono oscuro.',
        ],
    ],

    'ESCOBILLON 375 X 45MM CERDA RIGIDA GARDEX' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Barre superficies exteriores donde una escoba blanda no llega a mover nada: vereda con tierra apelmazada, patio de portland, playa de estacionamiento o galpón con viruta. La cerda dura arrastra hojas mojadas, arena y pasto cortado en vez de quedarse doblada encima.',
        ],
        [
            'title' => 'Características',
            'content' => 'El paño mide unos treinta y siete centímetros de ancho, así que cada pasada cubre bastante y el trabajo se termina en menos idas y vueltas. Lleva rosca estándar para cabo de madera o de metal, que se compra por separado y se enrosca a mano sin herramienta.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Conviene cuando el piso es rugoso —hormigón peinado, adoquín, baldosón— porque ahí una fibra fina se desgasta en un mes. Sobre pisos interiores encerados o flotantes resulta demasiado agresivo y se nota el rayado, y para juntar polvo fino no sirve porque lo levanta en vez de arrastrarlo.',
        ],
    ],

    'DRIVER PARA PANEL LED 24W ETHEOS' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la fuente que alimenta un artefacto de luz plano: convierte los 220 de la pared en la corriente continua que necesita adentro. Cuando el panel empieza a titilar, prende con demora o directamente queda apagado, casi siempre lo que se quemó es esta pieza y no los diodos del artefacto.',
        ],
        [
            'title' => 'Características',
            'content' => 'Entrega corriente estabilizada para una carga de veinticuatro vatios y viene en una caja plástica alargada con los cables ya salidos: dos de entrada y dos de salida. La conexión se hace con fichas rápidas o bornera, sin soldar. Queda escondida arriba del cielorraso o dentro del artefacto.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Reemplazarla cuesta mucho menos que tirar el artefacto entero, así que es la primera prueba antes de darlo por perdido. Hay que verificar que la potencia coincida con la del panel: una fuente de menos vatios prende igual pero trabaja al límite, se recalienta y vuelve a fallar en pocos meses.',
        ],
    ],

    'DUCHA DE MANO METAL GLOA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la regadera que se sostiene con la mano para bañarse sentado, enjuagar a un chico o a un perro, y limpiar las paredes de la bañera sin salpicar todo el baño. También sirve para llenar baldes donde no hay pileta de servicio.',
        ],
        [
            'title' => 'Características',
            'content' => 'El cuerpo es metálico y más pesado que los plásticos. La placa perforada se desarma para sacarle el sarro que va tapando los agujeros y desviando el chorro. Lleva rosca de media pulgada, que es la medida corriente, así que entra en cualquier manguera del mercado sin adaptador.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se busca cuando la de plástico ya se rajó en la unión con la manguera, que es justo donde siempre se parten. El peso se nota en la mano, pero soporta caídas contra el piso de la bañera que a las livianas las abren al medio.',
        ],
    ],

    'LAMPARA DICROICA LEDS 7W GU10 LUZ DIA NO / DIMERIZABLE CANDELA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la lamparita de los spots embutidos en el cielorraso de placa de yeso, esos redondos que se ponen en cocinas, baños y locales comerciales. Da un haz dirigido hacia abajo, así que ilumina una mesada, una vidriera o un cuadro sin tener que encender todo el ambiente.',
        ],
        [
            'title' => 'Características',
            'content' => 'El casquillo GU10 es el de las dos patitas que se calzan y se giran un cuarto de vuelta, no el de rosca. El tono es blanco frío, parecido a la luz del mediodía, que muestra los colores sin amarillearlos y por eso se usa donde hay que ver bien lo que se está haciendo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'No admite regulador de intensidad: en un circuito con llave dimmer zumba, parpadea o queda prendida al mínimo. Va en cocina, baño, oficina o comercio, donde la llave es de encendido común. Para un living o un dormitorio con regulación hay que pedir la versión dimerizable, que es otro artículo.',
        ],
    ],

    'MODULO HUSQVARNA (BOBINA)  236/235E/240/136/137/ POULAN 295/2600/2750/2775/2900' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Genera la chispa que enciende la mezcla en el motor de una motosierra chica. Cuando la máquina tiene nafta fresca y bujía nueva, tira bien del arranque pero no larga ni tosiendo, la falla suele estar acá: sin chispa no hay explosión y el motor gira en vano.',
        ],
        [
            'title' => 'Características',
            'content' => 'Va atornillado al costado del volante magnético, con el cable grueso que termina en el capuchón de la bujía. La separación contra el imán se regula con una galga —o con un papel doblado, que es el truco del taller— antes de ajustar los dos tornillos de fijación.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Cubre varios modelos de dos marcas emparentadas, así que conviene comparar el contorno del cuerpo y la posición de los agujeros contra la pieza que sale antes de comprarlo. Si la bujía ya da una chispa azul y fuerte, el problema es otro y hay que mirar carburador o compresión.',
        ],
    ],

    'JUNTA KOHLER TAPA VALVULAS XT650-XT675 - 1404101-S' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Sella la unión entre la tapa de arriba del motor y el bloque, para que el aceite quede adentro. Se reemplaza cuando aparece un manchón aceitoso alrededor de esa tapa, o cuando gotea sobre el escape y sale humo con olor a quemado apenas se pone en marcha.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es un empaque fino, recortado con el contorno exacto de la tapa y los agujeros de los tornillos. Va colocado en seco, sin sellador encima salvo que el manual del motor lo pida, y los tornillos se aprietan en cruz y sin forzar para que el material no se deforme de un lado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Cuando se abre el motor para regular la luz de válvulas, la pieza vieja ya quedó aplastada y no vuelve a asentar aunque parezca sana: se cambia siempre en ese momento. Es de lo más barato del motor y la pérdida que evita es de las más molestas de limpiar.',
        ],
    ],

    'FILTRO OREGON DE AIRE P/ B&S 12.5/13.5 MOD, VIEJOS OVALADO' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Retiene el polvo antes de que entre al motor de una cortadora de césped o un grupo electrógeno. Cuando el equipo arranca pero afloja apenas se le mete carga, humea negro o consume de más, casi siempre está tapado de tierra y pasto seco compactado.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es un elemento de papel plegado de contorno ovalado, del tipo que montan los motores de esa cilindrada en las versiones anteriores. Se limpia soplando de adentro hacia afuera con aire a baja presión y se descarta cuando el plegado quedó gris parejo y ya no aclara.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'La forma es lo que define la compra: hay que apoyar el que sale contra el nuevo, porque el mismo fabricante cambió el gabinete según el año de la máquina. Si el que se saca es rectangular y viene con una esponja alrededor, no es este y no va a asentar en el alojamiento.',
        ],
    ],

    'PIEDRA TECOMEC 145 X3.2 X 22.2MM P/ AFILADORA DE CADENA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la muela que monta la afiladora eléctrica de cadenas de motosierra. Devuelve el filo a cada diente cuando la máquina empezó a largar aserrín fino en vez de viruta gruesa, o cuando hay que empujarla contra el tronco para que corte en lugar de que baje sola.',
        ],
        [
            'title' => 'Características',
            'content' => 'El espesor define el paso de cadena que se puede afilar: uno más grueso no entra entre los dientes y uno más fino redondea el filo en vez de copiarlo. El agujero central corresponde a las afiladoras de banco, no a una amoladora de mano.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se reemplaza cuando el perfil quedó comido y ya no sigue la curva del diente, o cuando llegó a la marca de desgaste. Conviene tener una guardada: una cadena que tocó tierra, un clavo o un alambre gasta el abrasivo en una sola pasada por toda la vuelta.',
        ],
    ],

    'FILTRO OREGON AIRE P/ KOHLER SEMI RECTANGULAR C/ PREFILTRO (32-883-03-S1)' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Limpia el aire que aspira el motor de un tractorcito de corte o de una máquina que trabaja levantando mucha tierra. Trabaja en dos etapas: la de afuera frena el pasto y la basura gruesa, y la interna atrapa el polvo fino, que es el que raya el cilindro y baja la compresión.',
        ],
        [
            'title' => 'Características',
            'content' => 'El prefiltro es una funda de espuma que se calza por encima del cartucho de papel. Esa funda se lava con agua y detergente, se seca bien y se vuelve a colocar. El cartucho de papel no se lava nunca: se sopla o se cambia, porque el agua le arruina el plegado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Corresponde a los motores de gabinete alto y contorno casi recto, no a los chicos de forma ovalada. Si el equipo trabaja en campo abierto o cortando pasto alto y seco, la espuma de afuera hace que el cartucho dure varias veces más y se cambie mucho menos seguido.',
        ],
    ],

    'WP 230 STIHL BOMBA DE AGUA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Mueve grandes volúmenes de agua donde no hay electricidad: sacar de un pozo o de un arroyo para regar, vaciar un sótano inundado, achicar una pileta antes de limpiarla o cargar un tanque australiano en el campo. Trabaja con su propio motor a nafta.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es una bomba centrífuga autocebante con boca de aspiración y boca de impulsión para acoplar mangueras con abrazadera. La aspiración pide una válvula de pie con canasto para que no chupe barro ni piedras, porque eso le come el rodete en poco tiempo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se justifica cuando el trabajo es de mucho caudal y poca altura, como regar por surco o vaciar rápido un volumen grande. Para subir agua a un tanque de varios pisos hace falta otra curva de presión, y si el líquido viene con barro espeso corresponde una de sólidos.',
        ],
    ],

    'VOLANTE STIHL MS210 / 250 / 021 / 025' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Cumple tres funciones a la vez en una motosierra: lleva los imanes que le dan la señal al encendido, ventila el motor con sus paletas y acumula inercia para que el giro salga parejo. Se reemplaza cuando el arranque quedó irregular o el equipo se recalienta sin motivo.',
        ],
        [
            'title' => 'Características',
            'content' => 'Va montado sobre el cigüeñal con una chaveta que además funciona como fusible mecánico: se corta ella antes que el eje. Las aletas de ventilación son parte de la misma pieza, así que si están rotas o llenas de aserrín compactado el cilindro se calienta aunque el motor esté sano.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se cambia cuando la chaveta se partió por un golpe seco del freno de cadena, cuando el imán perdió fuerza o cuando faltan paletas. Antes de encararlo conviene descartar la bobina de encendido, que da síntomas parecidos, sale mucho menos y se prueba en cinco minutos.',
        ],
    ],

    'W80 LUBRICANTE MULTIUSO CON PTFE 250ML AEROSOL' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Afloja bulones tomados por el óxido, saca el chirrido de una bisagra o de la puerta de un auto, desplaza la humedad de un contacto eléctrico mojado que hace falso y deja una película que impide que la pieza se vuelva a agarrar con el tiempo.',
        ],
        [
            'title' => 'Características',
            'content' => 'Lleva teflón en la fórmula, que es lo que hace que la película quede seca al tacto y junte menos tierra que un aceite común. El envase presurizado trae la cánula fina para llegar a una rosca escondida sin tener que desarmar media máquina para alcanzarla.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'No reemplaza a la grasa de un rodamiento ni al aceite de una caja: sirve para destrabar, penetrar y proteger, no para lubricar algo que gira bajo carga. En la cadena de una moto o de una bicicleta se va a la primera vuelta y deja la transmisión en seco.',
        ],
    ],

    'LLAVE TERMICA SICA 1X25A.' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Corta la corriente sola cuando un circuito consume de más o cuando hay un cortocircuito, antes de que el cable se recaliente dentro de la pared. Es lo que salta y deja el ambiente a oscuras, que es exactamente el resultado que se busca frente a la alternativa.',
        ],
        [
            'title' => 'Características',
            'content' => 'Ocupa un módulo del tablero y se calza a presión sobre el riel metálico, sin tornillos y sin herramienta. El valor de corte de veinticinco amperes es el que se usa en una línea de tomacorrientes con equipos de consumo alto, como un termotanque o un aire acondicionado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se define por el consumo real de la línea y por la sección del cable, no por gusto: una de más amperaje no corta a tiempo y una de menos se dispara sola cada vez que arranca el compresor. Ojo que no protege a las personas contra descargas: eso lo hace el disyuntor diferencial.',
        ],
    ],

    'TUBO DE LED 9W LUZ DIA 6500K 60CM CANDELA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Va en el artefacto largo de cocinas, lavaderos, galpones y locales, en lugar del fluorescente que se pone negro en las puntas. Prende al instante, sin el parpadeo previo ni el zumbido del equipo viejo, y no tiene mercurio adentro, así que no es residuo peligroso.',
        ],
        [
            'title' => 'Características',
            'content' => 'Mide sesenta centímetros, la medida corriente de los artefactos de dos bocas cortas, y reparte la luz a lo largo de toda la barra en vez de concentrarla en un punto. El tono es blanco frío, del que se usa en ambientes de trabajo y de depósito.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'En un artefacto viejo hay que puentear o sacar el balasto y el arrancador antes de colocarlo, y ese es el paso que más se olvida. Sirve para iluminación general y pareja de un ambiente entero; si lo que hace falta es un haz concentrado sobre una mesada, corresponde un spot dirigido.',
        ],
    ],

    'PINZA CRIMPEADORA P/CONECTOR COAXIL RG59/6 SNAP-TIPO F' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Prensa las fichas del cable de antena o de televisión por cable para que queden firmes y sin ruido en la imagen. Se usa al cablear una casa, al mudar un decodificador de ambiente o cuando la señal se corta a cada rato porque la ficha quedó floja en el conector.',
        ],
        [
            'title' => 'Características',
            'content' => 'El cabezal trae los alojamientos para los dos espesores de cable coaxil más difundidos y aprieta el anillo de la ficha de un solo golpe de mano. Los mangos largos dan la fuerza necesaria sin tener que golpear nada ni apoyarse contra la pared.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la herramienta correcta cuando hay que hacer varias conexiones seguidas. Apretar el anillo con una pinza común lo deja desparejo y la unión falla semanas después, con una falla intermitente que cuesta encontrar. Para un solo empalme suelto puede convenir una ficha a rosca.',
        ],
    ],

    'LAMPARA MESH BLACK CUBO 4W CALIDA FILAMENTO CANDELA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es luz decorativa pensada para quedar a la vista: colgada de un cable textil sobre una barra, en una guirnalda de patio o dentro de un artefacto sin pantalla. El filamento se ve encendido, así que la lamparita forma parte del adorno en vez de esconderse.',
        ],
        [
            'title' => 'Características',
            'content' => 'La forma es cúbica con la malla oscura, y el filamento dibuja una línea de luz cálida, de tono amarillento parecido al de las bombitas de antes. El consumo es bajo porque adentro hay diodos imitando ese filamento y no un alambre incandescente que se pone al rojo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Da clima pero alumbra poco: va en bares, restaurantes, locales de ropa y rincones de living, no donde alguien tiene que leer o cocinar. En un ambiente que necesita luz pareja hay que combinarla con otra fuente o colgar varias repartidas a distinta altura.',
        ],
    ],

    'CINTA PASACABLE DE ACERO X 30 METROS KALOP' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Permite pasar conductores por dentro de un caño ya embutido en la pared o el piso. Se empuja la guía desde un extremo hasta que asoma del otro, ahí se atan los cables con cinta y se tira de vuelta para arrastrarlos por todo el recorrido.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es un fleje de acero enrollado en un carrete, con la punta preparada para no engancharse al pasar por una curva. Los treinta metros alcanzan para el recorrido de una casa entera o para bajar desde el tablero hasta el ambiente más lejano sin empalmar dos tramos.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El fleje metálico avanza donde uno plástico se dobla y se traba, que es lo que pasa en un recorrido con varios codos seguidos. En cambio, si el caño ya tiene conductores adentro hay que ir despacio, porque el canto de acero puede lastimarle la aislación a los que están.',
        ],
    ],

    'CAJA DERIVACION PVC 16x18X8 GEN-ROD' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Aloja los empalmes de una instalación eléctrica en un punto accesible, para que ninguna unión quede enterrada en la pared. Desde ahí se reparten las líneas que salen a cada ambiente, y una falla se revisa abriendo la tapa en vez de picar el revoque a ciegas.',
        ],
        [
            'title' => 'Características',
            'content' => 'Cuerpo plástico con tapa atornillada y paredes marcadas para abrir las entradas de caño en el diámetro que haga falta. El volumen interior deja lugar a varios empalmes con bornera sin tener que forzar los conductores unos contra otros ni doblarlos en ángulo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Este tamaño es el que conviene donde se juntan muchas líneas en un mismo punto, típico del arranque de una instalación o de una ampliación que suma ambientes. Para unir dos cables sueltos alcanza con una bastante más chica, que además queda menos a la vista.',
        ],
    ],

    'AZADA BELLOTA  S/CABO 2.1/2' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Corta yuyos de raíz, abre zanjas y afloja terreno compactado. Es la herramienta del que tiene que carpir un terreno que estuvo años abandonado, sacar pasto de las juntas de un contrapiso o preparar un cantero por primera vez, donde la tierra viene dura y con raíces.',
        ],
        [
            'title' => 'Características',
            'content' => 'Viene sin mango: el cabo de madera se compra aparte y se calza en el ojo por la parte de arriba. La hoja de acero forjado es gruesa y se le puede reavivar el borde con una lima plana cuando de tanto pegar contra piedra quedó redondeado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El peso de más es lo que hace el trabajo en suelo duro o con raíz gruesa: la herramienta baja sola y el brazo apenas la acompaña. En una huerta chica ya laboreada resulta cansadora al rato y ahí rinde mejor una hoja más liviana y de menor ancho.',
        ],
    ],

    'FOCO LED 12W LUZ FRIA NOVAELETRICITY' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la bombita de uso general que va en el portalámparas de rosca de cualquier ambiente: cocina, pasillo, galería, garaje o el velador de la mesa de luz. Entra en el lugar de una incandescente vieja o de una bajo consumo sin tocar nada del artefacto.',
        ],
        [
            'title' => 'Características',
            'content' => 'Tiene rosca común, la grande de toda la vida, y reparte la luz hacia todos los costados en vez de apuntarla a un solo lado. El tono es blanco frío y llega a pleno apenas se acciona la llave, sin el calentamiento lento que tenían las bajo consumo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la opción de reposición cuando hay que cambiar de a varias en una casa entera y el consumo importa. Como la luz sale en todas las direcciones, sirve para alumbrar un ambiente completo; si lo que se busca es un haz apuntado a un punto, corresponde un artefacto dirigido.',
        ],
    ],

    'AZADA TRAMONTINA S/CABO 2.0' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Trabaja la tierra de una huerta o un cantero que ya están preparados: abrir el surco para sembrar, aporcar plantas, arrimar tierra al pie y limpiar los yuyos chicos entre hileras. Se maneja de parado, sin tener que agacharse a cada rato.',
        ],
        [
            'title' => 'Características',
            'content' => 'La hoja es más chica y liviana que la de una herramienta de desmonte, y también se vende sin cabo para elegir el largo del mango según la altura de quien la va a usar. El acero admite afilado con lima cuando el borde deja de entrar en el suelo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se busca para tandas largas en suelo suelto, donde lo que cansa es el peso acumulado de cada golpe y no la dureza del terreno. Si la tierra está apelmazada o llena de raíces gruesas, esta hoja rebota sin morder y conviene una bastante más pesada.',
        ],
    ],

    'Fraccionadora de Cinta con Mango Anatomico reforzado' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Aplica y corta la cinta de embalar en un solo movimiento al cerrar cajas. Es lo que usa un depósito o cualquiera que despache pedidos todos los días, para no andar buscando la punta del rollo con la uña ni cortando la tira con los dientes.',
        ],
        [
            'title' => 'Características',
            'content' => 'El rollo se calza en un eje y la tira pasa por un rodillo que la va pegando mientras se apoya sobre el cartón; la cuchilla dentada del frente la corta al levantar la mano. El agarre es curvo para apoyar la palma y viene reforzado en la zona que más sufre.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'La diferencia se nota en volumen: cerrar veinte bultos por día con esto ahorra bastante tiempo y deja el precinto derecho y sin arrugas. Para un par de paquetes sueltos por mes no se justifica y alcanza con pegar la tira a mano.',
        ],
    ],

    'LLAVE ALLEN 9 PIERZAS JUSTER CORTO' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Ajusta y afloja tornillos con hueco hexagonal en la cabeza, que son los que traen los muebles armables, las bicicletas, las manijas de puerta y las prensas de las máquinas. El juego cubre nueve medidas para no quedarse a mitad de un armado por una sola.',
        ],
        [
            'title' => 'Características',
            'content' => 'Son varillas dobladas en ángulo recto, con punta de seis caras en los dos extremos y el brazo de agarre de largo reducido. Vienen sujetas en un soporte plegable que las mantiene en orden por medida y evita que se pierda la más chica en el fondo del cajón.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El brazo recortado es la ventaja cuando el tornillo está en un lugar apretado y no hay espacio para girar: se maneja casi como un destornillador y entra donde uno largo choca contra la pieza de al lado. A cambio da menos palanca y un tornillo muy tomado cuesta más.',
        ],
    ],

    'LLAVE TORX 9 PIEZAS JUSTER LARGO' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Va en los tornillos cuya cabeza tiene una estrella de seis puntas, cada vez más comunes en electrodomésticos, notebooks, herramientas eléctricas y partes de auto. Un destornillador plano en esa cabeza la redondea a la segunda vuelta y después no la saca nadie.',
        ],
        [
            'title' => 'Características',
            'content' => 'Cada varilla termina en punta de estrella y el juego trae nueve medidas, identificadas con la letra T y un número grabado en el cuerpo. Lo que distingue a este juego es el brazo extendido, que llega hasta el fondo de un alojamiento profundo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El largo sirve para tornillos hundidos en un pozo o rodeados de piezas que no dejan acercar la mano, y da más palanca para destrabar uno pegado por el óxido. En un hueco muy justo estorba y no deja girar, y ahí corresponde el formato de brazo recortado.',
        ],
    ],

    'LIMPIADOR QUITA OXIDO (FOSFATIZANTE) X 1LT' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Saca la herrumbre de una superficie de hierro y deja la base preparada para pintar. Es el paso previo obligado en un portón, una reja, un tanque o una parrilla con manchas coloradas: si se pinta encima sin tratar, la pintura salta en escamas a los pocos meses.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es un líquido que se aplica con pincel o trapo sobre la chapa ya cepillada, se deja actuar y ataca químicamente el óxido. Además fosfatiza, o sea que deja una capa que mejora el agarre de la mano siguiente. El litro rinde para varias aplicaciones de superficie mediana.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Corresponde mientras el ataque sea superficial y el metal todavía tenga cuerpo debajo. Si la chapa está agujereada o se hunde al apretarla con el dedo, no hay producto químico que la recupere y lo que hay que hacer es reemplazar ese tramo entero.',
        ],
    ],

    'FLEXIBLE PARA DUCHA 1/2 X 1.50MTS CROMO TGFLEX' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Une la salida de la pared con la regadera que se agarra con la mano. Se reemplaza cuando empieza a gotear en las uniones, cuando se rajó la cubierta y sale un chorro fino al costado, o cuando el interior se llenó de sarro y bajó la presión de golpe.',
        ],
        [
            'title' => 'Características',
            'content' => 'Mide un metro y medio, largo suficiente para bañarse sentado o enjuagar el fondo de la bañera, y termina en dos tuercas locas de media pulgada, que es la medida corriente. La cubierta metálica cromada aguanta el vapor y el roce mejor que el plástico.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'La trenza de metal se prefiere sobre la manguera blanca cuando queda a la vista o cuando el recorrido obliga a doblarla mucho: el plástico se marca en el pliegue y termina partiéndose justo ahí. Conviene revisar que las juntas de goma vengan puestas antes de enroscar.',
        ],
    ],

    'ESPUMA POLIURETANO 300ML DOGO' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Rellena y sella los huecos que quedan alrededor del marco de una ventana o una puerta recién colocada, el paso de un caño por una pared o una grieta ancha por donde entra frío, ruido y bichos. Crece al salir y toma la forma del espacio vacío.',
        ],
        [
            'title' => 'Características',
            'content' => 'Sale del envase como una crema y en pocos minutos aumenta varias veces su volumen; cuando fragua queda rígida y se recorta al ras con trincheta. Se aplica con la cánula que trae, con el envase boca abajo, y una vez abierto se usa entero porque no se puede guardar.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la salida cuando el hueco es demasiado ancho para masilla o silicona y demasiado irregular para un tapajuntas. Hay que tener presente que la parte expuesta se pone amarilla con el sol y se desarma, así que lo que quede a la intemperie se cubre con revoque o pintura.',
        ],
    ],

    'MECHA PARA CERAMICA Y AZULEJOS 10 MM  EXPERT BOSCH' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Perfora azulejos, cerámicos y porcelanato sin que la pieza se raje ni se astille el esmalte alrededor del agujero. Es lo que hace falta para colgar un botiquín en el baño, fijar el soporte de una cortina sobre revestimiento o pasar un caño por una pared ya terminada.',
        ],
        [
            'title' => 'Características',
            'content' => 'La punta tiene forma de lanza y corta el esmalte por raspado en vez de golpearlo. Se usa con el taladro en rotación pura, sin percusión, a pocas vueltas y mojando la zona con agua o con una esponja para que la punta no se recaliente y pierda el filo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El diámetro de diez milímetros es el de los tarugos medianos, los que se usan para un mueble colgado con algo de carga. Una mecha común de mampostería sobre un azulejo primero patina y después lo parte de punta a punta: no es la misma herramienta ni se puede reemplazar.',
        ],
    ],

    'ESPATULA ENDUIR 140MM BIASSONI' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Extiende masilla sobre la pared para tapar fisuras, agujeros de tarugo y desparejos antes de pintar. La hoja flexible copia la superficie y deja el material estirado y parejo, sin dejar las marcas de borde que después se notan con la pintura ya puesta.',
        ],
        [
            'title' => 'Características',
            'content' => 'La hoja es ancha y elástica, de acero fino que se arquea al apoyarla contra el muro, montada sobre un mango que se toma con la palma completa. Después de usarla hay que lavarla enseguida: el material seco sobre el filo deja mellas que se copian en cada pasada.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Este ancho es el punto medio para interiores: cubre superficie en cada pasada y todavía entra al costado de un marco o de un zócalo. Para masillar una pared completa en pocas manos hace falta una llana grande, que es otra herramienta y se toma con las dos manos.',
        ],
    ],

    'ESPATULA PARA JUNTAS 150MM CONST. EN SECO BIASSONI' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es la que se usa para tomar la unión entre dos placas de yeso: aplicar el material, pegar encima la cinta de papel y sacarle el sobrante de un solo lado sin levantar la cinta. También tapa las cabezas de los tornillos que quedan hundidas en la placa.',
        ],
        [
            'title' => 'Características',
            'content' => 'La hoja tiene más rigidez y un perfil pensado para trabajar sobre una banda angosta, siguiendo la línea sin hundirse en el medio y sin dejar el centro más lleno que los bordes. El mango está armado para empujar con fuerza sin que la hoja se vuelque de costado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se distingue de una espátula de masillado común porque acá el trabajo es sobre una franja larga y no sobre una superficie abierta. Para construcción en seco es la correcta; para tapar un agujero suelto en un revoque grueso rinde más una hoja bastante más flexible.',
        ],
    ],

    'LLAVE T 10MM GARDEX' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Afloja y aprieta tuercas y bulones de una sola medida, con las dos manos y en el eje del tornillo. Es lo que se usa en un taller de motos o de máquinas de jardín, donde el mismo tamaño de cabeza aparece veinte veces en el mismo trabajo.',
        ],
        [
            'title' => 'Características',
            'content' => 'El cuerpo forma una te: el tubo abajo y la barra transversal arriba, para hacer girar rápido con los dedos y después terminar de apretar con las dos manos. El vástago largo alcanza bulones hundidos donde una llave de boca común no llega a entrar.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Rinde donde la misma medida se repite todo el tiempo, porque se trabaja mucho más rápido que buscando el tubo suelto dentro del juego. Para cabezas de medidas variadas no sirve, porque tiene una sola boca y no se le puede cambiar el vaso.',
        ],
    ],

    'DISCO DIAMANTADO KEX 3 EN 1 9\"' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Corta materiales duros de obra: cerámica, porcelanato, ladrillo, bloque y piso de hormigón. Se usa para abrir la canaleta de un caño en una pared, recortar una baldosa a medida antes de colocarla o pasar de lado a lado un cordón de vereda.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es de nueve pulgadas, medida de amoladora grande, con el borde cargado de granos de diamante en lugar de dientes. Trabaja por abrasión, no por corte, y de ahí que avance en materiales que a cualquier hoja de acero se la comen en un par de minutos.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El diámetro grande es lo que da profundidad: permite atravesar un piso o un escalón de una sola pasada. En una amoladora chica no se puede montar por diámetro y por revoluciones, y para un trabajo de precisión sobre una cerámica delgada resulta demasiado pesado de manejar.',
        ],
    ],

    'SOPORTE D/PARED PARA TV,RECLINABLE 43 X 100,ONEBOX' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Cuelga el televisor de la pared y deja inclinar la pantalla hacia abajo. Resuelve dos cosas juntas: el reflejo de la ventana sobre el vidrio y la incomodidad de mirar de abajo hacia arriba cuando el aparato quedó alto, encima de un hogar o en un dormitorio.',
        ],
        [
            'title' => 'Características',
            'content' => 'Cubre un rango amplio de tamaños de pantalla y se fija con los agujeros normalizados que traen los televisores atrás. Viene con los tornillos y los tarugos, y una vez colgado el brazo permite variar el ángulo vertical sin desmontar nada.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es el punto medio entre el modelo fijo, que deja la pantalla pegada al muro sin ninguna regulación, y el articulado de brazo extensible, que se separa y gira pero cuesta bastante más. Va bien cuando el aparato queda en alto y no hace falta moverlo hacia los costados.',
        ],
    ],

    'DISCO  KEX FIBRA REMOVEDOR 115x2.2 mm' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Saca lo que quedó pegado sobre una superficie metálica sin comerse el material de abajo: pegamento viejo, sellador endurecido, pintura descascarada, restos de junta quemada y calcomanías. Deja la chapa limpia y lista para volver a sellar o para pintar.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es de fibra prensada, blando comparado con uno de corte, y del diámetro que entra en la amoladora chica de una mano. Se va desgastando a medida que trabaja y por eso pierde diámetro con el uso, cosa que es normal y no señal de que venga fallado.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'No corta ni desbasta metal: si hay que partir un caño o rebajar un cordón de soldadura, no es la pieza y se va a gastar sin avanzar. Su ventaja es exactamente la contraria, limpiar la superficie sin dejar surcos ni deformar la chapa fina.',
        ],
    ],

    'PRECINTO 200 x 4.8' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Agrupa y sujeta cables, mangueras finas y bolsas con un lazo que aprieta y no se afloja solo. Se usa para ordenar el cableado detrás de un rack, atar el ramal de una instalación contra la estructura o cerrar una bolsa que ya perdió el cierre original.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es el largo corto de la familia: alcanza para rodear un manojo de pocos centímetros de diámetro. El cabezal lleva un diente que traba la tira en un solo sentido, así que una vez ajustado no se puede aflojar y hay que cortarlo con pinza para sacarlo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la medida de uso diario para atados chicos, y como sobra poca tira el trabajo queda prolijo sin tener que recortar nada. Si el mazo es grueso, la tira no llega a dar la vuelta completa y hay que ir al largo mayor o encadenar dos, que queda flojo.',
        ],
    ],

    'PRECINTO 400 x 4.8' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Sujeta manojos gruesos y objetos de buen diámetro: un rollo de manguera enrollado, una media sombra contra el tejido, un bulto atado a un pallet o el mazo grande de conductores que baja de un tablero. La vuelta larga deja margen para rodear formas irregulares.',
        ],
        [
            'title' => 'Características',
            'content' => 'Comparte el ancho de tira con la versión corta, así que soporta la misma carga, pero el largo permite abarcar un perímetro bastante mayor de una sola vuelta. También se puede dejar flojo y usar como colgador improvisado de algo liviano.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se justifica cuando el atado es voluminoso, o cuando conviene una vuelta larga en lugar de encadenar dos cortos, que siempre dejan un punto débil en la unión de los dos cabezales. Para atados chicos desperdicia material y obliga a recortar mucho sobrante.',
        ],
    ],

    'CERRADURA PRIVE  DESTRABADOR ELECTRICO 122 8 A 12 VOLTS - 5WATTS' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Libera la puerta de calle a distancia cuando alguien aprieta el botón del portero desde adentro. Es lo que permite abrirle a una visita sin bajar la escalera, y lo que usan los edificios, los consultorios y los locales que trabajan con la puerta cerrada al público.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es un pestillo que se destraba al recibir tensión de baja intensidad, del rango que entrega la fuente de un portero eléctrico. Se embute en el marco, del lado del cerradero, y se alimenta con dos cables finos que suben hasta el aparato del interior.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Reemplaza a un cerradero fijo cuando se quiere sumar apertura remota a una puerta que ya está colocada, sin cambiar ni la cerradura ni la manija. Hay que verificar la tensión que entrega el equipo instalado: fuera de ese rango no destraba, o zumba y se termina quemando.',
        ],
    ],

    'ESPATULA 70mm REMACHADO M/MADERA BIASSONI' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Raspa y aplica en superficies angostas: sacar pintura descascarada del marco de una ventana, limpiar el asiento de un burlete, despegar restos secos o llegar al fondo de un rincón donde una hoja ancha no apoya plana y trabaja de punta.',
        ],
        [
            'title' => 'Características',
            'content' => 'La hoja va unida al cabo de madera con remaches, que es la unión que aguanta cuando se hace palanca o se le pega con la palma en el extremo del mango. La madera se agarra bien incluso con las manos manchadas de pintura o de solvente.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'El ancho reducido es lo que deja trabajar en zonas de pocos centímetros y concentrar la fuerza sobre un punto duro que no cede. Para estirar material sobre una pared abierta deja marca de borde en cada pasada, y ahí corresponde una hoja bastante más ancha.',
        ],
    ],

    'ESPATULA 80mm REMACHADO M/MADERA BIASSONI' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Sirve para raspar y emparejar en superficies medianas: un tramo de zócalo, la hoja de una puerta, la tapa de un mueble antes de lijar. Cubre un poco más de ancho por pasada sin perder la posibilidad de meterse en un espacio acotado.',
        ],
        [
            'title' => 'Características',
            'content' => 'Repite la construcción con cabo de madera unido por remaches, que no se afloja aunque se la use de palanca. La hoja tiene rigidez suficiente para acompañar la mano cuando hay que empujar contra material seco y bien pegado a la superficie.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se prefiere sobre la más angosta cuando el trabajo es una superficie continua y no un rincón cerrado: rinde más por pasada y deja menos surcos que después hay que emparejar. En un ángulo cerrado o un marco fino no entra cómoda y ahí conviene la chica.',
        ],
    ],

    'PISTOLA APLICADORA GARDEX P/ADHESIVO' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Empuja el contenido de un cartucho de silicona, adhesivo o sellador de forma pareja y controlada, para que el cordón salga del mismo espesor de punta a punta. Es lo que hace falta para sellar el perímetro de una bañera, pegar un zócalo sin clavos o rellenar la junta de una mesada.',
        ],
        [
            'title' => 'Características',
            'content' => 'El pomo se apoya en la cuna y un vástago accionado por gatillo empuja el pistón desde atrás. Al liberar la traba, la presión cede y el material deja de salir. Suele traer el punzón para romper el sello interno del envase antes de colocarle el pico.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Sin ella el cartucho no se puede aplicar de ninguna manera práctica, así que no es opcional. Conviene la que tiene alivio de presión al soltar el gatillo: sin ese mecanismo el material sigue saliendo unos segundos y ensucia todo lo que se acaba de hacer.',
        ],
    ],

    'SOPAPA AMERICANA 9 CM AC INOX' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Es el tapón con válvula que se coloca en el agujero de desagüe de una bacha o de una bañera. Retiene el agua cuando se cierra y la deja correr cuando se abre. Se reemplaza cuando el original quedó picado y ya no sella, o cuando se perdió la goma de abajo.',
        ],
        [
            'title' => 'Características',
            'content' => 'El cuerpo es de acero inoxidable, que en contacto permanente con agua y detergente no se pica como el metal cromado ni se pone verde. Los nueve centímetros corresponden al plato de arriba, que es el que apoya sobre el borde del agujero y hace el sello.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la medida chica de la familia, la que corresponde a bachas de baño, piletas de lavar ropa y bañeras con desagüe común. En una pileta de cocina de boca grande queda flojo, apoya mal contra el borde y el agua se escurre por el costado igual.',
        ],
    ],

    'SOPAPA AMERICANA 11 CM AC INOX C/REJILLA BLU' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Tapa el desagüe de una pileta de cocina o de una bacha de boca grande, y con la canasta puesta retiene los restos de comida y el pelo antes de que se vayan al caño. Es lo que evita tener que destapar el sifón cada dos por tres.',
        ],
        [
            'title' => 'Características',
            'content' => 'El plato de once centímetros trae una canasta perforada que se levanta con dos dedos para vaciar lo que juntó. El conjunto entero es inoxidable, así que se lava con detergente y esponja sin que pierda el brillo ni se manche con el tiempo.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Se prefiere sobre la versión lisa cuando el uso es de cocina, donde lo que tapa el caño no es el agua sino todo lo que se va con ella. Esa canasta filtrante es la diferencia que ahorra el destapado con soda cáustica y el desarme del sifón.',
        ],
    ],

    'TIMBRE INALAMBRICO REDONDO CANDELA' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Avisa adentro de la casa que alguien llegó, sin necesidad de pasar un cable desde la puerta hasta el interior. El pulsador se pega o se atornilla afuera y el aparato que suena se enchufa donde mejor se escuche, y se puede mudar de ambiente cuando haga falta.',
        ],
        [
            'title' => 'Características',
            'content' => 'Funciona con una señal de radio entre el botón de afuera y el receptor de adentro, con alcance que cubre una casa con patio. Trae varias melodías a elección y volumen regulable, y el botón se alimenta con una pila chica que dura meses de uso normal.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es la salida cuando no se puede o no se quiere romper la pared para pasar el cable, algo que en una casa alquilada, una oficina en altura o un local revestido resuelve el problema entero. Si la entrada queda muy lejos o hay muros gruesos, conviene probar el alcance primero.',
        ],
    ],

    'CINTA MULTIPROPOSITO BLANCO 48MM X 9Mts. TACSA DUCTAC' => [
        [
            'title' => 'Para qué sirve',
            'content' => 'Pega, une y repara casi cualquier cosa de manera provisoria y sin herramientas: una manguera pinchada, una lona rota, el tubo flexible de una aspiradora, el borde levantado de una alfombra o un bulto que se abrió en el camino.',
        ],
        [
            'title' => 'Características',
            'content' => 'Es de tela plastificada, lo que le da cuerpo y permite cortarla a mano en el ancho que haga falta, sin tijera. La tira de casi cinco centímetros cubre bien de una sola pasada y se adhiere sobre superficies secas y ásperas donde una cinta común se despega sola.',
        ],
        [
            'title' => 'Cuándo elegirlo',
            'content' => 'Es para el arreglo que tiene que aguantar hasta la reparación de verdad, no para quedarse puesto para siempre. Al despegarla puede dejar restos de adhesivo, así que sobre una superficie delicada o recién pintada conviene probar antes en un lugar poco visible.',
        ],
    ],

];
