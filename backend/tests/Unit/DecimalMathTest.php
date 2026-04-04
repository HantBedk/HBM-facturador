<?php

namespace Tests\Unit;

use App\Support\DecimalMath;
use PHPUnit\Framework\TestCase;

class DecimalMathTest extends TestCase
{
    public function test_adds_scaled_decimals(): void
    {
        $this->assertSame('10.50', DecimalMath::add('10.00', '0.50', 2));
        $this->assertSame('0.03', DecimalMath::add('0.01', '0.02', 2));
        $this->assertSame('100.00', DecimalMath::add('99.99', '0.01', 2));
    }

    public function test_accumulates_like_service_total(): void
    {
        $total = '0.00';
        foreach (['12.34', '0.66', '5.00'] as $a) {
            $total = DecimalMath::add($total, $a, 2);
        }
        $this->assertSame('18.00', $total);
    }
}
