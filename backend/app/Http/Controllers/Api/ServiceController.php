<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Company;
use App\Models\PanelNotification;
use App\Models\Service;
use App\Models\ServiceCatalog;
use App\Models\ServicePhoto;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PanelNotificationDispatcher;
use App\Services\ServiceCodeGenerator;
use App\Support\Pagination;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        $user = $request->user();
        $q = Service::query()->with(['company', 'user'])->withCount('invoices');

        if ($user->isAdminEquipo() && $request->boolean('incluir_eliminados')) {
            // sin filtrar por estado
        } else {
            $q->visibles();
        }

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

        $q->orderByDesc('service_date')->orderByDesc('id');

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
            'catalog_id' => ['nullable', 'integer', 'exists:service_catalog,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'service_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:8'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'service_date' => ['required', 'date'],
            'photos' => ['sometimes', 'array', 'max:4'],
            'photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:8192'],
        ];

        $data = $request->validate($rules);
        $this->assertCatalogActive(isset($data['catalog_id']) ? (int) $data['catalog_id'] : null);

        $company = Company::query()->findOrFail($data['company_id']);
        if ($company->estado !== Company::ESTADO_ACTIVO) {
            throw ValidationException::withMessages([
                'company_id' => ['Solo se pueden registrar servicios para empresas activas.'],
            ]);
        }

        $serviceDate = Carbon::parse($data['service_date'], config('app.timezone'))->startOfDay();

        if ($this->isDuplicate($request->user(), $data, $serviceDate)) {
            throw ValidationException::withMessages([
                'description' => ['Ya existe un servicio muy similar para la misma empresa y fecha.'],
            ]);
        }

        $code = $codes->nextForDate($serviceDate);

        $service = Service::create([
            'code' => $code,
            'company_id' => $data['company_id'],
            'user_id' => $request->user()->id,
            'catalog_id' => isset($data['catalog_id']) ? (int) $data['catalog_id'] : null,
            'client_name' => $data['client_name'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            'description' => $data['description'],
            'amount' => $data['amount'],
            'service_date' => $serviceDate->toDateString(),
            'status' => Service::STATUS_ACTIVO,
        ]);

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

        $service->load(['company', 'user', 'photos', 'catalog:id,name']);

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
        $service->load(['company', 'user', 'photos', 'catalog:id,name']);

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

        $this->assertCatalogActive(isset($data['catalog_id']) ? (int) $data['catalog_id'] : null);

        $service->catalog_id = isset($data['catalog_id']) ? (int) $data['catalog_id'] : null;
        $service->client_name = $data['client_name'];
        $service->service_type = $data['service_type'];
        $service->description = $data['description'];
        $service->amount = $data['amount'];

        if ($service->isDirty()) {
            $service->status = Service::STATUS_CORREGIDO;
            $service->save();
        }

        $service->loadCount('invoices');
        $service->load(['company', 'user', 'photos', 'catalog:id,name']);

        ActivityLogger::log(
            $request->user(),
            'servicio_editado',
            'Editó servicio '.$service->code.' (ID '.$service->id.').'
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
        $service->load(['company', 'user', 'photos', 'catalog:id,name']);

        ActivityLogger::log(
            $request->user(),
            'servicio_archivado',
            'Marcó como eliminado el servicio '.$service->code.' (ID '.$service->id.').'
        );

        return new ServiceResource($service);
    }

    private function assertCatalogActive(?int $catalogId): void
    {
        if ($catalogId === null) {
            return;
        }

        $row = ServiceCatalog::query()->find($catalogId);
        if ($row === null || $row->status !== ServiceCatalog::STATUS_ACTIVO) {
            throw ValidationException::withMessages([
                'catalog_id' => ['El ítem de catálogo no existe o no está activo.'],
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
