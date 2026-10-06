<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\address\FilasFantasmaDeSucursalHelper;
use App\Models\ConceptoStockMovement;
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
 *  6. El comando no avisa a Tienda Nube ni a Mercado Libre del cambio de stock (un barrido masivo
 *     encolaría cientos de sincronizaciones): el stock publicado se corrige con la próxima
 *     sincronización o movimiento del artículo.
 *
 * ─── Los archivos ─────────────────────────────────────────────────────────────────────────────
 *
 * Quedan en `storage/app/saneo-stock-sucursales-borradas/` (o en `--salida`), en el disco del
 * SERVIDOR donde corrió el comando, no en la máquina de quien lo lanzó por SSH. Si nadie los baja,
 * el día que haya que revertir no van a estar.
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
                            {--user_id= : Acota a un dueño (articles.user_id).}
                            {--articulo_id= : Acota a un artículo.}
                            {--limite= : Corta después de N artículos saneados (solo --aplicar). Los saltados y los ya limpios no cuentan.}
                            {--lote=200 : Artículos por tanda.}
                            {--detalle : Lista artículo por artículo (hasta 200 líneas).}
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
            . ' · lote ' . $opciones['lote'] . '.');

        if (!$aplicar) {
            if (!is_null($opciones['limite'])) {
                $this->comment('--limite solo cuenta en --aplicar: en --ver se mide todo.');
            }

            if (!is_null($opciones['salida'])) {
                $this->comment('--salida solo cuenta en --aplicar: --ver no escribe archivos.');
            }
        }

        // Ids de los artículos con al menos un fantasma. Solo ids: el análisis va por tandas.
        $ids = FilasFantasmaDeSucursalHelper::ids_de_articulos_afectados($opciones['user_id'], $opciones['articulo_id']);

        // La medición es la MISMA en los dos modos (misma función del helper que usa el saneo).
        $medicion = $this->medir($ids, $opciones['lote'], !$aplicar && $opciones['detalle']);

        $this->reportar_medicion($medicion, $opciones);

        if (!$aplicar) {
            // Mejor enterarse acá que con --aplicar: sin el concepto el comando se niega a escribir.
            if ($medicion['total']['articulos'] > 0 && is_null(ConceptoStockMovement::where('name', FilasFantasmaDeSucursalHelper::CONCEPTO)->value('id'))) {
                $this->warn('Ojo: no existe el concepto de stock "' . FilasFantasmaDeSucursalHelper::CONCEPTO . '" en esta base: --aplicar se negaría a escribir (exit 1) hasta que se cargue.');
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
            'limite' => $limite,
            'lote' => is_null($lote) ? 200 : $lote,
            'detalle' => (bool) $this->option('detalle'),
            'salida' => ($salida === null || $salida === '') ? null : (string) $salida,
        ];
    }

    /**
     * Una opción numérica que tiene que ser un entero positivo.
     *
     * @param  string  $nombre
     * @return int|null|false  null si no vino, false si es inválida (con el error ya impreso).
     */
    private function entero_de_opcion($nombre)
    {
        $valor = $this->option($nombre);

        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_scalar($valor) || !ctype_digit((string) $valor) || (int) $valor < 1) {
            $this->error('--' . $nombre . ' tiene que ser un entero positivo. Llegó: ' . (is_scalar($valor) ? $valor : gettype($valor)));

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
     * @param  int    $lote         Artículos por tanda.
     * @param  bool   $con_detalle  Si se juntan las líneas de `--detalle` (solo en `--ver`).
     * @return array  Ver las claves en el cuerpo.
     */
    private function medir(array $ids, $lote, $con_detalle)
    {
        $medicion = [
            // dueño => totales (ver fila_vacia()).
            'por_dueno' => [],
            'total' => $this->fila_vacia(),
            // Ids que `--aplicar` va a trabajar: los `recalcular` y `solo_fantasmas`.
            'trabajo' => [],
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

        foreach (array_chunk($ids, $lote) as $tanda) {

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
                    if (!isset($medicion['saltados'][$a['motivo']])) {
                        $medicion['saltados'][$a['motivo']] = 0;
                    }

                    $medicion['saltados'][$a['motivo']]++;
                } else {
                    $medicion['trabajo'][] = $id;
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
                    if (count($medicion['detalle']) < self::TOPE_DETALLE) {
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
     * Totales en cero de un dueño (o del total general).
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
    }

    /**
     * La línea de `--detalle` de un artículo medido.
     *
     * @param  array  $a
     * @return string
     */
    private function linea_de_detalle_de_medicion(array $a)
    {
        $stock = is_null($a['stock_proyectado'])
            ? 'stock ' . $this->numero($a['stock_actual']) . ' (no se recalcula)'
            : 'stock ' . $this->numero($a['stock_actual']) . ' → ' . $this->numero($a['stock_proyectado']) . ' (desfase ' . $this->con_signo($a['desfase']) . ')';

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

            foreach ($m['saltados'] as $motivo => $cantidad) {
                $this->warn('  no_recalculable / ' . $motivo . ': ' . $cantidad . ' artículos. --aplicar NO los toca (el motor tiraría Undefined index): revisalos a mano (se listan con --ver --detalle).');
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
                $this->comment('  ... y ' . $m['detalle_omitido'] . ' artículos más (el detalle se corta a las ' . self::TOPE_DETALLE . ' líneas).');
            }
        }
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
            // motivo => cantidad.
            'saltados' => [],
            'fallidos' => 0,
            'filas_articulo' => 0,
            'filas_variante' => 0,
            'unidades_movimientos' => 0.0,
            'proyeccion_distinta' => 0,
            'variantes_actualizadas' => 0,
            'pivots_reconstruidos' => 0,
        ];

        // Artículos en los que se intentó escribir (saneados + fallidos): lo que cuenta `--limite`.
        // Los saltados y los ya limpios no consumen el límite: si lo consumieran, una corrida con
        // `--limite=10` podría volver a toparse siempre con los mismos 10 artículos que no se tocan.
        $intentados = 0;

        // Posición en la lista de trabajo del próximo artículo, para informar cuántos quedan.
        $posicion = 0;

        // true si la corrida se cortó por un error de respaldo.
        $fatal = false;

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
                        }
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

                    $this->error('Artículo ' . $article_id . ': falló y se revirtió ("' . $e->getMessage() . '"). Seguimos con el próximo.');

                    $this->escribir_error_de_articulo($article_id, $e);

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

                if ($opciones['detalle'] && $lineas_detalle < self::TOPE_DETALLE) {
                    $lineas_detalle++;

                    $this->line('  art ' . $article_id . ' → saneado · ' . $resultado['clase']
                        . ' · filas borradas ' . $resultado['filas_borradas_articulo'] . '+' . $resultado['filas_borradas_variante']
                        . ' · stock ' . $this->numero($resultado['stock_antes']) . ' → ' . $this->numero($resultado['stock_despues'])
                        . (is_null($resultado['movimiento_id']) ? ' · sin movimiento' : ' · movimiento #' . $resultado['movimiento_id'] . ' (' . $this->con_signo($resultado['movimiento_amount']) . ')'));

                    if ($lineas_detalle === self::TOPE_DETALLE) {
                        $this->comment('  (el detalle se corta a las ' . self::TOPE_DETALLE . ' líneas)');
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
            $this->line('  Saltados (' . $motivo . '): ' . $cantidad . '.');
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

        return ($fatal || $r['fallidos'] > 0) ? 1 : 0;
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
     * @param  string  $sql
     * @return void
     * @throws \RuntimeException  Con CODIGO_RESPALDO si no se pudo escribir.
     */
    private function escribir_bloque_sql($sql)
    {
        if (!$this->volcar($this->manejador_sql, $sql)) {
            throw new \RuntimeException('No se pudo escribir el SQL de reversión en ' . $this->ruta_sql, self::CODIGO_RESPALDO);
        }

        $this->bloque_sql_escrito = true;
    }

    /**
     * Anota en el respaldo que un artículo falló y se revirtió.
     *
     * Mejor esfuerzo: si el respaldo mismo está roto, ya hay un error de más arriba que lo dice.
     *
     * @param  int         $article_id
     * @param  \Throwable  $e
     * @return void
     */
    private function escribir_error_de_articulo($article_id, \Throwable $e)
    {
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
     * @param  array  $datos
     * @return void
     * @throws \RuntimeException  Con CODIGO_RESPALDO.
     */
    private function escribir_linea_json(array $datos)
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
