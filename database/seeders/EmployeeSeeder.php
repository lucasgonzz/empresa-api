<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->colman();
    }

    function colman() {
        $user = User::where('company_name', 'colman')->first();
        $models = [
            [
                'name'      => 'Franco',
                'dni'       => '123',
                'password'  => bcrypt('123'),
                'visible_password'  => '123',
                'type'      => $user->type,
                'owner_id'  => $user->id,
            ],
            [
                'name'      => 'Matias',
                'dni'       => '1234',
                'type'      => $user->type,
                'owner_id'  => $user->id,
                'password'  => bcrypt('1234'),
                'visible_password'  => '1234',
            ],
        ];
        foreach ($models as $model) {
            $employee = User::create($model);
            for ($permission_id = 1; $permission_id < 20; ++$permission_id) {
                $employee->permissions()->attach($permission_id);
            }
        }
    }
}
