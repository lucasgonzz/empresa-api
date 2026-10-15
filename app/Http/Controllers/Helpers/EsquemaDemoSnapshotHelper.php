<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Volcado y restauración del ESQUEMA de una instancia de demo: las 435 tablas migradas, vacías,
 * más el contenido de `migrations`.
 *
 * Para qué existe (misión M9 del programa «embudo comercial», 13/10/2026): `DemoSetupHelper::run()`
 * arrancaba con `migrate:fresh`, que son 873 migraciones una atrás de la otra y la etapa más cara
 * del setup después de la siembra (34,9 s medidos en demo3 el 9/9/2026). Restaurar un volcado del
 * estado FINAL de esas 873 migraciones da exactamente la misma base en una fracción de ese tiempo,
 * porque es un puñado de `CREATE TABLE` en vez de ochocientas sesenta y pico de transformaciones.
 *
 * 🔴 LA «VERSIÓN» DEL VOLCADO ES LA HUELLA DEL CONJUNTO DE MIGRACIONES, NO UN NÚMERO DE RELEASE.
 * `empresa-api` no tiene número de versión en el código (no hay `config('app.version')`, ni archivo
 * `VERSION`, ni `version` en `composer.json`: el número vive en el tag de git y en el workflow de
 * release). Y un número de release sería PEOR para esto: en `develop` se agregan migraciones sin que
 * el número cambie, y ahí un volcado viejo restauraría un esquema incompleto sin que nada avise. La
 * huella de `version()` cambia con cada migración nueva, que es exactamente la condición que tiene
 * que disparar el respaldo a `migrate:fresh`.
 *
 * 🔴 SE AUTO-CEBA, y por eso no depende de que el pipeline de release empaquete nada: la primera
 * corrida después de una migración nueva no encuentra volcado, cae a `migrate:fresh` (lo de siempre)
 * y escribe el volcado para la próxima. Como una instancia de demo se rearma por cada lead, el
 * beneficio entra solo a partir del segundo lead de esa versión.
 *
 * 🔴 «MIGRADAS Y VACÍAS» ES MENTIRA A MEDIAS, Y POR ESO EL VOLCADO TAMBIÉN LLEVA FILAS (15/10/2026).
 * Hay migraciones que INSERTAN datos además de crear tablas: los dos estados de `meli_order_statuses`,
 * las plataformas de Mercado Pago y Zipnova (`asegurar_plataforma_*`), el tipo de pago de Mercado
 * Pago y la plantilla online ComercioCity. Medido sobre `empresa_demo_m9` recién migrada: `platforms`
 * 4 filas, `meli_order_statuses` 2, `payment_method_types` 1, `online_templates` 1. Un volcado solo
 * de DDL dejaba esas tablas vacías: `meli_order_statuses` no la repone ningún seeder, y las otras
 * las reponen seeders que, sobre una tabla vacía, las crean con OTROS ids que después de un
 * `migrate:fresh`. O sea, una base distinta, que es justo lo que este helper promete que no pasa.
 * Ahora `generar()` vuelca también esas filas, los dos caminos de `restaurar()` las reponen y
 * `verificar()` cuenta fila por tabla.
 *
 * Todo en PHP puro (`SHOW CREATE TABLE` + `DB::unprepared()`), sin `mysqldump` ni `mysql`: en el
 * shared hosting de Hostinger no hay binarios de cliente de MySQL garantizados, así que un volcado
 * que dependa de ellos no sirve justo donde tiene que servir.
 */
class EsquemaDemoSnapshotHelper
{
    /**
     * Separador entre sentencias del volcado.
     *
     * Línea propia y no un `;` al final, a propósito: el DDL que devuelve `SHOW CREATE TABLE` puede
     * traer un punto y coma ADENTRO (el `DEFAULT` de una columna de texto, un `CHECK`), así que
     * partir el archivo por `;` rompe la sentencia en dos y la restauración muere con un error de
     * sintaxis que no se parece en nada a la causa.
     */
    const SEPARADOR = "-- //";

    /** Primera línea del archivo. Si no está, no es un volcado nuestro y no se toca. */
    const MARCA = "-- esquema-demo v2";

    /**
     * Las tablas que llenan las propias migraciones sobre una base vacía (medido el 15/10/2026
     * sobre `empresa_demo_m9` recién migrada). Son las únicas cuyas filas viajan en el volcado: una
     * tabla con filas que no está acá hace que `generar()` se niegue (ver ahí por qué).
     */
    const TABLAS_CON_DATOS_DE_MIGRACION = [
        'meli_order_statuses',
        'online_templates',
        'payment_method_types',
        'platforms',
    ];

    /**
     * Tablas cuyas filas NO son datos de las migraciones aunque las encuentre llenas: `migrations`
     * va aparte (con `batch` normalizado) y las de infraestructura de Laravel las puede escribir
     * cualquier cosa que haya corrido en el medio (una cola, una sesión, un lock de caché).
     */
    const TABLAS_SIN_DATOS_DE_MIGRACION = [
        'migrations',
        'jobs',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
        'password_resets',
        'personal_access_tokens',
    ];

    /**
     * Huella del conjunto de migraciones que hay en disco. Son los primeros 12 caracteres del sha1
     * de la lista ordenada de nombres, que alcanzan de sobra para distinguir dos conjuntos y dejan
     * un nombre de archivo legible.
     *
     * @return string
     */
    public static function version()
    {
        return self::version_de(self::migraciones_en_disco());
    }

    /**
     * La huella de una lista cualquiera de migraciones. Separado de `version()` para poder probar
     * que dos conjuntos distintos dan huellas distintas sin tener que crear archivos de migración
     * de verdad en el repo.
     *
     * @param string[] $migraciones
     * @return string
     */
    public static function version_de(array $migraciones)
    {
        sort($migraciones);

        return substr(sha1(implode("\n", $migraciones)), 0, 12);
    }

    /**
     * Nombres de las migraciones que hay en `database/migrations/`, sin el `.php` y ORDENADOS, que
     * es la misma forma en la que los guarda la tabla `migrations` y el mismo orden en el que
     * Laravel las corre.
     *
     * @return string[]
     */
    public static function migraciones_en_disco()
    {
        $archivos = glob(database_path('migrations/*.php'));

        if ($archivos === false) {
            return [];
        }

        $nombres = [];

        foreach ($archivos as $archivo) {
            $nombres[] = basename($archivo, '.php');
        }

        sort($nombres);

        return $nombres;
    }

    /** Carpeta donde viven los volcados. */
    public static function carpeta()
    {
        return storage_path('demo');
    }

    /**
     * Ruta del volcado de una versión. Sin argumento, la de la versión que hay en disco ahora.
     *
     * @param string|null $version
     * @return string
     */
    public static function ruta($version = null)
    {
        if (is_null($version)) {
            $version = self::version();
        }

        return self::carpeta() . DIRECTORY_SEPARATOR . 'esquema-' . $version . '.sql';
    }

    /**
     * Genera el volcado de la base ACTUAL, que tiene que estar RECIÉN MIGRADA: el esquema, la tabla
     * `migrations` y las filas que insertaron las propias migraciones (ver la cabecera de la clase).
     *
     * Los que lo llaman son `DemoSetupHelper::run()` justo después de su `migrate:fresh` y el
     * comando `demo:snapshot-esquema`. Como las filas de cualquier tabla llena se toman por «datos
     * de las migraciones», acá se exige que `users` esté vacía: una base con un usuario ya pasó por
     * un setup o por seeders, y sus filas son del negocio, no de las migraciones. También se niega
     * si hay vistas u objetos que no sean `BASE TABLE`: este volcado no los sabe reproducir y una
     * vista futura lo rompería en silencio.
     *
     * @param string|null $ruta Destino. Null = `ruta()` de la versión en disco.
     * @param string[]|null $tablas_con_datos Null (lo normal) = detectarlas: toda tabla con filas
     *                                        salvo las de `TABLAS_SIN_DATOS_DE_MIGRACION`, con la
     *                                        guarda de `users` vacía. Una lista explícita saltea la
     *                                        guarda y vuelca solo esas: es para la suite, que corre
     *                                        sobre una base con fixture y no puede migrar de cero.
     * @return array{ruta: string, version: string, tablas: int, migraciones: int, filas: array<string,int>}
     *
     * @throws \RuntimeException Si hay vistas, si la base no está recién migrada, si la carpeta no
     *                           se puede crear o si no se puede escribir el archivo.
     */
    public static function generar($ruta = null, $tablas_con_datos = null)
    {
        $version = self::version();

        if (is_null($ruta)) {
            $ruta = self::ruta($version);
        }

        $tablas = self::tablas_base();

        if (empty($tablas)) {
            throw new \RuntimeException('No hay ninguna tabla para volcar: la base no está migrada.');
        }

        self::negarse_si_hay_vistas();

        self::negarse_si_la_base_esta_atrasada();

        if (is_null($tablas_con_datos)) {
            if (in_array('users', $tablas, true) && DB::table('users')->limit(1)->count() > 0) {
                throw new \RuntimeException(
                    'La base no está recién migrada: `users` tiene filas, así que lo que hay en las '
                        . 'tablas es del negocio y no de las migraciones. El volcado tiene que salir '
                        . 'de una base vacía recién pasada por `php artisan migrate:fresh --force`.'
                );
            }

            $tablas_con_datos = array_values(array_diff(
                self::tablas_con_filas($tablas),
                self::TABLAS_SIN_DATOS_DE_MIGRACION
            ));

            /*
                🔴 Solo las tablas que se SABE que llenan las migraciones (chequeo independiente del
                15/10/2026). La tienda de la demo comparte la base: si un visitante escribe una fila
                (un carrito, un rastreo) justo entre el `migrate:fresh` y este volcado, esa fila
                quedaría en el volcado, `verificar()` la contaría como esperada y todas las demos
                siguientes nacerían con ella. Ante una tabla que no está en la lista no se adivina:
                no se escribe el volcado, la próxima corrida vuelve a `migrate:fresh` (lento pero
                correcto) y el log nombra la tabla. Si es una migración nueva que inserta filas, se
                la agrega a `TABLAS_CON_DATOS_DE_MIGRACION`.
            */
            $desconocidas = array_values(array_diff($tablas_con_datos, self::TABLAS_CON_DATOS_DE_MIGRACION));

            if (! empty($desconocidas)) {
                throw new \RuntimeException(
                    'Hay filas en tablas que no se sabe que llenen las migraciones: '
                        . implode(', ', $desconocidas) . '. Si las inserta una migración, agregalas a '
                        . 'EsquemaDemoSnapshotHelper::TABLAS_CON_DATOS_DE_MIGRACION.'
                );
            }
        }

        sort($tablas_con_datos);

        $carpeta = dirname($ruta);

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0775, true) && ! is_dir($carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta del volcado: ' . $carpeta);
        }

        /*
            Temporal + rename, que en el mismo volumen es atómico: dos setups simultáneos (y el
            candado de `DemoSetupLockHelper` no cubre dos instancias distintas apuntando a la misma
            carpeta) no pueden dejar un archivo a medio escribir con el nombre bueno. Un volcado
            truncado pasaría la búsqueda por nombre y reventaría a mitad de la restauración, que es
            el peor final posible: base ya vaciada y esquema incompleto.
        */
        $temporal = $ruta . '.' . getmypid() . '.parcial';

        $f = fopen($temporal, 'w');

        if ($f === false) {
            throw new \RuntimeException('No se pudo escribir el volcado en ' . $temporal);
        }

        try {
            $migraciones = self::migraciones_de_la_base();

            $filas = [];

            foreach ($tablas_con_datos as $tabla) {
                $filas[$tabla] = (int) DB::table($tabla)->count();
            }

            fwrite($f, self::MARCA . "\n");
            fwrite($f, '-- meta: ' . json_encode([
                'version'     => $version,
                'generado'    => date('c'),
                'tablas'      => count($tablas),
                'migraciones' => count($migraciones),
                'filas'       => (object) $filas,
            ]) . "\n");
            fwrite($f, self::SEPARADOR . "\n");

            foreach ($tablas as $tabla) {
                fwrite($f, 'DROP TABLE IF EXISTS `' . $tabla . '`');
                fwrite($f, "\n" . self::SEPARADOR . "\n");

                fwrite($f, self::ddl_de($tabla));
                fwrite($f, "\n" . self::SEPARADOR . "\n");
            }

            foreach (self::inserts_de_migrations($migraciones) as $sentencia) {
                fwrite($f, $sentencia);
                fwrite($f, "\n" . self::SEPARADOR . "\n");
            }

            foreach ($tablas_con_datos as $tabla) {
                foreach (self::inserts_de_datos($tabla) as $sentencia) {
                    fwrite($f, $sentencia);
                    fwrite($f, "\n" . self::SEPARADOR . "\n");
                }
            }
        } catch (\Throwable $e) {
            fclose($f);
            @unlink($temporal);

            throw $e;
        }

        fclose($f);

        if (! rename($temporal, $ruta)) {
            @unlink($temporal);

            throw new \RuntimeException('No se pudo mover el volcado a su nombre final: ' . $ruta);
        }

        /*
            Los volcados de versiones anteriores no se van a usar nunca más (la huella cambió) y son
            ~380 KB cada uno: se borran. Solo en la carpeta propia, nunca junto a una `--salida`
            que eligió una persona.
        */
        if (realpath(dirname($ruta)) === realpath(self::carpeta())) {
            foreach ((array) glob(self::carpeta() . DIRECTORY_SEPARATOR . 'esquema-*.sql') as $viejo) {
                if (is_string($viejo) && basename($viejo) !== basename($ruta)) {
                    @unlink($viejo);
                }
            }
        }

        return [
            'ruta'        => $ruta,
            'version'     => $version,
            'tablas'      => count($tablas),
            'migraciones' => count($migraciones),
            'filas'       => $filas,
        ];
    }

    /**
     * Intenta dejar la base en el mismo estado que un `migrate:fresh`, restaurando el volcado de la
     * versión que hay en disco.
     *
     * 🔴 NO TIRA NUNCA. Devuelve `false` y el motivo, porque el único que la llama
     * (`DemoSetupHelper::run()`) tiene un respaldo que SÍ funciona siempre (`migrate:fresh`) y lo
     * que no puede pasar es que el setup de un lead muera porque un archivo de caché estaba
     * corrupto. Lo que sí hace es dejar el motivo en el log.
     *
     * @param string|null $ruta Volcado a restaurar. Null = el de la versión que hay en disco, que es
     *                          lo que usa `DemoSetupHelper`. Se puede pasar explícito para probar
     *                          los caminos de rechazo sin depender de qué archivos haya.
     * @return array{ok: bool, motivo: string, tablas: int, migraciones: int}
     */
    public static function restaurar($ruta = null)
    {
        /*
            Segunda guarda, por si alguien llama esto sin pasar por `DemoSetupHelper`: en la suite el
            volcado POR DEFECTO no se restaura nunca. Vaciaría la base de testing del slot sin pasar
            por el `migrate:fresh` que los tests mockean para protegerla. Los tests que prueban los
            rechazos pasan una ruta explícita.
        */
        if (is_null($ruta) && config('app.env') === 'testing') {
            return self::fallo('en la suite no se restaura el volcado por defecto');
        }

        $version = self::version();

        if (is_null($ruta)) {
            $ruta = self::ruta($version);
        }

        if (! is_file($ruta)) {
            return self::fallo('no hay volcado para la versión ' . $version);
        }

        $meta = self::leer_meta($ruta);

        if (is_null($meta)) {
            return self::fallo('el volcado ' . basename($ruta) . ' no tiene cabecera válida');
        }

        $en_disco = self::migraciones_en_disco();

        /*
            La cantidad, además del nombre del archivo. El nombre ya codifica la huella del conjunto
            de migraciones, así que esto es cinturón sobre tirantes -- pero cubre el caso de un
            archivo pisado a mano o copiado de otra instancia con el nombre equivocado, que es
            exactamente el tipo de cosa que pasa cuando alguien resuelve un incidente a las tres de
            la mañana.
        */
        if ((int) $meta['migraciones'] !== count($en_disco)) {
            return self::fallo(
                'el volcado declara ' . $meta['migraciones'] . ' migraciones y en disco hay '
                    . count($en_disco)
            );
        }

        try {
            $volcado = self::leer_volcado($ruta);

            /*
                🔴 DOS CAMINOS PARA EL MISMO RESULTADO, y el barato primero.

                El caso NORMAL de una instancia de demo es que se rearme muchas veces seguidas sobre
                la misma versión: el esquema que hay en la base ya es, tabla por tabla, el que trae
                el volcado, y lo único que sobra son las filas del lead anterior. Ahí tirar las 435
                tablas y volver a crearlas es trabajo puro de sistema de archivos (InnoDB con
                `innodb_file_per_table` crea y borra un `.ibd` por tabla) para terminar exactamente
                donde ya estábamos.

                Medido el 13/10/2026 en esta máquina, sobre la base de una demo recién sembrada:

                    - tirar y recrear las 435 tablas ............... 28,7 s
                    - de las 435, SOLO 118 tienen una fila ......... y 116 el contador corrido
                    - saber cuáles son (5 viajes, no 435) .......... 0,43 s
                    - comparar el DDL de las 435 .................. 0,50 s

                Así que el camino rápido vacía ~120 tablas en vez de recrear 435, y cae al completo
                en cuanto el esquema NO coincide -- que es justamente lo que pasa si el volcado es de
                otra versión, si alguien tocó una tabla a mano, o si el servidor escribe el DDL
                distinto. Si la comparación nunca diera, lo único que se pierde es la velocidad.
            */
            $estado = self::estado_del_esquema($volcado['tablas']);

            if ($estado['coincide']) {
                self::vaciar_lo_que_haga_falta($volcado, $estado['con_contador_corrido']);
            } else {
                self::recrear_todo($volcado);
            }
        } catch (\Throwable $e) {
            /*
                Acá la base puede haber quedado a medio restaurar, y es a propósito que no se intente
                arreglarla: el que llama corre `migrate:fresh` a continuación, que empieza tirando
                TODAS las tablas. O sea que el respaldo ya es la reparación.
            */
            return self::fallo('la restauración falló: ' . $e->getMessage());
        }

        $verificacion = self::verificar($meta, $en_disco);

        if (! $verificacion['ok']) {
            return $verificacion;
        }

        return [
            'ok'          => true,
            'motivo'      => 'restaurado desde ' . basename($ruta),
            'tablas'      => $verificacion['tablas'],
            'migraciones' => $verificacion['migraciones'],
        ];
    }

    /**
     * Lee el volcado y devuelve sus sentencias separadas por tipo.
     *
     * El archivo son 360 KB: se carga entero y se arma el mapa en memoria, que es más simple y más
     * fácil de leer que un generador, y 360 KB no son un problema de memoria en ningún servidor
     * donde corra esto.
     *
     * @param string $ruta
     * @return array{tablas: array<string,string>, migrations: string[]}
     */
    public static function leer_volcado($ruta)
    {
        $contenido = file_get_contents($ruta);

        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el volcado ' . $ruta);
        }

        $tablas = [];
        $migrations = [];
        $datos = [];

        foreach (explode("\n" . self::SEPARADOR . "\n", $contenido) as $bruto) {
            $sentencia = trim($bruto);

            if ($sentencia === '' || strpos($sentencia, '--') === 0) {
                continue;
            }

            if (preg_match('/^CREATE TABLE `([^`]+)`/', $sentencia, $coincidencia)) {
                $tablas[$coincidencia[1]] = $sentencia;

                continue;
            }

            if (strpos($sentencia, 'INSERT INTO `migrations`') === 0) {
                $migrations[] = $sentencia;

                continue;
            }

            /* Las filas que dejaron las migraciones (ver la cabecera de la clase). */
            if (preg_match('/^INSERT INTO `([^`]+)`/', $sentencia, $coincidencia)) {
                $datos[$coincidencia[1]][] = $sentencia;
            }

            /* Los `DROP TABLE` del volcado no se guardan: cada camino arma los suyos. */
        }

        if (empty($tablas)) {
            throw new \RuntimeException('El volcado no tiene ningún CREATE TABLE.');
        }

        return ['tablas' => $tablas, 'migrations' => $migrations, 'datos' => $datos];
    }

    /**
     * Una sola pasada de `SHOW CREATE TABLE` sobre toda la base, que contesta las DOS preguntas que
     * el camino rápido necesita:
     *
     * - `coincide`: ¿el esquema que hay es, tabla por tabla, el que trae el volcado? Se compara el
     *   DDL completo y no solo los nombres: dos bases pueden tener las mismas 435 tablas con una
     *   columna de diferencia, y ahí vaciar en vez de recrear dejaría el esquema viejo con la tabla
     *   `migrations` diciendo que está al día. Eso es mucho peor que tardar 28 segundos.
     * - `con_contador_corrido`: qué tablas tienen el autoincremento arriba de 1. MySQL **omite** el
     *   `AUTO_INCREMENT=N` del `SHOW CREATE TABLE` cuando vale 1, así que la presencia de esa
     *   cláusula ES la respuesta.
     *
     * 🔴 POR QUÉ ESTO NO SALE DE `information_schema.TABLES.AUTO_INCREMENT`, que sería una sola
     * consulta en vez de 435 (medido el 13/10/2026, y casi me come):
     *
     * En MySQL 8 ese campo viene de un caché del diccionario de datos que caduca a las 24 horas
     * (`information_schema_stats_expiry`, default 86400). Sobre esta misma base, con el contador ya
     * reiniciado por un `TRUNCATE`, `information_schema` devolvía **508** para `users` y
     * `SHOW CREATE TABLE` no traía la cláusula -- o sea, el valor real era 1. Con
     * `set session information_schema_stats_expiry = 0` devuelve 1, pero esa variable no existe en
     * MySQL 5.7 y no sabemos qué versión corre cada instancia de demo.
     *
     * Un dato cacheado acá no da un error: deja una tabla sin vaciar y sus ids arrancan donde los
     * dejó la demo anterior. Eso se descubre semanas después, mirando un id que no cierra.
     *
     * Las 435 consultas cuestan 0,5 s medidos, y hay que hacerlas igual para comparar el DDL.
     *
     * @param array<string,string> $del_volcado tabla => DDL
     * @return array{coincide: bool, con_contador_corrido: string[]}
     */
    protected static function estado_del_esquema(array $del_volcado)
    {
        $actuales = self::tablas_base();

        $esperadas = array_keys($del_volcado);
        sort($esperadas);

        if ($actuales !== $esperadas) {
            return ['coincide' => false, 'con_contador_corrido' => []];
        }

        $con_contador = [];

        foreach ($actuales as $tabla) {
            $ddl = self::ddl_de($tabla, false);

            if (strpos($ddl, 'AUTO_INCREMENT=') !== false) {
                $con_contador[] = $tabla;
            }

            if (self::normalizar_ddl($ddl) !== self::normalizar_ddl($del_volcado[$tabla])) {
                return ['coincide' => false, 'con_contador_corrido' => []];
            }
        }

        return ['coincide' => true, 'con_contador_corrido' => $con_contador];
    }

    /**
     * Deja dos DDL comparables: sin el contador de autoincremento (que cambia con el uso y no es
     * parte del esquema) y con los saltos de línea normalizados.
     *
     * @param string $ddl
     * @return string
     */
    protected static function normalizar_ddl($ddl)
    {
        $ddl = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $ddl);

        return trim(str_replace("\r\n", "\n", $ddl));
    }

    /**
     * Camino rápido: el esquema ya es el correcto, así que solo hay que sacarle las filas.
     *
     * Se vacía la tabla que tiene al menos una fila **o** el contador de autoincremento corrido. Lo
     * segundo no es un detalle: una tabla que tuvo filas y de la que la app las borró queda vacía
     * pero con el contador alto, y saltearla dejaría los ids de esa tabla arrancando donde quedó la
     * demo anterior. Media docena de seeders y toda la aritmética de `semilla:datos` dan por sentado
     * que arrancan en 1 (`AlineacionLocalDemoTest` fija los conceptos de compensación de caja en los
     * ids 7 a 10).
     *
     * `TRUNCATE` y no `DELETE`: reinicia el contador, y en InnoDB no recorre las filas.
     *
     * @param array{tablas: array<string,string>, migrations: string[]} $volcado
     * @param string[] $con_contador_corrido Tablas con el autoincremento arriba de 1, leídas del DDL
     *                                       crudo por `estado_del_esquema()`.
     * @return void
     */
    protected static function vaciar_lo_que_haga_falta(array $volcado, array $con_contador_corrido)
    {
        $tablas = array_keys($volcado['tablas']);

        $a_vaciar = array_unique(array_merge(
            self::tablas_con_filas($tablas),
            $con_contador_corrido
        ));

        /* `migrations` se repone entera más abajo, acá solo hay que asegurarse de vaciarla. */
        if (! in_array('migrations', $a_vaciar, true)) {
            $a_vaciar[] = 'migrations';
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($a_vaciar as $tabla) {
                DB::unprepared('TRUNCATE TABLE `' . $tabla . '`');
            }

            self::reponer_filas($volcado);
        } finally {
            DB::unprepared('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Camino completo: tirar todas las tablas y crearlas de nuevo desde el volcado.
     *
     * @param array{tablas: array<string,string>, migrations: string[]} $volcado
     * @return void
     */
    protected static function recrear_todo(array $volcado)
    {
        DB::unprepared('SET FOREIGN_KEY_CHECKS = 0');

        try {
            /*
                Primero TODOS los drops y después todos los creates: las tablas se crean en orden
                alfabético, así que una clave foránea puede apuntar a una tabla que todavía no
                existe. Con `FOREIGN_KEY_CHECKS` en 0 eso ya está cubierto, pero separar las dos
                pasadas además borra cualquier tabla que esté en la base y NO en el volcado, que es
                lo que hace que la restauración sea equivalente a un `migrate:fresh`.
            */
            foreach (self::tablas_base() as $tabla) {
                DB::unprepared('DROP TABLE IF EXISTS `' . $tabla . '`');
            }

            foreach ($volcado['tablas'] as $ddl) {
                DB::unprepared($ddl);
            }

            self::reponer_filas($volcado);
        } finally {
            DB::unprepared('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * `migrations` y las filas que dejaron las migraciones, sobre tablas que ya están vacías.
     *
     * Las filas van con su `id` explícito, así que quedan con los mismos ids que les dio el
     * `migrate:fresh` del que salió el volcado, e InnoDB deja el contador en el máximo + 1, que es
     * también lo que deja ese `migrate:fresh` (ninguna migración inserta y después borra sobre una
     * base vacía).
     *
     * @param array{tablas: array<string,string>, migrations: string[], datos: array<string,string[]>} $volcado
     * @return void
     */
    protected static function reponer_filas(array $volcado)
    {
        foreach ($volcado['migrations'] as $sentencia) {
            DB::unprepared($sentencia);
        }

        foreach ($volcado['datos'] as $sentencias) {
            foreach ($sentencias as $sentencia) {
                DB::unprepared($sentencia);
            }
        }
    }

    /**
     * Cuáles de esas tablas tienen al menos una fila, en pocos viajes a la base y no en uno por
     * tabla.
     *
     * Medido el 13/10/2026 con 435 tablas: **0,43 s** en lotes de 100 contra **2,87 s** haciendo un
     * `count()` por tabla. La diferencia es ida y vuelta de PDO, no trabajo de MySQL.
     *
     * `exists(select 1 from t)` y NO `information_schema.TABLE_ROWS`: en InnoDB ese campo es una
     * estimación del optimizador y puede decir 0 con filas adentro.
     *
     * @param string[] $tablas
     * @return string[]
     */
    public static function tablas_con_filas(array $tablas)
    {
        $con_filas = [];

        foreach (array_chunk($tablas, 100) as $lote) {
            $partes = [];

            foreach ($lote as $tabla) {
                $limpia = str_replace('`', '', $tabla);

                $partes[] = 'select ' . self::entrecomillar($limpia) . ' as t, exists(select 1 from `'
                    . $limpia . '`) as e';
            }

            foreach (DB::select(implode(' union all ', $partes)) as $fila) {
                if ((int) $fila->e === 1) {
                    $con_filas[] = $fila->t;
                }
            }
        }

        return $con_filas;
    }

    /**
     * Verifica que lo que quedó en la base después de restaurar sea indistinguible de lo que habría
     * dejado un `migrate:fresh`.
     *
     * Las tres cosas que se comparan, y el porqué de cada una:
     *
     * 1. **La tabla `migrations`, nombre por nombre y en las dos direcciones.** Es la que decide si
     *    un `php artisan migrate` posterior (el que corre `DeploymentService` en cada despliegue)
     *    vuelve a aplicar algo o no. Si al volcado le falta una fila, esa migración se corre de nuevo
     *    sobre una tabla que ya existe y el despliegue muere; si le sobra, una migración nunca
     *    aplicada queda marcada como hecha y la columna que agrega no existe nunca. Comparar solo la
     *    CANTIDAD no alcanza: dos conjuntos distintos pueden tener el mismo largo.
     * 2. **La cantidad de tablas** contra la que declara la cabecera, que es la forma baratísima de
     *    detectar una restauración que se cortó por la mitad.
     * 3. **Las filas** (solo si la cabecera las declara, que es siempre desde la marca v2): cada
     *    tabla con la cantidad que dejaron las migraciones, y ninguna otra tabla con filas.
     *
     * @param array<string,mixed> $meta
     * @param string[] $en_disco
     * @return array{ok: bool, motivo: string, tablas: int, migraciones: int}
     */
    public static function verificar(array $meta, array $en_disco)
    {
        $tablas = self::tablas_base();

        if (count($tablas) !== (int) $meta['tablas']) {
            return self::fallo(
                'quedaron ' . count($tablas) . ' tablas y el volcado declara ' . $meta['tablas']
            );
        }

        if (! in_array('migrations', $tablas, true)) {
            return self::fallo('la tabla migrations no quedó en la base');
        }

        $en_base = self::migraciones_de_la_base();

        $faltan = array_values(array_diff($en_disco, $en_base));
        $sobran = array_values(array_diff($en_base, $en_disco));

        if (! empty($faltan) || ! empty($sobran)) {
            return self::fallo(
                'la tabla migrations no quedó idéntica: faltan ' . count($faltan)
                    . ' (' . implode(', ', array_slice($faltan, 0, 3)) . ') y sobran '
                    . count($sobran) . ' (' . implode(', ', array_slice($sobran, 0, 3)) . ')'
            );
        }

        /*
            3. Las filas que dejaron las migraciones: la misma cantidad en cada tabla que declara la
               cabecera, y NINGUNA otra tabla con filas. Lo segundo es lo que garantiza que el camino
               rápido (vaciar en vez de recrear) no dejó nada del lead anterior.
        */
        $filas_esperadas = isset($meta['filas']) && is_array($meta['filas']) ? $meta['filas'] : [];

        foreach ($filas_esperadas as $tabla => $cantidad) {
            if (! in_array($tabla, $tablas, true)) {
                return self::fallo('el volcado trae filas de `' . $tabla . '` y la tabla no quedó');
            }

            $hay = (int) DB::table($tabla)->count();

            if ($hay !== (int) $cantidad) {
                return self::fallo(
                    '`' . $tabla . '` quedó con ' . $hay . ' filas y el volcado declara ' . $cantidad
                );
            }
        }

        $de_mas = [];

        if (isset($meta['filas'])) {
            $de_mas = array_values(array_diff(
                self::tablas_con_filas($tablas),
                array_merge(['migrations'], array_keys($filas_esperadas))
            ));
        }

        if (! empty($de_mas)) {
            return self::fallo(
                'quedaron filas en tablas que el volcado no trae: '
                    . implode(', ', array_slice($de_mas, 0, 5))
            );
        }

        return [
            'ok'          => true,
            'motivo'      => 'verificado',
            'tablas'      => count($tablas),
            'migraciones' => count($en_base),
        ];
    }

    /**
     * Nombres de las `BASE TABLE` de la base actual, ordenados.
     *
     * Se pide por `information_schema` y no por `SHOW TABLES` para poder filtrar por `TABLE_TYPE`:
     * una vista aparece en `SHOW TABLES` como si fuera una tabla. Acá el caché del diccionario de
     * datos no molesta -- lo que caduca a las 24 horas son las estadísticas (`TABLE_ROWS`,
     * `AUTO_INCREMENT`), no la existencia de la tabla ni su tipo.
     *
     * @return string[]
     */
    public static function tablas_base()
    {
        $filas = DB::select(
            "select TABLE_NAME as nombre from information_schema.TABLES
             where TABLE_SCHEMA = database() and TABLE_TYPE = 'BASE TABLE'
             order by TABLE_NAME"
        );

        $nombres = [];

        foreach ($filas as $fila) {
            $nombres[] = $fila->nombre;
        }

        return $nombres;
    }

    /**
     * Los nombres de `migrations` que hay en la BASE, ordenados igual que los de disco.
     *
     * @return string[]
     */
    public static function migraciones_de_la_base()
    {
        $filas = DB::select('select migration from migrations order by migration');

        $nombres = [];

        foreach ($filas as $fila) {
            $nombres[] = $fila->migration;
        }

        return $nombres;
    }

    /**
     * Se niega a volcar si la tabla `migrations` de la base NO es la lista que hay en disco.
     *
     * 🔴 Por qué esto no es paranoia (lo encontró `EsquemaSnapshotDeDemoTest` el 13/10/2026, sobre
     * la base del slot, que estaba atrasada una migración):
     *
     * El nombre del archivo sale de la lista de migraciones **en disco** y el contenido sale de la
     * **base**. Si la base está atrasada, el volcado queda ETIQUETADO como el de la versión nueva y
     * lleva adentro el esquema de la vieja. La verificación de `restaurar()` lo agarra (compara
     * `migrations` contra el disco) y cae a `migrate:fresh`, así que no se rompe nada -- pero
     * entonces ese archivo nunca sirve para nada y cada setup paga una restauración al vacío antes
     * de migrar igual. Negarse acá cuesta una consulta y evita generar un archivo que miente.
     *
     * Adelantada también cuenta: una base con filas en `migrations` que no tienen archivo es una
     * base de otra rama, y su esquema no es el de este código.
     *
     * @return void
     * @throws \RuntimeException
     */
    protected static function negarse_si_la_base_esta_atrasada()
    {
        $en_disco = self::migraciones_en_disco();
        $en_base = self::migraciones_de_la_base();

        $faltan = array_values(array_diff($en_disco, $en_base));
        $sobran = array_values(array_diff($en_base, $en_disco));

        if (empty($faltan) && empty($sobran)) {
            return;
        }

        throw new \RuntimeException(
            'La base no está en el mismo punto que las migraciones de este código, así que su '
                . 'esquema no es el de la versión con la que se nombraría el volcado. Sin aplicar: '
                . count($faltan) . ' (' . implode(', ', array_slice($faltan, 0, 3)) . '). '
                . 'En la base y no en el código: ' . count($sobran)
                . ' (' . implode(', ', array_slice($sobran, 0, 3)) . '). '
                . 'Corré `php artisan migrate --force` antes.'
        );
    }

    /**
     * @return void
     * @throws \RuntimeException
     */
    protected static function negarse_si_hay_vistas()
    {
        $filas = DB::select(
            "select TABLE_NAME as nombre from information_schema.TABLES
             where TABLE_SCHEMA = database() and TABLE_TYPE <> 'BASE TABLE'
             order by TABLE_NAME"
        );

        if (empty($filas)) {
            return;
        }

        $nombres = [];

        foreach ($filas as $fila) {
            $nombres[] = $fila->nombre;
        }

        throw new \RuntimeException(
            'La base tiene objetos que este volcado no sabe reproducir (vistas u otros): '
                . implode(', ', $nombres) . '. Hay que extender EsquemaDemoSnapshotHelper antes de '
                . 'confiar en un volcado que los deja afuera.'
        );
    }

    /**
     * El `CREATE TABLE` de una tabla.
     *
     * 🔴 Lo del AUTO_INCREMENT no es cosmética: media docena de seeders y toda la aritmética de
     * `semilla:datos` dan por sentado que los ids arrancan en 1 después de un `migrate:fresh` --
     * `AlineacionLocalDemoTest` fija los conceptos de compensación de caja en los ids 7 a 10, por
     * ejemplo. Un volcado tomado de una base que alguna vez tuvo filas arrastra el contador y
     * desplaza todos esos ids sin que nada falle ruidoso.
     *
     * @param string $tabla
     * @param bool $sin_contador `false` devuelve el DDL crudo, con la cláusula `AUTO_INCREMENT=N` si
     *                           el servidor la puso -- que es la única forma confiable de saber el
     *                           valor del contador (ver `estado_del_esquema()`).
     * @return string
     */
    protected static function ddl_de($tabla, $sin_contador = true)
    {
        $filas = DB::select('SHOW CREATE TABLE `' . str_replace('`', '', $tabla) . '`');

        if (empty($filas)) {
            throw new \RuntimeException('SHOW CREATE TABLE no devolvió nada para ' . $tabla);
        }

        $fila = (array) $filas[0];

        /* La clave es `Create Table`, pero el casing lo decide el servidor: se busca por contenido. */
        $ddl = null;

        foreach ($fila as $clave => $valor) {
            if (stripos($clave, 'create') !== false && is_string($valor)) {
                $ddl = $valor;
                break;
            }
        }

        if (is_null($ddl)) {
            throw new \RuntimeException('No se pudo leer el DDL de ' . $tabla);
        }

        if (! $sin_contador) {
            return $ddl;
        }

        return preg_replace('/\sAUTO_INCREMENT=\d+/', '', $ddl);
    }

    /**
     * Los `INSERT` de `migrations`, en lotes.
     *
     * `batch` se normaliza a **1** para todas: es lo que deja un `migrate:fresh`, que es el estado
     * que este volcado tiene que reproducir. Si se copiara el `batch` de la base de origen, un
     * volcado tomado de una instancia que recibió migraciones de a poco (un `migrate` por
     * despliegue, que es lo que pasa en un cliente real) dejaría treinta lotes distintos y un
     * `migrate:rollback` se llevaría puesta una sola migración en vez del esquema entero.
     *
     * Las filas van ORDENADAS por nombre, que es el mismo orden en el que Laravel corre las
     * migraciones: así los `migrations.id` quedan iguales a los de un `migrate:fresh`.
     *
     * @param string[] $migraciones
     * @return string[]
     */
    protected static function inserts_de_migrations(array $migraciones)
    {
        $sentencias = [];
        $lotes = array_chunk($migraciones, 200);

        foreach ($lotes as $lote) {
            $valores = [];

            foreach ($lote as $nombre) {
                $valores[] = '(' . self::entrecomillar($nombre) . ', 1)';
            }

            $sentencias[] = 'INSERT INTO `migrations` (`migration`, `batch`) VALUES '
                . implode(', ', $valores);
        }

        return $sentencias;
    }

    /**
     * Los `INSERT` de las filas de una tabla, en lotes, con las columnas nombradas y el `id`
     * explícito. Son tablas de catálogo de un puñado de filas (medido el 15/10/2026: ninguna pasa
     * de 4).
     *
     * Los valores se escapan con `PDO::quote()` de la misma conexión, que es el escapado que
     * corresponde al juego de caracteres del servidor; `NULL` va como `NULL`.
     *
     * @param string $tabla
     * @return string[]
     */
    public static function inserts_de_datos($tabla)
    {
        $limpia = str_replace('`', '', $tabla);
        $pdo = DB::connection()->getPdo();
        $sentencias = [];

        $filas = DB::select('select * from `' . $limpia . '`');

        foreach (array_chunk($filas, 200) as $lote) {
            $columnas = array_keys((array) $lote[0]);
            $valores = [];

            foreach ($lote as $fila) {
                $partes = [];

                foreach ((array) $fila as $valor) {
                    $partes[] = is_null($valor) ? 'NULL' : $pdo->quote((string) $valor);
                }

                $valores[] = '(' . implode(', ', $partes) . ')';
            }

            $sentencias[] = 'INSERT INTO `' . $limpia . '` (`' . implode('`, `', $columnas)
                . '`) VALUES ' . implode(', ', $valores);
        }

        return $sentencias;
    }

    /**
     * Comilla simple un literal para SQL. Los nombres de migración y de tabla son `[a-z0-9_]`, así
     * que esto es una red y no la defensa principal -- pero un nombre de archivo lo elige una
     * persona y no hay ninguna razón para construir SQL a mano sin escapar.
     *
     * @param string $valor
     * @return string
     */
    protected static function entrecomillar($valor)
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $valor) . "'";
    }

    /**
     * Lee la cabecera `-- meta: {...}` del volcado sin cargar el archivo entero.
     *
     * @param string $ruta
     * @return array<string,mixed>|null
     */
    protected static function leer_meta($ruta)
    {
        /* Con `@`: un archivo que existe y no se puede leer tiene que caer al respaldo, no tirar. */
        $f = @fopen($ruta, 'r');

        if ($f === false) {
            return null;
        }

        $marca = rtrim((string) fgets($f), "\r\n");
        $linea = rtrim((string) fgets($f), "\r\n");

        fclose($f);

        if ($marca !== self::MARCA || strpos($linea, '-- meta: ') !== 0) {
            return null;
        }

        $meta = json_decode(substr($linea, strlen('-- meta: ')), true);

        if (! is_array($meta)
            || ! isset($meta['version'])
            || ! isset($meta['tablas'])
            || ! isset($meta['migraciones'])
        ) {
            return null;
        }

        return $meta;
    }

    /**
     * @param string $motivo
     * @return array{ok: bool, motivo: string, tablas: int, migraciones: int}
     */
    protected static function fallo($motivo)
    {
        Log::warning('EsquemaDemoSnapshotHelper: se usa migrate:fresh porque ' . $motivo);

        return ['ok' => false, 'motivo' => $motivo, 'tablas' => 0, 'migraciones' => 0];
    }
}
