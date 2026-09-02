<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Thin cURL client for the Go rule engine.
 *
 * The engine is stateless: it gets the rule and the relevant transactions in
 * one POST and returns a verdict. No shared database, no session state.
 */
final class EngineClient
{
    public function __construct(private string $baseUrl)
    {
    }

    /** @return array verdict from POST /v1/evaluate */
    public function evaluate(array $rule, array $transactions, ?string $asOn = null): array
    {
        $body = ['rule' => $rule, 'transactions' => $transactions];
        if ($asOn !== null) {
            $body['as_on'] = $asOn;
        }
        return $this->post('/v1/evaluate', $body);
    }

    /** @return array backtest result from POST /v1/backtest */
    public function backtest(array $rule, array $transactions, string $from, string $to): array
    {
        return $this->post('/v1/backtest', [
            'rule' => $rule,
            'transactions' => $transactions,
            'from' => $from,
            'to' => $to,
        ]);
    }

    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('rule engine unreachable: ' . curl_error($ch));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
            $msg = is_array($decoded) && isset($decoded['error']) ? $decoded['error'] : $raw;
            throw new RuntimeException("rule engine error ({$status}): {$msg}");
        }
        return $decoded;
    }
}
