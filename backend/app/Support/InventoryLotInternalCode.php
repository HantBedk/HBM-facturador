<?php

namespace App\Support;

/**
 * Código interno legible para inventario: prioriza línea «Codigo interno:» en description,
 * luego referencia almacenada (columna legacy), serial y respaldo por id.
 */
final class InventoryLotInternalCode
{
    public static function extractFromDescription(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }
        foreach (preg_split("/\r\n|\n|\r/", $description) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'codigo interno:') === 0) {
                return trim(substr($line, strlen('codigo interno:')));
            }
        }

        return null;
    }

    public static function resolve(
        ?string $description,
        ?string $storedReference,
        int $lotId,
        ?string $serialNumber = null,
    ): string {
        $fromDesc = self::extractFromDescription($description);
        if ($fromDesc !== null && $fromDesc !== '') {
            return $fromDesc;
        }
        if ($storedReference !== null && trim((string) $storedReference) !== '') {
            return trim((string) $storedReference);
        }
        if ($serialNumber !== null && trim((string) $serialNumber) !== '') {
            return trim((string) $serialNumber);
        }

        return 'LOT-'.$lotId;
    }
}
