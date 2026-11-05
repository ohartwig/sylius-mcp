<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes an unrouted audit channel loud (AUD-016-F02).
 *
 * The tool-call audit trail is written at INFO on the monolog channel
 * `koh_mcp_audit`. A channel no handler names falls to the application's
 * catch-all handler, and in Symfony's production default that is a
 * fingers_crossed buffer that writes nothing unless an error occurs in the
 * same request: every successful, refused or previewed tool call vanished.
 *
 * So the channel counts as routed only when a handler names it in an
 * inclusive `channels` list. Otherwise this pass
 *  - writes the reason into the container build log (`debug:container`),
 *  - sets `koh_sylius_mcp.audit.unrouted`, on which ToolCallAuditor warns
 *    once per process on stderr (where container log collection reads), and
 *  - with `audit.require_handler: true` fails the container build, so
 *    `lint:container` / `cache:clear` in CI stop on it.
 *
 * Without MonologBundle there is nothing to check: the application wired its
 * own logger and answers for it.
 */
final class AuditChannelHandlerPass implements CompilerPassInterface
{
    public const CHANNEL = 'koh_mcp_audit';

    public const UNROUTED_PARAMETER = 'koh_sylius_mcp.audit.unrouted';

    public const REQUIRE_PARAMETER = 'koh_sylius_mcp.audit.require_handler';

    public const MESSAGE = 'koh/sylius-mcp: no monolog handler names the "koh_mcp_audit" channel, so the MCP tool-call audit trail goes to the catch-all handler (in production usually a fingers_crossed buffer that discards it). Give the channel its own handler: see the README, "Audit trail".';

    public function process(ContainerBuilder $container): void
    {
        $require = $container->hasParameter(self::REQUIRE_PARAMETER) && true === $container->getParameter(self::REQUIRE_PARAMETER);

        if (!$container->hasParameter('monolog.handlers_to_channels')) {
            $container->setParameter(self::UNROUTED_PARAMETER, false);

            return;
        }

        $routed = self::isRouted($container->getParameter('monolog.handlers_to_channels'));
        $container->setParameter(self::UNROUTED_PARAMETER, !$routed);
        if ($routed) {
            return;
        }

        if ($require) {
            throw new \LogicException(self::MESSAGE.' (koh_sylius_mcp.audit.require_handler is true.)');
        }

        $container->log($this, self::MESSAGE);
    }

    public static function isRouted(mixed $handlersToChannels): bool
    {
        if (!\is_array($handlersToChannels)) {
            return false;
        }

        foreach ($handlersToChannels as $channels) {
            if (\is_array($channels)
                && 'inclusive' === ($channels['type'] ?? null)
                && \is_array($channels['elements'] ?? null)
                && \in_array(self::CHANNEL, $channels['elements'], true)) {
                return true;
            }
        }

        return false;
    }
}
