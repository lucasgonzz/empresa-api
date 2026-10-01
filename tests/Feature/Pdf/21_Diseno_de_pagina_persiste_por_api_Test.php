<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El diseño de la hoja (`pdf_column_profiles.page_layout`) y su alto (`paper_height_mm`) viajan por
 * la API real de diseños de PDF, que es el único camino que usa el diseñador del SPA (misión
 * diseno-pdf-configurable, 1/10/2026, §2.4 del plan).
 *
 * Cubre la clase de bug que ya pasó tres veces en este controller (show_client_description,
 * show_subtotal_in_footer, catalog_header_layout: ver los tests 4, 13 y 5): una columna que está en
 * la migración y en el cast pero que store()/update() descartan en silencio, así que el guardado
 * responde 200 y el valor nunca llega a la base. Y además lo propio de este campo:
 *
 * - se guarda NORMALIZADO (columnas, tamaños, textos, campos repetidos, ids);
 * - los bloques fijos de ARCA se ponen en un perfil fiscal y se sacan en uno que no lo es;
 * - un JSON sin forma da 422 SIN escribir nada (ni los "por defecto" de los hermanos);
 * - `etiqueta: ""` ("sin rótulo") no se convierte en null ("la del catálogo") en el camino: el
 *   middleware global ConvertEmptyStringsToNull lo haría si el controller leyera $request->input().
 *
 * Cada test crea dentro de su transacción todo lo que usa.
 *
 * @group pdf-diseno-de-pagina
 */
class Diseno_de_pagina_persiste_por_api_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Dueño autenticado (mismo patrón que los tests 4 y 13 de esta carpeta).
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return $owner;
    }

    /**
     * Diseño de PDF mínimo, creado directo por Eloquent (para probar el update()).
     *
     * @param int   $owner_id
     * @param array $overrides
     * @return \App\Models\PdfColumnProfile
     */
    protected function crear_perfil($owner_id, array $overrides = [])
    {
        return PdfColumnProfile::create(array_merge([
            'user_id'            => $owner_id,
            'model_name'         => 'sale',
            'name'               => 'zz Remito de test diseño de página',
            'paper_width_mm'     => 210,
            'printable_width_mm' => 210,
            'margin_mm'          => 5,
            /** NOT NULL sin default en la tabla (columna JSON): hay que pasarla a mano. */
            'columns'            => [],
        ], $overrides));
    }

    /**
     * Dos opciones del catálogo del modelo, con el formato que manda el SPA (para el POST).
     *
     * @param string $model_name
     * @return array<int, array<string, mixed>>
     */
    protected function opciones_de_columnas($model_name)
    {
        $payload = [];
        $orden = 0;

        foreach (PdfColumnService::get_options($model_name)->take(2) as $opcion) {
            $payload[] = [
                'id'    => $opcion->id,
                'pivot' => ['visible' => true, 'order' => $orden++, 'width' => 50, 'wrap_content' => false],
            ];
        }

        return $payload;
    }

    /**
     * Lo que manda el formulario al crear un diseño.
     *
     * @param array $extra
     * @return array
     */
    protected function payload_de_alta($extra = [])
    {
        $model_name = isset($extra['model_name']) ? $extra['model_name'] : 'sale';

        return array_merge([
            'model_name'         => $model_name,
            'name'               => 'zz Diseño de test alta con diseño de página',
            'paper_width_mm'     => 210,
            'printable_width_mm' => 210,
            'margin_mm'          => 5,
            'pdf_column_options' => $this->opciones_de_columnas($model_name),
        ], $extra);
    }

    /**
     * Un diseño válido y ya normalizado: una caja arriba y la de totales abajo.
     *
     * @return array
     */
    protected function diseno_valido()
    {
        return [
            'version'  => 1,
            'superior' => [
                [
                    'tipo' => 'caja', 'id' => 'caja_cliente', 'cols' => 6, 'titulo' => 'Cliente', 'estilo' => 'borde',
                    'campos' => [
                        ['key' => 'cliente_nombre', 'etiqueta' => null, 'tamano' => null, 'negrita' => null, 'cursiva' => null, 'alineacion' => null],
                    ],
                ],
            ],
            'pie' => [
                [
                    'tipo' => 'caja', 'id' => 'caja_totales', 'cols' => 12, 'titulo' => '', 'estilo' => 'gris',
                    'campos' => [
                        ['key' => 'tot_total', 'etiqueta' => null, 'tamano' => 14, 'negrita' => true, 'cursiva' => false, 'alineacion' => 'derecha'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Un diseño con todo lo que normalizar() tiene que arreglar: columnas y tamaños fuera de rango,
     * un título con espacios de más, una etiqueta larguísima, un estilo y una alineación que no
     * existen, un campo repetido, una key inválida, un ítem de tipo desconocido, un salto de fila
     * y un texto libre sin id, y una caja que repite el id de otra.
     *
     * @return array
     */
    protected function diseno_crudo()
    {
        return [
            'superior' => [
                [
                    'tipo' => 'caja', 'id' => 'caja_cliente', 'cols' => 20, 'titulo' => '  Datos   del cliente  ', 'estilo' => 'raro',
                    'campos' => [
                        ['key' => 'cliente_nombre', 'tamano' => 40, 'negrita' => 1, 'alineacion' => 'arriba'],
                        ['key' => 'cliente_nombre'],
                        ['key' => 'Clave Invalida'],
                        ['key' => 'cliente_cuit', 'etiqueta' => str_repeat('x', 100), 'tamano' => 2],
                    ],
                ],
                ['tipo' => 'salto_de_fila'],
                ['tipo' => 'cualquiera', 'id' => 'otro'],
                [
                    'tipo' => 'caja', 'cols' => 0,
                    'campos' => [
                        ['key' => 'texto_libre', 'texto' => "Hola\r\nchau"],
                        ['key' => 'texto_libre', 'id' => 'texto_2', 'texto' => 'Otro texto'],
                    ],
                ],
            ],
            'pie' => [
                [
                    'tipo' => 'caja', 'id' => 'caja_cliente', 'cols' => 12,
                    'campos' => [['key' => 'tot_total']],
                ],
            ],
        ];
    }

    /**
     * El mismo arreglo con las claves de los objetos ordenadas (las listas quedan en su orden).
     * MySQL guarda las columnas JSON con las claves reordenadas, así que para comparar tipo por
     * tipo (assertSame) hay que llevar los dos lados a la misma forma.
     *
     * @param mixed $valor
     * @return mixed
     */
    protected function canonico($valor)
    {
        if (! is_array($valor)) {
            return $valor;
        }

        $es_lista = $valor === [] || array_keys($valor) === range(0, count($valor) - 1);

        $resultado = [];
        foreach ($valor as $clave => $item) {
            $resultado[$clave] = $this->canonico($item);
        }

        if (! $es_lista) {
            ksort($resultado);
        }

        return $resultado;
    }

    /**
     * Las keys de los bloques fijos de una zona.
     *
     * @param array $items
     * @return array<int, string>
     */
    protected function fijos_de($items)
    {
        $keys = [];
        foreach ($items as $item) {
            if ($item['tipo'] === 'fijo') {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * @test
     */
    public function el_put_guarda_el_diseno_normalizado()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $response = $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['page_layout' => $this->diseno_crudo()]);

        $response->assertStatus(200);

        $guardado = $perfil->fresh()->page_layout;

        $this->assertIsArray($guardado, 'El PUT tiene que guardar page_layout: si no está en el update(), responde 200 y la columna queda en null.');

        $esperado = DisenoDePaginaPdf::asegurar_fijos(DisenoDePaginaPdf::normalizar($this->diseno_crudo()), false);

        $this->assertSame(
            $this->canonico($esperado),
            $this->canonico($guardado),
            'Lo guardado tiene que ser exactamente el diseño normalizado (y sin bloques fijos: el perfil no es de ARCA).'
        );

        /** Lo que normalizar() tenía que arreglar, dicho explícitamente. */
        $caja = $guardado['superior'][0];
        $this->assertSame('caja_cliente', $caja['id']);
        $this->assertSame(12, $caja['cols'], 'Las columnas se acotan a 12.');
        $this->assertSame('Datos del cliente', $caja['titulo'], 'El título va en una línea, sin espacios de más.');
        $this->assertSame('borde', $caja['estilo'], 'Un estilo que no existe cae a "borde".');
        $this->assertCount(2, $caja['campos'], 'El campo repetido y la key inválida se descartan.');
        $this->assertSame('cliente_nombre', $caja['campos'][0]['key']);
        $this->assertSame(24, $caja['campos'][0]['tamano'], 'El tamaño se acota a 24 pt.');
        $this->assertTrue($caja['campos'][0]['negrita']);
        $this->assertNull($caja['campos'][0]['alineacion'], 'Una alineación que no existe queda en null (la del catálogo).');
        $this->assertSame(6, $caja['campos'][1]['tamano'], 'El tamaño se acota a 6 pt.');
        $this->assertSame(str_repeat('x', 60), $caja['campos'][1]['etiqueta'], 'La etiqueta se corta en 60 caracteres.');

        $this->assertCount(3, $guardado['superior'], 'El ítem de tipo desconocido se descarta.');
        $this->assertSame('salto_de_fila', $guardado['superior'][1]['tipo']);
        $this->assertMatchesRegularExpression(DisenoDePaginaPdf::PATRON_ID, $guardado['superior'][1]['id'], 'El salto de fila sin id recibe uno.');

        $caja_de_textos = $guardado['superior'][2];
        $this->assertSame(1, $caja_de_textos['cols'], 'Cero columnas se acota a 1.');
        $this->assertSame("Hola\nchau", $caja_de_textos['campos'][0]['texto']);
        $this->assertNotSame('texto_2', $caja_de_textos['campos'][0]['id'], 'El texto sin id no puede quedarse con el id explícito de otro.');
        $this->assertSame('texto_2', $caja_de_textos['campos'][1]['id']);

        $this->assertNotSame('caja_cliente', $guardado['pie'][0]['id'], 'Un id repetido se reemplaza por uno nuevo.');
        $this->assertMatchesRegularExpression(DisenoDePaginaPdf::PATRON_ID, $guardado['pie'][0]['id']);

        /** La respuesta trae lo guardado: el SPA lo vuelve a poner en el formulario. */
        $this->assertSame($this->canonico($guardado), $this->canonico($response->json('model.page_layout')));
    }

    /**
     * "Sin rótulo" (`etiqueta: ""`) y "la etiqueta del catálogo" (`etiqueta: null`) son dos cosas
     * distintas, y el middleware global ConvertEmptyStringsToNull convierte todo "" anidado en null.
     * Si el controller leyera el diseño de $request->input(), el recuadro de observaciones del
     * derivado (sin rótulo, debajo del título "OBSERVACIONES") se guardaría con el rótulo del
     * catálogo. Lo mismo para los espacios del principio de un texto libre (TrimStrings).
     *
     * @test
     */
    public function la_etiqueta_vacia_llega_vacia_y_no_como_null()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $diseno = [
            'superior' => [],
            'pie' => [
                [
                    'tipo' => 'caja', 'id' => 'caja_observaciones', 'cols' => 12, 'titulo' => 'OBSERVACIONES', 'estilo' => 'gris',
                    'campos' => [
                        ['key' => 'venta_observaciones', 'etiqueta' => '', 'tamano' => null, 'negrita' => null, 'cursiva' => null, 'alineacion' => null],
                        ['key' => 'texto_libre', 'id' => 'texto_1', 'texto' => '   Sangría a propósito', 'etiqueta' => null, 'tamano' => null, 'negrita' => null, 'cursiva' => null, 'alineacion' => null],
                    ],
                ],
            ],
        ];

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['page_layout' => $diseno])->assertStatus(200);

        $campos = $perfil->fresh()->page_layout['pie'][0]['campos'];

        $this->assertSame('', $campos[0]['etiqueta'], 'La etiqueta vacía ("sin rótulo") tiene que llegar vacía, no null.');
        $this->assertSame('   Sangría a propósito', $campos[1]['texto'], 'El texto libre conserva los espacios del principio.');

        /** El alta, por el mismo camino. */
        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta(['page_layout' => $diseno]));
        $response->assertStatus(201);

        $creado = PdfColumnProfile::find($response->json('model.id'));
        $this->assertSame('', $creado->page_layout['pie'][0]['campos'][0]['etiqueta']);
    }

    /**
     * @test
     */
    public function el_post_guarda_el_diseno_y_el_alto_de_hoja()
    {
        $this->autenticar();

        /** El diseño puede llegar también como string JSON (el contrato lo admite). */
        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'page_layout'     => json_encode($this->diseno_valido()),
            'paper_height_mm' => 210,
        ]));

        $response->assertStatus(201);

        $perfil = PdfColumnProfile::find($response->json('model.id'));

        $this->assertNotNull($perfil, 'El alta no devolvió el diseño creado.');
        $this->assertSame(
            $this->canonico($this->diseno_valido()),
            $this->canonico($perfil->page_layout),
            'El alta tiene que guardar page_layout (si no está en el create([...]) se pierde en silencio).'
        );
        $this->assertSame(210, $perfil->paper_height_mm, 'El alta tiene que guardar paper_height_mm.');
        $this->assertSame(210, $response->json('model.paper_height_mm'), 'La respuesta trae el alto guardado.');
        $this->assertSame(
            $this->canonico($this->diseno_valido()),
            $this->canonico($response->json('model.page_layout'))
        );
    }

    /**
     * Un diseño creado sin pasar por el diseñador es "el de siempre": page_layout y alto en null.
     *
     * @test
     */
    public function el_post_sin_diseno_deja_el_pdf_de_siempre()
    {
        $this->autenticar();

        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta());

        $response->assertStatus(201);

        $perfil = PdfColumnProfile::find($response->json('model.id'));

        $this->assertNull($perfil->page_layout, 'Sin diseño, el perfil imprime el PDF de siempre (null).');
        $this->assertNull($perfil->paper_height_mm);
        $this->assertFalse(DisenoDePaginaPdf::tiene_diseno($perfil));
    }

    /**
     * @test
     */
    public function el_put_guarda_el_alto_de_hoja_y_null_lo_borra()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['paper_height_mm' => 356])->assertStatus(200);
        $this->assertSame(356, $perfil->fresh()->paper_height_mm, 'El update tiene que persistir paper_height_mm.');

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['paper_height_mm' => null])->assertStatus(200);
        $this->assertNull($perfil->fresh()->paper_height_mm, 'null vuelve al alto por defecto (A4).');
    }

    /**
     * "Volver al diseño de siempre" del diseñador manda page_layout null.
     *
     * @test
     */
    public function page_layout_null_vuelve_al_diseno_de_siempre()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['page_layout' => $this->diseno_valido()]);

        $this->assertTrue(DisenoDePaginaPdf::tiene_diseno($perfil->fresh()));

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['page_layout' => null])->assertStatus(200);

        $this->assertNull($perfil->fresh()->page_layout, 'page_layout null tiene que borrar el diseño.');
        $this->assertFalse(DisenoDePaginaPdf::tiene_diseno($perfil->fresh()));
    }

    /**
     * `page_layout` solo se toca si el PUT lo menciona: renombrar el diseño, cambiar un flag o las
     * columnas no lo puede borrar ni convertir.
     *
     * @test
     */
    public function un_put_sin_la_clave_no_toca_el_diseno_ni_el_alto()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, [
            'page_layout'     => $this->diseno_valido(),
            'paper_height_mm' => 279,
        ]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
            'name'                 => 'zz Remito renombrado',
            'show_total_in_footer' => false,
        ])->assertStatus(200);

        $recargado = $perfil->fresh();

        $this->assertSame('zz Remito renombrado', $recargado->name);
        $this->assertSame(
            $this->canonico($this->diseno_valido()),
            $this->canonico($recargado->page_layout),
            'Un PUT que no menciona page_layout no lo puede tocar.'
        );
        $this->assertSame(279, $recargado->paper_height_mm, 'Un PUT que no menciona paper_height_mm no lo puede tocar.');
    }

    /**
     * Un diseño sin forma da 422 con el mensaje del normalizador, y NO escribe nada: ni el nombre
     * que venía en el mismo PUT, ni el diseño que ya estaba, ni el "por defecto" del hermano (lo
     * primero que escribe update() es apagar los defaults de los demás).
     *
     * @test
     */
    public function un_diseno_sin_forma_da_422_y_no_escribe_nada()
    {
        $owner = $this->autenticar();
        $hermano = $this->crear_perfil($owner->id, ['name' => 'zz Hermano por defecto', 'is_default' => true]);
        $perfil = $this->crear_perfil($owner->id, ['page_layout' => $this->diseno_valido()]);

        $rotos = [
            'JSON roto'           => '{"superior": [}',
            'sin superior ni pie' => ['otra_cosa' => 1],
            'un número'           => 5,
        ];

        foreach ($rotos as $caso => $roto) {
            $response = $this->putJson('api/pdf-column-profiles/'.$perfil->id, [
                'name'        => 'zz No se tiene que guardar',
                'is_default'  => true,
                'page_layout' => $roto,
            ]);

            $response->assertStatus(422);
            $this->assertSame(
                'El diseño de la hoja no tiene un formato válido.',
                $response->json('message'),
                'Caso "'.$caso.'": el 422 lleva el mensaje del normalizador.'
            );

            $recargado = $perfil->fresh();
            $this->assertSame('zz Remito de test diseño de página', $recargado->name, 'Caso "'.$caso.'": el 422 no puede guardar el resto del PUT.');
            $this->assertSame($this->canonico($this->diseno_valido()), $this->canonico($recargado->page_layout), 'Caso "'.$caso.'": el diseño que estaba sigue igual.');
            $this->assertFalse((bool) $recargado->is_default);
            $this->assertTrue((bool) $hermano->fresh()->is_default, 'Caso "'.$caso.'": el 422 no puede haberle sacado el "por defecto" al hermano.');
        }
    }

    /**
     * Lo mismo en el alta: 422 y no se crea nada.
     *
     * @test
     */
    public function el_post_con_un_diseno_sin_forma_da_422_y_no_crea_nada()
    {
        $owner = $this->autenticar();
        $hermano = $this->crear_perfil($owner->id, ['name' => 'zz Hermano por defecto', 'is_default' => true]);

        $antes = PdfColumnProfile::where('user_id', $owner->id)->count();

        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'is_default'  => true,
            'page_layout' => 'esto no es JSON',
        ]));

        $response->assertStatus(422);
        $this->assertSame('El diseño de la hoja no tiene un formato válido.', $response->json('message'));
        $this->assertSame($antes, PdfColumnProfile::where('user_id', $owner->id)->count(), 'El 422 no puede crear el diseño.');
        $this->assertTrue((bool) $hermano->fresh()->is_default, 'El 422 no puede haberle sacado el "por defecto" al hermano.');
    }

    /**
     * Un perfil de ARCA guarda los dos bloques fijos aunque el diseño no los traiga: el del
     * receptor al principio de "superior" y el de importes/QR/CAE al final del "pie".
     *
     * @test
     */
    public function un_perfil_de_arca_guarda_los_dos_bloques_fijos_aunque_no_vengan()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['is_afip_ticket' => true]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['page_layout' => $this->diseno_valido()])
            ->assertStatus(200);

        $guardado = $perfil->fresh()->page_layout;

        $this->assertSame(
            $this->canonico(['tipo' => 'fijo', 'key' => 'afip_receptor']),
            $this->canonico($guardado['superior'][0]),
            'El receptor de ARCA va primero en "superior".'
        );
        $this->assertSame('caja_cliente', $guardado['superior'][1]['id'], 'La caja que vino queda después del bloque fijo.');

        $ultimo = $guardado['pie'][count($guardado['pie']) - 1];
        $this->assertSame('fijo', $ultimo['tipo']);
        $this->assertSame('afip_pie', $ultimo['key'], 'Importes, QR y CAE van al final del "pie".');
        $this->assertTrue($ultimo['importes'], 'Si no vino, el cuadro de importes arranca prendido.');

        /** Si los manda (movidos y con los importes apagados), se respetan y no se duplican. */
        $con_fijos = $this->diseno_valido();
        $con_fijos['superior'][] = ['tipo' => 'fijo', 'key' => 'afip_receptor'];
        array_unshift($con_fijos['pie'], ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => false]);

        $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['page_layout' => $con_fijos])->assertStatus(200);

        $guardado = $perfil->fresh()->page_layout;

        $this->assertSame(['afip_receptor'], $this->fijos_de($guardado['superior']));
        $this->assertSame('afip_receptor', $guardado['superior'][1]['key'], 'El bloque se movió y queda donde lo puso el dueño.');
        $this->assertSame(['afip_pie'], $this->fijos_de($guardado['pie']));
        $this->assertSame(
            $this->canonico(['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => false]),
            $this->canonico($guardado['pie'][0]),
            'El pie de ARCA que mandó primero, con los importes apagados, queda así.'
        );
    }

    /**
     * El "Es factura de ARCA" que viaja en el MISMO PUT manda sobre el guardado: el formulario puede
     * tildarlo y guardar junto con el diseño.
     *
     * @test
     */
    public function el_is_afip_ticket_del_mismo_put_manda_sobre_el_guardado()
    {
        $owner = $this->autenticar();

        $remito = $this->crear_perfil($owner->id, ['is_afip_ticket' => false]);
        $this->putJson('api/pdf-column-profiles/'.$remito->id, [
            'is_afip_ticket' => true,
            'page_layout'    => $this->diseno_valido(),
        ])->assertStatus(200);

        $guardado = $remito->fresh()->page_layout;
        $this->assertSame(['afip_receptor'], $this->fijos_de($guardado['superior']), 'Pasó a ser de ARCA en el mismo PUT: lleva los fijos.');
        $this->assertSame(['afip_pie'], $this->fijos_de($guardado['pie']));

        $factura = $this->crear_perfil($owner->id, ['name' => 'zz Factura de test', 'is_afip_ticket' => true]);
        $this->putJson('api/pdf-column-profiles/'.$factura->id, [
            'is_afip_ticket' => false,
            'page_layout'    => $guardado,
        ])->assertStatus(200);

        $guardado = $factura->fresh()->page_layout;
        $this->assertSame([], $this->fijos_de($guardado['superior']), 'Dejó de ser de ARCA en el mismo PUT: los fijos se sacan.');
        $this->assertSame([], $this->fijos_de($guardado['pie']));
    }

    /**
     * Un perfil que no es factura de ARCA no guarda bloques fijos aunque vengan. Un presupuesto
     * tampoco, aunque tenga la columna is_afip_ticket prendida: solo una venta es fiscal.
     *
     * @test
     */
    public function un_perfil_no_fiscal_guarda_sin_bloques_fijos()
    {
        $owner = $this->autenticar();

        $con_fijos = $this->diseno_valido();
        array_unshift($con_fijos['superior'], ['tipo' => 'fijo', 'key' => 'afip_receptor']);
        $con_fijos['pie'][] = ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => true];

        $remito = $this->crear_perfil($owner->id);
        $this->putJson('api/pdf-column-profiles/'.$remito->id, ['page_layout' => $con_fijos])->assertStatus(200);

        $guardado = $remito->fresh()->page_layout;
        $this->assertSame([], $this->fijos_de($guardado['superior']), 'Un remito no guarda el receptor de ARCA.');
        $this->assertSame([], $this->fijos_de($guardado['pie']), 'Un remito no guarda el pie de ARCA.');
        $this->assertSame('caja_cliente', $guardado['superior'][0]['id'], 'Las cajas quedan.');

        $presupuesto = $this->crear_perfil($owner->id, ['model_name' => 'budget', 'name' => 'zz Presupuesto de test', 'is_afip_ticket' => true]);
        $this->putJson('api/pdf-column-profiles/'.$presupuesto->id, ['page_layout' => $con_fijos])->assertStatus(200);

        $guardado = $presupuesto->fresh()->page_layout;
        $this->assertSame([], $this->fijos_de($guardado['superior']), 'Un presupuesto nunca es fiscal.');
        $this->assertSame([], $this->fijos_de($guardado['pie']));
    }

    /**
     * El catálogo de artículos no se diseña con cajas: guarda null siempre, por PUT y por POST, y
     * aunque el diseño venga roto (su PDF no lee esta columna).
     *
     * @test
     */
    public function un_perfil_de_articulos_guarda_null_siempre()
    {
        $owner = $this->autenticar();
        $catalogo = $this->crear_perfil($owner->id, ['model_name' => 'article', 'name' => 'zz Catálogo de test']);

        $this->putJson('api/pdf-column-profiles/'.$catalogo->id, ['page_layout' => $this->diseno_valido()])->assertStatus(200);
        $this->assertNull($catalogo->fresh()->page_layout, 'Un perfil de artículos no guarda diseño de cajas.');

        $this->putJson('api/pdf-column-profiles/'.$catalogo->id, ['page_layout' => '{roto'])->assertStatus(200);
        $this->assertNull($catalogo->fresh()->page_layout);

        $response = $this->postJson('api/pdf-column-profiles', $this->payload_de_alta([
            'model_name'  => 'article',
            'name'        => 'zz Catálogo de test por POST',
            'page_layout' => $this->diseno_valido(),
        ]));

        $response->assertStatus(201);
        $this->assertNull(PdfColumnProfile::find($response->json('model.id'))->page_layout);
    }

    /**
     * @test
     */
    public function el_alto_de_hoja_fuera_de_rango_da_422()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, ['paper_height_mm' => 297]);

        foreach ([DisenoDePaginaPdf::ALTO_DE_HOJA_MIN - 1, DisenoDePaginaPdf::ALTO_DE_HOJA_MAX + 1, 'alto'] as $alto) {
            $response = $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['paper_height_mm' => $alto]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('paper_height_mm');
            $this->assertStringContainsString(
                'alto de hoja (mm)',
                $response->json('errors.paper_height_mm.0'),
                'El mensaje nombra el campo en castellano (validation_attribute_labels()).'
            );
            $this->assertSame(297, $perfil->fresh()->paper_height_mm);
        }

        foreach ([DisenoDePaginaPdf::ALTO_DE_HOJA_MIN, DisenoDePaginaPdf::ALTO_DE_HOJA_MAX] as $alto) {
            $this->putJson('api/pdf-column-profiles/'.$perfil->id, ['paper_height_mm' => $alto])->assertStatus(200);
            $this->assertSame($alto, $perfil->fresh()->paper_height_mm, 'Los topes son inclusivos.');
        }

        $this->postJson('api/pdf-column-profiles', $this->payload_de_alta(['paper_height_mm' => 50]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('paper_height_mm');
    }

    /**
     * Duplicar un diseño copia su hoja: `duplicate()` es un replicate() de todas las columnas.
     *
     * @test
     */
    public function duplicar_copia_el_diseno_y_el_alto_de_hoja()
    {
        $owner = $this->autenticar();
        $perfil = $this->crear_perfil($owner->id, [
            'page_layout'     => $this->diseno_valido(),
            'paper_height_mm' => 210,
        ]);

        $response = $this->postJson('api/pdf-column-profiles/'.$perfil->id.'/duplicate');

        $response->assertStatus(201);

        $copia = PdfColumnProfile::find($response->json('model.id'));

        $this->assertNotSame($perfil->id, $copia->id);
        $this->assertSame($this->canonico($this->diseno_valido()), $this->canonico($copia->page_layout), 'La copia tiene que llevar el diseño de la hoja.');
        $this->assertSame(210, $copia->paper_height_mm, 'La copia tiene que llevar el alto de la hoja.');
    }

    /**
     * El diseño de otro dueño no se puede pisar: 404 y queda como estaba.
     *
     * @test
     */
    public function un_perfil_de_otro_dueno_da_404_y_no_se_toca()
    {
        $this->autenticar();

        $otro_dueno = User::create([
            'name'         => 'Otro dueno diseño de página',
            'company_name' => 'Otro dueno diseño de página',
            'email'        => 'pdf-otro-dueno-'.uniqid().'@test.local',
            'password'     => 'x',
        ]);
        $ajeno = $this->crear_perfil($otro_dueno->id, ['paper_height_mm' => 297]);

        $this->putJson('api/pdf-column-profiles/'.$ajeno->id, [
            'page_layout'     => $this->diseno_valido(),
            'paper_height_mm' => 210,
        ])->assertStatus(404);

        $recargado = $ajeno->fresh();
        $this->assertNull($recargado->page_layout, 'Un 404 no puede haber guardado el diseño en el perfil ajeno.');
        $this->assertSame(297, $recargado->paper_height_mm);

        $this->postJson('api/pdf-column-profiles/'.$ajeno->id.'/duplicate')->assertStatus(404);
    }
}
