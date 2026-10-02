<?php

namespace Tests\Feature\Sucursales;

use App\Models\Address;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite del AJUSTE DE PRECIOS DE LA SUCURSAL (mision sucursal-recargo-descuento,
 * 2/10/2026).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUE PRUEBA ESTA SUITE, EN UNA LINEA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Que la API PERSISTE y DEVUELVE `ajuste_precio_tipo` / `ajuste_precio_porcentaje` de `addresses`
 *  (alta y edicion de sucursales), que rechaza con 422 lo que viola la invariante "las dos columnas
 *  con valor valido o las dos en NULL", que `update` NO pisa el ajuste cuando la SPA no manda las
 *  claves, que la guarda de esquema deja crear y editar sucursales antes de migrar, y que la API
 *  NO re-deriva el precio de una venta desde el catalogo (guarda lo que manda la SPA).
 *
 *  La API no calcula ningun precio con el ajuste: lo calcula la SPA. Por eso los numeros de los
 *  payloads de venta estan escritos A MANO (100 con el 10 % adentro = 110).
 *
 *  Toda afirmacion sobre lo guardado se lee DIRECTO de la tabla con `DB::table()`, nunca por la
 *  respuesta del endpoint: si el endpoint devolviera algo que no se guardo, un test que lo leyera
 *  de ahi daria verde con la columna vacia.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
abstract class SucursalesTestCase extends EmpresaTestCase
{
    /** Tolerancia de plata. */
    const DELTA = 0.01;

    /**
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return $user;
    }

    /**
     * Payload minimo de una sucursal, con los campos que manda el ABM de siempre. Las claves del
     * ajuste NO van: cada test las agrega (o no) a proposito, porque que NO viajen es justo uno de
     * los casos (la SPA vieja).
     *
     * @param  array  $extra  Claves que pisan o agregan.
     * @return array
     */
    protected function payload_sucursal($extra = [])
    {
        return array_merge([
            'street'          => 'zz Sucursal ajuste '.uniqid(),
            'street_number'   => null,
            'city'            => null,
            'province'        => null,
            'default_address' => 0,
        ], $extra);
    }

    /**
     * Crea una sucursal por el endpoint real y devuelve su id.
     *
     * @param  array  $extra  Claves que se suman al payload minimo (p. ej. las del ajuste).
     * @return int
     */
    protected function crear_sucursal($extra = [])
    {
        $response = $this->postJson('api/address', $this->payload_sucursal($extra));

        $response->assertStatus(201);

        $id = $response->json('model.id');

        $this->assertNotNull($id, 'POST api/address no devolvio la sucursal creada.');

        return (int) $id;
    }

    /**
     * El par del ajuste tal como esta guardado, leido DIRECTO de la tabla.
     *
     * Los valores vuelven crudos (el tipo como texto o null; el porcentaje como el string decimal
     * que entrega MySQL, p. ej. "10.00", o null).
     *
     * @param  int  $id
     * @return object  Con `ajuste_precio_tipo` y `ajuste_precio_porcentaje`.
     */
    protected function ajuste_guardado($id)
    {
        $fila = DB::table('addresses')
                    ->where('id', $id)
                    ->first(['ajuste_precio_tipo', 'ajuste_precio_porcentaje']);

        $this->assertNotNull($fila, 'La sucursal '.$id.' no esta en la base.');

        return $fila;
    }

    /**
     * Afirma el par guardado de una sucursal. `$porcentaje` en null = se esperan las dos en NULL.
     *
     * @param  int          $id
     * @param  string|null  $tipo
     * @param  float|null   $porcentaje
     * @param  string       $que        Para el mensaje.
     * @return void
     */
    protected function assert_ajuste($id, $tipo, $porcentaje, $que)
    {
        $fila = $this->ajuste_guardado($id);

        $this->assertSame($tipo, $fila->ajuste_precio_tipo, $que.': el tipo guardado no es el esperado.');

        if (is_null($porcentaje)) {
            $this->assertNull($fila->ajuste_precio_porcentaje, $que.': el porcentaje tiene que quedar en NULL.');
            return;
        }

        $this->assertNotNull($fila->ajuste_precio_porcentaje, $que.': el porcentaje no se guardo (quedo NULL).');

        $this->assertEqualsWithDelta(
            $porcentaje,
            (float) $fila->ajuste_precio_porcentaje,
            0.0001,
            $que.': el porcentaje guardado no es el esperado.'
        );
    }

    /**
     * Cantidad de sucursales del comercio de prueba. Para probar que un 422 en el alta no dejo una
     * sucursal a medio escribir.
     *
     * @return int
     */
    protected function cantidad_de_sucursales()
    {
        return Address::where('user_id', $this->comercio()->id)->count();
    }
}
