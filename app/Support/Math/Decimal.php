<?php

namespace App\Support\Math;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable exact decimal for money, rates, quantities and percentages.
 * Never construct from float: floats are rejected so binary rounding errors cannot enter calculations.
 */
final class Decimal implements JsonSerializable, Stringable
{
    public const MONEY_SCALE = 2;

    public const RATE_SCALE = 4;

    public const QTY_SCALE = 4;

    public const PERCENT_SCALE = 4;

    private const DIVISION_SCALE = 10;

    private function __construct(private readonly BigDecimal $value) {}

    public static function of(self|BigNumber|int|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::zero();
        }

        if (is_string($value) && ! preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim($value))) {
            throw new InvalidArgumentException("Invalid decimal value [{$value}].");
        }

        return new self(BigDecimal::of(is_string($value) ? trim($value) : $value));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /**
     * @param  iterable<self|int|string|null>  $values
     */
    public static function sum(iterable $values): self
    {
        $total = self::zero();
        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public function plus(self|int|string|null $other): self
    {
        return new self($this->value->plus(self::of($other)->value));
    }

    public function minus(self|int|string|null $other): self
    {
        return new self($this->value->minus(self::of($other)->value));
    }

    public function times(self|int|string|null $other): self
    {
        return new self($this->value->multipliedBy(self::of($other)->value));
    }

    public function dividedBy(self|int|string $other, int $scale = self::DIVISION_SCALE): self
    {
        $divisor = self::of($other);
        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self($this->value->dividedBy($divisor->value, $scale, RoundingMode::HalfUp));
    }

    /**
     * Amount × percent / 100, e.g. GST or retention on a base amount.
     */
    public function percentOf(self|int|string|null $percent): self
    {
        return $this->times($percent)->dividedBy(100);
    }

    public function round(int $scale = self::MONEY_SCALE): self
    {
        return new self($this->value->toScale($scale, RoundingMode::HalfUp));
    }

    public function toMoney(): string
    {
        return $this->round(self::MONEY_SCALE)->toString();
    }

    public function toRate(): string
    {
        return $this->round(self::RATE_SCALE)->toString();
    }

    public function toQuantity(): string
    {
        return $this->round(self::QTY_SCALE)->toString();
    }

    public function negate(): self
    {
        return new self($this->value->negated());
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    public function compareTo(self|int|string|null $other): int
    {
        return $this->value->compareTo(self::of($other)->value);
    }

    public function equals(self|int|string|null $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(self|int|string|null $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self|int|string|null $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self|int|string|null $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqual(self|int|string|null $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function toString(): string
    {
        return $this->value->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
