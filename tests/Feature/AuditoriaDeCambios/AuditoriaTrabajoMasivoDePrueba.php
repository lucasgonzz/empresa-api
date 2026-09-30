<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\Article;
use App\Models\BackgroundProcess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Job de prueba que hace lo que hace una operación masiva: guarda varios artículos y abre y
 * actualiza su propio registro de proceso. Se declara acá y se mete en `jobs_masivos` por
 * `config()` dentro del test, para probar el MECANISMO (eventos de la cola, silencio y cierre)
 * sin depender de la lógica interna de un job real.
 */
class AuditoriaTrabajoMasivoDePrueba implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cantidad de artículos que guarda.
     *
     * @var int
     */
    public $cantidad;

    /**
     * @var int
     */
    public $user_id;

    /**
     * @param int $user_id
     * @param int $cantidad
     */
    public function __construct($user_id, $cantidad)
    {
        $this->user_id = $user_id;
        $this->cantidad = $cantidad;
    }

    /**
     * @return void
     */
    public function handle()
    {
        // La fila de la operación: se abre y se va actualizando (progreso).
        $proceso = BackgroundProcess::create([
            'user_id' => $this->user_id,
            'tipo'    => 'prueba_auditoria',
            'titulo'  => 'Proceso de prueba de auditoría',
            'uuid'    => (string) Str::uuid(),
        ]);

        for ($i = 1; $i <= $this->cantidad; $i++) {

            $articulo = new Article();
            $articulo->user_id = $this->user_id;
            $articulo->name    = 'ZZ Auditoria masivo ' . $i;
            $articulo->status  = 'active';
            $articulo->iva_id  = 2;
            $articulo->save();

            // Cada cambio de cada artículo, y un avance del proceso.
            $articulo->cost = 10 * $i;
            $articulo->save();

            $proceso->procesados = $i;
            $proceso->save();
        }
    }
}
