<?php

namespace App\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class Money implements Stringable
{
    private const SCALE = 4;

    private const MAX = '999999999999999.9999';

    private function __construct(private string $value, private string $currencyCode) {}

    public static function of(string $amount, string $currency): self
    {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A currency must be a three-letter code.');
        }

        $amount = trim($amount);
        if (! preg_match('/^[+-]?\d+(?:\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException('Money must be an exact decimal with at most four fraction digits.');
        }

        $canonical = bcadd($amount, '0', self::SCALE);
        if (bccomp(self::absolute($canonical), self::MAX, self::SCALE) === 1) {
            throw new InvalidArgumentException('Money exceeds DECIMAL(19,4).');
        }
        if (bccomp($canonical, '0', self::SCALE) === 0) {
            $canonical = '0.0000';
        }

        return new self($canonical, $currency);
    }

    public function amount(): string
    {
        return $this->value;
    }

    public function currency(): string
    {
        return $this->currencyCode;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of(bcadd($this->value, $other->value, self::SCALE), $this->currencyCode);
    }

    public function negate(): self
    {
        return self::of(bcsub('0', $this->value, self::SCALE), $this->currencyCode);
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return bccomp($this->value, $other->value, self::SCALE) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function convert(string $currency, string $rate): self
    {
        if (! preg_match('/^\d+(?:\.\d{1,12})?$/', $rate) || bccomp($rate, '0', 12) <= 0) {
            throw new InvalidArgumentException('An exchange rate must be an exact positive decimal.');
        }

        $product = bcmul($this->value, $rate, 16);

        return self::of(self::roundHalfUp($product, self::SCALE), $currency);
    }

    public function __toString(): string
    {
        return $this->value.' '.$this->currencyCode;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currencyCode !== $other->currencyCode) {
            throw new InvalidArgumentException('Money currencies must match.');
        }
    }

    private static function absolute(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', $scale + 8) < 0
            ? bcsub($value, $increment, $scale + 8)
            : bcadd($value, $increment, $scale + 8);

        return bcadd($adjusted, '0', $scale);
    }
}
