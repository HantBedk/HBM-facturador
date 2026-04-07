<?php

namespace App\Services;

use App\Models\Company;
use App\Support\CompanyFacturaSiglaAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cliente «puntual»: se representa como Company con datos mínimos; el teléfono normalizado evita duplicados.
 */
final class QuickClientCompanyResolver
{
    public function resolveOrCreate(string $nombre, string $telefonoRaw): Company
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) < 2) {
            throw ValidationException::withMessages([
                'quick_client.nombre' => ['Indica el nombre del cliente (mín. 2 caracteres).'],
            ]);
        }

        $key = self::normalizePhoneKey($telefonoRaw);
        if (strlen($key) < 7) {
            throw ValidationException::withMessages([
                'quick_client.telefono' => ['El teléfono debe tener al menos 7 dígitos.'],
            ]);
        }
        if (strlen($key) > 15) {
            throw ValidationException::withMessages([
                'quick_client.telefono' => ['El teléfono no es válido.'],
            ]);
        }

        $telefonoDisplay = trim($telefonoRaw);
        if ($telefonoDisplay === '') {
            $telefonoDisplay = $key;
        }

        try {
            return DB::transaction(function () use ($nombre, $key, $telefonoDisplay) {
                $existing = Company::query()
                    ->where('es_cliente_puntual', true)
                    ->where('telefono_normalizado', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->nombre = $nombre;
                    $existing->telefono = $telefonoDisplay;
                    $existing->save();

                    return $existing->fresh();
                }

                $company = new Company([
                    'nombre' => $nombre,
                    'nit' => null,
                    'telefono' => $telefonoDisplay,
                    'telefono_normalizado' => $key,
                    'correo' => null,
                    'estado' => Company::ESTADO_ACTIVO,
                    'es_cliente_puntual' => true,
                    'factura_sigla' => null,
                ]);
                $company->save();

                $used = [];
                foreach (Company::query()->whereNotNull('factura_sigla')->pluck('factura_sigla') as $s) {
                    $used[(string) $s] = true;
                }
                $sigla = CompanyFacturaSiglaAllocator::allocate($nombre, (int) $company->id, $used);
                $company->factura_sigla = $sigla;
                $company->save();

                return $company->fresh();
            });
        } catch (QueryException $e) {
            // Carrera: otro request creó el mismo teléfono; reintenta lectura.
            if ($this->isDuplicateKey($e)) {
                $found = Company::query()
                    ->where('es_cliente_puntual', true)
                    ->where('telefono_normalizado', $key)
                    ->first();
                if ($found !== null) {
                    $found->nombre = $nombre;
                    $found->telefono = $telefonoDisplay;
                    $found->save();

                    return $found->fresh();
                }
            }
            throw $e;
        }
    }

    public static function normalizePhoneKey(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) >= 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'duplicate')
            || str_contains($msg, 'unique')
            || str_contains($msg, 'constraint');
    }
}
