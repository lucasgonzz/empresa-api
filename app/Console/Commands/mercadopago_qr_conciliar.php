<?php

namespace App\Console\Commands;

use App\Services\MercadoPago\MercadoPagoQrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Concilia los cobros con QR de Mercado Pago que quedaron pendientes (misión
 * cobro-qr-mercado-pago, 26/10/2026).
 *
 * Es la red de abajo del polling del modal de Vender: si el cajero cerró el modal, se cortó la
 * luz o el navegador se colgó mientras el cliente pagaba, el cobro quedaría `pendiente` para
 * siempre. Cada minuto (ver `app/Console/Kernel.php`) se consulta la orden de cada pendiente en
 * MP y se aplica lo que MP conteste; se recuperan las órdenes que MP no confirmó al crearlas. 🔴
 * Nunca se cambia un estado sin una respuesta válida de MP: sin conexión o con MP caído, el cobro
 * queda pendiente y se reintenta en la próxima corrida (ver `conciliar_pendientes()`).
 *
 * No hay webhook en esta misión (necesita un relay en el admin): por eso existe este comando.
 * Recorre los cobros pendientes de TODOS los comercios de la instancia, sin iterar owners: cada
 * cobro dice de qué comercio es, y el servicio resuelve la conexión una vez por comercio. Toda la
 * lógica vive en `MercadoPagoQrService::conciliar_pendientes()`.
 */
class mercadopago_qr_conciliar extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mercadopago:qr-conciliar';

    /**
     * @var string
     */
    protected $description = 'Concilia con Mercado Pago los cobros con QR pendientes (solo cambia estados con respuesta de MP).';

    /**
     * Ejecuta la conciliación. Sale en la primera línea si la tabla todavía no existe (la ventana
     * de un deploy entre subir los archivos y correr las migraciones): el gate del Kernel en ese
     * caso deja correr el comando por las dudas, y no tiene que explotar.
     *
     * @return int
     */
    public function handle()
    {
        if (!Schema::hasTable('mercado_pago_qr_cobros')) {
            $this->info('mercadopago:qr-conciliar: la tabla mercado_pago_qr_cobros todavía no existe.');

            return 0;
        }

        // Por el contenedor (y no con `new`) para que un test pueda reemplazar el servicio y
        // probar que una excepción con un cobro no corta el loop.
        $conteo = app(MercadoPagoQrService::class)->conciliar_pendientes();

        $this->info(
            'mercadopago:qr-conciliar: ' . $conteo['revisados'] . ' pendiente(s) revisado(s), '
            . $conteo['actualizados'] . ' cerrado(s) con respuesta de MP, ' . $conteo['sin_cambios'] . ' sin cambios, '
            . $conteo['fallidos'] . ' con error.'
        );

        return 0;
    }
}
