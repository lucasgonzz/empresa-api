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
        $users = User::where('company_name', 'Autopartes Boxes')
                        ->orWhere('company_name', 'Matias Mayorista')
                        ->get();
        $models = [
            [
                'num'       => 1,
                'color'     => '#333',
                'image_url' => 'http://empresa.local:8000/storage/banner.jpg',
                'crop_image_url' => 'http://empresa.local:8000/storage/banner_mobile.jpg',
            ],
            [
                'num'       => 2,
                'text_color'=> null,
                'image_url' => 'http://empresa.local:8000/storage/banner2.jpg',
                'crop_image_url' => 'http://empresa.local:8000/storage/banner_mobile2.jpg',
            ],
        ];
        foreach ($users as $user) {
            foreach ($models as $title) {
                $title['user_id'] = $user->id;
                Title::create($title);
            }
        }
    }
}
