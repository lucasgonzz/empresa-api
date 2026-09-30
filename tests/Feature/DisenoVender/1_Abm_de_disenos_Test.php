<?php

namespace Tests\Feature\DisenoVender;

use App\Http\Controllers\Helpers\VenderLayoutHelper;
use App\Http\Controllers\VenderLayoutController;
use App\Models\User;
use App\Models\VenderLayout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ABM de los "Diseños de Vender" (misión diseno-vender-configurable, 28/9/2026):
 * `api/vender-layout` y la normalización del JSON del diseño.
 *
 * Lo que estos tests cuidan, y que se rompería EN SILENCIO:
 *
 *   - El invariante "exactamente un diseño en uso por dueño". No hay unique en la base que lo
 *     sostenga (a propósito, ver la migración): si el controller deja dos en uso, o ninguno, cada
 *     sesión de Vender puede terminar vendiendo con un diseño distinto sin un solo error a la vista.
 *   - Que el index le cree el "Diseño predeterminado" al dueño que no tiene ninguno, UNA vez.
 *   - Que la normalización no deje entrar basura al JSON que después leen ~40 clientes.
 *   - Que un negocio no pueda ver, editar ni borrar los diseños de otro (bases compartidas).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes y
 * un refresh la vaciaría. Todo lo que se crea acá (diseños, otro dueño, un empleado) se revierte.
 *
 * @group disenos_de_vender
 */
class Abm_de_disenos_Test extends TestCase
{
    use DatabaseTransactions;

    /*
     * ---------------------------------------------------------------------------------------
     *  Andamio
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El dueño de los tests de esta rama (mismo patrón que Preferencias), autenticado.
     *
     * @return \App\Models\User
     */
    protected function dueno()
    {
        $dueno = User::find(500);

        if (is_null($dueno)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($dueno, 'web');

        return $dueno;
    }

    /**
     * El dueño, autenticado y SIN ningún diseño (el borrado se revierte con la transacción).
     *
     * @return \App\Models\User
     */
    protected function dueno_sin_disenos()
    {
        $dueno = $this->dueno();

        VenderLayout::where('user_id', $dueno->id)->delete();

        return $dueno;
    }

    /**
     * Otro negocio, para los tests de aislamiento. Se crea acá (y se revierte) en vez de depender
     * de que la base de testing tenga un segundo dueño.
     *
     * @return \App\Models\User
     */
    protected function otro_dueno()
    {
        return User::create([
            'name'         => 'zz Otro dueño (diseños de Vender)',
            'company_name' => 'zz Otro comercio',
            'email'        => 'zz_otro_dueno_disenos_de_vender@prueba.local',
            'password'     => bcrypt('zz-password-testing'),
            'status'       => 'commerce',
        ]);
    }

    /**
     * Un empleado del dueño 500.
     *
     * @return \App\Models\User
     */
    protected function empleado_del_dueno()
    {
        return User::create([
            'owner_id' => 500,
            'name'     => 'zz Empleado (diseños de Vender)',
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);
    }

    /**
     * Crea un diseño directo en la base, sin pasar por el endpoint.
     *
     * @param  int         $user_id
     * @param  string      $name
     * @param  bool        $en_uso
     * @param  array|null  $layout
     * @return \App\Models\VenderLayout
     */
    protected function crear_diseno($user_id, $name, $en_uso = false, $layout = null)
    {
        return VenderLayout::create([
            'user_id' => $user_id,
            'name'    => $name,
            'layout'  => $layout,
            'en_uso'  => $en_uso,
        ]);
    }

    /**
     * Un diseño ya normalizado: es el ejemplo del §3 del plan. Pasado por la normalización tiene que
     * quedar idéntico.
     *
     * @return array
     */
    protected function layout_de_ejemplo()
    {
        return [
            'version' => 1,
            'etapas'  => [
                'etapa_1' => [
                    ['key' => 'metodo_de_pago', 'cols' => 4],
                    ['key' => 'separador', 'id' => 'separador_1', 'cols' => 12],
                ],
                'etapa_2' => [
                    ['key' => 'resumen', 'cols' => 12],
                    ['key' => 'codigo_de_barras', 'cols' => 3],
                ],
                'etapa_3' => [
                    ['key' => 'descuentos', 'cols' => 6],
                ],
            ],
            'sacados' => ['combos'],
        ];
    }

    /**
     * Los ids de los diseños en uso de un dueño, leídos de la base.
     *
     * @param  int  $owner_id
     * @return array
     */
    protected function ids_en_uso($owner_id)
    {
        return VenderLayout::where('user_id', $owner_id)
                            ->where('en_uso', 1)
                            ->orderBy('id')
                            ->pluck('id')
                            ->all();
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Index y show
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El index trae solo los diseños del dueño autenticado, ordenados por id, con la forma del
     * contrato: `layout` como objeto (o null) y `en_uso` como booleano.
     *
     * @test
     */
    public function el_index_devuelve_solo_los_disenos_del_dueno()
    {
        $dueno = $this->dueno_sin_disenos();

        $a = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());
        $b = $this->crear_diseno($dueno->id, 'Depósito');

        $otro = $this->otro_dueno();
        $ajeno = $this->crear_diseno($otro->id, 'Diseño del otro negocio', true);

        $respuesta = $this->getJson('api/vender-layout');

        $respuesta->assertStatus(200);

        $models = $respuesta->json('models');

        $this->assertSame([$a->id, $b->id], array_column($models, 'id'));
        $this->assertNotContains($ajeno->id, array_column($models, 'id'));

        foreach ($models as $model) {
            $this->assertSame($dueno->id, $model['user_id']);
            foreach (['id', 'user_id', 'name', 'layout', 'en_uso', 'created_at', 'updated_at'] as $clave) {
                $this->assertArrayHasKey($clave, $model, 'Falta la clave '.$clave.' en el modelo serializado.');
            }
        }

        $this->assertSame(true, $models[0]['en_uso']);
        $this->assertSame(false, $models[1]['en_uso']);
        $this->assertSame($this->layout_de_ejemplo(), $models[0]['layout']);
        $this->assertNull($models[1]['layout']);
    }

    /**
     * Un dueño sin ningún diseño recibe el "Diseño predeterminado" (layout null, en uso) al pedir
     * el index. Pedirlo dos veces no lo duplica.
     *
     * @test
     */
    public function el_index_crea_el_predeterminado_una_sola_vez()
    {
        $dueno = $this->dueno_sin_disenos();

        $primera = $this->getJson('api/vender-layout');
        $primera->assertStatus(200);

        $segunda = $this->getJson('api/vender-layout');
        $segunda->assertStatus(200);

        $disenos = VenderLayout::where('user_id', $dueno->id)->get();

        $this->assertCount(1, $disenos, 'El index creó más de un diseño predeterminado.');

        $predeterminado = $disenos->first();

        $this->assertSame(VenderLayoutHelper::NOMBRE_PREDETERMINADO, $predeterminado->name);
        $this->assertSame('Diseño predeterminado', $predeterminado->name);
        $this->assertNull($predeterminado->layout);
        $this->assertTrue($predeterminado->en_uso);

        $this->assertCount(1, $primera->json('models'));
        $this->assertSame($predeterminado->id, $primera->json('models.0.id'));
        $this->assertNull($primera->json('models.0.layout'));
        $this->assertSame(true, $primera->json('models.0.en_uso'));
        $this->assertSame($primera->json('models'), $segunda->json('models'));
    }

    /**
     * El index no crea nada si el dueño ya tiene diseños, aunque ninguno sea el predeterminado.
     *
     * @test
     */
    public function el_index_no_crea_el_predeterminado_si_el_dueno_ya_tiene_disenos()
    {
        $dueno = $this->dueno_sin_disenos();

        $propio = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());

        $this->getJson('api/vender-layout')->assertStatus(200);

        $this->assertSame(
            [$propio->id],
            VenderLayout::where('user_id', $dueno->id)->pluck('id')->all()
        );
    }

    /**
     * El index entra por `recursos-iniciales` (el masivo del inicio de sesión) y ahí también crea el
     * predeterminado: es el camino por el que el SPA lo pide de verdad.
     *
     * @test
     */
    public function el_index_esta_en_los_recursos_iniciales_y_crea_el_predeterminado()
    {
        $dueno = $this->dueno_sin_disenos();

        $respuesta = $this->postJson('/api/recursos-iniciales', ['models' => ['vender_layout']]);

        $respuesta->assertStatus(200);

        $this->assertNotContains('vender_layout', $respuesta->json('no_soportados'));
        $this->assertNotContains('vender_layout', $respuesta->json('con_error'));

        $models = $respuesta->json('models.vender_layout.models');

        $this->assertCount(1, $models);
        $this->assertSame(VenderLayoutHelper::NOMBRE_PREDETERMINADO, $models[0]['name']);
        $this->assertSame($dueno->id, $models[0]['user_id']);
        $this->assertSame(1, VenderLayout::where('user_id', $dueno->id)->count());
    }

    /**
     * El show devuelve un diseño del dueño con su layout como objeto.
     *
     * @test
     */
    public function el_show_devuelve_el_diseno_del_dueno()
    {
        $dueno = $this->dueno_sin_disenos();

        $diseno = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());

        $respuesta = $this->getJson('api/vender-layout/'.$diseno->id);

        $respuesta->assertStatus(200);
        $this->assertSame($diseno->id, $respuesta->json('model.id'));
        $this->assertSame('Mostrador', $respuesta->json('model.name'));
        $this->assertSame($this->layout_de_ejemplo(), $respuesta->json('model.layout'));
        $this->assertSame(true, $respuesta->json('model.en_uso'));
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Alta y normalización
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El store normaliza el layout antes de guardarlo: cols fuera de rango o que no son números,
     * keys con otro formato, ítems que no son objetos, etapas de más, propiedades de más, ids
     * inválidos, sacados repetidos o inválidos y la versión.
     *
     * @test
     */
    public function el_store_normaliza_el_layout()
    {
        $this->dueno_sin_disenos();

        $key_de_60 = str_repeat('a', 60);
        $key_de_61 = str_repeat('b', 61);

        $sucio = [
            'version' => 7,
            'etapas'  => [
                'etapa_1' => [
                    ['key' => 'caja', 'cols' => 0],
                    ['key' => 'sucursal', 'cols' => 99],
                    ['key' => 'moneda', 'cols' => 'abc'],
                    ['key' => 'vendedor', 'cols' => '7'],
                    ['key' => 'cliente', 'cols' => 4.9],
                    ['key' => 'fecha_de_venta'],
                    ['key' => $key_de_60, 'cols' => 2],
                    ['key' => $key_de_61, 'cols' => 2],
                    ['key' => 'Caja', 'cols' => 4],
                    ['key' => 'caja-2', 'cols' => 4],
                    ['key' => 5, 'cols' => 4],
                    ['cols' => 4],
                    'esto no es un ítem',
                    ['key' => 'separador', 'id' => 'separador_1', 'cols' => 12, 'extra' => 'se descarta'],
                    ['key' => 'separador', 'id' => 'Separador Con Espacios', 'cols' => 12],
                ],
                'etapa_2' => [
                    ['key' => 'resumen', 'cols' => 12],
                ],
                'etapa_4' => [
                    ['key' => 'combos', 'cols' => 4],
                ],
                'otra_cosa' => 'x',
            ],
            'sacados' => ['combos', 'combos', 'Promociones', 3, 'promociones'],
            'otra_clave' => 'se descarta',
        ];

        $esperado = [
            'version' => 1,
            'etapas'  => [
                'etapa_1' => [
                    ['key' => 'caja', 'cols' => 1],
                    ['key' => 'sucursal', 'cols' => 12],
                    ['key' => 'moneda', 'cols' => 12],
                    ['key' => 'vendedor', 'cols' => 7],
                    ['key' => 'cliente', 'cols' => 4],
                    ['key' => 'fecha_de_venta', 'cols' => 12],
                    ['key' => $key_de_60, 'cols' => 2],
                    ['key' => 'separador', 'id' => 'separador_1', 'cols' => 12],
                    ['key' => 'separador', 'cols' => 12],
                ],
                'etapa_2' => [
                    ['key' => 'resumen', 'cols' => 12],
                ],
                'etapa_3' => [],
            ],
            'sacados' => ['combos', 'promociones'],
        ];

        $respuesta = $this->postJson('api/vender-layout', [
            'name'   => 'Mostrador',
            'layout' => $sucio,
            'en_uso' => false,
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame($esperado, $respuesta->json('model.layout'));
        $this->assertSame($esperado, VenderLayout::find($respuesta->json('model.id'))->layout);
    }

    /**
     * El layout también puede llegar como string JSON, y un layout ya normalizado queda idéntico.
     *
     * @test
     */
    public function el_store_acepta_el_layout_como_string_json()
    {
        $this->dueno_sin_disenos();

        $respuesta = $this->postJson('api/vender-layout', [
            'name'   => 'Mostrador',
            'layout' => json_encode($this->layout_de_ejemplo()),
        ]);

        $respuesta->assertStatus(201);

        $this->assertSame($this->layout_de_ejemplo(), $respuesta->json('model.layout'));
    }

    /**
     * Sin `layout` (o con null) el diseño nace con el predeterminado del sistema.
     *
     * @test
     */
    public function el_store_sin_layout_guarda_el_predeterminado_del_sistema()
    {
        $this->dueno_sin_disenos();

        $sin_clave = $this->postJson('api/vender-layout', ['name' => 'Sin layout']);
        $sin_clave->assertStatus(201);
        $this->assertNull($sin_clave->json('model.layout'));

        $con_null = $this->postJson('api/vender-layout', ['name' => 'Layout null', 'layout' => null]);
        $con_null->assertStatus(201);
        $this->assertNull(VenderLayout::find($con_null->json('model.id'))->layout);
    }

    /**
     * Los topes: 100 ítems por etapa y 100 sacados. Se conservan los primeros.
     *
     * @test
     */
    public function el_store_respeta_los_topes_de_items_y_de_sacados()
    {
        $this->dueno_sin_disenos();

        $items = [];
        $sacados = [];

        for ($i = 0; $i < 150; $i++) {
            $items[] = ['key' => 'campo_'.$i, 'cols' => 3];
            $sacados[] = 'sacado_'.$i;
        }

        $respuesta = $this->postJson('api/vender-layout', [
            'name'   => 'Gigante',
            'layout' => ['etapas' => ['etapa_2' => $items], 'sacados' => $sacados],
        ]);

        $respuesta->assertStatus(201);

        $layout = $respuesta->json('model.layout');

        $this->assertCount(VenderLayoutHelper::MAXIMO_DE_ITEMS_POR_ETAPA, $layout['etapas']['etapa_2']);
        $this->assertSame('campo_0', $layout['etapas']['etapa_2'][0]['key']);
        $this->assertSame('campo_99', $layout['etapas']['etapa_2'][99]['key']);

        $this->assertCount(VenderLayoutHelper::MAXIMO_DE_SACADOS, $layout['sacados']);
        $this->assertSame('sacado_99', $layout['sacados'][99]);
    }

    /**
     * Lo que el request no puede mostrar porque el middleware `TrimStrings` lo limpia antes: una key
     * con un salto de línea al final no pasa el patrón (el `$` de PHP matchearía antes del "\n" sin
     * el modificador D). Y una etapa que viene como objeto queda vacía, igual que en el SPA.
     *
     * @test
     */
    public function la_normalizacion_no_deja_pasar_un_salto_de_linea_ni_una_etapa_objeto()
    {
        list($ok, $layout) = VenderLayoutHelper::normalizar_layout([
            'etapas' => [
                'etapa_1' => [
                    ['key' => "caja\n", 'cols' => 4],
                    ['key' => 'separador', 'id' => "separador_1\n", 'cols' => 12],
                ],
                'etapa_3' => ['primero' => ['key' => 'descuentos', 'cols' => 6]],
            ],
            'sacados' => ["combos\n", 'combos'],
        ]);

        $this->assertTrue($ok);
        $this->assertSame([['key' => 'separador', 'cols' => 12]], $layout['etapas']['etapa_1']);
        $this->assertSame([], $layout['etapas']['etapa_3']);
        $this->assertSame(['combos'], $layout['sacados']);

        // El literal JSON `null` vale lo mismo que null: el diseño del sistema.
        $this->assertSame([true, null], VenderLayoutHelper::normalizar_layout('null'));
        $this->assertSame([true, null], VenderLayoutHelper::normalizar_layout(null));
    }

    /**
     * Un layout sin la forma mínima (objeto con `etapas` objeto) da 422 con el mensaje del contrato,
     * y no se guarda nada.
     *
     * @test
     */
    public function un_layout_invalido_da_422()
    {
        $dueno = $this->dueno_sin_disenos();

        $invalidos = [
            'no es JSON'             => 'esto no es un json',
            'JSON que no es objeto'  => '5',
            'un número'              => 5,
            'objeto sin etapas'      => ['foo' => 1],
            'etapas como lista'      => ['etapas' => [[['key' => 'caja', 'cols' => 4]]]],
            'etapas como texto'      => ['etapas' => 'etapa_1'],
            'una lista'              => [['key' => 'caja']],
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
        $this->assertSame(0, VenderLayout::where('user_id', $dueno->id)->count());

        // En el update tampoco entra, y el diseño queda como estaba.
        $diseno = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());

        $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => ['foo' => 1]])->assertStatus(422);

        $this->assertSame($this->layout_de_ejemplo(), $diseno->fresh()->layout);
    }

    /**
     * El nombre es obligatorio (después del trim) y tiene hasta 120 caracteres.
     *
     * @test
     */
    public function un_nombre_vacio_o_demasiado_largo_da_422()
    {
        $dueno = $this->dueno_sin_disenos();

        $sin_nombre = [
            'sin la clave'   => [],
            'vacío'          => ['name' => ''],
            'solo espacios'  => ['name' => '   '],
            'null'           => ['name' => null],
            'un array'       => ['name' => ['Mostrador']],
        ];

        foreach ($sin_nombre as $caso => $body) {
            $respuesta = $this->postJson('api/vender-layout', $body);

            $respuesta->assertStatus(422);
            $this->assertSame(VenderLayoutController::MENSAJE_SIN_NOMBRE, $respuesta->json('message'), 'Caso: '.$caso);
        }

        $largo = $this->postJson('api/vender-layout', ['name' => str_repeat('a', 121)]);
        $largo->assertStatus(422);
        $this->assertSame(VenderLayoutController::MENSAJE_NOMBRE_LARGO, $largo->json('message'));

        $this->assertSame(0, VenderLayout::where('user_id', $dueno->id)->count());

        // 120 caracteres con tildes entran: el largo se cuenta en caracteres, no en bytes.
        $justo = $this->postJson('api/vender-layout', ['name' => str_repeat('ñ', 120)]);
        $justo->assertStatus(201);
        $this->assertSame(str_repeat('ñ', 120), $justo->json('model.name'));

        // El nombre se guarda recortado.
        $con_espacios = $this->postJson('api/vender-layout', ['name' => '  Mostrador  ']);
        $con_espacios->assertStatus(201);
        $this->assertSame('Mostrador', $con_espacios->json('model.name'));

        // En el update, un nombre vacío tampoco entra y el diseño queda como estaba.
        $diseno_id = $con_espacios->json('model.id');

        $this->putJson('api/vender-layout/'.$diseno_id, ['name' => ''])->assertStatus(422);
        $this->putJson('api/vender-layout/'.$diseno_id, ['name' => null])->assertStatus(422);

        $this->assertSame('Mostrador', VenderLayout::find($diseno_id)->name);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  El invariante "exactamente uno en uso"
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El primer diseño de un dueño sin ninguno en uso queda en uso aunque no lo pidan. El segundo,
     * si no lo piden, queda apagado.
     *
     * @test
     */
    public function el_primer_diseno_queda_en_uso_aunque_no_lo_pidan()
    {
        $dueno = $this->dueno_sin_disenos();

        $primero = $this->postJson('api/vender-layout', [
            'name'   => 'Mostrador',
            'layout' => $this->layout_de_ejemplo(),
            'en_uso' => false,
        ]);

        $primero->assertStatus(201);
        $this->assertSame(true, $primero->json('model.en_uso'));

        $segundo = $this->postJson('api/vender-layout', [
            'name'   => 'Depósito',
            'en_uso' => false,
        ]);

        $segundo->assertStatus(201);
        $this->assertSame(false, $segundo->json('model.en_uso'));

        $this->assertSame([$primero->json('model.id')], $this->ids_en_uso($dueno->id));
    }

    /**
     * Si el dueño tiene diseños pero NINGUNO en uso (datos tocados a mano), el nuevo queda en uso.
     *
     * @test
     */
    public function si_no_hay_ninguno_en_uso_el_nuevo_queda_en_uso()
    {
        $dueno = $this->dueno_sin_disenos();

        $this->crear_diseno($dueno->id, 'Apagado 1');
        $this->crear_diseno($dueno->id, 'Apagado 2');

        $nuevo = $this->postJson('api/vender-layout', ['name' => 'Nuevo', 'en_uso' => false]);

        $nuevo->assertStatus(201);
        $this->assertSame([$nuevo->json('model.id')], $this->ids_en_uso($dueno->id));
    }

    /**
     * Poner un diseño en uso, al crearlo o al editarlo, apaga todos los demás del dueño. En cada
     * paso queda exactamente uno en uso.
     *
     * @test
     */
    public function poner_uno_en_uso_apaga_los_demas()
    {
        $dueno = $this->dueno_sin_disenos();

        $a = $this->postJson('api/vender-layout', ['name' => 'A'])->json('model.id');
        $b = $this->postJson('api/vender-layout', ['name' => 'B', 'en_uso' => false])->json('model.id');

        $this->assertSame([$a], $this->ids_en_uso($dueno->id));

        // Al crear.
        $c_respuesta = $this->postJson('api/vender-layout', ['name' => 'C', 'en_uso' => true]);
        $c_respuesta->assertStatus(201);
        $c = $c_respuesta->json('model.id');

        $this->assertSame(true, $c_respuesta->json('model.en_uso'));
        $this->assertSame([$c], $this->ids_en_uso($dueno->id));

        // Al editar ("Usar este diseño" del ABM manda solo {en_uso: true}).
        $b_respuesta = $this->putJson('api/vender-layout/'.$b, ['en_uso' => true]);
        $b_respuesta->assertStatus(200);

        $this->assertSame(true, $b_respuesta->json('model.en_uso'));
        $this->assertSame([$b], $this->ids_en_uso($dueno->id));
        $this->assertSame('B', $b_respuesta->json('model.name'));

        // Un checkbox que llega como texto también vale.
        $this->putJson('api/vender-layout/'.$a, ['en_uso' => '1'])->assertStatus(200);
        $this->assertSame([$a], $this->ids_en_uso($dueno->id));
    }

    /**
     * Poner en uso un diseño no toca los diseños de OTRO negocio.
     *
     * @test
     */
    public function poner_uno_en_uso_no_apaga_los_de_otro_negocio()
    {
        $dueno = $this->dueno_sin_disenos();

        $otro = $this->otro_dueno();
        $ajeno = $this->crear_diseno($otro->id, 'Del otro negocio', true);

        $this->postJson('api/vender-layout', ['name' => 'Propio', 'en_uso' => true])->assertStatus(201);

        $this->assertTrue($ajeno->fresh()->en_uso);
        $this->assertCount(1, $this->ids_en_uso($dueno->id));
    }

    /**
     * `en_uso: false` sobre el diseño en uso se ignora: tiene que haber siempre uno en uso, y se
     * cambia poniendo otro. Sobre uno apagado no cambia nada.
     *
     * @test
     */
    public function apagar_el_diseno_en_uso_se_ignora()
    {
        $dueno = $this->dueno_sin_disenos();

        $en_uso = $this->crear_diseno($dueno->id, 'En uso', true);
        $apagado = $this->crear_diseno($dueno->id, 'Apagado');

        $respuesta = $this->putJson('api/vender-layout/'.$en_uso->id, ['en_uso' => false, 'name' => 'Sigue en uso']);

        $respuesta->assertStatus(200);
        $this->assertSame(true, $respuesta->json('model.en_uso'));
        $this->assertSame('Sigue en uso', $respuesta->json('model.name'));

        $this->putJson('api/vender-layout/'.$apagado->id, ['en_uso' => false])->assertStatus(200);

        $this->assertFalse($apagado->fresh()->en_uso);
        $this->assertSame([$en_uso->id], $this->ids_en_uso($dueno->id));
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Update parcial
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Un update con solo `name` no toca el layout ni el "en uso".
     *
     * @test
     */
    public function el_update_parcial_solo_toca_lo_que_viene()
    {
        $dueno = $this->dueno_sin_disenos();

        $diseno = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());

        $respuesta = $this->putJson('api/vender-layout/'.$diseno->id, ['name' => 'Mostrador nuevo']);

        $respuesta->assertStatus(200);

        $fresco = $diseno->fresh();

        $this->assertSame('Mostrador nuevo', $fresco->name);
        $this->assertSame($this->layout_de_ejemplo(), $fresco->layout);
        $this->assertTrue($fresco->en_uso);
        $this->assertSame($this->layout_de_ejemplo(), $respuesta->json('model.layout'));

        // Y uno con solo `layout` no toca el nombre.
        $otro_layout = ['etapas' => ['etapa_1' => [['key' => 'cliente', 'cols' => 6]]]];

        $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => $otro_layout])->assertStatus(200);

        $fresco = $diseno->fresh();

        $this->assertSame('Mostrador nuevo', $fresco->name);
        $this->assertSame([['key' => 'cliente', 'cols' => 6]], $fresco->layout['etapas']['etapa_1']);
    }

    /**
     * `layout: null` explícito es válido: el diseño vuelve al predeterminado del sistema.
     *
     * @test
     */
    public function layout_null_vuelve_al_predeterminado_del_sistema()
    {
        $dueno = $this->dueno_sin_disenos();

        $diseno = $this->crear_diseno($dueno->id, 'Mostrador', true, $this->layout_de_ejemplo());

        $respuesta = $this->putJson('api/vender-layout/'.$diseno->id, ['layout' => null]);

        $respuesta->assertStatus(200);
        $this->assertNull($respuesta->json('model.layout'));

        $fresco = $diseno->fresh();

        $this->assertNull($fresco->layout);
        $this->assertSame('Mostrador', $fresco->name);
        $this->assertTrue($fresco->en_uso);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Borrado
     * ---------------------------------------------------------------------------------------
     */

    /**
     * El diseño en uso no se borra: 422 con el mensaje del contrato, y sigue estando.
     *
     * @test
     */
    public function no_se_puede_borrar_el_diseno_en_uso()
    {
        $dueno = $this->dueno_sin_disenos();

        $en_uso = $this->crear_diseno($dueno->id, 'En uso', true);

        $respuesta = $this->deleteJson('api/vender-layout/'.$en_uso->id);

        $respuesta->assertStatus(422);
        $this->assertSame(
            'No se puede eliminar el diseño en uso. Poné otro diseño en uso y después eliminá este.',
            $respuesta->json('message')
        );

        $this->assertNotNull(VenderLayout::find($en_uso->id));
        $this->assertSame([$en_uso->id], $this->ids_en_uso($dueno->id));
    }

    /**
     * Un diseño que no está en uso se borra (200) y el que está en uso sigue igual.
     *
     * @test
     */
    public function se_puede_borrar_un_diseno_que_no_esta_en_uso()
    {
        $dueno = $this->dueno_sin_disenos();

        $en_uso = $this->crear_diseno($dueno->id, 'En uso', true);
        $apagado = $this->crear_diseno($dueno->id, 'Apagado');

        $this->deleteJson('api/vender-layout/'.$apagado->id)->assertStatus(200);

        $this->assertNull(VenderLayout::find($apagado->id));
        $this->assertSame([$en_uso->id], $this->ids_en_uso($dueno->id));
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Aislamiento entre negocios y empleados
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Show, update y delete de un diseño de OTRO dueño dan 404 y no lo tocan. El update con
     * `en_uso: true` tampoco apaga los diseños propios.
     *
     * @test
     */
    public function un_diseno_de_otro_dueno_da_404()
    {
        $dueno = $this->dueno_sin_disenos();
        $propio = $this->crear_diseno($dueno->id, 'Propio', true);

        $otro = $this->otro_dueno();
        $ajeno = $this->crear_diseno($otro->id, 'Del otro negocio', false, $this->layout_de_ejemplo());

        $show = $this->getJson('api/vender-layout/'.$ajeno->id);
        $show->assertStatus(404);
        $this->assertSame(VenderLayoutController::MENSAJE_NO_ENCONTRADO, $show->json('message'));

        $this->putJson('api/vender-layout/'.$ajeno->id, [
            'name'   => 'Pisado',
            'layout' => null,
            'en_uso' => true,
        ])->assertStatus(404);

        $this->deleteJson('api/vender-layout/'.$ajeno->id)->assertStatus(404);

        $fresco = $ajeno->fresh();

        $this->assertNotNull($fresco, 'El delete borró el diseño de otro negocio.');
        $this->assertSame('Del otro negocio', $fresco->name);
        $this->assertSame($this->layout_de_ejemplo(), $fresco->layout);
        $this->assertFalse($fresco->en_uso);

        $this->assertSame([$propio->id], $this->ids_en_uso($dueno->id));

        // Un id que no existe también es 404.
        $this->getJson('api/vender-layout/999999999')->assertStatus(404);
    }

    /**
     * Un empleado ve los diseños de su dueño (no le nace ninguno propio) y lo que crea queda a
     * nombre del dueño: el diseño es uno para todo el negocio.
     *
     * @test
     */
    public function un_empleado_ve_y_crea_los_disenos_del_dueno()
    {
        $dueno = $this->dueno_sin_disenos();
        $propio = $this->crear_diseno($dueno->id, 'Mostrador', true);

        $empleado = $this->empleado_del_dueno();

        // Ver el comentario de flushSession() en Catalogos/2_Recursos_iniciales_Test: UserHelper lee
        // la sesión antes que Auth::user().
        $this->flushSession();
        $this->actingAs($empleado, 'web');

        $index = $this->getJson('api/vender-layout');

        $index->assertStatus(200);
        $this->assertSame([$propio->id], array_column($index->json('models'), 'id'));

        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());

        $creado = $this->postJson('api/vender-layout', ['name' => 'Del empleado']);

        $creado->assertStatus(201);
        $this->assertSame($dueno->id, $creado->json('model.user_id'));
        $this->assertSame(0, VenderLayout::where('user_id', $empleado->id)->count());
    }
}
