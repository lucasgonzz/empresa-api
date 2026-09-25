<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Andamiaje de los tests de "vincular un comprador con un cliente del sistema" (misión
 * vincular-comprador-desde-pedidos, 24/9/2026): dueños de empresa nuevos, empleados, clientes y
 * compradores armados por el camino más corto.
 *
 * 🔴 Cada test crea SUS dueños en lugar de usar el usuario del fixture. Las coincidencias de
 * clientes se afirman por conjunto exacto ("estos y solo estos"), y el fixture trae clientes
 * propios: con un dueño nuevo el universo de clientes es el que arma el test y nada más. Es el
 * mismo criterio de `IndexSinHistoriaDeMensajesTest`, y la base del slot arrastra datos de otras
 * suites. `DatabaseTransactions` (de `EmpresaTestCase`) deshace todo al terminar.
 *
 * Los nombres no pisan a `Tests\Concerns\PedidosDePrueba` (que tiene `crear_comprador()`), así
 * que las dos pueden convivir en una misma clase.
 *
 * Requiere `Tests\EmpresaTestCase`. PHP 7.4.
 */
trait ComercioDePrueba
{
    /**
     * Crea un dueño de empresa nuevo (sin `owner_id`), con un email único.
     *
     * @param  string  $etiqueta  Para distinguirlo en los mensajes de error.
     * @return \App\Models\User
     */
    protected function crear_dueno($etiqueta)
    {
        return User::create([
            'name'         => 'Dueño '.$etiqueta,
            'company_name' => 'Comercio '.$etiqueta,
            'email'        => 'vincular-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Crea un empleado de un dueño: un usuario con `owner_id`. `Controller::userId()` lo resuelve
     * al dueño, así que tiene que ver y tocar lo mismo que él.
     *
     * @param  \App\Models\User  $dueno
     * @return \App\Models\User
     */
    protected function crear_empleado($dueno)
    {
        return User::create([
            'name'         => 'Empleado de '.$dueno->name,
            'company_name' => $dueno->company_name,
            'email'        => 'vincular-empleado-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
        ]);
    }

    /**
     * Crea un cliente del sistema de un dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $atributos  Lo que se quiera pisar (`name`, `email`, `phone`, `cuit`...).
     * @return \App\Models\Client
     */
    protected function crear_cliente_de($dueno, $atributos = [])
    {
        return Client::create(array_merge([
            'name'    => 'Cliente sin nombre',
            'user_id' => $dueno->id,
        ], $atributos));
    }

    /**
     * Crea un comprador de la tienda de un dueño.
     *
     * @param  \App\Models\User  $dueno
     * @param  array  $atributos  Lo que se quiera pisar (`name`, `surname`, `email`, `phone`,
     *                            `comercio_city_client_id`...).
     * @return \App\Models\Buyer
     */
    protected function crear_comprador_de($dueno, $atributos = [])
    {
        return Buyer::create(array_merge([
            'name'    => 'Comprador sin nombre',
            'user_id' => $dueno->id,
        ], $atributos));
    }

    /**
     * Cambia el usuario autenticado. El `Auth::forgetGuards()` es imprescindible: la ruta está bajo
     * `auth:sanctum`, cuyo guard cachea el usuario que resolvió la primera vez, y
     * `EmpresaTestCase::setUp()` ya autenticó al usuario del fixture.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }
}
