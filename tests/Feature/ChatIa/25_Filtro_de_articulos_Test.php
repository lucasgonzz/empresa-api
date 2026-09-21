<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\FiltroDeArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\Article;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Image;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-masivas-imagenes-y-remito — el filtro de artículos que arma el asistente
 * (FiltroDeArticulosIaHelper, plan §4.1).
 *
 * Lo que protege: que cada campo y operador de la IA se traduzca al filter_form EXACTO que consume
 * ColumnFiltersHelper (una clave distinta no explota: llega vacía y el filtro "funciona" sin
 * filtrar); que `desde`/`hasta` corran el día para cada lado y el whereDate del helper filtre lo
 * que tiene que filtrar; que las relaciones se resuelvan por nombre (única, ambigua con opciones,
 * inexistente); que `imagen` se aplique aparte; que "los primeros N" sean los N más viejos por fecha
 * de alta; y que un campo u operador desconocido vuelva como error con la lista, sin adivinar.
 *
 * Sin red: no se llama a ninguna API. El comercio es propio del archivo.
 */
class Filtro_de_articulos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio filtro P25',
            'company_name' => 'Ferreteria P25',
            'email'        => 'filtro-p25-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * @param  array  $atributos
     * @return Article
     */
    protected function articulo(array $atributos = [])
    {
        return Article::create(array_merge([
            'name'    => 'zz-filtro-' . uniqid(),
            'user_id' => $this->comercio->id,
            'status'  => 'active',
        ], $atributos));
    }

    /**
     * @param  string  $nombre
     * @return Provider
     */
    protected function proveedor($nombre)
    {
        return Provider::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /**
     * @param  array  $filtros
     * @return array
     */
    protected function traducir(array $filtros)
    {
        return FiltroDeArticulosIaHelper::traducir($filtros, $this->comercio->id);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function cada_campo_y_operador_se_traduce_al_filter_form_exacto_que_consume_el_helper_de_columnas()
    {
        $bulonera = $this->proveedor('Bulonera P25');

        $traducido = $this->traducir([
            ['campo' => 'proveedor',            'operador' => 'igual',        'valor' => 'Bulonera P25'],
            ['campo' => 'categoria',            'operador' => 'en_blanco'],
            ['campo' => 'marca',                'operador' => 'no_en_blanco'],
            ['campo' => 'nombre',               'operador' => 'contiene',     'valor' => 'tornillo'],
            ['campo' => 'codigo_de_barras',     'operador' => 'igual',        'valor' => '7790001'],
            ['campo' => 'codigo_de_proveedor',  'operador' => 'en_blanco'],
            ['campo' => 'costo',                'operador' => 'mayor',        'valor' => 100],
            ['campo' => 'precio_final',         'operador' => 'menor',        'valor' => '50.5'],
            ['campo' => 'stock',                'operador' => 'igual',        'valor' => 0],
            ['campo' => 'margen_de_ganancia',   'operador' => 'no_en_blanco'],
            ['campo' => 'precio_actualizado',   'operador' => 'hasta',        'valor' => '2026-06-01'],
            ['campo' => 'fecha_de_alta',        'operador' => 'desde',        'valor' => '2026-01-15'],
            ['campo' => 'stock_actualizado',    'operador' => 'igual',        'valor' => '2026-03-10'],
            ['campo' => 'fecha_de_modificacion','operador' => 'mayor',        'valor' => '2026-02-01'],
            ['campo' => 'en_tienda',            'operador' => 'igual',        'valor' => 'si'],
            ['campo' => 'destacado',            'operador' => 'igual',        'valor' => 'no'],
            ['campo' => 'imagen',               'operador' => 'en_blanco'],
        ]);

        $this->assertFalse(RespuestaDeCargaIa::es_negativa($traducido), 'La traducción no tenía que fallar: ' . json_encode($traducido));

        $this->assertSame([
            ['key' => 'provider_id',            'type' => 'search',   'igual_que'    => (int) $bulonera->id],
            ['key' => 'category_id',            'type' => 'search',   'en_blanco'    => true],
            ['key' => 'brand_id',               'type' => 'search',   'no_en_blanco' => true],
            ['key' => 'name',                   'type' => 'textarea', 'que_contenga' => 'tornillo'],
            ['key' => 'bar_code',               'type' => 'text',     'igual_que'    => '7790001'],
            ['key' => 'provider_code',          'type' => 'text',     'en_blanco'    => true],
            // Los números viajan como string, como los manda la pantalla: con un 0 numérico el
            // helper lo lee como "vacío" (`0 != ''` es false en PHP 7) y saltea el filtro.
            ['key' => 'cost',                   'type' => 'number',   'mayor_que'    => '100'],
            ['key' => 'final_price',            'type' => 'number',   'menor_que'    => '50.5'],
            ['key' => 'stock',                  'type' => 'number',   'igual_que'    => '0'],
            ['key' => 'percentage_gain',        'type' => 'number',   'no_en_blanco' => true],
            // desde / hasta corren un día: el helper compara por whereDate con < y >, sin ≤ / ≥.
            ['key' => 'final_price_updated_at', 'type' => 'date',     'menor_que'    => '2026-06-02'],
            ['key' => 'created_at',             'type' => 'date',     'mayor_que'    => '2026-01-14'],
            ['key' => 'stock_updated_at',       'type' => 'date',     'igual_que'    => '2026-03-10'],
            ['key' => 'updated_at',             'type' => 'date',     'mayor_que'    => '2026-02-01'],
            ['key' => 'online',                 'type' => 'checkbox', 'checkbox'     => 1],
            ['key' => 'featured',               'type' => 'checkbox', 'checkbox'     => 0],
        ], $traducido['filter_form']);

        // `imagen` no es una columna: va aparte, no en el filter_form.
        $this->assertSame('en_blanco', $traducido['imagen']);

        $this->assertSame([
            'Proveedor: Bulonera P25',
            'Categoría: en blanco',
            'Marca: no en blanco',
            'Nombre: contiene "tornillo"',
            'Código de barras: 7790001',
            'Código de proveedor: en blanco',
            'Costo: mayor que 100',
            'Precio final: menor que 50.5',
            'Stock: 0',
            'Margen de ganancia: no en blanco',
            'Precio actualizado: hasta el 01/06/2026',
            'Fecha de alta: desde el 15/01/2026',
            'Stock actualizado: el 10/03/2026',
            'Fecha de modificación: después del 01/02/2026',
            'En tienda: sí',
            'Destacado: no',
            'Imagen: sin imagen',
        ], $traducido['legibles']);

        // Los renglones de la tarjeta llevan la misma información, partida en etiqueta y valor.
        $this->assertSame(['etiqueta' => 'Proveedor', 'valor' => 'Bulonera P25'], $traducido['renglones'][0]);
        $this->assertSame(['etiqueta' => 'Precio actualizado', 'valor' => 'hasta el 01/06/2026'], $traducido['renglones'][10]);

        // Sin filtros: lista vacía, sin imagen, nada que decir.
        $vacio = $this->traducir([]);
        $this->assertSame([], $vacio['filter_form']);
        $this->assertNull($vacio['imagen']);
    }

    /**
     * 🔴 `desde`/`hasta` son inclusivos, y el whereDate del helper tiene que filtrar lo que dice el
     * renglón: tres artículos con el precio actualizado el 31/5, el 1/6 y el 2/6.
     *
     * @group chat-ia
     * @test
     */
    public function desde_y_hasta_corren_el_dia_y_el_where_date_filtra_inclusivo()
    {
        $this->articulo(['name' => 'zz-p25 mayo', 'final_price_updated_at' => '2026-05-31 10:00:00']);
        $this->articulo(['name' => 'zz-p25 primero de junio', 'final_price_updated_at' => '2026-06-01 15:30:00']);
        $this->articulo(['name' => 'zz-p25 dos de junio', 'final_price_updated_at' => '2026-06-02 08:00:00']);
        $this->articulo(['name' => 'zz-p25 sin fecha']);

        $hasta = $this->traducir([['campo' => 'precio_actualizado', 'operador' => 'hasta', 'valor' => '2026-06-01']]);
        $this->assertSame(2, FiltroDeArticulosIaHelper::contar($this->comercio->id, $hasta['filter_form'], null), 'hasta el 1/6 tiene que incluir el 1/6 (a cualquier hora) y excluir el 2/6.');

        $desde = $this->traducir([['campo' => 'precio_actualizado', 'operador' => 'desde', 'valor' => '2026-06-01']]);
        $this->assertSame(2, FiltroDeArticulosIaHelper::contar($this->comercio->id, $desde['filter_form'], null), 'desde el 1/6 tiene que incluir el 1/6 y excluir el 31/5.');

        $rango = $this->traducir([
            ['campo' => 'precio_actualizado', 'operador' => 'desde', 'valor' => '2026-06-01'],
            ['campo' => 'precio_actualizado', 'operador' => 'hasta', 'valor' => '2026-06-01'],
        ]);
        $this->assertSame(['zz-p25 primero de junio'], FiltroDeArticulosIaHelper::muestra($this->comercio->id, $rango['filter_form'], null));

        $en_blanco = $this->traducir([['campo' => 'precio_actualizado', 'operador' => 'en_blanco']]);
        $this->assertSame(['zz-p25 sin fecha'], FiltroDeArticulosIaHelper::muestra($this->comercio->id, $en_blanco['filter_form'], null));

        // Un artículo de otro comercio con la misma fecha no entra: la query arranca en el dueño.
        $otro = User::create(['name' => 'Otro P25', 'email' => 'otro-p25-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        Article::create(['name' => 'zz-p25 ajeno', 'user_id' => $otro->id, 'final_price_updated_at' => '2026-06-01 12:00:00']);
        $this->assertSame(2, FiltroDeArticulosIaHelper::contar($this->comercio->id, $hasta['filter_form'], null));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function una_relacion_se_resuelve_por_nombre_unica_exacta_ambigua_o_inexistente()
    {
        $norte = $this->proveedor('Bulonera Norte P25');
        $sur = $this->proveedor('Bulonera Sur P25');
        $this->proveedor('Bulonera P25');
        $unico = $this->proveedor('Ferretería Central P25');

        // Única coincidencia: es esa.
        $this->assertSame((int) $unico->id, (int) FiltroDeArticulosIaHelper::resolver_relacion($this->comercio->id, 'proveedor', 'central p25')->id);

        // Varias coincidencias con una exacta (sin distinguir mayúsculas): la exacta.
        $exacta = FiltroDeArticulosIaHelper::resolver_relacion($this->comercio->id, 'proveedor', 'bulonera p25');
        $this->assertSame('Bulonera P25', (string) $exacta->name);

        // Varias sin exacta: faltan, con las opciones por nombre.
        $ambigua = FiltroDeArticulosIaHelper::resolver_relacion($this->comercio->id, 'proveedor', 'Bulonera');
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($ambigua));
        $this->assertCount(1, $ambigua['faltan']);
        $this->assertStringContainsString('cuál proveedor', $ambigua['faltan'][0]);
        $nombres = array_column($ambigua['opciones']['proveedores'], 'nombre');
        $this->assertContains('Bulonera Norte P25', $nombres);
        $this->assertContains('Bulonera Sur P25', $nombres);
        $this->assertContains((int) $norte->id, array_column($ambigua['opciones']['proveedores'], 'id'));
        $this->assertContains((int) $sur->id, array_column($ambigua['opciones']['proveedores'], 'id'));

        // Inexistente: error, sin adivinar.
        $inexistente = FiltroDeArticulosIaHelper::resolver_relacion($this->comercio->id, 'proveedor', 'Pinturería');
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($inexistente));
        $this->assertSame('No encontré ningún proveedor que se llame "Pinturería".', $inexistente['error']);

        // De otro comercio no se ve.
        $otro = User::create(['name' => 'Otro P25', 'email' => 'otro-p25-' . uniqid() . '@test.local', 'password' => Hash::make('secret')]);
        Provider::create(['name' => 'Ajeno P25', 'user_id' => $otro->id]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa(FiltroDeArticulosIaHelper::resolver_relacion($this->comercio->id, 'proveedor', 'Ajeno P25')));

        // Categorías y marcas, por el mismo camino, y lo que devuelve traducir() con una ambigua.
        $categoria = Category::create(['name' => 'Tornillos P25', 'user_id' => $this->comercio->id]);
        $marca = Brand::create(['name' => 'Acme P25', 'user_id' => $this->comercio->id]);

        $traducido = $this->traducir([
            ['campo' => 'categoria', 'operador' => 'igual', 'valor' => 'Tornillos P25'],
            ['campo' => 'marca',     'operador' => 'igual', 'valor' => 'acme'],
        ]);
        $this->assertSame((int) $categoria->id, $traducido['filter_form'][0]['igual_que']);
        $this->assertSame((int) $marca->id, $traducido['filter_form'][1]['igual_que']);

        $con_ambigua = $this->traducir([['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($con_ambigua));
        $this->assertArrayHasKey('proveedores', $con_ambigua['opciones']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_filtro_de_imagen_se_aplica_sobre_la_relacion_y_no_sobre_una_columna()
    {
        $bulonera = $this->proveedor('Bulonera P25');

        $con_imagen = $this->articulo(['name' => 'zz-p25 con imagen', 'provider_id' => $bulonera->id]);
        $sin_imagen = $this->articulo(['name' => 'zz-p25 sin imagen', 'provider_id' => $bulonera->id]);

        // 🔴 El morph map usa el alias 'article', no el FQCN (AppServiceProvider::enforceMorphMap).
        Image::create(['imageable_id' => $con_imagen->id, 'imageable_type' => 'article', 'hosting_url' => 'https://ejemplo.test/p25.webp']);

        $filtro = $this->traducir([['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P25']]);

        $this->assertSame(2, FiltroDeArticulosIaHelper::contar($this->comercio->id, $filtro['filter_form'], null));
        $this->assertSame([(int) $sin_imagen->id], FiltroDeArticulosIaHelper::ids($this->comercio->id, $filtro['filter_form'], 'en_blanco'));
        $this->assertSame([(int) $con_imagen->id], FiltroDeArticulosIaHelper::ids($this->comercio->id, $filtro['filter_form'], 'no_en_blanco'));

        $solo_sin = $this->traducir([['campo' => 'imagen', 'operador' => 'en_blanco']]);
        $this->assertSame([], $solo_sin['filter_form']);
        $this->assertSame(1, FiltroDeArticulosIaHelper::contar($this->comercio->id, $solo_sin['filter_form'], $solo_sin['imagen']));
    }

    /**
     * "Los primeros N" son los N más viejos por fecha de alta, con desempate por id.
     *
     * @group chat-ia
     * @test
     */
    public function orden_y_limite_devuelven_los_primeros_n_por_fecha_de_alta_ascendente()
    {
        $ids_por_alta = [];

        // Se crean en orden inverso de fecha a propósito: el id no puede ser lo que ordena.
        foreach ([1, 2, 3, 4, 5] as $dias_atras) {
            $articulo = $this->articulo([
                'name'       => 'zz-p25 alta hace ' . $dias_atras,
                'created_at' => Carbon::now()->subDays($dias_atras),
                'updated_at' => Carbon::now()->subDays($dias_atras),
            ]);
            $ids_por_alta[$dias_atras] = (int) $articulo->id;
        }

        $primeros = FiltroDeArticulosIaHelper::ids($this->comercio->id, [], null, 'primeros_creados', 3);
        $this->assertSame([$ids_por_alta[5], $ids_por_alta[4], $ids_por_alta[3]], $primeros, 'Los primeros 3 tienen que ser los más viejos por fecha de alta, no los de id más chico.');

        $ultimos = FiltroDeArticulosIaHelper::ids($this->comercio->id, [], null, 'ultimos_creados', 2);
        $this->assertSame([$ids_por_alta[1], $ids_por_alta[2]], $ultimos);

        $todos = FiltroDeArticulosIaHelper::ids($this->comercio->id, [], null, null, 0);
        $this->assertCount(5, $todos);
        $this->assertSame($ids_por_alta[5], $todos[0]);

        $this->assertSame(['zz-p25 alta hace 5', 'zz-p25 alta hace 4', 'zz-p25 alta hace 3', 'zz-p25 alta hace 2', 'zz-p25 alta hace 1'], FiltroDeArticulosIaHelper::muestra($this->comercio->id, [], null, 'primeros_creados'));

        // Y lo que ve la IA por contar_articulos_por_filtro: total, a_procesar con el límite y la muestra en orden.
        $respuesta = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, ['filtros' => [], 'orden' => 'primeros_creados', 'limite' => 2]);
        $this->assertTrue($respuesta['ok']);
        $this->assertSame(5, $respuesta['total']);
        $this->assertSame(2, $respuesta['a_procesar']);
        $this->assertSame('zz-p25 alta hace 5', $respuesta['muestra'][0]);
        $this->assertSame([], $respuesta['filtros_legibles']);

        $con_solo_sin_imagen = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, ['solo_sin_imagen' => true]);
        $this->assertSame(5, $con_solo_sin_imagen['total']);
        $this->assertSame(['Imagen: sin imagen'], $con_solo_sin_imagen['filtros_legibles']);

        $orden_invalido = FiltroDeArticulosIaHelper::contar_para_la_ia($this->comercio->id, ['orden' => 'alfabetico']);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($orden_invalido));
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_campo_o_un_operador_desconocido_vuelve_como_error_con_la_lista_valida()
    {
        $campo = $this->traducir([['campo' => 'color', 'operador' => 'igual', 'valor' => 'rojo']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($campo));
        $this->assertStringContainsString('No conozco el campo "color"', $campo['error']);
        $this->assertStringContainsString('proveedor', $campo['error']);
        $this->assertStringContainsString('precio_actualizado', $campo['error']);

        $operador = $this->traducir([['campo' => 'costo', 'operador' => 'contiene', 'valor' => '1']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($operador));
        $this->assertStringContainsString('El operador "contiene" no sirve para Costo', $operador['error']);
        $this->assertStringContainsString('mayor', $operador['error']);

        $sin_valor = $this->traducir([['campo' => 'costo', 'operador' => 'mayor']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($sin_valor));
        $this->assertStringContainsString('Falta el valor', $sin_valor['error']);

        $no_numerico = $this->traducir([['campo' => 'stock', 'operador' => 'menor', 'valor' => 'diez']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($no_numerico));

        $fecha_rota = $this->traducir([['campo' => 'fecha_de_alta', 'operador' => 'desde', 'valor' => '15/01/2026']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($fecha_rota));
        $this->assertStringContainsString('AAAA-MM-DD', $fecha_rota['error']);

        $checkbox_raro = $this->traducir([['campo' => 'en_tienda', 'operador' => 'igual', 'valor' => 'quizás']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($checkbox_raro));

        $imagen_mal = $this->traducir([['campo' => 'imagen', 'operador' => 'igual', 'valor' => 'x']]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($imagen_mal));

        // Nada de esto es una falla técnica: sin is_error, con la forma de RespuestaDeCargaIa.
        $this->assertArrayHasKey('faltan', $campo);
        $this->assertSame([], $campo['faltan']);
    }
}
