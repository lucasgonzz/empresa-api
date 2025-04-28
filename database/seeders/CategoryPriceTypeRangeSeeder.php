<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryPriceTypeRange;
use App\Models\PriceType;
use Illuminate\Database\Seeder;

class CategoryPriceTypeRangeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = Category::where('user_id', env('USER_ID'))
                                ->get();

        $this->price_types = PriceType::where('user_id', env('USER_ID'))
                                ->orderBy('position', 'DESC')
                                ->get();

        foreach ($categories as $category) {
            
            $min = 1;
            $max = 6;

            $index = 1;

            foreach ($this->price_types as $price_type) {
                
                $index++;
                
                CategoryPriceTypeRange::create([
                    'category_id'   => $category->id,
                    'price_type_id' => $price_type->id,
                    'min'           => $min,
                    'max'           => $max,
                    'user_id'       => env('USER_ID'),
                ]);

                $min += 6;

                if ($index == count($this->price_types)) {
                    
                    $max = null;

                } else {

                    $max += 6;
                }

            }

            $this->crear_sub_categories($category); 
        }
    }

    function crear_sub_categories($category) {


        foreach ($category->sub_categories as $sub_category) {

            $min = 1;
            $max = 10;

            $index = 1;
            
            foreach ($this->price_types as $price_type) {

                $index++;

                CategoryPriceTypeRange::create([
                    'category_id'       => $category->id,
                    'sub_category_id'   => $sub_category->id,
                    'price_type_id'     => $price_type->id,
                    'min'               => $min,
                    'max'               => $max,
                    'user_id'           => env('USER_ID'),
                ]);
                                
                $min += 10;

                if ($index == count($this->price_types)) {
                    
                    $max = null;

                } else {

                    $max += 10;
                }
            }
        }
    }
}
