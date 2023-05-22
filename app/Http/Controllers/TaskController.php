<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TaskController extends Controller
{

    function index() {
        $user_id = $this->userId(false);
        $models = Task::whereHas('to_users', function(Builder $query) use ($user_id) {
                            $query->where('user_id', $user_id);
                        })
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if ($this->userId() == $this->userId(false)) {
            // Significa que es el dueño
            $models = $models->where('user_id', $this->userId());
        }
        $models = $models->orWhere('from_user_id', $this->userId(false))
                        ->get();
        return response()->json(['models' => $models], 200);
    }

    function show($id) {
        return response()->json(['model' => $this->fullModel('Task', $id)], 200);
    }

    function store(Request $request) {
        $model = Task::create([
            'user_id'       => $this->userId(),
            'from_user_id'  => $this->userId(false),
            'content'       => $request->content,
        ]);
        foreach ($request->to_users as $to_user) {
            $model->to_users()->attach($to_user);
        }
        $this->sendAddModelNotification('task', $model->id);
        return response()->json(['model' => $this->fullModel('task', $model->id)], 200);
    }

    function finish(Request $request, $id) {
        $model = Task::find($id);
        $model->to_users()->updateExistingPivot($this->userId(false), [
                                'is_finished'   => 1,
                            ]);
        $this->sendAddModelNotification('task', $model->id);
        return response()->json(['model' => $this->fullModel('task', $model->id)], 200);
    }
}
