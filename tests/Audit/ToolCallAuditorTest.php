<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Audit;

use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCaller;
use Koh\SyliusMcp\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class ToolCallAuditorTest extends TestCase
{
    /**
     * The record answers the governance questions: who (user, token, client,
     * address), what (tool, class, arguments), and what came of it.
     */
    public function testARecordCarriesWhoWhatAndOutcome(): void
    {
        $logger = new MemoryLogger();
        (new ToolCallAuditor($logger))->record(
            'update_product',
            (new ToolPolicyProvider())->get('update_product'),
            new McpCaller('admin@example.com', ['mcp:write'], 42, 'mcpc_abc', '203.0.113.7'),
            ['code' => 'MUG'],
            'ok',
            microtime(true),
        );

        $context = $logger->records[0]['context'];
        self::assertSame(ToolCallAuditor::MESSAGE, $logger->records[0]['message']);
        self::assertSame('update_product', $context['tool']);
        self::assertSame('high_risk', $context['class']);
        self::assertSame('ok', $context['outcome']);
        self::assertSame('admin@example.com', $context['user']);
        self::assertSame(42, $context['token_id']);
        self::assertSame('mcpc_abc', $context['client_id']);
        self::assertSame('203.0.113.7', $context['ip']);
        self::assertSame(['code' => 'MUG'], $context['arguments']);
        self::assertIsInt($context['duration_ms']);
    }

    /** A cart's tokenValue is a bearer credential; addresses and e-mails are personal data. */
    public function testSecretsAndPersonalDataAreRedacted(): void
    {
        $redacted = (new ToolCallAuditor())->redact([
            'tokenValue' => 'cart-secret',
            'email' => 'jane@example.com',
            'billingAddress' => ['street' => 'Main 1', 'city' => 'Trier'],
            'shipping' => ['phoneNumber' => '0651', 'city' => 'Trier'],
            'code' => 'MUG',
        ]);

        self::assertSame([
            'tokenValue' => '[redacted]',
            'email' => '[redacted]',
            'billingAddress' => '[redacted]',
            'shipping' => ['phoneNumber' => '[redacted]', 'city' => 'Trier'],
            'code' => 'MUG',
        ], $redacted);
    }

    public function testLongTextIsCutAndTheFullCallIsHashed(): void
    {
        $logger = new MemoryLogger();
        $description = str_repeat('a', 5000);
        (new ToolCallAuditor($logger, 20))->record('update_product', null, McpCaller::anonymous(), ['description' => $description], 'denied', microtime(true));

        $context = $logger->records[0]['context'];
        self::assertSame(str_repeat('a', 20).'… [5000 chars]', $context['arguments']['description']);
        self::assertSame(hash('sha256', (string) json_encode(['description' => $description])), $context['arguments_sha256']);
    }
}
