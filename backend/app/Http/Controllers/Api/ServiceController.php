<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Company;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\ServiceItem;
use App\Models\ServicePhoto;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PanelNotificationDispatcher;
use App\Services\ServiceCodeGenerator;
use App\Support\CatalogPricing;
use App\Support\DecimalMath;
use App\Support\Pagination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        $user = $request->user();
        $q = Service::query()
            ->with(['company', 'user'])
            ->withCount('invoices')
            ->withCount('items')
            ->withSum('items', 'technician_line_amount')
            ->with(['invoices' => function ($rel) {
                $rel->select('invoices.id', 'invoices.code');
            }]);

        if (! $user->isAdminEquipo()) {
            $q->visibles();
        }
        // Admin: listado completo (activo, corregido, eliminado) para auditoría

        if (! $user->isAdminEquipo()) {
            $q->where('user_id', $user->id);
        }

        if ($request->filled('company_id')) {
            $q->where('company_id', $request->integer('company_id'));
        }

        if ($user->isAdminEquipo() && $request->filled('user_id')) {
            $q->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('service_date_from')) {
            $q->whereDate('service_date', '>=', $request->date('service_date_from')->format('Y-m-d'));
        }

        if ($request->filled('service_date_to')) {
            $q->whereDate('service_date', '<=', $request->date('service_date_to')->format('Y-m-d'));
        }

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($w) use ($term) {
                $w->where('description', 'like', $term)
                    ->orWhere('client_name', 'like', $term)
                    ->orWhere('service_type', 'like', $term)
                    ->orWhere('code', 'like', $term);
            });
        }

        if ($user->isAdminEquipo() && filter_var($request->query('technician_unpaid'), FILTER_VALIDATE_BOOLEAN)) {
            $sub = Service::query()->visibles()->whereNull('technician_paid_at');
            if ($request->filled('service_date_from')) {
                $sub->whereDate('service_date', '>=', $request->date('service_date_from')->format('Y-m-d'));
            }
            if ($request->filled('service_date_to')) {
                $sub->whereDate('service_date', '<=', $request->date('service_date_to')->format('Y-m-d'));
            }
            $ids = $sub->withSum('items', 'technician_line_amount')
                ->withCount('items')
                ->get()
                ->filter(fn (Service $s) => $s->technicianReferenceTotalValue() > 0.00001)
                ->pluck('id');
            if ($ids->isEmpty()) {
                $q->whereRaw('0 = 1');
            } else {
                $q->whereIn('services.id', $ids->all());
            }
        }

        $sort = $request->query('sort');
        $sortDir = strtolower((string) $request->query('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSorts = [
            'code',
            'service_date',
            'company_nombre',
            'client_name',
            'description',
            'user_nombre',
            'amount',
            'status',
        ];
        if (is_string($sort) && in_array($sort, $allowedSorts, true)) {
            if ($sort === 'company_nombre') {
                $q->leftJoin('companies', 'services.company_id', '=', 'companies.id')
                    ->select('services.*')
                    ->orderBy('companies.nombre', $sortDir)
                    ->orderBy('services.id', $sortDir);
            } elseif ($sort === 'user_nombre') {
                $q->leftJoin('users', 'services.user_id', '=', 'users.id')
                    ->select('services.*')
                    ->orderBy('users.nombre', $sortDir)
                    ->orderBy('services.id', $sortDir);
            } else {
                $col = match ($sort) {
                    'code' => 'services.code',
                    'service_date' => 'services.service_date',
                    'client_name' => 'services.client_name',
                    'description' => 'services.description',
                    'amount' => 'services.amount',
                    'status' => 'services.status',
                    default => null,
                };
                if ($col !== null) {
                    $q->orderBy($col, $sortDir)->orderBy('services.id', $sortDir);
                }
            }
        } else {
            $q->orderByDesc('services.service_date')->orderByDesc('services.id');
        }

        return ServiceResource::collection(
            $q->paginate(Pagination::perPage($request))->withQueryString()
        );
    }

    public function store(Request $request, ServiceCodeGenerator $codes): JsonResponse
    {
        $this->authorize('create', Service::class);

        $user = $request->user();
        $rules = [
            'company_id' => ['required', 'exists:companies,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'service_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:8'],
            'photos' => ['sometimes', 'array', 'max:4'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192'],
            'catalog_id' => ['nullable', 'integer', 'exists:service_catalog,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ];

        $data = $request->validate($rules);

        $itemsPayload = $this->parseItemsFromRequest($request);
        if ($itemsPayload === []) {
            if (! isset($data['amount'])) {
                throw ValidationException::withMessages([
                    'items' => ['Añade líneas del servicio (catálogo o «Otro») o indica un valor único.'],
                ]);
            }
            if (isset($data['catalog_id'])) {
                $itemsPayload = [[
                    'catalog_id' => (int) $data['catalog_id'],
                    'custom_name' => null,
                    'custom_description' => null,
                    'amount' => $data['amount'],
                    'line_description' => $data['description'],
                ]];
            } else {
                // Compatibilidad: registro único sin `items` ni catálogo → una línea «Otro».
                // El `amount` histórico es el total facturable; normalizeServiceItems interpreta `amount` como técnico.
                $billed = (float) $data['amount'];
                $p = CatalogPricing::globalTechnicianDiscountPercent();
                if ($p <= 0 || $p >= 100) {
                    $techAmount = $billed;
                } else {
                    $f = (100 - $p) / 100;
                    $techAmount = round($billed * $f, 2);
                }
                $itemsPayload = [[
                    'catalog_id' => null,
                    'custom_name' => $data['service_type'],
                    'custom_description' => null,
                    'amount' => $techAmount,
                    'line_description' => $data['description'],
                ]];
            }
        }

        $companyId = (int) $data['company_id'];
        $normalized = $this->validateAndNormalizeServiceItems($itemsPayload, $companyId);

        $company = Company::query()->findOrFail($companyId);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['Solo se pueden registrar servicios para empresas activas.'],
            ]);
        }

        // Fecha de servicio (día contable): siempre la del servidor; no aceptar valor del cliente.
        $serviceDate = Carbon::now(config('app.timezone'))->startOfDay();

        $totalAmount = '0.00';
        foreach ($normalized as $row) {
            $a = number_format((float) $row['amount'], 2, '.', '');
            $totalAmount = DecimalMath::add($totalAmount, $a, 2);
        }

        $dupData = array_merge($data, ['amount' => $totalAmount]);
        if ($this->isDuplicate($request->user(), $dupData, $serviceDate)) {
            throw ValidationException::withMessages([
                'description' => ['Ya existe un servicio muy similar para la misma empresa y fecha.'],
            ]);
        }

        try {
            $code = $codes->nextForDate($serviceDate);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'service_date' => [$e->getMessage()],
            ]);
        }

        $firstCatalogId = null;
        foreach ($normalized as $row) {
            if ($row['catalog_id'] !== null) {
                $firstCatalogId = $row['catalog_id'];
                break;
            }
        }

        $service = DB::transaction(function () use (
            $code,
            $companyId,
            $user,
            $data,
            $totalAmount,
            $serviceDate,
            $normalized,
            $firstCatalogId,
        ) {
            $service = Service::create([
                'code' => $code,
                'company_id' => $companyId,
                'user_id' => $user->id,
                'catalog_id' => $firstCatalogId,
                'client_name' => $data['client_name'],
                'service_type' => $data['service_type'],
                'description' => $data['description'],
                'amount' => $totalAmount,
                'service_date' => $serviceDate->toDateString(),
                'status' => Service::STATUS_ACTIVO,
            ]);

            foreach ($normalized as $sort => $row) {
                ServiceItem::query()->create([
                    'service_id' => $service->id,
                    'catalog_id' => $row['catalog_id'],
                    'catalog_suggestion_id' => null,
                    'label' => $row['label'],
                    'line_description' => $row['line_description'],
                    'amount' => $row['amount'],
                    'technician_line_amount' => $row['technician_line_amount'],
                    'sort_order' => $sort,
                ]);
            }

            return $service;
        });

        $uploaded = $request->file('photos', []);
        if (! is_array($uploaded)) {
            $uploaded = array_filter([$uploaded]);
        }
        $uploaded = array_values(array_filter($uploaded));
        foreach ($uploaded as $i => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $path = $file->store("service_photos/{$service->id}", 'public');
            ServicePhoto::query()->create([
                'service_id' => $service->id,
                'path' => $path,
                'sort_order' => $i,
            ]);
        }

        $service->load(['company', 'user', 'photos', 'catalog:id,name', 'items.catalogSuggestion']);

        app(PanelNotificationDispatcher::class)->notifyAdmins(
            PanelNotification::TYPE_SERVICE_CREATED,
            'Nuevo servicio '.$service->code.' registrado ('.($service->company?->nombre ?? 'empresa').').',
            [
                'service_id' => $service->id,
                'link' => '/admin/servicios/'.$service->id,
            ]
        );

        ActivityLogger::log(
            $request->user(),
            'servicio_creado',
            'Creó servicio '.$service->code.' (ID '.$service->id.').'
        );

        return (new ServiceResource($service))->response()->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        $this->authorize('view', $service);
        $service->loadCount('invoices');
        $service->load([
            'company',
            'user',
            'photos',
            'catalog:id,name',
            'items.catalogSuggestion',
            'invoices' => function ($rel) {
                $rel->select('invoices.id', 'invoices.code');
            },
        ]);

        return new ServiceResource($service);
    }

    public function update(Request $request, Service $service): ServiceResource|JsonResponse
    {
        $this->authorize('update', $service);

        if ($service->status === Service::STATUS_ELIMINADO) {
            return response()->json(['message' => 'No se puede editar un servicio eliminado.'], 422);
        }

        if ($service->invoices()->exists()) {
            return response()->json([
                'message' => 'Este servicio ya está asociado a una factura; no puede modificarse para mantener la coherencia contable.',
            ], 422);
        }

        $data = $request->validate([
            'catalog_id' => ['nullable', 'integer', 'exists:service_catalog,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'service_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:8'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if (isset($data['catalog_id'])) {
            $this->assertActiveCatalogItem((int) $data['catalog_id']);
        }

        $service->catalog_id = isset($data['catalog_id']) ? (int) $data['catalog_id'] : null;
        $service->client_name = $data['client_name'];
        $service->service_type = $data['service_type'];
        $service->description = $data['description'];
        $service->amount = $data['amount'];

        if ($service->isDirty()) {
            $service->status = Service::STATUS_CORREGIDO;
            $service->save();
            $this->notifyEmpleadoIfAdminActedOnTheirService(
                $request->user(),
                $service,
                PanelNotification::TYPE_EMP_SERVICIO_MODIFICADO_ADMIN,
                'Administración modificó su servicio '.$service->code.'. Revise los datos actualizados.',
                [
                    'service_id' => $service->id,
                    'link' => '/empleado/servicio/'.$service->id,
                ]
            );
        }

        $service->loadCount('invoices');
        $service->load(['company', 'user', 'photos', 'catalog:id,name', 'items.catalogSuggestion']);

        ActivityLogger::log(
            $request->user(),
            'servicio_editado',
            'Editó servicio '.$service->code.' (ID '.$service->id.').'
        );

        return new ServiceResource($service);
    }

    /**
     * Marca o limpia el registro interno de pago al técnico (no modifica líneas ni facturas).
     */
    public function patchTechnicianPaid(Request $request, Service $service): ServiceResource|JsonResponse
    {
        $this->authorize('view', $service);
        if (! $request->user()->isAdminEquipo()) {
            return response()->json(['message' => 'Solo el equipo administrador puede registrar este estado.'], 403);
        }

        if ($service->status === Service::STATUS_ELIMINADO) {
            return response()->json(['message' => 'No aplica a servicios eliminados.'], 422);
        }

        $data = $request->validate([
            'technician_paid_at' => ['nullable', 'date'],
        ]);

        $service->technician_paid_at = isset($data['technician_paid_at']) && $data['technician_paid_at'] !== null
            ? Carbon::parse($data['technician_paid_at'], config('app.timezone'))->startOfDay()
            : null;
        $service->save();

        $service->loadCount('invoices');
        $service->load(['company', 'user', 'photos', 'catalog:id,name', 'items.catalogSuggestion']);

        ActivityLogger::log(
            $request->user(),
            'servicio_pago_tecnico',
            ($service->technician_paid_at
                ? 'Marcó pago al técnico para servicio '.$service->code.' (ID '.$service->id.').'
                : 'Quitó marca de pago al técnico en servicio '.$service->code.' (ID '.$service->id.').')
        );

        return new ServiceResource($service);
    }

    public function archive(Request $request, Service $service): ServiceResource|JsonResponse
    {
        $this->authorize('delete', $service);

        if ($service->status === Service::STATUS_ELIMINADO) {
            return response()->json(['message' => 'El servicio ya está eliminado.'], 422);
        }

        if ($service->invoices()->exists()) {
            return response()->json([
                'message' => 'No se puede marcar como eliminado un servicio que figura en facturas.',
            ], 422);
        }

        $service->status = Service::STATUS_ELIMINADO;
        $service->save();
        $this->notifyEmpleadoIfAdminActedOnTheirService(
            $request->user(),
            $service,
            PanelNotification::TYPE_EMP_SERVICIO_ELIMINADO_ADMIN,
            'Administración marcó como eliminado su servicio '.$service->code.'.',
            [
                'service_id' => $service->id,
                'link' => '/empleado/servicio/'.$service->id,
            ],
            'emp_svc_elim_'.$service->id
        );
        $service->load(['company', 'user', 'photos', 'catalog:id,name']);

        ActivityLogger::log(
            $request->user(),
            'servicio_archivado',
            'Marcó como eliminado el servicio '.$service->code.' (ID '.$service->id.').'
        );

        return new ServiceResource($service);
    }

    /**
     * Avisa al técnico dueño del servicio si un administrador actúa sobre su registro.
     *
     * @param  array<string, mixed>  $meta
     */
    private function notifyEmpleadoIfAdminActedOnTheirService(
        User $actor,
        Service $service,
        string $type,
        string $message,
        array $meta = [],
        ?string $dedupeKey = null
    ): void {
        if (! $actor->isAdminEquipo()) {
            return;
        }
        $ownerId = (int) $service->user_id;
        if ($ownerId === 0 || $ownerId === (int) $actor->id) {
            return;
        }
        $owner = User::query()->find($ownerId);
        if ($owner === null || $owner->rol !== User::ROL_EMPLEADO) {
            return;
        }
        app(PanelNotificationDispatcher::class)->notifyUser($ownerId, $type, $message, $meta, $dedupeKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseItemsFromRequest(Request $request): array
    {
        $raw = $request->input('items');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{catalog_id: ?int, custom_name: ?string, custom_description: ?string, amount: string, technician_line_amount: string, label: string, line_description: ?string, is_custom: bool, propose_catalog: bool}>
     */
    private function validateAndNormalizeServiceItems(array $rows, int $companyId): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => ['Añade al menos una línea.']]);
        }
        if (count($rows) > 80) {
            throw ValidationException::withMessages(['items' => ['Demasiadas líneas (máx. 80).']]);
        }

        $out = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['items' => ['Formato de líneas inválido.']]);
            }
            $cid = isset($row['catalog_id']) && $row['catalog_id'] !== '' && $row['catalog_id'] !== null
                ? (int) $row['catalog_id'] : null;
            $cname = isset($row['custom_name']) ? trim((string) $row['custom_name']) : '';
            $cdesc = isset($row['custom_description']) ? trim((string) $row['custom_description']) : null;
            if ($cdesc === '') {
                $cdesc = null;
            }
            $lineDescRaw = isset($row['line_description']) ? trim((string) $row['line_description']) : '';
            $lineDescOverride = $lineDescRaw !== '' ? $lineDescRaw : null;

            $amt = $row['amount'] ?? null;
            if ($amt === null || ! is_numeric($amt) || (float) $amt < 0.01) {
                throw ValidationException::withMessages([
                    'items' => ['Línea '.($i + 1).': indica un importe válido (mín. 0,01).'],
                ]);
            }
            $amtStr = number_format((float) $amt, 2, '.', '');

            $isCustom = $cname !== '';
            if ($cid === null && ! $isCustom) {
                throw ValidationException::withMessages([
                    'items' => ['Línea '.($i + 1).': elige un ítem del catálogo o usa «Otro» con nombre.'],
                ]);
            }
            if ($cid !== null && $isCustom) {
                throw ValidationException::withMessages([
                    'items' => ['Línea '.($i + 1).': no combines catálogo y «Otro» en la misma línea.'],
                ]);
            }

            if ($cid !== null) {
                $this->assertActiveCatalogItem($cid);
                $cat = ServiceCatalog::query()->find($cid);
                if ($cat === null) {
                    throw ValidationException::withMessages(['items' => ['Ítem de catálogo no encontrado.']]);
                }
                if ($lineDescRaw === '' || mb_strlen($lineDescRaw) < 8) {
                    throw ValidationException::withMessages([
                        'items' => ['Línea '.($i + 1).': describe el trabajo realizado en este concepto (mín. 8 caracteres).'],
                    ]);
                }
                $lineDesc = $lineDescRaw;
                // `base_price` en catálogo es orientativo; el importe enviado es lo que declara el técnico por el trabajo real.
                // La factura a la empresa = mismo criterio que «Otro»: importe técnico + margen (% global o override por ítem).
                $techEntry = (float) $amtStr;
                $pEff = CatalogPricing::effectiveTechnicianDiscountPercent(
                    $cat->technician_discount_percent !== null ? (float) $cat->technician_discount_percent : null
                );
                $billedStr = CatalogPricing::billedAmountFromTechnicianEntry($techEntry, $pEff);
                $techStr = number_format($techEntry, 2, '.', '');
                $out[] = [
                    'catalog_id' => $cid,
                    'custom_name' => null,
                    'custom_description' => null,
                    'amount' => $billedStr,
                    'technician_line_amount' => $techStr,
                    'label' => $cat->name,
                    'line_description' => $lineDesc,
                    'is_custom' => false,
                    'propose_catalog' => false,
                ];
            } else {
                if (mb_strlen($cname) < 2) {
                    throw ValidationException::withMessages([
                        'items' => ['Línea «Otro» '.($i + 1).': indica un nombre (mín. 2 caracteres).'],
                    ]);
                }
                $lineDesc = $lineDescOverride ?? $cdesc;
                if ($lineDesc === null || trim((string) $lineDesc) === '' || mb_strlen(trim((string) $lineDesc)) < 8) {
                    throw ValidationException::withMessages([
                        'items' => ['Línea «Otro» '.($i + 1).': describe el trabajo realizado (mín. 8 caracteres).'],
                    ]);
                }
                $pGlobal = CatalogPricing::globalTechnicianDiscountPercent();
                $billedStr = CatalogPricing::billedAmountFromTechnicianEntry((float) $amtStr, $pGlobal);
                $out[] = [
                    'catalog_id' => null,
                    'custom_name' => $cname,
                    'custom_description' => $cdesc,
                    'amount' => $billedStr,
                    'technician_line_amount' => $amtStr,
                    'label' => $cname,
                    'line_description' => $lineDesc,
                    'is_custom' => true,
                    'propose_catalog' => false,
                ];
            }
        }

        return $out;
    }

    private function assertActiveCatalogItem(int $catalogId): void
    {
        $row = ServiceCatalog::query()->find($catalogId);
        if ($row === null || $row->status !== ServiceCatalog::STATUS_ACTIVO) {
            throw ValidationException::withMessages([
                'items' => ['Un ítem de catálogo no existe o no está activo.'],
            ]);
        }
    }

    private function isDuplicate(User $user, array $data, Carbon $serviceDate): bool
    {
        $desc = mb_strtolower(trim($data['description']));

        return Service::query()
            ->visibles()
            ->where('user_id', $user->id)
            ->where('company_id', $data['company_id'])
            ->whereDate('service_date', $serviceDate->toDateString())
            ->where('amount', $data['amount'])
            ->whereRaw('LOWER(TRIM(description)) = ?', [$desc])
            ->exists();
    }
}
