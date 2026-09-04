<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Mision `descuentos-proveedor-al-asignar` (4/9/2026).
 *
 * Preferencia del comercio `users.aplicar_descuentos_proveedor_al_asignar`: recupera la dinamica
 * anterior al merge de refractor — asignarle un proveedor a un articulo le materializa los
 * descuentos de ese proveedor como `article_discounts` tagueados.
 *
 * 🔴 LO QUE MAS IMPORTA DE ESTE ARCHIVO ES EL PRIMER TEST: con la preferencia APAGADA (que es el
 * default de los ~40 comercios) no tiene que pasar absolutamente nada. Si ese test se pone rojo, el
 * cambio le movio los costos a comercios que no pidieron nada.
 *
 * Los otros cuatro fijan el camino prendido: crear con proveedor, asignarle proveedor a uno que no
 * tenia, que los descuentos MANUALES del usuario no se toquen nunca, y que un guardado que no
 * cambia el proveedor no rehaga nada.
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Descuentos_Proveedor_Al_Asignar_Test extends EmpresaTestCase
{
    /**
     * Delta de tolerancia para comparaciones de plata (nunca comparacion exacta sobre floats).
     */
    const DELTA = 0.01;

    /**
     * Nombre del proveedor propio de esta suite. Prefijo `zz` como el resto de los fixtures de
     * Listado: no se toca ningun proveedor del seeder, que tiene expectativas en la suite e2e.
     */
    const PROVEEDOR = 'zz Proveedor Descuentos Al Asignar';

    /**
     * Segundo proveedor, para el caso de cambio A -> B.
     */
    const PROVEEDOR_B = 'zz Proveedor Descuentos Al Asignar B';

    /**
     * Owner del fixture, que es de quien se lee la preferencia.
     *
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Deja la preferencia del comercio en el estado que pide el test.
     *
     * @param  int $valor 0 apagada, 1 prendida.
     * @return void
     */
    private function set_preferencia($valor)
    {
        $owner = $this->owner();
        $owner->aplicar_descuentos_proveedor_al_asignar = $valor;
        $owner->save();
    }

    /**
     * Proveedor con bonificaciones estandar cargadas.
     *
     * @param  string $nombre
     * @param  array  $porcentajes Bonificaciones a cargarle (`provider_discounts.percentage`).
     * @return \App\Models\Provider
     */
    private function proveedor_con_descuentos($nombre, $porcentajes)
    {
        $provider = Provider::firstOrCreate([
            'name'    => $nombre,
            'user_id' => $this->owner()->id,
        ]);

        // Se rearma la lista en cada llamada para que el test no dependa de lo que haya quedado de
        // una corrida anterior (DatabaseTransactions revierte, pero el firstOrCreate puede tomar
        // una fila sembrada por otra suite con el mismo nombre).
        ProviderDiscount::where('provider_id', $provider->id)->delete();

        foreach ($porcentajes as $porcentaje) {
            ProviderDiscount::create([
                'provider_id' => $provider->id,
                'percentage'  => $porcentaje,
            ]);
        }

        return $provider->fresh();
    }

    /**
     * Body de `POST api/article`. Mismo criterio que `CostoBrutoEnElAbmTest::payload()`: store()
     * asigna decenas de campos desde el request y varios son NOT NULL, asi que un payload corto no
     * falla por la logica que se quiere probar sino con un 500 de integridad de la base.
     *
     * @param  array $overrides
     * @return array
     */
    private function payload_alta($overrides = [])
    {
        return array_merge([
            'name'               => 'zz Articulo descuentos al asignar',
            'cost'               => 1000,
            'percentage_gain'    => 50,
            /*
             * Las cinco columnas NOT NULL de `articles` que store() asigna DERECHO desde el request
             * (`$model->online = $request->online`, etc.). Si no viajan llegan null y pisan el
             * default de la base, y el request muere con un 1048 antes de llegar a la logica que se
             * quiere probar. Verificado contra information_schema, no adivinado.
             */
            'online'             => 0,
            'precio_pausado'     => 0,
            'aplicar_iva'        => 0,
            'apply_provider_percentage_gain' => 0,
            'status'             => 'active',
            'in_offer'           => 0,
            'default_in_vender'  => 0,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /**
     * Body de `PUT api/article/{id}`. El controller resuelve el modelo por `$request->id`, no por
     * el parametro de ruta, asi que el id va en el body si o si.
     *
     * @param  \App\Models\Article $article
     * @param  array               $overrides
     * @return array
     */
    private function payload_edicion($article, $overrides = [])
    {
        return array_merge($article->getAttributes(), [
            'id'                 => $article->id,
            'cost_incluye_iva'   => 0,
            'price_types'        => [],
            'price_type_monedas' => [],
            'tags'               => [],
        ], $overrides);
    }

    /**
     * Descuentos tagueados (con proveedor) que tiene hoy un articulo.
     *
     * @param  int $article_id
     * @return \Illuminate\Support\Collection
     */
    private function descuentos_tagueados($article_id)
    {
        return ArticleDiscount::where('article_id', $article_id)
                                ->whereNotNull('provider_id')
                                ->get();
    }

    /**
     * 🔴 EL TEST QUE PROTEGE A LOS ~40 COMERCIOS QUE NO PIDIERON NADA.
     *
     * Con la preferencia apagada (el default), crear un articulo con un proveedor que TIENE
     * bonificaciones cargadas no le crea ningun descuento y no le mueve el costo. Es exactamente el
     * comportamiento de develop.
     *
     * @test
     */
    public function apagada_no_crea_descuentos_ni_mueve_el_costo()
    {
        $this->set_preferencia(0);

        $provider = $this->proveedor_con_descuentos(self::PROVEEDOR, [10]);

        $response = $this->postJson('api/article', $this->payload_alta([
            'provider_id' => $provider->id,
        ]));

        $response->assertStatus(201);

        $article = Article::find(json_decode($response->getContent(), true)['model']['id']);

        $this->assertCount(
            0,
            $this->descuentos_tagueados($article->id),
            'Con la preferencia apagada no se le puede crear ningun descuento al articulo.'
        );

        $this->assertEqualsWithDelta(
            1000,
            (float) $article->costo_real,
            self::DELTA,
            'Con la preferencia apagada el costo real tiene que quedar en el costo bruto, sin descuento.'
        );
    }

    /**
     * Prendida: crear un articulo con proveedor le materializa las bonificaciones de ese proveedor
     * y el costo real sale ya descontado.
     *
     * Dos bonificaciones (10% y 5%) para fijar ademas que se componen en cascada, que es como las
     * aplica `ArticlePricesHelper::aplicar_descuentos()`: 1000 -> 900 -> 855, no 1000 - 15%.
     *
     * @test
     */
    public function prendida_al_crear_materializa_los_descuentos_del_proveedor()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_con_descuentos(self::PROVEEDOR, [10, 5]);

        $response = $this->postJson('api/article', $this->payload_alta([
            'provider_id' => $provider->id,
        ]));

        $response->assertStatus(201);

        $article = Article::find(json_decode($response->getContent(), true)['model']['id']);

        $descuentos = $this->descuentos_tagueados($article->id);

        $this->assertCount(2, $descuentos, 'Tienen que quedar los dos descuentos del proveedor.');

        foreach ($descuentos as $descuento) {
            $this->assertEquals(
                $provider->id,
                $descuento->provider_id,
                'Los descuentos creados tienen que quedar tagueados con el proveedor que los origino.'
            );
            $this->assertEquals(
                ArticleDiscount::TIPO_BONIFICACION_PROVEEDOR,
                $descuento->tipo,
                'Los descuentos creados son bonificaciones de proveedor.'
            );
        }

        /* Cascada: 1000 x 0,90 x 0,95 = 855. NO es 1000 - 15% = 850. */
        $this->assertEqualsWithDelta(
            855,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con los dos descuentos aplicados en cascada.'
        );
    }

    /**
     * Prendida: asignarle un proveedor a un articulo que NO tenia ninguno le materializa los
     * descuentos. Es el segundo hueco que dejaba develop — este camino no pasa por el modal de
     * confirmacion (que solo se abre cuando ya habia otro proveedor) y hasta ahora no hacia nada.
     *
     * @test
     */
    public function prendida_al_asignarle_proveedor_a_uno_que_no_tenia_materializa_los_descuentos()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_con_descuentos(self::PROVEEDOR, [20]);

        /* Articulo sin proveedor, creado por el mismo endpoint real. */
        $alta = $this->postJson('api/article', $this->payload_alta([
            'name'        => 'zz Articulo sin proveedor',
            'provider_id' => null,
        ]));

        $alta->assertStatus(201);

        $article = Article::find(json_decode($alta->getContent(), true)['model']['id']);

        $this->assertCount(
            0,
            $this->descuentos_tagueados($article->id),
            'El articulo tiene que nacer sin descuentos: se creo sin proveedor.'
        );

        $edicion = $this->putJson('api/article/'.$article->id, $this->payload_edicion($article, [
            'provider_id' => $provider->id,
        ]));

        $edicion->assertStatus(200);

        $article = $article->fresh();

        $this->assertCount(
            1,
            $this->descuentos_tagueados($article->id),
            'Al asignarle el proveedor tiene que quedarle su bonificacion.'
        );

        $this->assertEqualsWithDelta(
            800,
            (float) $article->costo_real,
            self::DELTA,
            'El costo real tiene que salir con el 20% del proveedor aplicado.'
        );
    }

    /**
     * 🔴 Prendida: los descuentos MANUALES del usuario (`provider_id` null) no se tocan nunca, ni
     * al asignar ni al cambiar de proveedor. Y el barrido del proveedor anterior es ACOTADO: se va
     * el del proveedor viejo, no el de un tercer proveedor.
     *
     * @test
     */
    public function prendida_no_toca_los_descuentos_manuales_y_barre_solo_al_proveedor_anterior()
    {
        $this->set_preferencia(1);

        $provider_a = $this->proveedor_con_descuentos(self::PROVEEDOR, [10]);
        $provider_b = $this->proveedor_con_descuentos(self::PROVEEDOR_B, [25]);

        $alta = $this->postJson('api/article', $this->payload_alta([
            'name'        => 'zz Articulo con descuento manual',
            'provider_id' => $provider_a->id,
        ]));

        $alta->assertStatus(201);

        $article = Article::find(json_decode($alta->getContent(), true)['model']['id']);

        /* Descuento cargado a mano por el usuario: sin provider_id. */
        $manual = ArticleDiscount::create([
            'article_id'  => $article->id,
            'provider_id' => null,
            'percentage'  => 3,
            'tipo'        => ArticleDiscount::TIPO_OTRO,
        ]);

        $article = $article->fresh();

        $edicion = $this->putJson('api/article/'.$article->id, $this->payload_edicion($article, [
            'provider_id' => $provider_b->id,
        ]));

        $edicion->assertStatus(200);

        $this->assertNotNull(
            ArticleDiscount::find($manual->id),
            'El descuento manual del usuario no se puede borrar nunca.'
        );

        $tagueados = $this->descuentos_tagueados($article->id);

        $this->assertCount(1, $tagueados, 'Tiene que quedar solo la bonificacion del proveedor nuevo.');

        $this->assertEquals(
            $provider_b->id,
            $tagueados->first()->provider_id,
            'El descuento que sobrevive tiene que ser el del proveedor nuevo.'
        );

        /* Cascada de los que quedaron: manual 3% + proveedor B 25%. 1000 x 0,97 x 0,75 = 727,50. */
        $this->assertEqualsWithDelta(
            727.50,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El costo real tiene que reflejar el manual y el del proveedor nuevo, sin el del anterior.'
        );
    }

    /**
     * 🔴 Prendida: un guardado que NO cambia el proveedor no rehace nada.
     *
     * Importa porque el usuario puede editar a mano los descuentos que se le materializaron (esa es
     * justamente la ventaja de que queden cargados en el articulo), y cualquier guardado posterior
     * del formulario —cambiarle el nombre, el costo, lo que sea— no puede pisarle esa edicion.
     *
     * @test
     */
    public function prendida_un_guardado_que_no_cambia_el_proveedor_no_rehace_los_descuentos()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_con_descuentos(self::PROVEEDOR, [10]);

        $alta = $this->postJson('api/article', $this->payload_alta([
            'name'        => 'zz Articulo guardado sin cambio de proveedor',
            'provider_id' => $provider->id,
        ]));

        $alta->assertStatus(201);

        $article = Article::find(json_decode($alta->getContent(), true)['model']['id']);

        $descuento = $this->descuentos_tagueados($article->id)->first();

        $this->assertNotNull($descuento, 'El alta tiene que haber materializado la bonificacion.');

        /* El usuario lo edita a mano: 10% -> 40%. */
        $descuento->percentage = 40;
        $descuento->save();

        $edicion = $this->putJson('api/article/'.$article->id, $this->payload_edicion($article->fresh(), [
            'name' => 'zz Articulo guardado sin cambio de proveedor (renombrado)',
        ]));

        $edicion->assertStatus(200);

        $vigentes = $this->descuentos_tagueados($article->id);

        $this->assertCount(1, $vigentes, 'No se puede duplicar el descuento en un guardado cualquiera.');

        $this->assertEqualsWithDelta(
            40,
            (float) $vigentes->first()->percentage,
            self::DELTA,
            'La edicion manual del usuario sobre el descuento tiene que sobrevivir al guardado.'
        );

        $this->assertEqualsWithDelta(
            600,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El costo real tiene que seguir el descuento editado (40%), no el original del proveedor.'
        );
    }

    /**
     * 🔴 El helper deja la relacion `article_discounts` FRESCA para quien recalcule despues.
     *
     * Es la clase de error del 31/8/2026 (relacion de Eloquent vieja en memoria): Eloquent cachea
     * las relaciones ya cargadas y `create()` escribe la base sin tocar esa cache, asi que un
     * `setFinalPrice()` posterior calcularia con los descuentos de ANTES —ninguno— y guardaria un
     * costo_real que no se corresponde con las filas de la base. Sin error, sin excepcion.
     *
     * Se prueba contra el HELPER y no contra el endpoint a proposito: hoy ni store() ni update()
     * traen la relacion cargada (`Article::find()` sin `withAll()`), asi que por el camino del
     * controller el defecto no se alcanza y un test de endpoint pasaria igual con el
     * `unsetRelation` sacado — verificado por mutacion. Lo que este test fija es el CONTRATO del
     * helper: devolver el modelo coherente, para el llamador de hoy y para el que se agregue
     * mañana.
     *
     * @test
     */
    public function el_helper_deja_la_relacion_fresca_para_el_recalculo_posterior()
    {
        $this->set_preferencia(1);

        $provider = $this->proveedor_con_descuentos(self::PROVEEDOR, [10]);

        $article = Article::create([
            'name'        => 'zz Articulo relacion fresca',
            'user_id'     => $this->owner()->id,
            'cost'        => 1000,
            'percentage_gain' => 50,
            'aplicar_iva' => 0,
            'provider_id' => null,
        ]);

        /*
         * Se fuerza la condicion que arma la trampa: alguien leyo la relacion ANTES de la escritura
         * (aca esta vacia) y Eloquent se la queda cacheada.
         */
        $article->load('article_discounts');

        $this->assertTrue(
            $article->relationLoaded('article_discounts'),
            'El fixture del test tiene que dejar la relacion cargada, si no no prueba nada.'
        );

        $article->provider_id = $provider->id;

        ArticleProviderDiscountHelper::aplicar_al_asignar_proveedor($article, null);

        $this->assertFalse(
            $article->relationLoaded('article_discounts'),
            'El helper tiene que dejar descargada la relacion que acaba de escribir.'
        );

        ArticleHelper::setFinalPrice($article);

        $this->assertEqualsWithDelta(
            900,
            (float) $article->fresh()->costo_real,
            self::DELTA,
            'El recalculo posterior tiene que ver el descuento recien creado, no la coleccion vacia cacheada.'
        );
    }

    /**
     * La preferencia es del COMERCIO y se resuelve al dueño: un empleado que crea el articulo
     * obtiene el comportamiento del comercio, no el de su propia fila (que nadie escribe nunca).
     *
     * @test
     */
    public function la_preferencia_se_resuelve_al_dueño_para_un_empleado()
    {
        $this->set_preferencia(1);

        $owner = $this->owner();

        $empleado = User::create([
            'name'      => 'zz Empleado descuentos al asignar',
            'email'     => 'zz-empleado-descuentos-al-asignar@test.local',
            'password'  => bcrypt('secret'),
            'owner_id'  => $owner->id,
            // Su propia columna queda en 0, que es justo lo que NO se tiene que leer.
            'aplicar_descuentos_proveedor_al_asignar' => 0,
        ]);

        $this->assertTrue(
            ArticleProviderDiscountHelper::debe_aplicar_al_asignar($empleado),
            'Para un empleado la preferencia tiene que leerse de la fila del dueño.'
        );

        $owner->aplicar_descuentos_proveedor_al_asignar = 0;
        $owner->save();

        $this->assertFalse(
            ArticleProviderDiscountHelper::debe_aplicar_al_asignar($empleado),
            'Apagada en el dueño, el empleado tampoco la tiene.'
        );
    }
}
