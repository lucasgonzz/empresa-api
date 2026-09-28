<?php

namespace Tests\Feature\TiendaMensajes;

use App\Models\Article;
use App\Models\Image;
use Tests\EmpresaTestCase;

/**
 * `GET tienda-chats/{buyer_id}/mensajes` — una conversación (misión mensajes-tienda-online,
 * 28/9/2026, contrato C3).
 *
 * - De a 40; la página 1 son los más recientes y, dentro de cada página, del más viejo al más
 *   nuevo (la SPA pinta la página tal cual y antepone la siguiente arriba).
 * - La forma `Mensaje` exacta, con booleanos de verdad, y el `article` liviano
 *   `{id, name, slug, image_url}` (nada de colors/sizes/questions).
 * - Un comprador ajeno (o inexistente) da 404: en las bases compartidas no se leen chats ajenos.
 *
 * PHP 7.4.
 */
class ConversacionTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Buyer */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('conversación');
        $this->comprador = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => 'ana-conv-'.uniqid().'@test.local',
            'phone'   => '1155550000',
        ]);

        $this->actuar_como($this->dueno);
    }

    /**
     * @param  int|string  $buyer_id
     * @param  int  $page
     * @return string
     */
    protected function ruta($buyer_id, $page = 1)
    {
        return 'api/tienda-chats/'.$buyer_id.'/mensajes?page='.$page;
    }

    /**
     * @test
     */
    public function pagina_de_a_40_con_los_mas_recientes_primero_y_en_orden_de_lectura_adentro()
    {
        $ids = [];

        for ($i = 1; $i <= 45; $i++) {
            $mensaje = ($i % 2 === 0)
                ? $this->mensaje_del_comercio($this->comprador, ['text' => 'Mensaje '.$i])
                : $this->mensaje_del_comprador($this->comprador, ['text' => 'Mensaje '.$i]);
            $ids[] = $mensaje->id;
        }

        $pagina_1 = $this->getJson($this->ruta($this->comprador->id, 1))->assertStatus(200)->json();

        $this->assertSame(['data', 'current_page', 'last_page', 'buyer'], array_keys($pagina_1));
        $this->assertSame(1, $pagina_1['current_page']);
        $this->assertSame(2, $pagina_1['last_page']);

        $ids_pagina_1 = array_map(function ($m) {
            return $m['id'];
        }, $pagina_1['data']);

        // Los 40 más recientes (del 6 al 45), del más viejo al más nuevo.
        $this->assertSame(array_slice($ids, 5, 40), $ids_pagina_1);

        $pagina_2 = $this->getJson($this->ruta($this->comprador->id, 2))->assertStatus(200)->json();

        $ids_pagina_2 = array_map(function ($m) {
            return $m['id'];
        }, $pagina_2['data']);

        // Los 5 más viejos, también en orden de lectura.
        $this->assertSame(array_slice($ids, 0, 5), $ids_pagina_2);
        $this->assertSame(2, $pagina_2['current_page']);

        $this->assertSame([
            'id'                      => $this->comprador->id,
            'name'                    => 'Ana',
            'surname'                 => 'Pérez',
            'email'                   => $this->comprador->email,
            'phone'                   => '1155550000',
            'comercio_city_client_id' => null,
            'comercio_city_client'    => null,
        ], $pagina_1['buyer']);
    }

    /**
     * @test
     */
    public function cada_mensaje_tiene_la_forma_del_contrato_con_el_articulo_liviano()
    {
        $articulo = Article::create([
            'name'    => 'Zapatilla urbana',
            'slug'    => 'zapatilla-urbana',
            'user_id' => $this->dueno->id,
        ]);

        Image::create([
            'imageable_id'   => $articulo->id,
            // El alias del morph map, no el nombre de la clase (ver AppServiceProvider::boot()).
            'imageable_type' => 'article',
            'hosting_url'    => 'https://cdn.test.local/storage/zapatilla.webp',
        ]);

        $con_articulo = $this->mensaje_del_comprador($this->comprador, [
            'text'       => '¿Tienen esta en 42?',
            'article_id' => $articulo->id,
        ]);
        $del_comercio = $this->mensaje_del_comercio($this->comprador, [
            'text' => 'Confirmamos tu pedido',
            'type' => 'order_confirmed',
            'read' => 1,
        ]);

        $data = $this->getJson($this->ruta($this->comprador->id))->assertStatus(200)->json('data');

        $this->assertCount(2, $data);

        $claves = ['id', 'buyer_id', 'user_id', 'text', 'type', 'from_buyer', 'read', 'article_id', 'order_id', 'created_at', 'article'];

        $this->assertSame($claves, array_keys($data[0]));
        $this->assertSame($claves, array_keys($data[1]));

        // El del comprador, con su artículo liviano.
        $this->assertSame($con_articulo->id, $data[0]['id']);
        $this->assertSame($this->comprador->id, $data[0]['buyer_id']);
        $this->assertSame($this->dueno->id, $data[0]['user_id']);
        $this->assertSame('¿Tienen esta en 42?', $data[0]['text']);
        $this->assertNull($data[0]['type']);
        $this->assertSame(true, $data[0]['from_buyer']);
        $this->assertSame(false, $data[0]['read']);
        $this->assertSame($articulo->id, $data[0]['article_id']);
        $this->assertNull($data[0]['order_id']);
        $this->assertSame($con_articulo->fresh()->created_at->toJSON(), $data[0]['created_at']);
        $this->assertSame([
            'id'        => $articulo->id,
            'name'      => 'Zapatilla urbana',
            'slug'      => 'zapatilla-urbana',
            'image_url' => 'https://cdn.test.local/storage/zapatilla.webp',
        ], $data[0]['article']);

        // El automático del comercio, sin artículo; `read` = el comprador ya lo leyó.
        $this->assertSame($del_comercio->id, $data[1]['id']);
        $this->assertSame('order_confirmed', $data[1]['type']);
        $this->assertSame(false, $data[1]['from_buyer']);
        $this->assertSame(true, $data[1]['read']);
        $this->assertNull($data[1]['article_id']);
        $this->assertNull($data[1]['article']);
    }

    /**
     * @test
     */
    public function un_articulo_sin_foto_viaja_con_image_url_null()
    {
        $articulo = Article::create([
            'name'    => 'Sin foto',
            'slug'    => 'sin-foto',
            'user_id' => $this->dueno->id,
        ]);

        $this->mensaje_del_comprador($this->comprador, ['article_id' => $articulo->id]);

        $data = $this->getJson($this->ruta($this->comprador->id))->assertStatus(200)->json('data');

        $this->assertSame([
            'id'        => $articulo->id,
            'name'      => 'Sin foto',
            'slug'      => 'sin-foto',
            'image_url' => null,
        ], $data[0]['article']);
    }

    /**
     * @test
     */
    public function un_comprador_ajeno_o_inexistente_da_404()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('conversación ajeno'));
        $this->mensaje_del_comprador($ajeno, ['text' => 'Esto no lo puede leer otro comercio']);

        $this->getJson($this->ruta($ajeno->id))->assertStatus(404)->assertJsonMissing(['text' => 'Esto no lo puede leer otro comercio']);
        $this->getJson($this->ruta(999999999))->assertStatus(404);
        $this->getJson($this->ruta('12abc'))->assertStatus(404);
    }

    /**
     * @test
     */
    public function el_empleado_lee_las_conversaciones_de_su_comercio()
    {
        $this->mensaje_del_comprador($this->comprador);

        $this->actuar_como($this->crear_empleado($this->dueno));

        $this->getJson($this->ruta($this->comprador->id))->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
