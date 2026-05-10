<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminInvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PanelNotification;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InvoiceCodeGenerator;
use App\Services\InvoicePublicAccessService;
use App\Services\MailNotificationTemplatesService;
use App\Services\MailTemplatePdfService;
use App\Services\PanelNotificationDispatcher;
use App\Services\PanelNotificationMailSender;
use App\Support\ActivityAmountNarrative;
use App\Support\InvoicePdfPayload;
use App\Support\InvoiceTotalsFromServices;
use App\Support\Pagination;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use Symfony\Component\HttpFoundation\Response;

class AdminInvoiceController extends Controller
{
    public function __construct(
        private readonly PanelNotificationMailSender $panelMail,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Invoice::query()->with([
            'company:id,nombre,nit,telefono,es_cliente_puntual',
            'payments:id,invoice_id,amount',
        ]);

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }

        $kind = $request->query('company_kind');
        if ($kind === 'registered') {
            $q->whereNotNull('company_id');
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

        $sort = $request->query('sort');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['code', 'company_nombre', 'period', 'status', 'total', 'balance', 'created_at'];

        if (is_string($sort) && in_array($sort, $allowedSorts, true)) {
            match ($sort) {
                'code' => $q->orderBy('invoices.code', $sortDir)->orderBy('invoices.id', $sortDir),
                'status' => $q->orderBy('invoices.status', $sortDir)->orderBy('invoices.id', $sortDir),
                'total' => $q->orderBy('invoices.total', $sortDir)->orderBy('invoices.id', $sortDir),
                'created_at' => $q->orderBy('invoices.created_at', $sortDir)->orderBy('invoices.id', $sortDir),
                'company_nombre' => $q->leftJoin('companies', 'invoices.company_id', '=', 'companies.id')
                    ->select('invoices.*')
                    ->orderByRaw('COALESCE(companies.nombre, invoices.bill_to_nombre) '.$sortDir)
                    ->orderBy('invoices.id', $sortDir),
                'period' => $q->orderBy('invoices.period_year', $sortDir)
                    ->orderBy('invoices.period_month', $sortDir)
                    ->orderBy('invoices.id', $sortDir),
                'balance' => $q->orderByRaw(
                    '(invoices.total - COALESCE((SELECT SUM(amount) FROM payments WHERE payments.invoice_id = invoices.id), 0)) '
                    .($sortDir === 'asc' ? 'asc' : 'desc')
                )->orderBy('invoices.id', $sortDir),
                default => $q->orderByDesc('invoices.period_year')
                    ->orderByDesc('invoices.period_month')
                    ->orderByDesc('invoices.id'),
            };
        } else {
            $q->orderByDesc('invoices.period_year')
                ->orderByDesc('invoices.period_month')
                ->orderByDesc('invoices.id');
        }

        return AdminInvoiceResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function show(Invoice $invoice): AdminInvoiceResource
    {
        $invoice->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments' => fn ($q) => $q->orderBy('payment_date')]);

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
            ? DB::table('invoice_service')->where('invoice_id', $exceptInvoiceId)->pluck('service_id')->all()
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
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'service_ids' => ['required', 'array', 'min:1', 'distinct'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $cid = (int) $data['company_id'];

        $company = Company::query()->findOrFail($cid);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['La empresa debe estar activa para generar facturas.'],
            ]);
        }
        $sigla = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $company->factura_sigla) ?? '');
        if (strlen($sigla) !== 3) {
            throw ValidationException::withMessages([
                'company_id' => ['La empresa debe tener una sigla de facturación de 3 letras (A-Z). Edítela en Empresas.'],
            ]);
        }

        $this->assertServicesAttachable(
            $cid,
            $data['period_year'],
            $data['period_month'],
            $data['service_ids'],
            null
        );

        $totals = InvoiceTotalsFromServices::fromServiceIds($data['service_ids']);

        $invoice = DB::transaction(function () use ($data, $totals, $codes, $cid) {
            $tz = config('app.timezone');
            $now = Carbon::now($tz);

            $company = Company::query()->findOrFail($cid);
            try {
                $code = $codes->nextForCompanyOnDate($company, $now);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['company_id' => [$e->getMessage()]]);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['company_id' => [$e->getMessage()]]);
            }

            $inv = Invoice::query()->create([
                'code' => $code,
                'company_id' => $cid,
                'bill_to_nombre' => null,
                'bill_to_telefono' => null,
                'bill_to_nit' => null,
                'period_month' => $data['period_month'],
                'period_year' => $data['period_year'],
                'status' => Invoice::STATUS_BORRADOR,
                'subtotal' => $totals['subtotal'],
                'total' => $totals['total'],
                'sent_at' => null,
            ]);
            $inv->services()->sync($data['service_ids']);

            return $inv;
        });

        $invoice->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent,iva_percent', 'payments']);

        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_INVOICE_DRAFT,
            'Factura borrador '.$invoice->code.' creada; pendiente de aprobación.',
            [
                'invoice_id' => $invoice->id,
                'link' => '/admin/facturas/'.$invoice->id,
            ]
        );

        ActivityLogger::log(
            $request->user(),
            'factura_creada',
            'Creó factura borrador '.$invoice->code.' (ID '.$invoice->id.'). Total: '.ActivityAmountNarrative::cop($invoice->total).'.'
        );

        return (new AdminInvoiceResource($invoice))->response()->setStatusCode(201);
    }

    public function update(Request $request, Invoice $invoice): AdminInvoiceResource|JsonResponse
    {
        if ($invoice->status !== Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'Solo las facturas en borrador pueden editarse.',
            ], 422);
        }

        if ($invoice->company_id === null) {
            return response()->json([
                'message' => 'Las facturas sin empresa en directorio ya no se editan. Elimine este borrador y cree la factura contra una empresa registrada.',
            ], 422);
        }

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'service_ids' => ['required', 'array', 'min:1', 'distinct'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $cid = (int) $data['company_id'];

        $company = Company::query()->findOrFail($cid);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['La empresa debe estar activa.'],
            ]);
        }

        $this->assertServicesAttachable(
            $cid,
            $data['period_year'],
            $data['period_month'],
            $data['service_ids'],
            $invoice->id
        );

        $totals = InvoiceTotalsFromServices::fromServiceIds($data['service_ids']);

        $prevServiceIds = DB::table('invoice_service')
            ->where('invoice_id', $invoice->id)
            ->pluck('service_id')
            ->all();

        DB::transaction(function () use ($invoice, $data, $totals, $cid) {
            $invoice->company_id = $cid;
            $invoice->bill_to_nombre = null;
            $invoice->bill_to_telefono = null;
            $invoice->bill_to_nit = null;
            $invoice->period_month = $data['period_month'];
            $invoice->period_year = $data['period_year'];
            $invoice->subtotal = $totals['subtotal'];
            $invoice->total = $totals['total'];
            $invoice->save();
            $invoice->services()->sync($data['service_ids']);
        });

        $invoice->refresh()->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments']);

        $removedIds = array_values(array_diff($prevServiceIds, $data['service_ids']));
        if ($removedIds !== []) {
            $dispatcher = app(PanelNotificationDispatcher::class);
            foreach ($removedIds as $sid) {
                $svc = Service::query()->with('user')->find((int) $sid);
                if ($svc === null) {
                    continue;
                }
                $owner = $svc->user;
                if ($owner === null || $owner->rol !== User::ROL_EMPLEADO) {
                    continue;
                }
                $dispatcher->notifyUser(
                    (int) $owner->id,
                    PanelNotification::TYPE_EMP_SERVICIO_EXCLUIDO_BORRADOR,
                    'Se quitó su servicio '.$svc->code.' del borrador de factura '.$invoice->code.'.',
                    [
                        'invoice_id' => $invoice->id,
                        'service_id' => $svc->id,
                        'link' => '/empleado/servicio/'.$svc->id,
                    ]
                );
            }
        }

        ActivityLogger::log(
            $request->user(),
            'factura_editada',
            'Editó borrador de factura '.$invoice->code.' (ID '.$invoice->id.'). Total: '.ActivityAmountNarrative::cop($invoice->total).'.'
        );

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

    public function pdf(Request $request, Invoice $invoice): Response|JsonResponse
    {
        $previewQuery = $request->boolean('preview');

        if ($invoice->status === Invoice::STATUS_BORRADOR && ! $previewQuery) {
            return response()->json([
                'message' => 'No se puede descargar el PDF oficial en borrador. Use vista previa (?preview=1) o apruebe la factura.',
            ], 422);
        }

        try {
            $isDraft = $invoice->status === Invoice::STATUS_BORRADOR;
            $data = InvoicePdfPayload::build($invoice, [
                'preview' => $isDraft,
            ]);
            $suffix = $isDraft ? '-vista-previa' : '';
            $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoice->code).$suffix.'.pdf';

            return Pdf::loadView('pdf.public_invoice', ['data' => $data])->stream($filename);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? ('No se pudo generar el PDF: '.$e->getMessage())
                    : 'No se pudo generar el PDF.',
            ], 500);
        }
    }

    /**
     * Envía el PDF oficial de la factura al correo registrado en la ficha de la empresa (directorio).
     */
    public function sendEmailToCompany(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'No se puede enviar por correo un borrador. Apruebe la factura o use la vista previa PDF desde el panel.',
            ], 422);
        }

        $invoice->load('company');
        if ($invoice->company_id === null || $invoice->company === null) {
            return response()->json([
                'message' => 'Solo se envían facturas vinculadas a una empresa del directorio con correo registrado.',
            ], 422);
        }

        $correo = strtolower(trim((string) ($invoice->company->correo ?? '')));
        if ($correo === '' || ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'company' => ['La empresa no tiene un correo válido en su ficha. Edite la empresa en el directorio y vuelva a intentar.'],
            ]);
        }

        try {
            $data = InvoicePdfPayload::build($invoice, ['preview' => false]);
            $filename = 'factura-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $invoice->code).'.pdf';
            $binary = Pdf::loadView('pdf.public_invoice', ['data' => $data])->output();
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? ('No se pudo generar el PDF: '.$e->getMessage())
                    : 'No se pudo generar el PDF para el envío.',
            ], 500);
        }

        $tpl = app(MailNotificationTemplatesService::class);
        $pdfExtra = app(MailTemplatePdfService::class);
        $companyNombre = (string) $invoice->company->nombre;
        $consultUrl = $tpl->invoicePublicConsultUrl($invoice->code);

        $blobAttachments = [
            ['content' => $binary, 'name' => $filename, 'mime' => 'application/pdf'],
        ];
        $fileAttachments = [];
        $supMeta = $pdfExtra->meta(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT);
        $supPath = $pdfExtra->absolutePath(MailTemplatePdfService::KIND_INVOICE_SUPPLEMENT);
        if ($supMeta !== null && $supPath !== null && is_readable($supPath)) {
            $supFn = basename($supMeta['original_filename']);
            if (! str_ends_with(strtolower($supFn), '.pdf')) {
                $supFn .= '.pdf';
            }
            $fileAttachments[] = [
                'path' => $supPath,
                'name' => $supFn,
                'mime' => 'application/pdf',
            ];
        }

        try {
            $this->panelMail->sendHtml(
                $correo,
                $tpl->invoiceToCompanySubjectRendered($invoice, $companyNombre),
                (string) $tpl->invoiceToCompanyBodyHtml($invoice, $companyNombre, $consultUrl),
                null,
                $fileAttachments,
                $blobAttachments,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'company' => [$e->getMessage()],
            ]);
        } catch (PhpMailerException $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? 'No se pudo enviar: '.$e->getMessage()
                    : 'No se pudo enviar el correo. Revise Gmail/SMTP en el panel o MAIL_* en .env.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? 'No se pudo enviar: '.$e->getMessage()
                    : 'No se pudo enviar el correo. Revise la configuración de correo del servidor.',
            ], 422);
        }

        ActivityLogger::log(
            $request->user(),
            'factura_enviada_correo',
            'Envió por correo la factura '.$invoice->code.' a '.$correo.'.'
        );

        return response()->json([
            'message' => 'Factura enviada a '.$correo.'.',
            'sent_to' => $correo,
        ]);
    }

    public function updateStatus(Request $request, Invoice $invoice, InvoicePublicAccessService $publicAccess): AdminInvoiceResource|JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.Invoice::STATUS_APROBADA.','.Invoice::STATUS_ENVIADA],
        ]);

        $plainVerification = null;

        if ($data['status'] === Invoice::STATUS_APROBADA) {
            if ($invoice->status !== Invoice::STATUS_BORRADOR) {
                return response()->json(['message' => 'Solo se puede aprobar una factura en borrador.'], 422);
            }
            $invoice->loadCount('services');
            if ($invoice->services_count < 1) {
                return response()->json(['message' => 'No se puede aprobar una factura sin servicios.'], 422);
            }
            if ((float) $invoice->total <= 0) {
                return response()->json(['message' => 'No se puede aprobar una factura con total en cero.'], 422);
            }
            if ($invoice->company_id === null) {
                return response()->json([
                    'message' => 'Solo se aprueban facturas asociadas a una empresa registrada en el directorio.',
                ], 422);
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

        if ($data['status'] === Invoice::STATUS_APROBADA) {
            $plainVerification = $publicAccess->ensureToken($invoice->fresh());
            $invoice->refresh();
        }

        $invoice->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments']);

        if ($data['status'] === Invoice::STATUS_APROBADA) {
            $dispatcher = app(PanelNotificationDispatcher::class);
            $dispatcher->notifyAdmins(
                PanelNotification::TYPE_INVOICE_PENDING_SEND,
                'Factura '.$invoice->code.' aprobada; pendiente de envío o de corte automático.',
                [
                    'invoice_id' => $invoice->id,
                    'link' => '/admin/facturas/'.$invoice->id,
                ]
            );

            $techFirstServiceId = [];
            foreach ($invoice->services as $svc) {
                $u = $svc->user;
                if ($u === null || $u->rol !== User::ROL_EMPLEADO) {
                    continue;
                }
                $uid = (int) $u->id;
                if (! isset($techFirstServiceId[$uid])) {
                    $techFirstServiceId[$uid] = (int) $svc->id;
                }
            }
            foreach ($techFirstServiceId as $uid => $firstSid) {
                $dispatcher->notifyUser(
                    $uid,
                    PanelNotification::TYPE_EMP_SERVICIO_FACTURA_APROBADA,
                    'La factura '.$invoice->code.' fue aprobada e incluye sus servicios (periodo '.$invoice->period_month.'/'.$invoice->period_year.').',
                    [
                        'invoice_id' => $invoice->id,
                        'service_id' => $firstSid,
                        'link' => '/empleado/servicio/'.$firstSid,
                    ],
                    'emp_inv_appr_'.$invoice->id
                );
            }
        }

        $actor = $request->user();
        $actionLabel = $data['status'] === Invoice::STATUS_APROBADA ? 'factura_aprobada' : 'factura_enviada';
        ActivityLogger::log(
            $actor,
            $actionLabel,
            ($data['status'] === Invoice::STATUS_APROBADA ? 'Aprobó' : 'Marcó como enviada').' factura '.$invoice->code.' (ID '.$invoice->id.'). Total: '.ActivityAmountNarrative::cop($invoice->total).'.'
        );

        $resource = new AdminInvoiceResource($invoice);
        if ($plainVerification !== null) {
            return $resource->additional([
                'public_verification_code' => $plainVerification,
                'public_verification_notice' => 'Comparta este código con el cliente junto al código de factura. No se volverá a mostrar; puede generar uno nuevo desde el detalle.',
            ]);
        }

        return $resource;
    }

    /**
     * Nuevo código de verificación para consulta pública (invalida el anterior).
     */
    public function regeneratePublicAccess(Request $request, Invoice $invoice, InvoicePublicAccessService $publicAccess): AdminInvoiceResource|JsonResponse
    {
        if ($invoice->status === Invoice::STATUS_BORRADOR) {
            return response()->json([
                'message' => 'Apruebe la factura antes de habilitar la consulta pública.',
            ], 422);
        }

        $plain = $publicAccess->regenerate($invoice);
        $invoice->refresh()->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments']);

        ActivityLogger::log(
            $request->user(),
            'factura_token_publico_regenerado',
            'Regeneró código de consulta pública para factura '.$invoice->code.' (ID '.$invoice->id.'). Total factura: '.ActivityAmountNarrative::cop($invoice->total).'.'
        );

        return (new AdminInvoiceResource($invoice))->additional([
            'public_verification_code' => $plain,
            'public_verification_notice' => 'El código anterior deja de ser válido.',
        ]);
    }

    public function storePayment(Request $request, Invoice $invoice): AdminInvoiceResource|JsonResponse
    {
        if (! in_array($invoice->status, [
            Invoice::STATUS_ENVIADA,
            Invoice::STATUS_PARCIALMENTE_PAGADA,
        ], true)) {
            if ($invoice->status === Invoice::STATUS_BORRADOR) {
                return response()->json(['message' => 'No se registran pagos mientras la factura está en borrador.'], 422);
            }
            if ($invoice->status === Invoice::STATUS_APROBADA) {
                return response()->json(['message' => 'Marque la factura como enviada antes de registrar pagos.'], 422);
            }
            if ($invoice->status === Invoice::STATUS_PAGADA) {
                return response()->json(['message' => 'La factura ya está pagada en su totalidad.'], 422);
            }

            return response()->json(['message' => 'No se pueden registrar pagos en este estado de la factura.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $existingPaid = (float) Payment::query()->where('invoice_id', $invoice->id)->sum('amount');
        $balance = (float) $invoice->total - $existingPaid;
        $amount = round((float) $data['amount'], 2);
        if ($amount > round(max(0, $balance), 2) + 0.009) {
            throw ValidationException::withMessages([
                'amount' => ['El monto excede el saldo pendiente ('.number_format(max(0, $balance), 2, '.', '').').'],
            ]);
        }

        $statusBeforePayment = $invoice->status;

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['method'],
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->refresh();
        $this->syncInvoiceStatusFromPayments($invoice);
        $invoice->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments']);

        if ($invoice->status === Invoice::STATUS_PARCIALMENTE_PAGADA) {
            app(PanelNotificationDispatcher::class)->notifyAdmins(
                PanelNotification::TYPE_INVOICE_PARTIAL_PAYMENT,
                'Abono registrado en factura '.$invoice->code.'; aún hay saldo pendiente.',
                [
                    'invoice_id' => $invoice->id,
                    'link' => '/admin/facturas/'.$invoice->id,
                    'previous_status' => $statusBeforePayment,
                ],
                'partial_inv_'.$invoice->id
            );
        }

        $dispatcher = app(PanelNotificationDispatcher::class);
        $empleadoIds = $invoice->services->pluck('user_id')->unique()->filter(fn ($id) => $id !== null && (int) $id > 0)->values();
        foreach ($empleadoIds as $uid) {
            $u = User::query()->find((int) $uid);
            if ($u === null || $u->rol !== User::ROL_EMPLEADO) {
                continue;
            }
            $dispatcher->notifyUser(
                (int) $uid,
                PanelNotification::TYPE_EMP_PAGO_FACTURA,
                'Se registró un pago en la factura '.$invoice->code.' (periodo '.$invoice->period_month.'/'.$invoice->period_year.').',
                [
                    'link' => '/empleado',
                    'invoice_id' => $invoice->id,
                ],
                'pago_'.$payment->id.'_u_'.$uid
            );
        }

        ActivityLogger::log(
            $request->user(),
            'pago_registrado',
            'Registró pago en factura '.$invoice->code.' (ID '.$invoice->id.'). Monto del pago: '.ActivityAmountNarrative::cop($payment->amount).'. Saldo factura tras el movimiento: '.ActivityAmountNarrative::cop(max(0, (float) $invoice->total - (float) Payment::query()->where('invoice_id', $invoice->id)->sum('amount'))).'. Estado: '.$invoice->status.'.'
        );

        return new AdminInvoiceResource($invoice);
    }

    public function destroyPayment(Request $request, Invoice $invoice, Payment $payment): AdminInvoiceResource|JsonResponse
    {
        if ((int) $payment->invoice_id !== (int) $invoice->id) {
            return response()->json(['message' => 'El pago no pertenece a esta factura.'], 404);
        }

        if (! in_array($invoice->status, [
            Invoice::STATUS_ENVIADA,
            Invoice::STATUS_PARCIALMENTE_PAGADA,
            Invoice::STATUS_PAGADA,
        ], true)) {
            return response()->json(['message' => 'No se pueden modificar pagos en este estado de la factura.'], 422);
        }

        $deletedAmount = $payment->amount;
        $payment->delete();
        $invoice->refresh();
        $this->syncInvoiceStatusFromPayments($invoice);
        $invoice->load(['company:id,nombre,nit,telefono,es_cliente_puntual', 'services.user', 'services.catalog:id,name,iva_percent', 'payments']);

        ActivityLogger::log(
            $request->user(),
            'pago_eliminado',
            'Eliminó un pago de '.ActivityAmountNarrative::cop($deletedAmount).' en la factura '.$invoice->code.' (ID '.$invoice->id.').'
        );

        return new AdminInvoiceResource($invoice);
    }

    /**
     * Elimina factura errónea: solo borrador, o aprobada sin pagos (aún no enviada con cobros).
     * Los servicios se desvinculan y pueden facturarse de nuevo. Queda registro en historial.
     */
    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        if (! in_array($invoice->status, [Invoice::STATUS_BORRADOR, Invoice::STATUS_APROBADA], true)) {
            return response()->json([
                'message' => 'Solo se pueden eliminar borradores o facturas aprobadas sin pagos. Las enviadas o cobradas no se borran desde aquí.',
            ], 422);
        }

        if ($invoice->status === Invoice::STATUS_APROBADA) {
            $paid = (float) Payment::query()->where('invoice_id', $invoice->id)->sum('amount');
            if ($paid > 0.001) {
                return response()->json([
                    'message' => 'No se puede eliminar una factura con pagos registrados.',
                ], 422);
            }
        }

        $invoice->loadMissing('company:id,nombre');
        $code = $invoice->code;
        $invId = $invoice->id;
        $prevStatus = $invoice->status;
        $companyNombre = $invoice->company?->nombre ?? '—';
        $periodLabel = (int) $invoice->period_month.'/'.(int) $invoice->period_year;
        $invTotal = $invoice->total;

        DB::transaction(function () use ($invoice) {
            $invoice->payments()->delete();
            $invoice->services()->detach();
            $invoice->delete();
        });

        ActivityLogger::log(
            $request->user(),
            'factura_eliminada',
            'Eliminó factura '.$code.' (ID '.$invId.'). Total factura: '.ActivityAmountNarrative::cop($invTotal).'. Estado previo: '.$prevStatus.'. Empresa: '.$companyNombre.'. Periodo facturación: '.$periodLabel.'. Servicios desvinculados para nueva factura.'
        );

        return response()->json(['message' => 'Factura eliminada.']);
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
