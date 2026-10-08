<?php

namespace Tests\Import\RelacionesParecidas;

use App\Models\Category;
use App\Models\ImportEquivalencia;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * La importación de artículos con las decisiones del paso 3 sobre nombres parecidos (misión
 * importacion-relaciones-parecidas, 8/10/2026), por el endpoint real del modal
 * (/api/ai-excel-import/import) y con la cola en sync.
 *
 * Cubre el orden de resolución del worker (decisión → equivalencia recordada → nombre exacto →
 * clave, la más vieja → crear) en las cuatro relaciones, el saneado de las decisiones, las
 * equivalencias recordadas, y el arreglo de mayúsculas de proveedores y categorías que corre
 * aunque no venga ninguna decisión.
 *
 * Fixture: 33_relaciones_parecidas_articulos.xlsx (ver el generador). Filas que importan acá:
 *   RP-01  ACME / Del norte / Martillo / Bosh
 *   RP-02  Acme / DEL NORTE / Martillos / Bosch
 *   RP-03  ACME / CAÑERÍA
 *   RP-04  Pinturería          RP-05  Pintureria        RP-06  Pinturería
 *   RP-08  Del norte           RP-09  Pintureria
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ImportacionArticulosTest extends RelacionesParecidasTestCase
{
    /* ------------------------------------------------------------------
     * usar / crear / unir
     * ------------------------------------------------------------------ */

    public function test_usar_asigna_el_existente_en_las_cuatro_relaciones()
    {
        /* Nombres distintos a propósito: el camino normal NUNCA llegaría a estos modelos. */
        $proveedor = $this->crear_proveedor('Distribuidora Acme SA');
        $categoria = $this->crear_categoria('Norteño');
        $sub       = $this->crear_sub('Martillo grande', $categoria);
        $marca     = $this->crear_marca('Bosch Argentina');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'proveedor',     'valor' => 'ACME',      'accion' => 'usar', 'id' => $proveedor->id],
            ['tipo' => 'categoria',     'valor' => 'Del norte', 'accion' => 'usar', 'id' => $categoria->id],
            ['tipo' => 'sub_categoria', 'valor' => 'Martillo',  'padre' => 'Del norte', 'accion' => 'usar', 'id' => $sub->id],
            ['tipo' => 'marca',         'valor' => 'Bosh',      'accion' => 'usar', 'id' => $marca->id],
        ]);

        $rp01 = $this->articulo('RP-01');

        $this->assertSame((int) $proveedor->id, (int) $rp01->provider_id, 'proveedor');
        $this->assertSame((int) $categoria->id, (int) $rp01->category_id, 'categoría');
        $this->assertSame((int) $sub->id, (int) $rp01->sub_category_id, 'subcategoría');
        $this->assertSame((int) $marca->id, (int) $rp01->brand_id, 'marca');

        $this->assertSame((int) $categoria->id, (int) $this->articulo('RP-08')->category_id, 'La otra fila con "Del norte" también.');
        $this->assertSame((int) $proveedor->id, (int) $this->articulo('RP-03')->provider_id, 'La otra fila con "ACME" también.');

        $this->assertSame(0, $this->cuantos_exactos('categories', 'Del norte'));
        $this->assertSame(0, $this->cuantos_exactos('providers', 'ACME'));
        $this->assertSame(0, $this->cuantos_exactos('brands', 'Bosh'));

        /* Cada "usar" queda recordado; la sub, en el contexto de SU categoría. */
        $this->assertSame((int) $categoria->id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'categoria')->where('contexto_id', 0)->where('valor_clave', 'Del norte')->value('model_id'));
        $this->assertSame((int) $sub->id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'sub_categoria')->where('contexto_id', $categoria->id)->where('valor_clave', 'Martillo')->value('model_id'));
        $this->assertSame(4, ImportEquivalencia::where('user_id', $this->tenant->id)->count());
    }

    public function test_crear_crea_aunque_exista_otro_igual_salvo_mayusculas()
    {
        $viejo = $this->crear_categoria('DEL NORTE');
        $acme  = $this->crear_proveedor('Acme');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear'],
            ['tipo' => 'proveedor', 'valor' => 'ACME',      'accion' => 'crear'],
        ]);

        $this->assertSame(1, $this->cuantos_exactos('categories', 'Del norte'), 'Una sola "Del norte" nueva.');

        $nueva = Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Del norte'])->first();

        $this->assertNotSame((int) $viejo->id, (int) $nueva->id);
        $this->assertSame((int) $nueva->id, (int) $this->articulo('RP-01')->category_id);
        $this->assertSame((int) $nueva->id, (int) $this->articulo('RP-08')->category_id);
        $this->assertSame((int) $viejo->id, (int) $this->articulo('RP-02')->category_id, 'Las filas con "DEL NORTE" siguen yendo a la vieja.');

        $this->assertSame(1, $this->cuantos_exactos('providers', 'ACME'));
        $acme_nuevo = Provider::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['ACME'])->first();
        $this->assertSame((int) $acme_nuevo->id, (int) $this->articulo('RP-01')->provider_id);
        $this->assertSame((int) $acme_nuevo->id, (int) $this->articulo('RP-03')->provider_id);
        $this->assertSame((int) $acme->id, (int) $this->articulo('RP-02')->provider_id, 'La fila con "Acme" sigue yendo al viejo.');
    }

    /**
     * "Crear aparte" entre dos escrituras NUEVAS no puede depender del orden de las filas. En el
     * 33, "Del norte" está en las filas 2 y 9 y "DEL NORTE" en la 3: con el crear en "Del norte" la
     * fila con decisión viene primero; con el crear en "DEL NORTE", la que no tiene decisión.
     * Antes, en el primer orden "DEL NORTE" caía por clave en la "Del norte" recién creada.
     */
    public function test_crear_entre_nuevos_con_la_fila_con_decision_primero()
    {
        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear'],
        ]);

        $this->assertDosCategoriasDelNorte();
    }

    public function test_crear_entre_nuevos_con_la_fila_sin_decision_primero()
    {
        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'DEL NORTE', 'accion' => 'crear'],
        ]);

        $this->assertDosCategoriasDelNorte();
    }

    /**
     * @return void
     */
    protected function assertDosCategoriasDelNorte()
    {
        $this->assertSame(1, $this->cuantos_exactos('categories', 'Del norte'));
        $this->assertSame(1, $this->cuantos_exactos('categories', 'DEL NORTE'));

        $del_norte = (int) Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Del norte'])->value('id');
        $mayusculas = (int) Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['DEL NORTE'])->value('id');

        $this->assertSame($del_norte, (int) $this->articulo('RP-01')->category_id);
        $this->assertSame($del_norte, (int) $this->articulo('RP-08')->category_id);
        $this->assertSame($mayusculas, (int) $this->articulo('RP-02')->category_id);
    }

    /**
     * "Crear aparte" de un HIJO no depende del orden aunque el padre esté unido: «Pintureria» se une
     * a «Pinturería», "LATEX"→crear bajo «Pinturería» y "Latex" (sin decisión) bajo «Pintureria».
     * Las dos subcategorías terminan en la misma categoría, y la regla mira esa categoría (el
     * contexto resuelto), no el padre como vino escrito. Antes, con "LATEX" primero, "Latex" caía
     * por clave en "LATEX".
     */
    public function test_crear_un_hijo_con_el_padre_unido_con_la_fila_del_crear_primero()
    {
        $this->assertCrearHijoConPadreUnido(self::CREAR_HIJO_PRIMERO);
    }

    public function test_crear_un_hijo_con_el_padre_unido_con_la_fila_del_crear_despues()
    {
        $this->assertCrearHijoConPadreUnido(self::CREAR_HIJO_DESPUES);
    }

    /**
     * @param  string $fixture
     * @return void
     */
    protected function assertCrearHijoConPadreUnido($fixture)
    {
        $this->importar_articulos($fixture, [
            ['tipo' => 'categoria',     'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
            ['tipo' => 'sub_categoria', 'valor' => 'LATEX', 'padre' => 'Pinturería', 'accion' => 'crear'],
        ]);

        $this->assertSame(1, $this->cuantos_exactos('categories', 'Pinturería'));
        $this->assertSame(0, $this->cuantos_exactos('categories', 'Pintureria'));

        $categoria = Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Pinturería'])->first();

        $subs = SubCategory::where('user_id', $this->tenant->id)->where('category_id', $categoria->id)->pluck('id', 'name')->all();

        $this->assertCount(2, $subs, 'LATEX (creada aparte) y Latex: ' . json_encode(array_keys($subs)));
        $this->assertSame((int) $subs['LATEX'], (int) $this->articulo('RP-51')->sub_category_id);
        $this->assertSame((int) $subs['Latex'], (int) $this->articulo('RP-52')->sub_category_id);
    }

    public function test_unir_crea_uno_solo_y_lo_recuerda()
    {
        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
        ]);

        $this->assertSame(1, $this->cuantos_exactos('categories', 'Pinturería'));
        $this->assertSame(0, $this->cuantos_exactos('categories', 'Pintureria'));

        $id = (int) Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Pinturería'])->value('id');

        foreach (['RP-04', 'RP-05', 'RP-06', 'RP-09'] as $codigo) {
            $this->assertSame($id, (int) $this->articulo($codigo)->category_id, $codigo);
        }

        $this->assertSame($id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'categoria')->where('valor_clave', 'Pintureria')->value('model_id'), 'El unir se recuerda cuando el worker conoce el id.');
    }

    /**
     * El caso real de los errores de tipeo que vienen juntos en la misma fila: "Látex" bajo
     * "Pintureria" y "Latex" bajo "Pinturería". Con las dos decisiones que propone el paso 3 (unir
     * las categorías y unir las subcategorías) queda UNA categoría con UNA subcategoría: el `unir`
     * de la sub se resuelve dentro de la categoría que resolvió la fila.
     */
    public function test_unir_la_categoria_y_la_sub_deja_una_sola_de_cada_una()
    {
        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria',     'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
            ['tipo' => 'sub_categoria', 'valor' => 'Latex', 'padre' => 'Pinturería', 'accion' => 'unir', 'con' => 'Látex'],
        ]);

        $categoria = Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Pinturería'])->first();

        $this->assertNotNull($categoria);
        $this->assertSame(0, $this->cuantos_exactos('categories', 'Pintureria'));

        $subs = SubCategory::where('user_id', $this->tenant->id)->where('category_id', $categoria->id)->get();

        $this->assertCount(1, $subs, 'Una sola subcategoría dentro de la categoría unida.');
        $this->assertSame('Látex', $subs[0]->name);

        foreach (['RP-05', 'RP-06'] as $codigo) {
            $this->assertSame((int) $categoria->id, (int) $this->articulo($codigo)->category_id, $codigo);
            $this->assertSame((int) $subs[0]->id, (int) $this->articulo($codigo)->sub_category_id, $codigo);
        }
    }

    /* ------------------------------------------------------------------
     * Sin decisiones: el arreglo de mayúsculas
     * ------------------------------------------------------------------ */

    /**
     * 🔴 Antes de esta misión, con "Acme" cargado y "ACME" en el Excel, se creaba un proveedor
     * "ACME" duplicado (whereIn de MySQL + diff de PHP), y "CAÑERÍA" con "cañería" cargada creaba
     * otra categoría (strtolower no baja la Ñ ni las vocales con tilde).
     */
    public function test_sin_equivalencias_no_duplica_por_mayusculas()
    {
        $acme    = $this->crear_proveedor('Acme');
        $caneria = $this->crear_categoria('cañería');

        $this->importar_articulos(self::ARTICULOS);

        foreach (['RP-01', 'RP-02', 'RP-03'] as $codigo) {
            $this->assertSame((int) $acme->id, (int) $this->articulo($codigo)->provider_id, $codigo);
        }

        $this->assertSame(0, $this->cuantos_exactos('providers', 'ACME'));
        $this->assertSame((int) $caneria->id, (int) $this->articulo('RP-03')->category_id);
        $this->assertSame(0, $this->cuantos_exactos('categories', 'CAÑERÍA'));
        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->count(), 'Sin decisiones no se recuerda nada.');
    }

    /**
     * Con dos categorías de la misma clave ya cargadas, gana la más vieja (antes, la última).
     */
    public function test_con_dos_de_la_misma_clave_gana_la_mas_vieja()
    {
        $vieja = $this->crear_categoria('DEL NORTE');
        $this->crear_categoria('Del Norte');

        $this->importar_articulos(self::ARTICULOS);

        $this->assertSame((int) $vieja->id, (int) $this->articulo('RP-01')->category_id, '"Del norte" no es idéntica a ninguna: va a la más vieja.');
    }

    /* ------------------------------------------------------------------
     * Saneado
     * ------------------------------------------------------------------ */

    public function test_usar_con_un_id_de_otro_comercio_se_ignora()
    {
        $otro = User::create([
            'name'         => 'Otro comercio relaciones',
            'company_name' => 'Otro comercio relaciones',
            'email'        => 'relaciones-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $ajena = $this->crear_categoria('Norteño', $otro->id);
        $propia = $this->crear_categoria('DEL NORTE');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'usar', 'id' => $ajena->id],
            ['tipo' => 'cualquier_cosa', 'valor' => 'Del norte', 'accion' => 'usar', 'id' => $propia->id],
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pintureria'],
        ]);

        $this->assertSame((int) $propia->id, (int) $this->articulo('RP-01')->category_id, 'La fila sigue el camino normal.');
        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->count());
        $this->assertSame(0, ImportEquivalencia::where('user_id', $otro->id)->count());
    }

    /**
     * `usar` con un id BORRADO (soft delete): se ignora y la fila sigue el camino normal. Tampoco
     * se recuerda.
     */
    public function test_usar_con_un_id_borrado_se_ignora()
    {
        $borrada = $this->crear_categoria('Norteño');
        $borrada->delete();

        $propia = $this->crear_categoria('DEL NORTE');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'usar', 'id' => $borrada->id],
        ]);

        $this->assertSame((int) $propia->id, (int) $this->articulo('RP-01')->category_id, 'Camino normal: la clave lleva a "DEL NORTE".');
        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->count());
    }

    public function test_sub_usar_con_otra_categoria_resuelta_se_ignora()
    {
        $del_norte    = $this->crear_categoria('DEL NORTE');
        $herramientas = $this->crear_categoria('Herramientas');
        $this->crear_sub('Martillos', $del_norte);
        $ajena        = $this->crear_sub('Martillos', $herramientas);

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'sub_categoria', 'valor' => 'Martillo', 'padre' => 'Del norte', 'accion' => 'usar', 'id' => $ajena->id],
        ]);

        $rp01 = $this->articulo('RP-01');

        $this->assertSame((int) $del_norte->id, (int) $rp01->category_id);
        $this->assertNotSame((int) $ajena->id, (int) $rp01->sub_category_id, 'Una sub de otra categoría no se aplica.');
        $this->assertSame((int) $del_norte->id, (int) SubCategory::find($rp01->sub_category_id)->category_id, 'La sub quedó dentro de la categoría de la fila.');
    }

    /* ------------------------------------------------------------------
     * Equivalencias recordadas
     * ------------------------------------------------------------------ */

    /**
     * La segunda importación va por el import CLÁSICO (/api/article/excel/import), que no tiene
     * paso 3 y no manda decisiones: la equivalencia recordada se aplica igual, porque vive en
     * ProcessRow.
     */
    public function test_la_equivalencia_guardada_se_aplica_en_la_segunda_importacion_sin_decisiones()
    {
        $this->crear_categoria('DEL NORTE');
        $norteno = $this->crear_categoria('Norteño');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'usar', 'id' => $norteno->id],
        ]);

        $this->importar(self::REIMPORTACION, [
            'prop_proveedor'     => 9,
            'prop_categoria'     => 10,
            'prop_sub_categoria' => 11,
            'prop_marca'         => 12,
        ]);

        $this->assertSame((int) $norteno->id, (int) $this->articulo('RP-21')->category_id);
    }

    public function test_crear_borra_la_equivalencia_recordada()
    {
        $viejo   = $this->crear_categoria('DEL NORTE');
        $norteno = $this->crear_categoria('Norteño');

        ImportEquivalencia::create([
            'user_id'     => $this->tenant->id,
            'tipo'        => 'categoria',
            'contexto_id' => 0,
            'valor'       => 'Del norte',
            'valor_clave' => 'Del norte',
            'model_id'    => $norteno->id,
        ]);

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear'],
        ]);

        $this->assertSame(0, ImportEquivalencia::where('user_id', $this->tenant->id)->where('tipo', 'categoria')->count());

        $nueva = Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Del norte'])->first();

        $this->assertNotNull($nueva);
        $this->assertSame((int) $nueva->id, (int) $this->articulo('RP-01')->category_id);
        $this->assertSame((int) $viejo->id, (int) $this->articulo('RP-02')->category_id, 'Sin la equivalencia, "DEL NORTE" vuelve a la idéntica.');
    }

    /**
     * 🔴 La clave de una equivalencia respeta mayúsculas: "DEL NORTE"→usar y "Del norte"→crear del
     * mismo Excel son dos decisiones distintas. Con la clave en minúsculas caían en la misma fila y
     * el "crear" borraba lo que el "usar" acababa de recordar.
     */
    public function test_usar_y_crear_de_dos_escrituras_no_se_pisan()
    {
        $norteno = $this->crear_categoria('Norteño');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'DEL NORTE', 'accion' => 'usar', 'id' => $norteno->id],
            ['tipo' => 'categoria', 'valor' => 'Del norte', 'accion' => 'crear'],
        ]);

        $this->assertSame((int) $norteno->id, (int) ImportEquivalencia::where('user_id', $this->tenant->id)
            ->where('tipo', 'categoria')->where('valor_clave', 'DEL NORTE')->value('model_id'), 'El "usar" quedó recordado.');
        $this->assertSame(1, ImportEquivalencia::where('user_id', $this->tenant->id)->count());

        $this->assertSame((int) $norteno->id, (int) $this->articulo('RP-02')->category_id);

        $nueva = Category::where('user_id', $this->tenant->id)->whereRaw('BINARY name = ?', ['Del norte'])->first();
        $this->assertNotNull($nueva);
        $this->assertSame((int) $nueva->id, (int) $this->articulo('RP-01')->category_id);
    }

    /**
     * "Pintureria" y "Pinturería" son dos equivalencias distintas (columna utf8mb4_bin): con la
     * collation por defecto el índice único las trataba como la misma fila.
     */
    public function test_pintureria_con_y_sin_tilde_son_equivalencias_distintas()
    {
        $sin_tilde = $this->crear_categoria('Pinturas');
        $con_tilde = $this->crear_categoria('Pinturerías del Centro');

        $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'usar', 'id' => $sin_tilde->id],
            ['tipo' => 'categoria', 'valor' => 'Pinturería', 'accion' => 'usar', 'id' => $con_tilde->id],
        ]);

        $this->assertSame(2, ImportEquivalencia::where('user_id', $this->tenant->id)->where('tipo', 'categoria')->count());

        foreach (['RP-05', 'RP-09'] as $codigo) {
            $this->assertSame((int) $sin_tilde->id, (int) $this->articulo($codigo)->category_id, $codigo);
        }

        foreach (['RP-04', 'RP-06'] as $codigo) {
            $this->assertSame((int) $con_tilde->id, (int) $this->articulo($codigo)->category_id, $codigo);
        }
    }

    /* ------------------------------------------------------------------
     * Varios lotes
     * ------------------------------------------------------------------ */

    /**
     * Con lotes de 2 filas, "Del norte" cae en los lotes 1 y 4 y "Pintureria" en los 3 y 5. Cada
     * lote es un ProcessRow nuevo que recarga los índices: el "crear" del lote 4 tiene que
     * encontrar la que creó el lote 1 (por nombre exacto) y el "unir" del lote 5, la canónica.
     */
    public function test_varios_lotes_con_crear_y_unir_no_duplican()
    {
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => 2]);

        $this->crear_categoria('DEL NORTE');

        $import = $this->importar_articulos(self::ARTICULOS, [
            ['tipo' => 'categoria', 'valor' => 'Del norte',  'accion' => 'crear'],
            ['tipo' => 'categoria', 'valor' => 'Pintureria', 'accion' => 'unir', 'con' => 'Pinturería'],
        ]);

        $this->assertSame(5, (int) $import->total_chunks, 'Si no hay 5 lotes, este test no prueba nada.');

        $this->assertSame(1, $this->cuantos_exactos('categories', 'Del norte'));
        $this->assertSame(
            (int) $this->articulo('RP-01')->category_id,
            (int) $this->articulo('RP-08')->category_id
        );

        $this->assertSame(1, $this->cuantos_exactos('categories', 'Pinturería'));
        $this->assertSame(0, $this->cuantos_exactos('categories', 'Pintureria'));

        $id = (int) $this->articulo('RP-04')->category_id;

        foreach (['RP-05', 'RP-06', 'RP-09'] as $codigo) {
            $this->assertSame($id, (int) $this->articulo($codigo)->category_id, $codigo);
        }
    }
}
