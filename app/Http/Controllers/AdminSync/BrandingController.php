<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Branding real del comercio (logo, color primario, nombre) para que admin-api
 * pueda resolver el favicon/logo del ecommerce sin depender de la tienda-api
 * propia del cliente (que en instalaciones nuevas todavía no existe).
 *
 * Ver grupo 208 · prompt 01: `LogoPaletteAiService::resolve_logo()` es el criterio
 * canónico de prioridad (tienda -> empresa), pero ese criterio se aplica del lado
 * de admin-api (prompt 02); acá solo se devuelven los dos valores crudos.
 */
class BrandingController extends Controller
{
    /**
     * GET admin-sync/branding/{user_id?}
     *
     * Devuelve el logo de la tienda (online_configuration.logo_url), el logo de
     * la empresa (user.image_url), el color primario de la tienda y el nombre
     * del comercio. No aplica ninguna prioridad entre logo_url e image_url: eso
     * queda del lado de quien consume (admin-api).
     *
     * @param  int|null $user_id  Id del cliente (dueño). Si es null, se usa config('app.USER_ID').
     * @return JsonResponse
     */
    public function show($user_id = null): JsonResponse
    {
        // Si no viene user_id, se asume el dueño configurado en la instancia (mismo criterio que MensualidadController)
        if (is_null($user_id)) {
            $user_id = config('app.USER_ID');
        }

        // Usuario dueño del comercio; si no existe, no hay branding que devolver
        $user = User::find($user_id);

        if (is_null($user)) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        // Configuración online del comercio (logo de tienda y color primario); puede no existir todavía
        $online_configuration = OnlineConfiguration::where('user_id', $user_id)->first();

        return response()->json([
            'branding' => [
                // Logo cargado en la tienda (prioridad, resuelto a URL absoluta descargable)
                'logo_url'      => $this->to_absolute_url($online_configuration ? $online_configuration->logo_url : null),
                // Logo cargado en Configuración general de la empresa (fallback)
                'image_url'     => $this->to_absolute_url($user->image_url),
                // Color primario cargado en la configuración online de la tienda
                'primary_color' => (!empty($online_configuration) && !empty($online_configuration->primary_color))
                    ? $online_configuration->primary_color
                    : null,
                // Nombre del comercio (mismo campo que ya se usa para mostrarlo, ver MensualidadController)
                'company_name'  => !empty($user->company_name) ? $user->company_name : null,
            ],
        ], 200);
    }

    /**
     * Resuelve un valor guardado de imagen a una URL absoluta y descargable por curl.
     *
     * La mayoría de los logos ya se guardan como URL absoluta (Cloudinary), pero
     * algunos registros viejos guardan solo el path local de /storage. Si no se
     * puede resolver a una URL absoluta real, se devuelve null (mejor null que
     * una URL rota que el pipeline de admin intente descargar).
     *
     * @param  string|null $value  Valor crudo guardado en la base (URL absoluta o path local).
     * @return string|null
     */
    private function to_absolute_url($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Ya es una URL absoluta (caso más común: Cloudinary u otro host externo)
        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        // Path relativo/local: intentamos resolverlo a un archivo real servido por /storage
        $local_path = GeneralHelper::storage_public_path_from_image_url($value);

        if ($local_path === null) {
            return null;
        }

        return url('storage/' . basename($local_path));
    }
}
