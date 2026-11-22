<?php

namespace Tests\Feature\DisenoVender;

use App\Http\Controllers\VenderLayoutController;
use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Formato versión 2 del diseño de Vender, con secciones personalizables (misión
 * vender-secciones-personalizables, 22/11/2026): `POST/PUT api/vender-layout` y la normalización
 * del JSON (`VenderLayoutHelper::normalizar_layout()`).
 *
 * Criterios que cubre (PLAN §6): CA1 (formato v2 normalizado y sus bordes), CA2 (el v1 sigue
 * siendo v1) y CA3 (plantilla sola y vacíos).
 *
 * Lo que estos tests cuidan, y que se rompería EN SILENCIO:
 *
 *   - Que la API NO tire a la basura lo nuevo: hoy `normalizar_layout()` reconstruye el JSON y
 *     descarta `secciones`, `disposicion`, `plantilla` y todo `seccion_N`. Con el SPA nuevo
 *     guardando v2, un dueño perdería sus secciones al recargar y nadie vería un error.
 *   - Que la API NO deje entrar basura: ids fuera del patrón, más de 6 secciones, anchos fuera de
 *     3..12, colores fuera de la paleta, títulos gigantes (los lee el SPA de ~40 clientes).
 *   - Que el v1 siga EXACTAMENTE como hoy (los 32 tests de 1_..3_ no se tocan): un JSON sin
 *     `disposicion` sale `version 1` con las 3 etapas, sin `secciones` ni `plantilla`.
 *
 * Detalles del diseño que estos tests fijan (PLAN §2.1 y §2.7):
 *
 *   - Salida v2 en este orden de claves: version, plantilla (si hay), etapas, sacados, secciones,
 *     disposicion. El orden de claves de adentro de cada sección NO se exige: se compara con las
 *     claves ordenadas (`ordenar_claves()`), pero con tipos estrictos (un `alto` 7.0 o '7' no
 *     pasa por 7).
 *   - El backend NO completa anchos a 12 ni agrega `etapa_2` a la disposición: eso lo hace el
 *     resolver del SPA (el backend no interpreta el diseño).
 *   - El título se guarda tal cual (sin escapar): escapar es cosa del SPA.
 *
 * DatabaseTransactions (no RefreshDatabase): igual que `1_Abm_de_disenos_Test`, la base de testing
 * está sembrada de antes. Todo lo que se crea acá se revierte.
 *
 * @group disenos_de_vender
 */
class Formato_con_secciones_Test extends TestCase
{
    use DatabaseTransactions;

    /*
     * ---------------------------------------------------------------------------------------
     *  Andamio
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El dueño 500 (el de los tests de esta rama), autenticado y SIN ningún diseño (el borrado se
     * revierte con la transacción).
     *
     * @return \App\Models\User
     */
    protected function dueno_sin_disenos()
    {
        $dueno = User::find(500);

        if (is_null($dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($dueno, 'web');

        VenderLayout::where('user_id', $dueno->id)->delete();

        return $dueno;
    }

    /**
     * Guarda un diseño por el endpoint (el camino real del editor) y devuelve el layout que quedó
     * guardado, leído de la RESPUESTA (el endpoint también tiene que coincidir con la base).
     *
     * @param  mixed  $layout  Lo que manda el SPA.
     * @return array|null  El layout normalizado.
     */
    protected function guardar($layout)
    {
        $respuesta = $this->postJson('api/vender-layout', ['name' => 'Con secciones', 'layout' => $layout]);

        $respuesta->assertStatus(201);

        $guardado = VenderLayout::find($respuesta->json('model.id'))->layout;

        $this->assertSame(
            $this->ordenar_claves($guardado),
            $this->ordenar_claves($respuesta->json('model.layout')),
            'La respuesta del store no coincide con lo que quedó guardado en la base.'
        );

        return $guardado;
    }

    /**
     * Igual que `guardar()`, pero exige que lo guardado sea un v2 completo (con `secciones` y
     * `disposicion`): así, si la API todavía descarta lo nuevo, el test falla con un mensaje claro
     * y no con un "Undefined index" al leer el resultado.
     *
     * @param  mixed  $layout  Lo que manda el SPA.
     * @return array  El layout normalizado.
     */
    protected function guardar_v2($layout)
    {
        $guardado = $this->guardar($layout);

        $this->assertSame(2, $guardado['version'] ?? null, 'La API tiene que guardar el formato versión 2.');
        $this->assertArrayHasKey('secciones', $guardado, 'La API descartó `secciones`.');
        $this->assertArrayHasKey('disposicion', $guardado, 'La API descartó `disposicion`.');

        return $guardado;
    }

    /**
     * Ordena las claves de los arreglos ASOCIATIVOS (las listas conservan su orden), a cualquier
     * profundidad. Sirve para comparar con `assertSame()` —tipos estrictos— sin exigir un orden
     * de claves que el plan sólo fija para el primer nivel.
     *
     * @param  mixed  $valor
     * @return mixed
     */
    protected function ordenar_claves($valor)
    {
        if (!is_array($valor)) {
            return $valor;
        }

        $es_lista = array_keys($valor) === range(0, count($valor) - 1);

        foreach ($valor as $clave => $hijo) {
            $valor[$clave] = $this->ordenar_claves($hijo);
        }

        if (!$es_lista) {
            ksort($valor);
        }

        return $valor;
    }

    /**
     * Los metadatos de una sección tal como los deja `normalizar_seccion()` cuando no se mandó
     * nada (PLAN §2.7): sin título, sin descripción, sin color, alto 4, automática, sin plegar.
     *
     * @param  array  $cambios  Lo que cambia respecto de los valores por defecto.
     * @return array
     */
    protected function seccion_por_defecto(array $cambios = [])
    {
        return array_merge([
            'titulo'      => null,
            'descripcion' => null,
            'color'       => null,
            'alto'        => 4,
            'alto_fijo'   => false,
            'plegado'     => 'no',
        ], $cambios);
    }

    /**
     * Un v2 mínimo y válido con una sola sección de por medio (la etapa 1) para probar bordes de
     * UN metadato: la disposición es una banda de una columna de 12.
     *
     * @param  array  $meta  Metadatos de `etapa_1` tal como los manda el SPA (puede ser sucio).
     * @return array
     */
    protected function v2_con_meta_de_etapa_1(array $meta)
    {
        return [
            'version'     => 2,
            'etapas'      => ['etapa_1' => [['key' => 'cliente', 'cols' => 12]], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'secciones'   => ['etapa_1' => $meta],
            'disposicion' => [['columnas' => [['ancho' => 12, 'secciones' => ['etapa_1', 'etapa_2', 'etapa_3']]]]],
        ];
    }

    /**
     * El v2 del §2.1 del plan, con una sección creada por el usuario (`seccion_4`).
     *
     * @return array
     */
    protected function v2_del_plan()
    {
        return [
            'version'     => 2,
            'plantilla'   => 'agil',
            'etapas'      => [
                'etapa_1'   => [
                    ['key' => 'cliente', 'cols' => 12],
                    ['key' => 'separador', 'id' => 'agil_separador_1', 'cols' => 12],
                ],
                'etapa_2'   => [['key' => 'resumen', 'cols' => 12]],
                'etapa_3'   => [],
                'seccion_4' => [['key' => 'observaciones', 'cols' => 6]],
            ],
            'sacados'     => ['combos'],
            'secciones'   => [
                'etapa_1' => [
                    'titulo'      => 'Configuración inicial',
                    'descripcion' => null,
                    'color'       => 'azul',
                    'alto'        => 12,
                    'alto_fijo'   => true,
                    'plegado'     => 'no',
                ],
                'etapa_2' => [
                    'titulo'      => null,
                    'descripcion' => null,
                    'color'       => 'verde',
                    'alto'        => 8,
                    'alto_fijo'   => true,
                    'plegado'     => 'no',
                ],
            ],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 4, 'secciones' => ['etapa_1']],
                    ['ancho' => 8, 'secciones' => ['etapa_2', 'etapa_3', 'seccion_4']],
                ]],
            ],
        ];
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  CA1 — formato v2 normalizado
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El v2 del §2.1 se guarda con 201 y queda EXACTAMENTE como se mandó, más lo que la
     * normalización completa: `etapa_3` y `seccion_4` sin metadatos reciben los valores por
     * defecto, y `etapas` trae una entrada por cada sección de la disposición (en el orden en
     * que aparecen). Después de guardarlo, el show devuelve lo mismo.
     *
     * @test
     */
    public function el_store_normaliza_el_formato_con_secciones()
    {
        $this->dueno_sin_disenos();

        $guardado = $this->guardar($this->v2_del_plan());

        // El orden de claves del primer nivel es parte del contrato (PLAN §2.7).
        $this->assertSame(
            ['version', 'plantilla', 'etapas', 'sacados', 'secciones', 'disposicion'],
            array_keys($guardado)
        );

        $this->assertSame(2, $guardado['version']);
        $this->assertSame('agil', $guardado['plantilla']);

        // Una entrada por sección de la disposición, en el orden de aparición.
        $this->assertSame(['etapa_1', 'etapa_2', 'etapa_3', 'seccion_4'], array_keys($guardado['etapas']));

        $esperado = [
            'version'     => 2,
            'plantilla'   => 'agil',
            'etapas'      => [
                'etapa_1'   => [
                    ['key' => 'cliente', 'cols' => 12],
                    ['key' => 'separador', 'id' => 'agil_separador_1', 'cols' => 12],
                ],
                'etapa_2'   => [['key' => 'resumen', 'cols' => 12]],
                'etapa_3'   => [],
                'seccion_4' => [['key' => 'observaciones', 'cols' => 6]],
            ],
            'sacados'     => ['combos'],
            'secciones'   => [
                'etapa_1'   => $this->seccion_por_defecto([
                    'titulo'    => 'Configuración inicial',
                    'color'     => 'azul',
                    'alto'      => 12,
                    'alto_fijo' => true,
                ]),
                'etapa_2'   => $this->seccion_por_defecto([
                    'color'     => 'verde',
                    'alto'      => 8,
                    'alto_fijo' => true,
                ]),
                // Estas dos no vinieron en `secciones`: salen con los valores por defecto.
                'etapa_3'   => $this->seccion_por_defecto(),
                'seccion_4' => $this->seccion_por_defecto(),
            ],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 4, 'secciones' => ['etapa_1']],
                    ['ancho' => 8, 'secciones' => ['etapa_2', 'etapa_3', 'seccion_4']],
                ]],
            ],
        ];

        $this->assertSame($this->ordenar_claves($esperado), $this->ordenar_claves($guardado));

        // El show devuelve lo guardado, como objeto.
        $id = VenderLayout::where('user_id', 500)->value('id');
        $respuesta = $this->getJson('api/vender-layout/'.$id);

        $respuesta->assertStatus(200);
        $this->assertSame($this->ordenar_claves($esperado), $this->ordenar_claves($respuesta->json('model.layout')));
    }

    /**
     * Normalizar un layout ya normalizado no lo cambia (el SPA vuelve a guardar lo que recibe).
     *
     * @test
     */
    public function un_layout_v2_ya_normalizado_se_guarda_igual()
    {
        $this->dueno_sin_disenos();

        $primero = $this->guardar_v2($this->v2_del_plan());
        $segundo = $this->guardar_v2($primero);

        $this->assertSame($this->ordenar_claves($primero), $this->ordenar_claves($segundo));
        $this->assertSame(array_keys($primero), array_keys($segundo));
        $this->assertSame(array_keys($primero['etapas']), array_keys($segundo['etapas']));
    }

    /**
     * Los bordes de cada metadato de una sección, cada uno con su valor esperado (CA1):
     *
     *   - color fuera de la paleta (o que no es un string) -> null; los 7 de la paleta se conservan.
     *   - plegado desconocido -> 'no'; los dos válidos se conservan (con alto automático).
     *   - alto 0 -> 1, 20 -> 12, 'x' -> 4, 7.9 -> 7 (se trunca, como `cols`).
     *   - alto_fijo sólo es true con true, 1, '1' o 'true'.
     *   - alto_fijo true fuerza plegado 'no' (una sección fija plegada deja un hueco en su columna).
     *   - título: se recorta a 60 caracteres (con `mb_`, las tildes cuentan como uno), se hace
     *     trim, '' o espacios -> null, y un título con HTML se guarda tal cual.
     *   - descripción: 160 caracteres.
     *   - claves desconocidas de la sección -> afuera.
     *
     * @test
     */
    public function los_bordes_de_las_secciones_se_acotan()
    {
        $this->dueno_sin_disenos();

        // Color.
        foreach (['azul', 'verde', 'naranja', 'violeta', 'rojo', 'turquesa', 'gris'] as $color) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['color' => $color]))['secciones']['etapa_1'];
            $this->assertSame($color, $meta['color'], 'El color '.$color.' es de la paleta y tiene que conservarse.');
        }
        foreach (['fucsia', 'AZUL', '', 5, true, ['azul']] as $color) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['color' => $color]))['secciones']['etapa_1'];
            $this->assertNull($meta['color'], 'Un color fuera de la paleta tiene que quedar en null: '.json_encode($color));
        }

        // Plegado.
        foreach (['si_hay_venta', 'arranca_plegada', 'no'] as $plegado) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['plegado' => $plegado, 'alto_fijo' => false]))['secciones']['etapa_1'];
            $this->assertSame($plegado, $meta['plegado']);
        }
        foreach (['x', '', 5, null] as $plegado) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['plegado' => $plegado, 'alto_fijo' => false]))['secciones']['etapa_1'];
            $this->assertSame('no', $meta['plegado'], 'Un plegado desconocido tiene que quedar en "no": '.json_encode($plegado));
        }

        // Alto: entero acotado a 1..12.
        foreach ([[0, 1], [-3, 1], [1, 1], [6, 6], [12, 12], [20, 12], [7.9, 7], ['x', 4], [null, 4], [[], 4]] as $par) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['alto' => $par[0]]))['secciones']['etapa_1'];
            $this->assertSame($par[1], $meta['alto'], 'alto '.json_encode($par[0]).' tiene que quedar en '.$par[1].'.');
        }

        // alto_fijo: sólo true, 1, '1' y 'true'.
        foreach ([true, 1, '1', 'true'] as $fijo) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['alto_fijo' => $fijo]))['secciones']['etapa_1'];
            $this->assertSame(true, $meta['alto_fijo'], 'alto_fijo '.json_encode($fijo).' tiene que ser true.');
        }
        foreach ([false, 0, '0', 'false', 'si', '', null, 'x'] as $fijo) {
            $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['alto_fijo' => $fijo]))['secciones']['etapa_1'];
            $this->assertSame(false, $meta['alto_fijo'], 'alto_fijo '.json_encode($fijo).' tiene que ser false.');
        }

        // alto_fijo true + plegado 'arranca_plegada' -> plegado 'no'.
        $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['alto_fijo' => true, 'plegado' => 'arranca_plegada']))['secciones']['etapa_1'];
        $this->assertSame(true, $meta['alto_fijo']);
        $this->assertSame('no', $meta['plegado'], 'Una sección de alto fijo no se pliega.');

        $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['alto_fijo' => true, 'plegado' => 'si_hay_venta']))['secciones']['etapa_1'];
        $this->assertSame('no', $meta['plegado']);

        // Título: 80 caracteres con tildes -> 60 (mb_strlen).
        $titulo = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['titulo' => str_repeat('á', 80)]))['secciones']['etapa_1']['titulo'];
        $this->assertSame(60, mb_strlen($titulo));
        $this->assertSame(str_repeat('á', 60), $titulo);

        // Un título de exactamente 60 queda igual.
        $titulo = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['titulo' => str_repeat('ñ', 60)]))['secciones']['etapa_1']['titulo'];
        $this->assertSame(str_repeat('ñ', 60), $titulo);

        // Trim; vacío o sólo espacios -> null; lo que no es texto -> null.
        $titulo = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['titulo' => '  Mi cierre  ']))['secciones']['etapa_1']['titulo'];
        $this->assertSame('Mi cierre', $titulo);

        foreach (['   ', '', "\t\n", 5, true, ['x']] as $titulo_invalido) {
            $titulo = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['titulo' => $titulo_invalido]))['secciones']['etapa_1']['titulo'];
            $this->assertNull($titulo, 'El título '.json_encode($titulo_invalido).' tiene que quedar en null.');
        }

        // Un título con HTML se guarda tal cual: escapar es cosa del SPA.
        $xss = '<img src=x onerror=1>';
        $titulo = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['titulo' => $xss]))['secciones']['etapa_1']['titulo'];
        $this->assertSame($xss, $titulo);

        // Descripción: 200 caracteres -> 160; trim; vacía -> null.
        $descripcion = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['descripcion' => str_repeat('é', 200)]))['secciones']['etapa_1']['descripcion'];
        $this->assertSame(160, mb_strlen($descripcion));
        $this->assertSame(str_repeat('é', 160), $descripcion);

        $descripcion = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['descripcion' => '   ']))['secciones']['etapa_1']['descripcion'];
        $this->assertNull($descripcion);

        $descripcion = $this->guardar_v2($this->v2_con_meta_de_etapa_1(['descripcion' => ' Lo que cambia en cada venta ']))['secciones']['etapa_1']['descripcion'];
        $this->assertSame('Lo que cambia en cada venta', $descripcion);

        // Claves desconocidas de la sección -> afuera.
        $meta = $this->guardar_v2($this->v2_con_meta_de_etapa_1([
            'titulo'   => 'Con extras',
            'icono'    => 'x',
            'rotacion' => 90,
            'cols'     => 4,
        ]))['secciones']['etapa_1'];

        $this->assertSame(
            $this->seccion_por_defecto(['titulo' => 'Con extras']),
            $this->ordenar_claves($meta)
        );
        $this->assertSame(
            ['alto', 'alto_fijo', 'color', 'descripcion', 'plegado', 'titulo'],
            array_keys($this->ordenar_claves($meta))
        );
    }

    /**
     * Ids, columnas y bandas (CA1):
     *
     *   - ids 'seccion_10', 'foo', 'etapa_4', 'seccion_3' -> afuera (patrón `^(etapa_[123]|seccion_[4-9])$`).
     *   - id repetido en todo el diseño -> queda el primero.
     *   - 7 secciones -> 6, 5 columnas -> 4, 7 bandas -> 6.
     *   - ancho 1 -> 3, 'x' -> 12, 15 -> 12 (acotado a 3..12, no numérico a 12).
     *   - columna sin secciones y banda sin columnas -> afuera (el backend NO completa anchos).
     *   - propiedades desconocidas de banda y de columna -> afuera.
     *   - `etapas.seccion_5` sin estar en la disposición -> afuera (y `etapa_3` tampoco, si no está).
     *
     * @test
     */
    public function ids_columnas_y_bandas_se_acotan()
    {
        $this->dueno_sin_disenos();

        $items = [['key' => 'cliente', 'cols' => 12]];

        // Ids inválidos y repetidos, y una sección de `etapas` que la disposición no nombra.
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => [
                'etapa_1'    => $items,
                'etapa_2'    => [],
                'seccion_5'  => $items,
                'etapa_3'    => $items,
                'seccion_10' => $items,
            ],
            'sacados'     => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 6, 'secciones' => ['seccion_10', 'foo', 'etapa_1', 'etapa_4', 'seccion_3', 'etapa_2']],
                    // etapa_1 ya está en la columna de arriba: queda el primero. seccion_4 es nueva.
                    ['ancho' => 6, 'secciones' => ['etapa_1', 'seccion_4', 'seccion_4']],
                ]],
            ],
        ]);

        $this->assertSame(
            [
                ['columnas' => [
                    ['ancho' => 6, 'secciones' => ['etapa_1', 'etapa_2']],
                    ['ancho' => 6, 'secciones' => ['seccion_4']],
                ]],
            ],
            $guardado['disposicion']
        );

        // `etapas`: una entrada por id de la disposición. Las que no están (seccion_5, seccion_10 y
        // etapa_3) se descartan; etapa_2 está porque la disposición la nombra.
        $this->assertEqualsCanonicalizing(['etapa_1', 'etapa_2', 'seccion_4'], array_keys($guardado['etapas']));
        $this->assertEqualsCanonicalizing(['etapa_1', 'etapa_2', 'seccion_4'], array_keys($guardado['secciones']));
        $this->assertSame($items, $guardado['etapas']['etapa_1']);
        $this->assertSame([], $guardado['etapas']['seccion_4']);

        // 7 secciones -> 6 (se conservan las 6 primeras en orden de lectura).
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 12, 'secciones' => ['etapa_1', 'etapa_2', 'etapa_3', 'seccion_4', 'seccion_5', 'seccion_6', 'seccion_7']],
                ]],
            ],
        ]);

        $this->assertSame(
            ['etapa_1', 'etapa_2', 'etapa_3', 'seccion_4', 'seccion_5', 'seccion_6'],
            $guardado['disposicion'][0]['columnas'][0]['secciones']
        );
        $this->assertCount(6, $guardado['etapas']);
        $this->assertCount(6, $guardado['secciones']);
        $this->assertArrayNotHasKey('seccion_7', $guardado['etapas']);
        $this->assertArrayNotHasKey('seccion_7', $guardado['secciones']);

        // 5 columnas -> 4 (la quinta, y su sección, se descartan).
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 3, 'secciones' => ['etapa_1']],
                    ['ancho' => 3, 'secciones' => ['etapa_2']],
                    ['ancho' => 3, 'secciones' => ['etapa_3']],
                    ['ancho' => 3, 'secciones' => ['seccion_4']],
                    ['ancho' => 3, 'secciones' => ['seccion_5']],
                ]],
            ],
        ]);

        $this->assertCount(4, $guardado['disposicion'][0]['columnas']);
        $this->assertSame(
            [['etapa_1'], ['etapa_2'], ['etapa_3'], ['seccion_4']],
            array_column($guardado['disposicion'][0]['columnas'], 'secciones')
        );
        $this->assertArrayNotHasKey('seccion_5', $guardado['etapas']);

        // 7 bandas -> 6.
        $bandas = [];
        foreach (['etapa_1', 'etapa_2', 'etapa_3', 'seccion_4', 'seccion_5', 'seccion_6', 'seccion_7'] as $id) {
            $bandas[] = ['columnas' => [['ancho' => 12, 'secciones' => [$id]]]];
        }

        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => $bandas,
        ]);

        $this->assertCount(6, $guardado['disposicion']);
        $this->assertSame('seccion_6', $guardado['disposicion'][5]['columnas'][0]['secciones'][0]);

        // Ancho: 1 -> 3, 'x' -> 12, 15 -> 12, 4.9 -> 4; los de 3..12 quedan como están.
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 1, 'secciones' => ['etapa_1']],
                    ['ancho' => 'x', 'secciones' => ['etapa_2']],
                    ['ancho' => 15, 'secciones' => ['etapa_3']],
                    ['ancho' => 4.9, 'secciones' => ['seccion_4']],
                ]],
                ['columnas' => [
                    ['ancho' => 3, 'secciones' => ['seccion_5']],
                    ['ancho' => 12, 'secciones' => ['seccion_6']],
                ]],
            ],
        ]);

        $this->assertSame([3, 12, 12, 4], array_column($guardado['disposicion'][0]['columnas'], 'ancho'));
        $this->assertSame([3, 12], array_column($guardado['disposicion'][1]['columnas'], 'ancho'));

        // Columna sin secciones, banda sin columnas y banda con sólo ids inválidos -> afuera.
        // El backend NO redistribuye los anchos: la columna que queda conserva el suyo.
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => [
                ['columnas' => [
                    ['ancho' => 6, 'secciones' => []],
                    ['ancho' => 6, 'secciones' => ['etapa_1']],
                ]],
                ['columnas' => []],
                ['columnas' => [['ancho' => 12, 'secciones' => ['foo']]]],
                ['columnas' => [['ancho' => 12, 'secciones' => ['etapa_2']]]],
            ],
        ]);

        $this->assertSame(
            [
                ['columnas' => [['ancho' => 6, 'secciones' => ['etapa_1']]]],
                ['columnas' => [['ancho' => 12, 'secciones' => ['etapa_2']]]],
            ],
            $guardado['disposicion']
        );

        // Propiedades desconocidas de banda y de columna -> afuera.
        $guardado = $this->guardar_v2([
            'version'     => 2,
            'etapas'      => ['etapa_1' => [], 'etapa_2' => [], 'etapa_3' => []],
            'sacados'     => [],
            'disposicion' => [
                [
                    'alto'     => 3,
                    'columnas' => [['ancho' => 12, 'color' => 'rojo', 'secciones' => ['etapa_1', 'etapa_2']]],
                    'extra'    => 'x',
                ],
            ],
        ]);

        $this->assertSame(
            [['columnas' => [['ancho' => 12, 'secciones' => ['etapa_1', 'etapa_2']]]]],
            $guardado['disposicion']
        );
        $this->assertSame(['columnas'], array_keys($guardado['disposicion'][0]));
        $this->assertSame(['ancho', 'secciones'], array_keys($guardado['disposicion'][0]['columnas'][0]));
    }

    /**
     * Un PUT con sólo `layout` v2 queda normalizado igual que el store (CA1: "PUT parcial con un v2
     * -> mismo resultado"), pisa el layout v1 anterior y no toca el nombre ni el "en uso".
     *
     * @test
     */
    public function el_put_parcial_acepta_el_formato_con_secciones()
    {
        $dueno = $this->dueno_sin_disenos();

        $v1 = [
            'version' => 1,
            'etapas'  => [
                'etapa_1' => [['key' => 'metodo_de_pago', 'cols' => 4]],
                'etapa_2' => [['key' => 'resumen', 'cols' => 12]],
                'etapa_3' => [],
            ],
            'sacados' => [],
        ];

        $diseno = VenderLayout::create([
            'user_id' => $dueno->id,
            'name'    => 'Mostrador',
            'layout'  => $v1,
            'en_uso'  => true,
        ]);

        $respuesta = $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => $this->v2_del_plan()]);

        $respuesta->assertStatus(200);

        $fresco = $diseno->fresh();

        $this->assertSame('Mostrador', $fresco->name);
        $this->assertTrue($fresco->en_uso);

        $this->assertSame(2, $fresco->layout['version']);
        $this->assertSame('agil', $fresco->layout['plantilla']);
        $this->assertSame(['etapa_1', 'etapa_2', 'etapa_3', 'seccion_4'], array_keys($fresco->layout['etapas']));

        // Mismo resultado que por el store.
        $por_store = $this->guardar($this->v2_del_plan());

        $this->assertSame($this->ordenar_claves($por_store), $this->ordenar_claves($fresco->layout));
        $this->assertSame($this->ordenar_claves($por_store), $this->ordenar_claves($respuesta->json('model.layout')));

        // Y se puede volver a un v1 con otro PUT.
        $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => $v1])->assertStatus(200);

        $this->assertSame($v1, $diseno->fresh()->layout);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  CA2 — el v1 sigue siendo v1
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Un layout SIN `disposicion` es v1 aunque traiga claves nuevas: sale `version 1`, sólo las 3
     * etapas, sin `secciones` ni `plantilla` (la rama v1 queda exactamente como hoy, y por eso los
     * 32 tests de 1_..3_ siguen verdes sin tocarlos).
     *
     * @test
     */
    public function un_layout_v1_con_claves_nuevas_sigue_siendo_v1()
    {
        $this->dueno_sin_disenos();

        $esperado = [
            'version' => 1,
            'etapas'  => [
                'etapa_1' => [['key' => 'cliente', 'cols' => 12]],
                'etapa_2' => [['key' => 'resumen', 'cols' => 12]],
                'etapa_3' => [],
            ],
            'sacados' => ['combos'],
        ];

        // Con `secciones`, `plantilla` y una `seccion_4`, pero sin `disposicion`.
        $guardado = $this->guardar([
            'version'   => 1,
            'plantilla' => 'agil',
            'etapas'    => [
                'etapa_1'   => [['key' => 'cliente', 'cols' => 12]],
                'etapa_2'   => [['key' => 'resumen', 'cols' => 12]],
                'seccion_4' => [['key' => 'observaciones', 'cols' => 6]],
            ],
            'sacados'   => ['combos'],
            'secciones' => ['etapa_1' => ['titulo' => 'Otro título', 'color' => 'rojo']],
        ]);

        $this->assertSame($esperado, $guardado);
        $this->assertSame(['version', 'etapas', 'sacados'], array_keys($guardado));
        $this->assertSame(['etapa_1', 'etapa_2', 'etapa_3'], array_keys($guardado['etapas']));

        // Pedir `version: 2` sin `disposicion` no lo vuelve v2: la versión la decide la forma.
        $guardado = $this->guardar([
            'version' => 2,
            'etapas'  => [
                'etapa_1' => [['key' => 'cliente', 'cols' => 12]],
                'etapa_2' => [['key' => 'resumen', 'cols' => 12]],
            ],
            'sacados' => ['combos'],
        ]);

        $this->assertSame($esperado, $guardado);

        // Lo mismo por PUT y con el layout como string JSON.
        $dueno = User::find(500);
        $diseno = VenderLayout::create(['user_id' => $dueno->id, 'name' => 'Viejo', 'layout' => null, 'en_uso' => false]);

        $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => json_encode([
            'plantilla' => 'agil',
            'etapas'    => ['etapa_1' => [['key' => 'cliente', 'cols' => 12]], 'etapa_2' => [['key' => 'resumen', 'cols' => 12]]],
            'sacados'   => ['combos'],
            'secciones' => ['etapa_1' => ['titulo' => 'x']],
        ])])->assertStatus(200);

        $this->assertSame($esperado, $diseno->fresh()->layout);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  CA3 — plantilla sola y vacíos
     * ---------------------------------------------------------------------------------------
     */

    /**
     * `{"plantilla":"agil"}` (sin `etapas`) es "el diseño ágil armado en código": se guarda como
     * `{version: 2, plantilla: 'agil'}` y nada más. Con `etapas` y `disposicion` presentes la
     * plantilla se conserva como origen del v2 completo.
     *
     * @test
     */
    public function la_plantilla_sola_se_guarda_tal_cual()
    {
        $dueno = $this->dueno_sin_disenos();

        $esperado = ['version' => 2, 'plantilla' => 'agil'];

        // Sola, como objeto.
        $guardado = $this->guardar(['plantilla' => 'agil']);

        $this->assertSame($esperado, $guardado);
        $this->assertSame(['version', 'plantilla'], array_keys($guardado));

        // Como string JSON.
        $this->assertSame($esperado, $this->guardar('{"plantilla":"agil"}'));

        // Ya normalizada, queda igual.
        $this->assertSame($esperado, $this->guardar($esperado));

        // Lo demás que venga con la plantilla sola se descarta.
        $this->assertSame($esperado, $this->guardar(['plantilla' => 'agil', 'version' => 9, 'sacados' => ['combos'], 'foo' => 1]));

        // Por PUT.
        $diseno = VenderLayout::create(['user_id' => $dueno->id, 'name' => 'Ágil', 'layout' => null, 'en_uso' => false]);

        $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => ['plantilla' => 'agil']])->assertStatus(200);

        $this->assertSame($esperado, $diseno->fresh()->layout);

        // Con `etapas` y `disposicion`, la plantilla se conserva en el v2 completo.
        $completo = $this->guardar($this->v2_del_plan());

        $this->assertSame(2, $completo['version']);
        $this->assertSame('agil', $completo['plantilla']);
        $this->assertArrayHasKey('disposicion', $completo);

        // Una plantilla desconocida junto a un v2 completo y válido no invalida el diseño: sólo
        // se descarta la plantilla.
        $v2 = $this->v2_del_plan();
        $v2['plantilla'] = 'otra';

        $sin_plantilla = $this->guardar($v2);

        $this->assertSame(2, $sin_plantilla['version']);
        $this->assertArrayNotHasKey('plantilla', $sin_plantilla);
        $this->assertSame(['version', 'etapas', 'sacados', 'secciones', 'disposicion'], array_keys($sin_plantilla));
    }

    /**
     * Lo inválido da 422 con el mensaje de siempre y no guarda nada:
     *
     *   - `{"plantilla":"otra"}` (plantilla desconocida y sin `etapas`).
     *   - `{"etapas":{},"disposicion":[]}` (disposición vacía).
     *   - `{"etapas":{},"disposicion":[{"columnas":[{"ancho":12,"secciones":["foo"]}]}]}` (la
     *     disposición queda vacía después de descartar los ids inválidos).
     *
     * En el PUT tampoco entra y el diseño queda como estaba.
     *
     * @test
     */
    public function una_plantilla_desconocida_o_una_disposicion_vacia_da_422()
    {
        $dueno = $this->dueno_sin_disenos();

        $invalidos = [
            'plantilla desconocida'            => ['plantilla' => 'otra'],
            'plantilla que no es texto'        => ['plantilla' => 5],
            'plantilla vacía'                  => ['plantilla' => ''],
            'disposición vacía'                => ['etapas' => [], 'disposicion' => []],
            'disposición con un id inválido'   => ['etapas' => [], 'disposicion' => [['columnas' => [['ancho' => 12, 'secciones' => ['foo']]]]]],
            'disposición sólo con ids viejos'  => ['etapas' => [], 'disposicion' => [['columnas' => [['ancho' => 12, 'secciones' => ['etapa_4', 'seccion_3', 'seccion_10']]]]]],
            'disposición sólo con vacíos'      => ['etapas' => [], 'disposicion' => [['columnas' => []], ['columnas' => [['ancho' => 12, 'secciones' => []]]]]],
            'plantilla desconocida y vacía'    => ['plantilla' => 'otra', 'etapas' => [], 'disposicion' => []],
        ];

        foreach ($invalidos as $caso => $layout) {
            $respuesta = $this->postJson('api/vender-layout', ['name' => 'Inválido', 'layout' => $layout]);

            $respuesta->assertStatus(422);
            $this->assertSame(
                VenderLayoutController::MENSAJE_LAYOUT_INVALIDO,
                $respuesta->json('message'),
                'Caso: '.$caso
            );
        }

        $this->assertSame('El diseño no tiene un formato válido.', VenderLayoutController::MENSAJE_LAYOUT_INVALIDO);
        $this->assertSame(0, VenderLayout::where('user_id', $dueno->id)->count(), 'Un layout inválido no tiene que guardar nada.');

        // También como string JSON.
        $this->postJson('api/vender-layout', ['name' => 'Inválido', 'layout' => '{"plantilla":"otra"}'])->assertStatus(422);
        $this->postJson('api/vender-layout', ['name' => 'Inválido', 'layout' => '{"etapas":{},"disposicion":[]}'])->assertStatus(422);

        // En el update tampoco entra, y el diseño queda como estaba.
        $propio = ['version' => 2, 'plantilla' => 'agil'];
        $diseno = VenderLayout::create(['user_id' => $dueno->id, 'name' => 'Ágil', 'layout' => $propio, 'en_uso' => true]);

        foreach ($invalidos as $caso => $layout) {
            $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => $layout])->assertStatus(422);
        }

        $this->assertSame($propio, $diseno->fresh()->layout);
    }
}
