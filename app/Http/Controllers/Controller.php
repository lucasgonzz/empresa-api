<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\User;
use App\Notifications\AddedModel;
use App\Notifications\AddedModelNow;
use App\Notifications\DeletedModel;
use App\Notifications\UpdateModels;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function userId($from_owner = true, $user_id = null) {
        if (!is_null($user_id)) {
            return $user_id;
        }

        if (session()->has('auth_user')) {

            $user = session('auth_user');
        } else {

            $user = Auth()->user();
        }
        
        if (is_null($user)) {
            $user = User::where('company_name', 'Autopartes Boxes')->first();
            return $user->id;
        }
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

    function user($from_owner = true) {
        if (session()->has('auth_user')) {
            
            if ($from_owner) {
                return session('owner');
            }
            return session('auth_user');
        }
        return User::find($this->userId($from_owner));
    }

    function is_owner() {
        $owner_id = $this->userId();
        $current_user_id = $this->userId(false);
        return $owner_id == $current_user_id;
    }

    function is_admin() {
        $user = $this->user(false);
        if (is_null($user->owner_id) || $user->admin_access) {
            return true;
        }
        return false;
    }

    function getModelBy($table, $prop_name, $prop_value, $from_user = false, $prop_to_return = null, $return_0 = false, $user_id = null) {
        $model = DB::table($table)
                    ->where($prop_name, $prop_value);
        if ($from_user) {
            if (is_null($user_id)) {
                $user_id = $this->userId();
            }
            $model = $model->where('user_id', $user_id);
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

    function setEnvironmentValue($environmentName, $configKey, $newValue) {
        file_put_contents(App::environmentFilePath(), str_replace(
            $environmentName . '=' . Config::get($configKey),
            $environmentName . '=' . $newValue,
            file_get_contents(App::environmentFilePath())
        ));

        Config::set($configKey, $newValue);

        // Reload the cached config       
        if (file_exists(App::getCachedConfigPath())) {
            Artisan::call("config:cache");
        }
    }

    function updateRelationsCreated($model_name, $model_id, $childrens) {
        if (isset($childrens)) {
            Log::info('Hay childrens para '.$model_name);
            foreach ($childrens as $children) {
                if (isset($children['is_imageable'])) {
                    Log::info('El children es image');
                    $relation_model = GeneralHelper::getModelName('Image')::where('imageable_id', null)
                                                                            ->where('temporal_id', $children['temporal_id'])
                                                                            ->first();
                    if (!is_null($relation_model)) {
                        Log::info('Se actualizo '.$children['model_name'].' con el temporal_id de '.$children['temporal_id'].' con '.$model_name.'_id de '.$model_id);
                        $relation_model->imageable_id = $model_id;
                        $relation_model->temporal_id = null;
                        $relation_model->save();
                    }

                } else {
                    $relation_model = GeneralHelper::getModelName($children['model_name'])::where($model_name.'_id', null)
                                                                            ->where('temporal_id', $children['temporal_id'])
                                                                            ->first();
                    if (!is_null($relation_model)) {
                        Log::info('Se actualizo '.$children['model_name'].' con el temporal_id de '.$children['temporal_id'].' con '.$model_name.'_id de '.$model_id);
                        $relation_model->{$model_name.'_id'} = $model_id;
                        $relation_model->temporal_id = null;
                        $relation_model->save();
                    }
                }
            }
        }
    }

    function getTemporalId($request) {
        if (is_null($request->model_id)) {
            return time().rand(0, 9999);
        }
        return null;
    }

    function get_model_id($request, $prop_key) {
        if (isset($request->model_id) 
            && !is_null($request->model_id)) {
            return $request->model_id;
        }

        if (isset($request->{$prop_key}) 
            && !is_null($request->{$prop_key})) {
            return $request->{$prop_key};
        }

        return null;
    }

    function sendAddModelNotification($model_name, $model_id, $check_added_by = true, $for_user_id = null, $ignore_queue = false) {

        if ($model_name == 'article'
        && !$this->user()->download_articles) {
            return;
        }

        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }

        if ($ignore_queue) {
    
            Auth()->user()->notify(new AddedModelNow($model_name, $model_id, $check_added_by, $for_user_id));

            Log::info('se mando notificacion DIRECTO a '.$model_name);
        } else {
            Auth()->user()->notify(new AddedModel($model_name, $model_id, $check_added_by, $for_user_id));
            Log::info('se mando notificacion por queue a '.$model_name);
        }



    }

    function sendDeleteModelNotification($model_name, $model_id, $check_added_by = true, $for_user_id = null) {
        if (is_null($for_user_id)) {
            $for_user_id = $this->userId();
        }
        Auth()->user()->notify(new DeletedModel($model_name, $model_id, $check_added_by, $for_user_id));
    }

    function sendUpdateModelsNotification($model_name, $check_added_by = true, $for_user = null) {
        if (is_null($for_user)) {
            $for_user = $this->user();
        }
        $for_user->notify(new UpdateModels($model_name, $check_added_by, $for_user->id));
        // Auth()->user()->notify(new UpdateModels($model_name, $check_added_by, $for_user_id));
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

    function createIfNotExist($table, $prop_name, $prop_value, $data_to_insert, $from_user = true, $user_id = false) {

        $model = DB::table($table)
                    ->where($prop_name, $prop_value);
        if ($from_user) {
            if (!$user_id) {
                $user_id = $this->userId();
            }
            $model = $model->where('user_id', $user_id);
        }
        $model = $model->first();
        

        if (is_null($model)) {
            DB::table($table)->insert($data_to_insert);
        }
    }

    function fullModel($model_name, $id) {
        $model_name = GeneralHelper::getModelName($model_name);
        $model = $model_name::where('id', $id)
                        ->withAll();
        if ($model_name == 'App\Models\Sale') {
            $model = $model->withTrashed();
        }
        $model = $model->first();
        return $model;
    }
}
