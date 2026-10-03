<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Audit;

use Koh\SyliusMcp\Policy\ToolPolicy;
use Koh\SyliusMcp\Security\McpCaller;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * One audit record per tools/call: who, through which token and client, from
 * where, called what with which arguments, and what came of it.
 *
 * The handbook's agent governance requires every agent action to be logged
 * with the triggering identity, the tool and its parameters (sensitive values
 * redacted), the outcome and a timestamp. Until now nothing on the shop side
 * could say which agent placed an order or changed a product.
 *
 * Written to the PSR-3 logger of the `koh_mcp_audit` channel, as one record
 * with a fixed message and the facts in the context, so a log pipeline can
 * index the fields. Where the records go and how long they stay — the
 * handbook says a year — is the application's monolog configuration.
 *
 * Arguments are logged, but not as given:
 *  - values under keys that name a secret or personal data (token, password,
 *    email, phone, address, iban …) are replaced by "[redacted]" — a cart's
 *    tokenValue is a bearer credential for that cart;
 *  - strings are cut to {@see self::$maxLength} characters — a product
 *    description is evidence of what was attempted at its first lines, and a
 *    whole article body in every log line is a second copy of the content;
 *  - a SHA-256 of the complete, unredacted arguments goes along, so two
 *    records can be shown to be the same call without storing either.
 */
final class ToolCallAuditor
{
    public const MESSAGE = 'MCP tool call';

    private const SENSITIVE = '/token|secret|password|passwd|credential|authorization|e-?mail|phone|iban|bic|card|cvc|address|street|postcode|birth/i';

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxLength = 200,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $extra     outcome-specific facts (reason, error)
     */
    public function record(
        string $tool,
        ?ToolPolicy $policy,
        McpCaller $caller,
        array $arguments,
        string $outcome,
        float $startedAt,
        array $extra = [],
    ): void {
        $this->logger->info(self::MESSAGE, [
            'event' => 'mcp.tool_call',
            'tool' => $tool,
            'class' => $policy?->class->value,
            'outcome' => $outcome,
            'user' => $caller->user,
            'token_id' => $caller->tokenId,
            'client_id' => $caller->clientId,
            'scopes' => $caller->scopes,
            'ip' => $caller->ip,
            'arguments' => $this->redact($arguments),
            'arguments_sha256' => hash('sha256', (string) json_encode($arguments, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_UNESCAPED_UNICODE)),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ...$extra,
        ]);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $values, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['…' => 'nested too deep'];
        }

        $out = [];
        foreach ($values as $key => $value) {
            if (\is_string($key) && 1 === preg_match(self::SENSITIVE, $key)) {
                $out[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $out[$key] = $this->redact($value, $depth + 1);
            } elseif (\is_string($value) && mb_strlen($value) > $this->maxLength) {
                $out[$key] = mb_substr($value, 0, $this->maxLength).'… ['.mb_strlen($value).' chars]';
            } elseif (\is_scalar($value) || null === $value) {
                $out[$key] = $value;
            } else {
                $out[$key] = get_debug_type($value);
            }
        }

        return $out;
    }
}
