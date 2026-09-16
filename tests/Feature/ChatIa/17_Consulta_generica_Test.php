<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Pending;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — bloque B3: la consulta genérica y su catálogo bajo demanda.
 *
 * Lo que este archivo protege, en orden de importancia:
 *
 * 🔴 EL SCOPE POR DUEÑO. `SearchController::search()` resuelve el dueño con `$this->userId()`, que
 * sale de la sesión — y el chat consulta desde un JOB, donde no hay sesión. `BuscadorDeDatosIa` lo
 * fija por constructor. Si eso se rompiera, la consulta contestaría con los datos del comercio que
 * diga `config('app.USER_ID')` y nada lo denunciaría: la respuesta se lee igual de bien.
 *
 * 🔴 LA WHITELIST. Que ninguna entidad fuera de la lista pase, y que todas las de la lista cumplan
 * los tres requisitos declarados (user_id, scopeWithAll, created_at) y tengan todos sus campos
 * declarados como columnas reales.
 *
 * 🔴 QUE UN FILTRO QUE NO SE ENTIENDE CORTE. Un filtro descartado en silencio devuelve la tabla
 * entera con cara de respuesta filtrada, y eso llega al comerciante como un dato de su negocio.
 */
class Consulta_generica_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio mano derecha B3',
            'email'    => 'mano-derecha-b3-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio mano derecha B3',
            'email'    => 'mano-derecha-b3-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 LA ASERCIÓN DEL ARCHIVO: sin sesión de por medio, la consulta contesta con los datos del
     * dueño que se le pasó y con ningún otro.
     *
     * @group chat-ia
     * @test
     */
    public function la_consulta_generica_scopea_por_el_dueno_que_se_le_pasa_y_no_por_la_sesion()
    {
        Article::create(['name' => 'Tornillo B3 propio', 'user_id' => $this->comercio->id]);
        Article::create(['name' => 'Tornillo B3 ajeno', 'user_id' => $this->otro_comercio->id]);

        // Nadie hizo login: es el escenario del job del chat.
        $this->assertNull(auth()->user(), 'El test tiene que correr sin sesion para valer.');

        $mio = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Tornillo B3'],
        ]);

        $this->assertEquals(1, $mio['registros_encontrados']);
        $this->assertEquals('Tornillo B3 propio', $mio['registros'][0]['name']);

        $del_otro = CatalogoDeDatosIaHelper::consultar_datos($this->otro_comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Tornillo B3'],
        ]);

        $this->assertEquals(1, $del_otro['registros_encontrados']);
        $this->assertEquals('Tornillo B3 ajeno', $del_otro['registros'][0]['name']);
    }

    /**
     * Una entidad fuera de la whitelist no se consulta, y la respuesta dice cuáles sí.
     *
     * @group chat-ia
     * @test
     */
    public function una_entidad_fuera_de_la_whitelist_corta_con_el_motivo()
    {
        $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'user');

        $this->assertArrayHasKey('error', $resultado);
        $this->assertArrayHasKey('entidades_validas', $resultado);
        $this->assertArrayNotHasKey('registros', $resultado);

        // Y las que el criterio dejó afuera a propósito siguen afuera.
        $this->assertFalse(CatalogoDeDatosIaHelper::acepta('movimiento_caja'), 'movimiento_cajas no tiene user_id.');
        $this->assertFalse(CatalogoDeDatosIaHelper::acepta('current_acount'), 'CurrentAcount no tiene scopeWithAll().');
        $this->assertFalse(CatalogoDeDatosIaHelper::acepta('user'));
        $this->assertFalse(CatalogoDeDatosIaHelper::acepta('article_purchase'));
    }

    /**
     * 🔴 EL CONTRATO DE LA WHITELIST, VERIFICADO CONTRA EL ESQUEMA. Los tres requisitos y las
     * columnas declaradas. Sin esto, sumar una entidad nueva mal declarada explota recién cuando el
     * comerciante la pregunta.
     *
     * @group chat-ia
     * @test
     */
    public function todas_las_entidades_de_la_whitelist_cumplen_los_tres_requisitos()
    {
        foreach (CatalogoDeDatosIaHelper::ENTIDADES as $entidad => $declaracion) {
            $clase = GeneralHelper::getModelName($entidad);

            $this->assertTrue(class_exists($clase), $entidad . ': el modelo ' . $clase . ' no existe.');

            $instancia = new $clase();
            $tabla = $instancia->getTable();

            $this->assertTrue(
                Schema::hasColumn($tabla, 'user_id'),
                $entidad . ': sin user_id el buscador no scopea por dueño y la entidad no puede estar en la lista.'
            );

            $this->assertTrue(
                method_exists($instancia, 'scopeWithAll'),
                $entidad . ': SearchController::search() llama a withAll() sin preguntar; sin ese scope es una excepción.'
            );

            $this->assertTrue(
                Schema::hasColumn($tabla, 'created_at'),
                $entidad . ': el buscador ordena por created_at siempre.'
            );

            foreach ($declaracion['campos'] as $campo => $definicion) {
                $this->assertTrue(
                    Schema::hasColumn($tabla, $campo),
                    $entidad . '.' . $campo . ': el campo declarado no es una columna de ' . $tabla . '.'
                );
            }

            foreach ($declaracion['relaciones'] as $clave => $relacion) {
                $this->assertTrue(
                    Schema::hasTable($relacion['tabla']),
                    $entidad . '.' . $clave . ': la tabla ' . $relacion['tabla'] . ' no existe.'
                );

                $this->assertTrue(
                    Schema::hasColumn($relacion['tabla'], $relacion['campo']),
                    $entidad . '.' . $clave . ': ' . $relacion['tabla'] . ' no tiene la columna ' . $relacion['campo'] . '.'
                );

                $this->assertArrayHasKey(
                    $relacion['columna_id'],
                    $declaracion['campos'],
                    $entidad . '.' . $clave . ': la columna con la que se filtra la relación tiene que estar declarada como campo.'
                );
            }
        }
    }

    /**
     * Y todas se pueden consultar de verdad: una declaración que no corre es una declaración que
     * miente.
     *
     * @group chat-ia
     * @test
     */
    public function todas_las_entidades_de_la_whitelist_se_pueden_consultar_sin_reventar()
    {
        foreach (CatalogoDeDatosIaHelper::entidades() as $entidad) {
            $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, $entidad);

            $this->assertArrayNotHasKey('error', $resultado, $entidad . ': la consulta devolvió error.');
            $this->assertArrayHasKey('registros', $resultado, $entidad);
            $this->assertEquals(0, $resultado['registros_encontrados'], $entidad . ': el comercio es nuevo y no tiene nada.');
        }
    }

    /**
     * El catálogo va en dos niveles: la lista para elegir, y los campos de una sola entidad para
     * armar el filtro.
     *
     * @group chat-ia
     * @test
     */
    public function el_catalogo_da_la_lista_sin_entidad_y_los_campos_con_entidad()
    {
        $lista = CatalogoDeDatosIaHelper::que_puedo_consultar();

        $this->assertArrayHasKey('entidades', $lista);
        $this->assertCount(count(CatalogoDeDatosIaHelper::entidades()), $lista['entidades']);
        $this->assertArrayNotHasKey('campos', $lista, 'La lista corta no lleva los campos: es lo que la hace corta.');

        $nombres = array_column($lista['entidades'], 'entidad');

        $this->assertContains('article', $nombres);
        $this->assertContains('cheque', $nombres);

        $detalle = CatalogoDeDatosIaHelper::que_puedo_consultar('article');

        $this->assertEquals('article', $detalle['entidad']);
        $this->assertNotEmpty($detalle['campos']);

        $campos = array_column($detalle['campos'], 'campo');

        $this->assertContains('final_price', $campos);
        $this->assertContains('category_id', $campos);

        // Cada campo dice qué operadores acepta: es lo que el modelo necesita para no inventar uno.
        foreach ($detalle['campos'] as $campo) {
            $this->assertNotEmpty($campo['operadores'], $campo['campo']);
        }

        // Y la relación aclara que se filtra por el id, no por el nombre lindo de la respuesta.
        $relaciones = array_column($detalle['relaciones'], 'campo_en_la_respuesta');

        $this->assertContains('rubro', $relaciones);
        $this->assertEquals('category_id', $detalle['relaciones'][array_search('rubro', $relaciones, true)]['se_filtra_por']);

        // Una entidad inexistente contesta con la lista, no con un catálogo vacío.
        $mal = CatalogoDeDatosIaHelper::que_puedo_consultar('facturas');

        $this->assertArrayHasKey('error', $mal);
        $this->assertArrayHasKey('entidades_validas', $mal);
    }

    /**
     * Filtros: contiene, mayor, igual sobre una relación, y el estado de los artículos.
     *
     * @group chat-ia
     * @test
     */
    public function los_filtros_recortan_por_texto_por_numero_y_por_relacion()
    {
        $rubro = Category::create(['name' => 'Buloneria B3', 'user_id' => $this->comercio->id]);
        $otro_rubro = Category::create(['name' => 'Pinturas B3', 'user_id' => $this->comercio->id]);
        $marca = Brand::create(['name' => 'Marca B3', 'user_id' => $this->comercio->id]);

        Article::create([
            'name'        => 'Tornillo B3 caro',
            'user_id'     => $this->comercio->id,
            'final_price' => 500,
            'category_id' => $rubro->id,
            'brand_id'    => $marca->id,
        ]);
        Article::create([
            'name'        => 'Tornillo B3 barato',
            'user_id'     => $this->comercio->id,
            'final_price' => 20,
            'category_id' => $rubro->id,
        ]);
        Article::create([
            'name'        => 'Pincel B3',
            'user_id'     => $this->comercio->id,
            'final_price' => 900,
            'category_id' => $otro_rubro->id,
        ]);
        // Pausado: SearchController lo deja afuera con status = active, y el catálogo lo declara.
        Article::create([
            'name'        => 'Tornillo B3 pausado',
            'user_id'     => $this->comercio->id,
            'final_price' => 700,
            'category_id' => $rubro->id,
            'status'      => 'inactive',
        ]);

        $por_texto = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Tornillo B3'],
        ]);

        $this->assertEquals(2, $por_texto['registros_encontrados'], 'El pausado no cuenta y el pincel tampoco.');

        $caros = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'B3'],
            ['campo' => 'final_price', 'operador' => 'mayor', 'valor' => 100],
        ]);

        $this->assertEquals(2, $caros['registros_encontrados'], 'El caro de 500 y el pincel de 900.');

        $del_rubro = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'igual', 'valor' => $rubro->id],
        ]);

        $this->assertEquals(2, $del_rubro['registros_encontrados']);

        // La etiqueta de la relación viaja resuelta, y la marca vacía viaja en null (no en "").
        $nombres = array_column($del_rubro['registros'], 'name');
        $indice = array_search('Tornillo B3 caro', $nombres, true);

        $this->assertEquals('Buloneria B3', $del_rubro['registros'][$indice]['rubro']);
        $this->assertEquals('Marca B3', $del_rubro['registros'][$indice]['marca']);

        $indice_barato = array_search('Tornillo B3 barato', $nombres, true);

        $this->assertNull($del_rubro['registros'][$indice_barato]['marca'], 'Sin marca, null y no una cadena vacía.');

        // Los filtros aplicados vuelven en la respuesta: es como el modelo confirma que se entendió.
        $this->assertEquals('category_id', $del_rubro['filtros_aplicados'][0]['campo']);
        $this->assertEquals('igual', $del_rubro['filtros_aplicados'][0]['operador']);
    }

    /**
     * 🔴 Un campo o un operador que no existe CORTA. No se descarta en silencio.
     *
     * @group chat-ia
     * @test
     */
    public function un_filtro_que_no_se_entiende_corta_en_vez_de_devolver_la_tabla_entera()
    {
        for ($i = 1; $i <= 3; $i++) {
            Article::create(['name' => 'Articulo B3 ruido ' . $i, 'user_id' => $this->comercio->id]);
        }

        $campo_inexistente = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'color_de_la_caja', 'operador' => 'igual', 'valor' => 'rojo'],
        ]);

        $this->assertArrayHasKey('error', $campo_inexistente);
        $this->assertArrayHasKey('campos_validos', $campo_inexistente);
        $this->assertArrayNotHasKey('registros', $campo_inexistente, 'Con un filtro roto NO se contesta con la tabla entera.');

        $operador_invalido = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'mayor', 'valor' => 'x'],
        ]);

        $this->assertArrayHasKey('error', $operador_invalido);
        $this->assertArrayNotHasKey('registros', $operador_invalido);

        $sin_valor = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene'],
        ]);

        $this->assertArrayHasKey('error', $sin_valor);

        $orden_invalido = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [], ['campo' => 'peso_en_kilos']);

        $this->assertArrayHasKey('error', $orden_invalido);
    }

    /**
     * El orden pedido manda sobre el `created_at DESC` que pone el buscador.
     *
     * @group chat-ia
     * @test
     */
    public function el_orden_pedido_se_aplica_antes_que_el_orden_por_defecto()
    {
        Article::create(['name' => 'Orden B3 medio', 'user_id' => $this->comercio->id, 'final_price' => 50]);
        Article::create(['name' => 'Orden B3 caro', 'user_id' => $this->comercio->id, 'final_price' => 900]);
        Article::create(['name' => 'Orden B3 barato', 'user_id' => $this->comercio->id, 'final_price' => 5]);

        $caros = CatalogoDeDatosIaHelper::consultar_datos(
            $this->comercio->id,
            'article',
            [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Orden B3']],
            ['campo' => 'final_price', 'direccion' => 'DESC']
        );

        $this->assertEquals('final_price DESC', $caros['orden']);
        $this->assertEquals(['Orden B3 caro', 'Orden B3 medio', 'Orden B3 barato'], array_column($caros['registros'], 'name'));

        $baratos = CatalogoDeDatosIaHelper::consultar_datos(
            $this->comercio->id,
            'article',
            [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Orden B3']],
            ['campo' => 'final_price', 'direccion' => 'ASC']
        );

        $this->assertEquals(['Orden B3 barato', 'Orden B3 medio', 'Orden B3 caro'], array_column($baratos['registros'], 'name'));

        // Sin orden pedido, lo más nuevo primero: es lo que ya hacía el buscador.
        $default = CatalogoDeDatosIaHelper::consultar_datos(
            $this->comercio->id,
            'article',
            [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Orden B3']]
        );

        $this->assertEquals('mas_nuevos_primero', $default['orden']);
    }

    /**
     * Paginado, totales y techo duro: el número de la pantalla no puede llegar como número del
     * negocio.
     *
     * @group chat-ia
     * @test
     */
    public function el_paginado_dice_cuantos_hay_en_total_y_el_limite_tiene_techo_duro()
    {
        for ($i = 1; $i <= 25; $i++) {
            Client::create([
                'name'    => 'Cliente B3 pagina ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'user_id' => $this->comercio->id,
            ]);
        }

        $filtro = [['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Cliente B3 pagina']];

        $primera = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'client', $filtro);

        $this->assertEquals(25, $primera['registros_encontrados'], 'El total nunca lo recorta la página.');
        $this->assertEquals(ConsultasSistemaIaHelper::MAX_RESULTS, $primera['registros_en_esta_lista']);
        $this->assertEquals(1, $primera['pagina']);
        $this->assertEquals(2, $primera['paginas']);

        $segunda = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'client', $filtro, null, 2);

        $this->assertEquals(2, $segunda['pagina']);
        $this->assertEquals(5, $segunda['registros_en_esta_lista']);

        $ids_1 = array_column($primera['registros'], 'id');
        $ids_2 = array_column($segunda['registros'], 'id');

        $this->assertEquals([], array_intersect($ids_1, $ids_2), 'Dos páginas no pueden traer la misma fila.');

        // Un límite desmedido se recorta al techo duro, no a lo que pidió el modelo.
        $de_una = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'client', $filtro, null, 1, 9999);

        $this->assertEquals(25, $de_una['registros_en_esta_lista']);
        $this->assertEquals(1, $de_una['paginas'], 'Con el techo duro de 100 los 25 entran en una página.');
    }

    /**
     * La proyección es la declarada: ni una columna de más. El `embedding` de los artículos es el
     * caso extremo — es un vector, y mandarlo al prompt es gastar el presupuesto de tiempo en algo
     * que nadie pidió.
     *
     * @group chat-ia
     * @test
     */
    public function la_respuesta_trae_solo_los_campos_declarados()
    {
        Article::create([
            'name'        => 'Articulo B3 proyeccion',
            'user_id'     => $this->comercio->id,
            'final_price' => 10,
            'embedding'   => '[0.1,0.2,0.3]',
            'descripcion' => 'una descripcion larguisima que no se declaro',
        ]);

        $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'igual', 'valor' => 'Articulo B3 proyeccion'],
        ]);

        $fila = $resultado['registros'][0];

        $esperadas = array_merge(
            ['id'],
            array_keys(CatalogoDeDatosIaHelper::ENTIDADES['article']['campos']),
            array_keys(CatalogoDeDatosIaHelper::ENTIDADES['article']['relaciones'])
        );

        $this->assertEquals($esperadas, array_keys($fila));
        $this->assertArrayNotHasKey('embedding', $fila);
        $this->assertArrayNotHasKey('descripcion', $fila);

        // Y los tipos salen casteados, no como cadenas de MySQL.
        $this->assertIsInt($fila['id']);
        $this->assertIsFloat($fila['final_price']);
        $this->assertIsBool($fila['online']);
    }

    /**
     * Los operadores `vacio` / `no_vacio` y el checkbox, que son los dos que no llevan valor de la
     * forma habitual.
     *
     * @group chat-ia
     * @test
     */
    public function vacio_no_vacio_y_checkbox_se_traducen_bien()
    {
        Provider::create(['name' => 'Proveedor B3 con mail', 'user_id' => $this->comercio->id, 'email' => 'b3@test.local']);
        Provider::create(['name' => 'Proveedor B3 sin mail', 'user_id' => $this->comercio->id]);

        $con = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'provider', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Proveedor B3'],
            ['campo' => 'email', 'operador' => 'no_vacio'],
        ]);

        $this->assertEquals(1, $con['registros_encontrados']);
        $this->assertEquals('Proveedor B3 con mail', $con['registros'][0]['name']);

        $sin = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'provider', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Proveedor B3'],
            ['campo' => 'email', 'operador' => 'vacio'],
        ]);

        $this->assertEquals(1, $sin['registros_encontrados']);
        $this->assertEquals('Proveedor B3 sin mail', $sin['registros'][0]['name']);

        // ⚠️ `articles.online` es NOT NULL con default 1: el caso "sin dato" no existe ahí. El
        // checkbox con NULL se prueba sobre `pendings.completado`, que sí admite null — y es
        // justamente el que el comerciante pregunta ("qué tareas me quedan sin hacer").
        Article::create(['name' => 'Articulo B3 online', 'user_id' => $this->comercio->id, 'online' => 1]);
        Article::create(['name' => 'Articulo B3 offline', 'user_id' => $this->comercio->id, 'online' => 0]);

        $online = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'Articulo B3'],
            ['campo' => 'online', 'operador' => 'igual', 'valor' => 1],
        ]);

        $this->assertEquals(1, $online['registros_encontrados']);
        $this->assertEquals('Articulo B3 online', $online['registros'][0]['name']);
        $this->assertTrue($online['registros'][0]['online']);

        Pending::create(['detalle' => 'Tarea B3 hecha', 'user_id' => $this->comercio->id, 'completado' => 1]);
        Pending::create(['detalle' => 'Tarea B3 pendiente', 'user_id' => $this->comercio->id, 'completado' => 0]);
        Pending::create(['detalle' => 'Tarea B3 sin dato', 'user_id' => $this->comercio->id]);

        $hechas = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'pending', [
            ['campo' => 'detalle', 'operador' => 'contiene', 'valor' => 'Tarea B3'],
            ['campo' => 'completado', 'operador' => 'igual', 'valor' => 1],
        ]);

        $this->assertEquals(1, $hechas['registros_encontrados']);
        $this->assertEquals('Tarea B3 hecha', $hechas['registros'][0]['detalle']);

        $sin_hacer = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'pending', [
            ['campo' => 'detalle', 'operador' => 'contiene', 'valor' => 'Tarea B3'],
            ['campo' => 'completado', 'operador' => 'igual', 'valor' => 0],
        ]);

        $this->assertEquals(2, $sin_hacer['registros_encontrados'], 'El que tiene NULL cuenta como desactivado.');
    }
}
