<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The Calculate block's formula language, evaluated without eval():
 *
 *   numbers, 'text', {{placeholders}}, + - * / %, ( ), comparisons are not needed (use a Condition)
 *   round(x[, decimals]) floor(x) ceil(x) abs(x) min(a, b, …) max(a, b, …)
 *   days_between(from, to)  → whole days from one date to another ("2026-10-15", {{var.pickup}})
 *   add_days(date, n)       → the date n days later, as YYYY-MM-DD
 *   today()                 → today's date, as YYYY-MM-DD
 *   pick(value, 'A', 1, 'B', 2[, other]) → the number paired with the matching text (price tables:
 *                             pick({{var.size}}, 'Studio', 150, '1BR', 200, 0))
 *
 * Example: days_between({{var.pickup}}, {{var.return}}) * {{var.product.price}}
 */
final class Expression
{
    private const FUNCTIONS = ['round', 'floor', 'ceil', 'abs', 'min', 'max', 'days_between', 'add_days', 'today', 'pick'];

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];

    private int $pos = 0;

    /** @var callable(string): mixed */
    private $resolve;

    private string $timezone;

    /** @param callable(string): mixed $resolve placeholder path → value */
    private function __construct(string $expression, callable $resolve, string $timezone)
    {
        $this->tokens = self::tokenize($expression);
        $this->resolve = $resolve;
        $this->timezone = $timezone;
    }

    public static function valid(string $expression): bool
    {
        try {
            (new self($expression, fn () => 1, 'UTC'))->parse(dryRun: true);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  callable(string): mixed  $resolve
     *
     * @throws InvalidArgumentException when the formula is wrong or a value is not a number
     */
    public static function evaluate(string $expression, callable $resolve, string $timezone = 'UTC'): float|string
    {
        return (new self($expression, $resolve, $timezone))->parse();
    }

    private function parse(bool $dryRun = false): float|string
    {
        if ($this->tokens === []) {
            throw new InvalidArgumentException('The formula is empty.');
        }
        $value = $this->sum($dryRun);
        if ($this->pos !== count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected “'.$this->tokens[$this->pos][1].'”.');
        }

        return $value;
    }

    private function sum(bool $dry): float|string
    {
        $left = $this->product($dry);
        while ($this->peek('op', ['+', '-'])) {
            $op = $this->tokens[$this->pos++][1];
            $right = $this->product($dry);
            $left = $op === '+' ? self::num($left) + self::num($right) : self::num($left) - self::num($right);
        }

        return $left;
    }

    private function product(bool $dry): float|string
    {
        $left = $this->unary($dry);
        while ($this->peek('op', ['*', '/', '%'])) {
            $op = $this->tokens[$this->pos++][1];
            $right = self::num($this->unary($dry));
            if ($op !== '*' && $right == 0.0) {
                if ($dry) {
                    $left = 0.0;

                    continue;
                }
                throw new InvalidArgumentException('Division by zero.');
            }
            $left = match ($op) {
                '*' => self::num($left) * $right,
                '/' => self::num($left) / $right,
                default => fmod(self::num($left), $right),
            };
        }

        return $left;
    }

    private function unary(bool $dry): float|string
    {
        if ($this->peek('op', ['-', '+'])) {
            $op = $this->tokens[$this->pos++][1];
            $v = self::num($this->unary($dry));

            return $op === '-' ? -$v : $v;
        }

        return $this->atom($dry);
    }

    private function atom(bool $dry): float|string
    {
        $token = $this->tokens[$this->pos++] ?? throw new InvalidArgumentException('The formula ends too early.');
        [$kind, $text] = $token;

        if ($kind === 'num') {
            return (float) $text;
        }
        if ($kind === 'str') {
            return $text;
        }
        if ($kind === 'var') {
            $value = ($this->resolve)($text);

            return is_numeric($value) ? (float) $value : Placeholders::text($value);
        }
        if ($kind === '(') {
            $v = $this->sum($dry);
            $this->expect(')');

            return $v;
        }
        if ($kind === 'id') {
            $name = strtolower($text);
            if (! in_array($name, self::FUNCTIONS, true)) {
                throw new InvalidArgumentException("Unknown function “{$text}”.");
            }
            $this->expect('(');
            $args = [];
            if (! $this->peek(')')) {
                do {
                    $args[] = $this->sum($dry);
                } while ($this->peek(',') && ++$this->pos);
            }
            $this->expect(')');

            return $this->call($name, $args, $dry);
        }

        throw new InvalidArgumentException("Unexpected “{$text}”.");
    }

    /** @param list<float|string> $a */
    private function call(string $name, array $a, bool $dry): float|string
    {
        $need = fn (int $min, int $max) => count($a) >= $min && count($a) <= $max ? null : throw new InvalidArgumentException("{$name}() takes ".($min === $max ? $min : "{$min}–{$max}").' value(s).');
        switch ($name) {
            case 'round':
                $need(1, 2);

                return round(self::num($a[0]), (int) self::num($a[1] ?? 0));
            case 'floor':
            case 'ceil':
            case 'abs':
                $need(1, 1);

                return $name(self::num($a[0]));
            case 'min':
            case 'max':
                if ($a === []) {
                    throw new InvalidArgumentException("{$name}() needs at least one value.");
                }
                $nums = array_map(self::num(...), $a);

                return $name === 'min' ? min($nums) : max($nums);
            case 'pick':
                if (count($a) < 3) {
                    throw new InvalidArgumentException('pick() needs a value and at least one “text, number” pair.');
                }
                $value = mb_strtolower(trim(is_float($a[0]) ? Placeholders::text($a[0]) : $a[0]));
                $pairs = array_slice($a, 1);
                for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                    $key = mb_strtolower(trim(is_float($pairs[$i]) ? Placeholders::text($pairs[$i]) : $pairs[$i]));
                    if ($dry || $key === $value) {
                        return $pairs[$i + 1];
                    }
                }
                if (count($pairs) % 2 === 1) {
                    return $pairs[count($pairs) - 1];
                }
                throw new InvalidArgumentException('“'.$value.'” is not in the pick() table.');
            case 'today':
                $need(0, 0);

                return now($this->timezone)->toDateString();
            case 'days_between':
                $need(2, 2);
                if ($dry) {
                    return 1.0;
                }

                return (float) $this->date($a[0])->diffInDays($this->date($a[1]), false);
            default: // add_days
                $need(2, 2);
                if ($dry) {
                    return '2026-01-01';
                }

                return $this->date($a[0])->addDays((int) self::num($a[1]))->toDateString();
        }
    }

    private function date(float|string $value): Carbon
    {
        $ts = is_string($value) ? strtotime($value) : false;
        if ($ts === false) {
            throw new InvalidArgumentException('“'.Placeholders::text($value).'” is not a date.');
        }

        return Carbon::createFromTimestamp($ts, $this->timezone)->startOfDay();
    }

    private static function num(float|string $v): float
    {
        if (is_float($v)) {
            return $v;
        }
        $clean = str_replace([',', ' '], '', preg_replace('/^[^\d\-+.]+|[^\d.]+$/u', '', $v) ?? '');
        if (! is_numeric($clean)) {
            throw new InvalidArgumentException('“'.$v.'” is not a number.');
        }

        return (float) $clean;
    }

    /** @param list<string>|null $values */
    private function peek(string $kind, ?array $values = null): bool
    {
        $t = $this->tokens[$this->pos] ?? null;

        return $t !== null && $t[0] === $kind && ($values === null || in_array($t[1], $values, true));
    }

    private function expect(string $kind): void
    {
        if (! $this->peek($kind)) {
            throw new InvalidArgumentException("Expected “{$kind}”.");
        }
        $this->pos++;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif (preg_match('/\G\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', $s, $m, 0, $i) === 1) {
                $tokens[] = ['var', $m[1]];
                $i += strlen($m[0]);
            } elseif (preg_match('/\G\d+(\.\d+)?/', $s, $m, 0, $i) === 1) {
                $tokens[] = ['num', $m[0]];
                $i += strlen($m[0]);
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $i) === 1) {
                $tokens[] = ['id', $m[0]];
                $i += strlen($m[0]);
            } elseif ($c === "'" || $c === '"') {
                $end = strpos($s, $c, $i + 1);
                if ($end === false) {
                    throw new InvalidArgumentException('A quote is not closed.');
                }
                $tokens[] = ['str', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
            } elseif (str_contains('+-*/%', $c)) {
                $tokens[] = ['op', $c];
                $i++;
            } elseif (in_array($c, ['(', ')', ','], true)) {
                $tokens[] = [$c, $c];
                $i++;
            } else {
                throw new InvalidArgumentException("“{$c}” cannot be used in a formula.");
            }
        }

        return $tokens;
    }
}
