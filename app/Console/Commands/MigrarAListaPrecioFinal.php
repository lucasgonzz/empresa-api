<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePriceTypeMonedaHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migra UN comercio a "siempre con listas de precios" (misión prueba-listas-siempre, 3/11/2026, D7).
 *
 * ─── Para qué ─────────────────────────────────────────────────────────────────────────────────
 *
 * Todo negocio pasa a trabajar con listas (`users.listas_de_precio = 1`). El que nunca las usó recibe
 * UNA, «Precio final», que es el precio del artículo de siempre espejado en una lista: su pivote en
 * `article_price_type` se escribe con `articles.final_price` al final de `ArticleHelper::setFinalPrice()`
 * (ver espejar_lista_precio_final()), así que el margen del proveedor, el de la categoría, el del
 * artículo, el precio manual, los recargos, los impuestos, el IVA y el redondeo dan EL MISMO NÚMERO.
 * Los clientes existentes se migran con este comando, de a uno, con respaldo. No es código que corra
 * solo en el release: un seeder tocaría a toda la flota a la vez (lo acordado con Lucas; mismo criterio
 * que `sucursales:migrar-a-principal`, del que este comando es copia).
 *
 *     php artisan listas:migrar-a-precio-final 38 --ver        # solo lee (por defecto)
 *     php artisan listas:migrar-a-precio-final 38 --aplicar    # escribe, con respaldo y reversión
 *
 * ─── Qué hace, en orden ───────────────────────────────────────────────────────────────────────
 *
 *  a. Frena (exit 1) si el dueño usa otro modo de listas: `lista_de_precios_por_categoria` o
 *     `lista_de_precios_por_rango_de_cantidad_vendida`. Esos no se migran acá.
 *  b. Si el dueño ya trabaja con listas (flag prendido y listas propias) no hace nada y lo dice: sus
 *     listas son sus precios. Si tiene listas cargadas con el flag APAGADO, frena salvo
 *     `--incluir-listas-existentes`: al prender el flag esas listas pasan a verse (tarjetas, columnas,
 *     selector de Vender). 🔴 Y una de dos cosas CAMBIA, según `--posicion-precio-final`:
 *       - `max` (por defecto): «Precio final» queda arriba de todas (`position` = la mayor + 1). Vender
 *         sigue igual; la TIENDA cambia, porque `tienda-api ArticleHelper::checkPriceTypes` toma la lista
 *         de mayor position sin mirar el flag: pasa de la lista más alta a «Precio final».
 *       - `min`: «Precio final» queda abajo de todas (`position` = la menor - 1). La tienda sigue con la
 *         misma lista; VENDER cambia: su lista por defecto pasa a ser la más alta en vez del precio final.
 *     En los dos casos, al prender el flag setFinalPrice() recalcula los pivotes de las listas existentes,
 *     que con el flag apagado pueden estar viejos: esos números se mueven. --ver los cuenta sin escribir.
 *     Lo decide Lucas, dueño por dueño. --ver y el freno lo dicen con los nombres de las listas.
 *  c. Crea la lista «Precio final» (`es_precio_final = 1`) sin disparar el observer de PriceType: el
 *     dueño ya tiene su diseño de etiquetas genérico y con una sola lista ese es el que vale. Prende el
 *     flag y otorga las tres extensiones de listas que el user setup da siempre.
 *  d. Por cada artículo VIVO del dueño, en su transacción: foto, recálculo real con el flag prendido
 *     (`setFinalPrice()`), y verificación de que `articles.final_price` quedó IDÉNTICO al de antes
 *     (tolerancia 0,005; null = null) y de que el pivote de «Precio final» es ese mismo número. Si
 *     difiere, revierte ESE artículo y lo informa. Los artículos en la papelera reciben el pivote
 *     espejo directo (sin recálculo: el motor no ve la papelera), así un restaurado conserva su precio.
 *  e. Idempotente: una segunda corrida encuentra la lista, el flag y los pivotes, y no hace nada.
 *  f. Respaldo write-ahead en `storage/app/respaldos/listas-precio-final/<fecha>-user<N>/`:
 *     `respaldo.jsonl` (el dueño, sus listas, sus extensiones y, por artículo, sus columnas de precio y
 *     sus pivotes ANTES de tocarlo) y `reversion.sql` (un bloque por artículo, más el de la cuenta, que
 *     devuelve todo a como estaba). Si el respaldo no se puede abrir, no se escribe nada.
 *
 * `--ver` (por defecto, y gana si llegan los dos) no deja nada en la base ni crea respaldos; lo único que
 * puede escribir es el log de la aplicación, si el motor de precios loguea al medir. Cuenta y
 * lista los «calculados por primera vez»: artículos con costo o precio que hoy tienen `final_price` NULL
 * y que después de migrar van a tener precio (no son un precio que cambia: antes no había ninguno).
 *
 * Memoria: el catálogo se cuenta y se recorre de a tandas, sin acumular (hay cuentas de ~750.000
 * artículos). El respaldo se escribe artículo por artículo al disco, antes de tocar cada uno.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class MigrarAListaPrecioFinal extends Command
{
    /**
     * @var string
     */
    protected $signature = 'listas:migrar-a-precio-final
                            {user_id? : Dueño del comercio; por defecto config(\'app.USER_ID\')}
                            {--ver : Solo lee y reporta (por defecto). No escribe nada.}
                            {--aplicar : Escribe: la lista «Precio final», el flag, las extensiones y el pivote de cada artículo, con respaldo y SQL de reversión.}
                            {--incluir-listas-existentes : Migra también a un dueño que tiene listas cargadas con el flag apagado (esas listas pasan a verse).}
                            {--posicion-precio-final=max : max = «Precio final» arriba de todo (Vender sigue igual, la tienda pasa a «Precio final»); min = abajo de todo (la tienda sigue igual, Vender pasa a la lista más alta).}
                            {--salida= : Carpeta base del respaldo (por defecto storage/app/respaldos/listas-precio-final).}';

    /**
     * @var string
     */
    protected $description = 'Migra un comercio a "siempre con listas de precios": crea la lista «Precio final» (espejo del precio del artículo), prende el flag y deja el pivote de cada artículo. --ver por defecto; --aplicar con respaldo y SQL de reversión.';

    /** Extensiones que el user setup da siempre a un negocio con listas (misión prueba-listas-siempre). */
    const EXTENSIONES = array(
        'articulo_margen_de_ganancia_segun_lista_de_precios',
        'cambiar_price_type_en_vender',
        'cambiar_price_type_en_vender_item_por_item',
    );

    /** Modos de listas que este comando no migra. */
    const EXTENSIONES_DE_OTRO_MODO = array(
        'lista_de_precios_por_categoria',
        'lista_de_precios_por_rango_de_cantidad_vendida',
    );

    /** Diferencia de precio que se tolera al verificar (la columna guarda 2 decimales). */
    const TOLERANCIA = 0.005;

    /** Carpeta base del respaldo, dentro de storage/app. */
    const CARPETA = 'respaldos/listas-precio-final';

    /** Se imprime en --ver y al terminar --aplicar. */
    const AVISO_HORARIO = 'Correr --aplicar FUERA del horario del negocio: la reversión solo vale inmediatamente después de migrar (si hubo cambios de precio en el medio, los pisa). Tiempo medido: 17,7 s cada 1.000 artículos (50.000 ≈ 15 min).';

    /** Segundos medidos de --aplicar por artículo, para la estimación de --ver. */
    const SEGUNDOS_POR_ARTICULO = 0.0177;

    /** Cuántos «calculados por primera vez» se listan como máximo (se cuentan todos). */
    const MAXIMO_A_LISTAR = 50;

    /** Tamaño de las tandas de lectura de artículos. */
    const TANDA = 500;

    /** @var resource|null */
    private $respaldo = null;

    /** @var resource|null */
    private $sql = null;

    /** @var string|null Carpeta de esta corrida. */
    private $carpeta = null;

    /** @var int Artículos que no tenían precio final calculado y ahora lo tienen (ver migrar_articulo()). */
    private $calculados_por_primera_vez = 0;

    /**
     * @return int
     */
    public function handle()
    {
        $user_id = $this->argument('user_id');

        if (is_null($user_id) || $user_id === '') {
            $user_id = config('app.USER_ID');
        }

        if (!is_numeric($user_id) || (int) $user_id <= 0) {
            $this->error('Falta el dueño: pasalo como argumento (listas:migrar-a-precio-final <user_id>) o configurá USER_ID.');

            return 1;
        }

        $user_id = (int) $user_id;

        $dueno = User::find($user_id);

        if (is_null($dueno)) {
            $this->error('No existe el usuario '.$user_id.'.');

            return 1;
        }

        if (!is_null($dueno->owner_id)) {
            $this->error('El usuario '.$user_id.' es empleado (owner_id '.$dueno->owner_id.'): pasá el id del dueño.');

            return 1;
        }

        $aplicar = (bool) $this->option('aplicar') && !(bool) $this->option('ver');

        $posicion = (string) $this->option('posicion-precio-final');

        if (!in_array($posicion, array('max', 'min'), true)) {
            $this->error('--posicion-precio-final tiene que ser max o min. No se escribe nada.');

            return 1;
        }

        $this->info('Migración a la lista «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'» · dueño '.$user_id.' ('.$dueno->name.') · base '.DB::connection()->getDatabaseName());

        if ($this->option('aplicar') && $this->option('ver')) {
            $this->warn('Llegaron --ver y --aplicar juntos: gana la lectura, no se escribe nada.');
        }

        $this->line($aplicar ? 'Modo: APLICAR (escribe, con respaldo y SQL de reversión).' : 'Modo: VER (no deja nada en la base ni crea respaldos; lo único que puede escribir es el log).');

        // a. Otro modo de listas: se frena en los dos modos.
        foreach (self::EXTENSIONES_DE_OTRO_MODO as $slug) {
            if (UserHelper::hasExtencion($slug, $dueno)) {
                $this->error('El dueño '.$user_id.' usa la extensión "'.$slug.'": es otro modo de listas y este comando no lo migra. No se escribe nada.');

                return 1;
            }
        }

        $medicion = $this->medir($dueno, $posicion);

        $medicion['pivotes_que_se_mueven'] = null;

        if (!$aplicar && $medicion['listas_con_flag_apagado'] && (bool) $this->option('incluir-listas-existentes')) {
            $medicion['pivotes_que_se_mueven'] = $this->pivotes_que_se_moverian($dueno);
        }

        // b. Ya trabaja con listas propias: no hay nada que migrar.
        if ($medicion['trabaja_con_listas_propias']) {
            $this->info('El dueño ya trabaja con listas de precios ('.$medicion['listas'].' lista(s), flag prendido): sus listas son sus precios y no se crea la «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'». No se escribe nada.');

            return 0;
        }

        if ($medicion['listas_con_flag_apagado'] && !(bool) $this->option('incluir-listas-existentes')) {
            $this->error('El dueño tiene '.$medicion['listas'].' lista(s) cargadas con el flag apagado ('.$medicion['nombres_de_listas'].'). '
                .'Al prender el flag esas listas pasan a VERSE (tarjetas del artículo, columnas del listado, selector de Vender). '
                .'Si es lo que se quiere, volvé a correr con --incluir-listas-existentes. No se escribe nada.');

            $this->avisar_que_cambia($medicion);

            return 1;
        }

        $this->reportar($medicion);

        if (!$aplicar) {
            $this->line('');
            $this->info('Modo ver: no se escribió nada. Para migrar, corré el mismo comando con --aplicar.');

            return 0;
        }

        if ($medicion['nada_que_hacer']) {
            $this->info('No hay nada que migrar: el comercio ya tiene su lista «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'», el flag prendido y todos los artículos con su pivote.');

            return 0;
        }

        if (!$this->abrir_respaldo($user_id)) {
            return 1;
        }

        try {
            return $this->aplicar($dueno, $medicion);
        } finally {
            $this->cerrar_respaldo();
            PriceTypeHelper::olvidar_precio_final($user_id);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  MEDICIÓN (solo lectura, la misma en los dos modos)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Lo que haría la migración, sin escribir nada.
     *
     * @param  User    $dueno
     * @param  string  $posicion  'max' | 'min'.
     * @return array
     */
    private function medir($dueno, $posicion = 'max')
    {
        $flag = (int) $dueno->listas_de_precio === 1;

        $listas = PriceType::where('user_id', $dueno->id)->orderBy('position')->orderBy('id')->get();

        $precio_final = null;

        foreach ($listas as $lista) {
            if (PriceTypeHelper::es_lista_precio_final($lista)) {
                $precio_final = $lista;
                break;
            }
        }

        $otras = $listas->filter(function ($lista) {
            return !PriceTypeHelper::es_lista_precio_final($lista);
        });

        $extensiones_faltantes = array();

        foreach (self::EXTENSIONES as $slug) {
            if (!UserHelper::hasExtencion($slug, $dueno)) {
                $extensiones_faltantes[] = $slug;
            }
        }

        $casos = array('vivos' => 0, 'papelera' => 0, 'con_precio_manual' => 0, 'sin_costo_ni_precio' => 0, 'calculados_por_primera_vez' => 0);

        $pendientes = 0;

        // Solo se cuenta: de un catálogo entero queda en memoria, como mucho, la lista acotada de abajo.
        $primera_vez = array();

        foreach ($this->tandas_de_articulos($dueno->id, is_null($precio_final) ? null : (int) $precio_final->id) as $tanda) {

            foreach ($tanda as $a) {

                $pendientes++;

                if ($a['papelera']) {
                    $casos['papelera']++;
                } else {
                    $casos['vivos']++;
                }

                if (!is_null($a['price'])) {
                    $casos['con_precio_manual']++;
                }

                if (is_null($a['cost']) && is_null($a['price'])) {
                    $casos['sin_costo_ni_precio']++;

                } else if (is_null($a['final_price']) && !$a['papelera']) {

                    $casos['calculados_por_primera_vez']++;

                    if (count($primera_vez) < self::MAXIMO_A_LISTAR) {
                        $primera_vez[] = $a;
                    }
                }
            }
        }

        $trabaja_con_listas_propias = $flag && count($otras) > 0 && is_null($precio_final);

        $mas_alta = null;

        foreach ($otras as $lista) {
            if (is_null($mas_alta) || (int) $lista->position >= (int) $mas_alta->position) {
                $mas_alta = $lista;
            }
        }

        $position = is_null($listas->max('position')) ? 1 : ((int) $listas->max('position')) + 1;

        if ($posicion === 'min' && !is_null($listas->min('position'))) {
            $position = ((int) $listas->min('position')) - 1;
        }

        return array(
            'flag'                        => $flag,
            'listas'                      => count($listas),
            'nombres_de_listas'           => implode(', ', $listas->pluck('name')->all()),
            'precio_final'                => $precio_final,
            'crear'                       => is_null($precio_final),
            'position'                    => $position,
            'posicion'                    => $posicion,
            'lista_mas_alta'              => is_null($mas_alta) ? null : (string) $mas_alta->name,
            'prender_flag'                => !$flag,
            'extensiones_faltantes'       => $extensiones_faltantes,
            'trabaja_con_listas_propias'  => $trabaja_con_listas_propias,
            'listas_con_flag_apagado'     => !$flag && count($otras) > 0,
            'otras_listas'                => count($otras),
            'usa_monedas'                 => UserHelper::hasExtencion('ventas_en_dolares', $dueno),
            'pendientes'                  => $pendientes,
            'casos'                       => $casos,
            'primera_vez'                 => $primera_vez,
            'nada_que_hacer'              => !is_null($precio_final) && $flag && count($extensiones_faltantes) === 0 && $pendientes === 0,
        );
    }

    /**
     * Los artículos del dueño (con la papelera) que todavía no tienen pivote en la lista «Precio
     * final» (todos, si la lista no existe), de a una tanda por vez y sin acumular: hay catálogos de
     * ~750.000 artículos y el `memory_limit` de la CLI no los aguanta en un array.
     *
     * @param  int       $user_id
     * @param  int|null  $price_type_id
     * @return \Generator  Cada tanda: [{id, name, cost, price, final_price, papelera}]
     */
    private function tandas_de_articulos($user_id, $price_type_id)
    {
        $ultimo_id = 0;

        while (true) {

            $tanda = DB::table('articles')
                ->where('user_id', $user_id)
                ->where('id', '>', $ultimo_id)
                ->orderBy('id')
                ->limit(self::TANDA)
                ->get(array('id', 'name', 'cost', 'price', 'final_price', 'deleted_at'));

            if (count($tanda) === 0) {
                break;
            }

            $ids = array();

            foreach ($tanda as $fila) {
                $ids[] = (int) $fila->id;
                $ultimo_id = (int) $fila->id;
            }

            $con_pivote = array();

            if (!is_null($price_type_id)) {
                $con_pivote = DB::table('article_price_type')
                    ->where('price_type_id', $price_type_id)
                    ->whereIn('article_id', $ids)
                    ->distinct()
                    ->pluck('article_id')
                    ->all();

                $con_pivote = array_flip(array_map('intval', $con_pivote));
            }

            $resultado = array();

            foreach ($tanda as $fila) {

                if (isset($con_pivote[(int) $fila->id])) {
                    continue;
                }

                $resultado[] = array(
                    'id'          => (int) $fila->id,
                    'name'        => $fila->name,
                    'cost'        => $fila->cost,
                    'price'       => $fila->price,
                    'final_price' => $fila->final_price,
                    'papelera'    => !is_null($fila->deleted_at),
                );
            }

            yield $resultado;
        }
    }

    /**
     * Cuántos pivotes de las listas existentes se moverían al prender el flag: con el flag apagado
     * setFinalPrice() no los recalcula, así que pueden estar viejos, y después de migrar son lo que
     * publica la tienda. Se mide con el motor real, artículo por artículo, en una transacción que siempre
     * se revierte (el mismo patrón de MargenesPorListaIaHelper::precios_estimados()): el dueño lleva el
     * flag prendido solo en memoria y en la base no queda nada. Por eso solo corre con
     * --incluir-listas-existentes: tarda lo que tarda --aplicar.
     *
     * @param  User  $dueno
     * @return array  ['filas' => int, 'articulos' => int, 'sin_medir' => int]
     */
    private function pivotes_que_se_moverian($dueno)
    {
        $ids_de_listas = PriceType::where('user_id', $dueno->id)->pluck('id')->all();

        $owner = User::find($dueno->id);
        $owner->listas_de_precio = 1;

        $resultado = array('filas' => 0, 'articulos' => 0, 'sin_medir' => 0);

        foreach ($this->tandas_de_articulos($dueno->id, null) as $tanda) {

            foreach ($tanda as $a) {

                if ($a['papelera']) {
                    continue;
                }

                DB::beginTransaction();

                try {
                    $antes = DB::table('article_price_type')->where('article_id', $a['id'])->whereIn('price_type_id', $ids_de_listas)->pluck('final_price', 'price_type_id')->all();

                    ArticleHelper::setFinalPrice(Article::find($a['id']), null, $owner);

                    $despues = DB::table('article_price_type')->where('article_id', $a['id'])->whereIn('price_type_id', $ids_de_listas)->pluck('final_price', 'price_type_id')->all();

                    $movidas = 0;

                    foreach ($despues as $price_type_id => $final_price) {

                        $anterior = array_key_exists($price_type_id, $antes) ? $antes[$price_type_id] : null;

                        if (!$this->iguales(is_null($anterior) ? null : (float) $anterior, is_null($final_price) ? null : (float) $final_price)) {
                            $movidas++;
                        }
                    }

                    $resultado['filas'] += $movidas;
                    $resultado['articulos'] += $movidas > 0 ? 1 : 0;

                } catch (\Throwable $e) {

                    $resultado['sin_medir']++;

                } finally {
                    DB::rollBack();
                }
            }
        }

        return $resultado;
    }

    /**
     * Las dos cosas que hay que decirle a quien migra a un dueño con listas cargadas y el flag apagado:
     * una de las dos pantallas cambia de precio, y cuál depende de `--posicion-precio-final`.
     *
     * @param  array  $m
     * @return void
     */
    private function avisar_que_cambia(array $m)
    {
        $mas_alta = '«'.$m['lista_mas_alta'].'»';
        $precio_final = '«'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'»';

        $this->warn('🔴 Con listas ya cargadas, UNA de estas dos cosas cambia de precio. Lo decide Lucas, dueño por dueño:');
        $this->warn(' · --posicion-precio-final=max (por defecto)'.($m['posicion'] === 'max' ? ' [elegida]' : '').': Vender sigue igual (cobra '.$precio_final.', el precio de hoy). La TIENDA cambia: hoy publica la lista de mayor position ('.$mas_alta.') y pasa a publicar '.$precio_final.'.');
        $this->warn(' · --posicion-precio-final=min'.($m['posicion'] === 'min' ? ' [elegida]' : '').': la tienda sigue con la misma lista ('.$mas_alta.'), sujeto a lo que se dice abajo de sus pivotes. VENDER cambia: su lista por defecto pasa a ser '.$mas_alta.' en vez del precio final de hoy.');

        $mueve = isset($m['pivotes_que_se_mueven']) ? $m['pivotes_que_se_mueven'] : null;

        if (is_null($mueve)) {
            $this->warn(' · Al prender el flag se recalculan los pivotes de las listas existentes, que con el flag apagado pueden estar viejos: esos precios se mueven, en Vender y en la tienda. Para contarlos: --ver --incluir-listas-existentes.');

            return;
        }

        $this->warn(' · Al prender el flag se recalculan los pivotes de las listas existentes: se moverían '.$mueve['filas'].' pivote(s), en '.$mueve['articulos'].' artículo(s).'
            .($mueve['sin_medir'] ? ' No se pudieron medir '.$mueve['sin_medir'].' artículo(s).' : '')
            .($mueve['filas'] ? ' Esos precios cambian en Vender y en la tienda, con cualquiera de las dos posiciones.' : ''));
    }

    /**
     * @param  array  $m
     * @return void
     */
    private function reportar(array $m)
    {
        $this->line('');

        if ($m['crear']) {
            $this->line('Lista: el comercio NO tiene la «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'» → se crea con position '.$m['position']
                .($m['otras_listas']
                    ? ($m['posicion'] === 'min'
                        ? ' (por debajo de sus '.$m['otras_listas'].' lista(s))'
                        : ' (por encima de sus '.$m['otras_listas'].' lista(s): queda como lista por defecto)')
                    : '').'.');
        } else {
            $this->line('Lista: el comercio ya tiene la «'.$m['precio_final']->name.'» (id '.$m['precio_final']->id.').');
        }

        $this->line('Flag listas_de_precio: '.($m['prender_flag'] ? 'apagado → se prende.' : 'ya prendido.'));
        $this->line('Extensiones de listas que faltan: '.(count($m['extensiones_faltantes']) ? implode(', ', $m['extensiones_faltantes']) : 'ninguna').'.');
        $this->line('Motor de listas: '.($m['usa_monedas'] ? 'por moneda (ventas_en_dolares): la lista nace en pesos y en dólares.' : 'en pesos.'));

        $c = $m['casos'];

        $this->line('Artículos sin pivote de «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'»: '.$m['pendientes']
            .' · vivos (recálculo real y verificación): '.$c['vivos']
            .' · en la papelera (pivote directo, sin recálculo): '.$c['papelera']
            .' · con precio manual: '.$c['con_precio_manual']
            .' · sin costo ni precio (conservan su precio final): '.$c['sin_costo_ni_precio'].'.');

        $this->line('Calculados por primera vez (tienen costo o precio y hoy no tienen precio final: pasan a tener precio): '.$c['calculados_por_primera_vez'].'.');

        foreach ($m['primera_vez'] as $a) {
            $this->line(' · '.$a['id'].' · '.$a['name'].' · costo '.(is_null($a['cost']) ? '-' : $a['cost']).' · precio manual '.(is_null($a['price']) ? '-' : $a['price']));
        }

        if ($c['calculados_por_primera_vez'] > count($m['primera_vez'])) {
            $this->line(' · … y '.($c['calculados_por_primera_vez'] - count($m['primera_vez'])).' más.');
        }

        if ($m['listas_con_flag_apagado']) {
            $this->avisar_que_cambia($m);
        }

        $this->line('Tiempo estimado de --aplicar: ≈ '.max(1, (int) ceil($c['vivos'] * self::SEGUNDOS_POR_ARTICULO / 60)).' min.');

        $this->warn(self::AVISO_HORARIO);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  APLICAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * @param  User   $dueno
     * @param  array  $m
     * @return int
     */
    private function aplicar($dueno, array $m)
    {
        // Respaldo general: el dueño, sus listas y sus extensiones como estaban.
        $this->escribir_json(array(
            'tipo'        => 'comercio',
            'user_id'     => $dueno->id,
            'listas_de_precio' => $dueno->listas_de_precio,
            'listas'      => DB::table('price_types')->where('user_id', $dueno->id)->orderBy('id')->get()->all(),
            'extensiones' => DB::table('extencion_empresa_user')->where('user_id', $dueno->id)->orderBy('extencion_empresa_id')->get()->all(),
        ));

        $lista = DB::transaction(function () use ($dueno, $m) {
            return $this->asegurar_cuenta($dueno, $m);
        });

        $this->info('Lista «'.$lista->name.'»: id '.$lista->id.' (position '.$lista->position.').');

        // El dueño releído, con el flag prendido y las extensiones: es el que ve setFinalPrice().
        $owner = User::find($dueno->id);

        $ok = 0;
        $revertidos = 0;
        $ids_revertidos = array();

        // Por tandas y sin acumular: la memoria de la corrida no crece con el catálogo.
        foreach ($this->tandas_de_articulos($dueno->id, (int) $lista->id) as $tanda) {

            foreach ($tanda as $a) {

                try {
                    DB::transaction(function () use ($a, $lista, $owner) {
                        $this->migrar_articulo($a, $lista, $owner);
                    });

                    $ok++;

                } catch (\Throwable $e) {

                    if ($e->getCode() === 9002) {
                        // El respaldo no se pudo escribir: no se sigue sin respaldo.
                        $this->error($e->getMessage());

                        return 1;
                    }

                    $revertidos++;

                    if (count($ids_revertidos) < self::MAXIMO_A_LISTAR) {
                        $ids_revertidos[] = $a['id'];
                    }

                    $this->warn('Artículo '.$a['id'].': '.$e->getMessage().' → se revirtió, quedó como estaba.');

                    $this->escribir_json(array('tipo' => 'revertido', 'article_id' => $a['id'], 'motivo' => $e->getMessage()), false);
                }
            }
        }

        $this->line('');
        $this->info('Listo: '.$ok.' artículo(s) migrados'.($revertidos ? ', '.$revertidos.' revertidos (ids: '.implode(', ', $ids_revertidos).')' : '').'.');

        if ($this->calculados_por_primera_vez > 0) {
            $this->line('Artículos que no tenían precio final calculado y ahora lo tienen: '.$this->calculados_por_primera_vez.'.');
        }

        $this->line('Respaldo y reversión en: '.$this->carpeta);
        $this->warn(self::AVISO_HORARIO);

        return $revertidos ? 1 : 0;
    }

    /**
     * c: crea la lista «Precio final» (si no la tiene), prende el flag y otorga las extensiones. Deja
     * su reversión en el SQL.
     *
     * @param  User   $dueno
     * @param  array  $m
     * @return PriceType
     */
    private function asegurar_cuenta($dueno, array $m)
    {
        $sql = "-- Cuenta: flag, extensiones y lista «".PriceTypeHelper::NOMBRE_PRECIO_FINAL."» como estaban\n";

        if ($m['prender_flag']) {
            DB::table('users')->where('id', $dueno->id)->update(array('listas_de_precio' => 1));
            $sql .= 'UPDATE users SET listas_de_precio = '.$this->literal($dueno->listas_de_precio).' WHERE id = '.(int) $dueno->id.";\n";
        }

        $ids_otorgados = array();

        if (count($m['extensiones_faltantes'])) {

            $ids = ExtencionEmpresa::whereIn('slug', $m['extensiones_faltantes'])->pluck('id')->all();

            foreach ($ids as $id) {
                $ya = DB::table('extencion_empresa_user')->where('user_id', $dueno->id)->where('extencion_empresa_id', $id)->exists();

                if (!$ya) {
                    DB::table('extencion_empresa_user')->insert(array('user_id' => $dueno->id, 'extencion_empresa_id' => $id));
                    $ids_otorgados[] = (int) $id;
                }
            }

            if (count($ids_otorgados)) {
                $sql .= 'DELETE FROM extencion_empresa_user WHERE user_id = '.(int) $dueno->id.' AND extencion_empresa_id IN ('.implode(',', $ids_otorgados).");\n";
            }
        }

        if ($m['crear']) {

            // Sin observer: el dueño conserva su diseño de etiquetas genérico, que con una sola lista es el que vale.
            $lista = PriceTypeHelper::crear_lista_precio_final($dueno->id, $m['position'], true);

            $sql .= 'DELETE FROM article_price_type_monedas WHERE price_type_id = '.(int) $lista->id.";\n"
                .'DELETE FROM article_price_type WHERE price_type_id = '.(int) $lista->id.";\n"
                .'DELETE FROM price_types WHERE id = '.(int) $lista->id.";\n";

        } else {
            $lista = $m['precio_final'];
        }

        $this->escribir_sql($sql."\n");

        $this->escribir_json(array(
            'tipo'          => 'cuenta',
            'price_type_id' => $lista->id,
            'lista_creada'  => (bool) $m['crear'],
            'flag_prendido' => (bool) $m['prender_flag'],
            'extensiones_otorgadas' => $ids_otorgados,
        ));

        PriceTypeHelper::olvidar_precio_final($dueno->id);

        return $lista->fresh();
    }

    /**
     * d para UN artículo (adentro de su transacción): respaldo, recálculo o pivote directo,
     * verificación, reversión.
     *
     * @param  array      $a
     * @param  PriceType  $lista
     * @param  User       $owner
     * @return void
     * @throws \RuntimeException si el precio no quedó idéntico (la transacción se revierte).
     */
    private function migrar_articulo(array $a, $lista, $owner)
    {
        $argumentos = func_get_args();
        return \App\Services\HistorialPreciosService::ejecutar([(int) $a['id']], null, 'migracion_precio_final', function () use ($argumentos) {
            return call_user_func_array([$this, 'migrar_articulo_sin_historial'], $argumentos);
        });
    }

    private function migrar_articulo_sin_historial(array $a, $lista, $owner)
    {
        $id = (int) $a['id'];

        // Estado previo, leído con candado, y el respaldo ANTES de tocar nada.
        $antes = DB::table('articles')->where('id', $id)->lockForUpdate()->first(array(
            'id', 'cost', 'costo_real', 'price', 'percentage_gain', 'final_price', 'previus_final_price',
            'final_price_updated_at', 'base_margen', 'base_margen_final', 'deleted_at',
        ));

        $pivotes_antes = DB::table('article_price_type')->where('article_id', $id)->orderBy('id')->get()->all();
        $monedas_antes = DB::table('article_price_type_monedas')->where('article_id', $id)->orderBy('id')->get()->all();
        $max_pivote    = (int) DB::table('article_price_type')->max('id');
        $max_moneda    = (int) DB::table('article_price_type_monedas')->max('id');
        $max_cambio    = (int) DB::table('price_changes')->max('id');

        $this->escribir_json(array(
            'tipo'       => 'articulo',
            'article_id' => $id,
            'articulo'   => $antes,
            'article_price_type'         => $pivotes_antes,
            'article_price_type_monedas' => $monedas_antes,
        ));

        $final_antes = is_null($antes->final_price) ? null : (float) $antes->final_price;

        if (!is_null($antes->deleted_at)) {

            // Papelera: el pivote espejo directo, sin recálculo (el motor no ve la papelera).
            DB::table('article_price_type')->insert(array(
                'article_id'          => $id,
                'price_type_id'       => $lista->id,
                'percentage'          => $antes->percentage_gain,
                'final_price'         => $antes->final_price,
                'setear_precio_final' => is_null($antes->price) ? 0 : 1,
                'incluir_en_excel_para_clientes' => 1,
            ));

            // Y sus filas por moneda, para el dueño con el motor por moneda: un restaurado tiene que
            // quedar igual que los vivos, en los dos motores (decisión D3 del plan).
            if (UserHelper::hasExtencion('ventas_en_dolares', $owner)) {
                ArticlePriceTypeMonedaHelper::espejar_precio_final_en_monedas(
                    Article::withTrashed()->find($id),
                    $owner,
                    $lista,
                    is_null($antes->final_price) ? null : (float) $antes->final_price,
                    is_null($antes->percentage_gain) ? null : (float) $antes->percentage_gain,
                    !is_null($antes->price)
                );
            }

        } else {

            $modelo = Article::find($id);

            if (is_null($modelo)) {
                throw new \RuntimeException('no se pudo cargar el artículo');
            }

            ArticleHelper::setFinalPrice($modelo, null, $owner);
        }

        // e. El precio final tiene que quedar IDÉNTICO, y el pivote tiene que ser ese número.
        $final_despues = DB::table('articles')->where('id', $id)->value('final_price');
        $final_despues = is_null($final_despues) ? null : (float) $final_despues;

        if (is_null($final_antes) && !is_null($final_despues)) {
            /*
             * El artículo no tenía precio final (nunca pasó por el motor: un alta a medias, una
             * importación cortada) y ahora lo tiene. No es un precio que cambió: antes no había
             * ninguno, y Vender tampoco tenía qué cobrar. Se acepta y se cuenta aparte.
             */
            $this->calculados_por_primera_vez++;
        } else if (!$this->iguales($final_antes, $final_despues)) {
            throw new \RuntimeException('el precio final iba a quedar en '.var_export($final_despues, true).' y era '.var_export($final_antes, true));
        }

        $pivote = DB::table('article_price_type')->where('article_id', $id)->where('price_type_id', $lista->id)->orderBy('id')->first();

        if (is_null($pivote)) {
            throw new \RuntimeException('no quedó el pivote de la lista «'.$lista->name.'»');
        }

        $pivote_final = is_null($pivote->final_price) ? null : (float) $pivote->final_price;

        if (!$this->iguales($final_despues, $pivote_final)) {
            throw new \RuntimeException('el pivote de la lista iba a quedar en '.var_export($pivote_final, true).' y el precio final es '.var_export($final_despues, true));
        }

        // f. La reversión de este artículo, antes del commit (si no se puede escribir, se revierte).
        $sql = '-- Artículo '.$id."\nSTART TRANSACTION;\n"
            .'DELETE FROM price_changes WHERE article_id = '.$id.' AND id > '.$max_cambio.";\n"
            .'DELETE FROM article_price_type_monedas WHERE article_id = '.$id.' AND id > '.$max_moneda.";\n"
            .'DELETE FROM article_price_type WHERE article_id = '.$id.' AND id > '.$max_pivote.";\n";

        foreach ($pivotes_antes as $p) {
            $sql .= 'UPDATE article_price_type SET percentage = '.$this->literal($p->percentage)
                .', final_price = '.$this->literal($p->final_price)
                .', previus_final_price = '.$this->literal($p->previus_final_price)
                .', setear_precio_final = '.$this->literal($p->setear_precio_final)
                .', precio_luego_de_recargos = '.$this->literal($p->precio_luego_de_recargos)
                .', monto_ganancia = '.$this->literal($p->monto_ganancia)
                .' WHERE id = '.(int) $p->id.";\n";
        }

        foreach ($monedas_antes as $e) {
            $sql .= 'UPDATE article_price_type_monedas SET percentage = '.$this->literal($e->percentage)
                .', final_price = '.$this->literal($e->final_price)
                .', setear_precio_final = '.$this->literal($e->setear_precio_final)
                .', cotizar_desde_otra_moneda = '.$this->literal($e->cotizar_desde_otra_moneda)
                .' WHERE id = '.(int) $e->id.";\n";
        }

        $sql .= 'UPDATE articles SET costo_real = '.$this->literal($antes->costo_real)
            .', price = '.$this->literal($antes->price)
            .', percentage_gain = '.$this->literal($antes->percentage_gain)
            .', final_price = '.$this->literal($antes->final_price)
            .', previus_final_price = '.$this->literal($antes->previus_final_price)
            .', final_price_updated_at = '.$this->literal($antes->final_price_updated_at)
            .', base_margen = '.$this->literal($antes->base_margen)
            .', base_margen_final = '.$this->literal($antes->base_margen_final)
            .' WHERE id = '.$id.";\nCOMMIT;\n\n";

        $this->escribir_sql($sql);
    }

    /**
     * @param  float|null  $a
     * @param  float|null  $b
     * @return bool
     */
    private function iguales($a, $b)
    {
        if (is_null($a) || is_null($b)) {
            return is_null($a) && is_null($b);
        }

        return abs((float) $a - (float) $b) <= self::TOLERANCIA;
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    //  RESPALDO
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Abre la carpeta y los dos archivos ANTES de la primera escritura. Si no se puede, no se escribe nada.
     *
     * @param  int  $user_id
     * @return bool
     */
    private function abrir_respaldo($user_id)
    {
        $raiz = trim((string) $this->option('salida'));

        if ($raiz === '') {
            $raiz = storage_path('app/'.self::CARPETA);
        }

        $base = rtrim($raiz, '/\\').DIRECTORY_SEPARATOR.Carbon::now()->format('Ymd-His').'-user'.$user_id;

        $carpeta = $base;
        $n = 1;

        while (file_exists($carpeta)) {
            $n++;
            $carpeta = $base.'-'.$n;
        }

        if (!@mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
            $this->error('No se pudo crear la carpeta del respaldo: '.$carpeta.'. No se escribió nada.');

            return false;
        }

        $this->respaldo = @fopen($carpeta.DIRECTORY_SEPARATOR.'respaldo.jsonl', 'x');
        $this->sql = @fopen($carpeta.DIRECTORY_SEPARATOR.'reversion.sql', 'x');

        if ($this->respaldo === false || $this->sql === false) {
            $this->error('No se pudieron abrir los archivos del respaldo en '.$carpeta.'. No se escribió nada.');

            return false;
        }

        $this->carpeta = $carpeta;

        $this->escribir_sql(implode("\n", array(
            '-- Reversión de listas:migrar-a-precio-final',
            '-- Base: '.DB::connection()->getDatabaseName().' · dueño '.$user_id.' · '.Carbon::now()->toDateTimeString(),
            '-- Orden del archivo: primero el bloque de la cuenta (flag, extensiones y la lista creada), después un bloque',
            '-- por artículo, en el orden en que se migraron.',
            '-- Para revertir: correr los bloques de artículo y RECIÉN DESPUÉS el de la cuenta (borrar la lista antes dejaría',
            '-- pivotes apuntando a un id que no existe). Cada bloque de artículo es completo por sí solo.',
            '-- OJO: vale solo INMEDIATAMENTE después de migrar. Los UPDATE dejan los precios como estaban ANTES de la',
            '-- migración, así que si hubo cambios de costo o de precio en el medio, los pisa. Por eso se corre fuera del horario.',
            '-- ESCRIBE: correr con criterio.',
            '',
            '',
        )));

        return true;
    }

    /**
     * @return void
     */
    private function cerrar_respaldo()
    {
        if (is_resource($this->respaldo)) {
            fclose($this->respaldo);
        }

        if (is_resource($this->sql)) {
            fclose($this->sql);
        }
    }

    /**
     * Una línea JSON en el respaldo.
     *
     * @param  array  $datos
     * @param  bool   $estricto  Si es true y no se puede escribir, lanza (código 9002): no se sigue sin respaldo.
     * @return void
     */
    private function escribir_json(array $datos, $estricto = true)
    {
        $linea = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)."\n";

        if (!is_resource($this->respaldo) || @fwrite($this->respaldo, $linea) === false) {
            if ($estricto) {
                throw new \RuntimeException('No se pudo escribir el respaldo ('.$this->carpeta.'): se corta la migración.', 9002);
            }
        }
    }

    /**
     * @param  string  $sql
     * @return void
     */
    private function escribir_sql($sql)
    {
        if (!is_resource($this->sql) || @fwrite($this->sql, $sql) === false) {
            throw new \RuntimeException('No se pudo escribir el SQL de reversión ('.$this->carpeta.'): se corta la migración.', 9002);
        }
    }

    /**
     * Un valor como literal SQL.
     *
     * @param  mixed  $valor
     * @return string
     */
    private function literal($valor)
    {
        if (is_null($valor)) {
            return 'NULL';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        return DB::connection()->getPdo()->quote((string) $valor);
    }
}
