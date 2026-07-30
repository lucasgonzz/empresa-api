<?php

namespace Tests\Import;

/**
 * Matriz de configuraciones del escalón provider_code de
 * ArticleIndexCache::find_with_index().
 *
 * Se importa SIEMPRE el mismo archivo (01_codigos_de_proveedor.xlsx) cambiando
 * solo la configuración, y se verifica en qué bucket cae cada fila. El archivo
 * tiene 7 filas, una por cada situación posible frente al escenario sembrado:
 *
 *   F2  PC-100   -> existe 1 vez en el proveedor A            (match limpio)
 *   F3  PC-DUP   -> existe 2 veces en el proveedor A          (repetido mismo proveedor)
 *   F4  PC-CROSS -> existe en B y en C, no en A               (repetido otros proveedores)
 *   F5  PC-NUEVO -> no existe                                  (creación)
 *   F6  PC-1500  -> existe solo en C                           (uno solo en otro proveedor)
 *   F7  "S/N"    -> placeholder, se descarta el identificador
 *   F8  "-"      -> placeholder, se descarta el identificador
 *
 * F2 y F3 llevan ademas un sku NUEVO (que no matchea nada por si mismo) desde
 * el grupo 265, prompt 10: no cambia ningun bucket ni conflicto de esta clase
 * (la cascada de sku sigue de largo hasta provider_code igual que antes), lo
 * agrega RepetidosEnElArchivoTest.php para probar la herencia de sku de la
 * cascada (prompt 08) contra un match unico (F2) y un match multiple (F3),
 * en vez de sumar un fixture nuevo.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class CodigosDeProveedorTest extends ImportTestCase
{
    const ARCHIVO = '01_codigos_de_proveedor.xlsx';

    /**
     * @dataProvider matriz_de_configuraciones
     *
     * @param  array       $config             Overrides de configuración
     * @param  string|null $proveedor          Clave del proveedor a seleccionar (A|B|C) o null
     * @param  array       $buckets_esperados
     * @param  int         $creados_esperados
     * @param  string      $descripcion
     * @return void
     */
    public function test_matriz_de_configuraciones(
        array $config,
        $proveedor,
        array $buckets_esperados,
        $creados_esperados,
        $descripcion
    ) {
        $config['provider_id'] = is_null($proveedor) ? null : $this->providers[$proveedor]->id;

        $import = $this->importar(self::ARCHIVO, $config);

        $this->assertBuckets($import, $buckets_esperados);

        $this->assertCount(
            $creados_esperados,
            $this->articulos_creados(),
            $descripcion . ' — cantidad de artículos creados'
        );
    }

    /**
     * @return array
     */
    public function matriz_de_configuraciones()
    {
        return [

            /*
             * 1) Configuración conservadora por defecto.
             * PC-CROSS y PC-1500 NO se bloquean porque permitir_..._multi_providers = true:
             * al no haber match en el proveedor A, se crean artículos nuevos.
             */
            'defaults con proveedor A' => [
                'config' => [],
                'proveedor' => 'A',
                'buckets' => [
                    'provider_code' => 1,   // F2
                    'ambiguo'       => 1,   // F3
                    'creado_nuevo'  => 5,   // F4, F5, F6, F7, F8
                ],
                'creados' => 5,
                'descripcion' => 'defaults con proveedor A',
            ],

            /*
             * 2) Bloqueo por proveedor ajeno: la única combinación que produce el
             * marcador __provider_code_blocked_by_other_provider.
             */
            'bloquea codigos de otros proveedores' => [
                'config' => [
                    'permitir_provider_code_repetido_en_multi_providers' => false,
                    'actualizar_articulos_de_otro_proveedor'             => false,
                ],
                'proveedor' => 'A',
                'buckets' => [
                    'provider_code'            => 1,  // F2
                    'ambiguo'                  => 1,  // F3
                    'bloqueado_otro_proveedor' => 2,  // F4, F6
                    'creado_nuevo'             => 3,  // F5, F7, F8
                ],
                'creados' => 3,
                'descripcion' => 'bloquea codigos de otros proveedores',
            ],

            /*
             * 3) Actualizar artículos de otro proveedor: PC-1500 (uno solo en C) pasa a
             * actualizarse, y PC-CROSS (dos, en B y C) pasa a ser ambiguo.
             */
            'actualiza articulos de otro proveedor' => [
                'config' => [
                    'actualizar_articulos_de_otro_proveedor' => true,
                ],
                'proveedor' => 'A',
                'buckets' => [
                    'provider_code' => 2,   // F2, F6
                    'ambiguo'       => 2,   // F3, F4
                    'creado_nuevo'  => 3,   // F5, F7, F8
                ],
                'creados' => 3,
                'descripcion' => 'actualiza articulos de otro proveedor',
            ],

            /*
             * 4) Permitir provider_code repetido: PC-DUP deja de ser ambiguo y actualiza
             * LOS DOS artículos (A3 y A4). Se verifica aparte en test_permitir_repetido_actualiza_los_dos().
             */
            'permite provider_code repetido' => [
                'config' => [
                    'permitir_provider_code_repetido' => true,
                ],
                'proveedor' => 'A',
                'buckets' => [
                    'provider_code' => 2,   // F2, F3 (F3 devuelve colección de 2)
                    'creado_nuevo'  => 5,   // F4, F5, F6, F7, F8
                ],
                'creados' => 5,
                'descripcion' => 'permite provider_code repetido',
            ],

            /*
             * 5) Identificación por provider_code deshabilitada: NINGUNA fila matchea,
             * todas se crean. Ojo: el escalón 4 hace return, no cae al escalón name.
             */
            'no identifica por provider_code' => [
                'config' => [
                    'actualizar_por_provider_code' => false,
                ],
                'proveedor' => 'A',
                'buckets' => [
                    'creado_nuevo' => 7,
                ],
                'creados' => 7,
                'descripcion' => 'no identifica por provider_code',
            ],

            /*
             * 6) Solo actualizar (create_and_edit = false): no se crea nada.
             */
            'solo actualizar sin crear' => [
                'config' => [
                    'create_and_edit' => false,
                ],
                'proveedor' => 'A',
                'buckets' => [
                    'provider_code'       => 1,  // F2
                    'ambiguo'             => 1,  // F3
                    'sin_match_no_creado' => 5,  // F4, F5, F6, F7, F8
                ],
                'creados' => 0,
                'descripcion' => 'solo actualizar sin crear',
            ],

            /*
             * 7) Sin proveedor seleccionado: find_with_index() colapsa la distinción
             * mismo/otro proveedor, así que PC-CROSS pasa a ser ambiguo (B + C) y
             * PC-1500 matchea con el artículo de C.
             */
            'sin proveedor seleccionado' => [
                'config' => [],
                'proveedor' => null,
                'buckets' => [
                    'provider_code' => 2,   // F2, F6
                    'ambiguo'       => 2,   // F3, F4
                    'creado_nuevo'  => 3,   // F5, F7, F8
                ],
                'creados' => 3,
                'descripcion' => 'sin proveedor seleccionado',
            ],
        ];
    }

    /**
     * Con repetidos permitidos, una sola fila del Excel tiene que impactar en TODOS
     * los artículos que comparten ese provider_code, no en el primero que aparezca.
     *
     * @return void
     */
    public function test_permitir_repetido_actualiza_los_dos_articulos()
    {
        $this->importar(self::ARCHIVO, [
            'provider_id'                     => $this->providers['A']->id,
            'permitir_provider_code_repetido' => true,
        ]);

        /* F3 del Excel: PC-DUP con costo 333. */
        $this->assertDecimal(333, $this->recargar('A3')->cost, 'A3 tiene que quedar con el costo del Excel');
        $this->assertDecimal(333, $this->recargar('A4')->cost, 'A4 tiene que quedar con el costo del Excel');
    }

    /**
     * Sin repetidos permitidos, PC-DUP es ambiguo: NO se toca ninguno de los dos y
     * queda registrado el conflicto para mostrárselo al usuario.
     *
     * @return void
     */
    public function test_provider_code_ambiguo_no_toca_ningun_articulo()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertDecimal(300, $this->recargar('A3')->cost, 'A3 no se tiene que tocar');
        $this->assertDecimal(400, $this->recargar('A4')->cost, 'A4 no se tiene que tocar');

        $this->assertSame(1, $this->conflictos($import, 'ambiguo'));
    }

    /**
     * Los placeholders ("S/N", "-") se descartan como identificador y quedan
     * registrados como conflicto, y la fila NO matchea contra ningún artículo.
     *
     * @return void
     */
    public function test_placeholders_se_descartan_y_se_reportan()
    {
        $import = $this->importar(self::ARCHIVO, [
            'provider_id' => $this->providers['A']->id,
        ]);

        $this->assertSame(2, $this->conflictos($import, 'placeholder_descartado'), 'F7 y F8');
        $this->assertSame(2, $this->conflictos($import, 'sin_identificador'), 'F7 y F8 quedan sin ningún identificador');
    }

    /**
     * Comportamiento fijado a propósito (NO es lo deseable, es lo que hace hoy):
     * A12 tiene provider_code 'PC-1200' pero provider_id NULL, y
     * ArticleIndexCache::build() solo indexa provider_codes cuando el artículo tiene
     * los DOS campos. Resultado: es invisible al escalón provider_code y, como ese
     * escalón hace return en vez de caer al escalón name, tampoco se lo encuentra
     * por nombre: se crea un duplicado en cada importación.
     *
     * Si algún día se corrige el build o el fall-through, este test falla y hay que
     * actualizarlo a conciencia.
     *
     * @return void
     */
    public function test_articulo_con_provider_code_pero_sin_proveedor_no_matchea()
    {
        $import = $this->importar('04_stock.xlsx', ['provider_id' => null]);

        $creados = $this->articulos_creados()->pluck('provider_code')->toArray();

        $this->assertContains(
            'PC-1200',
            $creados,
            'Hoy se crea un duplicado porque A12 no está indexado (provider_id null). '
            . 'Si esto falla, revisá ArticleIndexCache::build() antes de tocar el test.'
        );

        $this->assertDecimal(1200, $this->recargar('A12')->cost, 'A12 queda intacto');
    }
}
