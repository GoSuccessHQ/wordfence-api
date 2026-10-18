<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Tests\Unit\Model;

use GoSuccess\Wordfence\Enum\SoftwareType;
use GoSuccess\Wordfence\Model\Software;
use GoSuccess\Wordfence\Model\VersionRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionRange::class)]
#[CoversClass(Software::class)]
final class VersionRangeTest extends TestCase
{
    #[DataProvider('ranges')]
    public function testContains(string $from, bool $fromInclusive, string $to, bool $toInclusive, string $version, bool $expected): void
    {
        self::assertSame($expected, new VersionRange($from, $fromInclusive, $to, $toInclusive)->contains($version));
    }

    /**
     * @return iterable<string, array{string, bool, string, bool, string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'upper bound inclusive' => ['1.0.0', true, '1.2.3', true, '1.2.3', true];
        yield 'upper bound exclusive' => ['1.0.0', true, '1.2.3', false, '1.2.3', false];
        yield 'lower bound exclusive' => ['1.0.0', false, '1.2.3', true, '1.0.0', false];
        yield 'inside' => ['1.0.0', true, '1.2.3', true, '1.1', true];
        yield 'below' => ['1.0.0', true, '1.2.3', true, '0.9.9', false];
        yield 'above' => ['1.0.0', true, '1.2.3', true, '1.2.4', false];
        yield 'any lower, trailing dot' => ['*', true, '1.0.', true, '1.0', true];
        yield 'any lower, above the trailing dot' => ['*', true, '1.0.', true, '1.0.1', false];
        yield 'any upper' => ['2.0', true, '*', true, '9.9', true];
        yield 'any upper, below' => ['2.0', true, '*', true, '1.9', false];
        yield 'any version' => ['*', true, '*', true, '0.1', true];
        yield 'single version, other spelling' => ['1.0', true, '1.0', true, '1.0.0', true];
        yield 'empty version' => ['1.0', true, '2.0', true, '', false];
        yield 'any upper, exclusive flag ignored' => ['1.0', true, '*', false, '99', true];
    }

    public function testSoftwareAffectsIfAnyRangeContains(): void
    {
        $software = new Software(null, SoftwareType::Plugin, 'Example', 'example', [
            '1.0 - 1.1' => new VersionRange('1.0', true, '1.1', true),
            '2.0 - 2.1' => new VersionRange('2.0', true, '2.1', true),
        ], true, ['1.2', '2.2'], null);

        self::assertTrue($software->affects('2.0.5'));
        self::assertTrue($software->affects('1.0'));
        self::assertFalse($software->affects('1.5'));
        self::assertFalse($software->affects('2.2'));
    }
}
