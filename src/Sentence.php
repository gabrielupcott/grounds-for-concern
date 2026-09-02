<?php

declare(strict_types=1);

namespace App;

/**
 * Renders a rule definition as one plain-English sentence — the live preview
 * in the builder, and the summary stored with alerts.
 *
 * This lives server-side next to the rule itself so the sentence and the JSON
 * can never disagree: the preview is generated from the exact structure the
 * engine will evaluate.
 */
final class Sentence
{
    public static function render(array $rule): string
    {
        return sprintf(
            'Alert me when %s %s %s.',
            self::threshold($rule['threshold']),
            self::conditions($rule['group']),
            self::window((int) $rule['window_days'])
        );
    }

    private static function threshold(array $th): string
    {
        if ($th['metric'] === 'count') {
            $n = number_format($th['value'], 0);
            return $th['operator'] === '>'
                ? "I make more than {$n} purchases"
                : "I make at least {$n} purchases";
        }
        $amount = self::amount($th['value']);
        return $th['operator'] === '>'
            ? "I spend more than {$amount}"
            : "I spend at least {$amount}";
    }

    private static function conditions(array $group): string
    {
        $parts = array_map([self::class, 'condition'], $group['conditions']);
        $join = $group['match'] === 'any' ? ' or ' : ' and ';
        return implode($join, $parts);
    }

    private static function condition(array $c): string
    {
        return match (true) {
            $c['field'] === 'category' && $c['operator'] === 'is' => 'on ' . $c['value'],
            $c['field'] === 'category' && $c['operator'] === 'is_not' => 'on anything but ' . $c['value'],
            $c['field'] === 'category' && $c['operator'] === 'contains' => 'on categories containing “' . $c['value'] . '”',
            $c['field'] === 'merchant' && $c['operator'] === 'is' => 'at ' . $c['value'],
            $c['field'] === 'merchant' && $c['operator'] === 'is_not' => 'anywhere but ' . $c['value'],
            default => 'at merchants containing “' . $c['value'] . '”',
        };
    }

    private static function window(int $days): string
    {
        return $days === 1 ? 'in a single day' : "within any {$days} days";
    }

    /** $60 stays $60; $60.50 keeps its cents. */
    private static function amount(float $value): string
    {
        $s = number_format($value, floor($value) == $value ? 0 : 2);
        return '$' . $s;
    }
}
