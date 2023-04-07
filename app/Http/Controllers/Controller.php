<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
// use App\Http\Controllers\Helpers\GeneralHelper;
use App\Notifications\AddedModel;
use App\Notifications\DeletedModel;
use App\Notifications\UpdateModels;
use App\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function userId($from_owner = true, $user_id = null) {
        if (!is_null($user_id)) {
            return $user_id;
        }
        $user = Auth()->user();
        if ($from_owner) {
            if (is_null($user->owner_id)) {
                return $user->id;
            } else {
                return $user->owner_id;
            }
        } else {
            return $user->id;
        }
    }

    function user() {
        return User::find($this->userId());
    }

    function getModelBy($table, $prop_name, $prop_value, $from_user = false, $prop_to_return = null, $return_0 = false) {
        $model = DB::table($table)
                    ->where($prop_name, $prop_value);
        if ($from_user) {
            $model = $model->where('user_id', $this->userId());
        }
        $model = $model->first();
        if (!is_null($model) && !is_null($prop_to_return)) {
            return $model->{$prop_to_return};
        } 
        if ($return_0) {
            return 0;
        }
        return $model;
    }

    function updateRelationsCreated($model_name, $model_id, $relations) {
        foreach ($relations as $relation) {
            $relation_models = GeneralHelper::getModelName($relation)::where($model_name.'_id', null)
                                                                    ->get();
            foreach ($relation_models as $relation_model) {
                $relation_model->{$model_name.'_id'} = $model_id;
                $relation_model->save();
            }
        }
    }

    function sendAddModelNotification($model_name, $model_id, $check_added_by = true, $for_user_id = null) {
        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }
        Auth()->user()->notify(new AddedModel($model_name, $model_id, $check_added_by, $for_user_id));
    }

    function sendDeleteModelNotification($model_name, $model_id, $check_added_by = true, $for_user_id = null) {
        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }
        Auth()->user()->notify(new DeletedModel($model_name, $model_id, $check_added_by, $for_user_id));
    }

    function sendUpdateModelsNotification($model_name, $check_added_by = true, $for_user_id = null) {
        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }
        Auth()->user()->notify(new UpdateModels($model_name, $check_added_by, $for_user_id));
    }


    function num($table, $user_id = null, $prop_to_check = 'user_id', $prop_value = null) {
        if (is_null($prop_value)) {
            $prop_value = $this->userId(true, $user_id);
        }
        $last = DB::table($table)
                    ->where($prop_to_check, $prop_value)
                    ->orderBy('num', 'DESC')
                    ->first();
        if (is_null($last) || is_null($last->num)) {
            return 1;
        }
        return $last->num + 1;
    }

    function createIfNotExist($table, $prop_name, $prop_value, $data_to_insert, $from_user = true) {
        $model = DB::table($table)
                    ->where($prop_name, $prop_value);
        if ($from_user) {
            $model = $model->where('user_id', $this->userId());
        }
        $model = $model->first();
        if (is_null($model)) {
            DB::table($table)->insert($data_to_insert);
        }
    }

    function fullModel($model_name, $id) {
        $model_name = GeneralHelper::getModelName($model_name);
        $model = $model_name::where('id', $id)
                        ->withAll()
                        ->first();
        return $model;
    }
}
