<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class set_user_activity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'set_user_activity {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $user_id = config('app.USER_ID');
        if ($this->argument('user_id')) {
            $user_id = $this->argument('user_id');
        }

        $user = User::find($user_id);

        $user->activity_minutes = 15;
        $user->timestamps = false;
        $user->save();
        $this->info('Listo :)');
        return 0;
    }
}
