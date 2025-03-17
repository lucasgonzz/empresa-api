<?php

namespace App\Notifications;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class UpdateModels extends Notification {
    use Queueable;

    public $model_name;
    public $check_added_by;
    public $for_user_id;

    public function __construct($model_name, $check_added_by, $for_user_id) {
        $this->model_name = $model_name;
        $this->check_added_by = $check_added_by;
        $this->for_user_id = $for_user_id;
    }

    public function via($notifiable) {
        return ['broadcast'];
    }

    public function broadcastOn() {
        return 'update_models.'.$this->for_user_id;
    }

    public function toBroadcast($notifiable) {
        Log::info('enviando update_models.'.$this->for_user_id);
        return new BroadcastMessage([
            'model_name'        => $this->model_name,
            'added_by'          => UserHelper::userId(false),
            'check_added_by'    => $this->check_added_by,
        ]);
    }
}
