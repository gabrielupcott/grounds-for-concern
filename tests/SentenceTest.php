<?php

declare(strict_types=1);

namespace App\Tests;

use App\Sentence;
use PHPUnit\Framework\TestCase;

final class SentenceTest extends TestCase
{
    private function rule(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Coffee budget',
            'window_days' => 7,
            'group' => ['match' => 'all', 'conditions' => [['field' => 'category', 'operator' => 'is', 'value' => 'coffee']]],
            'threshold' => ['metric' => 'total', 'operator' => '>', 'value' => 60],
        ], $overrides);
    }

    public function testDemoSentence(): void
    {
        $this->assertSame(
            'Alert me when I spend more than $60 on coffee within any 7 days.',
            Sentence::render($this->rule())
        );
    }

    public function testAtLeastKeepsCents(): void
    {
        $rule = $this->rule(['threshold' => ['metric' => 'total', 'operator' => '>=', 'value' => 12.5]]);
        $this->assertSame(
            'Alert me when I spend at least $12.50 on coffee within any 7 days.',
            Sentence::render($rule)
        );
    }

    public function testCountMetric(): void
    {
        $rule = $this->rule(['threshold' => ['metric' => 'count', 'operator' => '>=', 'value' => 4]]);
        $this->assertSame(
            'Alert me when I make at least 4 purchases on coffee within any 7 days.',
            Sentence::render($rule)
        );
    }

    public function testSingleDayWindow(): void
    {
        $rule = $this->rule(['window_days' => 1]);
        $this->assertStringEndsWith('on coffee in a single day.', Sentence::render($rule));
    }

    public function testAnyGroupJoinsWithOr(): void
    {
        $rule = $this->rule([
            'group' => ['match' => 'any', 'conditions' => [
                ['field' => 'merchant', 'operator' => 'is', 'value' => 'Starbucks'],
                ['field' => 'category', 'operator' => 'is', 'value' => 'food'],
            ]],
        ]);
        $this->assertSame(
            'Alert me when I spend more than $60 at Starbucks or on food within any 7 days.',
            Sentence::render($rule)
        );
    }

    public function testConditionPhrasings(): void
    {
        $cases = [
            [[ 'field' => 'category', 'operator' => 'is_not', 'value' => 'food'], 'on anything but food'],
            [[ 'field' => 'merchant', 'operator' => 'is', 'value' => 'Starbucks'], 'at Starbucks'],
            [[ 'field' => 'merchant', 'operator' => 'is_not', 'value' => 'Starbucks'], 'anywhere but Starbucks'],
            [[ 'field' => 'merchant', 'operator' => 'contains', 'value' => 'bux'], 'at merchants containing “bux”'],
        ];

        foreach ($cases as [$condition, $expectedPhrase]) {
            $rule = $this->rule(['group' => ['match' => 'all', 'conditions' => [$condition]]]);
            $this->assertStringContainsString($expectedPhrase, Sentence::render($rule));
        }
    }
}
