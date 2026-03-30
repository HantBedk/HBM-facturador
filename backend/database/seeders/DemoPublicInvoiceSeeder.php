<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoPublicInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('nit', '900111222-3')->first();
        $user = User::query()->where('correo', 'tc1@hbm.local')->first();
        if (! $company || ! $user) {
            return;
        }

        $s1 = Service::query()->updateOrCreate(
            ['code' => 'DEMO-FAC-S1'],
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'client_name' => $company->nombre,
                'service_type' => 'Mantenimiento',
                'description' => 'Revisión de instalación eléctrica — consulta pública demo.',
                'amount' => 150000.00,
                'service_date' => '2026-03-10',
                'status' => Service::STATUS_ACTIVO,
            ]
        );

        $s2 = Service::query()->updateOrCreate(
            ['code' => 'DEMO-FAC-S2'],
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'client_name' => $company->nombre,
                'service_type' => 'Soporte',
                'description' => 'Ajuste de tablero y pruebas de continuidad.',
                'amount' => 95000.00,
                'service_date' => '2026-03-12',
                'status' => Service::STATUS_ACTIVO,
            ]
        );

        $subtotal = 245000.00;
        $invoice = Invoice::query()->updateOrCreate(
            ['code' => 'FAC-2026-DEMO001'],
            [
                'company_id' => $company->id,
                'period_month' => 3,
                'period_year' => 2026,
                'status' => Invoice::STATUS_PARCIALMENTE_PAGADA,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'sent_at' => now(),
            ]
        );

        $invoice->services()->sync([$s1->id, $s2->id]);

        if ($invoice->payments()->count() === 0) {
            Payment::query()->create([
                'invoice_id' => $invoice->id,
                'amount' => 100000.00,
                'payment_date' => '2026-03-15',
                'method' => 'Transferencia',
                'notes' => 'Abono inicial',
            ]);
        }

        /** Borrador adicional para probar aprobación / envío desde el panel. */
        $s3 = Service::query()->updateOrCreate(
            ['code' => 'DEMO-FAC-S3'],
            [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'client_name' => $company->nombre,
                'service_type' => 'Instalación',
                'description' => 'Montaje de punto adicional — factura en borrador (demo).',
                'amount' => 50000.00,
                'service_date' => '2026-03-18',
                'status' => Service::STATUS_ACTIVO,
            ]
        );

        $draft = Invoice::query()->updateOrCreate(
            ['code' => 'FAC-2026-DEMO002'],
            [
                'company_id' => $company->id,
                'period_month' => 3,
                'period_year' => 2026,
                'status' => Invoice::STATUS_BORRADOR,
                'subtotal' => 50000.00,
                'total' => 50000.00,
                'sent_at' => null,
            ]
        );
        $draft->services()->sync([$s3->id]);
    }
}
