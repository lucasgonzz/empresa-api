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
 *
 * 🔴 Y DEVUELVE UN SOLO CAMPO: `contacto.email`. No es un olvido — antes devolvía
 * también `name`, `company_name` y `phone`, y se recortaron a propósito:
 *
 *   - El consumidor no los usaba. admin-api lee `contacto.email` y nada más: el
 *     nombre del negocio lo saca de `clients.company_name` y el teléfono del
 *     WhatsApp, de `clients.phone`. Los tres viajaban para que nadie los leyera.
 *   - Y esta ruta hoy responde SIN validar nada. El middleware `admin.api.key`
 *     tiene el gate apagado en producción (`ADMIN_SYNC_REQUIRE_API_KEY` no está en
 *     ningún `.env` de cliente y su default es false), y el `{user_id?}` permite
 *     pedir cualquier id de la base — que en las bases compartidas viejas son 51
 *     comercios distintos. Devolver el nombre, el nombre del comercio y el teléfono
 *     de cualquiera de ellos es un padrón que nadie pidió.
 *
 * El día que haga falta otro dato acá, se agrega: agregar es compatible hacia
 * atrás, sacar no. Lo que no se hace es dejarlo "por si acaso".
 */
class ContactoDuenoController extends Controller
{
    /**
     * GET admin-sync/contacto-dueno/{user_id?}
     *
     * Devuelve la casilla del dueño, para que admin-api le mande el mail con las
     * novedades de la actualización.
     *
     * 🔴 `email` es un string con una dirección válida o `null`, NUNCA string vacío:
     * del otro lado se distingue por eso (mismo criterio que ya documenta
     * BrandingController). Un `''` se leería como "hay dato" y terminaría en un
     * `Mail::to('')`. Y tiene que ser una dirección válida: una casilla rota
     * guardada en la base vale lo mismo que no tener ninguna.
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
                'email' => $this->email_valido($user->email),
            ],
        ], 200);
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
        if (is_null($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }
}
