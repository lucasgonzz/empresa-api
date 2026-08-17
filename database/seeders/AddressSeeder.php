<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;

class AddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        if (config('app.FOR_USER') == 'fenix') {
            return;
        }

        // Guarda contra el doble llamado: local_y_demo() ahora llama a este seeder para
        // sembrar las sucursales que necesitan las cajas y las ventas de la semilla de
        // reportes, y varias ramas por FOR_USER (bad_girls, trama, racing_carts, leudinox,
        // san_blas, ht5) YA lo llamaban por su cuenta, después de local_y_demo(). Sin este
        // guard, esas cuentas terminarían con 8 sucursales en vez de 4.
        //
        // Lo que cambió: antes se salía de largo. Ahora se completa lo que les falte a las
        // sucursales que ya están, que es lo único que el guard nunca tuvo que impedir.
        if (Address::where('user_id', config('app.USER_ID'))->exists()) {
            $this->completar_sucursales_existentes();
            return;
        }

        if (config('app.FOR_USER') == 'bad_girls') {
            $this->bad_girls();
        } else {
            $this->default();
        }
    }

    /**
     * Completa lo que les falta a las sucursales que YA existen, en vez de salir de largo.
     *
     * POR QUÉ HACE FALTA: `DemoSetupHelper::run()` llama a `crear_depositos()` cuando el lead de
     * la demo marcó `use_deposits`, y eso pasa ANTES del loop de seeders. Ese método crea 1 a 3
     * Address con nada más que `street` y `user_id`. Cuando después corría este seeder, el guard
     * de arriba veía que ya había una sucursal y se iba sin hacer nada, y la demo quedaba con dos
     * agujeros:
     *
     *  - NINGUNA sucursal con `es_deposito_origen = 1`. `StockSuggestionService::obtenerOrigen()`
     *    arma `$designados_con_stock` filtrando por esa columna y, si queda vacío, cae directo al
     *    escalón 3 (el comportamiento histórico). O sea que el depósito de origen preferente --
     *    lo único que esta misión vino a habilitar acá -- no se llegaba a ver justo en la demo
     *    que lo pidió.
     *
     *  - `num` en NULL en todas. `SembrarDatosDePrueba::cargar_catalogo()` hace
     *    `Address::where('user_id', ...)->orderBy('num')->get()`, y de ESE orden salen el reparto
     *    del efectivo por sucursal (`REPARTO_SUCURSAL`, 40/30/20/10) y la caja de cada cobro. Con
     *    la columna toda nula el orden lo decide MySQL, así que qué sucursal se lleva el 40%
     *    cambia entre corridas y se pierde la reproducibilidad que el `mt_srand()` de semilla fija
     *    existe para garantizar.
     *
     * LO QUE NO HACE, A PROPÓSITO: no crea ni una sucursal. El guard existe para que las ramas por
     * FOR_USER (bad_girls, trama, racing_carts, leudinox, san_blas, ht5), que llaman a este seeder
     * una segunda vez después de `local_y_demo()`, no terminen con 8 en vez de 4 -- y a bad_girls,
     * que tiene 2 a propósito, completarle hasta 4 le cambiaría la cuenta igual. Rellenar columnas
     * de las que ya están no duplica nada; crear filas sí.
     *
     * @return void
     */
    protected function completar_sucursales_existentes()
    {
        $addresses = Address::where('user_id', config('app.USER_ID'))
                        ->orderBy('id')
                        ->get();

        // Los num ya ocupados, para no repetir ninguno: con dos sucursales en el mismo num el
        // orden de `orderBy('num')` vuelve a quedar a criterio de MySQL, que es justo el problema
        // que este método viene a cerrar.
        $ocupados = [];
        foreach ($addresses as $address) {
            if (!is_null($address->num)) {
                $ocupados[] = (int) $address->num;
            }
        }

        // Se numeran por `id`, o sea por orden de creación: para las de `crear_depositos()` eso es
        // exactamente el orden en que el formulario de la demo mandó address_1, address_2 y
        // address_3, que es el que la persona que cargó el lead espera ver.
        $siguiente = 1;
        foreach ($addresses as $address) {
            if (!is_null($address->num)) {
                continue;
            }

            while (in_array($siguiente, $ocupados, true)) {
                $siguiente++;
            }

            $address->num = $siguiente;
            $address->save();
            $ocupados[] = $siguiente;
        }

        // Si ya hay alguna designada no se toca ninguna: `AddressController::update()` deja marcar
        // varias y no valida unicidad, así que una elección hecha a mano en el sistema no se pisa
        // desde un seeder. Lo que este método garantiza es que nunca queden CERO designadas, que
        // es el caso que apagaba el escalón 1 del motor de traslados.
        $hay_designada = Address::where('user_id', config('app.USER_ID'))
                            ->where('es_deposito_origen', 1)
                            ->exists();

        if ($hay_designada) {
            return;
        }

        // La de num más bajo, igual que hace `default()` con la sucursal 1: es la que se lleva el
        // 40% del efectivo en `REPARTO_SUCURSAL` y la que más stock mueve, así que es la que tiene
        // sentido que oficie de depósito de origen.
        $origen = Address::where('user_id', config('app.USER_ID'))
                    ->orderBy('num')
                    ->first();

        if (!is_null($origen)) {
            $origen->es_deposito_origen = 1;
            $origen->save();
        }
    }

    function bad_girls() {

        $models = [
            [
                'num'       => 1,
                'street'    => 'Arriba',
                'user_id'   => config('app.USER_ID'),
            ],
            [
                'num'       => 2,
                'street'    => 'Abajo',
                'default_address'    => 1,
                'user_id'   => config('app.USER_ID'),
            ],
        ];
        foreach ($models as $model) {
            Address::create($model);
        }
    }

    function default() {

        $models = [
            [
                'num'       => 1,
                'street'    => 'Tucuman',
                'user_id'   => config('app.USER_ID'),
                // Deposito de origen preferente para las sugerencias de traslado de stock: sin
                // al menos una sucursal marcada, StockSuggestionService::obtenerOrigen() nunca
                // entra en el escalon 1 (designados sin deficit) y cae directo al comportamiento
                // historico, asi que la demo no llega a mostrar el escalonado por origen preferente.
                'es_deposito_origen' => 1,
            ],
            [
                'num'       => 2,
                'street'    => 'Santa Fe',
                'default_address'    => 1,
                'user_id'   => config('app.USER_ID'),
            ],
            [
                'num'       => 3,
                'street'    => 'Buenos Aires',
                'default_address'    => 1,
                'user_id'   => config('app.USER_ID'),
            ],
            [
                'num'       => 4,
                'street'    => 'Mar del Plata',
                'default_address'    => 1,
                'user_id'   => config('app.USER_ID'),
            ],
        ];
        foreach ($models as $model) {
            Address::create($model);
        }

        $employees = User::whereNotNull('owner_id')->get();

        $address_id = 1;
        foreach ($employees as $employee) {
            $employee->address_id = $address_id;
            $employee->save();
            $address_id++;
        }
    }
}
