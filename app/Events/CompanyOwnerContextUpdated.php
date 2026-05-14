<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa a todas las pestañas/sesiones del mismo comercio (canal por owner_id)
 * que otro usuario guardó cambios en `users`, para que refresquen `/api/user`
 * y mantengan `user.owner` alineado con el backend.
 */
class CompanyOwnerContextUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /** @var int Id del usuario dueño del comercio (mismo criterio que `owner_id` en el front). */
    public $company_owner_id;

    /** @var int Id del usuario que ejecutó el guardado (se excluye en el cliente). */
    public $updated_by_user_id;

    /**
     * Líneas de texto (español) con el detalle de cambios para el modal en otras sesiones.
     *
     * @var array<int, string>
     */
    public $change_descriptions;

    /**
     * @param int $company_owner_id Dueño de la cuenta (`user.owner_id` o `user.id` del owner).
     * @param int $updated_by_user_id Usuario autenticado que persistió el cambio.
     * @param array<int, string> $change_descriptions Detalle legible de cada cambio.
     */
    public function __construct(int $company_owner_id, int $updated_by_user_id, array $change_descriptions)
    {
        $this->company_owner_id = $company_owner_id;
        $this->updated_by_user_id = $updated_by_user_id;
        $this->change_descriptions = $change_descriptions;
    }

    /**
     * Canal público compartido con `GlobalNotification` (misma convención que el Echo del SPA).
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn()
    {
        return [
            new Channel('global_notification.'.$this->company_owner_id),
        ];
    }

    /**
     * Nombre corto del evento para `Echo.channel(...).listen('.CompanyOwnerContextUpdated', ...)`.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'CompanyOwnerContextUpdated';
    }

    /**
     * Payload mínimo para el cliente.
     *
     * @return array{updated_by_user_id:int,change_descriptions:array<int,string>}
     */
    public function broadcastWith()
    {
        return [
            'updated_by_user_id' => $this->updated_by_user_id,
            'change_descriptions' => $this->change_descriptions,
        ];
    }
}
