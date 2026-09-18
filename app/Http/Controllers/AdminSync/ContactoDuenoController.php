<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Contacto del dueño del comercio para que admin-api pueda avisarle cuando le
 * actualiza el sistema (misión aviso-de-actualizacion-al-cliente).
 *
 * POR QUÉ EXISTE Y NO SE REUSÓ OTRO ENDPOINT. El único endpoint de este canal que
 * hoy devuelve `email` es `admin-sync/mostrador/duenos`, pero filtra por
 * `whereHas('extencions', slug = asistente_ia)`: para un cliente sin esa extensión
 * —que son la mayoría— devuelve la lista vacía. `branding/{user_id?}` y
 * `mensualidad-info/{user_id?}` no traen el email, y agregárselo a `branding`
 * mezclaría la casilla del dueño con la identidad visual del comercio.
 *
 * 🔴 SOLO LEE. No escribe nada, no despacha jobs y no toca otros modelos.
 */
class ContactoDuenoController extends Controller
{
    /**
     * GET admin-sync/contacto-dueno/{user_id?}
     *
     * Devuelve la casilla, el nombre, el nombre del comercio y el teléfono del
     * dueño, para que admin-api arme el mail de novedades de la actualización y
     * el WhatsApp que lo anuncia.
     *
     * 🔴 Cada clave es un string con valor real o `null`, NUNCA string vacío: del
     * otro lado se distingue por eso (mismo criterio que ya documenta
     * BrandingController). Un `''` se leería como "hay dato" y terminaría en un
     * `Mail::to('')`. El `email` además tiene que ser una dirección válida: una
     * casilla rota guardada en la base vale lo mismo que no tener ninguna.
     *
     * @param  int|null $user_id  Id del cliente (dueño). Si es null, se usa config('app.USER_ID').
     * @return JsonResponse
     */
    public function show($user_id = null): JsonResponse
    {
        // Si no viene user_id, se asume el dueño configurado en la instancia (mismo criterio que
        // MensualidadController y BrandingController)
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // Usuario dueño del comercio; si no existe, no hay contacto que devolver
        $user = User::find($user_id);

        if (is_null($user)) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        return response()->json([
            'contacto' => [
                // Casilla del dueño: es la que usa admin-api para mandar el mail de novedades.
                // Si está vacía o no es una dirección válida, va null (ver docblock).
                'email'        => $this->email_valido($user->email),
                // Nombre de la persona (para el saludo del mail)
                'name'         => $this->texto_o_null($user->name),
                // Nombre del comercio (mismo campo que ya usan branding y mensualidad-info)
                'company_name' => $this->texto_o_null($user->company_name),
                // Teléfono del dueño. La columna existe en `users` desde la migración original
                // (create_users_table), así que se devuelve el valor real; admin-api igual manda
                // el WhatsApp al `clients.phone` que ya tiene cargado, esto es solo contraste.
                'phone'        => $this->texto_o_null($user->phone),
            ],
        ], 200);
    }

    /**
     * Devuelve el texto limpio si tiene contenido real, o null.
     *
     * @param  string|null $value  Valor crudo guardado en la base.
     * @return string|null
     */
    private function texto_o_null($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Devuelve la casilla solo si es una dirección de mail válida, o null.
     *
     * Se valida acá y no del lado del consumidor porque este endpoint es el que
     * conoce el dato crudo: hay instancias viejas con la columna cargada con un
     * nombre de usuario, un guion o un texto suelto, y admin-api trata "hay mail"
     * como permiso para mandar.
     *
     * @param  string|null $value  Valor crudo de users.email.
     * @return string|null
     */
    private function email_valido($value): ?string
    {
        $value = $this->texto_o_null($value);

        if (is_null($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }
}
