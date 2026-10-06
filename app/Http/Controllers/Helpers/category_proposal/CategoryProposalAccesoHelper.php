<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Http\Controllers\Helpers\MostradorHelper;

/**
 * Quién puede gestionar las propuestas de categorías desde el SPA (misión categorizacion-tres-modelos,
 * 5/10/2026).
 *
 * Elegir un sistema de categorías, volver atrás y aprobar o rechazar dudosos cambian el catálogo
 * ENTERO de un comercio, así que solo lo puede hacer el dueño o la sesión del acceso maestro de
 * ComercioCity. Un empleado, aunque tenga `admin_access`, NO.
 */
class CategoryProposalAccesoHelper
{
    /**
     * ¿La sesión actual es la del dueño o la del acceso maestro?
     *
     * 🔴 No usar `UserHelper::puede_ver()` ni el middleware `solo_administrador`: los dos dejan pasar
     * a un empleado con `admin_access`, que acá no corresponde. El acceso maestro no es un tipo de
     * usuario: autentica como un User real y deja una clave en la sesión
     * (ImageAssignmentRunHelper::CLAVE_DE_SESION_MAESTRO), por eso se mira aparte de `es_el_dueno()`.
     *
     * @return bool
     */
    public static function puede_gestionar()
    {
        return MostradorHelper::es_el_dueno() || ImageAssignmentRunHelper::es_acceso_maestro();
    }
}
