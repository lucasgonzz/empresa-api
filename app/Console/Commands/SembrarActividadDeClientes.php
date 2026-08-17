<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan tracking:sembrar-actividad-de-prueba` — datos de prueba de la actividad de los
 * compradores en la tienda (misión actividad-de-clientes-y-oferta-por-whatsapp, unidad 2).
 *
 * -------------------------------------------------------------------------------------------
 * POR QUÉ EXISTE
 * -------------------------------------------------------------------------------------------
 *
 * `buyer_tracking_events` y `buyer_tracking_daily` están hoy en CERO FILAS en todos los entornos
 * (medido sobre empresa_testing_s2), porque las escribe la tienda y tienda todavía no se desplegó.
 * Sin datos, "verificar" la pantalla de actividad es mirar un cartel de vacío y declararlo andando.
 * Y sin datos VIEJOS no se puede ver funcionar la regla de corte por antigüedad, que es la decisión
 * más delicada de la misión: con `periodo` 7/30/90 se lee de los eventos crudos y con `periodo = 0`
 * del agregado diario, y las dos ramas tienen que dar resultados DISTINTOS para que la regla se note.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 ES UN COMMAND Y NO UN SEEDER, A PROPÓSITO
 * -------------------------------------------------------------------------------------------
 *
 * No se registra en `DatabaseSeeder` ni se agenda en el `$schedule` del Kernel. Un Seeder se
 * arrastra solo con un `db:seed` que alguien corre por otra cosa, y este comando BORRA filas de
 * `buyer_tracking_daily` (ver limpiar()). Que haya que escribir su nombre completo para que corra
 * es la mitad de su seguridad; la otra mitad es la guarda de entorno.
 *
 * -------------------------------------------------------------------------------------------
 * QUÉ SIEMBRA: UNA ESCENA CHICA Y VERIFICABLE, MÁS UN FONDO QUE LE DA FORMA REAL A LAS TABLAS
 * -------------------------------------------------------------------------------------------
 *
 * LA ESCENA (50 eventos, todos a mano y sin una sola decisión al azar) es lo que se mira en la
 * pantalla y lo que la planilla de control declara fila por fila:
 *
 *   - Un comprador atado a un cliente del ERP con actividad completa: 12 vistas sobre 4 artículos
 *     en 6 días, con `dwell_ms` de 3 a 95 segundos (para que se vean segundos Y minutos), 4
 *     búsquedas —una de ellas repetida y con `results_count = 0`, que es el dato más accionable que
 *     hay: hay demanda y no hay oferta—, dos agregados al carrito de los cuales UNO queda sin compra
 *     posterior (carrito abandonado), un quitado del carrito, un checkout empezado y una compra
 *     cerrada con `order_id` y `amount`.
 *   - Un segundo comprador atado a OTRO cliente, con un puñado más chico. Está para que se vea que
 *     la actividad de un cliente no se mezcla con la del otro.
 *   - Un comprador SIN cliente asociado (`comercio_city_client_id` NULL). Es lo que hace visible la
 *     diferencia entre las dos puertas de la pantalla: desde Tienda Online → Clientes se ve, y desde
 *     Clientes del ERP no aparece, porque no hay `client_id` que lo alcance.
 *   - Visitantes ANÓNIMOS (`buyer_id` NULL) sobre el mismo artículo que más miró el cliente
 *     conocido, con varios `visitor_id` distintos: el conteo de anónimos tiene que salir aparte y no
 *     puede contaminar la lista de clientes nombrados.
 *   - Eventos de HOY y de AYER. Los de hoy, para que se vea que los periodos cortos incluyen el día
 *     en curso; los de ayer, para que el agregado tenga un último día y la leyenda "hasta ayer" del
 *     histórico signifique algo verificable en pantalla.
 *
 * EL BLOQUE VIEJO son filas escritas DIRECTO en `buyer_tracking_daily`, con fecha anterior a los
 * DIAS_RECIENTES de retención y SIN ningún evento crudo que las respalde, sobre artículos y un
 * término que no aparecen en el bloque reciente. Es exactamente el estado en que la purga deja la
 * base en producción, y es lo que hace VERIFICABLE la regla de corte: con `periodo` 30 o 90 esos
 * artículos no aparecen, y con `periodo = 0` sí. Si aparecen en los dos, la regla no está
 * implementada.
 *
 * -------------------------------------------------------------------------------------------
 * 🔴 EL FONDO NO ES RELLENO: LA FORMA DE LA SIEMBRA DECIDE EL PLAN DE EJECUCIÓN
 * -------------------------------------------------------------------------------------------
 *
 * Medido por la unidad 1 de esta misma misión: sembrando SÓLO los 3 compradores reales de la base,
 * `buyer_tracking_daily` queda con 3 valores distintos de `buyer_id`, así que un
 * `WHERE buyer_id IN (1,2)` matchea el ~40% de la tabla y MySQL elige un full scan — Y TIENE RAZÓN.
 * Un `EXPLAIN` con `type: ALL` sobre esa siembra no dice nada del código: dice que la tabla no tiene
 * la forma de una tabla real. Re-sembrado con ~300 compradores distintos vuelve a
 * `btd_buyer_fecha_index` / `range`.
 *
 * Por eso el comando siembra también un FONDO: los otros compradores de la tienda, que es la forma
 * REAL de estas dos tablas (una fila por día × comprador × artículo × tipo × término, de TODO el
 * comercio). El fondo no toca los artículos de la escena, así que ningún número de la planilla de
 * control se mueve por su culpa.
 *
 * 🔴 Los compradores del fondo NO tienen fila en `buyers` (sus ids arrancan en
 * PRIMER_BUYER_DE_FONDO, muy por encima de cualquier id real). No es un descuido: el lector resuelve
 * primero los `buyer_id` contra `buyers` filtrando por `user_id`, así que nunca los alcanza — están
 * ahí para darle CARDINALIDAD a la tabla y nada más. Crear 300 compradores de mentira en `buyers`
 * ensuciaría la pantalla de Tienda Online → Clientes, que es una de las dos que hay que verificar a
 * mano. Y no es un estado imposible: un comprador borrado de `buyers` deja exactamente estas filas
 * huérfanas en el agregado, que no se purga nunca.
 *
 * 🔴 CORRÉ `ANALYZE TABLE` ANTES DE MEDIR. Lo hace el propio comando al final (ver analizar_tablas()):
 * sin estadísticas frescas, el optimizador decide sobre la tabla que había antes de sembrar y el
 * `EXPLAIN` vuelve a no significar nada.
 *
 * -------------------------------------------------------------------------------------------
 * EL AGREGADO RECIENTE LO ESCRIBE EL ROLLUP, NO ESTE COMANDO
 * -------------------------------------------------------------------------------------------
 *
 * Después de insertar los eventos, se corre `tracking:agregar-buyers --fecha=<día>` para cada día
 * con eventos sembrados EXCEPTO HOY. Dos motivos:
 *
 *   1. Es el camino de producción, y el rollup es idempotente por construcción (borra el día y lo
 *      reinserta), así que es seguro. Escribir el agregado reciente a mano sería sembrar una
 *      respuesta distinta de la que el sistema produce.
 *   2. Dejar HOY sin agregar es fiel a la realidad: el rollup corre a las 03:30 sobre el día
 *      anterior. Es lo que hace que "el histórico llega hasta ayer" se pueda VER en pantalla.
 *
 * 🔴 Y de ahí sale el orden del handle(), que no es libre: el bloque viejo se escribe DESPUÉS de los
 * rollups. El rollup BORRA el día completo del comercio antes de reinsertarlo, así que cualquier
 * fila del agregado escrita a mano sobre un día que después se agrega desaparece sin dejar rastro.
 *
 * -------------------------------------------------------------------------------------------
 * ESTO NO ES UNA PRUEBA DE CARGA
 * -------------------------------------------------------------------------------------------
 *
 * El objetivo es que la verificación sea creíble y que cada regla de la misión se pueda VER, más la
 * cardinalidad mínima para que un `EXPLAIN` signifique algo. Medir performance con volumen real es
 * otra herramienta y otra misión.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class SembrarActividadDeClientes extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'tracking:sembrar-actividad-de-prueba '
        .'{--dias=120 : Cuántos días hacia atrás sembrar. Tiene que pasar de los días de retención para que se pueda ver la regla de corte.} '
        .'{--fondo=300 : Cuántos compradores de fondo. Es lo que le da a las tablas su cardinalidad real; con 0 el EXPLAIN no significa nada.} '
        .'{--limpiar : Borra lo que dejó una corrida anterior y sale sin sembrar nada.}';

    /**
     * Descripción del comando para php artisan list.
     *
     * @var string
     */
    protected $description = 'Siembra actividad de prueba de los compradores en la tienda (eventos crudos, agregado '
        .'reciente por el rollup real y un tramo viejo que sólo vive en el agregado). Solo corre en local, testing o demo.';

    /**
     * Prefijo de TODOS los `visitor_id` y `session_id` que siembra este comando. Es la marca de
     * "esto es de prueba": limpiar() borra por `visitor_id LIKE 'prefijo%'`, que entra por
     * `bte_visitante_index` (un LIKE con comodín sólo al final usa el índice), y no puede llevarse
     * por delante un evento real de la tienda ni por accidente.
     *
     * Son 24 caracteres; los 12 que faltan para el char(36) los completa el número del visitante.
     *
     * @var string
     */
    const PREFIJO_VISITANTE = 'ffffffff-0000-4000-8000-';

    /**
     * Semilla del generador, fijada ANTES del primer reparto para que dos corridas den exactamente
     * los mismos datos y un informe se pueda comparar con otro (molde: SembrarDatosDePrueba:164).
     * La escena no usa azar en absoluto; el fondo sí.
     *
     * @var int
     */
    const SEMILLA_ALEATORIA = 20260817;

    /**
     * Hasta dónde llegan los eventos crudos. 🔴 No es un 90 escrito acá: apunta a la constante de la
     * purga, que es quien decide de verdad qué sobrevive. Si alguien baja la retención, el bloque
     * viejo de esta siembra se corre solo y sigue cayendo del lado que no tiene crudos.
     *
     * @var int
     */
    const DIAS_RECIENTES = PurgarBuyerTracking::DIAS_RETENCION;

    /**
     * Margen mínimo entre la retención y el `--dias` pedido. Sin margen, los tres días del bloque
     * viejo caerían todos en la misma fecha y el tramo "sólo en el agregado" dejaría de parecerse a
     * un histórico.
     *
     * @var int
     */
    const MARGEN_DEL_BLOQUE_VIEJO = 10;

    /**
     * Tope de --dias. Diez años, igual que PurgarBuyerTracking::MAX_DIAS y por el mismo motivo: más
     * atrás la aritmética de fechas empieza a devolver cosas raras en vez de fallar.
     *
     * @var int
     */
    const MAX_DIAS = 3650;

    /**
     * Tope de --fondo. 2000 compradores × EVENTOS_POR_COMPRADOR_DE_FONDO es medio millón de filas:
     * bastante más de lo que hace falta para que el optimizador tenga estadísticas y bastante menos
     * de lo que tarda en volverse un problema.
     *
     * @var int
     */
    const MAX_FONDO = 2000;

    /**
     * Primer id de comprador del fondo. Muy por encima de cualquier `buyers.id` real para que no se
     * pueda pisar con uno de verdad (ver el 🔴 del docblock de la clase).
     *
     * @var int
     */
    const PRIMER_BUYER_DE_FONDO = 900001;

    /**
     * Eventos que genera cada comprador del fondo.
     *
     * @var int
     */
    const EVENTOS_POR_COMPRADOR_DE_FONDO = 30;

    /**
     * En cuántos días se reparte el fondo. Acotado a propósito: cada día con eventos sembrados es
     * una corrida más del rollup, y lo que se busca es cardinalidad de compradores, no de fechas.
     *
     * @var int
     */
    const DIAS_DE_FONDO = 45;

    /**
     * Artículos que necesita la escena: 4 del comprador principal, 2 del segundo, 2 del comprador sin
     * cliente y 2 que sólo existen en el bloque viejo.
     *
     * @var int
     */
    const ARTICULOS_DE_LA_ESCENA = 10;

    /**
     * Filas por sentencia INSERT, mismo criterio que AgregarBuyerTracking::FILAS_POR_INSERT: una sola
     * sentencia gigante se choca contra max_allowed_packet y mantiene el lock de más.
     *
     * @var int
     */
    const FILAS_POR_INSERT = 500;

    /**
     * Monto de la compra cerrada del bloque reciente.
     *
     * @var string
     */
    const MONTO_DE_LA_COMPRA = '15400.50';

    /**
     * Monto de la compra que sólo vive en el agregado viejo.
     *
     * @var string
     */
    const MONTO_DE_LA_COMPRA_VIEJA = '8200.00';

    /**
     * Término que el comprador principal buscó y NO encontró, en el bloque reciente.
     *
     * @var string
     */
    const BUSQUEDA_SIN_RESULTADO = 'taladro percutor';

    /**
     * Término que sólo existe en el agregado viejo.
     *
     * @var string
     */
    const BUSQUEDA_VIEJA = 'motosierra usada';

    /**
     * Comercio dueño de la instancia.
     *
     * @var int
     */
    protected $user_id;

    /**
     * Momento de la corrida, congelado: si se recalculara `now()` en cada fila, dos eventos "de hoy"
     * podrían caer en días distintos si el comando arranca a las 23:59:59.
     *
     * @var string
     */
    protected $ahora;

    /**
     * Los eventos crudos que se van a insertar. Es la ÚNICA fuente de la planilla de control: se
     * cuenta lo que se le pidió sembrar, nunca lo que después devuelva la base (§7.3 del plan; el
     * molde y el porqué están en SembrarDatosDePrueba::escribir_planilla_de_control():779-783).
     * Consultar la base para armar la planilla convertiría la verificación en el sistema
     * chequeándose a sí mismo.
     *
     * @var array<int, array>
     */
    protected $eventos = [];

    /**
     * Conteo por etiqueta y tipo de evento, alimentado en el mismo lugar donde se arma cada evento.
     *
     * @var array<string, array<string, int>>
     */
    protected $control = [];

    /**
     * Avisos de lo que no se pudo sembrar (falta un comprador de tal forma, faltan artículos).
     *
     * @var array<int, string>
     */
    protected $avisos = [];

    /**
     * @return int Código de salida (0 = OK)
     */
    public function handle()
    {
        /*
         * 🔴 GUARDA DE ENTORNO, PRIMERA LÍNEA, con config('app.env') y NUNCA env('APP_ENV'): con
         * config:cache activo, env() fuera de config/ devuelve null y la guarda deja pasar. Molde
         * literal de SembrarDatosDePrueba:138-145. Este comando borra filas de `buyer_tracking_daily`
         * (ver limpiar()), que es la única memoria de largo plazo del comportamiento de la tienda:
         * que no pueda correr contra la base de un cliente no es opcional.
         */
        if (config('app.env') !== 'local' && config('app.env') !== 'testing' && config('app.FOR_USER') !== 'demo') {
            $this->error('tracking:sembrar-actividad-de-prueba solo corre en local, en testing o en la instancia demo.');
            return 1;
        }

        /*
         * Guarda barata de esquema, igual que sus dos comandos hermanos de tracking: hay instancias
         * con el código nuevo y todavía sin las tablas.
         */
        if (!Schema::hasTable('buyer_tracking_events') || !Schema::hasTable('buyer_tracking_daily')) {
            $this->info('tracking:sembrar-actividad-de-prueba — la base no tiene las tablas de tracking, no se siembra nada.');
            return 0;
        }

        $user = $this->resolver_comercio();

        if (!$user) {
            $this->info('tracking:sembrar-actividad-de-prueba — no hay comercio configurado (app.USER_ID), no se siembra nada.');
            return 0;
        }

        /*
         * Doble gate por extensión, igual que sus dos comandos hermanos. Acá además es FUNCIONAL y no
         * sólo comercial: sin `tracking_buyers`, `tracking:agregar-buyers` sale en su primera línea y
         * el agregado reciente quedaría a medias, con la mitad de la siembra mintiendo. Y a
         * diferencia de los hermanos —que salen con 0 porque los corre el cron todas las noches— acá
         * el código de salida es 1: a este comando lo corre una persona, y quedarse esperando datos
         * que nunca se escribieron es peor que un error.
         */
        if (!UserHelper::hasExtencion('tracking_buyers', $user)) {
            $this->error(
                'tracking:sembrar-actividad-de-prueba — el comercio no tiene la extensión tracking_buyers, no se siembra nada.'
                . ' Activala con: php artisan db:seed --class=Database\\Seeders\\ExtencionTrackingBuyersSeeder'
                . ' y atándosela al comercio ' . $user->id . ' en extencion_empresa_user.'
            );
            return 1;
        }

        $dias = $this->resolver_dias();

        if (is_null($dias)) {
            $this->error(
                'tracking:sembrar-actividad-de-prueba — --dias tiene que ser un entero entre '
                . (self::DIAS_RECIENTES + self::MARGEN_DEL_BLOQUE_VIEJO) . ' y ' . self::MAX_DIAS
                . ': tiene que pasar de los ' . self::DIAS_RECIENTES
                . ' días de retención para que el tramo viejo quede del lado que ya no tiene eventos crudos.'
            );
            return 1;
        }

        $fondo = $this->resolver_fondo();

        if (is_null($fondo)) {
            $this->error('tracking:sembrar-actividad-de-prueba — --fondo tiene que ser un entero entre 0 y ' . self::MAX_FONDO . '.');
            return 1;
        }

        $this->user_id = (int) $user->id;
        $this->ahora   = Carbon::now()->format('Y-m-d H:i:s');

        /*
         * 🔴 Los acumuladores se vacían acá y no en la declaración de la propiedad, porque Artisan
         * REUSA LA MISMA INSTANCIA del comando cuando se lo llama dos veces en el mismo proceso
         * (Illuminate\Console\Application cachea el comando resuelto). Sin este reinicio, la segunda
         * corrida vuelve a insertar todo lo de la primera y siembra el doble — con la planilla de
         * control declarando el doble también, así que las dos coincidirían y nada lo denunciaría.
         * Lo encontró el test de "correrlo dos veces deja la misma cantidad".
         */
        $this->eventos = [];
        $this->control = [];
        $this->avisos  = [];

        $borradas = $this->limpiar($dias);

        if ($this->option('limpiar')) {
            $this->info(
                'tracking:sembrar-actividad-de-prueba — se borraron ' . $borradas['eventos'] . ' eventos de prueba y '
                . $borradas['agregado'] . ' filas de agregado. No se sembró nada (--limpiar).'
            );
            return 0;
        }

        $protagonistas = $this->resolver_protagonistas();

        if (!$protagonistas['con_cliente_1']) {
            $this->error(
                'tracking:sembrar-actividad-de-prueba — el comercio no tiene ningún comprador atado a un cliente del ERP'
                . ' (buyers.comercio_city_client_id). Sin eso no hay a quién sembrarle actividad: primero tiene que haber compradores.'
            );
            return 1;
        }

        $articulos = $this->articulos_del_comercio();

        if (count($articulos) < self::ARTICULOS_DE_LA_ESCENA) {
            $this->error(
                'tracking:sembrar-actividad-de-prueba — hacen falta al menos ' . self::ARTICULOS_DE_LA_ESCENA
                . ' artículos del comercio para armar la escena, y hay ' . count($articulos) . '.'
            );
            return 1;
        }

        // ANTES del primer reparto: sin esto dos corridas dan datos distintos y comparar una con otra
        // es imposible.
        mt_srand(self::SEMILLA_ALEATORIA);

        $this->sembrar_escena($protagonistas, $articulos);
        $this->sembrar_fondo($articulos, $fondo);
        $insertados = $this->insertar_eventos();

        $this->info('Se insertaron ' . $insertados . ' eventos crudos. Corriendo el rollup real, día por día...');

        $rollup = $this->correr_el_rollup();

        /*
         * 🔴 DESPUÉS del rollup, no antes: el rollup borra el día completo del comercio antes de
         * reinsertarlo, así que una fila del agregado escrita a mano sobre un día que después se
         * agrega desaparece sin dejar rastro. Estos días no tienen eventos crudos (para eso están),
         * pero el orden se respeta igual: la dependencia es real y no se puede depender de que los
         * rangos nunca se toquen.
         */
        $viejo = $this->sembrar_bloque_viejo($protagonistas['con_cliente_1'], $articulos, $dias);

        $this->analizar_tablas();

        $this->escribir_planilla_de_control($dias, $fondo, $rollup, $viejo, $insertados, $borradas);

        return 0;
    }

    /**
     * Dueño de la instancia con sus extensiones cargadas (mismo criterio que sus dos comandos
     * hermanos de tracking).
     *
     * @return User|null
     */
    protected function resolver_comercio()
    {
        $user_id = config('app.USER_ID');

        if (empty($user_id)) {
            return null;
        }

        return User::with('extencions')->find($user_id);
    }

    /**
     * Días hacia atrás a sembrar. Un valor inválido CORTA el comando en vez de caer en silencio al
     * default: es el mismo criterio de --dias en `tracking:purgar-buyers` y de --fecha en
     * `tracking:agregar-buyers`, y por el mismo motivo (el que escribió mal la opción se queda
     * creyendo que sembró algo que no sembró).
     *
     * @return int|null
     */
    protected function resolver_dias()
    {
        $opcion = $this->option('dias');

        if (!ctype_digit((string) $opcion)) {
            return null;
        }

        $dias = (int) $opcion;

        if ($dias < self::DIAS_RECIENTES + self::MARGEN_DEL_BLOQUE_VIEJO || $dias > self::MAX_DIAS) {
            return null;
        }

        return $dias;
    }

    /**
     * Cantidad de compradores del fondo. 0 es válido y significa "sembrá sólo la escena": sirve para
     * los tests, donde lo que se mide son los casos y no la cardinalidad. 🔴 Para medir un EXPLAIN,
     * NO uses 0 (ver el 🔴 del docblock de la clase).
     *
     * @return int|null
     */
    protected function resolver_fondo()
    {
        $opcion = $this->option('fondo');

        if (!ctype_digit((string) $opcion)) {
            return null;
        }

        $fondo = (int) $opcion;

        if ($fondo > self::MAX_FONDO) {
            return null;
        }

        return $fondo;
    }

    /**
     * Borra lo que dejó una corrida anterior.
     *
     * 🔴 Corre SIEMPRE, también sin --limpiar, y eso es lo que hace que el comando sea idempotente:
     * correrlo dos veces deja exactamente lo mismo y no el doble. Es la misma propiedad, tomada por
     * el mismo camino, que la de `tracking:agregar-buyers` (borrar y reinsertar), y por el mismo
     * motivo: a un sembrador se lo corre de nuevo apenas algo sale raro, y una siembra que se duplica
     * en silencio deja números que ya nadie puede distinguir de los buenos.
     *
     * Los EVENTOS se borran por la marca: `visitor_id LIKE 'prefijo%'`, que entra por
     * `bte_visitante_index` (un LIKE con comodín sólo al final usa el índice) y no puede llevarse por
     * delante un evento real de la tienda ni por accidente.
     *
     * 🔴 EL AGREGADO NO TIENE COLUMNA DE MARCA (su esquema es user_id, fecha, buyer_id, article_id,
     * event_type, search_term, ...), así que se borra POR RANGO DE FECHAS: se lleva puesto todo el
     * agregado del rango sembrado, sea de prueba o no. Eso se avisa fuerte antes de borrar y es una
     * de las razones por las que este comando sólo corre en local, testing o demo. En la base de un
     * cliente esto no puede pasar nunca: `buyer_tracking_daily` es la única memoria de largo plazo
     * del comportamiento de la tienda y lo que se borra ahí no se reconstruye con nada.
     *
     * @param  int $dias
     * @return array{'eventos': int, 'agregado': int}
     */
    protected function limpiar($dias)
    {
        $desde = Carbon::now()->startOfDay()->subDays($dias)->format('Y-m-d');
        $hasta = Carbon::now()->format('Y-m-d');

        $this->warn(
            'Se va a borrar el agregado (buyer_tracking_daily) del comercio ' . $this->user_id . ' entre ' . $desde
            . ' y ' . $hasta . ', SEA DE PRUEBA O NO: esa tabla no tiene columna de marca.'
            . ' Si una corrida anterior usó un --dias más grande, lo de más atrás queda sin borrar.'
        );

        $eventos = DB::table('buyer_tracking_events')
            ->where('user_id', $this->user_id)
            ->where('visitor_id', 'LIKE', self::PREFIJO_VISITANTE . '%')
            ->delete();

        $agregado = DB::table('buyer_tracking_daily')
            ->where('user_id', $this->user_id)
            ->whereBetween('fecha', [$desde, $hasta])
            ->delete();

        return ['eventos' => $eventos, 'agregado' => $agregado];
    }

    /**
     * Los tres compradores de la escena, sacados de los que YA existen en la base del comercio.
     *
     * Cada rol contesta una pregunta distinta de la pantalla, y por eso se buscan por su forma y no
     * por su id: el primero es el que tiene actividad completa, el segundo está para que se vea que
     * la actividad de un cliente no se mezcla con la del otro, y el tercero —sin
     * `comercio_city_client_id`— es el que hace visible la diferencia entre las dos puertas.
     *
     * Los que falten se avisan y su bloque no se siembra: es preferible una escena incompleta y
     * declarada a una que inventa compradores para completarse.
     *
     * @return array<string, object|null>
     */
    protected function resolver_protagonistas()
    {
        $con_cliente = DB::table('buyers')
            ->where('user_id', $this->user_id)
            ->whereNotNull('comercio_city_client_id')
            ->orderBy('id')
            ->get(['id', 'name', 'comercio_city_client_id']);

        $sin_cliente = DB::table('buyers')
            ->where('user_id', $this->user_id)
            ->whereNull('comercio_city_client_id')
            ->orderBy('id')
            ->first(['id', 'name', 'comercio_city_client_id']);

        $primero = $con_cliente->first();
        $segundo = null;

        foreach ($con_cliente as $candidato) {
            // El segundo tiene que ser de OTRO cliente: dos compradores del mismo cliente vienen
            // sumados en la misma pantalla y no demostrarían nada.
            if ($primero && (int) $candidato->comercio_city_client_id !== (int) $primero->comercio_city_client_id) {
                $segundo = $candidato;
                break;
            }
        }

        if (!$segundo) {
            $this->avisos[] = 'No hay un segundo comprador atado a OTRO cliente: no se puede ver que la actividad de dos clientes no se mezcle.';
        }

        if (!$sin_cliente) {
            $this->avisos[] = 'No hay ningún comprador SIN cliente asociado: no se puede ver la diferencia entre las dos puertas de la pantalla.';
        }

        return [
            'con_cliente_1' => $primero,
            'con_cliente_2' => $segundo,
            'sin_cliente'   => $sin_cliente,
        ];
    }

    /**
     * Ids de los artículos del comercio, ordenados. Los primeros ARTICULOS_DE_LA_ESCENA son la
     * escena; el resto es para el fondo.
     *
     * @return array<int, int>
     */
    protected function articulos_del_comercio()
    {
        $ids = [];

        $filas = DB::table('articles')
            ->where('user_id', $this->user_id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach ($filas as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    // ------------------------------------------------------------------------------------------
    // La escena
    // ------------------------------------------------------------------------------------------

    /**
     * Arma los 50 eventos de la escena. Ni una sola decisión al azar acá: cada evento está escrito,
     * y por eso la planilla de control puede declarar los números exactos que la pantalla tiene que
     * mostrar.
     *
     * @param  array $protagonistas
     * @param  array $articulos
     * @return void
     */
    protected function sembrar_escena(array $protagonistas, array $articulos)
    {
        $this->sembrar_comprador_principal($protagonistas['con_cliente_1'], $articulos);

        if ($protagonistas['con_cliente_2']) {
            $this->sembrar_segundo_comprador($protagonistas['con_cliente_2'], $articulos);
        }

        if ($protagonistas['sin_cliente']) {
            $this->sembrar_comprador_sin_cliente($protagonistas['sin_cliente'], $articulos);
        }

        $this->sembrar_anonimos($articulos);
    }

    /**
     * El comprador con actividad completa: 12 vistas sobre 4 artículos en 6 días, 4 búsquedas (una
     * repetida y sin resultados), 2 agregados al carrito —uno de ellos abandonado—, un quitado del
     * carrito, un checkout empezado y una compra cerrada.
     *
     * 🔴 Los dos eventos del último bloque son de HOY, y los `dwell_ms` van de 3.000 a 95.000 a
     * propósito: la pantalla tiene que mostrar tanto "3 segundos" como "1 minuto y medio", que son
     * dos formatos distintos y los dos se rompen distinto.
     *
     * @param  object $buyer
     * @param  array  $articulos
     * @return void
     */
    protected function sembrar_comprador_principal($buyer, array $articulos)
    {
        $etiqueta = 'Comprador #' . $buyer->id . ' (cliente #' . $buyer->comercio_city_client_id . ')';
        $id       = (int) $buyer->id;
        $visitor  = 1;

        list($a1, $a2, $a3, $a4) = [$articulos[0], $articulos[1], $articulos[2], $articulos[3]];

        // Hace 25 días
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a1, 'dwell_ms' => 95000, 'occurred_at' => $this->momento(25, 10, 15)], $visitor, 25);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a2, 'dwell_ms' => 30000, 'occurred_at' => $this->momento(25, 10, 22)], $visitor, 25);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a3, 'dwell_ms' => 5000, 'occurred_at' => $this->momento(25, 10, 26)], $visitor, 25);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'search', 'search_term' => self::BUSQUEDA_SIN_RESULTADO, 'results_count' => 0, 'occurred_at' => $this->momento(25, 10, 30)], $visitor, 25);

        // Hace 18 días
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'search', 'search_term' => 'cesto de basura', 'results_count' => 7, 'occurred_at' => $this->momento(18, 19, 0)], $visitor, 18);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a1, 'dwell_ms' => 60000, 'occurred_at' => $this->momento(18, 19, 5)], $visitor, 18);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a4, 'dwell_ms' => 20000, 'occurred_at' => $this->momento(18, 19, 12)], $visitor, 18);

        // Hace 12 días — la segunda vez que busca lo mismo y no encuentra nada
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'search', 'search_term' => self::BUSQUEDA_SIN_RESULTADO, 'results_count' => 0, 'occurred_at' => $this->momento(12, 8, 35)], $visitor, 12);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a2, 'dwell_ms' => 15000, 'occurred_at' => $this->momento(12, 8, 40)], $visitor, 12);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a4, 'dwell_ms' => 9000, 'occurred_at' => $this->momento(12, 8, 47)], $visitor, 12);

        // Hace 6 días — el carrito que queda ABANDONADO (cart_add sin compra posterior)
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'search', 'search_term' => 'escobillon', 'results_count' => 3, 'occurred_at' => $this->momento(6, 21, 5)], $visitor, 6);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a1, 'dwell_ms' => 45000, 'occurred_at' => $this->momento(6, 21, 10)], $visitor, 6);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a2, 'dwell_ms' => 7000, 'occurred_at' => $this->momento(6, 21, 18)], $visitor, 6);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'cart_add', 'article_id' => $a2, 'quantity' => 1, 'occurred_at' => $this->momento(6, 21, 20)], $visitor, 6);

        /*
         * Hace 2 días — el carrito que SÍ termina en compra. El `checkout_complete` lleva article_id
         * para que se pueda distinguir del carrito abandonado; la migración lo permite en null y la
         * tienda a veces lo manda así, pero acá interesa que los dos casos se distingan en pantalla.
         */
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a1, 'dwell_ms' => 12000, 'occurred_at' => $this->momento(2, 17, 30)], $visitor, 2);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a3, 'dwell_ms' => 3000, 'occurred_at' => $this->momento(2, 17, 35)], $visitor, 2);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'cart_add', 'article_id' => $a1, 'quantity' => 2, 'occurred_at' => $this->momento(2, 17, 40)], $visitor, 2);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'checkout_start', 'occurred_at' => $this->momento(2, 17, 42)], $visitor, 2);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'checkout_complete', 'article_id' => $a1, 'amount' => self::MONTO_DE_LA_COMPRA, 'order_id' => 900001, 'occurred_at' => $this->momento(2, 17, 45)], $visitor, 2);

        // HOY — los periodos cortos tienen que incluir el día en curso, y el histórico (que llega
        // hasta ayer) no.
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $a1, 'dwell_ms' => 8000, 'occurred_at' => $this->momento_de_hoy(90)], $visitor, 0);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'cart_remove', 'article_id' => $a3, 'quantity' => 1, 'occurred_at' => $this->momento_de_hoy(45)], $visitor, 0);
    }

    /**
     * El segundo cliente, con un puñado más chico y sobre OTROS artículos. Sirve para ver que la
     * actividad de un cliente no se mezcla con la del otro; si compartieran artículos, un error de
     * agrupación se vería igual de bien y no habría cómo distinguirlo.
     *
     * @param  object $buyer
     * @param  array  $articulos
     * @return void
     */
    protected function sembrar_segundo_comprador($buyer, array $articulos)
    {
        $etiqueta = 'Comprador #' . $buyer->id . ' (cliente #' . $buyer->comercio_city_client_id . ')';
        $id       = (int) $buyer->id;
        $visitor  = 2;

        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[4], 'dwell_ms' => 11000, 'occurred_at' => $this->momento(20, 12, 0)], $visitor, 20);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'search', 'search_term' => 'llave francesa', 'results_count' => 0, 'occurred_at' => $this->momento(9, 15, 25)], $visitor, 9);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[4], 'dwell_ms' => 4000, 'occurred_at' => $this->momento(9, 15, 30)], $visitor, 9);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[5], 'dwell_ms' => 26000, 'occurred_at' => $this->momento(3, 20, 10)], $visitor, 3);
    }

    /**
     * El comprador SIN cliente asociado. 🔴 Es el que hace visible la diferencia entre las dos
     * puertas: desde Tienda Online → Clientes se ve, y desde Clientes del ERP no aparece porque no
     * hay `client_id` que lo alcance.
     *
     * Su último evento es de AYER, y no es casual: es lo que le da al agregado un último día, para
     * que "el histórico llega hasta ayer" se pueda ver en pantalla en vez de tener que creerlo.
     *
     * @param  object $buyer
     * @param  array  $articulos
     * @return void
     */
    protected function sembrar_comprador_sin_cliente($buyer, array $articulos)
    {
        $etiqueta = 'Comprador #' . $buyer->id . ' (SIN cliente)';
        $id       = (int) $buyer->id;
        $visitor  = 3;

        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[6], 'dwell_ms' => 18000, 'occurred_at' => $this->momento(15, 11, 0)], $visitor, 15);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[6], 'dwell_ms' => 6000, 'occurred_at' => $this->momento(8, 9, 20)], $visitor, 8);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[7], 'dwell_ms' => 33000, 'occurred_at' => $this->momento(4, 22, 5)], $visitor, 4);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'product_view', 'article_id' => $articulos[6], 'dwell_ms' => 2000, 'occurred_at' => $this->momento(1, 16, 40)], $visitor, 1);
        $this->evento($etiqueta, ['buyer_id' => $id, 'event_type' => 'cart_add', 'article_id' => $articulos[6], 'quantity' => 1, 'occurred_at' => $this->momento(1, 16, 45)], $visitor, 1);
    }

    /**
     * 20 vistas anónimas (`buyer_id` NULL) con 6 `visitor_id` distintos, sobre el MISMO artículo que
     * más miró el comprador principal.
     *
     * Que sea el mismo artículo es el punto: la pantalla tiene que poder contestar "quién anduvo
     * mirando esto" separando los clientes que se pueden nombrar de los visitantes que no, y si los
     * anónimos estuvieran sobre un artículo aparte, mezclarlos no se notaría. Y son 20 eventos con 6
     * visitantes porque "eventos" y "personas distintas" son dos números diferentes: 20 vistas pueden
     * ser 20 personas o una sola que recargó 20 veces.
     *
     * @param  array $articulos
     * @return void
     */
    protected function sembrar_anonimos(array $articulos)
    {
        $etiqueta = 'Visitantes anónimos (buyer_id NULL)';
        $dias     = [22, 19, 16, 13, 11, 7, 5, 3];

        for ($i = 0; $i < 20; $i++) {
            $visitor     = 11 + ($i % 6);
            $dias_atras  = $dias[$i % count($dias)];

            $this->evento($etiqueta, [
                'buyer_id'    => null,
                'event_type'  => 'product_view',
                'article_id'  => $articulos[0],
                'dwell_ms'    => 2000 + ($i * 1500),
                'occurred_at' => $this->momento($dias_atras, 8 + ($i % 12), ($i * 7) % 60),
            ], $visitor, $dias_atras);
        }
    }

    // ------------------------------------------------------------------------------------------
    // El fondo
    // ------------------------------------------------------------------------------------------

    /**
     * El resto de los compradores de la tienda: lo que le da a las dos tablas su forma real (ver el
     * 🔴 del docblock de la clase). No toca los artículos de la escena, así que ningún número de la
     * planilla de control se mueve por su culpa.
     *
     * Reparte tipos de evento con una proporción parecida a la de una tienda —el grueso son vistas,
     * después carrito, después búsquedas y muy pocas compras cerradas— porque el agregado agrupa POR
     * TIPO: un fondo de puras vistas daría una tabla con una sola clase de fila y estadísticas que no
     * se parecen a las reales.
     *
     * @param  array $articulos
     * @param  int   $compradores
     * @return void
     */
    protected function sembrar_fondo(array $articulos, $compradores)
    {
        if ($compradores <= 0) {
            $this->avisos[] = 'Se corrió con --fondo=0: las tablas quedan sin cardinalidad y un EXPLAIN sobre ellas no significa nada.';
            return;
        }

        $etiqueta  = 'Fondo (' . $compradores . ' compradores de la tienda)';
        $del_fondo = array_slice($articulos, self::ARTICULOS_DE_LA_ESCENA);

        if (empty($del_fondo)) {
            $del_fondo = $articulos;
            $this->avisos[] = 'El comercio no tiene artículos de sobra: el fondo cae sobre los mismos artículos de la escena y los conteos de la planilla no van a coincidir con la pantalla.';
        }

        $terminos = ['motosierra', 'filtro de aire', 'bomba de agua', 'cadena 3/8', 'aceite 2 tiempos', 'guantes de trabajo'];

        for ($n = 0; $n < $compradores; $n++) {
            $buyer_id = self::PRIMER_BUYER_DE_FONDO + $n;
            $visitor  = 1001 + $n;

            for ($e = 0; $e < self::EVENTOS_POR_COMPRADOR_DE_FONDO; $e++) {
                // El fondo arranca en 1 y no en 0: HOY queda reservado para la escena, así se puede
                // verificar exactamente qué eventos tiene el día en curso.
                $dias_atras = 1 + mt_rand(0, self::DIAS_DE_FONDO - 1);
                $articulo   = $del_fondo[mt_rand(0, count($del_fondo) - 1)];
                $dado       = mt_rand(1, 100);

                $datos = [
                    'buyer_id'    => $buyer_id,
                    'event_type'  => 'product_view',
                    'article_id'  => $articulo,
                    'dwell_ms'    => mt_rand(1200, 70000),
                    'occurred_at' => $this->momento($dias_atras, mt_rand(0, 23), mt_rand(0, 59)),
                ];

                if ($dado > 95) {
                    $datos['event_type'] = 'checkout_complete';
                    $datos['dwell_ms']   = null;
                    $datos['amount']     = (string) mt_rand(1500, 90000) . '.00';
                    $datos['order_id']   = 950000 + ($n * 100) + $e;
                } elseif ($dado > 85) {
                    $termino = $terminos[mt_rand(0, count($terminos) - 1)];

                    $datos['event_type']    = 'search';
                    $datos['article_id']    = null;
                    $datos['dwell_ms']      = null;
                    $datos['search_term']   = $termino;
                    // Uno de cada cinco no encuentra nada, igual que en la escena: el 0 tiene que
                    // existir también en el fondo o el agregado quedaría sin un solo caso accionable.
                    $datos['results_count'] = (mt_rand(1, 5) === 1) ? 0 : mt_rand(1, 40);
                } elseif ($dado > 70) {
                    $datos['event_type'] = 'cart_add';
                    $datos['dwell_ms']   = null;
                    $datos['quantity']   = mt_rand(1, 4);
                }

                $this->evento($etiqueta, $datos, $visitor, $dias_atras);
            }
        }
    }

    // ------------------------------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------------------------------

    /**
     * Arma un evento con los defaults de la tabla y lo apila, contándolo de paso en la planilla.
     *
     * La marca de "esto es de prueba" (PREFIJO_VISITANTE) la pone acá y en un solo lugar: si algún
     * evento se escapara sin ella, `--limpiar` lo dejaría en la base para siempre.
     *
     * @param  string $etiqueta    Quién lo hizo, para la planilla de control
     * @param  array  $datos       Lo que distingue a este evento de los defaults
     * @param  int    $visitante   Número de visitante (se convierte en el visitor_id marcado)
     * @param  int    $dias_atras  Se usa para que la sesión sea una por visitante y por día
     * @return void
     */
    protected function evento($etiqueta, array $datos, $visitante, $dias_atras)
    {
        $tipo = isset($datos['event_type']) ? $datos['event_type'] : 'product_view';

        if (!isset($this->control[$etiqueta])) {
            $this->control[$etiqueta] = [];
        }

        if (!isset($this->control[$etiqueta][$tipo])) {
            $this->control[$etiqueta][$tipo] = 0;
        }

        $this->control[$etiqueta][$tipo]++;

        $this->eventos[] = array_merge([
            'user_id'         => $this->user_id,
            'buyer_id'        => null,
            'visitor_id'      => $this->marca($visitante),
            /*
             * Una sesión por visitante y por día: es lo que hace la tienda (la sesión se renueva a
             * los 30 minutos de inactividad), y con una sola sesión para todo no se podría distinguir
             * "volvió tres días seguidos" de "estuvo tres horas de corrido".
             */
            'session_id'      => $this->marca(500000 + ($visitante * 1000) + $dias_atras),
            'event_type'      => 'product_view',
            'article_id'      => null,
            'category_id'     => null,
            'sub_category_id' => null,
            'search_term'     => null,
            'results_count'   => null,
            'quantity'        => null,
            'amount'          => null,
            'dwell_ms'        => null,
            'order_id'        => null,
            'occurred_at'     => $this->ahora,
            'created_at'      => $this->ahora,
            'updated_at'      => $this->ahora,
        ], $datos);
    }

    /**
     * Inserta los eventos apilados, de a FILAS_POR_INSERT.
     *
     * @return int Cantidad de filas insertadas
     */
    protected function insertar_eventos()
    {
        foreach (array_chunk($this->eventos, self::FILAS_POR_INSERT) as $lote) {
            DB::table('buyer_tracking_events')->insert($lote);
        }

        return count($this->eventos);
    }

    /**
     * Corre el rollup real sobre cada día con eventos sembrados, EXCEPTO HOY.
     *
     * Los dos motivos están en el docblock de la clase: es el camino de producción (y el rollup es
     * idempotente, así que es seguro), y dejar hoy sin agregar es fiel a que el cron corre a las
     * 03:30 sobre el día anterior.
     *
     * Se usa Artisan::call() y no $this->call() para que las decenas de líneas de salida del rollup
     * queden en su buffer en vez de tapar la planilla de control.
     *
     * @return array{'dias': int, 'fallados': array<int, string>}
     */
    protected function correr_el_rollup()
    {
        $hoy  = Carbon::now()->format('Y-m-d');
        $dias = [];

        foreach ($this->eventos as $evento) {
            $dia = substr((string) $evento['occurred_at'], 0, 10);

            if ($dia !== $hoy) {
                $dias[$dia] = $dia;
            }
        }

        sort($dias);

        $fallados = [];

        foreach ($dias as $dia) {
            if (Artisan::call('tracking:agregar-buyers', ['--fecha' => $dia]) !== 0) {
                $fallados[] = $dia;
            }
        }

        return ['dias' => count($dias), 'fallados' => $fallados];
    }

    /**
     * El bloque VIEJO: filas escritas directo en `buyer_tracking_daily`, con fecha anterior a los
     * DIAS_RECIENTES de retención y sin ningún evento crudo detrás.
     *
     * 🔴 ES LO QUE HACE VERIFICABLE LA REGLA DE CORTE. Van sobre dos artículos y un término que NO
     * aparecen en el bloque reciente, así que con `periodo` 30 o 90 no tienen que aparecer y con
     * `periodo = 0` sí. Si aparecen en los dos, la regla no está implementada; si no aparecen en
     * ninguno, tampoco.
     *
     * Escribirlas a mano no es hacer trampa: es reproducir exactamente el estado en que la purga
     * deja la base en producción, donde el agregado sobrevive y los crudos de esa antigüedad ya no
     * existen. No hay otra forma de llegar a ese estado sin esperar 91 días.
     *
     * @param  object $buyer
     * @param  array  $articulos
     * @param  int    $dias
     * @return array<int, array> Las filas escritas
     */
    protected function sembrar_bloque_viejo($buyer, array $articulos, $dias)
    {
        $margen = $dias - self::DIAS_RECIENTES;

        /*
         * Tres días repartidos adentro del tramo que ya no tiene crudos, calculados sobre el --dias
         * pedido para que el bloque entre siempre en el rango que limpiar() sabe borrar.
         * Ojo con los nombres: son DÍAS HACIA ATRÁS, así que a MÁS días, MÁS viejo.
         */
        $mas_reciente = self::DIAS_RECIENTES + (int) floor($margen * 0.15);
        $del_medio    = self::DIAS_RECIENTES + (int) floor($margen * 0.50);
        $mas_viejo    = self::DIAS_RECIENTES + (int) floor($margen * 0.85);

        $a9  = $articulos[8];
        $a10 = $articulos[9];

        $filas = [
            $this->fila_del_agregado($buyer->id, $mas_reciente, $a9, 'product_view', null, null, 4, 40000, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_reciente, $a10, 'product_view', null, null, 5, 60000, '0.00'),
            $this->fila_del_agregado($buyer->id, $del_medio, $a9, 'product_view', null, null, 3, 30000, '0.00'),
            $this->fila_del_agregado($buyer->id, $del_medio, null, 'search', self::BUSQUEDA_VIEJA, 0, 2, 0, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_viejo, $a9, 'product_view', null, null, 2, 25000, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_viejo, $a10, 'product_view', null, null, 3, 20000, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_viejo, $a10, 'cart_add', null, null, 1, 0, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_viejo, null, 'search', self::BUSQUEDA_VIEJA, 0, 1, 0, '0.00'),
            $this->fila_del_agregado($buyer->id, $mas_viejo, null, 'checkout_complete', null, null, 1, 0, self::MONTO_DE_LA_COMPRA_VIEJA),
        ];

        DB::table('buyer_tracking_daily')->insert($filas);

        return $filas;
    }

    /**
     * Una fila del agregado diario, con las mismas columnas que escribe `tracking:agregar-buyers`.
     *
     * `results_count` conserva el NULL a propósito: null = no aplica, 0 = buscó y no encontró nada.
     * Es el mismo cuidado que tiene el rollup al insertar (AgregarBuyerTracking::insertar():333-334).
     *
     * @param  int         $buyer_id
     * @param  int         $dias_atras
     * @param  int|null    $article_id
     * @param  string      $event_type
     * @param  string|null $search_term
     * @param  int|null    $results_count
     * @param  int         $total
     * @param  int         $dwell_ms_total
     * @param  string      $amount_total
     * @return array
     */
    protected function fila_del_agregado($buyer_id, $dias_atras, $article_id, $event_type, $search_term, $results_count, $total, $dwell_ms_total, $amount_total)
    {
        return [
            'user_id'        => $this->user_id,
            'fecha'          => Carbon::now()->startOfDay()->subDays($dias_atras)->format('Y-m-d'),
            'buyer_id'       => (int) $buyer_id,
            'article_id'     => $article_id,
            'event_type'     => $event_type,
            'search_term'    => $search_term,
            'results_count'  => $results_count,
            'total'          => $total,
            // Un visitante por fila: son días de un comprador logueado, no del montón anónimo.
            'visitantes'     => 1,
            'dwell_ms_total' => $dwell_ms_total,
            'amount_total'   => $amount_total,
            'created_at'     => $this->ahora,
            'updated_at'     => $this->ahora,
        ];
    }

    /**
     * Recalcula las estadísticas de las dos tablas.
     *
     * 🔴 SIN ESTO, EL `EXPLAIN` DE DESPUÉS DE SEMBRAR NO SIGNIFICA NADA: InnoDB decide con las
     * estadísticas que tenía, que son las de la tabla vacía de antes de la corrida, y puede elegir un
     * plan que no es el que va a usar de verdad. Es la otra mitad del 🔴 sobre la forma de la siembra
     * del docblock de la clase.
     *
     * 🔴 Y NO CORRE ADENTRO DE UNA TRANSACCIÓN. `ANALYZE TABLE` es de las sentencias que hacen COMMIT
     * IMPLÍCITO en MySQL: corrida adentro de un test con DatabaseTransactions, confirmaría todo lo
     * sembrado y el rollback del final no revertiría nada, dejando la base de testing con datos que
     * nadie pidió y rompiendo las suites que vengan atrás. Por eso se mira transactionLevel() antes,
     * y no es defensivo de más: los tests de este comando lo corren de verdad.
     *
     * @return bool Si se llegó a correr
     */
    protected function analizar_tablas()
    {
        if (DB::transactionLevel() > 0) {
            $this->avisos[] = 'No se corrió ANALYZE TABLE porque hay una transacción abierta (haría COMMIT implícito). Corrélo a mano antes de medir un EXPLAIN.';
            return false;
        }

        DB::select('ANALYZE TABLE buyer_tracking_events, buyer_tracking_daily');

        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------------------------------

    /**
     * El identificador marcado como "de prueba", del ancho exacto del char(36).
     *
     * @param  int $numero
     * @return string
     */
    protected function marca($numero)
    {
        return self::PREFIJO_VISITANTE . str_pad((string) $numero, 12, '0', STR_PAD_LEFT);
    }

    /**
     * Un momento de hace N días, a una hora fija.
     *
     * @param  int $dias_atras
     * @param  int $hora
     * @param  int $minuto
     * @return string Y-m-d H:i:s
     */
    protected function momento($dias_atras, $hora, $minuto)
    {
        return Carbon::now()
            ->startOfDay()
            ->subDays($dias_atras)
            ->addHours($hora)
            ->addMinutes($minuto)
            ->format('Y-m-d H:i:s');
    }

    /**
     * Un momento de HOY, N minutos atrás.
     *
     * El recorte al arranque del día no es adorno: corrido a las 00:20, restarle 90 minutos se iría a
     * AYER y el evento dejaría de ser "de hoy", que es justo lo único que este bloque tiene que
     * demostrar. Falla hacia el lado seguro (queda de hoy) en vez de hacia el silencioso.
     *
     * @param  int $minutos_antes
     * @return string Y-m-d H:i:s
     */
    protected function momento_de_hoy($minutos_antes)
    {
        $ahora   = Carbon::now();
        $momento = $ahora->copy()->subMinutes($minutos_antes);

        if (!$momento->isSameDay($ahora)) {
            $momento = $ahora->copy()->startOfDay();
        }

        return $momento->format('Y-m-d H:i:s');
    }

    /**
     * La planilla de control: lo que la pantalla tiene que mostrar, para poder compararla contra lo
     * que muestra.
     *
     * 🔴 TODOS los números salen de lo que se le PIDIÓ sembrar (el array $eventos y las filas del
     * bloque viejo), nunca de una consulta a la base. Consultar la base acá convertiría la
     * verificación en el sistema chequeándose a sí mismo: si el insert escribió mal, la planilla
     * repetiría el error y las dos coincidirían. El molde y el porqué están en
     * SembrarDatosDePrueba::escribir_planilla_de_control():779-783.
     *
     * @param  int   $dias
     * @param  int   $fondo
     * @param  array $rollup
     * @param  array $viejo
     * @param  int   $insertados
     * @param  array $borradas
     * @return void
     */
    protected function escribir_planilla_de_control($dias, $fondo, array $rollup, array $viejo, $insertados, array $borradas)
    {
        $tipos = ['product_view', 'search', 'cart_add', 'cart_remove', 'checkout_start', 'checkout_complete'];
        $filas = [];

        foreach ($this->control as $etiqueta => $conteo) {
            $fila  = [$etiqueta];
            $total = 0;

            foreach ($tipos as $tipo) {
                $cantidad = isset($conteo[$tipo]) ? $conteo[$tipo] : 0;
                $total   += $cantidad;
                $fila[]   = $cantidad;
            }

            $fila[] = $total;
            $filas[] = $fila;
        }

        $this->info('');
        $this->info('PLANILLA DE CONTROL — esto es lo que se sembró, y es contra esto que se compara la pantalla.');
        $this->table(
            ['Quién', 'Vistas', 'Búsquedas', 'Al carrito', 'Del carrito', 'Checkout', 'Compras', 'Total'],
            $filas
        );

        $this->table(['Qué', 'Cuánto'], [
            ['Eventos crudos insertados', $insertados],
            ['Ventana sembrada', 'de hace ' . $dias . ' días hasta hoy'],
            ['Compradores de fondo', $fondo . ' (ids ' . self::PRIMER_BUYER_DE_FONDO . ' en adelante, sin fila en buyers)'],
            ['Días agregados por el rollup real', $rollup['dias'] . ' (HOY queda sin agregar, como en producción)'],
            ['Rollups fallados', empty($rollup['fallados']) ? 'ninguno' : implode(', ', $rollup['fallados'])],
            ['Filas del bloque VIEJO (sólo agregado)', count($viejo)],
            ['Fechas del bloque VIEJO', $this->fechas_del_bloque_viejo($viejo)],
            ['Borrado previo', $borradas['eventos'] . ' eventos y ' . $borradas['agregado'] . ' filas de agregado de una corrida anterior'],
        ]);

        $this->info('Qué tiene que poder verse con esto:');
        $this->info('  - Con periodo 7/30/90 NO aparecen los artículos ni el término del bloque viejo; con "todo el historial" SÍ.');
        $this->info('  - El término "' . self::BUSQUEDA_SIN_RESULTADO . '" aparece 2 veces con 0 resultados: hay demanda y no hay oferta.');
        $this->info('  - Hay un artículo puesto en el carrito y nunca comprado (carrito abandonado) y otro que sí terminó en compra.');
        $this->info('  - El comprador sin cliente asociado se ve desde Tienda Online → Clientes y NO desde Clientes del ERP.');
        $this->info('  - Los visitantes anónimos se cuentan aparte y no engordan la lista de clientes nombrados.');
        $this->info('  - Hay actividad de HOY (los periodos cortos la incluyen) y el agregado llega sólo hasta AYER.');

        foreach ($this->avisos as $aviso) {
            $this->warn('AVISO: ' . $aviso);
        }

        $this->info('Para borrar todo lo sembrado: php artisan tracking:sembrar-actividad-de-prueba --dias=' . $dias . ' --limpiar');
    }

    /**
     * @param  array $viejo
     * @return string
     */
    protected function fechas_del_bloque_viejo(array $viejo)
    {
        $fechas = [];

        foreach ($viejo as $fila) {
            $fechas[$fila['fecha']] = $fila['fecha'];
        }

        sort($fechas);

        return implode(', ', $fechas);
    }
}
