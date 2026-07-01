<?php

namespace App\Console\Commands;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\PagadoPor;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Audita la integridad de las cuentas corrientes de clientes de un owner y,
 * si encuentra inconsistencias, envía un mail resumen al email del owner.
 * Comando de solo lectura: no corrige ni modifica datos.
 */
class CheckCurrentAcountsIntegrity extends Command
{
    protected $signature = 'check_current_acounts_integrity {user_id?}';

    protected $description = 'Audita la integridad de cuentas corrientes de clientes y notifica al owner por mail si hay inconsistencias.';

    public function handle()
    {
        // user_id explícito por parámetro o, en su defecto, el owner de la instancia (.env)
        $user_id = $this->argument('user_id') ?? config('app.USER_ID');

        $user = User::find($user_id);

        if (!$user) {
            $this->error("No se encontró usuario con ID {$user_id}.");
            return 1;
        }

        $this->info("Auditando cuentas corrientes de: {$user->company_name}");

        // Acumula todas las inconsistencias detectadas por los 4 checks
        $inconsistencias = [];

        // === CHECK 1: Pagos con to_pay_id huérfano ===
        // Pagos que apuntan a un débito que ya no existe (fue eliminado)
        $this->info('Verificando pagos con to_pay_id huérfano...');
        $pagos_con_to_pay_id = CurrentAcount::where('user_id', $user_id)
            ->whereNotNull('to_pay_id')
            ->whereNotNull('client_id')
            ->where('is_provisorio', 0)
            ->get();

        foreach ($pagos_con_to_pay_id as $pago) {
            $debito = CurrentAcount::find($pago->to_pay_id);
            if (!$debito) {
                $client_name = $pago->client ? $pago->client->name : "client_id={$pago->client_id}";
                $inconsistencias[] = [
                    'tipo'    => 'to_pay_id_huerfano',
                    'label'   => "Pago ID {$pago->id} del cliente {$client_name} apunta a débito ID {$pago->to_pay_id} que no existe. Monto: $" . number_format($pago->haber, 2, ',', '.'),
                    'ca_id'   => $pago->id,
                    'credit_account_id' => $pago->credit_account_id,
                ];
            }
        }
        $this->info('  Encontrados: ' . count(array_filter($inconsistencias, fn($i) => $i['tipo'] === 'to_pay_id_huerfano')));

        // === CHECK 2: Imputaciones en pagado_por con debe_id inexistente ===
        // Registros en pagado_por que apuntan a un débito eliminado
        $this->info('Verificando imputaciones en pagado_por con debe_id inexistente...');
        $pagado_por_ids = PagadoPor::select('id', 'debe_id', 'haber_id', 'pagado')
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('current_acounts')
                    ->whereColumn('current_acounts.id', 'pagado_por.debe_id');
            })
            ->get();

        foreach ($pagado_por_ids as $pp) {
            $inconsistencias[] = [
                'tipo'  => 'pagado_por_debe_id_inexistente',
                'label' => "pagado_por ID {$pp->id}: debe_id={$pp->debe_id} no existe en current_acounts. haber_id={$pp->haber_id}, pagado=$" . number_format($pp->pagado, 2, ',', '.'),
                'ca_id' => null,
                'credit_account_id' => null,
            ];
        }
        $this->info('  Encontrados: ' . count(array_filter($inconsistencias, fn($i) => $i['tipo'] === 'pagado_por_debe_id_inexistente')));

        // === CHECK 3: Débitos en status 'pagandose' con campo pagandose = 0 ===
        // Inconsistencia: están marcados como pagándose pero sin monto registrado
        $this->info("Verificando débitos 'pagandose' con campo pagandose en cero...");
        $pagandose_en_cero = CurrentAcount::where('user_id', $user_id)
            ->where('status', 'pagandose')
            ->whereNotNull('client_id')
            ->where('is_provisorio', 0)
            ->where(function ($q) {
                $q->whereNull('pagandose')->orWhere('pagandose', 0);
            })
            ->get();

        foreach ($pagandose_en_cero as $ca) {
            $client_name = $ca->client ? $ca->client->name : "client_id={$ca->client_id}";
            $inconsistencias[] = [
                'tipo'  => 'pagandose_cero',
                'label' => "current_acount ID {$ca->id} del cliente {$client_name} tiene status='pagandose' pero campo pagandose=0. Detalle: {$ca->detalle}. Debe: $" . number_format($ca->debe, 2, ',', '.'),
                'ca_id' => $ca->id,
                'credit_account_id' => $ca->credit_account_id,
            ];
        }
        $this->info('  Encontrados: ' . count(array_filter($inconsistencias, fn($i) => $i['tipo'] === 'pagandose_cero')));

        // === CHECK 4: Coherencia de saldo corrido en current_acounts ===
        // Recalcula debe - haber acumulado y verifica que el campo saldo de cada movimiento sea correcto
        $this->info('Verificando coherencia de saldos por movimiento...');
        $credit_accounts = CreditAccount::where('model_name', 'client')
            ->whereHas('current_acount', function ($q) use ($user_id) {
                $q->where('user_id', $user_id)->where('is_provisorio', 0);
            })
            ->get();

        $errores_saldo = 0;
        foreach ($credit_accounts as $credit_account) {
            $movimientos = CurrentAcount::where('credit_account_id', $credit_account->id)
                ->where('is_provisorio', 0)
                ->orderBy('created_at', 'ASC')
                ->orderBy('id', 'ASC')
                ->get();

            $saldo_acum = 0.0;
            foreach ($movimientos as $mov) {
                $saldo_acum += (float) ($mov->debe ?? 0);
                $saldo_acum -= (float) ($mov->haber ?? 0);
                $saldo_acum = round($saldo_acum, 2);

                if ($mov->saldo === null) continue;

                $sg = round((float) $mov->saldo, 2);
                if (abs($sg - $saldo_acum) > 0.02) {
                    $errores_saldo++;
                    $client = $credit_account->client ?? null;
                    $client_name = $client ? $client->name : "credit_account_id={$credit_account->id}";
                    $inconsistencias[] = [
                        'tipo'  => 'saldo_movimiento_incorrecto',
                        'label' => "current_acount ID {$mov->id} ({$client_name}): saldo guardado=\${$sg} pero calculado=\${$saldo_acum}. Detalle: {$mov->detalle}.",
                        'ca_id' => $mov->id,
                        'credit_account_id' => $credit_account->id,
                    ];
                    // Limitar para no inundar el mail
                    if ($errores_saldo >= 20) break 2;
                }
            }
        }
        $this->info("  Encontrados: {$errores_saldo}");

        // === RESULTADO ===
        if (empty($inconsistencias)) {
            $this->info('No se encontraron inconsistencias.');
            Log::info("[CheckCurrentAcountsIntegrity] Sin inconsistencias para user_id={$user_id}.");
            return 0;
        }

        $total = count($inconsistencias);
        $this->warn("Se encontraron {$total} inconsistencia(s). Enviando mail...");

        // Agrupar por tipo para el resumen del mail
        $por_tipo = [];
        foreach ($inconsistencias as $inc) {
            $por_tipo[$inc['tipo']][] = $inc['label'];
        }

        $labels_map = [
            'to_pay_id_huerfano'           => 'Pagos con to_pay_id huérfano (débito eliminado)',
            'pagado_por_debe_id_inexistente' => 'Imputaciones en pagado_por con debe_id inexistente',
            'pagandose_cero'               => "Débitos en 'pagandose' con campo pagandose=0",
            'saldo_movimiento_incorrecto'  => 'Saldos de movimientos inconsistentes',
        ];

        $detail_lines = [];
        $paragraphs   = ["Se detectaron {$total} inconsistencia(s) en las cuentas corrientes de {$user->company_name}."];

        foreach ($por_tipo as $tipo => $items) {
            $label_tipo = $labels_map[$tipo] ?? $tipo;
            $detail_lines[] = [
                'label'      => $label_tipo,
                'value'      => count($items) . ' caso(s)',
                'bold_label' => true,
            ];
            foreach ($items as $item) {
                $detail_lines[] = [
                    'label' => '',
                    'value' => $item,
                ];
            }
        }

        $paragraphs[] = 'Revisá la base de datos del cliente y ejecutá los comandos de corrección correspondientes.';

        $payload = new ComercioCityMailPayload([
            'subject'  => "[ComercioCity] Inconsistencias en cuentas corrientes — {$user->company_name}",
            'title'    => 'Auditoría de cuentas corrientes',
            'paragraphs' => $paragraphs,
            'detail_lines' => $detail_lines,
            'closing'  => 'Este mail fue generado automáticamente por el comando check_current_acounts_integrity.',
            'preheader' => "{$total} inconsistencia(s) detectadas en {$user->company_name}",
        ]);

        $mail_to = $user->email;
        if (!$mail_to || !filter_var($mail_to, FILTER_VALIDATE_EMAIL)) {
            $this->error("El usuario no tiene un email válido configurado ({$mail_to}). No se pudo enviar el mail.");
            Log::error("[CheckCurrentAcountsIntegrity] Email inválido para user_id={$user_id}: {$mail_to}");
            return 1;
        }

        Mail::to($mail_to)->send(new ComercioCityMail($payload));

        Log::info("[CheckCurrentAcountsIntegrity] Mail enviado a {$mail_to} con {$total} inconsistencia(s). user_id={$user_id}.");
        $this->info("Mail enviado a {$mail_to}.");

        return 0;
    }
}
