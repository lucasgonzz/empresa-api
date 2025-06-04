<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Mail;
use App\Mail\Advise as AdviseMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSendAdviseMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $advise;
    public $article;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($advise, $article)
    {
        $this->advise = $advise;
        $this->article = $article;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        Mail::to($this->advise->email)->send(new AdviseMail($this->article));
        $this->advise->delete();

        Log::info('Se elimino advise a '.$this->advise->email);
    }
}
