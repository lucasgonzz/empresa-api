<?php

namespace Database\Seeders;

use App\Models\Title;
use App\Models\User;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            [
                'num'       => 1,
                'user_id'   => $user->id,
                'header'    => null,
                'lead'      => null,
                'color'     => '#f9b234',
                'image_url' => 'http://empresa.local:8000/storage/cubo.jpeg',
            ],
            [
                'num'       => 2,
                'user_id'   => $user->id,
                'header'    => null,
                'lead'      => null,
                'color'     => '#f9b234',
                'image_url' => 'http://empresa.local:8000/storage/cubo.jpeg',
            ],
        ];
        foreach ($models as $title) {
            Title::create([
                'num'               => $title['num'],
                'user_id'           => $title['user_id'],
                'header'            => $title['header'],
                'lead'              => $title['lead'],
                'color'             => $title['color'],
                'image_url'         => $title['image_url'],
            ]);
        }
    }
}
