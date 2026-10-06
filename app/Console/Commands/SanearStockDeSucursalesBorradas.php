<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\SyncToTNArticle;
use App\Services\TiendaNube\TiendaNubeSyncArticleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sanea el stock de los artículos que quedaron con filas "fantasma" de sucursales borradas
 * (misión sanear-stock-de-sucursales-borradas, 6/10/2026).
 *
 * ─── El problema ──────────────────────────────────────────────────────────────────────────────
 *
 * Cuando se borra una sucursal y después entra una venta, una anulación o una nota de crédito con
 * el `address_id` viejo (la cookie de la SPA de tres años, `users.address_id` colgado), el motor de
 * stock REABRE una fila de `address_article` / `address_article_variant` para la sucursal muerta.
 * `Article::addresses()` es un INNER JOIN y no la ve, pero `setArticleStockFromAddresses()` suma
 * `address_article.amount` crudo y sí la cuenta: `articles.stock` queda distinto de la suma de las
 * sucursales que el usuario ve. La misión `eliminar-sucursal-con-stock` impide fantasmas NUEVOS y
 * no repara los que ya existen: eso es lo que hace este comando.
 *
 * El criterio de qué es un fantasma, qué clase de artículo es y qué stock queda vive en
 * `FilasFantasmaDeSucursalHelper` (ver su encabezado). Este comando es solo la cáscara: opciones,
 * reporte, respaldo en disco y el bucle por lotes.
 *
 * ─── Los dos modos ────────────────────────────────────────────────────────────────────────────
 *
 *     php artisan stock:sanear-sucursales-borradas --user_id=38              # --ver (defecto)
 *     php artisan stock:sanear-sucursales-borradas --user_id=38 --aplicar    # escribe
 *
 *  - `--ver` (por defecto): SOLO LEE. No escribe en la base ni en archivos. Por dueño informa cuántas
 *    filas fantasma hay en cada pivot, cuántos artículos afectan (los de la papelera incluidos),
 *    cuántas unidades (con signo) y el desfase entre `articles.stock` y la suma de las sucursales
 *    vivas. Si llegan `--ver` y `--aplicar` juntos gana la lectura (mismo criterio que `--dry-run` en
 *    `sale:sanear-costo-de-linea`).
 *  - `--aplicar`: borra las filas fantasma, recalcula `articles.stock` con
 *    `ArticleHelper::setArticleStockFromAddresses()` y deja UN movimiento de stock por artículo cuyo
 *    stock global cambió (concepto "Actualizacion de deposito", observación "Baja de sucursal
 *    eliminada", como la limpieza manual de 3DTisk del 5/10/2026). Es idempotente (una segunda
 *    corrida no encuentra nada) y se puede cortar y retomar.
 *
 * ─── Seguridad ────────────────────────────────────────────────────────────────────────────────
 *
 * 🔴 Es plata de un negocio real (el stock). Por eso:
 *
 *  1. Una transacción por ARTÍCULO, nunca una gigante: no frena las ventas del cliente y una
 *     corrida cortada deja artículos terminados y artículos intactos (ver el helper).
 *  2. Respaldo write-ahead: antes de la PRIMERA escritura se abren la carpeta de salida y los dos
 *     archivos, y si no se puede el comando se niega a escribir una fila (exit 1). Cada artículo
 *     deja en `…-respaldo.jsonl` el estado completo que va a tocar ANTES de tocarlo, y en
 *     `…-reversion.sql` el bloque de SQL que lo devuelve a como estaba.
 *  3. Si el concepto "Actualizacion de deposito" no existe en la base, `--aplicar` aborta sin
 *     escribir nada: etiquetar el movimiento con un concepto ajeno corrompe el libro de stock
 *     (mismo criterio que `SetConcepto`).
 *  4. El dueño de cada artículo es `articles.user_id`, nunca `config('app.USER_ID')`: en una base
 *     compartida cada artículo se recalcula con SU dueño.
 *  5. `users.address_id` colgado se REPORTA y no se toca.
 *  6. Por defecto el comando no avisa a Tienda Nube ni a Mercado Libre del cambio de stock (un
 *     barrido masivo encolaría cientos de sincronizaciones): el stock publicado se corrige con la
 *     próxima sincronización o movimiento del artículo. `--sincronizar` (solo con `--aplicar`) marca
 *     para sincronizar con Tienda Nube a los artículos cuyo stock cambió, uno por uno y DESPUÉS del
 *     commit de cada uno (ver "Tienda Nube" más abajo). Mercado Libre no entra.
 *  7. Una opción numérica que llega VACÍA (`--user_id=`) o PELADA (`--user_id` sin valor), típico de un
 *     script con una variable sin valor, es un error, no "sin filtro".
 *  8. `--aplicar` sin `--user_id` ni `--articulo_id` sobre VARIOS dueños se niega (exit 1): hay que
 *     elegir uno o decir `--todos`. Con un solo dueño con trabajo no pide nada.
 *  9. Antes de escribir se verifica que las cinco tablas sean InnoDB (el rollback por artículo lo
 *     supone) y que existan los índices de los caminos de bloqueo; si no, no se escribe nada.
 * 10. La función del sistema (`setArticleStockFromAddresses`) solo se llama si el stock global TIENE
 *     que cambiar. Con el desfase en cero se borran los fantasmas y nada más (ver el helper).
 * 11. El reporte parte el desfase en lo que explican las filas fantasma y lo que NO (carga manual,
 *     importación, versión vieja), solo en los artículos que tienen algo que corregir.
 *     `--solo_explicados` deja afuera los artículos cuya corrección tiene una parte de lo segundo: esa
 *     parte llevaría la etiqueta "Baja de sucursal eliminada" sin tener nada que ver con las
 *     sucursales borradas. Un artículo que ya tiene el stock bien NO se saltea: solo hay que borrarle
 *     el fantasma (si quedara, el próximo movimiento del artículo lo volvería a sumar).
 * 12. Tras 10 artículos que fallan SEGUIDOS se corta: es un error del entorno, no del artículo.
 *
 * ─── Cómo conviene correrlo en un cliente real ────────────────────────────────────────────────
 *
 * 1. `--ver --user_id=N --detalle --sin_tope > detalle.txt`: mirar qué cambia y de dónde sale el desfase.
 * 2. Con `--aplicar --user_id=N --limite=20` en un cliente chico, bajar los dos archivos y mirarlos.
 * 3. Recién después, sin `--limite`. Con artículos de decenas de miles de filas fantasma: `--lote=20` y
 *    `php -d memory_limit=1G artisan ...` (el análisis de una tanda trae todas las filas de sus artículos).
 * 4. El detalle de los movimientos queda con la observación "Baja de sucursal eliminada - <stock>" (el
 *    motor le agrega el stock resultante): filtrar con `LIKE 'Baja de sucursal eliminada%'`.
 * 5. Si el cliente usa Tienda Nube, sumar `--sincronizar` desde la primera corrida (`--aplicar
 *    --sincronizar --limite=20`): una corrida SIN la opción deja los artículos limpios y este comando ya
 *    no los ve, así que después no hay cómo marcarlos con él (ver "Tienda Nube"). Y no subir el
 *    `--limite` hasta ver cuánto tarda el scheduler en vaciar la cola (ver "Lo que carga" más abajo).
 *
 * ─── Los archivos ─────────────────────────────────────────────────────────────────────────────
 *
 * Quedan en `storage/app/saneo-stock-sucursales-borradas/` (o en `--salida`), en el disco del
 * SERVIDOR donde corrió el comando, no en la máquina de quien lo lanzó por SSH. Si nadie los baja,
 * el día que haya que revertir no van a estar.
 *
 * ─── Tienda Nube (`--sincronizar`) ────────────────────────────────────────────────────────────
 *
 * Tienda Nube publica `articles.stock` (y solo eso: no el stock de las variantes ni el de cada
 * sucursal), así que un artículo cuyo stock global cambió queda desfasado allá hasta su próximo
 * movimiento. Con `--aplicar --sincronizar`, cada artículo saneado que dejó un movimiento (o sea, cuyo
 * stock global cambió) se marca con `TiendaNubeSyncArticleService::add_article_to_sync()`, la misma
 * función que usan la venta, el movimiento de stock y la categorización con IA:
 *
 *  - Eso NO llama a la API: inserta una fila pendiente en `sync_to_t_n_articles`. La API la llama
 *    después el scheduler del cliente (`sync_articles_to_tienda_nube`, cada minuto, solo si el dueño de
 *    la instancia tiene la extensión `usa_tienda_nube`).
 *  - Se marca DESPUÉS del commit de cada artículo y fuera de su transacción: un artículo que falla y
 *    se revierte no se marca, y un corte (`--limite`, fallidos seguidos, error de respaldo) no deja
 *    artículos ya saneados sin marcar.
 *  - No se marcan los artículos de la papelera: el scheduler carga el artículo de la fila sin la
 *    papelera y le pasa `null` a `crearOActualizarProducto(Article $article)`: un `TypeError` que
 *    `sync_article()` no atrapa (solo atrapa `\Exception`), así que aborta esa corrida del scheduler y
 *    deja la fila en `en_progreso`.
 *  - 🔴 Con `USA_TIENDA_NUBE` apagado en la instalación, `--sincronizar` SE NIEGA a escribir (exit 1,
 *    como una precondición más): `add_article_to_sync()` no encolaría nada, el saneo se haría igual y
 *    después los artículos quedarían limpios, así que este comando ya no los vería y no habría cómo
 *    marcarlos con él. La condición es la misma expresión que lee el servicio
 *    (`env('USA_TIENDA_NUBE', false)`); con `config:cache` esa variable da apagado también para el servicio.
 *  - Lo demás —que el artículo esté o no en Tienda Nube (`tiendanube_product_id` o
 *    `disponible_tienda_nube`)— lo decide `add_article_to_sync()`; el comando solo cuenta cuántos
 *    quedaron con una fila pendiente. No hay filtro por dueño: en una base compartida con `--todos`,
 *    lo que se marca depende de cada artículo y no de si su dueño usa Tienda Nube.
 *  - Un fallo al marcar NO revierte ni frena nada (el artículo ya está saneado): se cuenta, se intenta
 *    anotar en el respaldo (`tn_error`) y se lista al final con los ids; la corrida termina con exit 1.
 *    Re-correr no los reintenta (ya están limpios): se vuelven a marcar a mano con
 *    `TiendaNubeSyncArticleService::add_article_to_sync(Article::find($id))` desde `php artisan tinker`.
 *  - Mercado Libre no entra (`ProductService::add_article_to_sync()` sería el análogo).
 *  - Si después se corre el `.sql` de reversión, el stock vuelve a quedar desfasado en Tienda Nube:
 *    no se automatiza.
 *
 * Lo que carga (leído del código del scheduler y del servicio de Tienda Nube, NO medido):
 *
 *  - Cada artículo marcado son varias llamadas a la API (el producto, su variante, las descripciones y
 *    las imágenes), y el scheduler las hace en serie y sin pausa. Cada fallo le llega al dueño como
 *    notificación.
 *  - El scheduler corre en primer plano con `withoutOverlapping(15)`: si una corrida tarda más de 15
 *    minutos el candado vence, arranca otra y reprocesa filas que la anterior no alcanzó (un artículo
 *    que está solo `disponible_tienda_nube`, o sea que todavía no está en Tienda Nube, podría
 *    crearse dos veces). En el shared hosting además se mata el proceso a los 30 minutos y la fila
 *    queda en `en_progreso` para siempre.
 *  - Por eso: `--limite` chico, y esperar a que las pendientes bajen a 0 entre una corrida y la
 *    siguiente (`SELECT status, COUNT(*) FROM sync_to_t_n_articles WHERE user_id = N GROUP BY status`).
 *
 * Dos salvedades del lado de Tienda Nube (previas a este comando, valen para cualquier movimiento):
 *
 *  - El stock viaja en el PUT de la VARIANTE, y `actualizar()` lo hace solo si el artículo tiene
 *    `tiendanube_variant_id`: uno con `tiendanube_product_id` y sin variante se sincroniza sin stock.
 *  - Un artículo con solo `disponible_tienda_nube` (no está en Tienda Nube todavía) se CREA allá al
 *    sincronizarlo.
 *  - Se marcan todos los que cambiaron de stock aunque lo que ve Tienda Nube (un entero, con el
 *    negativo en 0) no cambie: cuesta llamadas de más y no deja nada desfasado. No se copia esa regla
 *    acá para no quedar desfasados el día que el servicio la cambie.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class SanearStockDeSucursalesBorradas extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stock:sanear-sucursales-borradas
                            {--ver : Solo lee y reporta. Es el modo por defecto; no escribe nada (ni archivos).}
                            {--aplicar : Borra las filas fantasma, recalcula articles.stock y deja un movimiento por artículo cuyo stock cambió.}
                            {--user_id= : Acota a un dueño (articles.user_id). Vacío o sin valor es un error.}
                            {--articulo_id= : Acota a un artículo.}
                            {--todos : Con --aplicar y sin --user_id, permite sanear a VARIOS dueños de una base compartida a la vez. Con un solo dueño con trabajo no hace falta.}
                            {--solo_explicados : Con --aplicar, saltea los artículos cuyo stock hay que corregir y esa corrección tiene una parte que no explican los fantasmas (se listan con --ver --detalle).}
                            {--sincronizar : Con --aplicar, marca para sincronizar con Tienda Nube los artículos cuyo stock cambió (los que están en Tienda Nube; se niega si USA_TIENDA_NUBE está apagado). El scheduler del cliente los sube en serie y sin pausa: conviene --limite chico.}
                            {--limite= : Corta después de N artículos saneados o fallidos (solo --aplicar). Los saltados y los ya limpios no cuentan.}
                            {--lote=200 : Artículos por tanda (con artículos de decenas de miles de filas fantasma, uno chico: 20).}
                            {--detalle : Lista artículo por artículo (hasta 200 líneas).}
                            {--sin_tope : Con --detalle, lista TODOS los artículos (sin cortar a las 200 líneas).}
                            {--salida= : Carpeta del respaldo y del SQL de reversión (solo --aplicar). Por defecto storage/app/saneo-stock-sucursales-borradas.}';

    /**
     * @var string
     */
    protected $description = 'Sanea el stock de artículos con filas fantasma de sucursales borradas (address_article / address_article_variant). --ver por defecto; --aplicar escribe con respaldo y SQL de reversión.';

    /**
     * Código de excepción que marca "no se pudo escribir el respaldo": es FATAL para la corrida
     * (no se sigue sin respaldo), a diferencia de cualquier otro error, que solo descarta el artículo.
     */
    const CODIGO_RESPALDO = 9001;

    /** Tope de líneas de `--detalle`. */
    const TOPE_DETALLE = 200;

    /** Cuántas sucursales borradas se listan en el reporte. */
    const TOPE_SUCURSALES_MUERTAS = 10;

    /** Cuántos ids de usuario se listan por dueño en el aviso de `users.address_id` colgado. */
    const TOPE_USUARIOS_COLGADOS = 10;

    /** Cuántos dueños se listan en el error de `--aplicar` sin `--user_id` sobre varios dueños. */
    const TOPE_DUENOS_LISTADOS = 10;

    /**
     * Artículos que fallan SEGUIDOS antes de cortar la corrida. Una falla de a un artículo se
     * descarta y se sigue; pero una falla sistemática (una columna que falta, un permiso, un
     * concepto) recorrería toda la lista dejando dos líneas de respaldo por artículo y nada hecho.
     */
    const TOPE_FALLIDOS_SEGUIDOS = 10;

    /**
     * Las tablas que cada artículo toca dentro de su transacción. Si alguna no es InnoDB (en MyISAM
     * `BEGIN` y `ROLLBACK` son no-ops) el "falló y se revirtió" sería mentira: un error después del
     * `DELETE` dejaría filas borradas y stock reescrito.
     */
    const TABLAS_TRANSACCIONALES = ['articles', 'article_variants', 'address_article', 'address_article_variant', 'stock_movements'];

    /**
     * Las columnas por las que se bloquea (`FOR UPDATE ... WHERE <columna> IN (...)`): sin un índice
     * que empiece por esa columna cada artículo escanea y bloquea el pivot entero y frena las
     * ventas del cliente (la migración `2026_08_19_120000_add_indexes_to_address_article_tables` los
     * crea).
     */
    const COLUMNAS_DE_BLOQUEO = [
        ['address_article', 'article_id'],
        ['address_article_variant', 'article_variant_id'],
        ['article_variants', 'article_id'],
    ];

    /** `--sincronizar`: el artículo quedó con una fila pendiente en `sync_to_t_n_articles` (nueva o ya existente). */
    const TN_MARCADO = 'marcado';

    /** `--sincronizar`: `add_article_to_sync()` no dejó ninguna fila pendiente (el artículo no está en Tienda Nube, o la instalación no la usa). */
    const TN_NO_SE_SUBE = 'no_se_sube';

    /** @var resource|null  Manejador de `…-respaldo.jsonl`. */
    private $manejador_respaldo = null;

    /** @var resource|null  Manejador de `…-reversion.sql`. */
    private $manejador_sql = null;

    /** @var string|null  Ruta de `…-respaldo.jsonl`. */
    private $ruta_respaldo = null;

    /** @var string|null  Ruta de `…-reversion.sql`. */
    private $ruta_sql = null;

    /**
     * true si el bloque de reversión del artículo en curso ya se escribió en el SQL. Si el artículo
     * falla DESPUÉS de eso (el commit mismo), la línea `revertido_por_error` lo avisa para que quien
     * revierta sepa que ese bloque no corresponde a nada.
     *
     * @var bool
     */
    private $bloque_sql_escrito = false;

    /**
     * @return int
     */
    public function handle()
    {
        $opciones = $this->leer_opciones();

        if ($opciones === false) {
            return 1;
        }

        // 🔴 --ver gana: si alguien escribe los dos, lo más seguro es que no se escriba nada.
        $aplicar = $opciones['aplicar'] && !$opciones['ver'];

        $this->info('Saneo de stock: filas fantasma de sucursales borradas (address_article / address_article_variant).');
        $this->line('Base: ' . DB::connection()->getDatabaseName());

        if ($opciones['aplicar'] && $opciones['ver']) {
            $this->warn('Llegaron --ver y --aplicar juntos: gana la lectura, no se escribe nada.');
        }

        $this->line($aplicar
            ? 'Modo: APLICAR (borra filas, recalcula articles.stock y deja movimientos).'
            : 'Modo: VER (solo lee: no escribe en la base ni en archivos).');

        $this->line('Alcance: dueño ' . (is_null($opciones['user_id']) ? 'todos' : $opciones['user_id'])
            . ' · artículo ' . (is_null($opciones['articulo_id']) ? 'todos' : $opciones['articulo_id'])
            . ' · lote ' . $opciones['lote']
            . ($opciones['solo_explicados'] ? ' · solo explicados' : '')
            . ($aplicar && $opciones['sincronizar'] ? ' · sincroniza con Tienda Nube' : '') . '.');

        if (!$aplicar) {
            if (!is_null($opciones['limite'])) {
                $this->comment('--limite solo cuenta en --aplicar: en --ver se mide todo.');
            }

            if ($opciones['sincronizar']) {
                $this->comment('--sincronizar solo cuenta en --aplicar: --ver no marca nada para Tienda Nube.');
            }

            if (!is_null($opciones['salida'])) {
                $this->comment('--salida solo cuenta en --aplicar: --ver no escribe archivos.');
            }

            if ($opciones['todos']) {
                $this->comment('--todos solo cuenta en --aplicar: el alcance de --ver lo fijan --user_id y --articulo_id.');
            }
        }

        // Ids de los artículos con al menos un fantasma. Solo ids: el análisis va por tandas.
        $ids = FilasFantasmaDeSucursalHelper::ids_de_articulos_afectados($opciones['user_id'], $opciones['articulo_id']);

        // La medición es la MISMA en los dos modos (misma función del helper que usa el saneo).
        $medicion = $this->medir($ids, $opciones, !$aplicar && $opciones['detalle']);

        $this->reportar_medicion($medicion, $opciones);

        if (!$aplicar) {
            // Lo que le impediría escribir a `--aplicar`, anticipado: que el dry-run diga "todo bien" y
            // `--aplicar` se niegue después es peor que avisar acá. Con la MISMA condición que `--aplicar`:
            // sin `trabajo` (artículos que puede tocar) sale con "No hay nada para sanear" antes de
            // cualquier precondición, así que no hay negativa que anticipar. Los `no_recalculable` y los
            // saltados son artículos afectados pero no son trabajo.
            if (count($medicion['trabajo']) > 0) {
                // Sin el concepto el comando se niega a escribir.
                if (is_null(ConceptoStockMovement::where('name', FilasFantasmaDeSucursalHelper::CONCEPTO)->value('id'))) {
                    $this->warn('Ojo: no existe el concepto de stock "' . FilasFantasmaDeSucursalHelper::CONCEPTO . '" en esta base: --aplicar se negaría a escribir (exit 1) hasta que se cargue.');
                }

                // Y lo mismo con el motor de las tablas y los índices (la precondición 3 de --aplicar).
                $problemas = $this->problemas_de_motor_e_indices();

                if (count($problemas) > 0) {
                    $this->warn('Ojo: --aplicar se negaría a escribir en esta base (exit 1) hasta que se resuelva:');

                    foreach ($problemas as $problema) {
                        $this->line('  - ' . $problema . '.');
                    }
                }

                // Y la precondición 4: con `--sincronizar` y la instalación sin Tienda Nube.
                if ($opciones['sincronizar'] && !$this->tienda_nube_prendida()) {
                    $this->warn('Ojo: --aplicar --sincronizar se negaría a escribir (exit 1): USA_TIENDA_NUBE está apagado en esta instalación y add_article_to_sync() no encolaría nada.');
                }
            }

            $this->line('');
            $this->info('Modo ver: no se escribió nada. Para sanear, corré el mismo comando con --aplicar'
                . (is_null($opciones['user_id']) ? ' (conviene de a un dueño: --user_id=N).' : '.'));

            return 0;
        }

        return $this->aplicar($medicion, $opciones);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  OPCIONES
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Lee y valida las opciones.
     *
     * @return array|false  false si alguna es inválida (ya se imprimió el error: el comando sale con 1).
     */
    private function leer_opciones()
    {
        $user_id = $this->entero_de_opcion('user_id');
        $articulo_id = $this->entero_de_opcion('articulo_id');
        $limite = $this->entero_de_opcion('limite');
        $lote = $this->entero_de_opcion('lote');

        if ($user_id === false || $articulo_id === false || $limite === false || $lote === false) {
            return false;
        }

        $salida = $this->option('salida');

        return [
            'ver' => (bool) $this->option('ver'),
            'aplicar' => (bool) $this->option('aplicar'),
            'user_id' => $user_id,
            'articulo_id' => $articulo_id,
            'todos' => (bool) $this->option('todos'),
            'solo_explicados' => (bool) $this->option('solo_explicados'),
            'sincronizar' => (bool) $this->option('sincronizar'),
            'limite' => $limite,
            'lote' => is_null($lote) ? 200 : $lote,
            'detalle' => (bool) $this->option('detalle'),
            'sin_tope' => (bool) $this->option('sin_tope'),
            'salida' => ($salida === null || $salida === '') ? null : (string) $salida,
        ];
    }

    /**
     * Una opción numérica que tiene que ser un entero positivo.
     *
     * 🔴 Una opción que LLEGA VACÍA (`--user_id=`, típico de un script con una variable sin valor)
     * es un error, no "no se pasó": si valiera como ausente, `--aplicar --user_id=$ID` con `$ID`
     * vacío saneaba a TODOS los dueños de la base (51 comercios en la base compartida del shared).
     *
     * 🔴 Lo mismo si llega PELADA (`--user_id` sin `=` ni valor: lo que pasa con `--user_id $ID` y
     * `$ID` vacío sin comillas). Laravel declara estas opciones como VALUE_OPTIONAL y la pelada llega
     * como `null`, igual que si no se hubiera escrito: por eso se distingue mirando si figura en la
     * línea de comandos.
     *
     * @param  string  $nombre
     * @return int|null|false  null si no vino, false si es inválida (con el error ya impreso).
     */
    private function entero_de_opcion($nombre)
    {
        $valor = $this->option($nombre);

        if ($valor === null) {
            if ($this->input->hasParameterOption('--' . $nombre)) {
                $this->error('--' . $nombre . ' tiene que ser un entero positivo. Llegó: (sin valor)');

                return false;
            }

            return null;
        }

        if ($valor === '' || !is_scalar($valor) || !ctype_digit((string) $valor) || (int) $valor < 1) {
            $this->error('--' . $nombre . ' tiene que ser un entero positivo. Llegó: ' . ($valor === '' ? '(vacío)' : (is_scalar($valor) ? $valor : gettype($valor))));

            return false;
        }

        return (int) $valor;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  MEDICIÓN (solo lectura: la hacen los dos modos)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Mide los artículos afectados por tandas y acumula los totales por dueño.
     *
     * @param  int[]  $ids          Ids de `ids_de_articulos_afectados()`.
     * @param  array  $opciones     Las opciones leídas (`lote`, `solo_explicados`, `sin_tope`).
     * @param  bool   $con_detalle  Si se juntan las líneas de `--detalle` (solo en `--ver`).
     * @return array  Ver las claves en el cuerpo.
     */
    private function medir(array $ids, array $opciones, $con_detalle)
    {
        $medicion = [
            // dueño => totales (ver fila_vacia()).
            'por_dueno' => [],
            'total' => $this->fila_vacia(),
            // Ids que `--aplicar` va a trabajar: los `recalcular` y `solo_fantasmas` (menos los que
            // `--solo_explicados` saltea).
            'trabajo' => [],
            // dueño => cantidad de artículos de `trabajo`. Es lo que mira `--aplicar` sin
            // `--user_id` para negarse a tocar a varios dueños de una base compartida a la vez.
            'duenos_con_trabajo' => [],
            // Artículos de `trabajo` en los que la función del sistema reconstruiría el pivot del
            // artículo (tienen variantes con depósitos y desfase): pierden stock_min/stock_max.
            'reconstruyen_pivot' => 0,
            'clases' => [
                FilasFantasmaDeSucursalHelper::CLASE_RECALCULAR => 0,
                FilasFantasmaDeSucursalHelper::CLASE_SOLO_FANTASMAS => 0,
                FilasFantasmaDeSucursalHelper::CLASE_NO_RECALCULABLE => 0,
            ],
            // motivo => cantidad de artículos que `--aplicar` saltearía.
            'saltados' => [],
            // address_id muerto => ['filas', 'unidades'] (las dos tablas juntas).
            'muertas' => [],
            // Unidades en filas fantasma de artículos `solo_fantasmas` (stock global: no se corrigen).
            'unidades_solo_fantasmas' => 0.0,
            'detalle' => [],
            'detalle_omitido' => 0,
        ];

        foreach (array_chunk($ids, $opciones['lote']) as $tanda) {

            // Una lectura por tanda, sin candados: --ver no puede frenar a nadie.
            $analisis = FilasFantasmaDeSucursalHelper::analizar($tanda, false);

            foreach ($tanda as $id) {

                // El artículo desapareció entre la lista de ids y esta lectura, o ya no tiene fantasmas.
                if (!isset($analisis[$id]) || is_null($analisis[$id]['clase'])) {
                    continue;
                }

                $a = $analisis[$id];

                $dueno = is_null($a['user_id']) ? 0 : $a['user_id'];

                if (!isset($medicion['por_dueno'][$dueno])) {
                    $medicion['por_dueno'][$dueno] = $this->fila_vacia();
                }

                $this->acumular($medicion['por_dueno'][$dueno], $a);
                $this->acumular($medicion['total'], $a);

                $medicion['clases'][$a['clase']]++;

                if ($a['clase'] === FilasFantasmaDeSucursalHelper::CLASE_NO_RECALCULABLE) {
                    $this->anotar_salteado($medicion, $a['motivo']);
                } elseif ($opciones['solo_explicados'] && FilasFantasmaDeSucursalHelper::es_desvio_ajeno($a)) {
                    // El MISMO criterio que aplica el helper bajo el candado (una sola definición):
                    // acá solo adelanta el reporte.
                    $this->anotar_salteado($medicion, FilasFantasmaDeSucursalHelper::MOTIVO_DESVIO_NO_EXPLICADO);
                } else {
                    $medicion['trabajo'][] = $id;

                    if (!isset($medicion['duenos_con_trabajo'][$dueno])) {
                        $medicion['duenos_con_trabajo'][$dueno] = 0;
                    }

                    $medicion['duenos_con_trabajo'][$dueno]++;

                    if ($a['reconstruye_pivot']) {
                        $medicion['reconstruyen_pivot']++;
                    }
                }

                if ($a['clase'] === FilasFantasmaDeSucursalHelper::CLASE_SOLO_FANTASMAS) {
                    $medicion['unidades_solo_fantasmas'] = round($medicion['unidades_solo_fantasmas'] + $a['unidades_fantasma_articulo'] + $a['unidades_fantasma_variante'], 2);
                }

                foreach ($a['sucursales_muertas'] as $address_id => $datos) {
                    if (!isset($medicion['muertas'][$address_id])) {
                        $medicion['muertas'][$address_id] = ['filas' => 0, 'unidades' => 0.0];
                    }

                    $medicion['muertas'][$address_id]['filas'] += $datos['filas'];
                    $medicion['muertas'][$address_id]['unidades'] = round($medicion['muertas'][$address_id]['unidades'] + $datos['unidades'], 2);
                }

                if ($con_detalle) {
                    if ($opciones['sin_tope'] || count($medicion['detalle']) < self::TOPE_DETALLE) {
                        $medicion['detalle'][] = $this->linea_de_detalle_de_medicion($a);
                    } else {
                        $medicion['detalle_omitido']++;
                    }
                }
            }
        }

        ksort($medicion['por_dueno']);

        return $medicion;
    }

    /**
     * Anota un artículo que `--aplicar` NO va a tocar, con su motivo.
     *
     * @param  array   $medicion  La medición en curso (por referencia: se le suma el motivo).
     * @param  string  $motivo
     * @return void
     */
    private function anotar_salteado(array &$medicion, $motivo)
    {
        if (!isset($medicion['saltados'][$motivo])) {
            $medicion['saltados'][$motivo] = 0;
        }

        $medicion['saltados'][$motivo]++;
    }

    /**
     * Totales en cero de un dueño (o del total general).
     *
     * Las tres últimas claves parten el desfase por causa (ver `acumular()`): `desfase_otra_causa` es la
     * parte de la corrección que NO explican las filas fantasma (solo de los artículos con
     * `es_desvio_ajeno()`), `desfase_fantasmas` es el resto del desfase (`desfase − otra causa`) y
     * `articulos_otra_causa` cuenta esos artículos. Los dos montos suman siempre el desfase.
     * No son columnas de la tabla por dueño (esa tiene su forma fija): salen en una tabla aparte.
     *
     * @return array
     */
    private function fila_vacia()
    {
        return [
            'articulos' => 0,
            'papelera' => 0,
            'filas_articulo' => 0,
            'filas_variante' => 0,
            'unidades_articulo' => 0.0,
            'unidades_variante' => 0.0,
            'desfase' => 0.0,
            'desfase_fantasmas' => 0.0,
            'desfase_otra_causa' => 0.0,
            'articulos_otra_causa' => 0,
        ];
    }

    /**
     * Suma un artículo a unos totales.
     *
     * @param  array  $fila  Totales (por referencia).
     * @param  array  $a     Análisis del artículo.
     * @return void
     */
    private function acumular(array &$fila, array $a)
    {
        $fila['articulos']++;

        if ($a['en_papelera']) {
            $fila['papelera']++;
        }

        $fila['filas_articulo'] += count($a['filas_fantasma_articulo']);
        $fila['filas_variante'] += count($a['filas_fantasma_variante']);
        $fila['unidades_articulo'] = round($fila['unidades_articulo'] + $a['unidades_fantasma_articulo'], 2);
        $fila['unidades_variante'] = round($fila['unidades_variante'] + $a['unidades_fantasma_variante'], 2);
        $fila['desfase'] = round($fila['desfase'] + $a['desfase'], 2);

        // El desfase se parte por causa SOLO donde hay una corrección que repartir, con el mismo criterio
        // que `--solo_explicados` (una sola definición: `es_desvio_ajeno()`). Un artículo cuyo stock ya
        // está bien (alguien lo corrigió a mano) no tiene corrección: sumaría "−2 por fantasmas, +2 por
        // otra causa", que da cero pero deja un monto de otra causa sin ningún artículo detrás. Así las
        // dos causas siguen sumando el desfase y "otra causa" es distinto de cero solo si hay artículos
        // con otra causa.
        $desvio_ajeno = FilasFantasmaDeSucursalHelper::es_desvio_ajeno($a);
        $otra_causa = $desvio_ajeno ? $a['desfase_inexplicado'] : 0.0;

        $fila['desfase_otra_causa'] = round($fila['desfase_otra_causa'] + $otra_causa, 2);
        $fila['desfase_fantasmas'] = round($fila['desfase_fantasmas'] + $a['desfase'] - $otra_causa, 2);

        if ($desvio_ajeno) {
            $fila['articulos_otra_causa']++;
        }
    }

    /**
     * La línea de `--detalle` de un artículo medido.
     *
     * @param  array  $a
     * @return string
     */
    private function linea_de_detalle_de_medicion(array $a)
    {
        // Si la corrección tiene una parte que NO explican los fantasmas, la línea la muestra: es lo que
        // `--solo_explicados` deja afuera (mismo criterio, `es_desvio_ajeno()`) y lo que conviene mirar
        // antes de `--aplicar`.
        $otra_causa = FilasFantasmaDeSucursalHelper::es_desvio_ajeno($a)
            ? ', de otra causa ' . $this->con_signo($a['desfase_inexplicado'])
            : '';

        $stock = is_null($a['stock_proyectado'])
            ? 'stock ' . $this->numero($a['stock_actual']) . ' (no se recalcula)'
            : 'stock ' . $this->numero($a['stock_actual']) . ' → ' . $this->numero($a['stock_proyectado'])
                . ' (desfase ' . $this->con_signo($a['desfase']) . $otra_causa . ')'
                . ($a['reconstruye_pivot'] ? ' · reconstruye el pivot' : '');

        return '  art ' . $a['article_id']
            . ' · dueño ' . $a['user_id']
            . ($a['en_papelera'] ? ' · papelera' : '')
            . ' · ' . $a['clase']
            . (is_null($a['motivo']) ? '' : ' (' . $a['motivo'] . ')')
            . ' · filas ' . count($a['filas_fantasma_articulo']) . '+' . count($a['filas_fantasma_variante'])
            . ' · unid ' . $this->con_signo($a['unidades_fantasma_articulo']) . '/' . $this->con_signo($a['unidades_fantasma_variante'])
            . ' · ' . $stock;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  REPORTE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Imprime lo medido: la tabla por dueño y las líneas complementarias.
     *
     * @param  array  $m         Resultado de `medir()`.
     * @param  array  $opciones  Opciones leídas.
     * @return void
     */
    private function reportar_medicion(array $m, array $opciones)
    {
        $this->line('');

        if ($m['total']['articulos'] === 0) {
            $this->info('No hay artículos con filas fantasma para este alcance.');
        } else {
            $filas = [];

            foreach ($m['por_dueno'] as $dueno => $totales) {
                $filas[] = $this->fila_de_tabla($dueno === 0 ? '(sin dueño)' : (string) $dueno, $totales);
            }

            $filas[] = $this->fila_de_tabla('TOTAL', $m['total']);

            $this->table(
                ['dueño', 'filas art.', 'filas var.', 'artículos', 'papelera', 'unid. art.', 'unid. var.', 'desfase'],
                $filas
            );

            $this->line('Unidades: suma CON SIGNO de los amount de las filas fantasma. Desfase = articles.stock − suma de las sucursales vivas (lo que sobra o falta hoy).');
            $this->line('Artículos por clase: recalcular ' . $m['clases'][FilasFantasmaDeSucursalHelper::CLASE_RECALCULAR]
                . ' · solo_fantasmas ' . $m['clases'][FilasFantasmaDeSucursalHelper::CLASE_SOLO_FANTASMAS]
                . ' · no_recalculable ' . $m['clases'][FilasFantasmaDeSucursalHelper::CLASE_NO_RECALCULABLE] . '.');

            if ($m['clases'][FilasFantasmaDeSucursalHelper::CLASE_SOLO_FANTASMAS] > 0) {
                $this->line('  solo_fantasmas: no tienen ninguna sucursal viva (llevan stock global). Se borran sus filas pero articles.stock NO se toca ('
                    . $this->con_signo($m['unidades_solo_fantasmas']) . ' unidades en esas filas).');
            }

            $this->reportar_origen_del_desfase($m);

            if ($m['reconstruyen_pivot'] > 0) {
                $this->line('  Artículos con variantes con depósitos y desfase: ' . $m['reconstruyen_pivot'] . '. --aplicar los recalcula con la función del sistema, que RECONSTRUYE el pivot del artículo desde sus variantes'
                    . ' (pierden stock_min/stock_max por sucursal y las filas del artículo en domicilios de comprador; el respaldo las guarda).');
            }

            foreach ($m['saltados'] as $motivo => $cantidad) {
                $this->warn($this->texto_de_salteados($motivo, $cantidad));
            }

            $this->reportar_sucursales_muertas($m['muertas']);
        }

        $this->reportar_lineas_complementarias($opciones);

        if (count($m['detalle']) > 0) {
            $this->line('');
            $this->line('Detalle por artículo:');

            foreach ($m['detalle'] as $linea) {
                $this->line($linea);
            }

            if ($m['detalle_omitido'] > 0) {
                $this->comment('  ... y ' . $m['detalle_omitido'] . ' artículos más (el detalle se corta a las ' . self::TOPE_DETALLE . ' líneas; --sin_tope las lista todas).');
            }
        }
    }

    /**
     * Parte el desfase por causa: cuánto lo explican las filas fantasma y cuánto viene de otra cosa
     * (carga manual, importación, una versión vieja). Es lo que permite decidir si conviene
     * `--solo_explicados`: sin esto, un desvío que no tiene nada que ver con las sucursales borradas
     * se corregiría con la etiqueta "Baja de sucursal eliminada" y nadie lo vería venir.
     *
     * @param  array  $m  Resultado de `medir()`.
     * @return void
     */
    private function reportar_origen_del_desfase(array $m)
    {
        if ($m['total']['articulos_otra_causa'] === 0) {
            $this->line('Origen del desfase: lo que hay para corregir lo explican por completo las filas fantasma.');

            return;
        }

        $filas = [];

        foreach ($m['por_dueno'] as $dueno => $totales) {
            $filas[] = $this->fila_de_origen($dueno === 0 ? '(sin dueño)' : (string) $dueno, $totales);
        }

        $filas[] = $this->fila_de_origen('TOTAL', $m['total']);

        $this->line('');
        $this->line('Origen del desfase:');
        $this->table(['dueño', 'desfase', 'por filas fantasma', 'por otra causa', 'artículos con otra causa'], $filas);

        $this->warn('  ' . $m['total']['articulos_otra_causa'] . ' artículos tenían el stock distinto de la suma de sus filas ANTES de los fantasmas (carga manual, importación, versión vieja).'
            . ' --aplicar los corrige igual y con la misma etiqueta ("' . FilasFantasmaDeSucursalHelper::OBSERVACION . '");'
            . ' con --solo_explicados se saltean y quedan para revisar (se listan con --ver --detalle --sin_tope).');
    }

    /**
     * Una fila de la tabla "Origen del desfase".
     *
     * @param  string  $titulo
     * @param  array   $t       Totales (ver fila_vacia()).
     * @return array
     */
    private function fila_de_origen($titulo, array $t)
    {
        return [
            $titulo,
            $this->con_signo($t['desfase']),
            $this->con_signo($t['desfase_fantasmas']),
            $this->con_signo($t['desfase_otra_causa']),
            $t['articulos_otra_causa'],
        ];
    }

    /**
     * El aviso de los artículos que `--aplicar` no va a tocar, según el motivo.
     *
     * @param  string  $motivo
     * @param  int     $cantidad
     * @return string
     */
    private function texto_de_salteados($motivo, $cantidad)
    {
        if ($motivo === FilasFantasmaDeSucursalHelper::MOTIVO_DESVIO_NO_EXPLICADO) {
            return '  --solo_explicados: ' . $cantidad . ' artículos con un desvío de stock que no explican los fantasmas. --aplicar NO los toca (quedan con sus filas fantasma): revisalos con --ver --detalle.';
        }

        return '  no_recalculable / ' . $motivo . ': ' . $cantidad . ' artículos. --aplicar NO los toca (el motor tiraría Undefined index): revisalos a mano (se listan con --ver --detalle).';
    }

    /**
     * Una fila de la tabla por dueño.
     *
     * @param  string  $titulo
     * @param  array   $t       Totales (ver fila_vacia()).
     * @return array
     */
    private function fila_de_tabla($titulo, array $t)
    {
        return [
            $titulo,
            $t['filas_articulo'],
            $t['filas_variante'],
            $t['articulos'],
            $t['papelera'],
            $this->con_signo($t['unidades_articulo']),
            $this->con_signo($t['unidades_variante']),
            $this->con_signo($t['desfase']),
        ];
    }

    /**
     * Las sucursales borradas con más filas.
     *
     * @param  array  $muertas  address_id => ['filas', 'unidades']
     * @return void
     */
    private function reportar_sucursales_muertas(array $muertas)
    {
        if (count($muertas) === 0) {
            return;
        }

        // Las de más filas primero; ante un empate, la de menor id (orden estable y legible).
        uksort($muertas, function ($x, $y) use ($muertas) {
            if ($muertas[$x]['filas'] === $muertas[$y]['filas']) {
                return $x <=> $y;
            }

            return $muertas[$y]['filas'] <=> $muertas[$x]['filas'];
        });

        $partes = [];

        foreach (array_slice($muertas, 0, self::TOPE_SUCURSALES_MUERTAS, true) as $address_id => $datos) {
            $partes[] = '#' . $address_id . ': ' . $datos['filas'] . ' filas (' . $this->con_signo($datos['unidades']) . ' u)';
        }

        $this->line('Sucursales borradas con filas (' . count($muertas) . (count($muertas) > self::TOPE_SUCURSALES_MUERTAS ? ', se listan las ' . self::TOPE_SUCURSALES_MUERTAS . ' con más filas' : '') . '): ' . implode(' · ', $partes) . '.');
    }

    /**
     * Las líneas que no dependen de la tabla por dueño: lo que NO se toca y lo que solo se reporta.
     *
     * @param  array  $opciones
     * @return void
     */
    private function reportar_lineas_complementarias(array $opciones)
    {
        // Domicilios de comprador: existen, no son fantasma, no se tocan.
        $compradores = FilasFantasmaDeSucursalHelper::filas_en_domicilios_de_comprador($opciones['user_id']);

        if (!is_null($compradores) && ($compradores['articulo']['filas'] + $compradores['variante']['filas']) > 0) {
            $this->line('Filas en domicilios de comprador (EXISTEN, no son fantasma, no se tocan): address_article '
                . $compradores['articulo']['filas'] . ' filas (' . $this->con_signo($compradores['articulo']['unidades']) . ' u) · address_article_variant '
                . $compradores['variante']['filas'] . ' filas (' . $this->con_signo($compradores['variante']['unidades']) . ' u).');
        }

        // Sin dueño: solo cuando la corrida no está acotada (una fila sin dueño no es de nadie).
        if (is_null($opciones['user_id']) && is_null($opciones['articulo_id'])) {
            $sin_dueno = FilasFantasmaDeSucursalHelper::filas_sin_dueno();

            if (($sin_dueno['articulo']['filas'] + $sin_dueno['variante']['filas']) > 0) {
                $this->warn('Filas fantasma SIN dueño (el artículo o la variante ya no existe; NO se tocan): address_article '
                    . $sin_dueno['articulo']['filas'] . ' filas (' . $this->con_signo($sin_dueno['articulo']['unidades']) . ' u) · address_article_variant '
                    . $sin_dueno['variante']['filas'] . ' filas (' . $this->con_signo($sin_dueno['variante']['unidades']) . ' u).');
            }
        }

        // users.address_id colgado: se reporta y NUNCA se escribe.
        $colgados = FilasFantasmaDeSucursalHelper::usuarios_con_sucursal_colgada($opciones['user_id']);

        if (count($colgados) > 0) {
            $this->warn('users.address_id COLGADO (apunta a una sucursal que ya no existe; el comando NO lo toca, hay que corregirlo en el perfil del usuario):');

            foreach ($colgados as $dueno => $datos) {
                $this->line('  dueño ' . $dueno . ': ' . count($datos['usuarios']) . ' usuarios (ids ' . $this->lista_acotada($datos['usuarios'], self::TOPE_USUARIOS_COLGADOS)
                    . ') · sucursales muertas ' . implode(', ', $datos['sucursales']) . '.');
            }

            $this->line('  Mientras siga colgado, cada venta de ese usuario vuelve a abrir un fantasma.');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  APLICAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * El modo `--aplicar`: precondiciones, respaldo y bucle por lotes.
     *
     * @param  array  $m         Lo medido.
     * @param  array  $opciones
     * @return int  Código de salida.
     */
    private function aplicar(array $m, array $opciones)
    {
        $this->line('');

        // Lo que este comando puede tocar: los `recalcular` y los `solo_fantasmas`.
        $trabajo = $m['trabajo'];

        // Sin nada para escribir no hay precondición que pedir: un `--aplicar` sobre una base limpia
        // (o con solo artículos que el comando no toca) termina bien aunque falte el concepto.
        if (count($trabajo) === 0) {
            $this->info('No hay nada para sanear: ninguna fila fantasma en artículos que el comando pueda tocar.');

            return 0;
        }

        // Precondición 0: el alcance. 🔴 Sin `--user_id` ni `--articulo_id`, `--aplicar` sanea a TODOS
        // los dueños con trabajo; en una base compartida (51 comercios en `u767360347_empresa`) eso es
        // mucho más de lo que alguien quiere por un tipeo. Con un solo dueño con trabajo no hay
        // ambigüedad y no se pide nada; con varios hay que elegir uno (`--user_id`) o decir `--todos`.
        if (is_null($opciones['user_id']) && is_null($opciones['articulo_id']) && !$opciones['todos'] && count($m['duenos_con_trabajo']) > 1) {
            $partes = [];

            foreach (array_slice($m['duenos_con_trabajo'], 0, self::TOPE_DUENOS_LISTADOS, true) as $dueno => $cantidad) {
                $partes[] = $dueno . ' (' . $cantidad . ' artículos)';
            }

            $this->error('--aplicar sin --user_id ni --articulo_id tocaría a ' . count($m['duenos_con_trabajo']) . ' dueños a la vez: '
                . implode(', ', $partes) . (count($m['duenos_con_trabajo']) > self::TOPE_DUENOS_LISTADOS ? ', ...' : '') . '. NO se tocó nada.');
            $this->line('Corrélo de a un dueño con --user_id=<N>. Si de verdad querés sanearlos a todos juntos, sumá --todos.');

            return 1;
        }

        // Precondición 1: el concepto del movimiento. Sin él NO se escribe nada, ni siquiera los
        // artículos que no necesitarían movimiento: un saneo a medias que después no puede dejar
        // el rastro de lo que hizo es peor que no empezar.
        $concepto_id = ConceptoStockMovement::where('name', FilasFantasmaDeSucursalHelper::CONCEPTO)->value('id');

        if (is_null($concepto_id)) {
            $this->error('No existe el concepto de stock "' . FilasFantasmaDeSucursalHelper::CONCEPTO . '" en esta base. NO se tocó nada.');
            $this->line('Etiquetar el movimiento con un concepto ajeno corrompe el libro de stock. Corré el ConceptoStockMovementSeeder (o cargá el concepto) y volvé a correrlo.');

            return 1;
        }

        // Precondición 2: las columnas que el movimiento completa. Un deploy sube los archivos antes
        // de migrar: sin ellas, cada artículo fallaría al crear su movimiento.
        foreach (['stock_anterior', 'stock_por_deposito', 'stock_resultante'] as $columna) {
            if (!Schema::hasColumn('stock_movements', $columna)) {
                $this->error('A stock_movements le falta la columna ' . $columna . ' (migraciones pendientes). NO se tocó nada.');

                return 1;
            }
        }

        // Precondición 3: que la base pueda hacer lo que el comando promete (transacciones e índices).
        if (!$this->verificar_motor_e_indices()) {
            return 1;
        }

        // Precondición 4 (solo con `--sincronizar`): que la instalación pueda marcar para Tienda Nube.
        // 🔴 `add_article_to_sync()` se corta sola con `USA_TIENDA_NUBE` apagado: el saneo se haría igual
        // y no se marcaría NADA, y después los artículos quedarían limpios: este comando ya no los ve y
        // no habría cómo marcarlos con él (Tienda Nube se quedaría con el stock viejo, que es justo lo
        // que la opción viene a evitar). Mejor no escribir nada.
        if ($opciones['sincronizar'] && !$this->tienda_nube_prendida()) {
            $this->error('--sincronizar pide marcar artículos para Tienda Nube, pero USA_TIENDA_NUBE está apagado en esta instalación: add_article_to_sync() no encola nada. NO se tocó nada.');
            $this->line('Corré sin --sincronizar (el stock se corrige igual, sin avisar a Tienda Nube) o prendé USA_TIENDA_NUBE en el .env de esta instalación y volvé a correrlo.');

            return 1;
        }

        // 🔴 Antes de escribir la PRIMERA fila: la carpeta y los dos archivos. Si no se pueden abrir,
        // el comando se niega a escribir (es la única forma de volver atrás).
        if (!$this->abrir_respaldos($opciones)) {
            $this->error('No se pudo dejar el respaldo, así que NO se tocó la base. Revisá permisos y espacio en disco y volvé a correrlo.');

            return 1;
        }

        $this->line('Respaldo JSONL:     ' . $this->ruta_respaldo);
        $this->line('SQL de reversión:  ' . $this->ruta_sql);
        $this->line('Artículos a sanear: ' . count($trabajo) . ' en tandas de ' . $opciones['lote'] . '.');

        $resumen = [
            'saneados' => 0,
            'con_movimiento' => 0,
            'ya_limpios' => 0,
            // motivo => cantidad. Arranca con lo que la medición ya dejó afuera (los `no_recalculable` y,
            // con --solo_explicados, los desvíos de otra causa): el resumen final tiene que decir TODO lo
            // que quedó sin tocar, no solo lo que el helper salteó por una carrera. Si no, "Saltados: 0."
            // y exit 0 con artículos que siguen con sus fantasmas.
            'saltados' => $m['saltados'],
            'fallidos' => 0,
            'filas_articulo' => 0,
            'filas_variante' => 0,
            'unidades_movimientos' => 0.0,
            'proyeccion_distinta' => 0,
            'variantes_actualizadas' => 0,
            'pivots_reconstruidos' => 0,
            // `--sincronizar`: de los artículos que dejaron un movimiento, cuántos quedaron marcados para
            // Tienda Nube, cuántos no se suben (el artículo no está en Tienda Nube o la instalación no la
            // usa), cuántos eran de la papelera (no se marcan) y cuáles fallaron al marcar (ids).
            'tn' => [
                'activo' => (bool) $opciones['sincronizar'],
                'marcados' => 0,
                'no_se_suben' => 0,
                'papelera' => 0,
                'fallidos' => [],
            ],
        ];

        // Artículos en los que se intentó escribir (saneados + fallidos): lo que cuenta `--limite`.
        // Los saltados y los ya limpios no consumen el límite: si lo consumieran, una corrida con
        // `--limite=10` podría volver a toparse siempre con los mismos 10 artículos que no se tocan.
        $intentados = 0;

        // Posición en la lista de trabajo del próximo artículo, para informar cuántos quedan.
        $posicion = 0;

        // true si la corrida se cortó por un error de respaldo o por demasiados fallidos seguidos.
        $fatal = false;

        // Artículos que fallaron SEGUIDOS: lo reinicia cualquier artículo que no falla.
        $fallidos_seguidos = 0;

        // true si la corrida se cortó por --limite.
        $cortada = false;

        // Líneas de --detalle ya impresas.
        $lineas_detalle = 0;

        $tandas = array_chunk($trabajo, $opciones['lote']);

        foreach ($tandas as $n_tanda => $tanda) {

            // Contadores de la tanda, solo para la línea de progreso.
            $tanda_saneados = 0;
            $tanda_otros = 0;
            $tanda_fallidos = 0;

            foreach ($tanda as $article_id) {

                if (!is_null($opciones['limite']) && $intentados >= $opciones['limite']) {
                    $cortada = true;

                    break 2;
                }

                $posicion++;

                $this->bloque_sql_escrito = false;

                try {
                    $resultado = FilasFantasmaDeSucursalHelper::sanear_articulo(
                        $article_id,
                        (int) $concepto_id,
                        function ($estado) {
                            $this->escribir_estado_previo($estado);
                        },
                        function ($sql, $resumen_del_articulo) {
                            $this->escribir_bloque_sql($sql);
                        },
                        $opciones['solo_explicados']
                    );
                } catch (\Throwable $e) {
                    // Sin respaldo no se sigue: lo que venga después tampoco se podría revertir.
                    if ($e->getCode() === self::CODIGO_RESPALDO) {
                        $this->error('No se pudo escribir el respaldo (artículo ' . $article_id . '): ' . $e->getMessage());
                        $this->error('Se corta la corrida. El artículo ' . $article_id . ' NO se tocó y los ya terminados están en el respaldo.');

                        $fatal = true;

                        break 2;
                    }

                    $intentados++;
                    $resumen['fallidos']++;
                    $tanda_fallidos++;
                    $fallidos_seguidos++;

                    $this->error('Artículo ' . $article_id . ': falló y se revirtió ("' . $e->getMessage() . '"). Seguimos con el próximo.');

                    $this->escribir_error_de_articulo($article_id, $e);

                    // Una falla sistemática (una columna, un permiso) recorrería toda la lista sin hacer nada.
                    if ($fallidos_seguidos >= self::TOPE_FALLIDOS_SEGUIDOS) {
                        $this->error('Se cortó la corrida: ' . self::TOPE_FALLIDOS_SEGUIDOS . ' artículos SEGUIDOS fallaron, así que el error no es de un artículo sino del entorno. Revisá el mensaje de arriba y volvé a correrlo: continúa donde quedó.');

                        $fatal = true;

                        break 2;
                    }

                    continue;
                }

                if ($resultado['resultado'] === FilasFantasmaDeSucursalHelper::RESULTADO_YA_LIMPIO) {
                    $resumen['ya_limpios']++;
                    $tanda_otros++;

                    continue;
                }

                if ($resultado['resultado'] === FilasFantasmaDeSucursalHelper::RESULTADO_SALTADO) {
                    $motivo = is_null($resultado['motivo']) ? 'sin_motivo' : $resultado['motivo'];

                    if (!isset($resumen['saltados'][$motivo])) {
                        $resumen['saltados'][$motivo] = 0;
                    }

                    $resumen['saltados'][$motivo]++;
                    $tanda_otros++;

                    continue;
                }

                // Solo un artículo que SE SANEÓ reinicia la cuenta: prueba que borrar y recalcular andan. Uno
                // `ya_limpio` o `saltado` no escribió nada y no dice nada de la salud del entorno.
                // Lo que NO promete: un artículo que se sanea SIN movimiento (su stock ya estaba bien) también
                // reinicia aunque no ejercite la creación del movimiento, así que una falla que solo pega ahí
                // corta más tarde, no nunca (cada artículo se revierte solo y queda anotado: la demora no daña).
                $fallidos_seguidos = 0;

                $intentados++;
                $tanda_saneados++;

                $resumen['saneados']++;
                $resumen['filas_articulo'] += $resultado['filas_borradas_articulo'];
                $resumen['filas_variante'] += $resultado['filas_borradas_variante'];
                $resumen['variantes_actualizadas'] += $resultado['variantes_actualizadas'];

                if ($resultado['pivot_reconstruido']) {
                    $resumen['pivots_reconstruidos']++;
                }

                if (!is_null($resultado['movimiento_id'])) {
                    $resumen['con_movimiento']++;
                    $resumen['unidades_movimientos'] = round($resumen['unidades_movimientos'] + $resultado['movimiento_amount'], 2);
                }

                if ($resultado['proyeccion_coincide'] === false) {
                    $resumen['proyeccion_distinta']++;

                    $this->warn('  art ' . $article_id . ': el stock que dejó el sistema (' . $this->numero($resultado['stock_despues']) . ') no coincide con el proyectado (' . $this->numero($resultado['stock_proyectado']) . '). Está en el log.');
                }

                // `--sincronizar`: el artículo YA está commiteado (la transacción terminó adentro de
                // `sanear_articulo()`), así que si el marcado falla no hay nada que revertir. Solo los que
                // dejaron un movimiento: son los que cambiaron `articles.stock`, que es lo que publica Tienda Nube.
                $estado_tn = null;

                if ($opciones['sincronizar'] && !is_null($resultado['movimiento_id'])) {
                    $estado_tn = $this->sincronizar_con_tienda_nube($resultado, $resumen['tn']);
                }

                if ($opciones['detalle'] && ($opciones['sin_tope'] || $lineas_detalle < self::TOPE_DETALLE)) {
                    $lineas_detalle++;

                    $this->line('  art ' . $article_id . ' → saneado · ' . $resultado['clase']
                        . ' · filas borradas ' . $resultado['filas_borradas_articulo'] . '+' . $resultado['filas_borradas_variante']
                        . ' · stock ' . $this->numero($resultado['stock_antes']) . ' → ' . $this->numero($resultado['stock_despues'])
                        . (is_null($resultado['movimiento_id']) ? ' · sin movimiento' : ' · movimiento #' . $resultado['movimiento_id'] . ' (' . $this->con_signo($resultado['movimiento_amount']) . ')')
                        . (is_null($estado_tn) ? '' : ' · tienda nube: ' . $estado_tn));

                    if (!$opciones['sin_tope'] && $lineas_detalle === self::TOPE_DETALLE) {
                        $this->comment('  (el detalle se corta a las ' . self::TOPE_DETALLE . ' líneas; --sin_tope las lista todas)');
                    }
                }
            }

            $this->line('Tanda ' . ($n_tanda + 1) . '/' . count($tandas) . ': saneados ' . $tanda_saneados
                . ' · ya limpios o saltados ' . $tanda_otros . ' · fallidos ' . $tanda_fallidos . '.');
        }

        $this->cerrar_respaldos();

        $restantes = count($trabajo) - $posicion;

        return $this->reportar_resultado($resumen, $cortada, $fatal, $restantes);
    }

    /**
     * El reporte final de `--aplicar`.
     *
     * @param  array  $r          Resumen acumulado.
     * @param  bool   $cortada    Se cortó por --limite.
     * @param  bool   $fatal      Se cortó por un error de respaldo.
     * @param  int    $restantes  Artículos de la lista que quedaron sin mirar.
     * @return int  Código de salida.
     */
    private function reportar_resultado(array $r, $cortada, $fatal, $restantes)
    {
        $this->line('');
        $this->info('Resultado:');
        $this->line('  Artículos saneados: ' . $r['saneados'] . ' (con movimiento de stock: ' . $r['con_movimiento'] . ', sin movimiento: ' . ($r['saneados'] - $r['con_movimiento']) . ').');
        $this->line('  Filas borradas: address_article ' . $r['filas_articulo'] . ' · address_article_variant ' . $r['filas_variante'] . '.');
        $this->line('  Suma de los movimientos: ' . $this->con_signo($r['unidades_movimientos']) . ' unidades.');

        if ($r['pivots_reconstruidos'] > 0 || $r['variantes_actualizadas'] > 0) {
            $this->line('  Artículos con variantes: pivot reconstruido en ' . $r['pivots_reconstruidos'] . ', stock de variante actualizado en ' . $r['variantes_actualizadas'] . '.');
        }

        $this->line('  Ya limpios al momento de tocarlos: ' . $r['ya_limpios'] . '.');

        $saltados = 0;

        foreach ($r['saltados'] as $motivo => $cantidad) {
            $saltados += $cantidad;
            // Advertencia y no una línea más: quien mire solo el código de salida (0) creería que
            // quedó limpio, y esos artículos siguen con sus filas fantasma.
            $this->warn('  Saltados (' . $motivo . '): ' . $cantidad . '. NO se tocaron: siguen con sus filas fantasma.');
        }

        if ($saltados === 0) {
            $this->line('  Saltados: 0.');
        }

        if ($r['proyeccion_distinta'] > 0) {
            $this->warn('  Stock distinto del proyectado: ' . $r['proyeccion_distinta'] . ' artículos (mirá el log y el detalle).');
        }

        if ($r['fallidos'] > 0) {
            $this->error('  Fallidos: ' . $r['fallidos'] . '. Cada uno se revirtió solo y quedó anotado como revertido_por_error en el JSONL. Volvé a correr el comando: continúa donde quedó.');
        } else {
            $this->line('  Fallidos: 0.');
        }

        if ($r['tn']['activo']) {
            $this->reportar_tienda_nube($r['tn']);
        }

        if ($cortada) {
            $this->warn('Se cortó por --limite: quedan ' . $restantes . ' artículos sin mirar. Volvé a correrlo para seguir.');
        }

        $this->line('');
        $this->line('Respaldo JSONL:     ' . $this->ruta_respaldo);
        $this->line('SQL de reversión:  ' . $this->ruta_sql);
        $this->warn('🔴 Los dos archivos quedaron en el disco DEL SERVIDOR, no en tu máquina.');
        $this->warn('   Bajalos ahora, antes de seguir: son lo único que permite revertir esta corrida.');
        $this->line('   scp/sftp desde: ' . dirname($this->ruta_respaldo));
        $this->comment('Para revertir (artículo por artículo, cada bloque es una transacción): mysql <base> < ' . $this->ruta_sql);

        return ($fatal || $r['fallidos'] > 0 || count($r['tn']['fallidos']) > 0) ? 1 : 0;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  TIENDA NUBE (--sincronizar)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Marca para sincronizar con Tienda Nube un artículo que YA se saneó y dejó un movimiento (su
     * `articles.stock` cambió). Ver "Tienda Nube" en el encabezado de la clase.
     *
     * Un artículo de la papelera no se marca; uno que falla al marcar se cuenta y se anota en el
     * respaldo, y no frena ni revierte nada.
     *
     * @param  array  $resultado  Lo que devolvió `sanear_articulo()` (saneado, con `movimiento_id`).
     * @param  array  $tn         Los contadores de `$resumen['tn']` (por referencia).
     * @return string  'marcado' | 'no_se_sube' | 'papelera' | 'error' (lo usa la línea de `--detalle`).
     */
    private function sincronizar_con_tienda_nube(array $resultado, array &$tn)
    {
        $article_id = (int) $resultado['article_id'];

        // El scheduler carga el artículo de la fila sin la papelera y le pasa null a una función que pide
        // un `Article` (`TypeError`, que `sync_article()` no atrapa): aborta su corrida. Ver el encabezado.
        if ($resultado['en_papelera']) {
            $tn['papelera']++;

            return 'papelera';
        }

        try {
            $estado = $this->marcar_para_tienda_nube($article_id, (int) $resultado['user_id']);
        } catch (\Throwable $e) {
            $tn['fallidos'][] = $article_id;

            $this->warn('  art ' . $article_id . ': ya está saneado pero no se pudo marcar para Tienda Nube ("' . $e->getMessage() . '"). Se intentó anotar en el respaldo (tn_error).');

            $this->anotar_en_el_respaldo(['evento' => 'tn_error', 'article_id' => $article_id, 'error' => $e->getMessage()]);

            return 'error';
        }

        if ($estado === self::TN_MARCADO) {
            $tn['marcados']++;

            $this->anotar_en_el_respaldo(['evento' => 'tn_marcado', 'article_id' => $article_id]);
        } else {
            $tn['no_se_suben']++;
        }

        return $estado;
    }

    /**
     * La marca en sí: carga el artículo con SOLO las columnas que lee `add_article_to_sync()`, se la
     * pasa y mira si quedó una fila pendiente (nueva, o una que ya había: el scheduler subirá el
     * artículo como esté cuando la procese, así que cuenta igual).
     *
     * Es `protected` para que un test la pise en una subclase y simule un fallo (un fallo real de la
     * base no se puede provocar dentro de la transacción de un test).
     *
     * @param  int  $article_id
     * @param  int  $dueno_id    `articles.user_id`.
     * @return string  TN_MARCADO | TN_NO_SE_SUBE
     * @throws \Throwable  Lo que lance la base o el servicio.
     */
    protected function marcar_para_tienda_nube($article_id, $dueno_id)
    {
        $article = Article::where('id', (int) $article_id)
            ->where('user_id', (int) $dueno_id)
            ->first(['id', 'user_id', 'tiendanube_product_id', 'disponible_tienda_nube']);

        // Desapareció entre el commit y acá (o pasó a la papelera): no hay nada que subir.
        if (is_null($article)) {
            return self::TN_NO_SE_SUBE;
        }

        TiendaNubeSyncArticleService::add_article_to_sync($article);

        $pendiente = SyncToTNArticle::where('article_id', $article->id)
            ->where('user_id', $article->user_id)
            ->where('status', 'pendiente')
            ->exists();

        return $pendiente ? self::TN_MARCADO : self::TN_NO_SE_SUBE;
    }

    /**
     * ¿La instalación tiene prendido `USA_TIENDA_NUBE`? Con la MISMA expresión que lee
     * `add_article_to_sync()` (`env('USA_TIENDA_NUBE', false)`): si está apagado, esa función no
     * encola nada, así que `--sincronizar` no marcaría ningún artículo.
     *
     * @return bool
     */
    protected function tienda_nube_prendida()
    {
        return (bool) env('USA_TIENDA_NUBE', false);
    }

    /**
     * Anota una línea en el respaldo JSONL sin cortar la corrida si no se puede (es un rastro, no el
     * respaldo de lo que se escribió: si el archivo está roto, la línea `antes` del próximo artículo lo
     * va a decir y va a cortar la corrida).
     *
     * @param  array  $datos
     * @return void
     */
    private function anotar_en_el_respaldo(array $datos)
    {
        try {
            $this->escribir_linea_json($datos);
        } catch (\Throwable $ignorado) {
            // Ver el docblock.
        }
    }

    /**
     * El bloque de Tienda Nube del reporte final de `--aplicar --sincronizar`.
     *
     * @param  array  $tn  `$resumen['tn']`.
     * @return void
     */
    private function reportar_tienda_nube(array $tn)
    {
        $this->line('  Tienda Nube (--sincronizar): marcados para sincronizar ' . $tn['marcados']
            . ' · con movimiento pero que no se suben ' . ($tn['no_se_suben'] + $tn['papelera'])
            . ' (artículos que no están en Tienda Nube: ' . $tn['no_se_suben'] . ' · papelera: ' . $tn['papelera'] . ')'
            . ' · fallidos al marcar ' . count($tn['fallidos']) . '.');

        if ($tn['marcados'] > 0) {
            $this->line('  Los marcados son filas pendientes (sync_to_t_n_articles, status "pendiente"): el scheduler del cliente los sube a la API de Tienda Nube en serie y sin pausa (si el dueño de la instancia tiene la extensión), y cada fallo le llega al dueño como notificación.');
            $this->line('  Esperá a que las pendientes bajen a 0 antes de la próxima corrida: SELECT status, COUNT(*) FROM sync_to_t_n_articles WHERE user_id = <N> GROUP BY status;');
        }

        if (count($tn['fallidos']) > 0) {
            $this->error('  No se pudieron marcar (ya están saneados y re-correr no los reintenta): ' . $this->lista_acotada($tn['fallidos'], 20) . '. Se vuelven a marcar a mano desde php artisan tinker: App\\Services\\TiendaNube\\TiendaNubeSyncArticleService::add_article_to_sync(App\\Models\\Article::find(<id>));');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  PRECONDICIONES DE LA BASE
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Que la base pueda hacer lo que el comando promete: transacciones por artículo e índices en los
     * caminos de bloqueo. Si no, NO se escribe nada (exit 1).
     *
     * 🔴 InnoDB: el "falló y se revirtió" de cada artículo, el respaldo y la reversión suponen que un
     * error después del `DELETE` deshace el `DELETE`. En MyISAM no pasa (`BEGIN` y `ROLLBACK` son
     * no-ops: `config/database.php` lo documenta y `EmpresaTestCase` lo verifica en testing): el
     * reporte diría "se revirtió" sobre filas ya borradas.
     *
     * 🔴 Índices: los `FOR UPDATE ... WHERE article_id IN (...)` del helper suponen un índice que
     * empiece por esa columna (los crea la migración `2026_08_19_120000`). Sin él cada artículo
     * escanea y bloquea el pivot entero y frena las ventas del cliente mientras corre el saneo.
     *
     * @return bool  true si se puede escribir; false si no (el error ya está impreso).
     */
    private function verificar_motor_e_indices()
    {
        $problemas = $this->problemas_de_motor_e_indices();

        if (count($problemas) === 0) {
            return true;
        }

        $this->error('La base no cumple lo que este comando necesita para escribir con seguridad. NO se tocó nada:');

        foreach ($problemas as $problema) {
            $this->line('  - ' . $problema . '.');
        }

        $this->line('Corré las migraciones pendientes (php artisan migrate) o convertí la tabla a InnoDB, y volvé a correrlo.');

        return false;
    }

    /**
     * Lo que le impide a esta base dejar que el comando escriba: tablas que no son InnoDB e índices de
     * bloqueo que faltan. Solo LEE (`information_schema`): por eso lo puede llamar también `--ver`
     * para avisar de antemano.
     *
     * @return string[]  Un texto por problema (sin el punto final). Vacío si la base está bien.
     */
    private function problemas_de_motor_e_indices()
    {
        $problemas = [];

        $motores = $this->motores_de_las_tablas();

        foreach (self::TABLAS_TRANSACCIONALES as $tabla) {
            $motor = isset($motores[$tabla]) ? $motores[$tabla] : null;

            if (is_null($motor)) {
                $problemas[] = 'la tabla ' . $tabla . ' no existe o no tiene motor';
            } elseif (strtoupper($motor) !== 'INNODB') {
                $problemas[] = 'la tabla ' . $tabla . ' es ' . $motor . ' y no InnoDB: sin transacciones no hay rollback por artículo';
            }
        }

        foreach ($this->indices_que_faltan() as $faltante) {
            $problemas[] = 'falta un índice que empiece por ' . $faltante . ' (migración 2026_08_19_120000): cada bloqueo escanearía la tabla entera y frenaría las ventas';
        }

        return $problemas;
    }

    /**
     * El motor de cada tabla que toca la transacción de un artículo.
     *
     * Es `protected` para que un test la pise en una subclase y simule una tabla MyISAM sin hacer
     * DDL: un `ALTER TABLE` adentro de la transacción de un test hace COMMIT implícito de todo lo
     * sembrado y lo deja grabado en la base del slot.
     *
     * @return array  tabla => motor ('InnoDB', 'MyISAM'...). Una tabla que no existe no figura.
     */
    protected function motores_de_las_tablas()
    {
        $filas = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', self::TABLAS_TRANSACCIONALES)
            ->selectRaw('TABLE_NAME as tabla, ENGINE as motor')
            ->get();

        $motores = [];

        foreach ($filas as $fila) {
            $motores[$fila->tabla] = $fila->motor;
        }

        return $motores;
    }

    /**
     * Las columnas de bloqueo (`COLUMNAS_DE_BLOQUEO`) que no tienen ningún índice que EMPIECE por
     * ellas. Alcanza con cualquier índice que arranque por la columna, no hace falta el nombre de la
     * migración: lo que importa es que el bloqueo no escanee la tabla. `protected` por el mismo
     * motivo que `motores_de_las_tablas()`.
     *
     * @return string[]  'tabla.columna'
     */
    protected function indices_que_faltan()
    {
        $faltan = [];

        foreach (self::COLUMNAS_DE_BLOQUEO as $par) {
            $hay = DB::select(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1 LIMIT 1',
                [DB::connection()->getDatabaseName(), $par[0], $par[1]]
            );

            if (count($hay) === 0) {
                $faltan[] = $par[0] . '.' . $par[1];
            }
        }

        return $faltan;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  RESPALDO EN DISCO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Abre la carpeta de salida y los dos archivos. Se llama ANTES de la primera escritura.
     *
     * 🔴 Si cualquiera de las dos cosas falla devuelve false y el comando no escribe una fila. Los
     * archivos se abren en modo exclusivo (`x`): dos corridas en el mismo segundo no pueden pisarse
     * (la segunda toma un sufijo `-2`, `-3`...).
     *
     * @param  array  $opciones
     * @return bool
     */
    private function abrir_respaldos(array $opciones)
    {
        $carpeta = $opciones['salida'];

        if (is_null($carpeta)) {
            $carpeta = storage_path('app/saneo-stock-sucursales-borradas');
        }

        if (!is_dir($carpeta) && !@mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
            $this->error('No se pudo crear la carpeta del respaldo: ' . $carpeta);

            return false;
        }

        if (!is_writable($carpeta)) {
            $this->error('La carpeta del respaldo existe pero no se puede escribir: ' . $carpeta);

            return false;
        }

        $sello = Carbon::now()->format('Ymd-His');

        $base = rtrim($carpeta, '/\\') . DIRECTORY_SEPARATOR . 'saneo-stock-sucursales-borradas-'
            . (is_null($opciones['user_id']) ? 'todos' : 'user' . $opciones['user_id'])
            . (is_null($opciones['articulo_id']) ? '' : '-art' . $opciones['articulo_id'])
            . '-' . $sello;

        // Sufijo que evita pisar una corrida del mismo segundo.
        $sufijo = '';
        $n = 1;

        while (file_exists($base . $sufijo . '-respaldo.jsonl') || file_exists($base . $sufijo . '-reversion.sql')) {
            $n++;
            $sufijo = '-' . $n;
        }

        $ruta_respaldo = $base . $sufijo . '-respaldo.jsonl';
        $ruta_sql = $base . $sufijo . '-reversion.sql';

        $manejador_respaldo = @fopen($ruta_respaldo, 'x');

        if ($manejador_respaldo === false) {
            $this->error('No se pudo abrir para escritura el respaldo: ' . $ruta_respaldo);

            return false;
        }

        $manejador_sql = @fopen($ruta_sql, 'x');

        if ($manejador_sql === false) {
            $this->error('No se pudo abrir para escritura el SQL de reversión: ' . $ruta_sql);

            fclose($manejador_respaldo);
            @unlink($ruta_respaldo);

            return false;
        }

        $encabezado = [
            '-- Reversión del saneo de stock de sucursales borradas',
            '-- Base: ' . DB::connection()->getDatabaseName(),
            '-- Generado: ' . Carbon::now()->toDateTimeString() . ' (' . $sello . ')',
            '-- Alcance: dueño ' . (is_null($opciones['user_id']) ? 'todos' : $opciones['user_id']) . ' · artículo ' . (is_null($opciones['articulo_id']) ? 'todos' : $opciones['articulo_id']),
            '-- Cada bloque (START TRANSACTION ... COMMIT) revierte UN artículo y es completo por sí solo:',
            '-- el archivo sirve aunque la corrida se haya cortado a la mitad. Se pueden correr solo los bloques que se quieran.',
            '-- Devuelve las filas borradas con su id original y los stocks a su valor previo (guardado: un UPDATE de stock',
            '-- solo aplica si el stock sigue siendo el que dejó el saneo). ESCRIBE: correr con criterio.',
            '',
            '',
        ];

        if (!$this->volcar($manejador_sql, implode("\n", $encabezado))) {
            $this->error('No se pudo escribir el encabezado del SQL de reversión: ' . $ruta_sql);

            fclose($manejador_respaldo);
            fclose($manejador_sql);
            @unlink($ruta_respaldo);
            @unlink($ruta_sql);

            return false;
        }

        $this->manejador_respaldo = $manejador_respaldo;
        $this->manejador_sql = $manejador_sql;
        $this->ruta_respaldo = $ruta_respaldo;
        $this->ruta_sql = $ruta_sql;

        return true;
    }

    /**
     * Write-ahead de un artículo: una línea JSON con todo lo que se va a tocar, ANTES de tocarlo.
     * Lo llama el helper adentro de la transacción: si lanza, no se escribe nada.
     *
     * @param  array  $estado
     * @return void
     * @throws \RuntimeException  Con CODIGO_RESPALDO si no se pudo escribir.
     */
    private function escribir_estado_previo(array $estado)
    {
        $this->escribir_linea_json($estado);
    }

    /**
     * Bloque de reversión de un artículo, ANTES del commit. Lo llama el helper adentro de la
     * transacción: si lanza, el artículo se revierte.
     *
     * Es `protected` y no `private` a propósito: el test del aviso del `.sql` la pisa en una subclase
     * para fallar DESPUÉS de escribir el bloque (que es lo que pasa si falla el commit), cosa que
     * dentro de la transacción de un test no se puede provocar de otra forma.
     *
     * @param  string  $sql
     * @return void
     * @throws \RuntimeException  Con CODIGO_RESPALDO si no se pudo escribir.
     */
    protected function escribir_bloque_sql($sql)
    {
        if (!$this->volcar($this->manejador_sql, $sql)) {
            throw new \RuntimeException('No se pudo escribir el SQL de reversión en ' . $this->ruta_sql, self::CODIGO_RESPALDO);
        }

        $this->bloque_sql_escrito = true;
    }

    /**
     * Anota que un artículo falló y se revirtió: en el .sql (si su bloque ya estaba escrito) y en el
     * respaldo JSONL.
     *
     * Mejor esfuerzo: si el respaldo mismo está roto, ya hay un error de más arriba que lo dice. Cada
     * escritura va en su propio `try` para que si una falla (disco lleno, un mensaje que no se pueda
     * serializar) la otra se escriba igual, y el aviso del .sql va primero: es lo único que le dice a
     * quien abra ese archivo que un bloque NO se aplicó.
     *
     * @param  int         $article_id
     * @param  \Throwable  $e
     * @return void
     */
    private function escribir_error_de_articulo($article_id, \Throwable $e)
    {
        // A la vista de quien abra el .sql: el bloque de ese artículo ya está escrito más arriba y no se
        // puede sacar, pero este aviso le dice que no lo corra (en un artículo con pivot reconstruido
        // pisaría ventas posteriores con las filas de antes).
        if ($this->bloque_sql_escrito) {
            try {
                $this->volcar($this->manejador_sql, '-- ⚠ ATENCIÓN: el bloque del artículo ' . (int) $article_id . ' de arriba NO se aplicó (la transacción se revirtió): no lo corras.' . "\n\n");
            } catch (\Throwable $ignorado) {
                // Ver el docblock.
            }
        }

        try {
            $this->escribir_linea_json([
                'evento' => 'revertido_por_error',
                'article_id' => (int) $article_id,
                'error' => $e->getMessage(),
                // Si es true el commit falló DESPUÉS de escribir el bloque: ese bloque no corresponde a nada.
                'bloque_sql_escrito' => $this->bloque_sql_escrito,
            ]);
        } catch (\Throwable $ignorado) {
            // Ver el docblock.
        }
    }

    /**
     * Una línea JSON al respaldo, con flush.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE`: el mensaje de un error de la base puede traer un byte que no es
     * UTF-8 (un dato mal codificado que el motor repite en su mensaje); sin la opción `json_encode`
     * devuelve false y la línea de ese artículo no se escribe.
     *
     * @param  array  $datos
     * @return void
     * @throws \RuntimeException  Con CODIGO_RESPALDO.
     */
    private function escribir_linea_json(array $datos)
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new \RuntimeException('No se pudo serializar el respaldo a JSON: ' . json_last_error_msg(), self::CODIGO_RESPALDO);
        }

        if (!$this->volcar($this->manejador_respaldo, $json . "\n")) {
            throw new \RuntimeException('No se pudo escribir el respaldo en ' . $this->ruta_respaldo, self::CODIGO_RESPALDO);
        }
    }

    /**
     * Cierra los dos archivos.
     *
     * @return void
     */
    private function cerrar_respaldos()
    {
        if (is_resource($this->manejador_respaldo)) {
            fclose($this->manejador_respaldo);
        }

        if (is_resource($this->manejador_sql)) {
            fclose($this->manejador_sql);
        }

        $this->manejador_respaldo = null;
        $this->manejador_sql = null;
    }

    /**
     * fwrite + fflush que no acepta una escritura parcial ni un false (un disco lleno a mitad de
     * un archivo deja un respaldo truncado que parece completo, que es peor que no tener ninguno).
     *
     * Es `protected` y no `private` a propósito: el test de "sin respaldo no se sigue" la pisa en una
     * subclase para simular un disco lleno a mitad de la corrida, que no se puede provocar de otra forma.
     *
     * @param  resource  $manejador
     * @param  string    $texto
     * @return bool
     */
    protected function volcar($manejador, $texto)
    {
        if (!is_resource($manejador)) {
            return false;
        }

        $escritos = @fwrite($manejador, $texto);

        if ($escritos === false || $escritos !== strlen($texto)) {
            return false;
        }

        return @fflush($manejador);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  FORMATO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Número con dos decimales y signo explícito (`+4.00`, `-69.00`): las unidades y el desfase
     * tienen signo y se leen mejor así.
     *
     * @param  float|null  $n
     * @return string
     */
    private function con_signo($n)
    {
        $n = is_null($n) ? 0.0 : round((float) $n, 2);

        // El cero va sin signo: "+0.00" parece un valor y es la ausencia de uno.
        return $n == 0.0 ? '0.00' : sprintf('%+.2f', $n);
    }

    /**
     * Número con dos decimales; null se muestra como "null" (un stock sin cargar no es 0).
     *
     * @param  float|null  $n
     * @return string
     */
    private function numero($n)
    {
        return is_null($n) ? 'null' : number_format((float) $n, 2, '.', '');
    }

    /**
     * Lista de ids separada por comas, cortada a un tope con un "...".
     *
     * @param  int[]  $ids
     * @param  int    $tope
     * @return string
     */
    private function lista_acotada(array $ids, $tope)
    {
        if (count($ids) <= $tope) {
            return implode(', ', $ids);
        }

        return implode(', ', array_slice($ids, 0, $tope)) . ', ... (+' . (count($ids) - $tope) . ')';
    }
}
