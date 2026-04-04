<?php

namespace App\Support;

use App\Models\ServiceCatalog;
use Illuminate\Support\Collection;

/**
 * Detecta si una propuesta «Otro» es equivalente a un ítem ya existente en el catálogo activo
 * (mismo nombre o uno contenido en el otro, p. ej. «Instalación» vs «Instalación de software»).
 */
final class CatalogSuggestionDuplicateChecker
{
    public static function isRedundantWithActiveCatalog(string $suggestedName, int $companyId): bool
    {
        $norm = self::normalize($suggestedName);
        if (mb_strlen($norm) < 2) {
            return false;
        }

        $catalogNorms = ServiceCatalog::query()
            ->activos()
            ->forCompany($companyId)
            ->pluck('name')
            ->map(fn ($n) => self::normalize((string) $n))
            ->all();

        return self::normOverlapsAny($norm, $catalogNorms);
    }

    /**
     * @param  Collection<int, object{id: int, company_id: int, name: string}>  $rows
     * @return list<int>
     */
    public static function idsWithoutCatalogOverlap(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $catalogNormByCompany = [];
        foreach ($rows->groupBy(fn ($r) => (int) $r->company_id)->keys() as $cid) {
            $catalogNormByCompany[(int) $cid] = ServiceCatalog::query()
                ->activos()
                ->forCompany((int) $cid)
                ->pluck('name')
                ->map(fn ($n) => self::normalize((string) $n))
                ->all();
        }

        return $rows
            ->filter(function ($r) use ($catalogNormByCompany) {
                $norm = self::normalize((string) $r->name);
                if (mb_strlen($norm) < 2) {
                    return true;
                }
                $list = $catalogNormByCompany[(int) $r->company_id] ?? [];

                return ! self::normOverlapsAny($norm, $list);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private static function normalize(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /**
     * @param  list<string>  $normalizedCatalogNames
     */
    private static function normOverlapsAny(string $a, array $normalizedCatalogNames): bool
    {
        foreach ($normalizedCatalogNames as $b) {
            if ($a === $b) {
                return true;
            }
            if (mb_strlen($a) >= 4 && mb_strlen($a) <= mb_strlen($b) && mb_strpos($b, $a) !== false) {
                return true;
            }
            if (mb_strlen($b) >= 4 && mb_strlen($b) <= mb_strlen($a) && mb_strpos($a, $b) !== false) {
                return true;
            }
        }

        return false;
    }
}
