<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Category;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Seeder;

class SellerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $this->fenix();

        $this->golo_norte();

        $this->truvari();

    }

    function truvari() {


        if (env('FOR_USER') != 'truvari') {
            return;
        }

        $employees = User::where('owner_id', env('USER_ID'))
                            ->get();

        $num = 0;
        
        foreach ($employees as $employee) {
        
            $num++;
        
            $seller = Seller::create([
                'num'               => $num,
                'name'              => $employee->name,
                'user_id'           => $employee->owner_id,
                'commission_after_pay_sale' => 0,
            ]);
            $employee->seller_id = $seller->id;
            $employee->save();
        }

    }

    function golo_norte() {


        if (env('FOR_USER') != 'golo_norte') {
            return;
        }

        $models = [
            [
                'num'           => 1,
                'name'          => 'Vendedor 1',
                'categories'    => [
                    'Almacen'       => 10,
                    'Gaseosas'      => 20,
                ],
            ],
            [
                'num'           => 2,
                'name'          => 'Vendedor 2',
                'categories'    => [
                    'Almacen'       => 50,
                    'Gaseosas'      => 100,
                ],
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            $seller = Seller::create([
                'num'               => $model['num'],
                'name'              => $model['name'],
                'user_id'           => $model['user_id']
            ]);

            $this->set_categories($seller, $model['categories']);

        }
    }

    function set_categories($seller, $categories) {
        foreach ($categories as $category_name => $percetage) {
            $cateogry_model = Category::where('name', $category_name)->first();
            $seller->categories()->attach($cateogry_model->id, [
                'percentage'    => $percetage
            ]);
        }
    }

    function fenix() {

        if (env('FOR_USER') != 'fenix') {
            return;
        }

        $models = [
            [
                'num'                       => 1,
                'name'                      => 'Marcelo',
                'commission_after_pay_sale' => 1,
                'user_id'                   => env('USER_ID'),
            ],
            [
                'num'                       => 2,
                'name'                      => 'Matias',
                'commission_after_pay_sale' => 1,
                'user_id'                   => env('USER_ID'),
            ],
            [
                'num'                       => 3,
                'name'                      => 'Oscar (perdidas)',
                'commission_after_pay_sale' => 0,
                'user_id'                   => env('USER_ID'),
            ],
            [
                'num'                       => 4,
                'name'                      => 'Oscar',
                'commission_after_pay_sale' => 0,
                'user_id'                   => env('USER_ID'),
            ],
            [
                'num'                       => 5,
                'name'                      => 'Fede',
                'commission_after_pay_sale' => 0,
                'user_id'                   => env('USER_ID'),
            ],
            [
                'num'                       => 6,
                'name'                      => 'Papi',
                'commission_after_pay_sale' => 0,
                'user_id'                   => env('USER_ID'),
            ],
        ];

        foreach ($models as $model) {
            Seller::create($model);
        }
    }
}
