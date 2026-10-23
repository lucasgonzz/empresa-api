<?php

namespace App\Console\Commands;

use App\Http\Controllers\Helpers\PdfLayout\SucursalesDelEncabezadoPdf;
use App\Models\PdfColumnProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Suma la sucursal al encabezado de los diseños de PDF que ya existen (misión
 * pdf-direccion-sucursales, 23/10/2026, D7).
 *
 * La dirección del PDF de venta, presupuesto y pedido sale ahora de las sucursales, con los chips
 * `sucursal_comprobante`, `todas_las_sucursales` y `sucursal_<id>` del encabezado. Un perfil SIN
 * `header_layout` guardado usa el default por código, que ya trae `sucursal_comprobante`: no
 * necesita este comando. Uno CON `header_layout` guardado no lo tiene, y es lo que hace este comando:
 *
 *  - Recorre `pdf_column_profiles` con `model_name` sale / budget / order y `header_layout` guardado,
 *    SIN los perfiles de ticket de comandera (D9: el ticket no se toca; `scopeDeHoja()`, el mismo
 *    criterio que `PdfColumnProfile::es_ticket()`: tipo de hoja sin alto).
 *  - Si el emisor no tiene ningún chip de sucursal (ni a la izquierda ni a la derecha), inserta uno
 *    justo después de `domicilio_comercial` (D3: se SUMA al Domicilio Comercial, no lo reemplaza),
 *    en el cuadrante donde esté; si no está en ninguno, al final de `emisor.izquierda`.
 *  - Cuál: `todas_las_sucursales` si el dueño del perfil tenía `users.all_addresses_in_sale_pdf = 1`
 *    (la opción vieja del perfil "todas las direcciones en el PDF"); si no, `sucursal_comprobante`.
 *  - Un layout sin `domicilio_comercial` y sin `emisor.izquierda` como lista no se toca (se
 *    informa): no se le inventa un cuadrante que hoy no imprime nada.
 *
 * Idempotente: un perfil que ya tiene un chip de sucursal se saltea. Solo escribe `header_layout`
 * del perfil; no toca `users` (`address_company` y `all_addresses_in_sale_pdf` quedan como están).
 *
 *     php artisan pdf:sumar-sucursal-al-encabezado            # --ver (por defecto): solo lista
 *     php artisan pdf:sumar-sucursal-al-encabezado --aplicar  # escribe
 *
 * Si llegan `--ver` y `--aplicar` juntos gana la lectura. Cada perfil cambiado se lista con su
 * `emisor.izquierda` de antes y de después: es el rastro para volverlo atrás a mano.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor ni enum.
 */
class SumarSucursalAlEncabezadoPdf extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pdf:sumar-sucursal-al-encabezado
                            {--ver : Solo lista qué perfiles cambiaría. Es el modo por defecto; no escribe nada.}
                            {--aplicar : Escribe el chip de sucursal en el header_layout de cada perfil que no tiene ninguno.}';

    /**
     * @var string
     */
    protected $description = 'Suma la sucursal (sucursal_comprobante o todas_las_sucursales) al encabezado de los diseños de PDF de venta, presupuesto y pedido con header_layout guardado. --ver por defecto; --aplicar escribe.';

    /** Modelos de perfil cuyo encabezado lleva el emisor con chips. */
    const MODELOS = ['sale', 'budget', 'order'];

    /**
     * @return int
     */
    public function handle()
    {
        $aplicar = (bool) $this->option('aplicar') && ! (bool) $this->option('ver');

        $this->info($aplicar ? 'Modo --aplicar: se escriben los cambios.' : 'Modo --ver: no se escribe nada.');

        /** Dueños con la opción vieja "todas las direcciones en el PDF" (la columna puede no existir en una base vieja). */
        $con_todas = [];
        if (Schema::hasColumn('users', 'all_addresses_in_sale_pdf')) {
            $con_todas = User::where('all_addresses_in_sale_pdf', 1)->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all();
        }

        $cambiados = 0;
        $con_chip = 0;
        $salteados = 0;

        PdfColumnProfile::whereIn('model_name', self::MODELOS)
            ->deHoja()
            ->whereNotNull('header_layout')
            ->orderBy('id')
            ->chunkById(200, function ($perfiles) use ($aplicar, $con_todas, &$cambiados, &$con_chip, &$salteados) {
                foreach ($perfiles as $perfil) {
                    $layout = $perfil->header_layout;

                    if (SucursalesDelEncabezadoPdf::layout_tiene_chip($layout)) {
                        $con_chip++;
                        continue;
                    }

                    $lado = self::cuadrante_del_chip($layout);

                    if (is_null($lado)) {
                        $salteados++;
                        $this->line('  Perfil '.$perfil->id.' ('.$perfil->name.'): sin emisor.izquierda, no se toca.');
                        continue;
                    }

                    $chip = in_array((int) $perfil->user_id, $con_todas, true)
                        ? SucursalesDelEncabezadoPdf::CHIP_TODAS
                        : SucursalesDelEncabezadoPdf::CHIP_DEL_COMPROBANTE;

                    $antes = array_values($layout['emisor'][$lado]);
                    $despues = self::con_chip($antes, $chip);

                    $this->line('  Perfil '.$perfil->id.' ('.$perfil->model_name.', user '.$perfil->user_id.', '.$perfil->name.'): emisor.'.$lado.' '
                        .json_encode($antes, JSON_UNESCAPED_UNICODE).' -> '.json_encode($despues, JSON_UNESCAPED_UNICODE));

                    if ($aplicar) {
                        $layout['emisor'][$lado] = $despues;
                        $perfil->header_layout = $layout;
                        $perfil->save();
                    }

                    $cambiados++;
                }
            });

        $this->info(($aplicar ? 'Perfiles actualizados: ' : 'Perfiles a actualizar: ').$cambiados
            .'. Ya tenían sucursal: '.$con_chip.'. Salteados: '.$salteados.'.');

        return 0;
    }

    /**
     * El cuadrante del emisor donde va el chip: el que tiene 'domicilio_comercial'; si ninguno lo
     * tiene, 'izquierda'. Null si el layout no tiene ese cuadrante como lista.
     *
     * @param mixed $layout
     * @return string|null
     */
    public static function cuadrante_del_chip($layout)
    {
        if (! is_array($layout) || ! isset($layout['emisor']) || ! is_array($layout['emisor'])) {
            return null;
        }

        foreach (['izquierda', 'derecha'] as $lado) {
            if (isset($layout['emisor'][$lado]) && is_array($layout['emisor'][$lado])
                && in_array('domicilio_comercial', $layout['emisor'][$lado], true)) {
                return $lado;
            }
        }

        return (isset($layout['emisor']['izquierda']) && is_array($layout['emisor']['izquierda'])) ? 'izquierda' : null;
    }

    /**
     * El cuadrante con el chip insertado justo después de 'domicilio_comercial', o al final si no
     * lo tiene.
     *
     * @param array<int, string> $izquierda
     * @param string             $chip
     * @return array<int, string>
     */
    public static function con_chip(array $izquierda, $chip)
    {
        $posicion = array_search('domicilio_comercial', $izquierda, true);

        if ($posicion === false) {
            $izquierda[] = $chip;

            return $izquierda;
        }

        array_splice($izquierda, $posicion + 1, 0, [$chip]);

        return $izquierda;
    }
}
