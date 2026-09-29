<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeDatosIaHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Misión catalogo-ia-tablas-sin-id (29/9/2026): lo que el motor de lectura del asistente no puede
 * leer, no se declara.
 *
 * EL CASO. La 4.3.0 (misión rag-whatsapp-memoria-acotada) creó `article_compact_embeddings`, el
 * vector compacto de cada artículo para la búsqueda semántica del agente de WhatsApp. Tiene
 * `user_id`, así que EsquemaDeDatosIaHelper la derivó como la entidad `article_compact_embedding`;
 * pero su clave primaria es `article_id` —no tiene columna `id`— y el motor de
 * CatalogoDeDatosIaHelper cuenta, proyecta, ordena y pagina por `tabla.id`. La entidad figuraba en
 * que_puedo_consultar y en los recursos MCP, y cada consultar_datos sobre ella volvía con
 * `SQLSTATE[42S22] ... Unknown column 'article_compact_embeddings.id'`. Lo destapó el test 17
 * (`todas_las_entidades_de_la_whitelist_se_pueden_consultar_sin_reventar`), que la misión RAG no
 * había corrido.
 *
 * Y un hermano latente de la misma familia: la columna `vector` es un BLOB. Con un `id` en la
 * tabla, esos bytes habrían viajado en la proyección por defecto, `json_encode` habría devuelto
 * false y el tool_result entero se habría vuelto `[]`: el modelo lee "no hay registros" y lo
 * contesta tranquilo.
 *
 * Lo que protege cada test:
 *
 *   1. Que la tabla de vectores compactos no entre por ninguna puerta, que consultarla sea un error
 *      prolijo y no una excepción de base, y que la lista negra la nombre con su motivo.
 *   2. EL INVARIANTE sobre el catálogo real, leyendo el esquema con una consulta propia (no con el
 *      lector del helper): toda entidad y toda relación apuntan a una tabla con `id`, y ningún
 *      campo es una columna binaria.
 *   3. LA DETECCIÓN: toda tabla de la base con `user_id` y sin `id` está en TABLAS_EXCLUIDAS. Si una
 *      migración nueva crea otra, este test la nombra y dice qué hacer. La guarda protege
 *      producción; este test obliga a decidir y a dejar el motivo escrito.
 *   4. LAS GUARDAS DE PUNTA A PUNTA, con derivar_de() sobre el mapa real más tablas sintéticas que
 *      ninguna lista conoce —como las que puede tener el esquema de un cliente viejo y la base de
 *      testing no—: la tabla sin `id` no entra, las columnas binarias (BLOB, VARBINARY, espacial) no
 *      son campo y la relación hacia un destino sin `id` no se arma. Y derivar_de() es el mismo
 *      código que sirve catalogo(), así que lo probado con mapas sintéticos es lo que corre.
 *   5. Los caminos que el 4 no recorre, con la misma guarda: una curada y una hija cuya tabla no
 *      tiene `id`, una relación fija (RELACIONES_POR_CONVENCION) hacia una tabla sin `id`, y una
 *      columna BLOB en la tabla de una hija.
 */
class Tablas_que_el_motor_no_puede_leer_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Los tipos de MySQL que por PDO vuelven como bytes (binarios, espaciales, VECTOR), como los
     * reporta `information_schema.DATA_TYPE`. Escritos acá y NO leídos de
     * EsquemaDeDatosIaHelper::TIPOS_BINARIOS: el invariante no puede depender de la constante que
     * está verificando.
     */
    const TIPOS_BINARIOS = [
        // Binarios.
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        // Espaciales (vuelven como WKB).
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon',
        'geomcollection', 'geometrycollection',
        // VECTOR de MySQL 9 (vuelve como float32 empaquetados).
        'vector',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // El catálogo se deriva del esquema de ESTA base, no de un caché de otra corrida.
        EsquemaDeDatosIaHelper::olvidar();
    }

    /**
     * 🔴 LA TABLA DEL 29/9 NO ENTRA, POR NINGUNA PUERTA.
     *
     * @group chat-ia
     * @test
     */
    public function la_tabla_de_vectores_compactos_no_entra_al_catalogo_por_ninguna_puerta()
    {
        // Sin la tabla en la base de testing, todo lo de abajo da verde sin probar nada.
        $this->assertTrue(
            Schema::hasTable('article_compact_embeddings'),
            'La base de testing no tiene article_compact_embeddings: migrala, o este test da verde sin probar nada.'
        );

        $dueno = User::create([
            'name'     => 'Comercio tablas sin id',
            'email'    => 'tablas-sin-id-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->assertFalse(EsquemaDeDatosIaHelper::existe('article_compact_embedding'), 'La tabla de vectores compactos no es una entidad.');

        // Por TABLA, no por nombre de entidad: que no se haya colado con otro nombre.
        $this->assertNotContains(
            'article_compact_embeddings',
            array_column(EsquemaDeDatosIaHelper::catalogo(), 'tabla'),
            'Ninguna entidad puede leer article_compact_embeddings, se llame como se llame.'
        );

        // Consultarla es el error de entidad inexistente —con la lista de las que sí hay—, no una
        // QueryException que el modelo recibe como texto crudo de la base.
        $consulta = CatalogoDeDatosIaHelper::consultar_datos($dueno->id, 'article_compact_embedding');

        $this->assertArrayHasKey('error', $consulta);
        $this->assertArrayHasKey('entidades_validas', $consulta, 'Es el error de entidad inexistente, el que le dice al modelo cuáles hay.');
        $this->assertArrayNotHasKey('registros', $consulta);

        // Agregarla, lo mismo: resumir_datos entra por la misma declaración.
        $resumen = ResumenDeDatosIaHelper::resumir($dueno->id, 'article_compact_embedding');

        $this->assertArrayHasKey('error', $resumen);
        $this->assertArrayHasKey('entidades_validas', $resumen);

        // No se ofrece en la lista que ve el modelo.
        $lista = CatalogoDeDatosIaHelper::que_puedo_consultar();

        $this->assertNotContains('article_compact_embedding', array_column($lista['entidades'], 'entidad'));

        // Y la lista negra la nombra con su motivo: la decisión queda escrita, no solo implícita en
        // la guarda.
        $this->assertArrayHasKey('article_compact_embeddings', EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS);
        $this->assertNotEmpty(trim(EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS['article_compact_embeddings']), 'Toda exclusión lleva su motivo.');
    }

    /**
     * 🔴 EL INVARIANTE: lo que el catálogo declara, el motor lo puede leer y lo puede devolver.
     *
     * El esquema se lee con una consulta de este test y no con
     * EsquemaDeDatosIaHelper::columnas_de_la_base(): si el lector del helper se rompiera, no podría
     * ser también el que confirma que todo está bien.
     *
     * @group chat-ia
     * @test
     */
    public function toda_entidad_y_toda_relacion_apuntan_a_una_tabla_con_id_y_ningun_campo_es_binario()
    {
        $esquema = $this->esquema_de_la_base();

        // Si la consulta propia no trajo nada, el invariante pasaría en falso.
        $this->assertTrue(
            isset($esquema['articles']['id']) && isset($esquema['articles']['user_id']),
            'La consulta propia a information_schema no trajo articles con id y user_id: el invariante pasaría en falso.'
        );

        $catalogo = EsquemaDeDatosIaHelper::catalogo();

        $this->assertNotEmpty($catalogo);

        foreach ($catalogo as $entidad => $declaracion) {
            $tabla = $declaracion['tabla'];

            $this->assertTrue(
                isset($esquema[$tabla]['id']),
                $entidad . ': la tabla ' . $tabla . ' no tiene columna id, y el motor cuenta, proyecta, ordena y pagina por id.'
            );

            foreach ($declaracion['relaciones'] as $clave => $relacion) {
                // Las de etiquetas fijas (moneda_id) no van contra ninguna tabla.
                if (isset($relacion['etiquetas_fijas'])) {
                    continue;
                }

                $this->assertTrue(
                    isset($esquema[$relacion['tabla']]['id']),
                    $entidad . '.' . $clave . ': la relación apunta a ' . $relacion['tabla'] . ', que no tiene columna id; el filtro por nombre y las etiquetas van por id.'
                );
            }

            foreach ($declaracion['campos'] as $campo => $definicion) {
                // Un campo calculado es una expresión, no una columna.
                if (! isset($definicion['columna'])) {
                    continue;
                }

                $partes = explode('.', $definicion['columna']);

                // Que la columna exista lo verifica el test 30; acá importa su tipo.
                $tipo = count($partes) === 2 && isset($esquema[$partes[0]][$partes[1]]) ? $esquema[$partes[0]][$partes[1]] : null;

                $this->assertNotContains(
                    $tipo,
                    self::TIPOS_BINARIOS,
                    $entidad . '.' . $campo . ': ' . $definicion['columna'] . ' es una columna binaria (' . $tipo . '); sus bytes vacían el tool_result entero.'
                );
            }
        }
    }

    /**
     * 🔴 LA DETECCIÓN HECHA TEST: toda tabla de la base con `user_id` y sin `id` está en la lista
     * negra.
     *
     * La guarda ya la deja afuera del catálogo; esto obliga a DECIDIR. Una tabla así puede ser algo
     * interno (va a TABLAS_EXCLUIDAS, con su motivo) o un dato del negocio que el dueño va a querer
     * leer (entonces necesita un `id`). Las dos son decisiones de alguien, y este test es el que las
     * pide cuando una migración nueva crea la tabla.
     *
     * @group chat-ia
     * @test
     */
    public function toda_tabla_con_user_id_y_sin_id_esta_en_la_lista_negra()
    {
        $esquema = $this->esquema_de_la_base();

        $sin_id = [];

        foreach ($esquema as $tabla => $columnas) {
            if (isset($columnas['user_id']) && ! isset($columnas['id'])) {
                $sin_id[] = $tabla;
            }
        }

        /*
         * La detección tiene que encontrar al menos el caso del 29/9: si no, la consulta está rota y
         * el loop de abajo da verde sin haber mirado nada. Si algún día esa tabla gana un `id`, esta
         * aserción se va (y su entrada en la lista negra se revisa).
         */
        $this->assertContains(
            'article_compact_embeddings',
            $sin_id,
            'La detección no encontró ni article_compact_embeddings: la consulta a information_schema está rota y este test pasaría en falso.'
        );

        foreach ($sin_id as $tabla) {
            $this->assertArrayHasKey(
                $tabla,
                EsquemaDeDatosIaHelper::TABLAS_EXCLUIDAS,
                'La tabla ' . $tabla . ' tiene user_id y no tiene id: el motor del asistente no la puede leer (cuenta, proyecta y pagina por id). Sumala a TABLAS_EXCLUIDAS con su motivo, o dale un id si es un dato del negocio.'
            );
        }
    }

    /**
     * 🔴 LAS GUARDAS DE PUNTA A PUNTA, sobre tablas que ninguna lista conoce.
     *
     * Se parte del mapa real de la base y se le suman tres tablas sintéticas con prefijo `zz_`
     * (ninguna existe, y se verifica): es el esquema de un cliente que tiene algo que la base de
     * testing no tiene. derivar_de() es pura, así que no hace falta crearlas; un CREATE TABLE haría
     * commit implícito y rompería la transacción del test.
     *
     * @group chat-ia
     * @test
     */
    public function las_guardas_frenan_en_derivar_de_lo_que_ninguna_lista_conoce()
    {
        $columnas = EsquemaDeDatosIaHelper::columnas_de_la_base();

        // El destino "bueno" de la relación de control: articles tiene id y name.
        $this->assertArrayHasKey('id', $columnas['articles']);
        $this->assertArrayHasKey('name', $columnas['articles']);

        foreach (['zz_sin_ids', 'zz_legibles', 'zz_destinos'] as $tabla) {
            $this->assertArrayNotHasKey($tabla, $columnas, $tabla . ' existe en la base: el prefijo zz_ estaba para que no.');
        }

        // Si alguna de las columnas que vuelven como bytes cayera en la lista negra por NOMBRE, este
        // test no probaría la guarda por TIPO.
        foreach (['vector', 'ubicacion', 'firma'] as $nombre) {
            $this->assertSame(
                0,
                preg_match(EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES, $nombre),
                $nombre . ' cae en COLUMNAS_SENSIBLES: saldría por nombre y no probaría la guarda de tipo.'
            );
        }

        // 1. Con user_id y name, SIN id: la forma de article_compact_embeddings, en ninguna lista.
        $columnas['zz_sin_ids'] = [
            'user_id' => $this->columna_sintetica('bigint', 'bigint unsigned', 1, false),
            'name'    => $this->columna_sintetica('varchar', 'varchar(191)', 2, true),
        ];

        // 2. Legible, con tres columnas que vuelven como bytes (BLOB, espacial y VARBINARY), una
        //    relación que se arma y otra hacia un destino sin id.
        $columnas['zz_legibles'] = [
            'id'            => $this->columna_sintetica('bigint', 'bigint unsigned', 1, false),
            'user_id'       => $this->columna_sintetica('bigint', 'bigint unsigned', 2, false),
            'name'          => $this->columna_sintetica('varchar', 'varchar(191)', 3, true),
            'vector'        => $this->columna_sintetica('blob', 'blob', 4, false),
            'article_id'    => $this->columna_sintetica('bigint', 'bigint unsigned', 5, true),
            'zz_destino_id' => $this->columna_sintetica('bigint', 'bigint unsigned', 6, true),
            'ubicacion'     => $this->columna_sintetica('geometry', 'geometry', 7, true),
            'firma'         => $this->columna_sintetica('varbinary', 'varbinary(64)', 8, true),
        ];

        // 3. El destino de zz_destino_id: tiene name (la convención lo tomaría) y NO tiene id.
        $columnas['zz_destinos'] = [
            'name' => $this->columna_sintetica('varchar', 'varchar(191)', 1, true),
        ];

        $catalogo = EsquemaDeDatosIaHelper::derivar_de($columnas);

        // Se busca por TABLA: una tabla que se cuela, se cuela con cualquier nombre de entidad.
        $this->assertNull(
            $this->declaracion_de_la_tabla($catalogo, 'zz_sin_ids'),
            'Una tabla con user_id y sin id no puede entrar al catálogo, esté o no en la lista negra.'
        );

        $legible = $this->declaracion_de_la_tabla($catalogo, 'zz_legibles');

        $this->assertNotNull($legible, 'Una tabla con id y user_id entra: la guarda no puede frenar de más.');
        $this->assertArrayHasKey('name', $legible['campos']);
        $this->assertArrayNotHasKey('vector', $legible['campos'], 'Una columna BLOB no es un campo: sus bytes vacían el tool_result entero.');
        $this->assertArrayNotHasKey('ubicacion', $legible['campos'], 'Una columna espacial no es un campo: vuelve como WKB, bytes que vacían el tool_result entero.');
        $this->assertArrayNotHasKey('firma', $legible['campos'], 'Una columna VARBINARY no es un campo: sus bytes vacían el tool_result entero.');

        $relaciones = $this->relaciones_por_columna($legible);

        // Control: hacia un destino con id y name la convención sigue armando la relación.
        $this->assertEquals('search', $legible['campos']['article_id']['tipo']);
        $this->assertArrayHasKey('article_id', $relaciones);
        $this->assertEquals('articles', $relaciones['article_id']['tabla']);

        // Y hacia un destino sin id no la arma.
        $this->assertEquals('number', $legible['campos']['zz_destino_id']['tipo'], 'Hacia una tabla sin id no hay relación: el campo queda number.');
        $this->assertArrayNotHasKey('zz_destino_id', $relaciones, 'El filtro por nombre haría SELECT id FROM zz_destinos y reventaría.');

        /*
         * Y catalogo() y derivar_de() son el mismo código: derivar_de() sobre el mapa real da
         * exactamente el catálogo que se sirve, así que lo probado arriba con mapas sintéticos es lo
         * que corre en producción. Esto NO compara contra la derivación de develop —acá las dos
         * puntas son la versión nueva—: esa comparación se midió aparte (informe de la misión).
         */
        $this->assertSame(
            EsquemaDeDatosIaHelper::catalogo(),
            EsquemaDeDatosIaHelper::derivar_de(EsquemaDeDatosIaHelper::columnas_de_la_base())
        );
    }

    /**
     * Los caminos que el test anterior no recorre, con la misma guarda: una CURADA y una HIJA cuya
     * tabla no tiene `id` no se declaran; una relación FIJA (RELACIONES_POR_CONVENCION) hacia una
     * tabla sin `id` no se arma; y una columna BLOB en la tabla de una hija no es campo.
     *
     * Las curadas, las hijas y las relaciones fijas están escritas a mano: justamente por eso nadie
     * iría a revisar si su tabla, en el esquema de algún cliente, no tiene `id`.
     *
     * @group chat-ia
     * @test
     */
    public function la_guarda_vale_tambien_para_curadas_hijas_y_relaciones_fijas()
    {
        $columnas = EsquemaDeDatosIaHelper::columnas_de_la_base();

        foreach (['pendings', 'movimiento_cajas', 'ivas'] as $tabla) {
            $this->assertArrayHasKey('id', $columnas[$tabla], $tabla . ' no tiene id en la base de testing: el control de abajo no probaría nada.');
        }

        $this->assertArrayHasKey('percentage', $columnas['ivas'], 'iva_id se relaciona con ivas.percentage (RELACIONES_POR_CONVENCION).');
        $this->assertArrayNotHasKey('zz_con_ivas', $columnas);

        // Una tabla sintética con una relación fija: iva_id → ivas.percentage.
        $columnas['zz_con_ivas'] = [
            'id'      => $this->columna_sintetica('bigint', 'bigint unsigned', 1, false),
            'user_id' => $this->columna_sintetica('bigint', 'bigint unsigned', 2, false),
            'iva_id'  => $this->columna_sintetica('bigint', 'bigint unsigned', 3, true),
        ];

        // Y una columna BLOB en la tabla de una hija. movimiento_de_caja no tiene `solo_campos`:
        // toda columna visible es campo, así que si la BLOB no entra es por la guarda de tipo...
        // siempre que su nombre no caiga en la lista negra por NOMBRE.
        $this->assertSame(
            0,
            preg_match(EsquemaDeDatosIaHelper::COLUMNAS_SENSIBLES, 'zz_adjunto'),
            'zz_adjunto cae en COLUMNAS_SENSIBLES: saldría por nombre y no probaría la guarda de tipo.'
        );

        $columnas['movimiento_cajas']['zz_adjunto'] = $this->columna_sintetica('blob', 'blob', 999, true);

        // Control: con su id, todo entra y la relación fija se arma.
        $con_id = EsquemaDeDatosIaHelper::derivar_de($columnas);

        $this->assertArrayHasKey('pending', $con_id);
        $this->assertTrue($con_id['pending']['curada'], 'pending es una de las diecisiete curadas.');
        $this->assertArrayHasKey('movimiento_de_caja', $con_id);
        $this->assertEquals('movimiento_cajas', $con_id['movimiento_de_caja']['tabla']);
        $this->assertArrayNotHasKey('zz_adjunto', $con_id['movimiento_de_caja']['campos'], 'Una BLOB en la tabla de una hija tampoco es campo.');

        $con_ivas = $this->declaracion_de_la_tabla($con_id, 'zz_con_ivas');

        $this->assertNotNull($con_ivas);
        $this->assertEquals('search', $con_ivas['campos']['iva_id']['tipo']);
        $this->assertEquals('ivas', $this->relaciones_por_columna($con_ivas)['iva_id']['tabla']);

        // Las mismas tres tablas, sin id: lo que podría tener el esquema de un cliente viejo.
        unset($columnas['pendings']['id'], $columnas['movimiento_cajas']['id'], $columnas['ivas']['id']);

        $sin_id = EsquemaDeDatosIaHelper::derivar_de($columnas);

        $this->assertArrayNotHasKey('pending', $sin_id, 'Una curada cuya tabla no tiene id no se declara.');
        $this->assertNull($this->declaracion_de_la_tabla($sin_id, 'pendings'), 'Ni como curada ni como derivada.');
        $this->assertArrayNotHasKey('movimiento_de_caja', $sin_id, 'Una hija cuya tabla no tiene id no se declara.');
        $this->assertNull($this->declaracion_de_la_tabla($sin_id, 'movimiento_cajas'));

        $con_ivas = $this->declaracion_de_la_tabla($sin_id, 'zz_con_ivas');

        $this->assertNotNull($con_ivas);
        $this->assertEquals('number', $con_ivas['campos']['iva_id']['tipo'], 'Una relación fija hacia una tabla sin id no se arma: el campo queda number.');
        $this->assertArrayNotHasKey('iva_id', $this->relaciones_por_columna($con_ivas));
    }

    /**
     * El esquema de la base conectada, leído por este test: tabla => columna => DATA_TYPE.
     *
     * ⚠️ Con alias en minúscula: MySQL 8 devuelve las columnas de `information_schema` en
     * MAYÚSCULAS por PDO, y sin alias `$fila->table_name` no existe.
     *
     * @return array<string, array<string, string>>
     */
    protected function esquema_de_la_base(): array
    {
        $filas = DB::select(
            'SELECT table_name AS tabla, column_name AS columna, data_type AS tipo_dato'
            . ' FROM information_schema.columns WHERE table_schema = DATABASE()'
        );

        $esquema = [];

        foreach ($filas as $fila) {
            $esquema[(string) $fila->tabla][(string) $fila->columna] = strtolower((string) $fila->tipo_dato);
        }

        return $esquema;
    }

    /**
     * Una columna sintética, con la misma forma que las de EsquemaDeDatosIaHelper::columnas_de_la_base().
     *
     * @param  string  $tipo_dato
     * @param  string  $tipo_columna
     * @param  int     $posicion
     * @param  bool    $admite_nulo
     * @return array<string, mixed>
     */
    protected function columna_sintetica(string $tipo_dato, string $tipo_columna, int $posicion, bool $admite_nulo): array
    {
        return [
            'tipo_dato'    => $tipo_dato,
            'tipo_columna' => $tipo_columna,
            'posicion'     => $posicion,
            'admite_nulo'  => $admite_nulo,
        ];
    }

    /**
     * La declaración que lee una tabla, buscada por TABLA y no por nombre de entidad; null si
     * ninguna la lee.
     *
     * @param  array   $catalogo
     * @param  string  $tabla
     * @return array<string, mixed>|null
     */
    protected function declaracion_de_la_tabla(array $catalogo, string $tabla)
    {
        foreach ($catalogo as $declaracion) {
            if ($declaracion['tabla'] === $tabla) {
                return $declaracion;
            }
        }

        return null;
    }

    /**
     * Las relaciones de una declaración indexadas por la columna con la que se filtran.
     *
     * @param  array  $declaracion
     * @return array<string, array<string, mixed>>
     */
    protected function relaciones_por_columna(array $declaracion): array
    {
        $relaciones = [];

        foreach ($declaracion['relaciones'] as $relacion) {
            $relaciones[$relacion['columna_id']] = $relacion;
        }

        return $relaciones;
    }
}
