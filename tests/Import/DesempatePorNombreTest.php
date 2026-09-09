<?php

namespace Tests\Import;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Desempate por nombre cuando el codigo de proveedor esta repetido
 * (mision `desempate-por-nombre-codigo-repetido`, 9/9/2026).
 *
 * EL CASO REAL: DobleP Herrajes, lista de Bronzen. El proveedor usa el MISMO codigo
 * para dos productos distintos -- el suelto y su pack x15, con precios distintos:
 *
 *   FA-NN   SILICONA NEUTRA 280 ML NEGRO                          $3.189,05
 *   FA-NN   SILICONA NEUTRA 280 ML NEGRO X 15 BULTOS COMBINABLES   $2.140,00
 *
 * El codigo lo decide el proveedor y viene asi en el Excel que el manda: no es
 * corregible desde el archivo. Los nombres SI son unicos dentro de cada par, y eso es
 * lo unico que habilita el desempate.
 *
 * Son DOS defectos distintos y se arreglan por separado, por eso hay dos bloques de
 * tests:
 *
 *   1. AL CREAR -- ActualizarBBDD::get_article_model_from_cache() volvia a buscar los
 *      articulos recien insertados con un ->first() por provider_code, asi que las dos
 *      filas resolvian al MISMO modelo: el primero se llevaba los recargos de las dos
 *      filas y el segundo quedaba sin descuento ni recargo. Medido en la base de
 *      produccion de DobleP: 6 articulos con 2 recargos y 6 con ninguno.
 *      🔴 Este defecto NO depende de la opcion nueva: se arregla siempre, para todo el
 *      mundo, porque hoy no hay ninguna configuracion en la que el comportamiento viejo
 *      sea el correcto.
 *
 *   2. AL REIMPORTAR -- los articulos ya existen, no pasan por ese metodo: van por
 *      ArticleIndexCache::find_with_index(). Ahi si manda la opcion nueva
 *      `desempatar_por_nombre`, que con default false deja todo como estaba.
 *
 * ⚠️ Los descuentos se leen de `article_discounts` y los recargos de `article_surchages`
 * con DB::table() y aserciones de cantidad EXACTA, no de "al menos uno". Es a proposito:
 * la huella del defecto 1 es justamente la cantidad (2 y 0 en vez de 1 y 1), y los
 * recargos son la mitad que la delata porque NO tienen barrido previo -- los descuentos
 * pasan por un delete que enmascara parte del sintoma.
 *
 * ⚠️ Las importaciones de esta clase van con `provider_id => null`. No es casual: con un
 * proveedor elegido, ProcessRow::set_discounts_de_la_fila() rutea los descuentos al
 * camino "tagueado" (ArticleProviderDiscountHelper::sync_provider_discounts()) y no al
 * legado de `article_discounts`, que es el que pasa por get_article_model_from_cache().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class DesempatePorNombreTest extends ImportTestCase
{
    /** Fixture del caso real: PC-PACK con dos nombres distintos, + descuentos y recargos. */
    const ARCHIVO = '22_desempate_por_nombre.xlsx';

    /** Codigo compartido por el producto suelto y su pack. */
    const CODIGO_PACK = 'PC-PACK';

    /** Codigo cuyos dos articulos de la base comparten codigo Y nombre: no hay desempate. */
    const CODIGO_IGUAL = 'PC-IGUAL';

    /** Codigo cuyos dos articulos de la base se llaman distinto que la fila del Excel. */
    const CODIGO_REDACTADO = 'PC-REDACT';

    const NOMBRE_SUELTO = 'Silicona neutra negra';
    const NOMBRE_PACK   = 'Silicona neutra negra x 15 bultos';

    /**
     * Mapa de columnas del fixture 22. La cabecera comun de ImportTestCase::columnas()
     * tiene 8 columnas; esta agrega descuentos (9) y recargos (10).
     *
     * @return array
     */
    protected function columnas_del_fixture()
    {
        return array_merge(self::columnas(), [
            'prop_descuentos' => 9,
            'prop_recargos'   => 10,
        ]);
    }

    /**
     * Configuracion base: el usuario ya dijo que los codigos repetidos son productos
     * distintos, tanto dentro del archivo como contra la base. Sin esas dos, las filas
     * se fusionan antes de llegar a donde vive el defecto.
     *
     * @param  array $extra
     * @return array
     */
    protected function config($extra = [])
    {
        return array_merge(
            $this->columnas_del_fixture(),
            [
                'provider_id'                     => null,
                'permitir_provider_code_repetido' => true,
                'filas_repetidas_del_archivo'     => 'productos_distintos',
            ],
            $extra
        );
    }

    /**
     * Articulos del tenant con ese provider_code, ordenados por id.
     *
     * @param  string $provider_code
     * @return \Illuminate\Support\Collection
     */
    protected function articulos_con_codigo($provider_code)
    {
        return Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', $provider_code)
                        ->orderBy('id')
                        ->get();
    }

    /**
     * Porcentajes de descuento de un articulo, ordenados.
     *
     * @param  int $article_id
     * @return array
     */
    protected function descuentos($article_id)
    {
        return DB::table('article_discounts')
                    ->where('article_id', $article_id)
                    ->whereNotNull('percentage')
                    ->orderBy('percentage')
                    ->pluck('percentage')
                    ->map(function ($valor) {
                        return (float) $valor;
                    })
                    ->all();
    }

    /**
     * Porcentajes de recargo de un articulo, ordenados.
     *
     * @param  int $article_id
     * @return array
     */
    protected function recargos($article_id)
    {
        return DB::table('article_surchages')
                    ->where('article_id', $article_id)
                    ->whereNotNull('percentage')
                    ->orderBy('percentage')
                    ->pluck('percentage')
                    ->map(function ($valor) {
                        return (float) $valor;
                    })
                    ->all();
    }

    /**
     * Crea un articulo del tenant con codigo y nombre dados, sin bar_code ni sku, para
     * fabricar el escenario de REIMPORTACION (los articulos ya existen en la base).
     *
     * @param  string   $provider_code
     * @param  string   $name
     * @param  float    $cost
     * @param  int|null $provider_id  null = sin proveedor (el caso de la mayoria de los tests)
     * @return \App\Models\Article
     */
    protected function crear_articulo($provider_code, $name, $cost, $provider_id = null)
    {
        $article = new Article();

        $article->user_id       = $this->tenant->id;
        $article->name          = $name;
        $article->provider_code = $provider_code;
        $article->bar_code      = null;
        $article->sku           = null;
        $article->provider_id   = $provider_id;
        $article->cost          = $cost;
        $article->stock         = 0;
        $article->iva_id        = 2;
        $article->status        = 'active';
        $article->online        = 1;

        $article->save();

        return $article;
    }

    /* ==================================================================
     * Defecto 1 -- AL CREAR
     * ================================================================== */

    /**
     * EL TEST DE PLATA. Dos filas con el mismo provider_code NUEVO y nombres distintos:
     * cada articulo tiene que quedar con SU descuento y SU recargo.
     *
     * Antes del arreglo, get_article_model_from_cache() resolvia las dos filas al mismo
     * modelo con un ->first() por provider_code, y el resultado era:
     *   - el primer articulo con LOS DOS recargos (5 y 8)
     *   - el segundo con NINGUNO
     *
     * Es la reproduccion exacta de lo medido en produccion (6 articulos con 2 recargos y
     * 6 con 0), y no depende de la opcion nueva: al crear no hay ninguna configuracion en
     * la que meterle los recargos de dos filas a un solo articulo sea lo correcto.
     *
     * @return void
     */
    public function test_al_crear_cada_articulo_queda_con_su_descuento_y_su_recargo()
    {
        $this->importar(self::ARCHIVO, $this->config());

        $creados = $this->articulos_con_codigo(self::CODIGO_PACK);

        $this->assertCount(2, $creados, 'Las dos filas de PC-PACK tienen que crear DOS articulos.');

        $suelto = $creados->firstWhere('name', self::NOMBRE_SUELTO);
        $pack   = $creados->firstWhere('name', self::NOMBRE_PACK);

        $this->assertNotNull($suelto, 'Falta el articulo suelto.');
        $this->assertNotNull($pack,   'Falta el articulo del pack.');

        /* Cada uno con SU costo, que es lo unico que ya andaba bien. */
        $this->assertDecimal(3189.05, $suelto->cost, 'El suelto conserva su costo.');
        $this->assertDecimal(2140.00, $pack->cost,   'El pack conserva su costo.');

        /*
         * 🔴 Aserciones de contenido EXACTO, no de cantidad. Con el defecto puesto, el
         * suelto quedaba con [5.0, 8.0] y el pack con []: una asercion de "tiene al menos
         * un recargo" pasaria igual sobre el articulo roto.
         */
        $this->assertSame([5.0], $this->recargos($suelto->id), 'El suelto tiene que quedar solo con SU recargo (5%).');
        $this->assertSame([8.0], $this->recargos($pack->id),   'El pack tiene que quedar solo con SU recargo (8%).');

        $this->assertSame([10.0], $this->descuentos($suelto->id), 'El suelto tiene que quedar solo con SU descuento (10%).');
        $this->assertSame([20.0], $this->descuentos($pack->id),   'El pack tiene que quedar solo con SU descuento (20%).');
    }

    /**
     * El arreglo del defecto 1 NO depende de la opcion nueva: con `desempatar_por_nombre`
     * apagada -- que es el default y lo que manda cualquier SPA sin desplegar -- el
     * resultado al crear tiene que ser el mismo.
     *
     * Este test es la red que atrapa la version equivocada del arreglo: colgar la
     * correccion de get_article_model_from_cache() del flag. La opcion es para el camino
     * de REIMPORTACION, donde el usuario elige entre comportamientos que ambos tienen
     * sentido; al crear no hay eleccion posible, es un defecto y punto.
     *
     * @return void
     */
    public function test_al_crear_el_arreglo_no_depende_de_la_opcion()
    {
        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => false]));

        $creados = $this->articulos_con_codigo(self::CODIGO_PACK);

        $this->assertCount(2, $creados);

        $suelto = $creados->firstWhere('name', self::NOMBRE_SUELTO);
        $pack   = $creados->firstWhere('name', self::NOMBRE_PACK);

        $this->assertSame([5.0], $this->recargos($suelto->id), 'Sin la opcion, el suelto igual queda con SU recargo.');
        $this->assertSame([8.0], $this->recargos($pack->id),   'Sin la opcion, el pack igual queda con SU recargo.');
    }

    /* ==================================================================
     * Defecto 2 -- AL REIMPORTAR
     * ================================================================== */

    /**
     * Con la opcion ACTIVA y los dos articulos ya en la base, cada fila tiene que
     * actualizar SU articulo, no los dos.
     *
     * Sin el desempate, find_with_index() devuelve la Collection con los dos y cada fila
     * escribe en ambos: los dos terminan con el costo de la ultima fila (2140), o sea que
     * ninguno de los dos queda bien.
     *
     * @return void
     */
    public function test_al_reimportar_con_la_opcion_cada_fila_actualiza_su_articulo()
    {
        $suelto = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $pack   = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => true]));

        $this->assertCount(
            2,
            $this->articulos_con_codigo(self::CODIGO_PACK),
            'No se tiene que crear ningun articulo nuevo: los dos ya existian.'
        );

        $this->assertDecimal(3189.05, Article::find($suelto->id)->cost, 'El suelto toma el costo de SU fila.');
        $this->assertDecimal(2140.00, Article::find($pack->id)->cost,   'El pack toma el costo de SU fila.');
    }

    /**
     * 🔴 EL TEST QUE FIJA QUE LA OPCION NO CUELGA DE LA POLITICA DE COLISION.
     *
     * La primera version de esta mision metio el desempate ADENTRO del bloque de
     * `permitir_provider_code_repetido`, o sea que la opcion no hacia nada salvo que el
     * usuario ademas hubiera elegido "actualizar todos los que tengan ese codigo". Con
     * "Saltear esas filas y avisarme" -- que es esta configuracion -- la ejecucion caia al
     * else, devolvia AmbiguousMatch y el desempate no corria NUNCA: el usuario prendia la
     * casilla, la importacion terminaba sin error y no pasaba nada.
     *
     * El desempate es DESAMBIGUACION y la politica de colision es que hacer CUANDO NO SE
     * PUEDE desambiguar. Si el nombre resuelve, ya no hay colision y la politica es
     * irrelevante.
     *
     * @return void
     */
    public function test_el_desempate_corre_aunque_la_politica_sea_saltear_y_avisar()
    {
        $suelto = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $pack   = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        $import = $this->importar(self::ARCHIVO, $this->config([
            'permitir_provider_code_repetido' => false,
            'desempatar_por_nombre'           => true,
        ]));

        $this->assertDecimal(3189.05, Article::find($suelto->id)->cost, 'El suelto toma el costo de SU fila.');
        $this->assertDecimal(2140.00, Article::find($pack->id)->cost,   'El pack toma el costo de SU fila.');

        $this->assertSame(
            0,
            $this->conflictos($import, 'ambiguo'),
            'Si el nombre desempato, las filas de ' . self::CODIGO_PACK . ' ya no son ambiguas.'
        );
    }

    /**
     * La otra mitad del test de arriba: con la MISMA politica de colision pero SIN la
     * opcion, el comportamiento sigue siendo el de hoy -- las dos filas se saltean como
     * ambiguas y ninguno de los dos articulos se toca.
     *
     * Sin este test, el de arriba podria pasar por un cambio que arregle la ambiguedad
     * para todo el mundo, que no es lo pedido: la opcion tiene que ser una eleccion.
     *
     * @return void
     */
    public function test_sin_la_opcion_la_politica_de_saltear_sigue_salteando()
    {
        $suelto = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $pack   = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        $import = $this->importar(self::ARCHIVO, $this->config([
            'permitir_provider_code_repetido' => false,
            'desempatar_por_nombre'           => false,
        ]));

        $this->assertDecimal(1.0, Article::find($suelto->id)->cost, 'Sin la opcion, la fila ambigua no toca nada.');
        $this->assertDecimal(2.0, Article::find($pack->id)->cost,   'Sin la opcion, la fila ambigua no toca nada.');

        $this->assertSame(
            2,
            $this->conflictos($import, 'ambiguo'),
            'Las dos filas de ' . self::CODIGO_PACK . ' se reportan como ambiguas, como siempre.'
        );
    }

    /**
     * NO REGRESION: con la opcion APAGADA (el default), el comportamiento tiene que ser
     * identico al de hoy -- las dos filas escriben en los dos articulos y gana la ultima.
     *
     * 🔴 Este test fija a proposito el comportamiento VIEJO, que es "malo" para el caso de
     * DobleP. Es lo que hace que la opcion sea una eleccion del usuario y no un cambio de
     * comportamiento por la espalda: una cuenta que hoy importa asi tiene que seguir
     * importando igual hasta que alguien prenda la casilla.
     *
     * @return void
     */
    public function test_al_reimportar_sin_la_opcion_el_comportamiento_no_cambia()
    {
        $suelto = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $pack   = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => false]));

        $this->assertCount(2, $this->articulos_con_codigo(self::CODIGO_PACK));

        /* Las dos filas escribieron en los dos: gana la ultima (2140) en ambos. */
        $this->assertDecimal(2140.00, Article::find($suelto->id)->cost, 'Sin la opcion, el suelto queda con el costo de la ULTIMA fila.');
        $this->assertDecimal(2140.00, Article::find($pack->id)->cost,   'Sin la opcion, el pack queda con el costo de la ULTIMA fila.');
    }

    /**
     * Sin pasar la clave en el payload -- que es lo que manda hoy cualquier cliente que no
     * conoce la opcion -- el resultado tiene que ser el mismo que con la opcion apagada.
     *
     * Es el chequeo de compatibilidad hacia atras del contrato: la clave es nueva, con
     * default false, y una SPA vieja no la manda.
     *
     * @return void
     */
    public function test_sin_mandar_la_clave_el_comportamiento_es_el_de_siempre()
    {
        $suelto = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $pack   = $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        /* config() no incluye 'desempatar_por_nombre': el request sale sin esa clave. */
        $this->importar(self::ARCHIVO, $this->config());

        $this->assertDecimal(2140.00, Article::find($suelto->id)->cost);
        $this->assertDecimal(2140.00, Article::find($pack->id)->cost);

        $this->assertSame(
            0,
            $this->conflictos_de_desempate(),
            'Sin la opcion no se registra ningun conflicto de desempate: la funcionalidad esta apagada entera.'
        );
    }

    /* ==================================================================
     * El mismo codigo en DOS proveedores
     * ================================================================== */

    /**
     * 🔴 EL DESEMPATE NO SE PUEDE QUEDAR CON EL ARTICULO DE OTRO PROVEEDOR.
     *
     * Con `actualizar_articulos_de_otro_proveedor` prendido, los articulos de otros
     * proveedores que usan el mismo codigo entran al conjunto de candidatos. Si el
     * desempate elige uno de esos, le escribe precio, costo e identificadores y deja SIN
     * ACTUALIZAR el del proveedor que se esta importando. Antes de esta mision se
     * actualizaban los dos, asi que el correcto al menos quedaba bien: elegir el ajeno es
     * una regresion silenciosa -- deja los numeros mal y no falla nada.
     *
     * La fila PC-XPROV se llama igual que el articulo del proveedor B y distinto que el
     * del A. Con la preferencia puesta, el universo del desempate son los del proveedor A,
     * ahi no coincide ninguno, y se cae al comportamiento de siempre: se actualizan los
     * dos. Lo que NO puede pasar es que el de A quede en su costo viejo.
     *
     * @return void
     */
    public function test_el_desempate_no_se_queda_con_el_articulo_de_otro_proveedor()
    {
        $de_a = $this->crear_articulo('PC-XPROV', 'Nombre viejo del articulo de A', 1.0, $this->providers['A']->id);
        $de_b = $this->crear_articulo('PC-XPROV', 'Nombre del articulo de B',       2.0, $this->providers['B']->id);

        $this->importar('23_desempate_otro_proveedor.xlsx', $this->config_otro_proveedor());

        $this->assertDecimal(
            999.00,
            Article::find($de_a->id)->cost,
            'El articulo del proveedor de la importacion tiene que actualizarse SIEMPRE.'
        );

        $this->assertDecimal(
            999.00,
            Article::find($de_b->id)->cost,
            'Sin desempate posible dentro del proveedor A, se cae al comportamiento de siempre: los dos.'
        );
    }

    /**
     * La otra mitad: cuando el nombre SI coincide con el articulo del proveedor de la
     * importacion, el desempate resuelve ahi y el de otro proveedor queda sin tocar.
     *
     * Sin este test, "preferir el proveedor actual" podria implementarse como "no
     * desempatar nunca si hay articulos de otros proveedores", que apagaria la opcion
     * justo en las cuentas que actualizan cruzado.
     *
     * @return void
     */
    public function test_el_desempate_resuelve_dentro_del_proveedor_de_la_importacion()
    {
        $de_a = $this->crear_articulo('PC-XPROV2', 'Nombre del articulo de A2', 3.0, $this->providers['A']->id);
        $de_b = $this->crear_articulo('PC-XPROV2', 'Nombre del articulo de B2', 4.0, $this->providers['B']->id);

        $this->importar('23_desempate_otro_proveedor.xlsx', $this->config_otro_proveedor());

        $this->assertDecimal(555.00, Article::find($de_a->id)->cost, 'El de A es el que desempata la fila.');
        $this->assertDecimal(4.00,   Article::find($de_b->id)->cost, 'El de B no se toca.');
    }

    /**
     * Configuracion de los dos tests de arriba: importacion del proveedor A, con
     * actualizacion cruzada habilitada y el desempate prendido.
     *
     * @return array
     */
    protected function config_otro_proveedor()
    {
        return array_merge(self::columnas(), [
            'provider_id'                            => $this->providers['A']->id,
            'permitir_provider_code_repetido'        => true,
            'actualizar_articulos_de_otro_proveedor' => true,
            'desempatar_por_nombre'                  => true,
        ]);
    }

    /* ==================================================================
     * Cuando el nombre NO desempata
     * ================================================================== */

    /**
     * Dos articulos de la base con el MISMO codigo Y el MISMO nombre: el nombre no
     * distingue nada.
     *
     * 🔴 NO se crea nada nuevo en silencio. Se cae al comportamiento de siempre (los dos
     * se actualizan) y queda un import_conflict para que el usuario lo vea en el historial.
     * Un articulo creado sin aviso duplica el catalogo de a poco y no lo detecta nadie.
     *
     * @return void
     */
    public function test_mismo_codigo_y_mismo_nombre_no_desempata_y_deja_conflicto()
    {
        $uno = $this->crear_articulo(self::CODIGO_IGUAL, 'Nombre igual repetido', 1.0);
        $dos = $this->crear_articulo(self::CODIGO_IGUAL, 'Nombre igual repetido', 2.0);

        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => true]));

        $this->assertCount(
            2,
            $this->articulos_con_codigo(self::CODIGO_IGUAL),
            'No se crea un tercer articulo: cuando el desempate no resuelve, NO se crea nada.'
        );

        /* Comportamiento de siempre: la fila escribio en los dos. */
        $this->assertDecimal(500.00, Article::find($uno->id)->cost);
        $this->assertDecimal(500.00, Article::find($dos->id)->cost);

        $conflictos = $this->conflictos_de_desempate_por_codigo();

        $this->assertArrayHasKey(
            self::CODIGO_IGUAL,
            $conflictos,
            'Tiene que quedar registrado que el desempate de ' . self::CODIGO_IGUAL . ' no resolvio.'
        );
    }

    /**
     * El proveedor cambio la redaccion entre listas: el nombre de la fila no coincide con
     * NINGUNO de los dos articulos de la base.
     *
     * Es el otro motivo por el que el desempate puede no resolver, y tiene el mismo
     * tratamiento: comportamiento de siempre + conflicto registrado. Que los dos motivos
     * esten cubiertos importa porque son ramas distintas del codigo (0 coincidencias vs.
     * mas de 1).
     *
     * @return void
     */
    public function test_nombre_que_no_coincide_con_ninguno_no_desempata_y_deja_conflicto()
    {
        $uno = $this->crear_articulo(self::CODIGO_REDACTADO, 'Nombre viejo del proveedor A', 1.0);
        $dos = $this->crear_articulo(self::CODIGO_REDACTADO, 'Nombre viejo del proveedor B', 2.0);

        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => true]));

        $this->assertCount(
            2,
            $this->articulos_con_codigo(self::CODIGO_REDACTADO),
            'Tampoco se crea nada nuevo cuando el nombre no coincide con ninguno.'
        );

        $this->assertDecimal(700.00, Article::find($uno->id)->cost);
        $this->assertDecimal(700.00, Article::find($dos->id)->cost);

        $conflictos = $this->conflictos_de_desempate_por_codigo();

        $this->assertArrayHasKey(
            self::CODIGO_REDACTADO,
            $conflictos,
            'Tiene que quedar registrado que el desempate de ' . self::CODIGO_REDACTADO . ' no resolvio.'
        );
    }

    /**
     * Cuando el desempate SI resuelve, no se registra ningun conflicto: la fila se aplico
     * exactamente como el usuario pidio.
     *
     * Es el complemento necesario de los dos de arriba. Sin este, un arreglo que registrara
     * el conflicto SIEMPRE (resuelva o no) los pasaria a los dos, y el historial de
     * importacion le mostraria al usuario un problema que no existe.
     *
     * @return void
     */
    public function test_cuando_el_desempate_resuelve_no_deja_conflicto()
    {
        $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_SUELTO, 1.0);
        $this->crear_articulo(self::CODIGO_PACK, self::NOMBRE_PACK,   2.0);

        $this->importar(self::ARCHIVO, $this->config(['desempatar_por_nombre' => true]));

        $conflictos = $this->conflictos_de_desempate_por_codigo();

        $this->assertArrayNotHasKey(
            self::CODIGO_PACK,
            $conflictos,
            'El desempate de ' . self::CODIGO_PACK . ' resolvio: no tiene que dejar conflicto.'
        );
    }

    /* ==================================================================
     * Helpers de conflictos
     * ================================================================== */

    /**
     * Cantidad de conflictos de tipo desempate_por_nombre_sin_resolver de la ultima
     * importacion del tenant.
     *
     * @return int
     */
    protected function conflictos_de_desempate()
    {
        return DB::table('import_conflicts')
                    ->where('tipo', 'desempate_por_nombre_sin_resolver')
                    ->count();
    }

    /**
     * Conflictos de desempate agrupados por el codigo que no se pudo resolver.
     *
     * @return array  ['PC-IGUAL' => 1, ...]
     */
    protected function conflictos_de_desempate_por_codigo()
    {
        $filas = DB::table('import_conflicts')
                    ->where('tipo', 'desempate_por_nombre_sin_resolver')
                    ->get();

        $por_codigo = [];

        foreach ($filas as $fila) {

            $codigo = (string) $fila->valor;

            if (!isset($por_codigo[$codigo])) {
                $por_codigo[$codigo] = 0;
            }

            $por_codigo[$codigo]++;
        }

        return $por_codigo;
    }
}
