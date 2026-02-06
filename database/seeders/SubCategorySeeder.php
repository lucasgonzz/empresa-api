<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\SubCategory;

class SubCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->truvari();

        $this->default();
    }

    function truvari() {

        if (config('app.FOR_USER') != 'truvari') {
            return;
        }
        
        $categories = Category::where('user_id', config('app.USER_ID'))
                                ->get();

        require('subcategories/truvari.php');

        foreach ($sub_categories as $category) {
            $num = 1;
            foreach ($category['sub_categories'] as $sub_category) {
                $sub_category = SubCategory::create([
                    'num'           => $num,
                    'name'          => $sub_category,
                    'category_id'   => $category['category_id'],
                    'user_id'       => config('app.USER_ID'),
                ]);         
                $num++;
            }
        }
    }

    function default() {

        if (config('app.FOR_USER') == 'truvari') {
            return;
        }
        
        $categories = Category::where('user_id', config('app.USER_ID'))
                                ->get();

        require('subcategories/supermercado.php');

        foreach ($sub_categories as $category) {
            $num = 1;
            foreach ($category['sub_categories'] as $sub_category) {
                $sub_category = SubCategory::create([
                    'num'           => $num,
                    'name'          => $sub_category,
                    'category_id'   => $category['category_id'],
                    'user_id'       => config('app.USER_ID'),
                ]);         
                $num++;
            }
        }
    }
}
 