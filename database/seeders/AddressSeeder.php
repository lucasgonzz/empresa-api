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
        if (Address::where('user_id', config('app.USER_ID'))->exists()) {
            return;
        }

        if (config('app.FOR_USER') == 'bad_girls') {
            $this->bad_girls();
        } else {
            $this->default();
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
