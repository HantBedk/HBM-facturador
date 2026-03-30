<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminInvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\InvoiceCodeGenerator;
use App\Support\InvoicePdfPayload;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminInvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Invoice::query()->with(['company:id,nombre,nit'])->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id');

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }

        if ($request->filled('period_year')) {
            $q->where('period_year', $request->integer('period_year'));
        }

        if ($request->filled('period_month')) {
            $q->where('period_month', $request->integer('period_month'));
        }

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where('code', 'like', $term);
        }

        return AdminInvoiceResource::collection(
            $q->paginate($request->integer('per_page', 15))->withQueryString()
        );
    }

    public function show(Invoice $invoice): AdminInvoiceResource
    {
        $invoice->load(['company:id,nombre,nit', 'services.user', 'payments' => fn ($q) => $q->orderBy('payment_date')]);

        return new AdminInvoiceResource($invoice);
    }

    /**
     * Servicios de la empresa en el mes que aún pueden facturarse (o ya van en esta factura al editar).
     */
    public function availableServices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'invoice_id' => ['sometimes', 'nullable', 'exists:invoices,id'],
        ]);

        $tz = config('app.timezone');
        $start = Carbon::createFromDate($validated['period_year'], $validated['period_month'], 1, $tz)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $exceptInvoiceId = isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null;

        $blockedIds = DB::table('invoice_service')
            ->when($exceptInvoiceId, fn ($q) => $q->where('invoice_id', '!=', $exceptInvoiceId))
            ->pluck('service_id')
            ->all();

        $currentIds = $exceptInvoiceId
            ? Invoice::query()->findOrFail($exceptInvoiceId)->services()->pluck('services.id')->all()
            : [];

        $rows = Service::query()
            ->where('company_id', $validated['company_id'])
            ->whereBetween('service_date', [$start->toDateString(), $end->toDateString()])
            ->visibles()
            ->with(['user:id,nombre'])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get()
            ->filter(function (Service $s) use ($blockedIds, $currentIds) {
                return ! in_array($s->id, $blockedIds, true) || in_array($s->id, $currentIds, true);
            })
            ->values();

        return response()->json([
            'data' => $rows->map(fn (Service $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'service_date' => $s->service_date?->format('Y-m-d'),
                'description' => $s->description,
                'service_type' => $s->service_type,
                'amount' => (string) $s->amount,
                'empleado' => $s->user ? ['nombre' => $s->user->nombre] : null,
            ])->all(),
        ]);
    }

    public function store(Request $request, InvoiceCodeGenerator $codes): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $company = Company::query()->findOrFail($data['company_id']);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['La empresa debe estar activa para generar facturas.'],
            ]);
        }

        $this->assertServicesAttachable(
            $data['company_id'],
            $data['period_year'],
            $data['period_month'],
            $data['service_ids'],
            null
        );

        $total = $this->sumServiceAmounts($data['service_ids']);
        $code = $codes->nextForYear((int) $data['period_year']);

        $invoice = DB::transaction(function () use ($data, $total, $code) {
            $inv = Invoice::query()->create([
                'code' => $code,
                'company_id' => $data['company_id'],
                'period_month' => $data['period_month'],
                'period_year' => $data['period_year'],
                'status' => Invoice::STATUS_BORRADOR,
                'subtotal' => $total,
                'total' => $total,
                'sent_at' => null,
            ]);
            $inv->services()->sync($data['service_ids']);

            return $inv;
        });

        $invoice->load(['company:id,nombre,nit', 'services.user', 'payments']);

        return (new AdminInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function update(Request $request, Invoice $invoice): AdminInvoiceResource|JsonResponse
    {
        if ($invoice->status !== Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'Solo las facturas en borrador pueden editarse.',
            ], 422);
        }

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $company = Company::query()->findOrFail($data['company_id']);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['La empresa debe estar activa.'],
            ]);
        }

        $this->assertServicesAttachable(
            $data['company_id'],
            $data['period_year'],
            $data['period_month'],
            $data['service_ids'],
            $invoice->id
        );

        $total = $this->sumServiceAmounts($data['service_ids']);

        DB::transaction(function () use ($invoice, $data, $total) {
            $invoice->company_id = $data['company_id'];
            $invoice->period_month = $data['period_month'];
            $invoice->period_year = $data['period_year'];
            $invoice->subtotal = $total;
            $invoice->total = $total;
            $invoice->save();
            $invoice->services()->sync($data['service_ids']);
        });

        $invoice->refresh()->load(['company:id,nombre,nit', 'services.user', 'payments']);

        return new AdminInvoiceResource($invoice);
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function assertServicesAttachable(
        int $companyId,
        int $year,
        int $month,
        array $serviceIds,
        ?int $exceptInvoiceId
    ): void {
        $tz = config('app.timezone');
        $start = Carbon::createFromDate($year, $month, 1, $tz)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $blockedIds = DB::table('invoice_service')
            ->when($exceptInvoiceId, fn ($q) => $q->where('invoice_id', '!=', $exceptInvoiceId))
            ->pluck('service_id')
            ->all();

        foreach ($serviceIds as $sid) {
            $s = Service::query()->findOrFail($sid);
            if ((int) $s->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'service_ids' => ['El servicio '.$s->code.' no pertenece a la empresa indicada.'],
                ]);
            }
            $sd = $s->service_date?->toDateString();
            if ($sd === null || $sd < $start->toDateString() || $sd > $end->toDateString()) {
                throw ValidationException::withMessages([
                    'service_ids' => ['El servicio '.$s->code.' no corresponde al periodo (mes/año) seleccionado.'],
                ]);
            }
            if (! in_array($s->status, [Service::STATUS_ACTIVO, Service::STATUS_CORREGIDO], true)) {
                throw ValidationException::withMessages([
                    'service_ids' => ['El servicio '.$s->code.' no está disponible para facturación.'],
                ]);
            }
            if (in_array($sid, $blockedIds, true)) {
                throw ValidationException::withMessages([
                    'service_ids' => ['El servicio '.$s->code.' ya está incluido en otra factura.'],
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function sumServiceAmounts(array $serviceIds): string
    {
        $sum = Service::query()->whereIn('id', $serviceIds)->sum('amount');

        return (string) $sum;
    }

    public function pdf(Invoice $invoice): \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\JsonResponse
    {
        try {
            $data = InvoicePdfPayload::build($invoice);
            $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoice->code).'.pdf';

            return Pdf::loadView('pdf.public_invoice', ['data' => $data])->download($filename);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'No se pudo generar el PDF.',
            ], 500);
        }
    }

    public function updateStatus(Request $request, Invoice $invoice): AdminInvoiceResource|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.Invoice::STATUS_APROBADA.','.Invoice::STATUS_ENVIADA],
        ]);

        if ($data['status'] === Invoice::STATUS_APROBADA) {
            if ($invoice->status !== Invoice::STATUS_BORRADOR) {
                return response()->json(['message' => 'Solo se puede aprobar una factura en borrador.'], 422);
            }
            $invoice->status = Invoice::STATUS_APROBADA;
        } else {
            if ($invoice->status !== Invoice::STATUS_APROBADA) {
                return response()->json(['message' => 'Solo se puede marcar como enviada una factura aprobada.'], 422);
            }
            $invoice->status = Invoice::STATUS_ENVIADA;
            $invoice->sent_at = $invoice->sent_at ?? now();
        }

        $invoice->save();
        $invoice->load(['company:id,nombre,nit', 'services.user', 'payments']);

        return new AdminInvoiceResource($invoice);
    }

    public function storePayment(Request $request, Invoice $invoice): AdminInvoiceResource|\Illuminate\Http\JsonResponse
    {
        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json(['message' => 'No se registran pagos mientras la factura está en borrador.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->refresh();
        $this->syncInvoiceStatusFromPayments($invoice);
        $invoice->load(['company:id,nombre,nit', 'services.user', 'payments']);

        return new AdminInvoiceResource($invoice);
    }

    public function destroyPayment(Invoice $invoice, Payment $payment): AdminInvoiceResource|\Illuminate\Http\JsonResponse
    {
        if ((int) $payment->invoice_id !== (int) $invoice->id) {
            return response()->json(['message' => 'El pago no pertenece a esta factura.'], 404);
        }

        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json(['message' => 'Factura en borrador.'], 422);
        }

        $payment->delete();
        $invoice->refresh();
        $this->syncInvoiceStatusFromPayments($invoice);
        $invoice->load(['company:id,nombre,nit', 'services.user', 'payments']);

        return new AdminInvoiceResource($invoice);
    }

    private function syncInvoiceStatusFromPayments(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return;
        }

        $invoice->load('payments');
        $paid = (float) $invoice->payments->sum('amount');
        $total = (float) $invoice->total;

        if ($paid <= 0.001) {
            if ($invoice->sent_at !== null) {
                $invoice->status = Invoice::STATUS_ENVIADA;
            } else {
                $invoice->status = Invoice::STATUS_APROBADA;
            }
        } elseif ($paid >= $total - 0.01) {
            $invoice->status = Invoice::STATUS_PAGADA;
        } else {
            $invoice->status = Invoice::STATUS_PARCIALMENTE_PAGADA;
        }

        $invoice->save();
    }
}
