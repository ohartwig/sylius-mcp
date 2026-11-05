<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\DependencyInjection;

use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\DependencyInjection\AuditChannelHandlerPass;
use Koh\SyliusMcp\Security\McpCaller;
use Koh\SyliusMcp\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * AUD-016-F02: the audit trail went to a channel no handler named, and in
 * production Symfony's fingers_crossed default discarded it without a word.
 * The handler map below is the one MonologBundle builds from the consuming
 * shop's production configuration at the time of the review.
 */
#[CoversClass(AuditChannelHandlerPass::class)]
#[CoversClass(ToolCallAuditor::class)]
final class AuditChannelHandlerPassTest extends TestCase
{
    /** monolog.handlers_to_channels for main (fingers_crossed, !deprecation), console and deprecation. */
    private const PRODUCTION_WITHOUT_AUDIT_HANDLER = [
        'monolog.handler.main' => ['type' => 'exclusive', 'elements' => ['deprecation']],
        'monolog.handler.console' => ['type' => 'exclusive', 'elements' => ['event', 'doctrine']],
        'monolog.handler.deprecation' => ['type' => 'inclusive', 'elements' => ['deprecation']],
    ];

    public function testF02AnUnroutedChannelIsReportedAtBuild(): void
    {
        $container = $this->container(self::PRODUCTION_WITHOUT_AUDIT_HANDLER);

        (new AuditChannelHandlerPass())->process($container);

        self::assertTrue($container->getParameter(AuditChannelHandlerPass::UNROUTED_PARAMETER));
        self::assertNotSame([], array_filter($container->getCompiler()->getLog(), static fn (string $line): bool => str_contains($line, 'koh_mcp_audit')));
    }

    public function testF02ARequiredHandlerFailsTheBuild(): void
    {
        $container = $this->container(self::PRODUCTION_WITHOUT_AUDIT_HANDLER, require: true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('koh_mcp_audit');

        (new AuditChannelHandlerPass())->process($container);
    }

    public function testF02AHandlerNamingTheChannelRoutesIt(): void
    {
        $container = $this->container(self::PRODUCTION_WITHOUT_AUDIT_HANDLER + [
            'monolog.handler.koh_mcp_audit' => ['type' => 'inclusive', 'elements' => ['koh_mcp_audit']],
        ], require: true);

        (new AuditChannelHandlerPass())->process($container);

        self::assertFalse($container->getParameter(AuditChannelHandlerPass::UNROUTED_PARAMETER));
    }

    /** A catch-all handler is exactly what lost the records; it does not count. */
    public function testACatchAllHandlerDoesNotCount(): void
    {
        self::assertFalse(AuditChannelHandlerPass::isRouted(['monolog.handler.main' => null]));
        self::assertFalse(AuditChannelHandlerPass::isRouted(['monolog.handler.main' => ['type' => 'exclusive', 'elements' => ['koh_mcp_audit']]]));
    }

    public function testWithoutMonologThereIsNothingToCheck(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AuditChannelHandlerPass::REQUIRE_PARAMETER, true);

        (new AuditChannelHandlerPass())->process($container);

        self::assertFalse($container->getParameter(AuditChannelHandlerPass::UNROUTED_PARAMETER));
    }

    public function testF02AnUnroutedAuditorWarnsOncePerProcessAndStillRecords(): void
    {
        $warnings = [];
        $logger = new MemoryLogger();
        $auditor = new ToolCallAuditor($logger, 200, unrouted: true, warn: static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $caller = new McpCaller('admin@example.com', ['mcp:write'], 42, 'mcpc_test', '203.0.113.7');
        $auditor->record('update_product', null, $caller, ['code' => 'MUG'], 'ok', microtime(true));
        $auditor->record('update_product', null, $caller, ['code' => 'MUG'], 'ok', microtime(true));

        self::assertSame([AuditChannelHandlerPass::MESSAGE], $warnings);
        self::assertCount(2, $logger->records);
        self::assertSame('mcp.tool_call', $logger->records[0]['context']['event']);
    }

    public function testARoutedAuditorDoesNotWarn(): void
    {
        $warnings = [];
        $auditor = new ToolCallAuditor(new MemoryLogger(), 200, unrouted: false, warn: static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $auditor->record('search_products', null, new McpCaller('admin@example.com'), [], 'ok', microtime(true));

        self::assertSame([], $warnings);
    }

    /** @param array<string, array{type: string, elements: list<string>}|null> $handlersToChannels */
    private function container(array $handlersToChannels, bool $require = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('monolog.handlers_to_channels', $handlersToChannels);
        $container->setParameter(AuditChannelHandlerPass::REQUIRE_PARAMETER, $require);

        return $container;
    }
}
