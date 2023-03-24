<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Discount;
use App\Http\Controllers\Controller;

class DiscountHelper extends Controller {

    static function getDiscountsFromDiscountsId($discounts_id) {
        $discounts = [];
        foreach ($discounts_id as $discount_id) {
            $discounts[] = Discount::find($discount_id);
        }
        return $discounts;
    }

    static function getTotalDiscountsPercentage($discounts, $from_pivot = false) {
        $discounts_percentage = 0;
        foreach ($discounts as $discount) {
            if ($from_pivot) {
                $percentage = $discount->pivot->percentage;
            } else {
                $percentage = $discount->percentage;
            }
            $discounts_percentage += $percentage;
        }
        return $discounts_percentage;
    }

}

