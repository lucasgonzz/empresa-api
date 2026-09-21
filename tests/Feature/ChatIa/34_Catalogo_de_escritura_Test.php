<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Helpers\asistente_ia\CatalogoDeEscrituraIaHelper as Catalogo;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-omnisciente (21/9/2026, bloque B) — el catálogo de escritura genérica: qué
 * entidades se pueden crear, editar y borrar por el controller de la pantalla, y con qué campos.
 *
 * Lo que protege:
 *
 * - Que cada entidad escribible tenga su ruta de recurso, su tabla con user_id y un método real
 *   en su controller; y que ninguna excluida entre.
 * - 🔴 La guarda de revisión: toda ruta de recurso con store() y tabla scopeada por dueño está en
 *   ENTIDADES (revisada) o en EXCLUIDAS (con motivo). Una ruta nueva hace fallar este test hasta
 *   que alguien la mire.
 * - Que `sale` entre solo con baja, `expense` sin alta y `pending` solo con baja: las tres tienen
 *   herramienta propia para lo demás.
 * - Que las columnas calculadas de `article` (precio final, stock, costo real, embedding...) no se
 *   ofrezcan, y que las sensibles y las de sistema no salgan de ninguna entidad.
 * - 🔴 Que el store() de cada entidad con alta LEA sus obligatorios del request. Es una guarda por
 *   regex sobre el código del controller (claves_que_lee), no un análisis del PHP: si un store()
 *   deja de leer `name`, el catálogo lo saca solo y este test lo denuncia.
 *
 * @group chat-ia
 */
class Catalogo_de_escritura_Test extends EmpresaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Catalogo::olvidar();
    }

    /**
     * @test
     */
    public function cada_entidad_escribible_tiene_ruta_tabla_con_user_id_y_metodo_en_su_controller()
    {
        $entidades = Catalogo::entidades();

        $this->assertNotEmpty($entidades);

        $rutas = [];

        foreach (Route::getRoutes() as $ruta) {
            $rutas[$ruta->uri()][] = ['metodos' => $ruta->methods(), 'accion' => $ruta->getActionName()];
        }

        foreach ($entidades as $entidad => $resumen) {
            $declaracion = Catalogo::declaracion($entidad);

            $this->assertNotNull($declaracion, $entidad);
            $this->assertTrue(Schema::hasTable($declaracion['tabla']), $entidad . ': la tabla ' . $declaracion['tabla'] . ' no existe');
            $this->assertTrue(Schema::hasColumn($declaracion['tabla'], 'user_id'), $entidad . ': la tabla no tiene user_id');
            $this->assertSame(Str::plural($entidad), $declaracion['tabla']);
            $this->assertSame(str_replace('_', '-', $entidad), $declaracion['slug']);
            $this->assertNotEmpty($resumen['operaciones'], $entidad . ' no tiene operaciones');
            $this->assertNotEmpty($resumen['etiqueta']);
            $this->assertNotEmpty($resumen['etiqueta_singular']);

            foreach ($resumen['operaciones'] as $operacion) {
                $controller = Catalogo::controller_y_metodo($entidad, $operacion);

                $this->assertTrue(class_exists($controller['clase']), $entidad . '/' . $operacion . ': ' . $controller['clase']);
                $this->assertTrue(method_exists($controller['clase'], $controller['metodo']), $entidad . '/' . $operacion . ': ' . $controller['metodo']);

                $uri = 'api/' . $declaracion['slug'] . ($operacion === Catalogo::OP_ALTA ? '' : '/{' . $this->parametro_de_ruta($rutas, $declaracion['slug']) . '}');
                $http = [Catalogo::OP_ALTA => 'POST', Catalogo::OP_EDICION => 'PUT', Catalogo::OP_BAJA => 'DELETE'][$operacion];

                $encontrada = false;

                foreach (isset($rutas[$uri]) ? $rutas[$uri] : [] as $registrada) {
                    if (in_array($http, $registrada['metodos'], true) && $registrada['accion'] === $controller['clase'] . '@' . $controller['metodo']) {
                        $encontrada = true;
                    }
                }

                $this->assertTrue($encontrada, $entidad . '/' . $operacion . ': no hay ruta ' . $http . ' ' . $uri . ' → ' . $controller['clase'] . '@' . $controller['metodo']);
            }
        }
    }

    /**
     * El nombre del parámetro de la ruta `api/{slug}/{x}` (Route::resource usa el singular del slug).
     *
     * @param  array  $rutas
     * @param  string  $slug
     * @return string
     */
    protected function parametro_de_ruta(array $rutas, $slug)
    {
        foreach (array_keys($rutas) as $uri) {
            if (preg_match('#^api/' . preg_quote($slug, '#') . '/\{([a-zA-Z_]+)\}$#', $uri, $m)) {
                return $m[1];
            }
        }

        return 'id';
    }

    /**
     * 🔴 La guarda de revisión, en las dos direcciones.
     *
     * @test
     */
    public function ninguna_excluida_entra_y_no_queda_ninguna_ruta_sin_revisar()
    {
        $entidades = array_keys(Catalogo::entidades());

        foreach (array_keys(Catalogo::EXCLUIDAS) as $excluida) {
            $this->assertNotContains($excluida, $entidades, $excluida . ' está excluida y entró igual');
            $this->assertFalse(Catalogo::existe($excluida));
        }

        $this->assertSame([], Catalogo::entidades_sin_revisar(), 'Hay rutas de recurso con tabla scopeada por dueño que nadie revisó: van a ENTIDADES (con la nota de qué se miró) o a EXCLUIDAS (con el motivo).');
        $this->assertSame([], Catalogo::entidades_que_no_resolvieron(), 'Una entidad curada dejó de resolver (ruta, tabla o user_id).');

        // Y las del plan, nombradas: nunca por el genérico.
        foreach (['combo', 'client_offer', 'provider_order', 'budget', 'order', 'payment_method', 'platform_connector', 'stock_movement', 'deposit_movement', 'movimiento_entre_caja', 'caja', 'whatsapp_chats', 'whatsapp_templates', 'pdf_column_profiles'] as $afuera) {
            $this->assertFalse(Catalogo::existe($afuera), $afuera);
        }
    }

    /**
     * @test
     */
    public function sale_entra_solo_con_baja_expense_sin_alta_y_pending_solo_con_baja()
    {
        $this->assertSame([Catalogo::OP_BAJA], Catalogo::entidades()['sale']['operaciones']);
        $this->assertSame([Catalogo::OP_EDICION, Catalogo::OP_BAJA], Catalogo::entidades()['expense']['operaciones']);
        $this->assertSame([Catalogo::OP_BAJA], Catalogo::entidades()['pending']['operaciones']);

        $this->assertFalse(Catalogo::permite('sale', Catalogo::OP_ALTA));
        $this->assertFalse(Catalogo::permite('sale', Catalogo::OP_EDICION));
        $this->assertTrue(Catalogo::permite('sale', Catalogo::OP_BAJA));
        $this->assertFalse(Catalogo::permite('expense', Catalogo::OP_ALTA));
        $this->assertTrue(Catalogo::permite('expense', Catalogo::OP_EDICION));
        $this->assertFalse(Catalogo::permite('pending', Catalogo::OP_EDICION));

        // La baja de una venta va al destroy de SaleController, que es la anulación de la pantalla.
        $this->assertSame(['clase' => 'App\Http\Controllers\SaleController', 'metodo' => 'destroy', 'slug' => 'sale'], Catalogo::controller_y_metodo('sale', Catalogo::OP_BAJA));

        // Una entidad que solo se borra no ofrece campos.
        $this->assertSame([], Catalogo::declaracion('sale')['campos']);
        $this->assertSame([], Catalogo::declaracion('pending')['campos']);

        // Las cuatro principales entran con las tres operaciones.
        foreach (['provider', 'client', 'category', 'article', 'brand', 'sub_category', 'address', 'seller', 'expense_concept', 'expense_category', 'price_type'] as $entidad) {
            $this->assertSame(Catalogo::OPERACIONES, Catalogo::entidades()[$entidad]['operaciones'], $entidad);
        }
    }

    /**
     * @test
     */
    public function los_campos_calculados_de_article_no_se_ofrecen_y_los_de_la_ficha_si()
    {
        $campos = Catalogo::declaracion('article')['campos'];

        foreach (['final_price', 'final_price_blanco', 'stock', 'costo_real', 'slug', 'status', 'embedding', 'embedding_generated_at', 'needs_sync_with_tn', 'tiendanube_product_id', 'me_li_id', 'featured', 'user_id', 'id', 'num', 'created_at', 'deleted_at'] as $calculado) {
            $this->assertArrayNotHasKey($calculado, $campos, $calculado . ' es calculado por el sistema y no se puede tipear');
        }

        foreach (['name', 'cost', 'percentage_gain', 'price', 'stock_min', 'bar_code', 'provider_code', 'category_id', 'sub_category_id', 'brand_id', 'provider_id', 'iva_id', 'aplicar_iva', 'online', 'es_insumo'] as $de_la_ficha) {
            $this->assertArrayHasKey($de_la_ficha, $campos, $de_la_ficha);
            $this->assertSame([Catalogo::OP_ALTA, Catalogo::OP_EDICION], $campos[$de_la_ficha]['operaciones'], $de_la_ficha);
        }

        $this->assertTrue($campos['name']['obligatorio']);
        $this->assertFalse($campos['cost']['obligatorio']);
        $this->assertSame('number', $campos['cost']['tipo']);
        $this->assertSame('checkbox', $campos['aplicar_iva']['tipo']);
        $this->assertSame('checkbox', $campos['default_in_vender']['tipo'], 'Es int en la tabla pero la ficha la dibuja como tilde.');
        $this->assertSame('search', $campos['category_id']['tipo']);
        $this->assertSame(['tabla' => 'categories', 'campo' => 'name'], $campos['category_id']['relacion']);
        $this->assertSame(['tabla' => 'providers', 'campo' => 'name'], $campos['provider_id']['relacion']);
        $this->assertSame(['tabla' => 'ivas', 'campo' => 'percentage'], $campos['iva_id']['relacion'], 'La alícuota de IVA se nombra por su porcentaje: ivas no tiene name.');
        $this->assertNull($campos['cost']['relacion']);
    }

    /**
     * @test
     */
    public function las_columnas_sensibles_y_de_sistema_no_salen_de_ninguna_entidad()
    {
        foreach (array_keys(Catalogo::entidades()) as $entidad) {
            $declaracion = Catalogo::declaracion($entidad);

            foreach ($declaracion['campos'] as $columna => $campo) {
                $this->assertSame(0, preg_match(Catalogo::REGEX_SENSIBLES, $columna), $entidad . '.' . $columna . ' es sensible');
                $de_sistema_editables = isset($declaracion['de_sistema_editables']) ? $declaracion['de_sistema_editables'] : [];
                $this->assertTrue(!in_array($columna, Catalogo::COLUMNAS_DE_SISTEMA, true) || in_array($columna, $de_sistema_editables, true), $entidad . '.' . $columna . ' es de sistema');
                $this->assertNotContains($columna, $declaracion['solo_lectura'], $entidad . '.' . $columna . ' es solo lectura');
                $this->assertContains($campo['tipo'], ['text', 'textarea', 'number', 'checkbox', 'date', 'search'], $entidad . '.' . $columna);
                $this->assertNotEmpty($campo['operaciones'], $entidad . '.' . $columna . ' no la lee ninguna operación y se ofrece igual');
                $this->assertTrue(Schema::hasColumn($declaracion['tabla'], $columna), $entidad . '.' . $columna . ' no es columna de ' . $declaracion['tabla']);
            }
        }

        // Y el que sí tiene columnas sensibles se queda afuera entero.
        $this->assertArrayHasKey('payment_method', Catalogo::EXCLUIDAS);

        // La única columna de sistema que una pantalla edita: la fecha de un gasto (created_at), solo al editar.
        $this->assertSame([Catalogo::OP_EDICION], Catalogo::declaracion('expense')['campos']['created_at']['operaciones']);
        $this->assertSame('date', Catalogo::declaracion('expense')['campos']['created_at']['tipo']);
        $this->assertArrayNotHasKey('created_at', Catalogo::declaracion('provider')['campos']);
    }

    /**
     * 🔴 La guarda: el store() de cada entidad con alta lee del request los campos obligatorios que
     * el catálogo declara (name, o el que sea). Se lee el código del controller por reflexión, sin
     * comentarios.
     *
     * @test
     */
    public function el_store_de_cada_entidad_con_alta_lee_sus_obligatorios_del_request()
    {
        $con_alta = 0;

        foreach (array_keys(Catalogo::entidades()) as $entidad) {
            if (!Catalogo::permite($entidad, Catalogo::OP_ALTA)) {
                continue;
            }

            $con_alta++;

            $declaracion = Catalogo::declaracion($entidad);
            $controller = Catalogo::controller_y_metodo($entidad, Catalogo::OP_ALTA);

            $leidas = Catalogo::claves_que_lee($controller['clase'], $controller['metodo']);

            $this->assertNotEmpty($leidas, $entidad . ': su store() no lee nada del request');

            $obligatorios = [];

            foreach ($declaracion['campos'] as $columna => $campo) {
                if ($campo['obligatorio'] && in_array(Catalogo::OP_ALTA, $campo['operaciones'], true)) {
                    $obligatorios[] = $columna;
                }
            }

            $this->assertNotEmpty($obligatorios, $entidad . ': ninguna columna obligatoria para el alta');

            foreach ($obligatorios as $columna) {
                $leida = in_array($columna, $leidas, true)
                    || (isset(Catalogo::LEIDOS_POR_HELPER[$entidad][Catalogo::OP_ALTA]) && in_array($columna, Catalogo::LEIDOS_POR_HELPER[$entidad][Catalogo::OP_ALTA], true));

                $this->assertTrue($leida, $entidad . ': store() no lee ' . $columna);
            }

            // Y toda columna ofrecida para el alta la lee el store() (o el helper curado).
            foreach ($declaracion['campos'] as $columna => $campo) {
                if (in_array(Catalogo::OP_ALTA, $campo['operaciones'], true)) {
                    $curada = isset(Catalogo::LEIDOS_POR_HELPER[$entidad][Catalogo::OP_ALTA]) && in_array($columna, Catalogo::LEIDOS_POR_HELPER[$entidad][Catalogo::OP_ALTA], true);
                    $this->assertTrue(in_array($columna, $leidas, true) || $curada, $entidad . '.' . $columna . ' se ofrece para el alta y store() no la lee');
                }
            }
        }

        $this->assertGreaterThanOrEqual(30, $con_alta);

        // Las claves de pantalla que un store() itera están declaradas: sin ellas el foreach del
        // controller lanza con null.
        $this->assertSame([], Catalogo::declaracion('category')['claves_de_pantalla']['price_types']);
        $this->assertSame([], Catalogo::declaracion('article')['claves_de_pantalla']['tags']);
        $this->assertSame([], Catalogo::declaracion('article')['claves_de_pantalla']['price_types']);
        $this->assertSame([], Catalogo::declaracion('seller')['claves_de_pantalla']['categories']);
        $this->assertSame([], Catalogo::declaracion('price_type')['claves_de_pantalla']['categories']);
    }

    /**
     * @test
     */
    public function claves_que_lee_ignora_los_comentarios_del_controller()
    {
        $leidas = Catalogo::claves_que_lee(ArticleController::class, 'store');

        $this->assertContains('name', $leidas);
        $this->assertContains('bar_code', $leidas);
        $this->assertContains('tags', $leidas);
        // `// $model->stock = $request->stock;` está comentado en store(): no cuenta.
        $this->assertNotContains('stock', $leidas);
        // `cost` lo lee set_costo_desde_request(), no store(): por eso va en LEIDOS_POR_HELPER.
        $this->assertNotContains('cost', $leidas);
        $this->assertContains('cost', Catalogo::LEIDOS_POR_HELPER['article'][Catalogo::OP_ALTA]);

        // Los tres campos que la pantalla de sucursales solo carga al editar.
        $address = Catalogo::declaracion('address')['campos'];
        $this->assertSame([Catalogo::OP_EDICION], $address['phone']['operaciones']);
        $this->assertSame([Catalogo::OP_EDICION], $address['email']['operaciones']);
        $this->assertSame([Catalogo::OP_ALTA, Catalogo::OP_EDICION], $address['street']['operaciones']);
        $this->assertSame('street', Catalogo::declaracion('address')['columna_nombre']);

        // Lo que la pantalla no lee queda listado aparte, y no entre los campos (addresses.depto
        // existe en la tabla y ningún método del controller la lee).
        $this->assertArrayHasKey('depto', Catalogo::declaracion('address')['no_la_lee_la_pantalla']);
        $this->assertArrayNotHasKey('depto', $address);
        $this->assertContains('depto', Catalogo::que_puedo_cargar('address')['no_se_cargan_desde_la_pantalla']);
    }

    /**
     * @test
     */
    public function que_puedo_cargar_devuelve_la_lista_y_el_detalle_y_acepta_la_etiqueta_como_alias()
    {
        $lista = Catalogo::que_puedo_cargar();

        $this->assertArrayHasKey('entidades', $lista);
        $this->assertArrayHasKey('como_sigo', $lista);

        $por_entidad = [];

        foreach ($lista['entidades'] as $fila) {
            $por_entidad[$fila['entidad']] = $fila;
        }

        $this->assertSame(['alta', 'edicion', 'baja'], $por_entidad['provider']['operaciones']);
        $this->assertSame(['baja'], $por_entidad['sale']['operaciones']);
        $this->assertStringContainsString('Proveedores', $por_entidad['provider']['descripcion']);

        $detalle = Catalogo::que_puedo_cargar('provider');

        $this->assertSame('provider', $detalle['entidad']);
        $this->assertSame('proveedores', $detalle['etiqueta']);

        $campos = [];

        foreach ($detalle['campos'] as $campo) {
            $campos[$campo['campo']] = $campo;
        }

        $this->assertTrue($campos['name']['obligatorio']);
        $this->assertFalse($campos['phone']['obligatorio']);
        $this->assertStringContainsString('el nombre de', $campos['location_id']['se_acepta']);
        $this->assertStringContainsString('si / no', $campos['price_from_cost_mas_iva']['se_acepta']);
        $this->assertStringContainsString('nombre', $detalle['como_se_ubica_un_registro']);

        // Alias por etiqueta: "proveedores" y "proveedor" son provider.
        $this->assertSame('provider', Catalogo::normalizar_entidad('Proveedores'));
        $this->assertSame('provider', Catalogo::normalizar_entidad('proveedor'));
        $this->assertSame('category', Catalogo::normalizar_entidad('categoría'));
        $this->assertSame('category', Catalogo::normalizar_entidad('categorias'));
        $this->assertSame('article', Catalogo::normalizar_entidad('Artículos'));
        $this->assertSame('sub_category', Catalogo::normalizar_entidad('sub-category'));
        $this->assertSame('provider', Catalogo::que_puedo_cargar('proveedores')['entidad']);

        // Una venta se ubica por número.
        $this->assertStringContainsString('número', Catalogo::que_puedo_cargar('sale')['como_se_ubica_un_registro']);

        $desconocida = Catalogo::que_puedo_cargar('nave_espacial');

        $this->assertArrayHasKey('error', $desconocida);
        $this->assertContains('provider', $desconocida['entidades_disponibles']);
    }

    /**
     * @test
     */
    public function los_titulos_las_rutas_y_los_valores_legibles_tienen_la_forma_de_la_tarjeta()
    {
        $this->assertSame('Nueva categoría', Catalogo::titulo('category', Catalogo::OP_ALTA));
        $this->assertSame('Nuevo proveedor', Catalogo::titulo('provider', Catalogo::OP_ALTA));
        $this->assertSame('Editar cliente: Juan Pérez', Catalogo::titulo('client', Catalogo::OP_EDICION, 'Juan Pérez'));
        $this->assertSame('Borrar venta: Venta N° 12', Catalogo::titulo('sale', Catalogo::OP_BAJA, 'Venta N° 12'));

        // La ruta tiene la forma que lee AccionCard.vue ({name, params, texto}), o es null.
        foreach (array_keys(Catalogo::entidades()) as $entidad) {
            $ruta = Catalogo::ruta_de_pantalla($entidad);

            if (is_null($ruta)) {
                continue;
            }

            $this->assertIsString($ruta['name'], $entidad);
            $this->assertIsString($ruta['texto'], $entidad);
            $this->assertTrue(is_array($ruta['params']) || $ruta['params'] instanceof \stdClass, $entidad);
        }

        $this->assertSame('provider', Catalogo::ruta_de_pantalla('provider')['name']);
        $this->assertSame('abm', Catalogo::ruta_de_pantalla('category')['name']);
        $this->assertSame(['view' => 'articulos', 'sub_view' => 'categorias'], Catalogo::ruta_de_pantalla('category')['params']);
        $this->assertSame('expense', Catalogo::ruta_de_pantalla('expense')['name']);

        $numero = ['columna' => 'price', 'tipo' => 'number', 'etiqueta' => 'precio', 'relacion' => null];
        $porcentaje = ['columna' => 'percentage_gain', 'tipo' => 'number', 'etiqueta' => 'margen', 'relacion' => null];
        $tilde = ['columna' => 'online', 'tipo' => 'checkbox', 'etiqueta' => 'online', 'relacion' => null];
        $fecha = ['columna' => 'created_at', 'tipo' => 'date', 'etiqueta' => 'fecha', 'relacion' => null];
        $moneda = ['columna' => 'moneda_id', 'tipo' => 'search', 'etiqueta' => 'moneda', 'relacion' => ['tabla' => 'monedas', 'campo' => 'nombre']];

        $this->assertSame('$ 1.500', Catalogo::valor_legible($numero, 1500));
        $this->assertSame('$ 1.500,50', Catalogo::valor_legible($numero, '1500.5'));
        $this->assertSame('30 %', Catalogo::valor_legible($porcentaje, 30));
        $this->assertSame('Sí', Catalogo::valor_legible($tilde, 1));
        $this->assertSame('No', Catalogo::valor_legible($tilde, '0'));
        $this->assertSame('21/09/2026', Catalogo::valor_legible($fecha, '2026-09-21 10:00:00'));
        $this->assertSame('(vacío)', Catalogo::valor_legible($numero, null));
        $this->assertSame('dólares', Catalogo::valor_legible($moneda, 2));
    }
}
