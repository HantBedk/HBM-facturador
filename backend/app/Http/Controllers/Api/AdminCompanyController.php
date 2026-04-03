<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Company::query()->orderBy('nombre');

        if ($request->filled('q')) {
            $raw = $request->string('q')->toString();
            $term = '%'.addcslashes($raw, '%_\\').'%';
            $q->where(function ($w) use ($term) {
                $w->where('nombre', 'like', $term)
                    ->orWhere('nit', 'like', $term)
                    ->orWhere('correo', 'like', $term);
            });
        }

        if ($request->filled('estado') && in_array($request->string('estado')->toString(), [
            Company::ESTADO_ACTIVO,
            Company::ESTADO_INACTIVO,
        ], true)) {
            $q->where('estado', $request->string('estado')->toString());
        }

        return CompanyResource::collection($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'factura_sigla' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', Rule::unique('companies', 'factura_sigla')],
            'nit' => ['nullable', 'string', 'max:100', Rule::unique('companies', 'nit')],
            'telefono' => ['nullable', 'string', 'max:64'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'estado' => ['sometimes', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $nombre = trim($data['nombre']);
        $this->assertNombreUnique($nombre);

        $company = Company::query()->create([
            'nombre' => $nombre,
            'factura_sigla' => strtoupper($data['factura_sigla']),
            'nit' => isset($data['nit']) && $data['nit'] !== '' ? trim($data['nit']) : null,
            'telefono' => isset($data['telefono']) && $data['telefono'] !== '' ? trim($data['telefono']) : null,
            'correo' => isset($data['correo']) && $data['correo'] !== '' ? trim($data['correo']) : null,
            'estado' => $data['estado'] ?? Company::ESTADO_ACTIVO,
        ]);

        return (new CompanyResource($company))->response()->setStatusCode(201);
    }

    public function update(Request $request, Company $company): CompanyResource
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'factura_sigla' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Za-z]{3}$/',
                Rule::unique('companies', 'factura_sigla')->ignore($company->id),
            ],
            'nit' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('companies', 'nit')->ignore($company->id),
            ],
            'telefono' => ['nullable', 'string', 'max:64'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'estado' => ['required', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $nombre = trim($data['nombre']);
        $this->assertNombreUnique($nombre, $company->id);

        $company->nombre = $nombre;
        $company->factura_sigla = strtoupper($data['factura_sigla']);
        $company->nit = isset($data['nit']) && $data['nit'] !== '' ? trim($data['nit']) : null;
        $company->telefono = isset($data['telefono']) && $data['telefono'] !== '' ? trim($data['telefono']) : null;
        $company->correo = isset($data['correo']) && $data['correo'] !== '' ? trim($data['correo']) : null;
        $company->estado = $data['estado'];
        $company->save();

        return new CompanyResource($company);
    }

    public function updateEstado(Request $request, Company $company): CompanyResource
    {
        $data = $request->validate([
            'estado' => ['required', Rule::in([Company::ESTADO_ACTIVO, Company::ESTADO_INACTIVO])],
        ]);

        $company->estado = $data['estado'];
        $company->save();

        return new CompanyResource($company);
    }

    private function assertNombreUnique(string $nombre, ?int $ignoreId = null): void
    {
        $normalized = mb_strtolower($nombre);
        $q = Company::query()->whereRaw('LOWER(TRIM(nombre)) = ?', [$normalized]);
        if ($ignoreId !== null) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([
                'nombre' => ['Ya existe una empresa con el mismo nombre (sin distinguir mayúsculas).'],
            ]);
        }
    }
}
