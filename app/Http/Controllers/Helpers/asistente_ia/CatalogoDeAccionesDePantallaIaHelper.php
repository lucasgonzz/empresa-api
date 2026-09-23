<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Escritura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * QUÉ PUEDE HACER EL ASISTENTE POR LAS PANTALLAS DEL SISTEMA, Y QUÉ NO (misión asistente-mcp,
 * 22/9/2026, constructor B).
 *
 * El pedido de Lucas fue "literalmente todo lo que se hace desde la interfaz". La interfaz hace más
 * de mil rutas, y la única forma honesta de cubrirlas sin escribir mil herramientas a mano es UNA
 * herramienta genérica que llame a la misma ruta y al mismo controller que llama la pantalla,
 * autenticada como la persona (EjecutorAccionDePantallaIaHelper). Este catálogo decide QUÉ rutas
 * entran en esa herramienta, y es lo único que lo decide.
 *
 * De dónde sale: de Route::getRoutes(), como CatalogoDeEscrituraIaHelper::rutas(). Entra toda ruta
 * cuya URI empieza con `api/` y cuyos middlewares incluyen `auth:sanctum` (o sea, lo que la SPA
 * llama con la sesión de la persona). Por cada ruta y método HTTP (GET, POST, PUT, DELETE; PATCH se
 * pliega en PUT y HEAD se ignora) queda una fila con el método, la URI con sus {param}, la acción
 * (`Clase@metodo` sin el namespace), el módulo (el nombre del controller, humanizado), los nombres
 * de los {param}, las claves del request que el método lee (best-effort, por regex sobre el código:
 * ver Escritura::claves_que_lee()) y la extensión que exige `check_extencion_empresa:<slug>`, si la
 * tiene.
 *
 * 🔴 LA LISTA NEGRA ES EXPLÍCITA Y CADA ENTRADA LLEVA SU MOTIVO, como Escritura::EXCLUIDAS. Una
 * exclusión vaga es la que el modelo rellena inventando, y una ruta excluida sin motivo es una ruta
 * que alguien vuelve a meter "porque no parecía peligrosa". Lo que no entra, y por qué:
 *
 *   - El asistente mismo (sus conversaciones, el MCP, el mostrador, su configuración): un modelo
 *     que puede cambiar su propio modo de confianza o borrar sus conversaciones es un modelo que se
 *     puede desarmar solo.
 *   - Sesión y cuenta: el usuario autenticado, el candado de sesión, contraseñas, y la configuración
 *     de la cuenta (`PUT user/{id}` dispara el recálculo de TODOS los precios).
 *   - Credenciales: métodos de pago de la tienda (las claves de Mercado Pago), conectores, AFIP,
 *     empleados (con su contraseña), la escritura sobre compradores de la tienda, y las
 *     integraciones (Mercado Pago, Zippin, Tienda Nube, Mercado Libre).
 *   - Lo que manda mensajes a terceros: por URI (`send`, `enviar`), por controller
 *     (`Whatsapp*Send*`, `*Mail*`, `RecordatorioCobro*`) y por método (`@send_*`, `@enviar_*`).
 *     Decisión de la misión: mandar mensajes a terceros sigue afuera del asistente.
 *   - Masivas por POST/PUT: el borrado en masa de DeleteController, poner en 0 el stock de una lista,
 *     revertir una masiva o una importación. Lo masivo SIEMPRE se confirma (decisión de Lucas con la
 *     actualización masiva), y una acción por POST/PUT con el dueño en "directo" se ejecuta sin
 *     tarjeta: por eso no entran.
 *   - Lo que devuelve o recibe archivos (PDF, Excel, imágenes...): desde el chat no se ven ni se
 *     adjuntan.
 *   - `admin-sync/*` y `claude/*`: las APIs internas para el admin y para Claude.
 *   - 🔴 El alta, la edición y la baja de las entidades de Escritura::ENTIDADES: esas van por
 *     proponer_alta / proponer_edicion / proponer_baja, que validan campos, resuelven relaciones por
 *     nombre y avisan qué queda colgado. Y las cargas que tienen su propia herramienta (ventas,
 *     gastos, tareas, combos, ofertas, presupuestos nuevos, los movimientos de stock, los diseños de
 *     PDF): HERRAMIENTAS_PROPIAS y HERRAMIENTAS_DE_ENTIDAD. Una ruta con herramienta propia NO se
 *     ofrece dos veces: la propia sabe más que la genérica.
 *
 * Lo excluido no aparece en el catálogo, declaracion() devuelve null para eso y
 * motivo_de_exclusion() dice por qué, para que la herramienta se lo cuente al modelo.
 *
 * 🔴 LO QUE ENTRA PERO SIEMPRE SE CONFIRMA (SIEMPRE_CONFIRMAN): una acción puede estar en el
 * catálogo y aun así no poder correr sola con el dueño en "directo". Hoy es todo lo de AFIP: emitir
 * un comprobante ante ARCA es irreversible (se reversa con una nota de crédito, no borrándolo).
 * Cada fila lleva `siempre_confirma` y `motivo_confirmacion`, que_acciones_de_pantalla_hay los
 * muestra, y PropuestaAccionDePantallaIaHelper marca la respuesta con `requiere_confirmacion`, que
 * es lo que quizas_auto_confirmar() respeta sin que HerramientasDeCarga sepa nada de AFIP.
 *
 * La tenencia de los ids que viajan en la ruta no la decide el catálogo sino el ejecutor
 * (EjecutorAccionDePantallaIaHelper::verificar_tenencia()), a partir de los nombres de los {param}
 * que este catálogo declara.
 *
 * Cacheado por proceso (olvidar() para los tests). El orden es estable —por ruta y después por
 * método— para que la paginación de lista() no cambie entre llamadas.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 */
class CatalogoDeAccionesDePantallaIaHelper
{
    /** Los métodos que entran, en el orden en que se listan. */
    const METODOS = ['GET', 'POST', 'PUT', 'DELETE'];

    /** Cuántas acciones devuelve lista() por página. */
    const POR_PAGINA = 40;

    /** El middleware de sesión, como alias de ruta y como clase resuelta. */
    const AUTH_ALIAS = 'auth:sanctum';

    const AUTH_CLASE = 'App\Http\Middleware\Authenticate:sanctum';

    /** El middleware de extensión, como alias y como clase, seguido del slug. */
    const EXTENSION_ALIAS = 'check_extencion_empresa:';

    const EXTENSION_CLASE = 'App\Http\Middleware\CheckExtencionEmpresa:';

    /**
     * LAS EXCLUIDAS POR MÉTODO Y URI: `[patrón => motivo]`, evaluadas con preg_match sobre
     * `"METODO api/la/ruta/{con}/{params}"` (el método en mayúsculas, la URI tal cual la declara el
     * router). La primera que matchea manda.
     *
     * Van agrupadas por el porqué (ver el docblock de la clase). Los patrones son deliberadamente
     * anchos donde el riesgo es de credenciales o de mensajes: excluir de más una ruta inocua cuesta
     * una acción menos; excluir de menos cuesta una clave de Mercado Pago en un tool_result.
     *
     * @var array<string, string>
     */
    const EXCLUIDAS = [
        // ── El asistente mismo ────────────────────────────────────────────────────────────────
        '#^[A-Z]+ api/ai-conversations(/|$)#'         => 'el asistente mismo: sus conversaciones se manejan desde el chat, no desde una acción',
        '#^[A-Z]+ api/ai-mensajes(/|$)#'              => 'el asistente mismo: las fotos de sus propios mensajes',
        '#^[A-Z]+ api/mcp(/|$)#'                      => 'el asistente mismo: el servidor MCP y su clave de conexión',
        '#^[A-Z]+ api/mostrador(/|$)#'                => 'el asistente mismo: los informes del mostrador',
        '#^[A-Z]+ api/user/asistente-config$#'        => 'el asistente mismo: su configuración (el modo de confianza no se cambia desde una acción)',
        '#^[A-Z]+ api/mi-consumo-ia$#'                => 'el asistente mismo: su consumo del plan',
        '#^[A-Z]+ api/user/set-chat-ia-preferencias#' => 'el asistente mismo: sus preferencias de chat',
        '#/ficha-asistente$#'                         => 'el asistente mismo: la ficha de una mención del chat',
        '#/para-cuenta-corriente$#'                   => 'el asistente mismo: el cliente de una mención del chat',
        // ── Sesión y cuenta ───────────────────────────────────────────────────────────────────
        '#^[A-Z]+ api/user$#'                         => 'sesión y cuenta: el usuario autenticado',
        '#^[A-Z]+ api/user/\{id\}$#'                  => 'sesión y cuenta: la configuración de la cuenta (márgenes, IVA, redondeos) dispara el recálculo de todos los precios',
        '#api/user/set_eliminar_articulos_offline#'   => 'sesión y cuenta: escribe sobre cualquier usuario por su id, sin filtrar por dueño (UserController::set_eliminar_articulos_offline)',
        '#logout#'                                    => 'sesión y cuenta: cerrar sesión',
        '#session#'                                   => 'sesión y cuenta: el candado de sesión',
        '#token#'                                     => 'sesión y cuenta: tokens',
        '#password#'                                  => 'sesión y cuenta: contraseñas',
        '#set-comercio-city-user#'                    => 'sesión y cuenta: vincula cuentas de ComercioCity entre sí, y busca usuarios sin filtrar por dueño',
        // ── Credenciales ──────────────────────────────────────────────────────────────────────
        '#api/payment-method(/|$)#'                   => 'credenciales: los métodos de pago de la tienda guardan las claves de Mercado Pago',
        '#platform-connector#'                        => 'credenciales: conectores de plataforma',
        '#afip-information#'                          => 'credenciales: los datos de AFIP del negocio',
        '#afip-.*(cert|key|token)#'                   => 'credenciales: certificados y claves de AFIP',
        '#employee#'                                  => 'credenciales: los empleados llevan contraseña y permisos (para un permiso está proponer_permiso_de_empleado)',
        '#^(POST|PUT|DELETE) api/buyer(/|$)#'         => 'credenciales: las cuentas de la tienda online llevan contraseña (leerlas sí se puede)',
        '#mercado-?pago#'                             => 'credenciales: Mercado Pago',
        '#zippin#'                                    => 'credenciales: Zippin / Zipnova',
        '#tienda-?nube#'                              => 'credenciales: Tienda Nube',
        '#meli#'                                      => 'credenciales: Mercado Libre',
        // ── Mensajes a terceros ───────────────────────────────────────────────────────────────
        '#(^|[/-])send([-/]|$)#'                      => 'manda mensajes a terceros',
        '#enviar#'                                    => 'manda mensajes a terceros',
        '#^(POST|PUT|DELETE) api/whatsapp-chats/.*(messages|media|template)#' => 'manda mensajes a un cliente por WhatsApp',
        '#whatsapp-bot/simulate-inbound#'             => 'simula un mensaje entrante y dispara el bot de WhatsApp',
        // ── Masivas por POST/PUT (con el dueño en "directo" se ejecutarían sin tarjeta) ───────
        '#^PUT api/delete/#'                          => 'borrado en masa por PUT: un borrado va por proponer_baja o por proponer_borrado_por_pantalla, que siempre confirman',
        '#article/reset-stock#'                       => 'masiva: deja en 0 el stock de una lista de artículos de un saque',
        '#masive-update/.*/revert#'                   => 'masiva: revierte una actualización masiva entera',
        '#import-history/rollback#'                   => 'masiva: revierte una importación entera',
        '#articles-pre-import/update-articles#'       => 'masiva: aplica el pre-import a todos los artículos alcanzados',
        // ── Ya tienen herramienta propia (las de recurso van por HERRAMIENTAS_PROPIAS) ────────
        '#api/pdf-column-profiles#'                   => 'tiene su herramienta: proponer_cambio_en_diseno_pdf',
        // ── Archivos ──────────────────────────────────────────────────────────────────────────
        '#pdf|excel|export|download|print|imagen|image|foto|file|csv|zip|qr#' => 'devuelve o recibe un archivo, y desde el chat un archivo no se ve ni se adjunta',
        // ── Sincronización y Claude ───────────────────────────────────────────────────────────
        '#admin-sync#'                                => 'la sincronización con el admin de ComercioCity',
        '#claude/#'                                   => 'la API interna para Claude',
    ];

    /**
     * LAS EXCLUIDAS POR CONTROLLER: `[patrón => motivo]`, evaluadas sobre `Clase@metodo` (sin el
     * namespace App\Http\Controllers). Es la otra mitad de "lo que manda mensajes a terceros": la
     * URI no siempre lo dice (`POST whatsapp-chats/{id}/messages` manda un WhatsApp), el nombre del
     * método casi siempre.
     *
     * @var array<string, string>
     */
    const EXCLUIDAS_POR_CONTROLLER = [
        '#Whatsapp[A-Za-z]*Send#' => 'manda mensajes a terceros (WhatsApp)',
        '#[Mm]ail#'               => 'manda mensajes a terceros (mail)',
        '#RecordatorioCobro#'     => 'manda mensajes a terceros (recordatorios de cobro)',
        '#@(send|enviar)#i'       => 'manda mensajes a terceros',
    ];

    /**
     * LAS QUE ENTRAN PERO SIEMPRE DEJAN TARJETA, en los tres modos: `[patrón => motivo]`, evaluadas
     * sobre `"METODO uri"` y sobre `Clase@metodo`, sin distinguir mayúsculas. Decisión de la misión
     * asistente-mcp (22/9/2026): emitir un comprobante ante ARCA no se deshace, así que ni el modo
     * "directo" lo ejecuta solo. El patrón es ancho a propósito: agarra también los comprobantes de
     * compras y la configuración fiscal, y ahí confirmar de más no cuesta nada.
     *
     * @var array<string, string>
     */
    const SIEMPRE_CONFIRMAN = [
        '#afip#i' => 'emite un comprobante ante ARCA: no se deshace',
    ];

    /**
     * Rutas exactas (`METODO uri`) que ya tienen una herramienta del asistente que sabe más que la
     * genérica: resuelve nombres, valida, arma la tarjeta con lo que la persona entiende. Se
     * excluyen para que el modelo no tenga dos caminos para la misma carga.
     *
     * @var array<string, string>
     */
    const HERRAMIENTAS_PROPIAS = [
        'POST api/combo'                   => 'proponer_combo',
        'POST api/client-offer'            => 'proponer_oferta',
        'POST api/stock-movement'          => 'proponer_movimiento_de_stock',
        'PUT api/article-update-addresses' => 'proponer_stock_en_deposito',
        'POST api/pending-completed'       => 'proponer_marcar_tarea_hecha',
        'POST api/budget'                  => 'proponer_presupuesto',
    ];

    /**
     * Las operaciones de recurso de una entidad de Escritura::ENTIDADES que el ABM genérico NO
     * ofrece porque tienen herramienta propia: `[entidad => [operacion => herramienta]]`. Las que
     * el genérico sí ofrece se excluyen con "usá proponer_<op>"; las que no están ni acá ni ahí
     * (editar una venta por `PUT api/sale/{id}`) quedan en el catálogo, que es justamente lo que
     * esta misión vino a abrir.
     *
     * @var array<string, array<string, string>>
     */
    const HERRAMIENTAS_DE_ENTIDAD = [
        'sale'    => [Escritura::OP_ALTA => 'proponer_venta'],
        'expense' => [Escritura::OP_ALTA => 'proponer_gasto'],
        'pending' => [Escritura::OP_ALTA => 'proponer_tarea', Escritura::OP_EDICION => 'proponer_cambios_en_tarea'],
    ];

    /** @var array<string, array<string, mixed>>|null  ['METODO uri' => fila] */
    protected static $catalogo = null;

    /** @var array<string, string>  ['METODO uri' => motivo] */
    protected static $excluidas = [];

    /** @var array<string, string>  ['METODO api/x/{}/y' => 'METODO api/x/{id}/y'], para tolerar otro nombre de {param} */
    protected static $por_forma = [];

    /** @var array<string, mixed> */
    protected static $conteo = [];

    // -------------------------------------------------------------------------------------------
    // API pública
    // -------------------------------------------------------------------------------------------

    /**
     * Una página del catálogo, para la herramienta que_acciones_de_pantalla_hay.
     *
     * `buscar` es substring sin acentos ni mayúsculas sobre ruta + módulo + acción; con más de una
     * palabra tienen que estar todas ("cerrar caja" encuentra `cerrar-caja`: los guiones, guiones
     * bajos y barras de la ruta también cuentan como espacios).
     *
     * @param  string|null  $buscar
     * @param  string|null  $metodo  GET | POST | PUT | DELETE, o nada.
     * @param  int  $pagina
     * @return array{acciones: array, encontradas: int, pagina: int, paginas: int, como_sigo: string}
     */
    public static function lista($buscar = null, $metodo = null, $pagina = 1): array
    {
        $metodo = is_null($metodo) || trim((string) $metodo) === '' ? null : self::normalizar_metodo($metodo);

        $palabras = [];

        if (!is_null($buscar) && trim((string) $buscar) !== '') {

            $palabras = preg_split('/\s+/', ConsultasSistemaIaHelper::normalize_text((string) $buscar), -1, PREG_SPLIT_NO_EMPTY);
        }

        $filas = [];

        foreach (self::catalogo() as $fila) {

            if (!is_null($metodo) && $fila['metodo'] !== $metodo) {

                continue;
            }

            if (count($palabras)) {

                $pajar = ConsultasSistemaIaHelper::normalize_text($fila['ruta'].' '.$fila['modulo'].' '.$fila['accion']);
                $pajar .= ' '.str_replace(['-', '_', '/'], ' ', $pajar);

                foreach ($palabras as $palabra) {

                    if (strpos($pajar, $palabra) === false) {

                        continue 2;
                    }
                }
            }

            $filas[] = $fila;
        }

        $encontradas = count($filas);
        $paginas = max(1, (int) ceil($encontradas / self::POR_PAGINA));
        $pagina = max(1, min((int) $pagina, $paginas));

        $acciones = array_slice($filas, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        if ($encontradas === 0) {

            $como_sigo = 'No hay ninguna acción de pantalla con ese texto. Las rutas están en inglés (caja, budget, sale, article, client, provider, order...): probá con otra palabra o sin filtro. Si lo que buscás es cargar un gasto, un pago, una tarea, una venta o algo del ABM, eso tiene su propia herramienta y no está acá.';

        } else {

            $como_sigo = 'Elegí la acción y pasale su `ruta` TAL CUAL (con los {param}) a consultar_por_pantalla (GET), proponer_accion_de_pantalla (POST o PUT) o proponer_borrado_por_pantalla (DELETE), con los valores de los {param} en `parametros`. Las `claves` son las que el controller lee del cuerpo, best-effort: puede leer más o menos de lo que dice. Una acción con `siempre_confirma: true` deja tarjeta aunque la confianza esté en "directo" (`motivo_confirmacion` dice por qué): no digas que quedó hecha.';

            if ($paginas > 1) {

                $como_sigo .= ' Hay '.$paginas.' páginas: pedí la siguiente con `pagina`, o afiná `buscar`.';
            }
        }

        return [
            'acciones'    => array_values($acciones),
            'encontradas' => $encontradas,
            'pagina'      => $pagina,
            'paginas'     => $paginas,
            'como_sigo'   => $como_sigo,
        ];
    }

    /**
     * Todas las filas del catálogo, en su orden (para los tests y para quien quiera recorrerlo
     * entero sin paginar).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function todas(): array
    {
        return array_values(self::catalogo());
    }

    /**
     * La fila del catálogo para un método y una ruta, o null si no existe o está excluida.
     *
     * La ruta se acepta con o sin `api/` adelante, con o sin la barra inicial, como URL completa,
     * con los {param} literales (`api/caja/{caja_id}/cerrar`, incluso con otro nombre de param) o
     * ya reemplazados (`api/caja/12/cerrar`, que se resuelve contra el router).
     *
     * @param  string  $metodo
     * @param  string  $ruta
     * @return array<string, mixed>|null
     */
    public static function declaracion($metodo, $ruta)
    {
        $metodo = self::normalizar_metodo($metodo);
        $ruta = self::normalizar_ruta($ruta);

        if (!in_array($metodo, self::METODOS, true) || $ruta === '') {

            return null;
        }

        $catalogo = self::catalogo();

        $clave = $metodo.' '.$ruta;

        if (isset($catalogo[$clave])) {

            return $catalogo[$clave];
        }

        if (strpos($ruta, '{') !== false) {

            $forma = $metodo.' '.self::forma_de($ruta);

            return isset(self::$por_forma[$forma]) && isset($catalogo[self::$por_forma[$forma]])
                ? $catalogo[self::$por_forma[$forma]]
                : null;
        }

        return self::resolver_ruta($metodo, $ruta);
    }

    /**
     * La fila del catálogo que atiende una URI concreta (`api/caja/12/cerrar` → la de
     * `api/caja/{caja_id}/cerrar`), resuelta con el MISMO matcher del router: así lo que el catálogo
     * dice que va a correr es exactamente lo que `$route->run()` corre después.
     *
     * @param  string  $metodo
     * @param  string  $ruta_con_valores
     * @return array<string, mixed>|null
     */
    public static function resolver_ruta($metodo, $ruta_con_valores)
    {
        $metodo = self::normalizar_metodo($metodo);
        $uri = self::normalizar_ruta($ruta_con_valores);

        if (!in_array($metodo, self::METODOS, true) || $uri === '') {

            return null;
        }

        $ruta = self::ruta_que_matchea($metodo, $uri);

        if (is_null($ruta)) {

            return null;
        }

        $catalogo = self::catalogo();

        $clave = $metodo.' '.$ruta->uri();

        return isset($catalogo[$clave]) ? $catalogo[$clave] : null;
    }

    /**
     * Por qué una ruta no está en el catálogo, o null si no está excluida (porque entra, o porque
     * directamente no existe en el router).
     *
     * @param  string  $metodo
     * @param  string  $ruta  Con {param} o con valores.
     * @return string|null
     */
    public static function motivo_de_exclusion($metodo, $ruta)
    {
        $metodo = self::normalizar_metodo($metodo);
        $ruta = self::normalizar_ruta($ruta);

        if (!in_array($metodo, self::METODOS, true) || $ruta === '') {

            return null;
        }

        self::catalogo();

        $clave = $metodo.' '.$ruta;

        if (isset(self::$excluidas[$clave])) {

            return self::$excluidas[$clave];
        }

        if (strpos($ruta, '{') !== false) {

            $forma = $metodo.' '.self::forma_de($ruta);

            return isset(self::$por_forma[$forma]) && isset(self::$excluidas[self::$por_forma[$forma]])
                ? self::$excluidas[self::$por_forma[$forma]]
                : null;
        }

        $encontrada = self::ruta_que_matchea($metodo, $ruta);

        if (is_null($encontrada)) {

            return null;
        }

        $clave = $metodo.' '.$encontrada->uri();

        return isset(self::$excluidas[$clave]) ? self::$excluidas[$clave] : null;
    }

    /**
     * Los {param} obligatorios de la ruta que no vienen en `$parametros` (un valor null, vacío o
     * que no es escalar cuenta como que no vino). Los opcionales (`{desde?}`) nunca faltan.
     *
     * @param  string  $ruta
     * @param  array  $parametros
     * @return array<int, string>
     */
    public static function parametros_que_faltan(string $ruta, array $parametros): array
    {
        $faltan = [];

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\??)\}/', $ruta, $m, PREG_SET_ORDER);

        foreach ($m as $param) {

            if ($param[2] === '?') {

                continue;
            }

            if (!self::vino($parametros, $param[1])) {

                $faltan[] = $param[1];
            }
        }

        return $faltan;
    }

    /**
     * La URI concreta: cada {param} reemplazado por su valor (codificado), y cada {param?} sin
     * valor sacado de la ruta. Llamar antes a parametros_que_faltan(): acá un obligatorio sin valor
     * queda como está y no matchea nada.
     *
     * @param  string  $ruta
     * @param  array  $parametros
     * @return string
     */
    public static function uri_concreta(string $ruta, array $parametros): string
    {
        $uri = self::normalizar_ruta($ruta);

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)(\??)\}/', $uri, $m, PREG_SET_ORDER);

        foreach ($m as $param) {

            if (self::vino($parametros, $param[1])) {

                $valor = $parametros[$param[1]];

                // Un booleano se escribe como 1/0: (string) false es '' y dejaría un segmento vacío.
                $valor = is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor;

                $uri = str_replace($param[0], rawurlencode($valor), $uri);

            } elseif ($param[2] === '?') {

                $uri = str_replace('/'.$param[0], '', $uri);
                $uri = str_replace($param[0], '', $uri);
            }
        }

        return trim(preg_replace('#/+#', '/', $uri), '/');
    }

    /**
     * El método HTTP en mayúsculas, con PATCH plegado en PUT.
     *
     * @param  mixed  $metodo
     * @return string
     */
    public static function normalizar_metodo($metodo): string
    {
        $metodo = strtoupper(trim((string) $metodo));

        return $metodo === 'PATCH' ? 'PUT' : $metodo;
    }

    /**
     * La ruta como la declara el router: sin host, sin `public/`, sin barra inicial, sin query
     * string ni fragmento, y con `api/` adelante.
     *
     * @param  mixed  $ruta
     * @return string
     */
    public static function normalizar_ruta($ruta): string
    {
        $ruta = trim((string) $ruta);

        if ($ruta === '') {

            return '';
        }

        if (preg_match('#^https?://#i', $ruta)) {

            $path = parse_url($ruta, PHP_URL_PATH);

            $ruta = is_string($path) ? $path : '';
        }

        /*
         * La query string se saca desde el primer `?` que NO sea el de un {param?} opcional: en
         * `api/budget/from-date/{from_date}/{until_date?}` ese `?` es parte de la ruta y va seguido
         * de `}`; en `api/x?desde=1` va seguido de otra cosa (o de nada).
         */
        $ruta = preg_replace('/#.*$/', '', $ruta);
        $ruta = preg_replace('/\?(?!\}).*$/', '', $ruta);
        $ruta = trim($ruta, "/ \t");

        if (Str::startsWith($ruta, 'public/')) {

            $ruta = substr($ruta, strlen('public/'));
        }

        if ($ruta !== '' && $ruta !== 'api' && !Str::startsWith($ruta, 'api/')) {

            $ruta = 'api/'.$ruta;
        }

        return $ruta;
    }

    /**
     * Los números del catálogo, para el informe y para los tests: cuántas entran (total y por
     * método), cuántas quedaron afuera y por qué motivo, y lo que se salteó (sin sesión, closures).
     *
     * @return array<string, mixed>
     */
    public static function conteo(): array
    {
        self::catalogo();

        return self::$conteo;
    }

    /**
     * Olvida la derivación (para los tests).
     *
     * @return void
     */
    public static function olvidar(): void
    {
        self::$catalogo = null;
        self::$excluidas = [];
        self::$por_forma = [];
        self::$conteo = [];
    }

    // -------------------------------------------------------------------------------------------
    // La derivación
    // -------------------------------------------------------------------------------------------

    /**
     * El catálogo derivado, cacheado por proceso.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function catalogo(): array
    {
        if (is_null(self::$catalogo)) {

            self::derivar();
        }

        return self::$catalogo;
    }

    /**
     * Recorre el router una vez y arma el catálogo, la lista de excluidas y el conteo.
     *
     * @return void
     */
    protected static function derivar(): void
    {
        $catalogo = [];
        $excluidas = [];
        $por_forma = [];
        $por_metodo = array_fill_keys(self::METODOS, 0);
        $por_motivo = [];
        $sin_sesion = 0;
        $closures = 0;

        foreach (Route::getRoutes() as $ruta) {

            $uri = $ruta->uri();

            if (strpos($uri, 'api/') !== 0) {

                continue;
            }

            $middlewares = self::middlewares_de($ruta);

            if (!in_array(self::AUTH_ALIAS, $middlewares, true) && !in_array(self::AUTH_CLASE, $middlewares, true)) {

                $sin_sesion++;

                continue;
            }

            $accion_completa = (string) $ruta->getActionName();

            if ($accion_completa === 'Closure' || strpos($accion_completa, '@') === false) {

                $closures++;

                continue;
            }

            list($clase, $metodo_php) = explode('@', $accion_completa, 2);

            $clase = ltrim($clase, '\\');

            $accion = Str::startsWith($clase, 'App\Http\Controllers\\')
                ? substr($clase, strlen('App\Http\Controllers\\')).'@'.$metodo_php
                : $clase.'@'.$metodo_php;

            $extension = self::extension_de($middlewares);

            foreach ($ruta->methods() as $metodo) {

                $metodo = self::normalizar_metodo($metodo);

                if (!in_array($metodo, self::METODOS, true)) {

                    continue;
                }

                $clave = $metodo.' '.$uri;

                // La primera ruta declarada gana, que es también la que el router elige al matchear.
                if (isset($catalogo[$clave]) || isset($excluidas[$clave])) {

                    continue;
                }

                $por_metodo[$metodo]++;

                $forma = $metodo.' '.self::forma_de($uri);

                if (!isset($por_forma[$forma])) {

                    $por_forma[$forma] = $clave;
                }

                $motivo = self::motivo_para($metodo, $uri, $accion, $clase, $metodo_php);

                if (!is_null($motivo)) {

                    $excluidas[$clave] = $motivo;
                    $por_motivo[$motivo] = (isset($por_motivo[$motivo]) ? $por_motivo[$motivo] : 0) + 1;

                    continue;
                }

                $motivo_confirmacion = self::motivo_de_confirmacion($metodo, $uri, $accion);

                $catalogo[$clave] = [
                    'metodo'              => $metodo,
                    'ruta'                => $uri,
                    'accion'              => $accion,
                    'modulo'              => self::modulo_de($clase),
                    'parametros'          => array_values($ruta->parameterNames()),
                    'claves'              => self::claves_de($clase, $metodo_php),
                    'extension'           => $extension,
                    'siempre_confirma'    => !is_null($motivo_confirmacion),
                    'motivo_confirmacion' => $motivo_confirmacion,
                ];
            }
        }

        /*
         * Orden estable: por ruta y, dentro de la misma ruta, GET, POST, PUT, DELETE. El router las
         * tiene en orden de declaración, que cambia cada vez que alguien suma una ruta en el medio
         * de routes/api.php, y la paginación de lista() no puede moverse por eso.
         */
        uasort($catalogo, function ($a, $b) {

            $por_ruta = strcmp($a['ruta'], $b['ruta']);

            if ($por_ruta !== 0) {

                return $por_ruta;
            }

            return array_search($a['metodo'], self::METODOS, true) - array_search($b['metodo'], self::METODOS, true);
        });

        ksort($por_motivo);

        self::$catalogo = $catalogo;
        self::$excluidas = $excluidas;
        self::$por_forma = $por_forma;
        self::$conteo = [
            'total'                => count($catalogo),
            'por_metodo'           => self::contar_por_metodo($catalogo),
            'consideradas'         => array_sum($por_metodo),
            'excluidas'            => count($excluidas),
            'excluidas_por_motivo' => $por_motivo,
            'sin_sesion'           => $sin_sesion,
            'closures'             => $closures,
        ];
    }

    /**
     * El motivo por el que una ruta no entra, o null si entra. El orden es el de la lista negra:
     * patrones de URI, patrones de controller, herramientas propias, el ABM genérico y, al final,
     * la subida de archivos (que necesita leer el código del método).
     *
     * @param  string  $metodo
     * @param  string  $uri
     * @param  string  $accion  Clase@metodo sin namespace.
     * @param  string  $clase  Clase completa.
     * @param  string  $metodo_php
     * @return string|null
     */
    protected static function motivo_para(string $metodo, string $uri, string $accion, string $clase, string $metodo_php)
    {
        $texto = $metodo.' '.$uri;

        foreach (self::EXCLUIDAS as $patron => $motivo) {

            if (preg_match($patron, $texto)) {

                return $motivo;
            }
        }

        foreach (self::EXCLUIDAS_POR_CONTROLLER as $patron => $motivo) {

            if (preg_match($patron, $accion)) {

                return $motivo;
            }
        }

        if (isset(self::HERRAMIENTAS_PROPIAS[$texto])) {

            return 'tiene su herramienta: '.self::HERRAMIENTAS_PROPIAS[$texto];
        }

        $abm = self::motivo_del_abm_generico($metodo, $uri);

        if (!is_null($abm)) {

            return $abm;
        }

        $cuerpo = null;

        try {

            $cuerpo = Escritura::cuerpo_del_metodo($clase, $metodo_php);

        } catch (\Throwable $e) {

            $cuerpo = null;
        }

        if (is_string($cuerpo) && (strpos($cuerpo, '->file(') !== false || strpos($cuerpo, 'hasFile(') !== false)) {

            return 'sube un archivo, y desde el chat no se puede adjuntar';
        }

        return null;
    }

    /**
     * El motivo por el que una acción del catálogo deja tarjeta en los tres modos, o null si se
     * rige por el modo de confianza como las demás (ver SIEMPRE_CONFIRMAN).
     *
     * @param  string  $metodo
     * @param  string  $uri
     * @param  string  $accion  Clase@metodo sin namespace.
     * @return string|null
     */
    protected static function motivo_de_confirmacion(string $metodo, string $uri, string $accion)
    {
        foreach (self::SIEMPRE_CONFIRMAN as $patron => $motivo) {

            if (preg_match($patron, $metodo.' '.$uri) || preg_match($patron, $accion)) {

                return $motivo;
            }
        }

        return null;
    }

    /**
     * Si la ruta es el alta, la edición o la baja de recurso de una entidad de Escritura::ENTIDADES:
     * "usá proponer_<op>" cuando el genérico la ofrece, la herramienta propia cuando la tiene, y
     * null cuando ninguna de las dos (esa sí queda como acción de pantalla).
     *
     * @param  string  $metodo
     * @param  string  $uri
     * @return string|null
     */
    protected static function motivo_del_abm_generico(string $metodo, string $uri)
    {
        if ($metodo === 'POST' && preg_match('#^api/([a-z0-9\-]+)$#', $uri, $m)) {

            $operacion = Escritura::OP_ALTA;

        } elseif (($metodo === 'PUT' || $metodo === 'DELETE') && preg_match('#^api/([a-z0-9\-]+)/\{[a-zA-Z_]+\}$#', $uri, $m)) {

            $operacion = $metodo === 'PUT' ? Escritura::OP_EDICION : Escritura::OP_BAJA;

        } else {

            return null;
        }

        $entidad = str_replace('-', '_', $m[1]);

        if (!isset(Escritura::ENTIDADES[$entidad])) {

            return null;
        }

        $curada = Escritura::ENTIDADES[$entidad];

        $permitidas = isset($curada['operaciones']) && is_array($curada['operaciones']) ? $curada['operaciones'] : Escritura::OPERACIONES;

        if (in_array($operacion, $permitidas, true)) {

            return 'ya lo hace el ABM genérico: usá proponer_'.$operacion;
        }

        if (isset(self::HERRAMIENTAS_DE_ENTIDAD[$entidad][$operacion])) {

            return 'tiene su herramienta: '.self::HERRAMIENTAS_DE_ENTIDAD[$entidad][$operacion];
        }

        return null;
    }

    /**
     * Los middlewares de la ruta como strings (alias o clases), SIN instanciar el controller.
     *
     * 🔴 Se lee `$ruta->middleware()` y no `gatherMiddleware()` ni `Router::gatherRouteMiddleware()`
     * a propósito: esos dos instancian el controller para preguntarle por su middleware, y acá se
     * recorren más de doscientos controllers desde un job, sin request ni sesión. El middleware de
     * grupo (`Route::middleware([...])->group()`) ya viene mergeado en el de cada ruta, así que
     * alcanza. Se aceptan las dos formas —el alias `auth:sanctum` y la clase resuelta— por si
     * alguien registra la clase directo.
     *
     * @param  \Illuminate\Routing\Route  $ruta
     * @return array<int, string>
     */
    protected static function middlewares_de($ruta): array
    {
        $lista = [];

        foreach ((array) $ruta->middleware() as $middleware) {

            if (is_string($middleware)) {

                $lista[] = ltrim($middleware, '\\');
            }
        }

        return $lista;
    }

    /**
     * El slug de `check_extencion_empresa:<slug>` si la ruta lo tiene, o null.
     *
     * @param  array<int, string>  $middlewares
     * @return string|null
     */
    protected static function extension_de(array $middlewares)
    {
        foreach ($middlewares as $middleware) {

            foreach ([self::EXTENSION_ALIAS, self::EXTENSION_CLASE] as $prefijo) {

                if (Str::startsWith($middleware, $prefijo)) {

                    $slug = trim(substr($middleware, strlen($prefijo)));

                    return $slug === '' ? null : $slug;
                }
            }
        }

        return null;
    }

    /**
     * El nombre del controller, humanizado: `ResumenCajaController` → "Resumen caja",
     * `Stock\StockMovementController` → "Stock movement".
     *
     * @param  string  $clase
     * @return string
     */
    protected static function modulo_de(string $clase): string
    {
        $corto = preg_replace('/Controller$/', '', class_basename($clase));

        $palabras = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $corto);

        return Str::ucfirst(mb_strtolower(trim((string) $palabras)));
    }

    /**
     * Las claves del request que lee el método, best-effort; [] si no se pudo leer el código.
     *
     * @param  string  $clase
     * @param  string  $metodo_php
     * @return array<int, string>
     */
    protected static function claves_de(string $clase, string $metodo_php): array
    {
        try {

            return Escritura::claves_que_lee($clase, $metodo_php);

        } catch (\Throwable $e) {

            return [];
        }
    }

    /**
     * La ruta del router que atiende una URI concreta con ese método, o null. Usa el matcher de
     * Laravel (el mismo que después corre la acción), y traduce sus excepciones de "no hay ruta" y
     * "no con ese método" a null.
     *
     * @param  string  $metodo
     * @param  string  $uri  Ya normalizada.
     * @return \Illuminate\Routing\Route|null
     */
    protected static function ruta_que_matchea(string $metodo, string $uri)
    {
        try {

            return Route::getRoutes()->match(Request::create('/'.$uri, $metodo));

        } catch (HttpExceptionInterface $e) {

            return null;

        } catch (\Throwable $e) {

            Log::warning('CatalogoDeAccionesDePantallaIaHelper: no se pudo resolver una ruta', [
                'metodo' => $metodo,
                'uri'    => $uri,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * La ruta con cada {param} reducido a `{}`, para comparar rutas que difieren solo en el nombre
     * del parámetro (`{id}` contra `{caja_id}`).
     *
     * @param  string  $ruta
     * @return string
     */
    protected static function forma_de(string $ruta): string
    {
        return preg_replace('/\{[^}]*\}/', '{}', $ruta);
    }

    /**
     * true si el parámetro vino con un valor escalar no vacío.
     *
     * @param  array  $parametros
     * @param  string  $nombre
     * @return bool
     */
    protected static function vino(array $parametros, string $nombre): bool
    {
        if (!array_key_exists($nombre, $parametros)) {

            return false;
        }

        $valor = $parametros[$nombre];

        if (is_bool($valor)) {

            return true;
        }

        return is_scalar($valor) && trim((string) $valor) !== '';
    }

    /**
     * @param  array<string, array<string, mixed>>  $catalogo
     * @return array<string, int>
     */
    protected static function contar_por_metodo(array $catalogo): array
    {
        $por_metodo = array_fill_keys(self::METODOS, 0);

        foreach ($catalogo as $fila) {

            $por_metodo[$fila['metodo']]++;
        }

        return $por_metodo;
    }
}
