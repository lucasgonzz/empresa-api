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
     * Cantidad de reintentos que hace la queue antes de darse por vencida y llamar a failed().
     * Con QUEUE_CONNECTION=sync corre en el mismo request, pero declararlo no rompe nada y sirve
     * si en algún momento se cambia a una queue real.
     *
     * @var int
     */
    public $tries = 3;


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
     * Execute the job: manda el mail de aviso y borra la fila SOLO si el envío fue exitoso.
     *
     * Todo el cuerpo va dentro de un try/catch: con QUEUE_CONNECTION=sync este Job corre en el
     * mismo request que ingresa el stock del ERP, así que una excepción acá NUNCA puede
     * propagarse hacia arriba (rompería la operación de stock, que es el bug raíz que este
     * prompt viene a corregir).
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Revalido el email por las dudas: checkAdvises ya filtra, pero este Job puede
            // llegar a ser despachado desde otro lado en el futuro.
            $email = trim((string) $this->advise->email);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                Log::warning('ProcessSendAdviseMail: email invalido, se borra el advise', [
                    'advise_id' => $this->advise->id,
                    'email'     => $email,
                ]);
                $this->advise->delete();
                return;
            }

            if (!env('SEND_MAILS', false)) {
                // Mails deshabilitados: no se manda ni se borra, queda pendiente.
                Log::info('ProcessSendAdviseMail: SEND_MAILS en false, no se envia ni se borra', [
                    'advise_id' => $this->advise->id,
                ]);
                return;
            }

            // Si send() tira excepción, el catch de abajo la atrapa y NO se borra la fila.
            Mail::to($email)->send(new AdviseMail($this->article));

            // Recien acá, con el mail mandado con éxito, se borra el aviso.
            $this->advise->delete();

            Log::info('Se elimino advise a '.$email);
        } catch (\Exception $e) {
            // No se borra el advise: queda pendiente para el próximo ingreso de stock.
            // No se relanza la excepción: con QUEUE_CONNECTION=sync eso rompería la operación
            // de stock del ERP que disparó este Job.
            Log::error('ProcessSendAdviseMail: error enviando mail, advise queda pendiente', [
                'advise_id' => isset($this->advise->id) ? $this->advise->id : null,
                'email'     => isset($this->advise->email) ? $this->advise->email : null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Se ejecuta cuando el Job agota los reintentos ($tries) sin éxito.
     * Solo loguea el fallo definitivo; no borra el advise (queda pendiente).
     *
     * @param \Exception $e Excepción que causó el fallo final.
     * @return void
     */
    public function failed(\Exception $e)
    {
        Log::error('ProcessSendAdviseMail: fallo definitivo tras agotar reintentos', [
            'advise_id' => isset($this->advise->id) ? $this->advise->id : null,
            'error'     => $e->getMessage(),
        ]);
    }
}
