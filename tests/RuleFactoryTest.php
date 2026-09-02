<?php

declare(strict_types=1);

namespace App\Tests;

use App\RuleFactory;
use App\RuleValidationException;
use PHPUnit\Framework\TestCase;

final class RuleFactoryTest extends TestCase
{
    private function coffeeForm(): array
    {
        return [
            'name' => 'Coffee budget',
            'window_days' => '7',
            'group_match' => 'all',
            'cond_field' => ['category'],
            'cond_operator' => ['is'],
            'cond_value' => ['coffee'],
            'threshold_metric' => 'total',
            'threshold_operator' => '>',
            'threshold_value' => '60',
        ];
    }

    public function testBuildsValidRule(): void
    {
        $rule = RuleFactory::fromForm($this->coffeeForm());

        $this->assertSame('Coffee budget', $rule['name']);
        $this->assertSame(7, $rule['window_days']);
        $this->assertSame('all', $rule['group']['match']);
        $this->assertSame([['field' => 'category', 'operator' => 'is', 'value' => 'coffee']], $rule['group']['conditions']);
        $this->assertSame(['metric' => 'total', 'operator' => '>', 'value' => 60.0], $rule['threshold']);
    }

    public function testSkipsUntouchedConditionRows(): void
    {
        $form = $this->coffeeForm();
        $form['cond_field'][] = '';
        $form['cond_operator'][] = '';
        $form['cond_value'][] = '';

        $rule = RuleFactory::fromForm($form);
        $this->assertCount(1, $rule['group']['conditions']);
    }

    public function testCleansCurrencyInput(): void
    {
        $form = $this->coffeeForm();
        $form['threshold_value'] = '$1,250.50';

        $rule = RuleFactory::fromForm($form);
        $this->assertSame(1250.5, $rule['threshold']['value']);
    }

    /** @return iterable<array-key, array{0: array, 1: string}> */
    public static function invalidForms(): iterable
    {
        yield 'missing name' => [['name' => ''], 'name'];
        yield 'name too long' => [['name' => str_repeat('x', 121)], 'name'];
        yield 'bad window' => [['window_days' => '5'], 'window_days'];
        yield 'bad group match' => [['group_match' => 'sometimes'], 'group_match'];
        yield 'no conditions' => [['cond_field' => [''], 'cond_operator' => [''], 'cond_value' => ['']], 'cond_0'];
        yield 'bad condition field' => [['cond_field' => ['amount'], 'cond_operator' => ['is'], 'cond_value' => ['5']], 'cond_0'];
        yield 'bad condition operator' => [['cond_field' => ['category'], 'cond_operator' => ['like'], 'cond_value' => ['coffee']], 'cond_0'];
        yield 'empty condition value' => [['cond_field' => ['merchant'], 'cond_operator' => ['is'], 'cond_value' => [' ']], 'cond_0'];
        yield 'bad metric' => [['threshold_metric' => 'average'], 'threshold_metric'];
        yield 'bad threshold operator' => [['threshold_operator' => '<'], 'threshold_operator'];
        yield 'zero threshold' => [['threshold_value' => '0'], 'threshold_value'];
        yield 'negative threshold' => [['threshold_value' => '-5'], 'threshold_value'];
        yield 'fractional count' => [['threshold_metric' => 'count', 'threshold_value' => '2.5'], 'threshold_value'];
    }

    /**
     * @dataProvider invalidForms
     */
    public function testRejectsInvalidForms(array $overrides, string $expectedKey): void
    {
        $form = array_merge($this->coffeeForm(), $overrides);

        try {
            RuleFactory::fromForm($form);
            $this->fail('Expected RuleValidationException');
        } catch (RuleValidationException $e) {
            $this->assertArrayHasKey($expectedKey, $e->errors);
        }
    }
}
