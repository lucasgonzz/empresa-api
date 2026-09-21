<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeDatosIaHelper;
use App\Models\Article;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloque A2: el motor nuevo de consultar_datos.
 *
 * Las doce pruebas de 17_Consulta_generica_Test siguen valiendo (y verdes) para lo que ya existía.
 * Acá va lo que el motor nuevo agrega, y cada caso es uno que antes no se podía contestar:
 *
 *  - filtrar una relación POR NOMBRE ("los artículos del proveedor Rosario") sin una vuelta previa
 *    para conseguir el id, y sin matchear un proveedor de OTRO comercio que se llame igual;
 *  - `desde` / `hasta` inclusivos por día, que es como la persona dice "del 1 al 15";
 *  - elegir qué columnas viajan (`campos`);
 *  - una entidad hija (renglón de venta) que solo ve los renglones de las ventas del dueño;
 *  - `sale` sin las consolidaciones AFIP;
 *  - la moneda por etiqueta, con el NULL contado como pesos.
 */
class Consulta_generica_omnisciente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        EsquemaDeDatosIaHelper::olvidar();

        $this->comercio = User::create([
            'name'     => 'Comercio omnisciente A2',
            'email'    => 'omnisciente-a2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio omnisciente A2',
            'email'    => 'omnisciente-a2-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 EL FILTRO POR NOMBRE DE UNA RELACIÓN, scopeado por dueño.
     *
     * @group chat-ia
     * @test
     */
    public function una_relacion_se_filtra_por_nombre_y_solo_entre_las_del_dueno()
    {
        $buloneria = Category::create(['name' => 'Buloneria A2', 'user_id' => $this->comercio->id]);
        $pinturas = Category::create(['name' => 'Pinturas A2', 'user_id' => $this->comercio->id]);

        // Un rubro de OTRO comercio con el mismo nombre: el subquery por nombre no lo puede tomar.
        $ajeno = Category::create(['name' => 'Buloneria A2', 'user_id' => $this->otro_comercio->id]);

        Article::create(['name' => 'Tornillo A2', 'user_id' => $this->comercio->id, 'category_id' => $buloneria->id]);
        Article::create(['name' => 'Tuerca A2', 'user_id' => $this->comercio->id, 'category_id' => $buloneria->id]);
        Article::create(['name' => 'Pincel A2', 'user_id' => $this->comercio->id, 'category_id' => $pinturas->id]);
        Article::create(['name' => 'Tornillo ajeno A2', 'user_id' => $this->otro_comercio->id, 'category_id' => $ajeno->id]);

        $contiene = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'contiene', 'valor' => 'bulon'],
        ]);

        $this->assertEquals(2, $contiene['registros_encontrados']);
        $this->assertEquals(['Tuerca A2', 'Tornillo A2'], array_column($contiene['registros'], 'name'));
        $this->assertEquals('Buloneria A2', $contiene['registros'][0]['rubro']);

        $igual = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'igual', 'valor' => 'Pinturas A2'],
        ]);

        $this->assertEquals(1, $igual['registros_encontrados']);
        $this->assertEquals('Pincel A2', $igual['registros'][0]['name']);

        // Un nombre que solo existe en el otro comercio no trae nada acá.
        $solo_ajeno = Category::create(['name' => 'Ferreteria ajena A2', 'user_id' => $this->otro_comercio->id]);
        Article::create(['name' => 'Cosa ajena A2', 'user_id' => $this->otro_comercio->id, 'category_id' => $solo_ajeno->id]);

        $nada = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'contiene', 'valor' => 'ajena'],
        ]);

        $this->assertEquals(0, $nada['registros_encontrados']);

        // Con un número sigue siendo por id, como siempre.
        $por_id = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'igual', 'valor' => (string) $pinturas->id],
        ]);

        $this->assertEquals(1, $por_id['registros_encontrados']);

        // `en` sobre una relación acepta solo ids: con un nombre corta y dice qué usar.
        $en_con_nombre = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', [
            ['campo' => 'category_id', 'operador' => 'en', 'valor' => 'Pinturas A2'],
        ]);

        $this->assertArrayHasKey('error', $en_con_nombre);
        $this->assertStringContainsString('contiene', $en_con_nombre['error']);

        // Y el catálogo dice que la columna _id acepta el nombre.
        $detalle = CatalogoDeDatosIaHelper::que_puedo_consultar('article');
        $campos = [];

        foreach ($detalle['campos'] as $campo) {
            $campos[$campo['campo']] = $campo;
        }

        $this->assertContains('contiene', $campos['category_id']['operadores']);
        $this->assertContains('en', $campos['category_id']['operadores']);
    }

    /**
     * Las fechas se comparan por día: `desde` y `hasta` inclusivos, `igual` el día entero,
     * `mayor` y `menor` estrictos.
     *
     * @group chat-ia
     * @test
     */
    public function desde_y_hasta_son_inclusivos_por_dia()
    {
        $dia_1 = now()->subDays(10)->format('Y-m-d');
        $dia_2 = now()->subDays(5)->format('Y-m-d');
        $dia_3 = now()->subDays(2)->format('Y-m-d');

        Provider::create(['name' => 'Proveedor A2 dia 1', 'user_id' => $this->comercio->id, 'created_at' => $dia_1 . ' 09:00:00']);
        Provider::create(['name' => 'Proveedor A2 dia 2 temprano', 'user_id' => $this->comercio->id, 'created_at' => $dia_2 . ' 00:00:01']);
        Provider::create(['name' => 'Proveedor A2 dia 2 tarde', 'user_id' => $this->comercio->id, 'created_at' => $dia_2 . ' 23:59:59']);
        Provider::create(['name' => 'Proveedor A2 dia 3', 'user_id' => $this->comercio->id, 'created_at' => $dia_3 . ' 12:00:00']);

        $casos = [
            'desde'  => 3,   // día 2 (los dos) y día 3
            'hasta'  => 3,   // día 1 y día 2 (los dos)
            'igual'  => 2,   // el día 2 entero
            'mayor'  => 1,   // solo el día 3
            'menor'  => 1,   // solo el día 1
        ];

        foreach ($casos as $operador => $esperado) {
            $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'provider', [
                ['campo' => 'created_at', 'operador' => $operador, 'valor' => $dia_2],
            ]);

            $this->assertEquals($esperado, $resultado['registros_encontrados'], 'created_at ' . $operador . ' ' . $dia_2);
        }

        // dd/mm/aaaa también se entiende; una fecha que no se entiende corta.
        $con_barras = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'provider', [
            ['campo' => 'created_at', 'operador' => 'igual', 'valor' => date('d/m/Y', strtotime($dia_2))],
        ]);

        $this->assertEquals(2, $con_barras['registros_encontrados']);

        $rota = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'provider', [
            ['campo' => 'created_at', 'operador' => 'desde', 'valor' => 'la semana pasada'],
        ]);

        $this->assertArrayHasKey('error', $rota);
        $this->assertArrayNotHasKey('registros', $rota);
    }

    /**
     * `campos` elige qué columnas viajan; un campo desconocido corta con la lista; una columna de
     * relación pedida trae también su etiqueta.
     *
     * @group chat-ia
     * @test
     */
    public function campos_elige_que_columnas_viajan()
    {
        $rubro = Category::create(['name' => 'Rubro A2 campos', 'user_id' => $this->comercio->id]);

        Article::create([
            'name'        => 'Articulo A2 campos',
            'user_id'     => $this->comercio->id,
            'cost'        => 120,
            'final_price' => 300,
            'descripcion' => 'una descripcion que no viaja por defecto',
            'category_id' => $rubro->id,
        ]);

        $filtro = [['campo' => 'name', 'operador' => 'igual', 'valor' => 'Articulo A2 campos']];

        $elegidos = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', $filtro, null, 1, 0, ['name', 'cost', 'descripcion']);

        $this->assertEquals(['id', 'name', 'cost', 'descripcion'], array_keys($elegidos['registros'][0]));
        $this->assertEquals(120.0, $elegidos['registros'][0]['cost']);
        $this->assertEquals('una descripcion que no viaja por defecto', $elegidos['registros'][0]['descripcion']);
        $this->assertArrayNotHasKey('campos_omitidos', $elegidos, 'Con campos explícitos no hay omitidos que avisar.');

        // Una relación pedida por su columna trae la etiqueta al lado.
        $con_relacion = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', $filtro, null, 1, 0, ['category_id']);

        $this->assertEquals(['id', 'category_id', 'rubro'], array_keys($con_relacion['registros'][0]));
        $this->assertEquals('Rubro A2 campos', $con_relacion['registros'][0]['rubro']);

        $desconocido = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', $filtro, null, 1, 0, ['name', 'color_de_la_caja']);

        $this->assertArrayHasKey('error', $desconocido);
        $this->assertArrayHasKey('campos_validos', $desconocido);
        $this->assertArrayNotHasKey('registros', $desconocido);

        // Sin campos, la proyección curada de siempre (lo fija 17_): ni descripcion ni cost derivado.
        $por_defecto = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'article', $filtro);

        $this->assertArrayNotHasKey('descripcion', $por_defecto['registros'][0]);
        $this->assertArrayHasKey('cost', $por_defecto['registros'][0], 'cost es curado y viaja.');
    }

    /**
     * 🔴 Una entidad hija ve solo los renglones de las ventas DEL DUEÑO: el renglón de una venta de
     * otro dueño no aparece aunque la tabla del renglón no tenga user_id.
     *
     * @group chat-ia
     * @test
     */
    public function una_entidad_hija_queda_scopeada_por_su_padre()
    {
        $articulo = Article::create(['name' => 'Articulo A2 renglon', 'user_id' => $this->comercio->id]);
        $articulo_ajeno = Article::create(['name' => 'Articulo ajeno A2 renglon', 'user_id' => $this->otro_comercio->id]);

        $venta = Sale::create(['user_id' => $this->comercio->id, 'num' => 77, 'moneda_id' => 1, 'total' => 500, 'terminada' => 1]);
        $venta->articles()->attach($articulo->id, ['amount' => 2, 'price' => 250, 'cost' => 100]);

        $venta_ajena = Sale::create(['user_id' => $this->otro_comercio->id, 'num' => 78, 'moneda_id' => 1, 'total' => 900]);
        $venta_ajena->articles()->attach($articulo_ajeno->id, ['amount' => 3, 'price' => 300, 'cost' => 100]);

        $mios = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'renglon_de_venta');

        $this->assertEquals(1, $mios['registros_encontrados']);

        $renglon = $mios['registros'][0];

        $this->assertEquals(77.0, $renglon['numero_venta']);
        $this->assertEquals(2.0, $renglon['amount']);
        $this->assertEquals(250.0, $renglon['price']);
        $this->assertEquals('Articulo A2 renglon', $renglon['article'], 'article_id resuelve a articles.name por convención.');
        $this->assertEquals('pesos', $renglon['moneda']);
        $this->assertTrue($renglon['terminada']);
        $this->assertEquals(date('d/m/Y'), $renglon['fecha_venta']);

        $del_otro = CatalogoDeDatosIaHelper::consultar_datos($this->otro_comercio->id, 'renglon_de_venta');

        $this->assertEquals(1, $del_otro['registros_encontrados']);
        $this->assertEquals('Articulo ajeno A2 renglon', $del_otro['registros'][0]['article']);

        // Filtrar por un campo del padre (el número de venta) también anda.
        $por_numero = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'renglon_de_venta', [
            ['campo' => 'numero_venta', 'operador' => 'igual', 'valor' => 78],
        ]);

        $this->assertEquals(0, $por_numero['registros_encontrados'], 'La venta 78 es del otro dueño.');

        // Y el empleado, la otra hija sin padre: scopeada por owner_id.
        User::create(['name' => 'Empleado A2', 'email' => 'empleado-a2-' . uniqid() . '@test.local', 'password' => Hash::make('x'), 'owner_id' => $this->comercio->id]);

        $empleados = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'empleado');

        $this->assertEquals(1, $empleados['registros_encontrados']);
        $this->assertEquals(['id', 'name', 'email', 'phone', 'admin_access', 'created_at'], array_keys($empleados['registros'][0]));
        $this->assertEquals(0, CatalogoDeDatosIaHelper::consultar_datos($this->otro_comercio->id, 'empleado')['registros_encontrados']);
    }

    /**
     * LAS DOS HIJAS QUE SE SUMARON EL 21/9/2026: los arqueos de caja y las facturas emitidas.
     *
     * Ninguna de las dos tablas tiene `user_id`, así que las dos se scopean por su padre:
     * `apertura_cajas` por `cajas.user_id` y `afip_tickets` por `sales.user_id`. Se verifica con
     * datos sembrados porque ninguna base de testing tiene comprobantes de ARCA: el join no se
     * podía medir contra datos reales.
     *
     * @group chat-ia
     * @test
     */
    public function los_arqueos_y_las_facturas_se_leen_y_quedan_scopeados_por_su_padre()
    {
        // ---- Arqueos: dos cajas de dueños distintos, un arqueo cada una.
        $mi_caja = DB::table('cajas')->insertGetId([
            'name' => 'Caja principal A2', 'num' => 1, 'user_id' => $this->comercio->id, 'moneda_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $caja_ajena = DB::table('cajas')->insertGetId([
            'name' => 'Caja ajena A2', 'num' => 1, 'user_id' => $this->otro_comercio->id, 'moneda_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('apertura_cajas')->insert([
            'caja_id' => $mi_caja, 'saldo_apertura' => 1000, 'saldo_cierre' => 4500,
            'total_ingresos' => 4000, 'total_egresos' => 500, 'cerrada_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('apertura_cajas')->insert([
            'caja_id' => $caja_ajena, 'saldo_apertura' => 9999, 'total_ingresos' => 9999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $arqueos = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'apertura_caja');

        $this->assertArrayNotHasKey('error', $arqueos, json_encode($arqueos));
        $this->assertEquals(1, $arqueos['registros_encontrados'], 'El arqueo del otro dueño no se ve.');

        $arqueo = $arqueos['registros'][0];

        $this->assertEquals('Caja principal A2', $arqueo['nombre_caja'], 'El nombre sale de la caja padre.');
        $this->assertEquals(1000.0, $arqueo['saldo_apertura']);
        $this->assertEquals(4500.0, $arqueo['saldo_cierre']);
        $this->assertEquals(4000.0, $arqueo['total_ingresos']);
        $this->assertEquals('pesos', $arqueo['moneda'], 'La moneda sale de la caja.');

        // ---- Facturas: una por venta propia, una por venta ajena, y una anulada.
        $mi_venta = Sale::create(['user_id' => $this->comercio->id, 'num' => 910, 'moneda_id' => 1, 'total' => 1210]);
        $venta_ajena = Sale::create(['user_id' => $this->otro_comercio->id, 'num' => 911, 'moneda_id' => 1, 'total' => 500]);

        DB::table('afip_tickets')->insert([
            'sale_id' => $mi_venta->id, 'punto_venta' => '0003', 'cbte_numero' => '00001234',
            'cbte_letra' => 'B', 'cbte_tipo' => '6', 'importe_total' => '1210', 'importe_iva' => 210,
            'cae' => '75123456789012', 'cae_expired_at' => now()->addDays(10), 'resultado' => 'A',
            'moneda' => 'PES', 'afip_fecha_emision' => now()->toDateString(),
            // 🔴 El XML crudo va en la fila: lo que se verifica es que NO salga.
            'request' => '<?xml version="1.0"?><soap:Envelope>' . str_repeat('x', 500) . '</soap:Envelope>',
            'response' => '<?xml version="1.0"?><soap:Envelope>' . str_repeat('y', 500) . '</soap:Envelope>',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('afip_tickets')->insert([
            'sale_id' => $venta_ajena->id, 'punto_venta' => '0001', 'cbte_numero' => '00009999',
            'cbte_letra' => 'A', 'importe_total' => '500', 'cae' => '99999999999999', 'resultado' => 'A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('afip_tickets')->insert([
            'sale_id' => $mi_venta->id, 'punto_venta' => '0003', 'cbte_numero' => '00001233',
            'cbte_letra' => 'B', 'importe_total' => '999', 'resultado' => 'R',
            'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $facturas = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'factura');

        $this->assertArrayNotHasKey('error', $facturas, json_encode($facturas));
        $this->assertEquals(1, $facturas['registros_encontrados'], 'La del otro dueño y la anulada quedan afuera.');

        $factura = $facturas['registros'][0];

        $this->assertEquals('B', $factura['cbte_letra']);
        $this->assertEquals('0003', $factura['punto_venta']);
        $this->assertEquals('00001234', $factura['cbte_numero']);
        $this->assertEquals('75123456789012', $factura['cae']);
        $this->assertEquals('A', $factura['resultado']);
        $this->assertEquals(910.0, $factura['numero_venta'], 'El número de venta sale del padre.');

        // 🔴 El XML crudo de ARCA no viaja, ni por defecto ni pidiéndolo.
        $this->assertArrayNotHasKey('request', $factura);
        $this->assertArrayNotHasKey('response', $factura);

        $pedido = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'factura', [], null, 1, 5, ['request']);

        $this->assertArrayHasKey('error', $pedido, 'Pedir el XML por `campos` tiene que cortar.');

        // Y se puede sumar lo facturado sin traer una sola fila.
        $total = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'factura', [], [['campo' => 'cbte_letra']], [
            ['funcion' => 'suma', 'campo' => 'importe_iva'], ['funcion' => 'conteo'],
        ]);

        $this->assertArrayNotHasKey('error', $total, json_encode($total));
        $this->assertEquals(1, $total['grupos_encontrados']);
        $this->assertEquals(210.0, $total['grupos'][0]['suma_importe_iva']);
    }

    /**
     * `sale` ya no incluye las contenedoras de consolidación AFIP, ni sus renglones.
     *
     * @group chat-ia
     * @test
     */
    public function sale_no_incluye_consolidaciones()
    {
        $articulo = Article::create(['name' => 'Articulo A2 consolidacion', 'user_id' => $this->comercio->id]);

        $real = Sale::create(['user_id' => $this->comercio->id, 'num' => 1, 'total' => 100]);
        $real->articles()->attach($articulo->id, ['amount' => 1, 'price' => 100, 'cost' => 50]);

        $contenedora = Sale::create(['user_id' => $this->comercio->id, 'num' => 2, 'total' => 100, 'is_consolidacion_facturacion' => 1]);
        $contenedora->articles()->attach($articulo->id, ['amount' => 1, 'price' => 100, 'cost' => 50]);

        $ventas = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale');

        $this->assertEquals(1, $ventas['registros_encontrados']);
        $this->assertEquals(1.0, $ventas['registros'][0]['num']);

        $renglones = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'renglon_de_venta');

        $this->assertEquals(1, $renglones['registros_encontrados']);

        $detalle = CatalogoDeDatosIaHelper::que_puedo_consultar('sale');

        $this->assertStringContainsString('consolidacion', implode(' ', $detalle['condiciones_fijas']));
    }

    /**
     * Los operadores nuevos: `en`, `distinto` (incluye NULL), `mayor_o_igual`, y la moneda por
     * etiqueta con el NULL contado como pesos.
     *
     * @group chat-ia
     * @test
     */
    public function los_operadores_nuevos_y_la_moneda_por_etiqueta()
    {
        Sale::create(['user_id' => $this->comercio->id, 'num' => 10, 'total' => 100, 'moneda_id' => null, 'observations' => null]);
        Sale::create(['user_id' => $this->comercio->id, 'num' => 11, 'total' => 200, 'moneda_id' => 1, 'observations' => 'urgente']);
        Sale::create(['user_id' => $this->comercio->id, 'num' => 12, 'total' => 300, 'moneda_id' => 2, 'observations' => 'normal']);

        $en = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'num', 'operador' => 'en', 'valor' => '10, 12']]);
        $this->assertEquals([12.0, 10.0], array_column($en['registros'], 'num'));

        $en_lista = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'num', 'operador' => 'en', 'valor' => [11]]]);
        $this->assertEquals(1, $en_lista['registros_encontrados']);

        $distinto = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'observations', 'operador' => 'distinto', 'valor' => 'urgente']]);
        $this->assertEquals(2, $distinto['registros_encontrados'], 'distinto incluye la venta sin observaciones (NULL).');

        $mayor_o_igual = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'total', 'operador' => 'mayor_o_igual', 'valor' => 200]]);
        $this->assertEquals(2, $mayor_o_igual['registros_encontrados']);

        $no_numerico = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'total', 'operador' => 'mayor', 'valor' => 'mucho']]);
        $this->assertArrayHasKey('error', $no_numerico);

        $pesos = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'moneda_id', 'operador' => 'igual', 'valor' => 'pesos']], null, 1, 0, ['num', 'moneda_id']);
        $this->assertEquals(2, $pesos['registros_encontrados'], 'NULL y 1 son pesos.');
        $this->assertEquals('pesos', $pesos['registros'][1]['moneda'], 'La venta con moneda NULL se etiqueta como pesos.');

        $dolares = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'moneda_id', 'operador' => 'contiene', 'valor' => 'dol']]);
        $this->assertEquals(1, $dolares['registros_encontrados']);
        $this->assertEquals(12.0, $dolares['registros'][0]['num']);

        $euros = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'sale', [['campo' => 'moneda_id', 'operador' => 'igual', 'valor' => 'euros']]);
        $this->assertArrayHasKey('error', $euros);
        $this->assertStringContainsString('pesos, dolares', $euros['error']);
    }

    /**
     * Una entidad derivada sin override viaja con las primeras treinta columnas y avisa cuáles
     * quedaron afuera; una hija con más de treinta pone primero las del padre.
     *
     * @group chat-ia
     * @test
     */
    public function una_derivada_sin_override_topea_las_columnas_por_defecto()
    {
        $declaracion = EsquemaDeDatosIaHelper::declaracion('envio');

        $this->assertFalse($declaracion['curada']);
        $this->assertCount(EsquemaDeDatosIaHelper::TOPE_COLUMNAS_POR_DEFECTO, $declaracion['campos_por_defecto']);
        $this->assertNotEmpty($declaracion['campos_omitidos'], 'envios tiene más de treinta columnas visibles.');

        DB::table('envios')->insert([
            'user_id'    => $this->comercio->id,
            'proveedor'  => 'zipnova',
            'status'     => 'creado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'envio');

        $this->assertEquals(1, $resultado['registros_encontrados']);
        $this->assertEquals($declaracion['campos_omitidos'], $resultado['campos_omitidos']);
        $this->assertCount(EsquemaDeDatosIaHelper::TOPE_COLUMNAS_POR_DEFECTO + 1, $resultado['registros'][0], 'Las treinta más el id.');

        // Y los omitidos se pueden pedir.
        $omitido = $declaracion['campos_omitidos'][0];
        $pedido = CatalogoDeDatosIaHelper::consultar_datos($this->comercio->id, 'envio', [], null, 1, 0, [$omitido]);

        $this->assertArrayHasKey($omitido, $pedido['registros'][0]);

        // El catálogo lo cuenta compacto: campos por defecto con detalle, adicionales como campo => tipo.
        $detalle = CatalogoDeDatosIaHelper::que_puedo_consultar('envio');

        $this->assertCount(EsquemaDeDatosIaHelper::TOPE_COLUMNAS_POR_DEFECTO, $detalle['campos']);
        $this->assertEquals(array_flip($declaracion['campos_omitidos']), array_map(function () {
            return 0;
        }, $detalle['campos_adicionales']));
        $this->assertArrayHasKey('operadores_por_tipo', $detalle);
    }

    /**
     * El catálogo: `buscar` acota la lista, el detalle de una hija dice su padre y sus condiciones,
     * y una entidad inexistente contesta con la lista de nombres.
     *
     * @group chat-ia
     * @test
     */
    public function que_puedo_consultar_acota_con_buscar_y_detalla_las_hijas()
    {
        $lista = CatalogoDeDatosIaHelper::que_puedo_consultar(null, 'cheque');

        $this->assertContains('cheque', array_column($lista['entidades'], 'entidad'));
        $this->assertNotContains('article', array_column($lista['entidades'], 'entidad'));
        $this->assertArrayHasKey('modulo', $lista['entidades'][0]);

        $nada = CatalogoDeDatosIaHelper::que_puedo_consultar(null, 'zzz-nada');

        $this->assertEquals([], $nada['entidades']);
        $this->assertStringContainsString('Nada coincide', $nada['como_sigo']);

        $hija = CatalogoDeDatosIaHelper::que_puedo_consultar('renglon_de_compra');

        $this->assertEquals('proveedores y compras', $hija['modulo']);
        $this->assertStringContainsString('provider_order', $hija['padre']);
        $this->assertArrayNotHasKey('campos_adicionales', $hija, 'Todos sus campos viajan por defecto.');

        $campos = array_column($hija['campos'], 'campo');

        $this->assertEquals('fecha_compra', $campos[0], 'Los campos del padre van primero.');
        $this->assertContains('article_id', $campos);

        $relaciones = array_column($hija['relaciones'], 'se_filtra_por');

        $this->assertContains('provider_id', $relaciones);
        $this->assertContains('article_id', $relaciones);

        $mal = CatalogoDeDatosIaHelper::que_puedo_consultar('facturas_de_marte');

        $this->assertArrayHasKey('error', $mal);
        $this->assertEquals(CatalogoDeDatosIaHelper::entidades(), $mal['entidades_validas']);
    }
}
