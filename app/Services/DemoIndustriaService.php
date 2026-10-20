<?php

namespace App\Services;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\OnlineConfiguration;
use App\Models\OnlineTemplate;
use App\Models\Provider;
use App\Models\Title;
use App\Models\User;

class DemoIndustriaService
{
    public static function resolver($slug)
    {
        if (!is_string($slug) || !preg_match('/^[a-z_]+$/', $slug)) {
            return null;
        }

        $perfiles = config('demo_industrias', []);
        if (!isset($perfiles[$slug]) || empty($perfiles[$slug]['productos'])) {
            return null;
        }

        return array_merge($perfiles[$slug], ['slug' => $slug]);
    }

    public static function url($ruta)
    {
        if (preg_match('#^https?://#', (string) $ruta)) {
            return $ruta;
        }

        // ApiUrlHelper conserva el /public del shared y lo evita en VPS/local.
        return rtrim(ApiUrlHelper::public_base(), '/').'/'.ltrim($ruta, '/');
    }

    public static function preparar(User $user, $slug)
    {
        $perfil = self::resolver($slug);
        if (!$perfil) {
            return false;
        }

        $nombre = $perfil['nombre_demo'];
        $logo = self::url($perfil['logo_url']);
        $user->company_name = $nombre;
        $user->image_url = $logo;
        $user->save();

        // Los empleados también muestran la identidad del negocio en su sesión.
        User::where('owner_id', $user->id)->update([
            'company_name' => $nombre,
            'image_url' => $logo,
        ]);

        // Preservar IDs y movimientos de la semilla, pero darles contexto del rubro.
        // Evita que una demo de ropa conserve proveedores de bulones y sanitarios.
        $categorias = array_values($perfil['categorias']);
        $zonas = ['Norte', 'Centro', 'Sur', 'Litoral'];
        foreach (Provider::where('user_id', $user->id)->orderBy('id')->get() as $indice => $proveedor) {
            $categoria = $categorias[$indice % count($categorias)];
            $zona = $zonas[(int) floor($indice / count($categorias)) % count($zonas)];
            $proveedor->name = $categoria.' '.$zona.' · Demo';
            $proveedor->email = 'proveedor'.($indice + 1).'@'.$slug.'.example';
            $proveedor->save();
        }

        $configuracion = OnlineConfiguration::where('user_id', $user->id)->first();
        if ($configuracion) {
            $plantilla = OnlineTemplate::firstOrCreate(['slug' => 'comerciocity'], ['name' => 'ComercioCity']);
            $configuracion->online_template_id = $plantilla->id;
            $configuracion->logo_url = $logo;
            $configuracion->default_article_image_url = $logo;
            $configuracion->primary_color = $perfil['color'];
            $configuracion->secondary_color = '#252A32';
            $configuracion->text_color = '#FFFFFF';
            $configuracion->hover_text_color = '#FFFFFF';
            $configuracion->background_color = '#F3F4F6';
            $configuracion->titulo_quienes_somos = 'Conocé '.$nombre;
            $configuracion->quienes_somos = '<h2>'.e($nombre).'</h2><p>'.e($perfil['presentacion']).'</p>'
                .'<p>Este negocio es una demostración de ComercioCity. Podés recorrer el catálogo, probar el sistema y conocer cómo se conectan la tienda y los agentes.</p>';
            $configuracion->save();
        }

        foreach ($perfil['banners'] as $indice => $banner) {
            Title::updateOrCreate(['user_id' => $user->id, 'num' => $indice + 1], [
                'color' => $perfil['color'],
                'image_url' => self::url($banner['desktop_url']),
                'crop_image_url' => self::url($banner['mobile_url']),
            ]);
        }

        return true;
    }
}
