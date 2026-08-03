<?php

namespace Database\Seeders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Article;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;


class ProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->oscar();
        // $this->matias();
    }

    function oscar() {
        $ct = new Controller();
        $models = [
            [
                'num'               => 1,
                'name'              => 'Buenos Aires',
                'percentage_gain'   => 100,
                'dolar'             => 1200,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => 100,
                'porcentaje_comision_negro' => 10,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 2,
                'name'              => 'Rosario',
                'percentage_gain'   => null,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => 1200,
                'porcentaje_comision_negro' => 7,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 3,
                'name'              => 'Proveedor S.A',
                'percentage_gain'   => 0,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'porcentaje_comision_negro' => 6,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 1,
                'name'              => 'Autopartes Boxes',
                'percentage_gain'   => 50,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => 2,
                'comercio_city_user_id' => 1,
            ],
            // Proveedores 4 a 10 agregados para la semilla de datos de prueba de reportes
            // (grupo 321). `saldo` no se escribe a mano (queda null): lo produce el sembrador
            // con movimientos reales de pago a proveedor.
            [
                'num'               => 4,
                'name'              => 'Distribuidora Norte',
                'percentage_gain'   => 80,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 5,
                'name'              => 'Ferretera del Sur S.A.',
                'percentage_gain'   => 90,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 6,
                'name'              => 'Materiales San Jorge',
                'percentage_gain'   => 70,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 7,
                'name'              => 'Insumos Industriales Rio',
                'percentage_gain'   => 100,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 8,
                'name'              => 'Aceros Litoral',
                'percentage_gain'   => 60,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 9,
                'name'              => 'Distribuidora Central',
                'percentage_gain'   => 85,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
            [
                'num'               => 10,
                'name'              => 'Bulones y Tornillos SRL',
                'percentage_gain'   => 75,
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
                'saldo'             => null,
                'porcentaje_comision_negro' => 8,
                'porcentaje_comision_blanco' => 5,
            ],
        ];
        foreach ($models as $model) {
            $provider = Provider::create($model);

            CreditAccountHelper::crear_credit_accounts('provider', $provider->id);
        }
    }

    function matias() {
        $user = User::where('company_name', 'Ferretodo')->first();
        $lucas_user = User::where('company_name', 'Autopartes Boxes')->first();
        $lucas_provider = Provider::create([
            'num'                   => 1,
            'name'                  => 'Oscar Peroni',
            'comercio_city_user_id' => $lucas_user->id,
            'user_id'               => config('app.USER_ID'),
            'percentage_gain'       => 10,
        ]);
    }

    function la_barraca() {
        $ct = new Controller();
        $user = User::where('company_name', 'la barraca')->first();
        $models = [
            [
                'name'              => 'Buenos Aires',
                'percentage_gain'   => 50,
            ],
            [
                'name'              => 'Rosario',
                'percentage_gain'   => 100,
            ],
        ];
        foreach ($models as $model) {
            Provider::create([
                'num'               => $ct->num('providers', config('app.USER_ID')), 
                'name'              => $model['name'],
                'percentage_gain'   => $model['percentage_gain'],
                'email'             => 'lucasgonzalez5500@gmail.com',
                'address'           => 'Calle 123',
                'user_id'           => config('app.USER_ID'),
            ]);
        }
    }
}
