<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Escaneo de facturas con IA" en la tabla
 * extencion_empresas.
 *
 * Esta extensión habilita, por empresa, el escaneo de facturas de compra: el botón
 * "Escanear" con la cámara en el listado de compras, el modal de revisión con la tabla
 * editable, y las ocho rutas REST de provider-order-scan en la API. Con la extensión apagada
 * el botón no existe y las rutas contestan 403; nada del resto del sistema cambia de
 * comportamiento.
 *
 * Extensión propia y no reusar ai_excel_import: son dos formas distintas de cargar artículos
 * a una compra (un Excel del proveedor vs. una foto del comprobante en papel), se venden
 * aparte, y hay clientes que van a querer una sin la otra. Mismo precedente que
 * sugerencias_compras frente a sugerencias_inteligentes.
 *
 * En producción se corre SOLO este seeder —o el ExtencionEscaneoFacturaCompraProduccionSeeder,
 * que es este mismo más el informe por consola—, NUNCA ExtencionSeeder, que hace create() en
 * un foreach y correrlo dos veces duplica todo el catálogo:
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionEscaneoFacturaCompraSeeder --force
 */
class ExtencionEscaneoFacturaCompraSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * firstOrCreate para que sea idempotente: si el slug ya existe (porque ya se corrió
         * antes, o porque una instancia nueva lo sembró desde DatabaseSeeder), no genera un
         * duplicado al ejecutarlo de nuevo.
         */
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'escaneo_factura_compra'],
            ['name' => 'Escaneo de facturas con IA']
        );
    }
}
