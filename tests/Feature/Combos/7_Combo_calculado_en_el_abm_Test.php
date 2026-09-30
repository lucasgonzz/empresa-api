<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboAltaHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Combo;
use App\Models\Image;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * El combo calculado por el ABM (`POST/PUT api/combo`) y por el helper del alta (misión
 * combos-calculados, 30/9/2026).
 *
 * Lo que protegen, en orden de importancia:
 *
 *  - 🔴 que las claves nuevas lleguen hasta la base en las DOS puntas del alta: el `only()` del
 *    controlador y el `Combo::create` del helper. Lo que el `only()` no nombra se pierde en silencio
 *    (el alta responde 201, el combo queda con el check apagado y los números tipeados), y es el
 *    mismo agujero que ya se comió `online` (ver `1_Combo_online_en_el_abm_Test`);
 *  - que un empresa-spa VIEJO (que no manda las claves nuevas) no pise lo que el combo ya tiene: la
 *    edición solo escribe las claves que vienen en el request;
 *  - que en un combo calculado el costo y el precio del request se IGNOREN (los pone el servidor);
 *  - que apagar el check devuelva el combo a manual y limpie los precios por lista, y que el
 *    descuento inválido se rechace con 422 en vez de guardarse "arreglado";
 *  - que las fotos del combo (`images`, alias `combo` del morph map) funcionen.
 *
 * @group combos-calculados
 */
class Combo_calculado_en_el_abm_Test extends ComboCalculadoTestCase
{
    /**
     * Payload de `POST/PUT api/combo` en la forma que manda el modal del ABM.
     *
     * @param  array  $componentes  Lista de [articulo, cantidad].
     * @param  array  $extra        Claves extra o pisadas.
     * @return array
     */
    protected function payload(array $componentes, array $extra = [])
    {
        $articulos = [];

        foreach ($componentes as $par) {
            $articulos[] = ['id' => $par[0]->id, 'pivot' => ['amount' => $par[1]]];
        }

        return array_merge([
            'name'     => 'zz Combo ABM ' . uniqid(),
            'cost'     => 1,
            'price'    => 2,
            'articles' => $articulos,
        ], $extra);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Alta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL TEST DE LA PUNTA DEL CONTROLADOR: el check llega hasta la base y el costo y el precio
     * que mandó el SPA se ignoran. Se pone rojo si `calcular_desde_articulos` se cae del `only()`.
     *
     * @test
     */
    public function crear_por_el_endpoint_con_el_check_prendido_calcula_el_combo()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);
        $b = $this->nuevo_articulo(['costo_real' => 40, 'final_price' => 90]);

        $respuesta = $this->post('api/combo', $this->payload([[$a, 2], [$b, 1]], [
            'calcular_desde_articulos' => 1,
            'cost'                     => 12345,
            'price'                    => 67890,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertNotNull($id);

        $this->assertSame(1, (int) $this->fila($id)->calcular_desde_articulos, 'el check tiene que llegar a la base');
        $this->assertSame(240.0, $this->costo_en_base($id), 'el costo del request se ignora');
        $this->assertSame(590.0, $this->precio_en_base($id), 'el precio del request se ignora');

        // Y la respuesta trae el combo ya calculado (el SPA pinta con lo que le devuelve el alta).
        $this->assertEquals(240, $respuesta->json('model.cost'));
        $this->assertEquals(590, $respuesta->json('model.price'));
        $this->assertEquals(1, $respuesta->json('model.calcular_desde_articulos'));
    }

    /**
     * El descuento también viaja por el `only()` y se aplica al alta.
     *
     * @test
     */
    public function crear_por_el_endpoint_con_descuento_lo_aplica_al_precio()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 200]);

        $respuesta = $this->post('api/combo', $this->payload([[$a, 1]], [
            'calcular_desde_articulos' => 1,
            'descuento_tipo'           => 'porcentaje',
            'descuento_valor'          => 25,
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertSame('porcentaje', $this->fila($id)->descuento_tipo);
        $this->assertSame(25.0, (float) $this->fila($id)->descuento_valor);
        $this->assertSame(150.0, $this->precio_en_base($id));
        $this->assertSame(100.0, $this->costo_en_base($id), 'el descuento no toca el costo');
    }

    /**
     * 🔴 EL TEST DE LA PUNTA DEL HELPER: el `Combo::create` de `ComboAltaHelper::crear()` escribe las
     * columnas nuevas. Va contra el helper para que las dos puntas se caigan por separado.
     *
     * @test
     */
    public function el_helper_del_alta_escribe_el_check_y_el_descuento()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 50, 'final_price' => 100]);

        $combo = ComboAltaHelper::crear([
            'name'                     => 'zz Combo directo al helper ' . uniqid(),
            'cost'                     => 1,
            'price'                    => 2,
            'calcular_desde_articulos' => 1,
            'descuento_tipo'           => 'monto',
            'descuento_valor'          => 30,
            'articles'                 => [['id' => $a->id, 'pivot' => ['amount' => 2]]],
        ], self::DUENO, 970500 + rand(1, 999));

        $this->assertSame(1, (int) $this->fila($combo)->calcular_desde_articulos);
        $this->assertSame('monto', $this->fila($combo)->descuento_tipo);
        $this->assertSame(100.0, $this->costo_en_base($combo), '2 x 50');
        $this->assertSame(170.0, $this->precio_en_base($combo), '2 x 100 menos 30');
    }

    /**
     * Sin las claves nuevas (un SPA viejo, o el asistente de IA) el combo nace MANUAL, con los
     * números que se mandaron, exactamente como antes de la misión.
     *
     * @test
     */
    public function crear_sin_las_claves_nuevas_deja_un_combo_manual()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);

        $respuesta = $this->post('api/combo', $this->payload([[$a, 2]], ['cost' => 777, 'price' => 888]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $this->assertSame(0, (int) $this->fila($id)->calcular_desde_articulos);
        $this->assertNull($this->fila($id)->descuento_tipo);
        $this->assertSame(777.0, $this->costo_en_base($id));
        $this->assertSame(888.0, $this->precio_en_base($id));
    }

    /**
     * Un descuento inválido se rechaza con 422 y no se crea nada.
     *
     * @test
     */
    public function crear_con_un_descuento_invalido_responde_422_y_no_crea()
    {
        $a = $this->nuevo_articulo();

        $antes = Combo::where('user_id', self::DUENO)->count();

        foreach ([['porcentaje', 150], ['porcentaje', 100], ['monto', -5], ['regalo', 10]] as $par) {

            $respuesta = $this->post('api/combo', $this->payload([[$a, 1]], [
                'calcular_desde_articulos' => 1,
                'descuento_tipo'           => $par[0],
                'descuento_valor'          => $par[1],
            ]));

            $respuesta->assertStatus(422);
            $this->assertNotEmpty($respuesta->json('message'));
        }

        $this->assertSame($antes, Combo::where('user_id', self::DUENO)->count());
    }

    /**
     * Con listas, el alta devuelve `price_types` con `pivot.price` (el SPA los muestra) y
     * `combos.price` es el de la lista por defecto.
     *
     * @test
     */
    public function crear_con_listas_devuelve_los_precios_por_lista()
    {
        $this->con_listas(1);

        $baja = $this->lista('ABM baja', 90);
        $alta = $this->lista('ABM alta', 91);

        $a = $this->nuevo_articulo(['costo_real' => 10]);

        $this->precio_en_lista($a, $baja, 300);
        $this->precio_en_lista($a, $alta, 260);

        $respuesta = $this->post('api/combo', $this->payload([[$a, 2]], ['calcular_desde_articulos' => 1]));

        $respuesta->assertStatus(201);

        $por_lista = [];

        foreach ($respuesta->json('model.price_types') as $lista) {
            $por_lista[$lista['id']] = (float) $lista['pivot']['price'];
        }

        $this->assertSame(600.0, $por_lista[$baja->id]);
        $this->assertSame(520.0, $por_lista[$alta->id]);
        $this->assertEquals(520, $respuesta->json('model.price'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Edición
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Un empresa-spa VIEJO edita el nombre de un combo calculado con descuento: no manda el
     * check ni el descuento, y reenvía el costo y el precio que vio en pantalla. Nada de eso pisa
     * al combo: sigue calculado, con su descuento, y los números los pone el servidor.
     *
     * @test
     */
    public function editar_desde_un_spa_viejo_no_pisa_el_check_ni_el_descuento()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 200]);

        $combo = $this->combo_calculado([[$a, 1]], ['descuento_tipo' => 'porcentaje', 'descuento_valor' => 50]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(100.0, $this->precio_en_base($combo));

        $respuesta = $this->put('api/combo/' . $combo->id, [
            'name'     => 'zz Renombrado por un SPA viejo',
            'cost'     => 1,
            'price'    => 2,
            'articles' => [['id' => $a->id, 'pivot' => ['amount' => 1]]],
        ]);

        $respuesta->assertStatus(200);

        $fila = $this->fila($combo);

        $this->assertSame('zz Renombrado por un SPA viejo', $fila->name);
        $this->assertSame(1, (int) $fila->calcular_desde_articulos, 'el check no se pisa si la clave no viene');
        $this->assertSame('porcentaje', $fila->descuento_tipo);
        $this->assertSame(50.0, (float) $fila->descuento_valor);
        $this->assertSame(100.0, $this->costo_en_base($combo), 'el costo del request se ignora en un combo calculado');
        $this->assertSame(100.0, $this->precio_en_base($combo), 'el precio del request se ignora en un combo calculado');
    }

    /**
     * Cambiar la composición de un combo calculado rehace la cuenta con los artículos NUEVOS.
     *
     * @test
     */
    public function editar_la_composicion_de_un_combo_calculado_lo_recalcula()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 200]);
        $b = $this->nuevo_articulo(['costo_real' => 10, 'final_price' => 30]);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $this->assertSame(200.0, $this->precio_en_base($combo));

        $this->put('api/combo/' . $combo->id, [
            'name'     => $combo->name,
            'articles' => [
                ['id' => $a->id, 'pivot' => ['amount' => 2]],
                ['id' => $b->id, 'pivot' => ['amount' => 3]],
            ],
        ])->assertStatus(200);

        $this->assertSame(230.0, $this->costo_en_base($combo), '2 x 100 + 3 x 10');
        $this->assertSame(490.0, $this->precio_en_base($combo), '2 x 200 + 3 x 30');
    }

    /**
     * Prender el check en un combo manual lo calcula; apagarlo lo devuelve a manual con los números
     * del request y BORRA los precios por lista (una tienda nueva los preferiría a `combos.price`).
     *
     * @test
     */
    public function prender_y_apagar_el_check_de_un_combo_existente()
    {
        $this->con_listas(1);

        $lista = $this->lista('Toggle', 90);

        $a = $this->nuevo_articulo(['costo_real' => 100]);
        $this->precio_en_lista($a, $lista, 300);

        $combo = $this->combo([[$a, 1]], ['cost' => 5, 'price' => 6]);

        $payload = [
            'name'     => $combo->name,
            'cost'     => 5,
            'price'    => 6,
            'articles' => [['id' => $a->id, 'pivot' => ['amount' => 1]]],
        ];

        // Prendido: se calcula.
        $this->put('api/combo/' . $combo->id, array_merge($payload, ['calcular_desde_articulos' => 1]))->assertStatus(200);

        $this->assertSame(100.0, $this->costo_en_base($combo));
        $this->assertSame(300.0, $this->precio_en_base($combo));
        $this->assertArrayHasKey($lista->id, $this->precios_por_lista_en_base($combo));

        // Apagado: vuelve a manual con lo que mandó la pantalla y se limpian los precios por lista.
        $this->put('api/combo/' . $combo->id, array_merge($payload, [
            'calcular_desde_articulos' => 0,
            'cost'                     => 111,
            'price'                    => 222,
        ]))->assertStatus(200);

        $this->assertSame(0, (int) $this->fila($combo)->calcular_desde_articulos);
        $this->assertSame(111.0, $this->costo_en_base($combo));
        $this->assertSame(222.0, $this->precio_en_base($combo));
        $this->assertSame([], $this->precios_por_lista_en_base($combo), 'un combo manual no deja precios por lista viejos');
    }

    /**
     * El descuento se puede cambiar en la edición, y uno inválido se rechaza con 422 sin tocar nada.
     *
     * @test
     */
    public function editar_el_descuento_lo_aplica_y_uno_invalido_responde_422()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 200]);

        $combo = $this->combo_calculado([[$a, 1]]);

        ComboCalculadoHelper::guardar($combo);

        $payload = [
            'name'     => $combo->name,
            'articles' => [['id' => $a->id, 'pivot' => ['amount' => 1]]],
        ];

        $this->put('api/combo/' . $combo->id, array_merge($payload, [
            'descuento_tipo'  => 'monto',
            'descuento_valor' => 20,
        ]))->assertStatus(200);

        $this->assertSame(180.0, $this->precio_en_base($combo));

        $this->put('api/combo/' . $combo->id, array_merge($payload, [
            'descuento_tipo'  => 'porcentaje',
            'descuento_valor' => 120,
        ]))->assertStatus(422);

        $this->assertSame('monto', $this->fila($combo)->descuento_tipo, 'el 422 no guarda nada');
        $this->assertSame(180.0, $this->precio_en_base($combo));
    }

    /**
     * Un combo manual editado por el ABM se comporta como siempre: sus números son los del request.
     *
     * @test
     */
    public function editar_un_combo_manual_escribe_el_costo_y_el_precio_del_request()
    {
        $this->con_listas(0);

        $a = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);

        $combo = $this->combo([[$a, 1]], ['cost' => 5, 'price' => 6]);

        $this->put('api/combo/' . $combo->id, [
            'name'     => $combo->name,
            'cost'     => 40,
            'price'    => 90,
            'articles' => [['id' => $a->id, 'pivot' => ['amount' => 1]]],
        ])->assertStatus(200);

        $this->assertSame(40.0, $this->costo_en_base($combo));
        $this->assertSame(90.0, $this->precio_en_base($combo));
        $this->assertSame(0, (int) $this->fila($combo)->calcular_desde_articulos);
    }

    /**
     * `Article::combos()` estaba definida contra `Article` (un artículo contra sí mismo) y nadie la
     * usaba; ahora devuelve los combos que incluyen al artículo, borrados incluidos.
     *
     * @test
     */
    public function un_articulo_sabe_en_que_combos_esta()
    {
        $a = $this->nuevo_articulo();

        $uno  = $this->combo([[$a, 1]]);
        $otro = $this->combo([[$a, 3]]);
        $otro->delete();

        $this->combo([[$this->nuevo_articulo(), 1]]);

        $ids = $a->combos()->pluck('combos.id')->sort()->values()->all();

        $this->assertSame([$uno->id, $otro->id], $ids);
        $this->assertInstanceOf(Combo::class, $a->combos()->first());
    }

    /**
     * La búsqueda de combos de Vender (`POST api/search/combo`) trae el stock calculado y las fotos.
     *
     * @test
     */
    public function la_busqueda_de_combos_trae_el_stock_y_las_fotos()
    {
        $a = $this->nuevo_articulo(['stock' => 9]);

        $combo = $this->combo([[$a, 2]], ['name' => 'zz Combo buscable ' . uniqid()]);

        $respuesta = $this->postJson('api/search/combo', ['filters' => []]);

        $respuesta->assertStatus(200);

        $modelos = $respuesta->json('models');

        if (isset($modelos['data'])) {
            $modelos = $modelos['data'];
        }

        $encontrado = null;

        foreach ($modelos as $modelo) {
            if ($modelo['id'] === $combo->id) {
                $encontrado = $modelo;
            }
        }

        $this->assertNotNull($encontrado, 'el combo tiene que venir en la búsqueda');
        $this->assertSame(4, $encontrado['stock_disponible'], '9 / 2');
        $this->assertArrayHasKey('images', $encontrado);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Fotos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El alias `combo` del morph map apunta al modelo, y `Combo::images()` lee la tabla `images` de
     * siempre. Sin el alias, `Image::create(['imageable_type' => 'combo'])` de
     * `ImageController::setImage()` revienta con ClassMorphViolationException.
     *
     * @test
     */
    public function el_combo_tiene_fotos_propias_por_el_alias_combo_del_morph_map()
    {
        $this->assertSame(Combo::class, Relation::getMorphedModel('combo'));

        $combo = $this->combo([]);

        $imagen = Image::create([
            'hosting_url'    => 'http://empresa.local/storage/zz-combo.webp',
            'imageable_id'   => $combo->id,
            'imageable_type' => 'combo',
        ]);

        $this->assertSame($combo->id, $imagen->imageable->id, 'el morph resuelve al combo');
        $this->assertSame(['http://empresa.local/storage/zz-combo.webp'], $combo->images()->pluck('hosting_url')->all());

        $json = $this->get('api/combo/' . $combo->id)->json('model');

        $this->assertCount(1, $json['images'], 'el combo serializa sus fotos, igual que un artículo');
        $this->assertSame('http://empresa.local/storage/zz-combo.webp', $json['images'][0]['hosting_url']);
    }

    /**
     * Una foto subida ANTES de que el combo existiera (con `temporal_id`) se engancha al combo al
     * crearlo, con el mismo mecanismo que Article: `childrens` en el payload.
     *
     * @test
     */
    public function una_foto_temporal_se_engancha_al_crear_el_combo()
    {
        $a = $this->nuevo_articulo();

        $temporal = 8800000 + rand(1, 99999);

        $imagen = Image::create([
            'hosting_url'    => 'http://empresa.local/storage/zz-temporal.webp',
            'imageable_id'   => null,
            'imageable_type' => 'combo',
            'temporal_id'    => $temporal,
        ]);

        $respuesta = $this->post('api/combo', $this->payload([[$a, 1]], [
            'childrens' => [['is_imageable' => true, 'temporal_id' => $temporal, 'model_name' => 'image']],
        ]));

        $respuesta->assertStatus(201);

        $id = $respuesta->json('model.id');

        $imagen->refresh();

        $this->assertSame($id, (int) $imagen->imageable_id, 'la foto temporal quedó atada al combo recién creado');
        $this->assertNull($imagen->temporal_id);
        $this->assertCount(1, $respuesta->json('model.images'));
    }

    /**
     * Borrar el combo (borrado lógico, va a la papelera y se puede restaurar) NO borra sus fotos:
     * si se borraran, un combo restaurado volvería sin foto. Queda fijado a propósito.
     *
     * @test
     */
    public function borrar_un_combo_conserva_sus_fotos_para_poder_restaurarlo()
    {
        $combo = $this->combo([]);

        Image::create([
            'hosting_url'    => 'http://empresa.local/storage/zz-conservada.webp',
            'imageable_id'   => $combo->id,
            'imageable_type' => 'combo',
        ]);

        $this->delete('api/combo/' . $combo->id)->assertStatus(200);

        $this->assertNotNull($this->fila($combo)->deleted_at);
        $this->assertSame(1, Image::where('imageable_type', 'combo')->where('imageable_id', $combo->id)->count());
    }
}
