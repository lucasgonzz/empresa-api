<?php

namespace Tests\Feature\Catalogos;

use App\Models\CurrentAcountPaymentMethod;
use App\Models\CurrentAcountPaymentMethodDiscount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Borrar un metodo de pago tiene que llevarse sus descuentos configurados.
 *
 * 🔴 EL CANDADO DE UN INCIDENTE REAL, no una prolijidad. En la produccion de masquito, el
 * 3/9/2026, el dueno borro un metodo de pago desde el ABM y el comercio quedo sin poder editar
 * stock: desaparecieron el boton de editar stock y las columnas de stock por sucursal del listado
 * de articulos.
 *
 * La cadena: el descuento quedaba apuntando a un metodo inexistente, el SPA le leia
 * `current_acount_payment_method.name` para armar la columna, y esa excepcion se llevaba puestas
 * las columnas de sucursal, que se arman despues en la misma funcion. Ninguno de los tres sintomas
 * se parecia a "borre un medio de pago".
 *
 * El SPA ahora se defiende igual (filtra los descuentos sin metodo), pero esta es la mitad que
 * evita que el dato malo llegue a existir.
 *
 * DatabaseTransactions y no RefreshDatabase: la base de testing de la rama viene sembrada de antes
 * y un refresh la vaciaria.
 */
class BorrarMetodoDePagoSeLlevaSusDescuentosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama. Null si la base no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @test
     */
    public function borrar_un_metodo_de_pago_borra_sus_descuentos()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'Metodo a borrar']);

        $descuento = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => $metodo->id,
            'discount_percentage'              => -20,
            'user_id'                          => $user->id,
        ]);

        $this->delete('/api/current-acount-payment-method/' . $metodo->id)->assertStatus(200);

        $this->assertNull(
            CurrentAcountPaymentMethod::find($metodo->id),
            'El metodo de pago no se borro.'
        );
        $this->assertNull(
            CurrentAcountPaymentMethodDiscount::find($descuento->id),
            'El descuento quedo huerfano: es exactamente lo que rompio el listado de masquito.'
        );
    }

    /**
     * Los descuentos de OTRO metodo no se tocan.
     *
     * Es la otra mitad del candado: una limpieza que se lleve de mas seria peor que el bug.
     *
     * @test
     */
    public function no_toca_los_descuentos_de_otro_metodo_de_pago()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $metodo_a_borrar = CurrentAcountPaymentMethod::create(['name' => 'Se borra']);
        $metodo_que_queda = CurrentAcountPaymentMethod::create(['name' => 'Se queda']);

        $descuento_del_borrado = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => $metodo_a_borrar->id,
            'discount_percentage'              => -10,
            'user_id'                          => $user->id,
        ]);
        $descuento_del_que_queda = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => $metodo_que_queda->id,
            'discount_percentage'              => -15,
            'user_id'                          => $user->id,
        ]);

        $this->delete('/api/current-acount-payment-method/' . $metodo_a_borrar->id)->assertStatus(200);

        $this->assertNull(CurrentAcountPaymentMethodDiscount::find($descuento_del_borrado->id));
        $this->assertNotNull(
            CurrentAcountPaymentMethodDiscount::find($descuento_del_que_queda->id),
            'Se borro el descuento de un metodo que no se estaba borrando.'
        );
        $this->assertNotNull(CurrentAcountPaymentMethod::find($metodo_que_queda->id));
    }

    /**
     * Un metodo sin descuentos se borra igual que siempre.
     *
     * @test
     */
    public function un_metodo_sin_descuentos_se_borra_igual()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'Sin descuentos']);

        $this->delete('/api/current-acount-payment-method/' . $metodo->id)->assertStatus(200);

        $this->assertNull(CurrentAcountPaymentMethod::find($metodo->id));
    }

    /**
     * 🔴 EL FILTRO QUE PROTEGE A TODO EL SPA: el index no devuelve descuentos huerfanos.
     *
     * Un descuento cuyo metodo fue borrado deja la relacion en null, y el SPA le lee `.name` en
     * SEIS lugares sin preguntar — incluido vender_set_total.js, que calcula el total de una venta.
     * Filtrarlo en el index protege a los seis de una, y a los que se escriban despues.
     *
     * Este test crea el huerfano a mano (con un id de metodo que no existe) porque desde que
     * destroy() limpia, el camino normal ya no los genera: lo que se prueba es la red para los que
     * quedaron de antes o los que aparezcan por otro camino.
     *
     * @test
     */
    public function el_index_no_devuelve_descuentos_cuyo_metodo_no_existe()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'Metodo vivo']);

        $sano = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => $metodo->id,
            'discount_percentage'              => -5,
            'user_id'                          => $user->id,
        ]);

        /* Id de metodo que con seguridad no existe: el huerfano que rompia el listado. */
        $huerfano = CurrentAcountPaymentMethodDiscount::create([
            'current_acount_payment_method_id' => 99999999,
            'discount_percentage'              => -20,
            'user_id'                          => $user->id,
        ]);

        $respuesta = $this->getJson('/api/cc-payment-method-discount');
        $respuesta->assertStatus(200);

        $ids = collect($respuesta->json('models'))->pluck('id')->all();

        $this->assertContains($sano->id, $ids, 'El descuento sano no viajo.');
        $this->assertNotContains(
            $huerfano->id,
            $ids,
            'El descuento huerfano viajo al SPA: es lo que tira el TypeError y voltea el listado.'
        );
    }

    /**
     * Borrar un id que no existe no revienta.
     *
     * Antes `find()` devolvia null y el `->delete()` siguiente tiraba un error 500 sobre null.
     *
     * @test
     */
    public function borrar_un_metodo_inexistente_no_revienta()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->delete('/api/current-acount-payment-method/99999999')->assertStatus(200);
    }
}
