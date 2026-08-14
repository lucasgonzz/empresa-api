<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ExtencionEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserExtencionController extends Controller
{
    /**
     * En qué orden se muestran los módulos del formulario.
     *
     * Sale de las familias de `contexto/extensiones_comportamiento.md` (misión 53) y lo escribe
     * el seeder en la columna `modulo`. Las que tengan un módulo que no esté acá —o ninguno—
     * caen en un grupo propio al final, en vez de desaparecer del formulario.
     *
     * @var array
     */
    const ORDEN_MODULOS = [
        'VENDER',
        'Codigos de barra',
        'Precios',
        'Articulos y listado',
        'Ventas y comprobantes',
        'Cuentas, caja y comisiones',
        'Importacion',
        'Produccion y depositos',
        'Tienda e integraciones',
        'General',
    ];

    /**
     * Grupo donde van a parar las extensiones sin `modulo` cargado.
     *
     * @var string
     */
    const MODULO_SIN_CLASIFICAR = 'Sin clasificar';

    /**
     * El configurador de extensiones de un comercio.
     *
     * Dos rutas entran acá: `/extensiones` (sin parámetro, el usuario de `config('app.USER_ID')`)
     * y `/user/extencions/edit/{user_id?}`, que se sigue necesitando porque acepta un id.
     *
     * @param  int|null  $user_id
     * @return \Illuminate\View\View
     */
    public function edit($user_id = null)
    {
        /*
         * Se guarda ANTES de resolverlo: si no vino id, después de guardar hay que volver a la
         * ruta propia y no a la que lleva el id adentro.
         */
        $sin_parametro = is_null($user_id);

        if ($sin_parametro) {
            $user_id = config('app.USER_ID');
        }

        $user = User::find($user_id);

        $extencions = ExtencionEmpresa::orderBy('name')->get();

        $user_extencion_ids = $user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray();

        /*
         * Las en desuso salen del listado principal y van a su propia sección al final: son las
         * que ningún código lee, así que mezcladas solo hacen ruido. Siguen siendo tildables —
         * marcar en desuso no es borrar, y las asignaciones que ya tiene el cliente se ven.
         */
        $en_desuso = $extencions->filter(function ($extencion) {
            return (bool) $extencion->en_desuso;
        })->values();

        $vivas = $extencions->filter(function ($extencion) {
            return !((bool) $extencion->en_desuso);
        })->values();

        return view('user.extencions.edit', [
            'user'               => $user,
            'extencions'         => $extencions,
            'user_extencion_ids' => $user_extencion_ids,
            'grupos'             => $this->agrupar_por_modulo($vivas),
            'en_desuso'          => $en_desuso,
            'sin_parametro'      => $sin_parametro,
        ]);
    }

    /**
     * Guarda las extensiones del comercio.
     *
     * Red de seguridad (misión 54): `sync()` deja exactamente lo que llega, así que un envío que
     * no trae nada le saca TODAS las extensiones al cliente. Con 91 checkboxes eso es un
     * accidente a un click de distancia. Cuando el envío quita extensiones, se exige que venga
     * confirmado; si no viene, no se guarda nada y se dice cuántas se iban a quitar.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $user_id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $user_id)
    {
        $user = User::find($user_id);

        // sincronizamos: las extensiones seleccionadas serán exactamente las que tenga después
        $selected = is_array($request->extencions) ? $request->extencions : [];

        $actuales = $user->extencions()->pluck('extencion_empresa_user.extencion_empresa_id')->toArray();

        /* array_diff compara como texto, así que no importa que la base traiga int y el form string. */
        $quitadas = array_diff($actuales, $selected);

        $volver_a = $request->filled('volver_a_ruta_propia')
            ? redirect()->route('extensiones')
            : redirect()->route('users.extencions.edit', $user);

        if (count($quitadas) > 0 && $request->input('confirmar_quitar') !== '1') {

            Log::info(
                'UserExtencionController@update: envio sin confirmar que quitaba ' . count($quitadas)
                . ' extension(es) al user ' . $user_id . '. No se guardo nada.'
            );

            return $volver_a->with(
                'warning',
                'No se guardó nada: el envío quitaba ' . count($quitadas) . ' de las ' . count($actuales)
                . ' extensiones que tiene el comercio, y llegó sin confirmar. Volvé a guardar y confirmá el aviso.'
            );
        }

        $user->extencions()->sync($selected);

        return $volver_a->with('success', 'Extensiones actualizadas correctamente.');
    }

    /**
     * Arma los grupos del formulario, en el orden de ORDEN_MODULOS y sin grupos vacíos.
     *
     * @param  \Illuminate\Support\Collection  $extencions
     * @return array
     */
    private function agrupar_por_modulo($extencions)
    {
        $grupos = [];

        foreach (self::ORDEN_MODULOS as $modulo) {
            $del_modulo = $extencions->filter(function ($extencion) use ($modulo) {
                return $extencion->modulo === $modulo;
            })->values();

            if ($del_modulo->count() > 0) {
                $grupos[$modulo] = $del_modulo;
            }
        }

        $sin_clasificar = $extencions->filter(function ($extencion) {
            return !in_array($extencion->modulo, self::ORDEN_MODULOS, true);
        })->values();

        if ($sin_clasificar->count() > 0) {
            $grupos[self::MODULO_SIN_CLASIFICAR] = $sin_clasificar;
        }

        return $grupos;
    }
}
