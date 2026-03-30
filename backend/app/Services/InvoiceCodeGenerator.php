<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceCodeGenerator
{
    public function nextForYear(int $year): string
    {
        $prefix = 'FAC-'.$year.'-';
        $count = Invoice::query()->where('code', 'like', $prefix.'%')->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
