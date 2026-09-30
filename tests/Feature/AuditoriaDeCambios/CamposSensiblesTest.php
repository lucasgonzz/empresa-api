<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Test 4 del plan: los campos sensibles se guardan como "[oculto]" y NINGUNA clave, token ni
 * contraseña aparece en claro en `audit_logs` (misión auditoria-de-cambios, 30/9/2026).
 *
 * Queda constancia de que el campo CAMBIÓ (sirve para auditar "alguien cambió la clave"), sin
 * filtrar el valor. La tabla no tiene pantalla hoy, pero la puede leer cualquiera con acceso a la
 * base (soporte, un backup): una contraseña en claro ahí sería una filtración.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class CamposSensiblesTest extends AuditoriaTestCase
{
    /**
     * Cambiar la contraseña de un usuario deja "[oculto]" y la palabra real no está en ninguna fila.
     *
     * @return void
     */
    public function test_la_password_de_un_usuario_queda_oculta_en_toda_la_tabla()
    {
        $palabra_vieja = 'PalabraSecretaVieja9271';
        $palabra_nueva = 'PalabraSecretaNueva5834';
        $hash_viejo    = Hash::make($palabra_vieja);
        $hash_nuevo    = Hash::make($palabra_nueva);

        $usuario = User::create([
            'name'             => 'ZZ Usuario con clave',
            'company_name'     => 'Ferreteria sensibles',
            'email'            => 'aud-sensible-' . uniqid() . '@test.local',
            'doc_number'       => 'DOC-SENS-' . uniqid(),
            'password'         => $hash_viejo,
            'visible_password' => $palabra_vieja,
            'owner_id'         => $this->dueno->id,
        ]);

        $usuario->password         = $hash_nuevo;
        $usuario->visible_password = $palabra_nueva;
        $usuario->save();

        $fila = $this->filas(User::class, 'updated')->first();

        $this->assertNotNull($fila, 'Cambiar la password tiene que dejar una fila updated.');

        $nuevos = json_decode($fila->new_values, true);
        $viejos = json_decode($fila->old_values, true);

        $this->assertSame('[oculto]', $nuevos['password'], 'Queda constancia de que cambió.');
        $this->assertSame('[oculto]', $viejos['password']);
        $this->assertSame('[oculto]', $nuevos['visible_password']);

        // Y ni la palabra ni el hash aparecen en NINGUNA fila de la tabla (creación incluida).
        foreach ([$palabra_vieja, $palabra_nueva, $hash_viejo, $hash_nuevo] as $secreto) {

            $this->assertSame(
                0,
                AuditLog::where('new_values', 'like', '%' . $secreto . '%')->count(),
                'El secreto apareció en new_values.'
            );

            $this->assertSame(
                0,
                AuditLog::where('old_values', 'like', '%' . $secreto . '%')->count(),
                'El secreto apareció en old_values.'
            );
        }

        // La creación del usuario también los ocultó.
        $creacion = $this->filas(User::class, 'created')->first();
        $creados = json_decode($creacion->new_values, true);

        $this->assertSame('[oculto]', $creados['password']);
        $this->assertSame('[oculto]', $creados['visible_password']);
    }

    /**
     * Las claves de terceros (Serper, Google, exportación) y los campos `*_token` / `*_secret`
     * también se ocultan, sin que nadie los tenga que declarar uno por uno.
     *
     * @return void
     */
    public function test_las_claves_de_terceros_y_los_campos_con_sufijo_sensible_se_ocultan()
    {
        $usuario = User::create([
            'name'                         => 'ZZ Usuario con claves',
            'company_name'                 => 'Ferreteria claves',
            'email'                        => 'aud-claves-' . uniqid() . '@test.local',
            'doc_number'                   => 'DOC-CLAVES-' . uniqid(),
            'password'                     => Hash::make('x'),
            'owner_id'                     => $this->dueno->id,
        ]);

        $usuario->serper_api_key               = 'SerperClaveEnClaro7741';
        $usuario->google_custom_search_api_key = 'GoogleClaveEnClaro8852';
        $usuario->articles_export_key          = 'ExportClaveEnClaro9963';
        $usuario->clave_eliminar_article       = 'ClaveDeBorradoEnClaro1174';
        $usuario->save();

        $fila = $this->filas(User::class, 'updated')->first();

        $nuevos = json_decode($fila->new_values, true);

        foreach (['serper_api_key', 'google_custom_search_api_key', 'articles_export_key', 'clave_eliminar_article'] as $campo) {
            $this->assertSame('[oculto]', $nuevos[$campo], 'El campo ' . $campo . ' se guardó en claro.');
        }

        foreach (['SerperClaveEnClaro7741', 'GoogleClaveEnClaro8852', 'ExportClaveEnClaro9963', 'ClaveDeBorradoEnClaro1174'] as $secreto) {
            $this->assertSame(0, AuditLog::where('new_values', 'like', '%' . $secreto . '%')->count());
        }
    }

    /**
     * Un valor larguísimo se corta, y un JSON enorme se reemplaza por la lista de campos.
     *
     * @return void
     */
    public function test_los_valores_largos_se_cortan_y_el_json_gigante_se_reemplaza_por_los_nombres()
    {
        $articulo = $this->crear_articulo(['name' => 'ZZ Auditoria largo']);

        $largo = str_repeat('a', 5000);

        $articulo->name = $largo;
        $articulo->save();

        $fila = $this->filas(\App\Models\Article::class, 'updated')->first();

        $nuevos = json_decode($fila->new_values, true);

        $this->assertStringEndsWith('…[+4000 caracteres]', $nuevos['name']);
        $this->assertLessThan(1100, strlen($nuevos['name']));

        // Con el tope de bytes bajo, la fila entera se reemplaza por los nombres de los campos.
        config(['audit_log.max_bytes_fila' => 50]);

        $articulo->name = 'ZZ Auditoria otro nombre bastante largo para pasar el tope';
        $articulo->save();

        $ultima = $this->filas(\App\Models\Article::class, 'updated')->last();

        $this->assertSame(
            ['_truncado' => true, 'campos' => ['name']],
            json_decode($ultima->new_values, true)
        );
    }
}
