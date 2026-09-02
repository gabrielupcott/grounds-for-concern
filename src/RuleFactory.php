<?php

declare(strict_types=1);

namespace App;

/**
 * Turns the rule-builder form POST into a rule definition array (the JSON DSL
 * the Go engine consumes) — or rejects it with errors the UI can show
 * field-by-field.
 *
 * The form posts parallel arrays (cond_field[], cond_operator[], cond_value[])
 * because that is what a plain HTML form can do without JavaScript.
 */
final class RuleFactory
{
    public const WINDOWS = [1, 3, 7, 14, 30];
    private const FIELDS = ['category', 'merchant'];
    private const OPERATORS = ['is', 'is_not', 'contains'];
    private const METRICS = ['total', 'count'];
    private const THRESHOLD_OPS = ['>', '>='];

    /**
     * @return array rule definition
     * @throws RuleValidationException with field-keyed errors
     */
    public static function fromForm(array $post): array
    {
        $errors = [];

        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 120 characters or fewer.';
        }

        $windowDays = (int) ($post['window_days'] ?? 0);
        if (!in_array($windowDays, self::WINDOWS, true)) {
            $errors['window_days'] = 'Select a window.';
        }

        $match = (string) ($post['group_match'] ?? '');
        if ($match !== 'any' && $match !== 'all') {
            $errors['group_match'] = 'Select a match mode.';
        }

        // Conditions: rows of parallel arrays; completely empty rows are skipped
        // so "Add condition" then leaving it blank isn't an error.
        $conditions = [];
        $fields = (array) ($post['cond_field'] ?? []);
        $operators = (array) ($post['cond_operator'] ?? []);
        $values = (array) ($post['cond_value'] ?? []);

        foreach ($fields as $i => $field) {
            $field = trim((string) $field);
            $operator = trim((string) ($operators[$i] ?? ''));
            $value = trim((string) ($values[$i] ?? ''));

            if ($field === '' && $operator === '' && $value === '') {
                continue; // untouched row
            }

            if (!in_array($field, self::FIELDS, true)) {
                $errors["cond_$i"] = 'Select a field.';
                continue;
            }
            if (!in_array($operator, self::OPERATORS, true)) {
                $errors["cond_$i"] = 'Select an operator.';
                continue;
            }
            if ($value === '') {
                $errors["cond_$i"] = 'Value is required.';
                continue;
            }
            if (mb_strlen($value) > 120) {
                $errors["cond_$i"] = 'Value must be 120 characters or fewer.';
                continue;
            }
            $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        if ($conditions === [] && !isset($errors['cond_0'])) {
            $errors['cond_0'] = 'Add at least one condition.';
        }

        $metric = (string) ($post['threshold_metric'] ?? '');
        if (!in_array($metric, self::METRICS, true)) {
            $errors['threshold_metric'] = 'Select a metric.';
        }

        $thresholdOp = (string) ($post['threshold_operator'] ?? '');
        if (!in_array($thresholdOp, self::THRESHOLD_OPS, true)) {
            $errors['threshold_operator'] = 'Select an operator.';
        }

        $rawValue = str_replace(['$', ','], '', (string) ($post['threshold_value'] ?? ''));
        $thresholdValue = $rawValue === '' ? null : round((float) $rawValue, 2);
        if ($thresholdValue === null || $thresholdValue <= 0) {
            $errors['threshold_value'] = 'Enter an amount greater than zero.';
        } elseif ($metric === 'count' && floor($thresholdValue) != $thresholdValue) {
            $errors['threshold_value'] = 'Count must be a whole number.';
        } elseif ($thresholdValue > 100000) {
            $errors['threshold_value'] = 'Amount is too large.';
        }

        if ($errors) {
            throw new RuleValidationException($errors);
        }

        return [
            'name' => $name,
            'window_days' => $windowDays,
            'group' => [
                'match' => $match,
                'conditions' => $conditions,
            ],
            'threshold' => [
                'metric' => $metric,
                'operator' => $thresholdOp,
                'value' => $thresholdValue,
            ],
        ];
    }

    /**
     * The shape the builder opens with — a sensible starting point, not a
     * finished rule (no name, so saving without typing one shows validation).
     */
    public static function defaultDefinition(): array
    {
        return [
            'name' => '',
            'window_days' => 7,
            'group' => [
                'match' => 'all',
                'conditions' => [['field' => 'category', 'operator' => 'is', 'value' => 'coffee']],
            ],
            'threshold' => ['metric' => 'total', 'operator' => '>', 'value' => 60],
        ];
    }
}
