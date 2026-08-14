<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Misión 54 — llena `description`, `modulo` y `en_desuso` del catálogo de extensiones.
 *
 * De dónde salen los textos: de `contexto/extensiones_comportamiento.md`, que produjo la misión 53
 * leyendo el código de `empresa-api` (ff41b1e) y `empresa-spa` (292830b). Acá NO se escribe ni se
 * mejora ninguna descripción: se copian. Si una extensión de la base no está en el padrón, se
 * queda con `description` en null y se reporta — inventarle una descripción plausible es
 * exactamente lo que la misión 53 se prohibió a sí misma.
 *
 * Este es el seeder de las bases NUEVAS: lo llama `DatabaseSeeder::common_seeders()` después de
 * los seeders que insertan las filas. Para las bases de producción que ya existen está
 * `ExtencionEmpresaDescriptionProduccionSeeder`, que corre esta misma lógica suelta y además
 * informa qué slugs de la base del cliente quedaron fuera del padrón.
 *
 * Es idempotente y NO toca `extencion_empresa_user`: actualiza por `slug` con el query builder,
 * así que las extensiones que cada cliente tiene asignadas quedan como estaban.
 */
class ExtencionEmpresaDescriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $resultado = $this->aplicar();

        Log::info(
            'ExtencionEmpresaDescriptionSeeder: ' . $resultado['actualizadas'] . ' extension(es) actualizada(s), '
            . count($resultado['faltantes']) . ' del padron no estan en la base'
        );
    }

    /**
     * Escribe el padrón sobre la tabla y devuelve qué pasó con cada slug.
     *
     * Se fija primero si la fila existe en vez de mirar cuántas filas afectó el update: MySQL
     * cuenta las filas CAMBIADAS, así que una segunda corrida devolvería cero para todas y
     * parecería que la base no tiene ninguna extensión.
     *
     * Idempotente en los valores, con una sola salvedad: `update()` pisa `updated_at` de las
     * filas que toca. Nada lee esa columna para extensiones, pero "correrlo dos veces deja la
     * base igual" vale para el contenido, no para la marca de tiempo.
     *
     * @return array  con las claves `actualizadas` (int) y `faltantes` (array de slugs)
     */
    public function aplicar()
    {
        $actualizadas = 0;
        $faltantes    = [];

        foreach (self::padron() as $fila) {

            $existe = ExtencionEmpresa::where('slug', $fila['slug'])->exists();

            if (!$existe) {
                $faltantes[] = $fila['slug'];
                continue;
            }

            ExtencionEmpresa::where('slug', $fila['slug'])
                ->update([
                    'description' => $fila['description'],
                    'modulo'      => $fila['modulo'],
                    'en_desuso'   => $fila['en_desuso'],
                ]);

            $actualizadas++;
        }

        return [
            'actualizadas' => $actualizadas,
            'faltantes'    => $faltantes,
        ];
    }

    /**
     * Los slugs de la base que NO están en el padrón, o sea las que quedarían sin descripción.
     *
     * @return array
     */
    public static function slugs_sin_descripcion()
    {
        $del_padron = [];
        foreach (self::padron() as $fila) {
            $del_padron[] = $fila['slug'];
        }

        return ExtencionEmpresa::whereNotIn('slug', $del_padron)
            ->pluck('slug')
            ->toArray();
    }

    /**
     * El padrón: una entrada por extensión descripta por la misión 53.
     *
     * `en_desuso` en true son las que ese trabajo dejó con `estado: sin_uso`, o sea las que
     * ningún código lee. Eran once y no las cinco que la misión 54 anticipaba: a las cinco sin
     * referencias se sumaron seis que el rastreo automático contaba como vivas.
     *
     * 🔴 SON 91, PERO NO SON LOS MISMOS 91 QUE TIENE EL CATÁLOGO. La coincidencia del número
     * tapa dos huecos que se compensan, y conviene saberlos antes de leer "91 de 91":
     *
     *  - `unidades_individuales_en_articulos` está en el padrón y NO existe como fila: su
     *    entrada de `ExtencionSeeder` está comentada. El seeder la reporta como faltante.
     *  - `duplicar_presupuestos` existe como fila (la siembra `ExtencionDuplicarPresupuestosSeeder`,
     *    llamado desde `DatabaseSeeder`) y NO está en el padrón: la misión 53 no le escribió
     *    entrada. Queda con `description` en null, que es lo que corresponde — inventarle una
     *    descripción plausible es lo que ese trabajo se prohibió a sí mismo. Ficha:
     *    `prompts/hallazgos/20260813-duplicar-presupuestos-quedo-sin-entrada-en-el-padron-de-la-53.json`.
     *
     * O sea que la cobertura real sobre una base sembrada es 90 de 91.
     *
     * @return array
     */
    public static function padron()
    {
        return [
            [
                'slug'        => 'check_article_stock_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida IMPIDE agregar el articulo: en VENDER (y solo si no se esta guardando como presupuesto), al agregar un articulo con stock menor o igual a cero muestra el toast \'Articulo sin stock, NO se agrego\', limpia el input del codigo de barras y corta el flujo, asi que el item nunca entra a la venta; lo mismo al confirmar la cantidad, si el stock del articulo (o el del deposito/sucursal seleccionada) es menor a la cantidad pedida, avisa \'Solo hay X en stock\' / \'No hay stock en <sucursal>\' y no lo agrega, y si se edita la cantidad en la tabla se la fuerza a 0. Apagada (y sin warn_article_stock_en_vender encendida) el sistema ni siquiera evalua el stock: se puede vender cualquier cantidad de cualquier articulo sin un solo aviso. Se ve en VENDER, en la barra de carga por codigo de barras/nombre, al elegir un combo y en la columna Cantidad de la tabla de articulos del remito.',
            ],
            [
                'slug'        => 'warn_article_stock_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida solo ADVIERTE, no bloquea: habilita la evaluacion de stock en VENDER (no se aplica si se guarda como presupuesto) y muestra los toasts \'Articulo sin stock\', \'El articulo X no tiene stock\', \'Solo hay X en stock\' o \'No hay stock en <sucursal>\', pero devuelve true en todos los casos, asi que el articulo se agrega igual y la venta sigue. Apagada (y sin check_article_stock_en_vender) no se evalua nada y el vendedor no recibe ningun aviso al vender sin stock. Se ve en VENDER, al cargar articulos o combos y al modificar la cantidad de un item en la tabla del remito.',
            ],
            [
                'slug'        => 'check_guardar_ventas_con_cliente',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, al apretar Guardar en VENDER se corta el guardado si no hay cliente seleccionado: muestra \'Asigne un cliente para esta venta\' y no manda la venta al API, salvo que el empleado tenga tildado \'puede_guardar_ventas_sin_cliente\' o sea dueño/admin. Ademas hace aparecer ese checkbox \'puede_guardar_ventas_sin_cliente\' en el formulario de Empleados. Apagada se puede guardar cualquier venta sin cliente y el checkbox no se muestra en el ABM de empleados.',
            ],
            [
                'slug'        => 'check_sales',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida activa el circuito de chequeo por deposito. Las ventas creadas al convertir un presupuesto nacen con to_check=1 y terminada=0 y no descuentan stock al crearse; lo mismo las creadas al bajar un pedido online, donde el to_check sale directo de hasExtencion. En VENDER aparece, bajo el total, el bloque con los tres checkboxes \'Para chequear\' / \'Chequeada\' / \'Cofirmada\' (asi, con el typo tal cual esta en el codigo), y mientras la venta este en to_check o checked el API no descuenta stock de los articulos. Ademas suma al menu las pantallas \'Deposito\' (para checkear) y \'Checkeadas\'; suma la columna \'U. chequeadas\' en la tabla de items del remito; precarga checked_amount cuando la cantidad vendida del presupuesto supera el stock actual; y cuando la venta se confirma le pone printed=1 y dispara la notificacion de alta (setPrinted). Las tres visibilidades piden condiciones extra ademas de la extension: el bloque de checkboxes exige que no se este guardando como presupuesto y que la venta previa no este ya terminada; las dos pantallas del menu exigen tambien los permisos deposito_para_checkear y deposito_checkeadas; y la columna \'U. chequeadas\' exige estar parado en una venta previa (index_previus_sales > 0) no confirmada y en estado to_check o checked. Apagada, la venta nace confirmada y descuenta stock al guardarla, y ni los checkboxes ni las pantallas de deposito existen; nace terminada=1 SALVO que la cuenta tenga ademas ventas_con_fecha_de_entrega y la venta traiga fecha_entrega, porque get_terminada tiene un segundo return 0 independiente para esa otra extension.',
            ],
            [
                'slug'        => 'forzar_total',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida muestra un boton verde \'Forzar\' al lado del total de VENDER (solo si el total es mayor a 0); al apretarlo se abre el modal \'Forzar Total\', se escribe el precio final deseado y el sistema calcula el porcentaje de descuento equivalente y lo guarda en vender/descuento, que despues se resta del total de los articulos y viaja al API en el campo \'descuento\' de la venta. Ademas hace visible la propiedad \'Descuento\' en la ficha/tabla de la venta. Apagada no hay boton para forzar el total (solo se puede llegar al numero deseado a mano con descuentos por articulo o de venta) y la propiedad \'Descuento\' no se muestra.',
            ],
            [
                'slug'        => 'hide_iva_and_discount_stock_in_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Es una extension negativa: encendida OCULTA en VENDER los dos toggles \'Precios con IVA\' y \'Descontar stock\' (los computed can_use_iva_aplicado / can_use_discount_stock devuelven false y, si ninguno queda habilitado, desaparece todo el contenedor). No cambia el valor de esos campos: la venta se guarda con lo que ya tenga el store (por defecto descontar stock activado), solo que el vendedor no puede tocarlo. Apagada, los dos toggles se muestran siempre que el usuario tenga los permisos vender.iva_aplicado y vender.discount_stock respectivamente.',
            ],
            [
                'slug'        => 'maximo_descuento_posible_por_articulo_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, en la columna de descuento (%) de cada articulo de la tabla del remito de VENDER, el placeholder del input muestra entre parentesis el porcentaje maximo que se le puede descontar a ese item sin bajar el precio de venta por debajo del costo, calculado como 100 - (costo / price_vender * 100), por ejemplo \'(35.71)\'. Solo aparece si el item tiene cost cargado. Apagada el placeholder queda vacio y no se calcula nada. Es puramente informativo: no valida ni limita el descuento que el vendedor efectivamente escribe, se puede tipear un numero mayor y la venta se guarda igual.',
            ],
            [
                'slug'        => 'balanza_bar_code',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: en /vender/buscar-articulo-por-codido/{code}, si la busqueda normal NO encontro ningun articulo y el codigo arranca con el prefijo \'22\', lo trata como etiqueta de balanza con precio embebido: toma los ULTIMOS 8 caracteres del codigo y de esos se queda con los PRIMEROS 7, casteados con intval, y eso es el precio. Responde 200 con from_balanza:true, price_vender y un articulo de id HARDCODEADO: 60 si APP_ENV es local, 6346 en cualquier otro caso (comentado \'Id de la carniceria\'), buscado por id y sin filtrar por user_id. No valida largo del codigo: cualquier cosa que empiece con \'22\' entra. En VENDER el front no agrega ningun item: set_from_balanza busca ese articulo hardcodeado entre los items ya cargados en la venta y, si esta, le pisa price_vender y price_vender_personalizado, recalcula el total y limpia el input; si NO esta cargado no pasa nada visible — no se agrega el item, no suena el error y no sale el toast \'No se encontro articulo\', porque la bandera from_balanza apaga las dos ramas de set_article_from_barcode, y el codigo escaneado queda escrito en el input. Apagada: no se hace el parseo de PRECIO de balanza; el codigo \'22...\' sigue el camino comun y termina en el toast \'No se encontro articulo\' con sonido de error, SALVO que la cuenta tenga plu_balanza_bar_code, en cuyo caso un \'22...\' de 12 caracteres o mas cae igual en check_balanza_plu y se parsea como etiqueta de PLU + peso. Al reves tambien importa: con las dos prendidas, balanza_bar_code se chequea primero y se queda con todos los codigos \'22...\' antes de que plu los vea, mientras exista el articulo hardcodeado. Cortocircuito: si la cuenta tiene usar_articles_cache prendido (o esta offline), VENDER busca solo en Dexie por bar_code y no pega al endpoint, asi que la extension no hace nada ahi (plu_balanza_bar_code es la unica de las tres que tiene rama offline propia).',
            ],
            [
                'slug'        => 'plu_balanza_bar_code',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: si el codigo escaneado no matcheo ningun articulo y tiene 12 o mas caracteres, lo parsea como etiqueta PLU de balanza (2 digitos de tipo de balanza + 5 de PLU + 5 de peso), busca el articulo del usuario por la columna `plu`, y responde from_balanza_plu:true con el `amount` ya calculado (dividido por 1000 salvo que unidad_medida_id == 2, o sea gramos); el front agrega el item con esa cantidad sin preguntar cantidad. Ademas habilita el campo \'PLU\' en el formulario de articulos. Apagada: no se parsea el codigo, no existe el campo PLU en el ABM de articulos, y el codigo no encontrado termina en \'No se encontro articulo\'.',
            ],
            [
                'slug'        => 'codigos_de_barra_por_defecto',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: al CREAR un articulo (ArticleController::store), si el campo bar_code vino null o vacio, el sistema le escribe como codigo de barras el id del articulo recien creado y lo guarda. Apagada: el articulo queda directamente sin codigo de barras (bar_code null). Se ve en el ABM de articulos, al dar de alta un producto sin completar el campo \'Codigo de barras\'.',
            ],
            [
                'slug'        => 'codigos_de_barra_basados_en_numero_interno',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: cambia como el endpoint /vender/buscar-articulo-por-codido/{code} resuelve el codigo escaneado, en dos ramas excluyentes. (1) Si el codigo EMPIEZA CON \'0\', lo interpreta como \'0\' + id de variante: hace ArticleVariant::find(substr($code,1)) y, si la variante existe, filtra el articulo por su article_id y responde con variant_id para que el front agregue esa variante. OJO: si ese id de variante no existe, el if no tiene else, asi que al query de articulo no se le agrega NINGUNA condicion mas alla de user_id y el ->first() devuelve el PRIMER articulo del usuario; el endpoint responde 200 con un articulo arbitrario y el front lo agrega a la venta como si fuera el escaneado. No es un caso de borde raro: todo EAN-13 derivado de UPC-A arranca con \'0\'. Ademas, con la extension prendida un codigo que arranque con \'0\' NUNCA se busca por num ni por bar_code, porque esas ramas estan en el else. (2) Si el codigo no empieza con \'0\': primero se prueba matchear una variante por article_variants.bar_code y, solo si ninguna matcheo, se busca el articulo por la columna `num` (numero interno) en lugar de por bar_code; esa rama tambien le gana a codigo_proveedor_en_vender, que queda mas abajo en la cadena else-if. Apagada: se hace la misma prueba de variante por article_variants.bar_code y, si no matchea, se busca por articles.provider_code si la cuenta tiene codigo_proveedor_en_vender, o por articles.bar_code en el caso por defecto; ningun codigo se interpreta como id de variante. Cortocircuitos que hay que tener en cuenta: en VENDER, si la cuenta tiene usar_articles_cache prendido (o el navegador esta offline), ArticleBarCode busca solo en Dexie por la columna bar_code y no pega nunca al endpoint, asi que la extension no hace absolutamente nada en el input de VENDER; en el listado pasa lo mismo si download_articles esta prendido, porque BarcodeSearch filtra los articulos ya descargados por bar_code en memoria.',
            ],
            [
                'slug'        => 'no_usar_codigos_de_barra',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: saca los codigos de barra de la interfaz de venta — oculta por completo el input \'Cod barras\' del header de VENDER, ensancha en 3 columnas el buscador por nombre para ocupar ese lugar, manda el foco al buscador por nombre (y no al input de codigo) cada vez que se limpia el item, quita la columna \'Cod Barras\' de los resultados del buscador de articulos, hace que el listado arranque con el buscador por NOMBRE en vez del de codigo, y en los PDF de venta imprime provider_code en la columna \'Codigo\' en lugar del bar_code. Apagada: el input de codigo esta visible y se lleva el foco, aparece la columna \'Cod Barras\' en el buscador, el listado arranca con buscador por codigo y los PDF imprimen el bar_code. Se ve en VENDER (header del remito), listado de articulos y PDF/ticket de venta.',
            ],
            [
                'slug'        => 'bar_code_scanner',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: agrega un boton de camara al lado de los inputs de codigo. Al apretarlo abre el modal \'Escanear Codigo\', que pide la camara trasera (getUserMedia facingMode environment) y detecta con BarcodeDetector nativo o, si no hay, con el polyfill que carga public/index.html desde un CDN; formatos code_128, code_39, ean_13, itf, qr_code y upc_a. Al leer el primer codigo emite el valor y cierra el modal. Que pasa con ese valor depende del lugar y NO es lo mismo en los tres: (a) en el ABM de articulos y en el modal de filtros del listado, el codigo NO va al input desde el que abriste el escaner: va SIEMPRE al campo/filtro \'Codigo de barras\'. ModelForm y FilterModal resuelven el destino con getBarCodeProp, que devuelve la PRIMERA prop del modelo con use_bar_code_scanner, y en article.js esa primera es bar_code (:33), no sku (:46). O sea que si apretas la camara al lado de SKU, el codigo se escribe en Codigo de barras. (b) En VENDER el boton se renderiza pero la lectura no hace nada: ArticleBarCode.setBarCode llama a this.getArticleFromCodigo(), metodo que no existe en ningun lado del repo, asi que tira TypeError; como setBarCode es async, queda como promesa rechazada que Vue manda al error handler global. El modal igual se cierra (lo cierra el propio ScannerModal despues de emitir), pero el input queda vacio, no se busca el articulo, no se agrega el item y no aparece ningun toast: falla en silencio para el usuario. Apagada: no se renderiza ningun boton de camara en ninguno de los tres lugares y el codigo se tipea a mano o lo mete un lector fisico, que actua como teclado y no depende de esta extension. Ojo con un cortocircuito en VENDER: si la cuenta tiene no_usar_codigos_de_barra, ArticleBarCode.vue:5 no renderiza la columna entera, asi que el boton no aparece aunque bar_code_scanner este prendida.',
            ],
            [
                'slug'        => 'bar_codes_in_vender_table',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: la propiedad bar_code queda disponible como columna en la tabla de items de VENDER y aparece marcada como visible por defecto, ademas de figurar en el selector \'Propiedades para mostrar\' del topbar de VENDER. Apagada: check_extencions filtra esa propiedad, asi que la columna \'Cod Barras\' no se muestra en la tabla de items ni se puede activar desde el configurador de columnas. Se ve en VENDER, en la grilla de articulos ya cargados en el remito.',
            ],
            [
                'slug'        => 'search_bar_code_en_vender',
                'modulo'      => 'Codigos de barra',
                'en_desuso'   => false,
                'description' => 'Encendida: en el buscador POR NOMBRE de VENDER (POST /vender/buscar-articulo-por-nombre), suma el bar_code del articulo y el de sus variantes como criterio de busqueda — con una sola palabra el match es exacto (orWhere(\'bar_code\', $keyword)), con varias palabras es LIKE %palabra% — y ademas, si con una sola palabra esa palabra coincide exacto con el bar_code de una variante disponible, devuelve solo esa variante en vez de todo el abanico de variantes del articulo. Apagada: ese buscador solo mira name y provider_code (mas descripcion si esta search_descripcion_en_vender), asi que tipear un codigo de barras en el buscador por nombre no encuentra nada. Se ve en VENDER, en el input de busqueda por nombre del header del remito.',
            ],
            [
                'slug'        => 'search_descripcion_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, el buscador de articulos por nombre de VENDER (POST vender/buscar-articulo-por-nombre) suma la columna descripcion al WHERE: ademas de matchear name y provider_code, matchea cada palabra tipeada contra el texto largo del articulo. Apagada, el mismo endpoint busca unicamente por name y provider_code, asi que un articulo cuyo nombre no contiene la palabra no aparece aunque la tenga en la descripcion. Es 100% backend: no hay ni un hasExtencion de este slug en la SPA, no cambia nada visual.',
            ],
            [
                'slug'        => 'buscar_por_categoria_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida hace dos cosas distintas. (a) En VENDER agrega, pegados al buscador de articulos por nombre, dos combos: uno de categoria y otro de stock con las opciones \'Con o sin Stock\' / \'Con Stock\' / \'Que hayan tenido Stock\'; lo que elige el usuario viaja como category_id y stock_option en el body del POST a vender/buscar-articulo-por-nombre y recorta los resultados del modal. (b) En el modulo LISTADO de articulos hace aparecer una fila entera con un buscador propio que pega a esa misma ruta y carga el resultado en article/filtered, o sea filtra la tabla contra la API en vez de filtrar en memoria (ese buscador tambien lleva los dos combos al lado del input). Apagada, ni los combos ni la fila del listado se renderizan, pero los dos parametros se mandan igual con los valores por defecto hardcodeados en el data() del buscador (category_id: 0 y stock_option: \'con_o_sin_stock\'), porque el codigo que arma el body los copia sin consultar ninguna extension. Igual no filtran nada, y por dos motivos DISTINTOS: category_id=0 es falsy y el back ni entra al if; \'con_o_sin_stock\' en cambio SI es truthy y entra al if de stock_option, solo que adentro no matchea ninguna de las dos ramas (\'con_stock\' / \'hayan_tenido_stock\') y sale sin agregar ningun where. No cambia por que campo se busca el texto: solo acota el resultado.',
            ],
            [
                'slug'        => 'codigo_proveedor_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, el input de codigo del encabezado de VENDER pasa a trabajar contra el codigo del proveedor y no contra el codigo de barras: el placeholder dice \'Cod proveedor\', el texto tipeado se manda tal cual (no se le sacan los espacios) y el endpoint de escaneo busca el articulo por la columna provider_code; ademas, al elegir un articulo desde el buscador por nombre, en ese input se escribe el provider_code, y en los PDF de venta (remito y ticket AFIP) la columna \'Codigo\' imprime provider_code. Apagada, todo eso trabaja con bar_code: placeholder \'Cod barras\', se buscan coincidencias en la columna bar_code y el PDF imprime el codigo de barras. Ojo que en la cadena del backend la extension de codigos basados en numero interno gana primero, asi que si la cuenta tiene las dos, esta no se aplica al escaneo.',
            ],
            [
                'slug'        => 'atajo_buscar_por_nombre',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, el buscador chico del encabezado del LISTADO de articulos se renderiza como busqueda por nombre (input con placeholder \'Nombre\'): al apretar ENTER, si la cuenta descarga los articulos filtra en memoria con includes() sobre article.name; si no, arma un filtro de texto sobre la columna name ({type:\'text\', key:\'name\', que_contenga}) y dispara el filtro paginado del store (article/runFilter). Apagada, ese mismo lugar muestra la busqueda por codigo de barras (input \'Codigo de barras\'), que al ENTER pega a vender/buscar-articulo-por-codido, deja como unico resultado el articulo de ese codigo, agrega el filtro bar_code igual_que y lo autoselecciona si esta activo add_buscador_to_selected. Eso es TODO lo que hace: su unica linea de codigo en los dos repos es un termino de un OR de tres. Por eso apagada no cambia nada en dos casos muy comunes: si la cuenta tiene no_usar_codigos_de_barra, o si la ventana mide menos de 700px de ancho (is_mobile), el encabezado ya muestra la busqueda por nombre igual. Y prenderla en una cuenta que ya tiene no_usar_codigos_de_barra no cambia absolutamente nada. Prendida sola si hace el swap, o sea que no es codigo muerto: es un subconjunto de otra extension.',
            ],
            [
                'slug'        => 'crear_articulos_desde_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida (y solo estando parado en la ruta \'vender\' y con el permiso de usuario vender.create_article), cuando el buscador de articulos por nombre no encuentra nada, el modal muestra el cartel \'ENTER para crear articulo\' y al apretar ENTER hace POST a search/save-if-not-exist/article/... con el texto tipeado como nombre, crea el articulo, lo agrega al store y lo selecciona para la venta. Apagada, el modal solo muestra \'No se encontraron resultados\' y el ENTER cae en el toast de error \'No hay resultados seleccionados\': hay que ir a cargar el articulo al listado y volver. Fuera de VENDER la bandera es siempre false, asi que la extension no habilita creacion al vuelo en ningun otro buscador de articulos.',
            ],
            [
                'slug'        => 'articles_default_in_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida, al arrancar sesion la SPA pide GET /articles-por-defecto y al entrar a VENDER (y despues de cada venta guardada y de cada \'limpiar\') agrega solos al remito todos los articulos marcados con default_in_vender, con cantidad 1 y precio vacio, reintentando hasta 5 veces cada 2s si el store todavia no cargo; ademas, al guardar, saca del remito los que quedaron sin precio, y habilita en el ABM de articulos los campos \'Por defecto en VENDER\' (que ademas define la posicion) y \'Siempre personalizar precio en VENDER\'. Apagada, VENDER arranca con el remito vacio, no se pide el endpoint de articulos por defecto y esos dos campos no aparecen en el formulario del articulo. Es el caso tipico de la panaderia/carniceria que quiere el pan o el pollo ya puesto en la pantalla.',
            ],
            [
                'slug'        => 'personalizar_nombre_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida (y con el permiso de usuario article.vender.change_name), la columna \'Nombre\' de la tabla del remito de VENDER deja de ser texto y pasa a ser un input editable por linea, con el nombre de catalogo como placeholder; lo que se escriba ahi viaja como name_vender_personalizado y el backend lo persiste en article_sale.name solo si difiere del nombre real del articulo, quedando ese nombre en el comprobante y en la venta reabierta. Apagada, la columna \'Nombre\' es texto de solo lectura con el nombre del articulo (mas la descripcion de variante si tiene) y no hay forma de cambiarlo por linea. Son dos capas: la extension es por empresa y el permiso por usuario, y falla con cualquiera de las dos apagada.',
            ],
            [
                'slug'        => 'varios_precios',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'Encendida, al apretar Enter en el input \'Personalizado\' de un item en la tabla de VENDER, el valor tipeado no queda como precio unico: se agrega como una fila mas a una lista varios_precios del item (cada fila con su propio precio y su propia cantidad), se limpia el input, y el precio del item pasa a ser calculated_price_vender = suma de precio x cantidad de todas las filas. Al guardar, la API attachea una fila de article_sale por cada uno de esos precios en vez de una sola por el articulo. Apagada, add_varios_precios() sale sin hacer nada: el Enter no acumula, no aparece el bloque .varios-precios y el item lleva un solo price_vender_personalizado.',
            ],
            [
                'slug'        => 'cambiar_price_type_en_vender',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'ENCENDIDA hace dos cosas: (a) en el modal de busqueda de articulos de VENDER saca la columna \'Precio\' (final_price), no agrega las columnas de descuento por metodo de pago y agrega una columna por cada lista de precios del owner; (b) en REPORTES agrega el grupo de tarjetas \'Listas de precio\' con el total vendido por cada lista. PERO (a) esta cortocircuitado para casi toda la poblacion que tiene esta extension: los tres if del buscador son un OR con list_price_extensions, que es hasExtencion(\'articulo_margen_de_ganancia_segun_lista_de_precios\') || hasExtencion(\'lista_de_precios_por_categoria\'), y la primera de esas dos ni siquiera consulta la tabla de extensiones -- hasExtencion la cortocircuita al flag owner.listas_de_precio (generals.js:388-391). O sea: si el dueno tiene listas_de_precio = 1 o tiene lista_de_precios_por_categoria, prender o apagar ESTA extension no cambia nada en el buscador: final_price se omite igual, las columnas por lista se agregan igual, y las de metodo de pago tampoco vuelven, porque solo se agregan si final_price NO esta omitida. Y vienen de a dos: el alta de usuarios con use_price_lists entrega \'articulo_margen_de_ganancia_segun_lista_de_precios\' y \'cambiar_price_type_en_vender\' juntas, ademas de poner listas_de_precio = 1. Asi que lo unico que depende de esta extension sola es el grupo de REPORTES, que ademas exige !lista_de_precios_por_categoria y que el reporte traiga ingresos_brutos_price_types. APAGADA: desaparece el grupo \'Listas de precio\' de REPORTES, siempre. Y el buscador vuelve a mostrar la columna \'Precio\' mas una columna por cada descuento por metodo de pago (y pierde las columnas por lista) SOLO si el dueno ademas no usa listas de precio ni tiene lista_de_precios_por_categoria; si las usa, apagarla no cambia nada ahi. Lo que promete el nombre -- mostrar el selector de lista en VENDER y aplicarla -- ya NO depende de esta extension: el selector se muestra siempre que exista price_type_vender (su return gateado quedo comentado) y la lista se aplica siempre (el if que la gateaba en aplicar_tipos_de_precio tambien esta comentado).',
            ],
            [
                'slug'        => 'cambiar_price_type_en_vender_item_por_item',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'Encendida, habilita la propiedad/columna \'Lista de precio individual\' (price_type_personalizado_id) en la tabla de articulos de VENDER: cada renglon muestra un select con las listas de precios y, al elegir una, ese item se recalcula con el precio de ESA lista (pisa la lista global de la venta) y se pinta de verde; el id elegido se guarda en el pivot article_sale.price_type_personalizado_id. Apagada, la propiedad se filtra del catalogo de columnas (check_extencions la descarta) y la columna no existe, asi que no se puede setear a mano ese campo desde VENDER y todos los items usan la lista global.',
            ],
            [
                'slug'        => 'lista_de_precios_por_categoria',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'Encendida, el precio de cada lista sale del margen cargado en la CATEGORIA (o subcategoria) del articulo y no del articulo ni de la lista: setFinalPrice llama a aplicar_precios_segun_listas_de_precios_y_categorias, que toma el pivot percentage de price_type_sub_category (con prioridad) o de price_type_category, calcula precio = costo + costo*%/100 y lo guarda en el pivot article_price_type; ademas, cada categoria/subcategoria nueva (creada a mano o por import) queda con todas las listas del owner attacheadas, aparece el campo \'Listas de Precio\' con margen en el ABM de categorias, y en el listado de articulos se ocultan \'Margen de ganancia\', \'Precio manual\', \'Precio final\', \'Precio final actualizado/anterior\' y \'Aplicar margen del proveedor\' reemplazados por una columna por lista. Apagada, nada de eso pasa: el articulo usa su propio percentage_gain/final_price unico, las categorias no tienen listas asociadas y el excel de articulos exporta el precio plano.',
            ],
            [
                'slug'        => 'articulo_margen_de_ganancia_segun_lista_de_precios',
                'modulo'      => 'Precios',
                'en_desuso'   => true,
                'description' => 'CONFIRMADO el cortocircuito: prender o apagar esta fila de ExtencionEmpresa ya no cambia nada por si sola; el gate real es la columna users.listas_de_precio del owner, tanto en la SPA (hasExtencion intercepta el slug y devuelve ownerUsesListasDePrecio()) como en la API (UserHelper::uses_listas_de_precio lee users.listas_de_precio). Con ese flag en 1, cada articulo tiene un margen y un precio final POR lista de precios guardados en el pivot article_price_type (o en price_type_monedas si tambien hay ventas_en_dolares), el listado reemplaza las columnas \'Margen de ganancia\'/\'Precio manual\'/\'Precio final\' por una columna por lista, el cliente gana el campo \'Tipo de precio\', el import de excel pide \'% \' y \'$ Final \' por lista, y en VENDER se oculta el input del valor del dolar. Con el flag en 0 el articulo tiene un unico margen y un unico precio final y nada de eso aparece.',
            ],
            [
                'slug'        => 'lista_de_precios_por_rango_de_cantidad_vendida',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'ENCENDIDA prende cinco cosas, todas gateadas de verdad: (1) al editar la CANTIDAD de un renglon ya cargado en la tabla del remito se recalcula su lista y se le reescribe price_type_personalizado_id (ArticlesTable.vue:508); (2) el select \'Lista de precio individual\' del renglon queda deshabilitado para todo el que no sea admin (PriceType.vue:22-28); (3) NO se auto-setea la lista de precios global de la venta al abrir VENDER -- ni la del presupuesto, ni la del cliente, ni la de mayor position (price_types.js:9-13); (4) al recuperar una venta anterior se ignora el precio guardado en el pivot y se repricea con la lista vigente, salvo que el item sea combo (generals.js:584-595); (5) del lado API, cada categoria nueva nace con un CategoryPriceTypeRange por cada lista de precios del owner, tanto en el alta del ABM como en los dos imports (SetPriceTypesHelper::set_rangos). APAGADA: la asignacion automatica de lista por cantidad NO se apaga, y ese es el punto. check_price_type_ranges no tiene ningun if de extension adentro -- la version que si lo tenia quedo comentada en mixins/vender.js:719-732 -- y se la llama sin gate en cuatro lugares: al agregar el articulo a la venta (mixins/vender/index.js:137), al volver a agregar uno repetido (repetidos.js:71), al recuperar una venta anterior cuando el item no traia lista propia (previus_sale/index.js:490-492) y en addArticleAndSetTotal (mixins/vender.js:703). Los category_price_type_range se descargan siempre al iniciar sesion (call_methods.js:79, string pelado, sin if_has_extencion, a diferencia de otras entradas del mismo archivo), el ABM donde se cargan tampoco esta gateado (abm.js:53) y el valor escrito se usa para pricear sin gate porque su if tambien esta comentado (generals.js:848-853). Resultado: apagada, si la empresa tiene rangos cargados, el item igual sale con la lista del rango; lo unico que se apaga son los cinco efectos de arriba. Apagada queda realmente inerte solo si nunca se crearon rangos. Detalle del toast: \'No hay rango para X\' NO sale cuando no matchea ningun rango -- en ese caso sale mudo por el \'return null\' final; sale solo cuando hubo rangos que matchearon por categoria y despues los elimino el filtro por subcategoria.',
            ],
            [
                'slug'        => 'article_price_range',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'ENCENDIDA: al editar la CANTIDAD de un renglon que YA esta cargado en la tabla del remito de VENDER se vuelve a correr check_price_range sobre ese item: recorre los article_price_ranges del articulo (\'Ofertas para VENDER\', cada oferta con modo \'Igual\' o \'Mayor o igual\', cantidad y precio), se queda con los rangos validos para esa cantidad, toma el de mayor cantidad y pisa item.price_vender_personalizado con el precio de esa oferta; si el articulo TIENE rangos cargados pero ninguno aplica, limpia el personalizado y vuelve al final_price. Ese recalculo al corregir la cantidad en la tabla es LO UNICO que enciende la extension: ArticlesTable.vue:513 es su unico gate vivo en los dos repos (grep de \'article_price_range\' como slug: solo aparece ahi, en seeders y como model_name). Si el articulo no tiene ninguna oferta cargada, check_price_range no toca nada (no limpia el personalizado). APAGADA: la oferta se sigue aplicando igual, no cambia el precio de venta en el caso normal. check_price_range corre SIN ningun hasExtencion al agregar el articulo a la venta (mixins/vender/index.js:139, dentro de add_item_to_sale, que es el camino de tipear la cantidad en el header y agregar) y al volver a agregar uno que ya estaba en el remito (repetidos.js:73, actualizar_cantidad); ademas los article_price_ranges viajan siempre en el articulo (Article.php:29, scopeWithAll, que es lo que usa el buscador de VENDER en VenderController.php:304) y el precio personalizado gana en el calculo sin gate alguno (generals.js:567-569 es la PRIMERA rama de getPriceVender). O sea: apagada, un articulo con \'Ofertas para VENDER\' cargadas se sigue vendiendo al precio de la oferta. Lo unico que se pierde es el recalculo al corregir la cantidad en la tabla: el renglon se queda con el precio de oferta que le toco por la cantidad con la que se agrego. Y aun encendida, si tambien esta prendida lista_de_precios_por_rango_de_cantidad_vendida el else-if nunca entra y ese recalculo tampoco ocurre.',
            ],
            [
                'slug'        => 'elegir_si_incluir_lista_de_precios_de_excel',
                'modulo'      => 'Precios',
                'en_desuso'   => false,
                'description' => 'Encendida, el excel de articulos para clientes deja de exportar todo el catalogo y exporta solamente los articulos cuyo pivot con la lista de precios elegida tenga incluir_en_excel_para_clientes = 1; para poder marcarlos, en el formulario del articulo aparece el checkbox \'Incluir en Excel\' al lado de cada lista de precios. Apagada, el checkbox no se muestra y el excel exporta todos los articulos con status active del usuario.',
            ],
            [
                'slug'        => 'production',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida habilita tres cosas sueltas, ninguna de ellas una pantalla propia: es UNA de las tres extensiones que el item \'ProduccionV2\' del menu lateral exige simultaneamente, muestra el campo \'costo de mano de obra\' en el formulario de articulo, y muestra el checkbox \'Descontar stock en los insumos recien cuando se supera el estado de produccion\' en la configuracion del usuario. Apagada, el item del menu no aparece nunca (aunque la URL /produccionV2 se sigue pudiendo tipear a mano porque el router no valida extensiones), el formulario de articulo no muestra costo de mano de obra y la opcion de configuracion queda oculta (el flag en la base sigue existiendo y ProductionMovementHelper lo sigue leyendo). La API no la chequea en ningun lado.',
            ],
            [
                'slug'        => 'productionV2',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida hace tres cosas: es una de las TRES extensiones que habilitan el item \'ProduccionV2\' del menu lateral (pantalla con solapas \'Lotes de produccion\', \'Recetas\' e \'Insumos\'), agrega el checkbox \'Es un insumo\' al formulario de articulo, y suma tres catalogos a la descarga del arranque de sesion (recipe_route_type, production_batch_status, production_batch_movement_type). Apagada, el item del menu no aparece, el articulo no se puede marcar como insumo desde el formulario y esos tres catalogos no se descargan al iniciar sesion, asi que los componentes de lotes que los leen del store buscan sobre un array vacio. NO reemplaza a production: el item del menu exige las tres prendidas a la vez (production, comerciocity_interno y productionV2), asi que productionV2 sola no muestra nada. Ojo con dos cosas que el item del menu pide ademas de las extenciones y con dos que se ven igual apagada: (a) routes.js:362 exige el permiso \'produccion.index\', que nav.js:45-47 evalua ANTES que las extenciones y que no existe en la API, asi que a un empleado no-admin el item no le aparece nunca aunque tenga las tres extenciones (el owner/admin entra igual porque can() cortocircuita); (b) el router no valida extenciones, asi que un owner puede tipear /produccionV2 con las tres apagadas y entra a la pantalla, solo que con los catalogos vacios; (c) la solapa \'produccion\' del ABM (/abm), con order_production_status y recipe_route_type, se lista sin ningun gate de extension.',
            ],
            [
                'slug'        => 'production.order_production',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida agrega la solapa \'Ordenes\' a la barra horizontal del modulo de produccion viejo (/produccion), que es el ABM de order_production (ordenes de produccion con su estado). Apagada, esa solapa no se lista y el modulo viejo queda con \'Movimientos\'/\'Cantidades actuales\' (si esta la otra extension) y \'Recetas\' (que solo depende del permiso recipe.index). Ademas exige el permiso order_production.index u order_production.create, asi que la extension sola no alcanza.',
            ],
            [
                'slug'        => 'production.production_movement',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida agrega dos solapas al modulo de produccion viejo (/produccion): \'Movimientos\' (ABM de production_movement, los avances de un articulo por los estados de produccion, que son los que descuentan stock de los insumos de la receta) y \'Cantidades actuales\'. Apagada, esas dos solapas no se listan. Atencion: por un error de precedencia en el if, alcanza con tener el permiso production_movement.create para que aparezcan aunque la extension este apagada.',
            ],
            [
                'slug'        => 'deposit_movements',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida agrega la solapa \'Movimientos de depositos\' al modulo de Alertas, con el badge de cuantos movimientos de deposito estan en curso y la tabla para verlos/cerrarlos. Apagada, esa solapa no aparece en Alertas — pero el resto de la funcionalidad de depositos sigue disponible igual: el dropdown \'Depositos\' del Listado (con \'Movimientos\' y \'Sugerencias\') se monta sin ningun chequeo de extension, y el modal de movimientos solo pide que la cuenta tenga sucursales cargadas. La API no la chequea en ningun lado.',
            ],
            [
                'slug'        => 'acopios',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida prende el circuito de entregas parciales: en el detalle de una venta aparecen los botones \'U. entregadas\' (abre el modal para cargar cuantas unidades se entregan ahora) e \'Imprimir unidades entregadas\'; en el Listado cada articulo suma un boton \'Acopios\' con un badge que cuenta las ventas de ese articulo con entrega parcial YA INICIADA y todavia incompleta (no todas las ventas con unidades pendientes); la propiedad \'En acopio\' entra en las props del modelo venta (columna y campo del detalle en VENTAS); y en VENDER, al reeditar una venta anterior, aparece la columna editable \'U. Entregadas\'. Apagada no se ven los botones de acopio del detalle, ni el boton \'Acopios\' del Listado, ni la columna \'U. Entregadas\' de VENDER, ni la propiedad \'En acopio\' del modelo. PERO el badge rojo \'Acopio\' sigue apareciendo en toda venta con en_acopio=1, tanto en el listado de VENTAS (Ventas.vue:49-54) como en el de cuentas corrientes (current-acounts/List.vue:30-32): esos v-if miran solo el campo del modelo, no la extension. Y el backend tampoco esta gateado: SaleHelper marca en_acopio=1 apenas le llega un delivered_amount y AcopioHelper lo recalcula, las dos cosas sin mirar extenciones. O sea que en una cuenta que ya tuvo entregas parciales, apagar la extension saca las herramientas pero no borra el distintivo.',
            ],
            [
                'slug'        => 'road_map_detalle_por_articulos_y_no_por_venta',
                'modulo'      => 'Produccion y depositos',
                'en_desuso'   => false,
                'description' => 'Encendida cambia como se lista el detalle de cada cliente dentro del modal de una hoja de ruta: en vez de una tarjeta por venta (con numero de venta, total, cantidad de articulos y boton \'Marcar como Entregada\' individual), muestra una tarjeta por articulo, aplanando articulos y promociones de vinoteca de TODAS las ventas de ese cliente; y en el encabezado del cliente agrega un boton unico \'Marcar como Entregado\' que termina todas las ventas del cliente de una (si ya estan todas terminadas muestra \'YA ESTA ENTREGADO\' deshabilitado). Apagada, se lista venta por venta, cada tarjeta clickeable para abrir el detalle de la venta y con su propio boton de entrega, y el boton unico del encabezado no aparece. Vive adentro del modulo Rutas, pero la subordinacion a ventas_con_fecha_de_entrega es de hecho, no de codigo: esa otra extension esconde el ITEM \'Rutas\' del menu lateral (routes.js:204) y nada mas. El router no valida extenciones en ningun momento, asi que entrando a /rutas por URL —o quedandose en la pantalla— esta extension cambia el render igual con la otra apagada, y los datos de la hoja de ruta se cargan lo mismo porque no dependen de ninguna extension.',
            ],
            [
                'slug'        => 'cambiar_empleado_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida (y con el permiso vender.change_employee) muestra un select "Empleado" en el encabezado del remito de VENDER y en la etapa 3, que escribe vender/employee_id y viaja en el POST /api/sale; asi el operador puede atribuirle la venta a otro empleado distinto al logueado. Apagada el select no se renderiza y employee_id queda en 0, con lo cual el backend decide solo: SaleHelper::getEmployeeId devuelve el id del usuario logueado si es empleado (tiene owner_id) y null si es el dueno. Se ve en VENDER, bloque de encabezado del remito (al lado de la info de AFIP / forma de pago) y en la pantalla de cierre (stage-3).',
            ],
            [
                'slug'        => 'indicar_vendedor_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida muestra el select "Vendedor" (b-input-group con prepend + b-form-select alimentado por getOptions({key:\'seller_id\'})) en dos lugares de VENDER: en la etapa 1 "Configuracion inicial", en el bloque de arriba junto a punto de venta AFIP, metodo de pago, caja, sucursal, lista de precios, moneda y tipo de venta — es decir POR ENCIMA del separador y del buscador de cliente, no debajo (stage-1/Index.vue:70, el <hr> recien en :74 y select-client en :79) —, y en la etapa 3 "Cierre y opciones", al lado del selector de empleado (stage-3/Index.vue:51). El mismo componente esta declarado ademas en el encabezado del remito (header-2/payment-method-afip-information/Index.vue:10), pero ese arbol ya no se monta: la vista arma la pantalla con VenderStages -> stage-1/2/3 y nadie importa remito/Index.vue. Lo elegido se guarda en vender/seller_id (mutation setSellerId, vender.js:497) y viaja en el POST /api/sale (vender.js:1069). En el alta la API no lo toma crudo: pasa por SaleHelper::get_seller_id (SaleController.php:190). Apagada el select no aparece, seller_id queda en el 0 con el que arranca el store (vender.js:266) y get_seller_id lo deduce en cascada (SaleHelper.php:686-715): si viene distinto de 0 lo respeta, si no busca el seller_id del cliente elegido, despues el seller_id del empleado que resuelve getEmployeeId, y si nada de eso hay devuelve 0. Lo que decide la comision no es el if de SaleHelper::crear_comision (SaleHelper.php:727-728, !is_null($sale->seller_id)), que entra SIEMPRE porque get_seller_id nunca devuelve null sino 0: el corte real esta un nivel abajo, en ComisionesHelper::crear_comision (ComisionesHelper.php:28-31, !is_null && seller_id !== 0), asi que con vendedor 0 no se crea ninguna comision. Detalle asimetrico del update: al actualizar una venta reabierta el seller_id se asigna crudo, sin pasar por get_seller_id (SaleController.php:365), y el front reenvia el que restauro del modelo (previus_sale/index.js:206 y :384), asi que en el PUT el valor sobrevive aun con la extension apagada.',
            ],
            [
                'slug'        => 'numero_orden_de_compra_para_las_ventas',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida agrega el input numerico "N° Orden compra" (b-input-group con prepend, type=number) en la etapa 3 de VENDER, "Cierre y opciones" (empresa-spa: src/components/vender/components/stage-3/Index.vue:43). Ese es hoy el UNICO lugar donde se monta: el mismo componente esta declarado tambien en la columna de botones del remito (header-2/buttons/Index.vue:21), pero ese arbol quedo huerfano — la vista arma la pantalla con VenderStages -> stage-1/2/3 (views/Vender.vue:23, VenderStages.vue:8-12) y nadie importa remito/Index.vue, que es el unico que monta header-2. Lo cargado se guarda en vender/numero_orden_de_compra (mutation set_numero_orden_de_compra, vender.js:743), viaja en el POST /api/sale (vender.js:1062) y en el PUT de la venta reabierta (previus_sale/index.js:381), y la API lo persiste tal cual en sales.numero_orden_de_compra (SaleController.php:185 en el alta, :363 en el update; no hay chequeo de la extension en ningun lado del backend). Ahi termina el recorrido: el dato NO se imprime. El PDF vivo de la venta es NewSalePdf (unica instanciacion de un PDF de venta, SaleController.php:692) y no imprime el campo; la unica impresion que existe es SalePdf::print_numero_orden_de_compra (SalePdf.php:283-296), de una clase que no se instancia en ningun lado (no hay ningun "new SalePdf(" en el repo; se importa en SaleController.php:37 y nunca se usa). Lo unico que devuelve el valor a la vista es reabrir esa misma venta en VENDER (previus_sale/index.js:269-270). Apagada el input no se renderiza, el store manda la cadena vacia con la que arranca (vender.js:261) y ConvertEmptyStringsToNull (Kernel.php:23) la guarda como null. O sea: apagada no se pierde ninguna salida visible, porque encendida tampoco hay ninguna fuera del propio input — la extension solo decide si existe el campo en la pantalla y si el dato queda en la base.',
            ],
            [
                'slug'        => 'sale.observations',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida agrega un b-form-textarea "Observaciones" de 5 filas en el cuerpo del remito de VENDER (entre los datos de la venta previa y la tabla de articulos), ligado a vender/observations, que viaja en el POST de la venta y se imprime en el PDF. Apagada ese textarea desaparece, pero el campo NO queda inaccesible: en la misma pantalla sigue habiendo otro textarea de observaciones sin ningun gate, en la columna de botones del encabezado, que escribe exactamente la misma variable del store. Se ve en VENDER, vista remito.',
            ],
            [
                'slug'        => 'adjuntar_archivos_en_vantas',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida agrega una primera columna "Adj." en la tabla de articulos de VENDER y en el detalle de una venta ya guardada, con un boton por fila que abre un modal para subir, previsualizar, descargar y borrar archivos adjuntos de ESE articulo dentro de ESA venta (en venta nueva quedan pendientes en el store y se suben recien cuando se guarda la venta; en venta existente se suben en el momento contra /api/sale-article-attachment). Apagada la columna no se agrega y el modal no existe, asi que la venta no tiene forma de llevar archivos por articulo. Se ve en VENDER (tabla de articulos del remito) y en VENTAS, modal de detalle de una venta.',
            ],
            [
                'slug'        => 'filtrar_clientes_por_sucursal_en_vender',
                'modulo'      => 'VENDER',
                'en_desuso'   => false,
                'description' => 'Encendida hace tres cosas: (1) el buscador de clientes de la etapa 1 de VENDER manda al backend el parametro address_id_con_nulos con la sucursal elegida en VENDER, y SearchController@searchFromModal devuelve solo los clientes de esa sucursal mas los que no tienen sucursal (null o 0); (2) habilita el campo "Sucursal" en el ABM de clientes; (3) agrega la barra de navegacion por sucursal en COMPROBANTES > Pagos de clientes, que filtra los pagos por la sucursal del cliente. Apagada, props_to_send_to_api devuelve [] y el buscador trae todos los clientes de la cuenta, el ABM de clientes no muestra el campo Sucursal y la pantalla de pagos de clientes lista todo sin nav de sucursales. Ojo que aun encendida no filtra si todavia no se eligio sucursal en VENDER (address_id en 0).',
            ],
            [
                'slug'        => 'unidades_individuales_en_articulos',
                'modulo'      => 'VENDER',
                'en_desuso'   => true,
                'description' => 'Hoy no hace nada, y por partida doble. Primero, la extension ni siquiera existe como registro: su fila esta comentada en el ExtencionSeeder (empresa-api: database/seeders/ExtencionSeeder.php:128-132, el slug en :131), asi que el ExtencionEmpresa::whereIn(\'slug\', ...) de UserSetupHelper.php:88 y DemoSetupHelper.php:89 no la puede encontrar aunque UserSetupHelper.php:311 y DemoSetupHelper.php:344 la agreguen al array de extensiones cuando el rubro elegido es \'ferreteria\'. Segundo, el unico lugar del sistema que la consultaba era la tabla de articulos del remito de VENDER, donde agregaba una columna \'U. Individuales\' con dos inputs (\'Div Por\' / \'Div En\'), y ese push de la columna esta comentado entero (empresa-spa: src/components/vender/components/remito/ArticlesTable.vue:314-318), con lo cual la columna nunca se agrega y el slot #cell(unidades_individuales) (:131-147) queda colgado. Y aunque se descomentara el push no andaria: la INTENCION de los dos inputs era recalcular el precio del item por unidad, pero el handler calcular_precio_por_unidades_individuales (:544-548) calcula tres variables locales (precio, precio_por_unidad, precio_de_las_unidades_vendidas) y las descarta — no asigna a item.price_vender ni a nada del item, no emite evento, no llama a callSetTotal y no retorna. O sea que la funcionalidad nunca estuvo terminada. Encendida o apagada, el comportamiento de VENDER es identico. OJO al leer esto: lo muerto es la EXTENSION y la columna de VENDER, no el concepto de unidades individuales, que sigue vivo gobernado por la columna articles.unidades_individuales sin ninguna extension de por medio.',
            ],
            [
                'slug'        => 'articulos_unidades_individuales',
                'modulo'      => 'VENDER',
                'en_desuso'   => true,
                'description' => 'Hoy no hace nada: sigue consultada en dos funciones del helper de exportacion (set_unidades_individuales, que agregaba el heading "U Individuales", y map_unidades_individuales, que agregaba el valor $article->unidades_individuales), pero ninguna de las dos tiene llamador vivo: las tres llamadas a set_unidades_individuales en ArticleExport estan comentadas y map_unidades_individuales no se llama en ningun lado. La columna quedo fija en el Excel de articulos para todas las cuentas, porque \'U individuales\' esta en get_base_headings y el valor esta hardcodeado en el map base. Encendida o apagada, el Excel exportado del listado de articulos sale igual.',
            ],
            [
                'slug'        => 'article_variants',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, el articulo pasa a manejarse por variantes: en VENDER la busqueda por nombre devuelve una fila por variante en vez del articulo padre y la lectura de codigo de barras abre el selector de variantes, la tabla de VENDER suma la columna \'Variante\', el listado muestra el boton/grilla de variantes, el PDF de venta agrega la columna \'Variante\' (achicando \'Nombre\' 15 puntos) y el Excel de import/export suma una columna por cada tipo de propiedad (talle, color, etc). Apagada, la busqueda de VENDER devuelve los articulos tal cual ($results = $articles), no hay columna \'Variante\' ni en pantalla ni en el PDF, el Excel no lleva columnas de propiedades y al terminar una importacion no se recalcula el stock por deposito a partir de las variantes. Se ve en VENDER (buscador y tabla del remito), listado de articulos (boton de variantes y modal de import) y PDF de venta.',
            ],
            [
                'slug'        => 'combos',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, aparece el buscador \'Combos\' en el encabezado de VENDER (que permite agregar un combo entero al remito), y en el listado de articulos se habilita el boton + modal de ABM de combos; ademas el listado de ventas muestra la columna \'Combos\' con los combos vendidos y el encabezado de VENDER se reacomoda (una columna menos de ancho para el nombre). Apagada, esos tres bloques no se renderizan: no se pueden crear combos ni agregarlos a una venta desde la UI, y la columna \'Combos\' del listado de ventas no se muestra. Se ve en VENDER (encabezado del remito), listado de articulos y listado de ventas.',
            ],
            [
                'slug'        => 'imagenes',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, la columna de imagenes del articulo sobrevive al filtro de columnas del listado de articulos, asi que el usuario ve la miniatura aunque no tenga tienda online. Apagada, y solo si ademas el owner no tiene tienda online activa (owner.online falsy), se saca del listado la propiedad \'images\'; si la cuenta tiene tienda online la columna se muestra igual, con o sin extension. Se ve unicamente en el listado de articulos (armado de columnas).',
            ],
            [
                'slug'        => 'articulos_en_exhibicion',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, el modal de importacion del listado de articulos agrega al final de la plantilla una columna \'Exhibicion <nombre del deposito>\' por cada deposito de la cuenta, para que el Excel pueda marcar que variantes estan en exhibicion en cada sucursal. Apagada, esas columnas no se listan en la plantilla que ve el usuario, pero el importador de la API las sigue leyendo si estan en el Excel y guarda on_display=1 en el pivot de variante-deposito, y el checkbox \'En exhibicion\' de la grilla de variantes se muestra igual. Se ve en el listado de articulos, modal de importacion (armado de columnas de la plantilla).',
            ],
            [
                'slug'        => 'article.costo_real',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => true,
                'description' => 'No cambia nada: no queda ninguna consulta activa de este slug en ninguno de los dos repos. La propiedad \'Costo Real\' del articulo se muestra siempre a quien tenga el permiso article.cost (el unico gate real es \'can\'), y la API calcula y guarda articles.costo_real en cada recalculo de precio sin preguntar por la extension. Encendida o apagada, el comportamiento del listado de articulos y de VENDER es identico.',
            ],
            [
                'slug'        => 'articulos_con_propiedades_de_distribuidora',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida hace dos cosas, y solo dos. Primero: en el modal de edicion del articulo aparecen tres campos de rubro distribuidora, \'U x Bulto\' (unidades_por_bulto), \'contenido\' y \'Tipo de envase\' (select contra tipo_envases), que se guardan por el save normal del articulo. Segundo: la plantilla del modal de importacion por Excel suma tres columnas, \'Tipo de envase\', \'Contenido\' y \'U x Bulto\'. Ahora, del lado del importador solo una de esas tres columnas termina escribiendo en la base: \'Contenido\', y no por ningun bloque de la extension sino por el camino general de ProcessRow, que no consulta ninguna extension. \'Tipo de envase\' y \'U x Bulto\' no se importan ni con la extension prendida: el unico codigo que sabe leerlas (set_tipo_de_envase / set_unidades_por_bulto) cuelga de metodos que ya no tienen call site (ver evidencia), y encima el modal manda esas dos columnas con claves \'tipo de envase\' y \'u x bulto\' (con espacios, derivadas del texto visible) que ningun lector busca. Apagada: los tres campos no se renderizan en el modal del articulo y las tres columnas no salen en la plantilla del Excel. Ojo con el caso donde apagada no cambia nada: si la cuenta tiene \'autopartes\', su plantilla ya empuja una columna \'contenido\', asi que articles.contenido se sigue escribiendo igual en la importacion. Y los valores ya guardados no se ocultan en ningun otro lado: unidades_por_bulto se sigue usando para la columna \'Caja\' del PDF de venta y contenido/unidades_por_bulto se siguen exponiendo en la API de integracion de articulos, sin preguntar por la extension.',
            ],
            [
                'slug'        => 'autopartes',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, el articulo pasa a tener el grupo de campos \'Autopartes\' en su formulario (espesor, modelo, pastilla, diametro, litros, contenido, cm3, calipers y juego), esas mismas 9 columnas se agregan a la plantilla del modal de importacion, se insertan en el Excel de exportacion justo despues de \'U individuales\' y el importador las lee y las graba en el articulo. Apagada, el grupo no se renderiza, el Excel exportado no lleva esas columnas y el importador ni siquiera mira esas celdas (el bloque data_autopartes no se arma). Se ve en el listado de articulos: modal de edicion, modal de importacion y exportacion a Excel.',
            ],
            [
                'slug'        => 'vinoteca',
                'modulo'      => 'Articulos y listado',
                'en_desuso'   => false,
                'description' => 'Encendida, el articulo gana el grupo \'Vinoteca\' (Bodega, Cepa, Origen, Presentacion y \'Omitir en lista pdf\'), se cargan los stores de bodega y cepa, aparece la vista ABM de vinoteca, el buscador de \'Promocion\' en el encabezado de VENDER, el boton \'Promos\' en el listado de articulos, la columna \'Promociones\' en el listado de ventas y el checkbox \'Mostrar en PDF de articulos\' en categorias; ademas, en la API el precio final calculado del articulo se multiplica por el campo \'Presentacion\' (unidades por presentacion). Apagada, nada de eso se renderiza ni se carga, y el precio final queda como salio del calculo normal (costo + margenes + recargos), sin multiplicar por presentacion. Se ve en listado de articulos (modal de edicion, boton Promos), VENDER (encabezado del remito), ABM de vinoteca, categorias y listado de ventas.',
            ],
            [
                'slug'        => 'ai_excel_import',
                'modulo'      => 'Importacion',
                'en_desuso'   => true,
                'description' => 'No cambia nada: el slug existe en la tabla pero ningún código lo consulta. Las cinco rutas de la importación asistida por Claude (POST /ai-excel-import/analyze, /import, /get-recomendacion, /refresh-provider-stats y GET /analysis/{uuid}) están dentro del grupo auth:sanctum común, sin el middleware check_extencion_empresa, y el item de menú "Importar con IA" se muestra según el model_name (article, client, provider), no según la extensión. Encendida o apagada, cualquier usuario autenticado de cualquier cuenta puede usar la importación con IA.',
            ],
            [
                'slug'        => 'articles_pre_import',
                'modulo'      => 'Importacion',
                'en_desuso'   => false,
                'description' => 'Ojo: la extension YA NO retiene ninguna actualizacion, aunque su nombre y su codigo digan eso. En el importador vivo (InitExcelImport -> ProcessArticleChunk -> ArticleImport -> ProcessRow), encendida hace dos cosas y nada mas: (1) el constructor de ArticleImport instancia ArticlesPreImportHelper, y como el pre_import_id nunca se asigna en ese constructor, ArticlesPreImport::find(null) devuelve null y se CREA un registro ArticlesPreImport vacio por cada chunk de la importacion; (2) en la SPA aparece la opcion \'Pre Importaciones\' en el dropdown de Excel del listado de articulos, con su modal que lista esos registros. Los articulos existentes se actualizan exactamente igual que con la extension apagada: la rama que frenaba el update vive en ArticleImport::saveArticle(), un metodo que ya no llama nadie -- collection() procesa cada fila con $this->process_row->procesar() y ProcessRow no menciona pre_import en ningun lado. Apagada, la importacion actualiza igual (mismo camino), no se crea ningun ArticlesPreImport y la opcion \'Pre Importaciones\' no se muestra. Aclaracion sobre el codigo muerto, por si alguna vez lo revivien: ahi el gate tampoco haria lo que promete, porque add_article() solo engancha el articulo si cambio el COSTO, y el update esta en el else de ese mismo if; un cambio que no fuera de costo (nombre, codigo de barras, iva, categoria, margen, precio) no se aplicaria ni quedaria pendiente de revision. El circuito de confirmacion (elegir cuales aplicar, pisar el costo y recalcular el precio final) sigue vivo del lado del controller, pero como nadie hace el attach no hay filas para confirmar: el modal abre pre-importaciones vacias.',
            ],
            [
                'slug'        => 'articulos_precios_en_blanco',
                'modulo'      => 'Importacion',
                'en_desuso'   => false,
                'description' => 'Encendida, cada artículo pasa a tener un segundo juego de precios "en blanco" (descuentos, recargos, margen y precio final propios): setFinalPrice recalcula final_price_blanco además del final_price, el Excel de artículos suma cuatro columnas EN BLANCO (exportación e importación), el formulario/filtros de artículo muestran esos campos, y en VENDER, si el item es artículo y hay comprobante AFIP elegido, se cobra final_price_blanco en vez de final_price. Apagada, el artículo tiene un solo precio final, las columnas EN BLANCO no se exportan ni se leen del Excel y VENDER usa el precio final normal con listas de precio y descuentos por método de pago.',
            ],
            [
                'slug'        => 'mostrar_diferenia_de_precios_en_excel_para_clientes',
                'modulo'      => 'Importacion',
                'en_desuso'   => false,
                'description' => 'Encendida, el Excel para clientes agrega, al lado de cada columna de lista de precios, una columna "Diferencia" con el texto "Aumento" o "Disminuyo" según cómo quedó el precio de esa lista contra su precio anterior (previus_final_price del pivot), y esas celdas se pintan verde claro para Aumento y rojo claro para Disminuyo. Apagada, el archivo sale solo con la columna de precio de cada lista, sin comparación ni colores. Es un cambio puramente de exportación: no toca precios ni cálculos.',
            ],
            [
                'slug'        => 'costo_en_dolares',
                'modulo'      => 'Importacion',
                'en_desuso'   => false,
                'description' => 'Encendida, aparece el checkbox \'costo en dolares\' (campo cost_in_dollars) entre las props del articulo: formulario de alta/edicion, filtros y actualizacion masiva del listado, mas el checkbox \'Costo en dolares\' por renglon en la tabla de articulos del pedido a proveedor. Como columna de la tabla del listado viene apagada por defecto (article.js:190 lleva not_show: true, y column_preferences_helper.js:265 arranca visible: !not_show), asi que hay que habilitarla a mano desde preferencias de columnas. Apagada, esos checkboxes desaparecen de la SPA y NADA MAS: la extension no gatea ningun escritor de la API ni el calculo del precio. article.cost_in_dollars se sigue escribiendo desde la importacion de Excel por la columna \'moneda\' (ProcessRow.php:633-637 + get_cost_in_dollars en :2138-2166) y desde el POST/PUT de articulo (ArticleController.php:189 y :366), y ArticleHelper::cotizar() (:401-415) decide por la COLUMNA del articulo mas el flag $user->cotizar_precios_en_dolares -- que ademas nace en true por migracion --, sin mirar la extension nunca. O sea: una cuenta con la extension apagada puede perfectamente tener articulos con costo en dolares cotizandose a pesos, y editar ese articulo desde el formulario tampoco lo apaga, porque el form manda el modelo entero (model/Index.vue:638-641) y el campo viaja aunque no se renderice. Cuando el efecto si corre, la cotizacion usa el dolar del proveedor si tiene uno mayor a 0 y, si no, el dolar global del usuario.',
            ],
            [
                'slug'        => 'providers_article_price_from_costo_mas_iva',
                'modulo'      => 'Importacion',
                'en_desuso'   => false,
                'description' => 'Encendida, aparece el checkbox "Setear precio con COSTO + IVA" en el formulario de proveedor (grupo "Precios y descuentos"); con ese checkbox marcado, los artículos de ese proveedor calculan el precio final como costo de lista + IVA, salteando el costo real (descuentos/recargos) y los márgenes de ganancia del usuario y del proveedor. Apagada, el checkbox no se muestra en el ABM de proveedores y los artículos se siguen precificando por el camino normal: costo real + margen del usuario + margen del proveedor + listas de precio.',
            ],
            [
                'slug'        => 'cerrar_ventas',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, en cada fila del listado de VENTAS (y en el listado de cuentas corrientes del cliente) aparece un boton verde "Cerrar" que, tras un confirm, pega PUT sale-cerrar-venta/{id} y la API pone sale.is_cerrada = 1; desde ese momento esa venta no se puede editar mas, ni desde el front ni desde la API. Apagada, el bloque entero no se renderiza: no hay boton "Cerrar" ni badge "Cerrada", y ninguna venta se puede marcar como cerrada desde la UI, aunque siguen valiendo las demas restricciones de edicion (facturada, con caja, con varios metodos de pago). Ojo que el bloqueo por is_cerrada NO depende de la extension: si una cuenta tiene ventas cerradas y despues le apagan la extension, esas ventas siguen sin poder editarse.',
            ],
            [
                'slug'        => 'ventas_con_estados',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, aparece el item de menu "Por Estados" colgando de Ventas, que abre /por-estado: un nav horizontal con una solapa por cada SaleStatus del usuario (con el contador de ventas en cada estado) y abajo el listado de las ventas de ese estado, filtrado por sale_status_id. Apagada, ese item no se muestra en el menu lateral y no hay entrada a la pantalla, pero la ruta sigue accesible escribiendo la URL y el selector de estado dentro de VENDER sigue apareciendo igual, porque depende de que existan filas de sale_status, no de la extension. Es puramente visibilidad de navegacion: no cambia ningun dato ni ninguna consulta.',
            ],
            [
                'slug'        => 'sales.hide',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, en la pantalla de Ventas la barra de dias previos (titulo con la fecha, nav de dias de la semana y boton "Por fecha") solo se dibuja cuando la ruta es VentasAll (/ventas-completas); en la ruta /ventas (name \'sale\', que es el item "Ventas" del menu) queda oculta, con lo cual desde ahi no se puede cambiar de dia ni abrir el modal de rango de fechas. Apagada, la barra se muestra en las dos rutas, y solo se oculta si hay un filtro activo (is_filtered). No esconde ninguna venta: el nombre visible del seeder miente.',
            ],
            [
                'slug'        => 'consolidar_ventas_en_factura',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, en el listado de VENTAS aparecen dos controles: la opcion "Consolidar para facturar" dentro del dropdown de Seleccion (valida que haya ventas seleccionadas, que todas tengan el mismo client_id, que ninguna sea de mostrador y que ninguna sea ya una venta contenedora, y abre el modal modal-consolidar-facturacion con esas ventas precargadas) y el boton "Ver consolidadas / Ocultar consolidadas" arriba de la grilla. Apagada, no se dibuja ninguno de los dos: no hay forma de armar una consolidacion desde la UI y las ventas contenedoras quedan permanentemente ocultas del listado, porque mostrar_consolidadas arranca en false y el filtro de mixins/sale.js las saca. Los endpoints de la API (sales/por-consolidar y sales/consolidar-facturacion) no chequean la extension.',
            ],
            [
                'slug'        => 'ventas_con_fecha_de_entrega',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida hace tres cosas. (1) En VENDER aparece el input \'Fecha de Entrega\' en el header del remito, salvo cuando se esta cargando un presupuesto. (2) Al guardar la venta, si vino fecha_entrega, get_terminada() devuelve 0 y la venta se crea con terminada=0 y terminada_at=null, con lo cual NO aparece en el listado de Ventas (el modulo \'ventas\' filtra terminada=1) y si aparece en Ventas > Por Entregar hasta que alguien la marque \'Terminada\'. (3) Se habilitan en el menu los items \'Por Entregar\' (hay DOS entradas distintas con la misma extension, colgando de ramas distintas del menu) y \'Rutas\' (hojas de ruta), mas la columna \'Fecha Entrega\' en las tablas de ventas y de pedidos. Ojo con los items de menu: la extension es condicion necesaria pero no suficiente, porque las tres rutas piden ademas permiso (road_map.index para Por Entregar, road_map.terminadas.index para Rutas) y nav.js chequea el permiso ANTES que la extension: sin el permiso no aparecen aunque la extension este prendida. Apagada, no hay input de fecha de entrega en VENDER, la rama de fecha_entrega de get_terminada() nunca entra, y por lo tanto ninguna venta queda no terminada POR FECHA DE ENTREGA; los items Por Entregar y Rutas no se muestran y la columna Fecha Entrega se oculta. Pero \'toda venta nace terminada\' solo vale si check_sales tambien esta apagada: get_terminada() tiene una rama ANTERIOR (la de check_sales + to_check) que tambien devuelve 0, y esa venta tampoco entra al listado de Ventas -- y como no tiene fecha_entrega, tampoco la levanta la pantalla Por Entregar, que pide terminada=0 Y fecha_entrega dentro del rango. Otro detalle del caso apagada: la columna fecha_entrega se guarda igual si alguien la manda en el request (no hay gate en el insert); lo que la extension gatea es el input y el calculo de terminada.',
            ],
            [
                'slug'        => 'vendedor_en_sale_pdf',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, en el PDF de venta NO fiscal (el remito/comprobante interno que arma NewSalePdf) se imprime, justo debajo del header y antes de las observaciones del cliente y de la tabla de items, una linea extra en negrita "Vendedor: <nombre del empleado>", siempre y cuando la venta tenga employee asociado. Apagada, esa linea no se imprime y el header sigue directo con la descripcion del cliente y el encabezado de columnas. No afecta al PDF fiscal AFIP: cuando el perfil es fiscal, Header() dibuja AfipPdfHelper::header y hace return en NewSalePdf.php:244, antes de llegar al bloque del vendedor. El chequeo gemelo que existe en SalePdf.php:164 (que ademas mete el dato como extra_info del header) NO se comporta distinto: simplemente nunca corre, porque la clase SalePdf no se instancia en ningun lado.',
            ],
            [
                'slug'        => 'firma_entrega_en_pdf_ventas',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => true,
                'description' => 'Hoy no cambia absolutamente nada: prendida o apagada, todos los PDFs salen iguales. El unico codigo que la consulta es el metodo SalePdf::firma_entrega_en_pdf_ventas(), que dibujaria tres renglones con lineas de puntos ("Nombre y apellido: ____", "DNI: ____", "Firma: ____") para que quien recibe la mercaderia firme el comprobante. Ese metodo no lo llama nadie, ni siquiera el Footer() de la propia clase, y ademas la clase SalePdf entera es codigo muerto: el PDF de venta lo genera NewSalePdf.',
            ],
            [
                'slug'        => 'costos_en_nota_credito_pdf',
                'modulo'      => 'Ventas y comprobantes',
                'en_desuso'   => false,
                'description' => 'Encendida, cambia la grilla del PDF de Nota de Credito: getFields() saca la columna "Desc" (descuento del item) y agrega "Total Cos", tanto en el encabezado de la tabla como en cada fila de articulo, donde imprime costo x cantidad; y en el pie, si la nota tiene al menos un articulo, se suma el renglon "Total Costos: $X" con la suma de costo x cantidad de todos los articulos. Apagada, la tabla lleva la columna "Desc" con el porcentaje de descuento del item y no hay ni columna ni renglon de costos, es decir el PDF no muestra costos por ningun lado. Aplica solo al PDF de nota de credito interna (NotaCreditoPdf), la que se imprime cuando la nota NO tiene comprobante AFIP; si tiene afip_ticket se usa AfipTicketPdf y la extension no participa.',
            ],
            [
                'slug'        => 'nota_credito_descriptions',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, en la pantalla de Devoluciones (alta de nota de credito) aparece el bloque "Descripciones": filas libres con nota, precio e IVA que se guardan como NotaCreditoDescription y despues salen como items propios en el PDF de la NC y en los items que se le mandan a AFIP. Apagada, el bloque no se renderiza y la nota de credito solo lleva los articulos y servicios devueltos. El chequeo es 100% de la SPA: la API guarda las descripciones que le lleguen sin preguntar por la extension.',
            ],
            [
                'slug'        => 'guardad_cuenta_corriente_despues_de_facturar',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Decide si la venta escribe (o no) su debe en la cuenta corriente del cliente. APAGADA (por defecto): al guardar en VENDER, SaleController::store crea la Sale con save_current_acount tal cual viene del front (el store de vender lo manda en 1) y en la MISMA request SaleHelper::attachProperies llama a create_current_acount, que escribe el debe en la C/C del cliente (si la venta queda \'a chequear\' la escritura se corre al update, pero igual no depende de facturar); facturar despues no toca la C/C. ENCENDIDA: apenas se crea la Sale, SaleController.php:224 llama a SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar, que fuerza save_current_acount = 0 -salvo que el cliente tenga tildado \'Pasar las ventas a la C/C sin esperar a facturar\'-, asi que create_current_acount no escribe nada. Y la escritura diferida al facturar NO ocurre: el bloque de AfipWsfeHelper.php:719-731 es codigo muerto (corre sobre un AfipTicket y pregunta por ->afip_ticket->resultado y ->client, que no existen en ese modelo ni en la tabla), asi que la condicion nunca da true. Resultado real: con la extension prendida, la venta de un cliente sin ese checkbox no entra NUNCA a la cuenta corriente: ni al guardar, ni al facturar, ni al editar la venta despues (el update vuelve a llamar a create_current_acount con el mismo 0). Cuando la venta nace de un presupuesto pasa lo mismo, pero por otra puerta: BudgetHelper la crea directamente con save_current_acount = false, y solo si el presupuesto tiene cliente y ese cliente no tiene el checkbox (si no hay cliente devuelve true, aunque igual no se escribe nada porque create_current_acount exige client_id). Ademas agrega el checkbox por cliente en el ABM de Clientes.',
            ],
            [
                'slug'        => 'pagos_provisorios',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, el modal "Pago" de cuenta corriente muestra el checkbox "Pago Provisorio"; si se tilda, el movimiento se guarda con is_provisorio = 1 y la API se saltea todo el bloque de saldo: no calcula ni persiste el saldo del pago, no actualiza el saldo de la credit_account ni del cliente, no imputa el pago contra los debitos pendientes y no paga cuotas. El movimiento queda listado en la cuenta corriente con un badge "(Provisorio)" y getSaldo lo excluye de todos los calculos. Apagada, el checkbox no existe, is_provisorio viaja siempre en 0 y todo pago impacta el saldo del cliente al instante.',
            ],
            [
                'slug'        => 'resumen_caja',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, en la barra superior de Tesoreria aparece el boton "Resumenes", que abre un modal con el ABM de resumen_caja: se genera un resumen por turno + sucursal + empleado que toma el saldo de apertura/cierre e ingresos/egresos de cada caja de esa sucursal, le suma el total de las ventas a cuenta corriente del turno, y permite bajar el PDF del resumen. Apagada, el boton no se renderiza y no hay forma de llegar al modal desde la UI, aunque el modulo Tesoreria funciona igual (cajas, aperturas y movimientos siguen operando). Las rutas de la API quedan abiertas: el chequeo es solo de la SPA.',
            ],
            [
                'slug'        => 'cajas',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, en el formulario de detalle de una venta aparece el campo de solo lectura "Caja destino" (sale.caja_id), y solo cuando esa venta NO fue a la cuenta corriente (save_current_acount == 0). Apagada, ese campo se filtra y no se muestra; nada mas cambia. El modulo Tesoreria/Cajas, los movimientos de caja y la seleccion de caja en los metodos de pago funcionan igual con la extension apagada, porque el gate del router esta comentado y la API nunca consulta este slug.',
            ],
            [
                'slug'        => 'comision_por_proveedores',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, en el ABM de Proveedores aparecen los dos campos numericos "% de comision ventas en NEGRO" y "% de comision ventas en BLANCO" (providers.porcentaje_comision_negro / porcentaje_comision_blanco), que son los que despues consume el motor de comision de Ros Mar: por cada articulo de la venta, si el vendedor no tiene porcentaje propio cargado, usa el % en BLANCO sobre el neto sin IVA cuando la venta se facturo (tiene afip_information_id) y el % en NEGRO sobre el total del item cuando no. Apagada, esos dos campos no se muestran ni se pueden cargar desde el ABM. Ojo: la extension NO decide como se calcula la comision -eso lo decide users.comision_funcion-, solo expone donde cargar los porcentajes.',
            ],
            [
                'slug'        => 'comisiones_por_categoria',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, en el ABM de Vendedores aparece la seccion "Categorias": un buscador belongsToMany contra categorias donde a cada categoria se le carga un "Porcentaje" en el pivot. Ese pivot es lo que consume el motor de comision de Golo Norte: por cada articulo de la venta busca la categoria del articulo entre las del vendedor y aplica ese porcentaje sobre precio x cantidad; si el articulo no tiene categoria o el vendedor no la tiene cargada, ese articulo no suma comision, y si el total queda en cero no se crea ninguna fila de comision. Apagada, la seccion no se muestra y no hay forma de cargar porcentajes por categoria desde la UI (SellerController igual los adjunta si llegan en el request).',
            ],
            [
                'slug'        => 'budgets',
                'modulo'      => 'Cuentas, caja y comisiones',
                'en_desuso'   => false,
                'description' => 'Encendida, habilita el modulo de Presupuestos: aparece el item de nav "Presupuestos" colgado de Vender (ademas requiere la extension comerciocity_interno y el permiso budget.index, porque el gate del router es un array y se evaluan todas con AND), y en VENDER aparece el toggle "Guardar como presupuesto", que solo se renderiza si ademas hay un cliente elegido y queda deshabilitado si la venta viene de una venta previa o ya es un presupuesto. Apagada, no hay item de nav ni toggle: desde VENDER solo se puede guardar la venta como venta. Los endpoints de la API (BudgetController y sus rutas) quedan abiertos igual: el chequeo es solo de la SPA.',
            ],
            [
                'slug'        => 'online',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida muestra en el nav lateral el item "Tienda Online" (ruta /online, con pedidos, compradores y chat, mas el badge online_menu_alert_count) y agrega al formulario de artículo el grupo "Tienda online" con los campos Disponible en la tienda (online), Destacado (featured), En oferta (in_offer) y Precio pausado (precio_pausado), y al ABM de descuentos el campo "Mostrar en la tienda online" (show_in_online). Apagada, ese item de menu no se dibuja y esos campos no se renderizan en los formularios; los artículos igual se guardan con online=1 por default de la migracion, asi que el dato existe pero el operador no lo puede tocar desde el sistema. No la consulta nadie en la API: es un gate 100% de la SPA.',
            ],
            [
                'slug'        => 'mercado_libre',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida suma +1 "cuenta de Mercado Libre" al calculo de la mensualidad que se le cobra al cliente: el total pasa a incluir precio_mercado_libre (o precio_por_cuenta como fallback si ese precio individual esta vacio). Apagada, ese renglon vale 0 y no se cobra. No habilita, esconde ni cambia absolutamente nada del sistema que usa el comerciante: es un flag puramente administrativo/de facturacion.',
            ],
            [
                'slug'        => 'usa_mercado_libre',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida habilita todo el modulo de Mercado Libre: aparece el item "MercadoLibre" en el nav (ruta /mercado-libre con pedidos y sincronizaciones), el formulario de artículo suma el grupo "Mercado Libre" (Disponible en Mercado Libre, Tipo publicacion, Modo de compra, Condicion, Descripcion), el listado de artículos muestra el boton ML por fila con badge verde si el artículo ya tiene me_li_id, el ABM suma la solapa "meli" (tipos de publicacion, modos de compra, condiciones de item), las listas de precio suman el checkbox "Se usa para Mercado Libre", y el scheduler registra el comando sync_to_meli_articles cada minuto. Apagada, ninguna de esas cosas existe y el cron de sincronizacion ni siquiera se agenda.',
            ],
            [
                'slug'        => 'usa_tienda_nube',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida habilita el modulo Tienda Nube: item "Tienda Nube" en el nav (ruta /tienda-nube) con badge de sincronizaciones fallidas que se carga al iniciar sesion, grupo "Tienda Nube" en el formulario de artículo (Disponible en Tienda Nube, Titulo y Descripcion para SEO, Tags, y medidas como alto), boton TN por fila en el listado de artículos, checkbox "se_usa_en_tienda_nube" en las listas de precio, y el scheduler registra sync_articles_to_tienda_nube cada minuto; ademas suma +1 cuenta al total de la mensualidad. Apagada, nada de eso aparece, el cron no se agenda, no se pide el contador de fallos y no se cobra el renglon de Tienda Nube.',
            ],
            [
                'slug'        => 'whatsapp',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida habilita el modulo de chats de WhatsApp con clientes: aparece el item \'WhatsApp\' en el nav lateral (ruta /whatsapp, con lista de chats, conversacion, plantillas y config del agente), pasan las 16 rutas del grupo gateado (/whatsapp-chats/*, /whatsapp-templates/* y sales/{id}/send-whatsapp-agent) y en la botonera del modal de una venta aparece el boton \'enviar comprobante por el agente de WhatsApp\'. Apagada, el item del nav no se dibuja (esta ruta no pide ademas ningun permiso \'can\', asi que la extension es el unico gate del item) y la API responde 403 con \'No tenes acceso a esta funcionalidad. Extension requerida: whatsapp\' a cualquiera de esas 16 rutas. Dos cosas NO se cortan apagada: (1) si el usuario entra a /whatsapp escribiendo la URL, la vista igual carga porque el router solo chequea sesion, y adentro la lista de chats y las plantillas fallan con 403 pero la solapa de config del agente sigue funcionando, porque whatsapp-bot/config vive en otro grupo de rutas que solo pide auth:sanctum; (2) el boton clasico de wa.me (WhatsappBtn), que abre WhatsApp Web con el celular del operador, sigue apareciendo en el modal de Ventas porque no consulta la extension.',
            ],
            [
                'slug'        => 'whatsapp_ia',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida el scheduler registra articles:generate-embeddings cada 30 minutos, que recorre los artículos activos del dueño y genera/actualiza su embedding vectorial para que el bot pueda hacer busqueda semantica del catalogo (RAG). Apagada, el comando no se agenda y si alguien lo corre a mano devuelve 0 en silencio sin tocar nada, con lo cual los artículos quedan con embedding NULL. Importante: apagada NO se apaga la IA del bot — WhatsappBotAiService sigue llamando a la API de Anthropic y contestando igual, solo que search_similar_articles no devuelve ningun artículo y la respuesta va sin contexto de catalogo.',
            ],
            [
                'slug'        => 'support_chat',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida renderiza en toda la aplicacion un boton flotante de soporte (arrastrable, con posicion guardada en localStorage) que muestra un badge rojo con la suma de mensajes de soporte sin leer y, al clickearlo, abre el modal de chat con el equipo de soporte de ComercioCity. Apagada, el boton simplemente no se renderiza y el usuario no tiene ninguna entrada al chat de soporte desde la UI; los endpoints de support-ticket/support-message siguen abiertos igual, la extension no los protege.',
            ],
            [
                'slug'        => 'comerciocity_interno',
                'modulo'      => 'Tienda e integraciones',
                'en_desuso'   => false,
                'description' => 'Encendida muestra en el nav lateral los modulos administrativos del sistema de gestion: Vender (y como hijo Presupuestos, que ademas necesita \'budgets\'), Proveedores, Clientes, Empleados (dentro de ABM) y ProduccionV2 (que ademas necesita \'production\' y \'productionV2\'). Apagada, esos cinco items desaparecen del menu y la cuenta queda solo con las pantallas restantes (Listado, Caja, Ventas, Alertas, Tienda Online, integraciones). Es el toggle que separa "esta cuenta usa el sistema de administracion completo" de "esta cuenta solo usa la tienda / los modulos externos".',
            ],
            [
                'slug'        => 'ventas_en_dolares',
                'modulo'      => 'General',
                'en_desuso'   => false,
                'description' => 'Encendida, la cuenta pasa a operar multimoneda. En VENDER (etapa 1) aparece el bloque Moneda, pero con un matiz importante: el select de Moneda sale siempre que este la extension, y el input de cotizacion USD del mismo bloque tiene una SEGUNDA condicion invertida y solo aparece si la cuenta NO usa listas de precio (Moneda.vue:15 lo esconde cuando hasExtencion(\'articulo_margen_de_ganancia_segun_lista_de_precios\'), slug que generals.js:389 cortocircuita a ownerUsesListasDePrecio()). Aunque el input este escondido, el componente igual se monta y su created() inicializa valor_dolar con owner.dollar: la venta lleva cotizacion, lo que no se puede es editarla desde VENDER. Ademas el select de Tipo de Comprobante AFIP deja de estar deshabilitado. En el listado de articulos el precio por lista se carga con el sub-formulario por moneda (PriceTypeMonedas) en vez del input de lista comun, y del lado de la API el calculo por moneda exige ADEMAS que la cuenta use listas de precio: ArticleHelper.php:259 mete el camino de ArticlePriceTypeMonedaHelper::aplicar_precios_por_price_type_y_moneda adentro de if (uses_listas_de_precio), y el salteo de cotizar() (ArticleHelper.php:236-245, y lo mismo en :205-212) pide las dos condiciones juntas. Tambien se muestran los totales en USD en el listado de Ventas y en Caja, la columna Moneda y Cotizacion USD en la tabla de ventas, las tarjetas en USD del panel de Reportes (Total vendido Bruto USD, Caja USD, Utilidad USD Bruta, etc.), la moneda por metodo de pago en ventas y en gastos, la caja por defecto se elige filtrando por moneda, los botones de cuenta corriente en moneda distinta de pesos, y el PDF de catalogo se ofrece una vez por moneda. APAGADA: todo en pesos. No hay bloque Moneda en VENDER (ni select ni cotizacion), el select de Tipo de Comprobante AFIP queda siempre deshabilitado, los precios por lista se calculan con ArticlePricesHelper::aplicar_precios_segun_listas_de_precios y se cotizan a pesos con cotizar(), los metodos de pago y los gastos quedan fijos en moneda_id 1, la caja por defecto es cajas_por_defecto[0], solo se ven los botones de C/C en pesos, no hay tarjetas USD en Reportes y el PDF de catalogo es uno solo. El cruce a tener presente: en una cuenta con listas de precio -que es justo donde funciona toda la maquinaria de price_type_monedas, y es el caso de racing_carts, la unica cuenta del seeder que trae la extension- el input para escribir la cotizacion del dolar en VENDER no se ve.',
            ],
            [
                'slug'        => 'consultora_de_precios',
                'modulo'      => 'General',
                'en_desuso'   => false,
                'description' => 'Encendida, aparece en el menu lateral el item \'Cons. Precios\' que lleva a la pantalla de consulta de precios: un cartel a pantalla completa con el logo de la empresa y un input para escanear un codigo, pensado para dejar en el salon para que el cliente consulte precios solo. Apagada, el item no se dibuja en el nav (nav.js corta el show), pero la pantalla sigue existiendo y se abre igual tipeando /consultora-de-precios en la URL, porque ni el guard del router ni la API validan la extension. Se ve unicamente en el menu lateral / vista Consultora de precios; no cambia nada dentro de VENDER, listado ni PDF.',
            ],
            [
                'slug'        => 'enviar_mail_a_clientes',
                'modulo'      => 'General',
                'en_desuso'   => false,
                'description' => 'Encendida, en VENDER aparece el checkbox \'Enviar correo al cliente\' cuando el cliente seleccionado tiene email, y al guardar la venta se persiste send_mail y se encola el mail de notificacion (numero de venta, cliente, total, fecha); ademas en el listado de Ventas se habilita el boton de sobre por fila para (re)enviar el correo y la opcion \'Enviar correo a clientes (seleccion)\' del menu de seleccion multiple, y los endpoints POST sale/{id}/send-client-mail y sale/send-client-mail-bulk responden. Apagada, el checkbox y los dos botones no se renderizan, al crear o actualizar una venta send_mail se fuerza a false (en update ni se toca, para no pisar el historico) y los dos endpoints devuelven 403 \'No autorizado\', asi que no sale ningun mail al cliente. Se ve en VENDER (etapa 1, debajo del selector de cliente) y en el listado de Ventas (boton por fila y menu de seleccion).',
            ],
            [
                'slug'        => 'article_num_in_online',
                'modulo'      => 'General',
                'en_desuso'   => true,
                'description' => 'NO ESTA IMPLEMENTADA: por el nombre habria hecho que en la ficha publica del e-commerce se muestre el campo `num` del articulo (columna entera de la tabla articles, empresa-api: database/migrations/2019_10_24_002337_create_articles_table.php:19), ademas del nombre y el precio. En los dos repos del slot, encendida y apagada dan exactamente el mismo resultado, porque ningun archivo consulta el slug: no hay hasExtencion(\'article_num_in_online\') en la SPA ni chequeo en la API, y tampoco aparece como valor de la clave declarativa if_has_extencion. Lo unico que hace la fila es aparecer tildada en la pantalla de extensiones de la cuenta. Aclaracion de alcance: el front publico del e-commerce no vive en ninguno de estos dos repos, asi que la equivalencia encendida/apagada se afirma sobre lo que hay aca; del lado servidor lo que si se puede afirmar es que el unico endpoint que exporta articulos hacia afuera (empresa-api: app/Http/Controllers/Integraciones/ArticulosExportController.php:180-206, ruta publica sin auth en routes/api.php:838) arma el payload campo por campo, manda `sku` y no manda `num`, y no consulta ninguna extension.',
            ],
            [
                'slug'        => 'articulo_multi_proveedor',
                'modulo'      => 'General',
                'en_desuso'   => true,
                'description' => 'NO ESTA IMPLEMENTADA COMO TOGGLE: la capacidad de que un articulo tenga varios proveedores existe y esta SIEMPRE prendida para todas las cuentas, sin consultar la extension. La relacion Article::providers() es un belongsToMany con pivot (amount, cost, price, provider_code) y en el LISTADO el boton \'Proveedores\' de cada fila se muestra con la sola condicion de que el articulo tenga proveedores cargados; abre un modal con el historial (proveedor, codigo de proveedor, ultima cantidad comprada, costo, precio final, fecha). Apagada pasa exactamente lo mismo: la fila de la tabla no cambia nada.',
            ],
            [
                'slug'        => 'ask_save_current_acount',
                'modulo'      => 'General',
                'en_desuso'   => true,
                'description' => 'NO ESTA IMPLEMENTADA: por el nombre habria hecho que al cerrar una venta con cliente el sistema pregunte si esa venta va o no a la cuenta corriente del cliente. No existe esa pregunta: el estado save_current_acount del modulo VENDER arranca en 1 (empresa-spa: src/store/vender/vender.js:246) y nadie lo cambia nunca, asi que toda venta se manda con save_current_acount = 1 por los dos caminos de guardado — el online, que es el POST a /api/sale del store (src/store/vender/vender.js:1048), y el offline (src/mixins/vender/guardar_venta/index.js:203, adentro de guardar_venta_offline() que arranca en :195). La API lo persiste tal cual (empresa-api: app/Http/Controllers/SaleController.php:177) sin preguntar por esta extension. Encendida y apagada se comportan igual, y ademas se le enciende a TODA cuenta nueva y a TODA demo por cableado. Un matiz para no leer de mas: que la venta termine o no en la cuenta corriente igual puede cambiar despues, pero por decision de OTRA extension — SaleController.php:224 llama a SaleHelper::check_guardad_cuenta_corriente_despues_de_facturar($model, $this), que en SaleHelper.php:106-112 pisa save_current_acount a 0 si la cuenta tiene guardad_cuenta_corriente_despues_de_facturar; esta extension no participa de eso.',
            ],
            [
                'slug'        => 'fecha_impresion_en_article_tickets',
                'modulo'      => 'General',
                'en_desuso'   => true,
                'description' => 'NO ESTA IMPLEMENTADA COMO TOGGLE, pero el comportamiento existe y corre SIEMPRE: al imprimir el PDF de etiquetas de gondola, cada etiqueta lleva la fecha del dia (formato dd/mm/aa) al lado del codigo de barras, para toda cuenta, tenga o no la extension. Apagada pasa exactamente lo mismo, porque la llamada no esta dentro de ningun if. La unica cuenta que no ve la fecha es la que tiene article_ticket_print_function = \'golonorte\', porque ese camino usa otra clase que no la imprime.',
            ],
            [
                'slug'        => 'setear_precio_final_en_listas_de_precio',
                'modulo'      => 'General',
                'en_desuso'   => true,
                'description' => 'NO ESTA IMPLEMENTADA: por el nombre habria habilitado la opcion de cargar el precio final a mano en una lista de precios en vez de calcularlo desde el costo con el margen. Esa capacidad existe pero no depende de la extension: es la columna `setear_precio_final` de la tabla price_types (y su equivalente por moneda en el pivot), disponible para cualquier cuenta que use listas de precio. Encendida y apagada no cambia nada, salvo que la fila aparece tildada en la pantalla de extensiones.',
            ],
        ];
    }
}
