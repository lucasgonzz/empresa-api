<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\UserHelper;
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
 *     selector de Vender) y «Precio final» queda como lista por defecto (`position` = la mayor + 1).
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
 * `--ver` (por defecto, y gana si llegan los dos) no escribe nada, ni en la base ni en archivos.
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
    const AVISO_HORARIO = 'Correr --aplicar FUERA del horario del negocio: la reversión solo vale inmediatamente después de migrar (si hubo cambios de precio en el medio, los pisa).';

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

        $this->info('Migración a la lista «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'» · dueño '.$user_id.' ('.$dueno->name.') · base '.DB::connection()->getDatabaseName());

        if ($this->option('aplicar') && $this->option('ver')) {
            $this->warn('Llegaron --ver y --aplicar juntos: gana la lectura, no se escribe nada.');
        }

        $this->line($aplicar ? 'Modo: APLICAR (escribe, con respaldo y SQL de reversión).' : 'Modo: VER (solo lee: no escribe en la base ni en archivos).');

        // a. Otro modo de listas: se frena en los dos modos.
        foreach (self::EXTENSIONES_DE_OTRO_MODO as $slug) {
            if (UserHelper::hasExtencion($slug, $dueno)) {
                $this->error('El dueño '.$user_id.' usa la extensión "'.$slug.'": es otro modo de listas y este comando no lo migra. No se escribe nada.');

                return 1;
            }
        }

        $medicion = $this->medir($dueno);

        // b. Ya trabaja con listas propias: no hay nada que migrar.
        if ($medicion['trabaja_con_listas_propias']) {
            $this->info('El dueño ya trabaja con listas de precios ('.$medicion['listas'].' lista(s), flag prendido): sus listas son sus precios y no se crea la «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'». No se escribe nada.');

            return 0;
        }

        if ($medicion['listas_con_flag_apagado'] && !(bool) $this->option('incluir-listas-existentes')) {
            $this->error('El dueño tiene '.$medicion['listas'].' lista(s) cargadas con el flag apagado ('.$medicion['nombres_de_listas'].'). '
                .'Al prender el flag esas listas pasan a VERSE (tarjetas del artículo, columnas del listado, selector de Vender) y la «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'» queda como lista por defecto. '
                .'Si es lo que se quiere, volvé a correr con --incluir-listas-existentes. No se escribe nada.');

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
     * @param  User  $dueno
     * @return array
     */
    private function medir($dueno)
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

        $articulos = is_null($precio_final)
            ? $this->articulos_del_dueno($dueno->id, null)
            : $this->articulos_del_dueno($dueno->id, (int) $precio_final->id);

        $casos = array('vivos' => 0, 'papelera' => 0, 'con_precio_manual' => 0, 'sin_costo_ni_precio' => 0);

        foreach ($articulos as $a) {
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
            }
        }

        $trabaja_con_listas_propias = $flag && count($otras) > 0 && is_null($precio_final);

        return array(
            'flag'                        => $flag,
            'listas'                      => count($listas),
            'nombres_de_listas'           => implode(', ', $listas->pluck('name')->all()),
            'precio_final'                => $precio_final,
            'crear'                       => is_null($precio_final),
            'position'                    => is_null($listas->max('position')) ? 1 : ((int) $listas->max('position')) + 1,
            'prender_flag'                => !$flag,
            'extensiones_faltantes'       => $extensiones_faltantes,
            'trabaja_con_listas_propias'  => $trabaja_con_listas_propias,
            'listas_con_flag_apagado'     => !$flag && count($otras) > 0,
            'otras_listas'                => count($otras),
            'usa_monedas'                 => UserHelper::hasExtencion('ventas_en_dolares', $dueno),
            'articulos'                   => $articulos,
            'casos'                       => $casos,
            'nada_que_hacer'              => !is_null($precio_final) && $flag && count($extensiones_faltantes) === 0 && count($articulos) === 0,
        );
    }

    /**
     * Los artículos del dueño (con la papelera) que todavía no tienen pivote en la lista «Precio
     * final» (todos, si la lista no existe).
     *
     * @param  int       $user_id
     * @param  int|null  $price_type_id
     * @return array  [{id, cost, price, final_price, papelera}]
     */
    private function articulos_del_dueno($user_id, $price_type_id)
    {
        $resultado = array();

        $ultimo_id = 0;

        while (true) {

            $tanda = DB::table('articles')
                ->where('user_id', $user_id)
                ->where('id', '>', $ultimo_id)
                ->orderBy('id')
                ->limit(self::TANDA)
                ->get(array('id', 'cost', 'price', 'final_price', 'deleted_at'));

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

            foreach ($tanda as $fila) {

                if (isset($con_pivote[(int) $fila->id])) {
                    continue;
                }

                $resultado[] = array(
                    'id'          => (int) $fila->id,
                    'cost'        => $fila->cost,
                    'price'       => $fila->price,
                    'final_price' => $fila->final_price,
                    'papelera'    => !is_null($fila->deleted_at),
                );
            }
        }

        return $resultado;
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
                .($m['otras_listas'] ? ' (por encima de sus '.$m['otras_listas'].' lista(s): queda como lista por defecto)' : '').'.');
        } else {
            $this->line('Lista: el comercio ya tiene la «'.$m['precio_final']->name.'» (id '.$m['precio_final']->id.').');
        }

        $this->line('Flag listas_de_precio: '.($m['prender_flag'] ? 'apagado → se prende.' : 'ya prendido.'));
        $this->line('Extensiones de listas que faltan: '.(count($m['extensiones_faltantes']) ? implode(', ', $m['extensiones_faltantes']) : 'ninguna').'.');
        $this->line('Motor de listas: '.($m['usa_monedas'] ? 'por moneda (ventas_en_dolares): la lista nace en pesos y en dólares.' : 'en pesos.'));

        $c = $m['casos'];

        $this->line('Artículos sin pivote de «'.PriceTypeHelper::NOMBRE_PRECIO_FINAL.'»: '.count($m['articulos'])
            .' · vivos (recálculo real y verificación): '.$c['vivos']
            .' · en la papelera (pivote directo, sin recálculo): '.$c['papelera']
            .' · con precio manual: '.$c['con_precio_manual']
            .' · sin costo ni precio (conservan su precio final): '.$c['sin_costo_ni_precio'].'.');

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
        $revertidos = array();

        foreach ($m['articulos'] as $a) {

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

                $revertidos[] = $a['id'];

                $this->warn('Artículo '.$a['id'].': '.$e->getMessage().' → se revirtió, quedó como estaba.');

                $this->escribir_json(array('tipo' => 'revertido', 'article_id' => $a['id'], 'motivo' => $e->getMessage()), false);
            }
        }

        $this->line('');
        $this->info('Listo: '.$ok.' artículo(s) migrados'.(count($revertidos) ? ', '.count($revertidos).' revertidos (ids: '.implode(', ', array_slice($revertidos, 0, 50)).')' : '').'.');

        if ($this->calculados_por_primera_vez > 0) {
            $this->line('Artículos que no tenían precio final calculado y ahora lo tienen: '.$this->calculados_por_primera_vez.'.');
        }

        $this->line('Respaldo y reversión en: '.$this->carpeta);
        $this->warn(self::AVISO_HORARIO);

        return count($revertidos) ? 1 : 0;
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
