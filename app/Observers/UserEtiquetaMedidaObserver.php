<?php

namespace App\Observers;

use App\Http\Controllers\Helpers\EtiquetaMedidaHelper;
use App\Models\User;

/**
 * Crea medidas de etiqueta predeterminadas al registrar un usuario nuevo.
 */
class UserEtiquetaMedidaObserver
{
    /**
     * Tras crear un usuario dueño (owner_id null), inserta medidas predeterminadas.
     * Los empleados no reciben filas propias: usan las del owner en la API.
     *
     * @param User $user
     *
     * @return void
     */
    public function created(User $user)
    {
        if (!is_null($user->owner_id)) {
            return;
        }

        EtiquetaMedidaHelper::seed_defaults_for_user($user->id);
    }
}
