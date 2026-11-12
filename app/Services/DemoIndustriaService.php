<?php

namespace App\Services;

use App\Http\Controllers\Helpers\ApiUrlHelper;
use App\Models\OnlineConfiguration;
use App\Models\Article;
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

    /**
     * Le da a la instancia la identidad y el catálogo del rubro.
     *
     * `$nombre_del_negocio` (misión prueba-e2e-campana-imperio, 11/11/2026) es el nombre que el negocio
     * cargó en el alta de su PRUEBA GRATUITA: si viene, `company_name` del dueño y de sus empleados queda con
     * ese nombre y no con el de la marca de ejemplo del rubro («Punto Fierro · Demo»), que es lo que el
     * dueño leía debajo de su nombre. Todo lo demás (logo, tienda, proveedores, portadas) sigue siendo el de
     * la marca de ejemplo. La demo no lo manda (null) y queda idéntica.
     *
     * @param \App\Models\User $user Dueño de la instancia.
     * @param string $slug Rubro de `config('demo_industrias')`.
     * @param string|null $nombre_del_negocio
     * @return bool
     */
    public static function preparar(User $user, $slug, $nombre_del_negocio = null)
    {
        $perfil = self::resolver($slug);
        if (!$perfil) {
            return false;
        }

        $nombre = $perfil['nombre_demo'];
        $nombre_del_negocio = is_string($nombre_del_negocio) ? trim($nombre_del_negocio) : '';
        $nombre_visible = $nombre_del_negocio !== '' ? $nombre_del_negocio : $nombre;
        $logo = self::url($perfil['logo_url']);
        $user->company_name = $nombre_visible;
        $user->image_url = $logo;
        $user->save();

        // Los empleados también muestran la identidad del negocio en su sesión.
        User::where('owner_id', $user->id)->update([
            'company_name' => $nombre_visible,
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

        // El histórico se siembra después del catálogo y mueve precios/stock.
        // Verificar el texto final y sellar sus vectores ya horneados evita que una demo
        // recién preparada muestre pendientes hasta el próximo ciclo del scheduler.
        $codigos = array_column($perfil['productos'], 'bar_code');
        foreach (Article::where('user_id', $user->id)->whereIn('bar_code', $codigos)->get() as $articulo) {
            if (!DemoIndustriaEmbeddings::aplicar($articulo, $slug)) {
                throw new \RuntimeException('El catálogo final de '.$slug.' necesita un vector vigente para '.$articulo->name.'.');
            }
        }

        return true;
    }
}
