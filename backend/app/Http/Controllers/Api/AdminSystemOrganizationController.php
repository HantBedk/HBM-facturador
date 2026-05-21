<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\DevEmpresaSistemaSnapshotService;
use App\Services\SystemOrganizationProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminSystemOrganizationController extends Controller
{
    public function __construct(
        private readonly SystemOrganizationProfileService $organization,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $profile = $this->organization->profileForForm();
        $logo = $this->organization->logoMeta();

        return response()->json([
            'data' => array_merge($profile, [
                'logo_configured' => $logo !== null,
                'logo_filename' => $logo['original_filename'] ?? null,
            ]),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nit' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone_secondary' => ['sometimes', 'nullable', 'string', 'max:64'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_regimen' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $emailCheck = trim((string) ($data['email'] ?? ''));
        if ($emailCheck !== '' && ! filter_var($emailCheck, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Indique un correo válido o déjelo vacío.'],
            ]);
        }

        $this->organization->persist($data);
        $this->exportDevSnapshotIfLocal();

        ActivityLogger::log(
            $request->user(),
            'empresa_sistema_actualizada',
            'Actualizó los datos de la empresa del sistema (perfil operador).'
        );

        return response()->json([
            'message' => 'Datos guardados.',
            'data' => $this->payload(),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ]);

        $this->organization->storeLogo($request->file('file'));
        $this->exportDevSnapshotIfLocal();

        ActivityLogger::log(
            $request->user(),
            'empresa_sistema_logo',
            'Actualizó el logo de la empresa del sistema.'
        );

        return response()->json([
            'message' => 'Logo guardado.',
            'data' => $this->payload(),
        ]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $this->organization->deleteLogo();
        $this->exportDevSnapshotIfLocal();

        ActivityLogger::log(
            $request->user(),
            'empresa_sistema_logo',
            'Eliminó el logo de la empresa del sistema.'
        );

        return response()->json([
            'message' => 'Logo eliminado.',
            'data' => $this->payload(),
        ]);
    }

    public function logoFile(): BinaryFileResponse|JsonResponse
    {
        $path = $this->organization->logoAbsolutePath();
        if ($path === null || ! is_readable($path)) {
            return response()->json(['message' => 'Sin logo.'], 404);
        }

        $filename = $this->organization->logoMeta()['original_filename'] ?? 'logo';

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $profile = $this->organization->profileForForm();
        $logo = $this->organization->logoMeta();

        return array_merge($profile, [
            'logo_configured' => $logo !== null,
            'logo_filename' => $logo['original_filename'] ?? null,
            'invoice_emitter' => $this->organization->invoiceEmitterStatus(),
        ]);
    }

    private function exportDevSnapshotIfLocal(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        app(DevEmpresaSistemaSnapshotService::class)->exportCurrentProfileSnapshot();
    }
}
