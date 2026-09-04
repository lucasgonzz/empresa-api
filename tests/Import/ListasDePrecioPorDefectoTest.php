<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use Illuminate\Support\Facades\DB;

/**
 * Listas de precio por defecto al importar.
 *
 * Pedido de Lucas (24/8/2026): un negocio que trabaja con listas de precio importa un Excel
 * que no dice NADA de listas (ninguna columna de margen ni de precio final mapeada), y los
 * articulos que la importacion crea tienen que quedar igual relacionados con todas las listas,
 * con el margen por defecto de cada una.
 *
 * Lo que estaba roto: ProcessRow::obtener_price_types() exigia
 * $this->se_importaron_price_types -- que solo se prende si el mapeo trae alguna columna
 * %_<lista>, $_final_<lista> o setear_precio_final_<lista> -- y sin eso devolvia [], asi que
 * ActualizarBBDD::asignar_price_types() no insertaba ni una fila en article_price_type.
 *
 * 🔴 Por que hay dos escenarios y no uno: la relacion la puede terminar creando un SEGUNDO
 * camino, ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() (via
 * ActualizarBBDD::set_precios_finales() -> ArticleHelper::setFinalPrice()), que hace
 * syncWithoutDetaching sin condicion. Ese camino tapaba parte del sintoma, pero:
 *
 *   1. NO escribe el default de la lista en incluir_en_excel_para_clientes: lo deja en el 0
 *      del esquema. Eso lo mide
 *      test_un_articulo_creado_sin_columnas_de_lista_queda_relacionado_con_todas_las_listas.
 *   2. Con la extension `ventas_en_dolares` no corre nunca: setFinalPrice rutea a
 *      ArticlePriceTypeMonedaHelper, que no toca article_price_type en ningun lado. Ahi el
 *      sintoma se ve completo -- CERO filas -- y es el que reprodujo el reporte de Lucas.
 *      Eso lo mide test_con_ventas_en_dolares_el_articulo_creado_igual_queda_relacionado.
 *
 * Medido el 24/8/2026 contra el arbol sin el arreglo: sin la extension, 2 filas con
 * percentage correcto pero incluir_en_excel_para_clientes en 0; con la extension, 0 filas.
 *
 * 🔴 Lo que el arreglo NO propaga, a proposito, es `setear_precio_final`: ver el comentario de
 * ProcessRow::add_price_type_data() y el test
 * test_el_precio_de_una_lista_que_setea_precio_final_sigue_al_costo. Propagarlo dejaba el precio
 * de esa lista congelado desde el segundo recalculo.
 *
 * ⚠️ Se lee con DB::table('article_price_type') y no con la relacion de Eloquent a proposito:
 * lo que se mide son filas de una tabla pivot, y article_price_type NO tiene indice unico
 * sobre (article_id, price_type_id) -- el INSERT IGNORE de asignar_price_types() no deduplica
 * nada, solo ignora errores. Por eso las aserciones son de cantidad EXACTA, nunca de "al
 * menos una".
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class ListasDePrecioPorDefectoTest extends ImportTestCase
{
    /** Fixture cuyo mapeo por defecto NO trae ninguna columna de lista: el escenario roto. */
    const ARCHIVO = '04_stock.xlsx';

    /** Fixture donde tres filas con el mismo bar_code nuevo crean UN SOLO articulo. */
    const ARCHIVO_REPETIDOS = '02_codigos_de_barra_repetidos.xlsx';

    /** provider_code del unico articulo que 04_stock.xlsx crea. */
    const CODIGO_NUEVO = 'PC-STK-NEW';

    /** @var \App\Models\PriceType */
    protected $lista_a;

    /** @var \App\Models\PriceType */
    protected $lista_b;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * El tenant de prueba no trabaja con listas de precio (users.listas_de_precio = 0) y no
         * tiene ninguna fila en price_types, asi que el escenario se fabrica aca, DENTRO de la
         * transaccion de DatabaseTransactions: al terminar cada test la base vuelve sola.
         *
         * Va despues de parent::setUp() a proposito: ImportTestSeeder siembra A1..A15 llamando a
         * ArticleHelper::setFinalPrice(), que con el flag ya prendido los relacionaria con las
         * listas ANTES de que corra ninguna importacion, y entonces
         * test_los_articulos_que_ya_existian_no_cambian_de_listas mediria el sembrado y no el
         * import.
         */
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        /*
         * Los dos defaults van CRUZADOS a proposito (A: incluir 1 / setear 0; B: incluir 0 /
         * setear 1). Con los dos iguales, un arreglo que escribiera una constante en vez del
         * default de la lista pasaria el test igual.
         */
        $this->lista_a = $this->crear_price_type('Lista A', 30, 1, 0, 1);
        $this->lista_b = $this->crear_price_type('Lista B', 40, 0, 1, 2);
    }

    /**
     * Se asigna propiedad por propiedad y no con PriceType::create() por el mismo motivo que
     * ImportTestSeeder::crear_article(): no depender de $fillable.
     *
     * @param  string $name
     * @param  float  $percentage
     * @param  int    $incluir_en_lista_de_precios_de_excel
     * @param  int    $setear_precio_final
     * @param  int    $position
     * @return \App\Models\PriceType
     */
    protected function crear_price_type($name, $percentage, $incluir_en_lista_de_precios_de_excel, $setear_precio_final, $position)
    {
        $price_type = new PriceType();

        $price_type->name                                 = $name;
        $price_type->percentage                           = $percentage;
        $price_type->incluir_en_lista_de_precios_de_excel = $incluir_en_lista_de_precios_de_excel;
        $price_type->setear_precio_final                  = $setear_precio_final;
        $price_type->position                             = $position;
        $price_type->user_id                              = $this->tenant->id;

        $price_type->save();

        return $price_type;
    }

    /**
     * Filas de la pivot de un articulo, indexadas por price_type_id.
     *
     * @param  int $article_id
     * @return array
     */
    protected function pivots($article_id)
    {
        $filas = DB::table('article_price_type')
                    ->where('article_id', $article_id)
                    ->orderBy('price_type_id')
                    ->get();

        $por_lista = [];

        foreach ($filas as $fila) {
            $por_lista[(int) $fila->price_type_id] = $fila;
        }

        /* Si hubiera filas duplicadas, el array indexado las taparia: se chequea antes. */
        $this->assertSame(
            count($filas),
            count($por_lista),
            'Hay filas DUPLICADAS en article_price_type para el articulo ' . $article_id
        );

        return $por_lista;
    }

    /**
     * @param  string $codigo
     * @return \App\Models\Article
     */
    protected function articulo_creado($codigo)
    {
        $articulo = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', $codigo)
                            ->first();

        $this->assertNotNull($articulo, 'La importacion tenia que crear el articulo ' . $codigo);

        return $articulo;
    }

    /**
     * Prende la extension `ventas_en_dolares` para el tenant de prueba.
     *
     * La tabla extencion_empresas esta vacia en la base de tests, asi que la fila se crea aca.
     * Se revierte con la transaccion del test, igual que todo lo demas.
     *
     * @return void
     */
    protected function prender_ventas_en_dolares()
    {
        $extencion = ExtencionEmpresa::where('slug', 'ventas_en_dolares')->first();

        if (is_null($extencion)) {
            $extencion = new ExtencionEmpresa();
            $extencion->name = 'Ventas en dolares';
            $extencion->slug = 'ventas_en_dolares';
            $extencion->save();
        }

        $this->tenant->extencions()->syncWithoutDetaching($extencion->id);
        $this->tenant->load('extencions');
    }

    /**
     * El test central: sin ninguna columna de lista en el mapeo, el articulo creado queda
     * relacionado con TODAS las listas y con los defaults de cada una.
     *
     * @return void
     */
    public function test_un_articulo_creado_sin_columnas_de_lista_queda_relacionado_con_todas_las_listas()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $nuevo  = $this->articulo_creado(self::CODIGO_NUEVO);
        $pivots = $this->pivots($nuevo->id);

        $this->assertCount(2, $pivots, 'Tiene que haber una fila por cada lista de precio, ni una mas');

        $this->assertArrayHasKey($this->lista_a->id, $pivots, 'Falta la relacion con Lista A');
        $this->assertArrayHasKey($this->lista_b->id, $pivots, 'Falta la relacion con Lista B');

        $a = $pivots[$this->lista_a->id];
        $b = $pivots[$this->lista_b->id];

        $this->assertDecimal(30, $a->percentage, 'Lista A tiene que quedar con su margen por defecto');
        $this->assertDecimal(40, $b->percentage, 'Lista B tiene que quedar con su margen por defecto');

        /*
         * `incluir_en_excel_para_clientes` SI toma el default de la lista: antes del arreglo
         * quedaba en el 0 del esquema porque el camino de setFinalPrice no lo mira. Es la
         * diferencia que pone rojo a este test sin el arreglo, y alinea al import con lo que ya
         * hace el ABM (ArticlePriceTypeHelper::get_incluir_en_excel_para_clientes()).
         */
        $this->assertSame(1, (int) $a->incluir_en_excel_para_clientes, 'Lista A: incluir_en_lista_de_precios_de_excel = 1');
        $this->assertSame(0, (int) $b->incluir_en_excel_para_clientes, 'Lista B: incluir_en_lista_de_precios_de_excel = 0');

        /*
         * 🔴 `setear_precio_final` NO se propaga, aunque la Lista B lo tenga en 1 por defecto, y
         * esto es deliberado: ver el comentario de ProcessRow::add_price_type_data(). Un Excel
         * que no habla de listas no esta fijando ningun precio a mano, y propagar la bandera
         * dejaba el precio de esa lista congelado para siempre.
         * El test que mide la consecuencia es
         * test_el_precio_de_una_lista_que_setea_precio_final_sigue_al_costo.
         */
        $this->assertSame(0, (int) $a->setear_precio_final, 'Lista A: setear_precio_final = 0');
        $this->assertSame(0, (int) $b->setear_precio_final, 'Lista B: setear_precio_final NO se propaga');
    }

    /**
     * 🔴 El test de plata de esta mision.
     *
     * Sin esta guarda, el arreglo introducia una regresion: la fila de pivot se insertaba con el
     * `setear_precio_final` de la lista, despues set_precios_finales() escribia ahi el precio
     * calculado por margen, y a partir del segundo recalculo
     * ArticlePricesHelper::aplicar_precios_segun_listas_de_precios() (~:386) lo leia como "precio
     * fijado a mano" y derivaba el margen al reves.
     *
     * Medido el 24/8/2026 con la regresion en el arbol: al duplicar el costo, la lista con
     * setear_precio_final = 1 por defecto quedaba clavada en 169.40 con margen -30.00, mientras
     * la otra pasaba de 157.30 a 314.60. Los mismos numeros que asierta este test salen de
     * `develop` sin el arreglo, o sea que la conducta correcta es la de siempre.
     *
     * @return void
     */
    public function test_el_precio_de_una_lista_que_setea_precio_final_sigue_al_costo()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $nuevo = $this->articulo_creado(self::CODIGO_NUEVO);

        $antes = $this->pivots($nuevo->id);

        $this->assertDecimal(157.30, $antes[$this->lista_a->id]->final_price, 'Lista A al importar');
        $this->assertDecimal(169.40, $antes[$this->lista_b->id]->final_price, 'Lista B al importar');

        /* Se duplica el costo y se recalcula, igual que haria una compra o un guardado del ABM. */
        $nuevo->refresh();
        $nuevo->cost = (float) $nuevo->cost * 2;
        $nuevo->save();

        ArticleHelper::setFinalPrice($nuevo, $this->tenant->id);

        $despues = $this->pivots($nuevo->id);

        $this->assertDecimal(314.60, $despues[$this->lista_a->id]->final_price, 'Lista A sigue al costo');
        $this->assertDecimal(338.80, $despues[$this->lista_b->id]->final_price, 'Lista B TAMBIEN sigue al costo: no quedo congelada');

        $this->assertDecimal(30, $despues[$this->lista_a->id]->percentage, 'Lista A conserva su margen');
        $this->assertDecimal(40, $despues[$this->lista_b->id]->percentage, 'Lista B conserva su margen, no uno derivado al reves');
    }

    /**
     * Tres filas con el mismo provider_code NUEVO crean tres articulos distintos, pero
     * ActualizarBBDD::get_article_model_from_cache() los resuelve a todos al mismo modelo con
     * un ->first() por provider_code.
     *
     * Sin la guarda de deduplicacion de asignar_price_types(), el arreglo hacia que el primero
     * terminara con 6 filas de pivot (medido el 24/8/2026): article_price_type NO tiene indice
     * unico, asi que el INSERT IGNORE no deduplica nada.
     *
     * 🔴 El ESCENARIO cambio el 2/9/2026 (mision fix-ultima-gana-con-actualizar-todos), la
     * afirmacion no. Este test conseguia sus tres articulos con
     * `permitir_provider_code_repetido = true` y sin decir nada sobre las filas repetidas del
     * archivo -- y eso funcionaba por el defecto que esa mision arreglo: el flag de la BASE
     * apagaba la deteccion de repetidos DENTRO del archivo, asi que cada fila creaba su
     * articulo aunque el default fuera 'ultima_gana' (fusionar). Ahora los tres articulos se
     * piden por la via legitima: 'productos_distintos', que es la respuesta del usuario a
     * "estas filas repetidas son productos distintos".
     *
     * No se toco el numero 3 ni ninguna asercion: lo que este test custodia es que NO se
     * dupliquen las filas de pivot, y para eso hacen falta tres articulos que compartan
     * provider_code -- da igual por que camino se hayan creado.
     *
     * ⚠️ Lo que este test NO arregla y queda como limite conocido: los articulos 2 y 3 no reciben
     * filas de asignar_price_types() (el modelo resuelto no es el suyo). En una cuenta normal los
     * rescata setFinalPrice; en una cuenta con `ventas_en_dolares` quedan sin listas. Es el
     * defecto preexistente de get_article_model_from_cache(), que tambien afecta a descuentos y
     * recargos y esta fuera del alcance de aquella mision.
     *
     * @return void
     */
    public function test_provider_code_repetido_no_duplica_filas_de_pivot()
    {
        $this->importar('07_repetidos_en_el_archivo.xlsx', [
            'provider_id'                     => null,
            'permitir_provider_code_repetido' => true,
            'filas_repetidas_del_archivo'     => 'productos_distintos',
        ]);

        $creados = Article::where('user_id', $this->tenant->id)
                            ->where('provider_code', 'PC-R-Z')
                            ->orderBy('id')
                            ->get();

        $this->assertCount(3, $creados, 'Las tres filas con el mismo provider_code nuevo crean tres articulos');

        foreach ($creados as $articulo) {

            $this->assertCount(
                2,
                $this->pivots($articulo->id),
                'El articulo ' . $articulo->id . ' tiene que tener una fila por lista, sin duplicados'
            );
        }
    }

    /**
     * El sintoma literal que reporto Lucas: con `ventas_en_dolares` no hay ningun otro camino
     * que relacione el articulo, asi que sin el arreglo quedaba con CERO listas.
     *
     * @return void
     */
    public function test_con_ventas_en_dolares_el_articulo_creado_igual_queda_relacionado()
    {
        $this->prender_ventas_en_dolares();

        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $nuevo  = $this->articulo_creado(self::CODIGO_NUEVO);
        $pivots = $this->pivots($nuevo->id);

        $this->assertCount(2, $pivots, 'Con ventas_en_dolares el articulo creado tambien va relacionado con las dos listas');

        $this->assertDecimal(30, $pivots[$this->lista_a->id]->percentage, 'Lista A con su margen por defecto');
        $this->assertDecimal(40, $pivots[$this->lista_b->id]->percentage, 'Lista B con su margen por defecto');
    }

    /**
     * Alcance: el arreglo es solo para los articulos que la importacion CREA (decision de
     * Lucas, 24/8/2026). Un articulo que ya existia no puede cambiar de listas por este camino.
     *
     * Los valores esperados salen de la medicion del 24/8/2026 contra el arbol sin el arreglo:
     * A1 termina con dos filas puestas por setFinalPrice, con el margen de cada lista y las dos
     * banderas en 0. Si el arreglo se "simplificara" sacando el flag de artículo nuevo y
     * dejando solo uses_listas_de_precio(), A1 pasaria por asignar_price_types() y las dos
     * banderas cambiarian: este test es la red que lo detecta.
     *
     * @return void
     */
    public function test_los_articulos_que_ya_existian_no_cambian_de_listas()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $pivots = $this->pivots($this->recargar('A1')->id);

        $this->assertCount(2, $pivots, 'A1 sigue con las dos filas que le pone setFinalPrice');

        $this->assertSame(0, (int) $pivots[$this->lista_a->id]->incluir_en_excel_para_clientes, 'A1 no pasa por asignar_price_types()');
        $this->assertSame(0, (int) $pivots[$this->lista_a->id]->setear_precio_final,            'A1 no pasa por asignar_price_types()');
        $this->assertSame(0, (int) $pivots[$this->lista_b->id]->incluir_en_excel_para_clientes, 'A1 no pasa por asignar_price_types()');
        $this->assertSame(0, (int) $pivots[$this->lista_b->id]->setear_precio_final,            'A1 no pasa por asignar_price_types()');
    }

    /**
     * Borde: la cuenta NO trabaja con listas de precio. Aunque existan PriceType cargadas, no
     * se relaciona nada.
     *
     * @return void
     */
    public function test_sin_listas_de_precio_no_se_relaciona_nada()
    {
        $this->tenant->listas_de_precio = 0;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertCount(
            0,
            $this->pivots($this->articulo_creado(self::CODIGO_NUEVO)->id),
            'Una cuenta sin listas de precio no puede quedar con relaciones de lista'
        );
    }

    /**
     * No regresion del camino que YA funcionaba: con una columna de lista mapeada, la lista
     * mapeada toma el valor del Excel y la otra queda con su default. Tiene que estar verde
     * antes y despues del arreglo.
     *
     * `prop_%_lista_a` apunta a la columna 6 del fixture (el precio, 200 para PC-STK-NEW):
     * GeneralHelper::getImportColumns() corta en el PRIMER guion bajo, asi que la clave que
     * queda es `%_lista_a`, exactamente la que arma ProcessRow::get_price_type_row_name().
     *
     * @return void
     */
    public function test_con_una_columna_de_lista_mapeada_el_comportamiento_no_cambia()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null, 'prop_%_lista_a' => 6]);

        $pivots = $this->pivots($this->articulo_creado(self::CODIGO_NUEVO)->id);

        $this->assertCount(2, $pivots);

        $this->assertDecimal(200, $pivots[$this->lista_a->id]->percentage, 'Lista A toma el margen del Excel');
        $this->assertDecimal(40,  $pivots[$this->lista_b->id]->percentage, 'Lista B, sin columna, queda con su default');
    }

    /**
     * Borde: la cuenta trabaja con listas pero no tiene ninguna creada. La importacion tiene
     * que terminar igual y no armar un INSERT sin valores.
     *
     * @return void
     */
    public function test_una_cuenta_con_listas_pero_sin_ninguna_lista_creada_importa_igual()
    {
        PriceType::where('user_id', $this->tenant->id)->delete();

        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertCount(
            0,
            $this->pivots($this->articulo_creado(self::CODIGO_NUEVO)->id),
            'Sin listas creadas no hay nada que relacionar'
        );
    }

    /**
     * Tres filas con el mismo bar_code nuevo crean UN SOLO articulo, y las filas 2 y 3 pasan
     * por merge_fila_en_articulo_para_crear_pendiente(), o sea por el segundo call site de
     * obtener_price_types(). Ese call site tambien tiene que pedir las listas por defecto: si
     * devolviera [], el merge lo pisa entero sobre price_types_data y la fila 3 le BORRARIA al
     * articulo las listas que la fila 1 le habia conseguido.
     *
     * @return void
     */
    public function test_dos_filas_que_crean_el_mismo_articulo_no_duplican_las_listas()
    {
        $this->importar(self::ARCHIVO_REPETIDOS, ['provider_id' => null]);

        $nuevo = Article::where('user_id', $this->tenant->id)
                        ->where('bar_code', '7799001')
                        ->first();

        $this->assertNotNull($nuevo, 'Las tres filas con el mismo bar_code nuevo crean un articulo');

        $pivots = $this->pivots($nuevo->id);

        $this->assertCount(2, $pivots, 'Una fila de pivot por lista: ni cero, ni duplicadas');

        $this->assertDecimal(30, $pivots[$this->lista_a->id]->percentage);
        $this->assertDecimal(40, $pivots[$this->lista_b->id]->percentage);

        $this->assertSame(1, (int) $pivots[$this->lista_a->id]->incluir_en_excel_para_clientes, 'La lista mapeada toma el default de incluir');
        $this->assertSame(0, (int) $pivots[$this->lista_b->id]->setear_precio_final, 'setear_precio_final no se propaga: ver add_price_type_data()');
    }
}
