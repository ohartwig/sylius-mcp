<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decorates `mcp.registry`: every tool passes through here on its way in,
 * whichever loader registered it — sylius/mcp-server-plugin, the mcp-bundle's
 * discovery of the application's own tools, or this package.
 *
 * (registerTool() for manual registrations, setDiscoveryState() for what the
 * mcp-bundle's discovery finds). That makes it the one place where three
 * things can be decided for all of them alike:
 *
 *  - **Switched off** (`koh_sylius_mcp.tools.<name>.enabled: false`): the tool
 *    is never registered, so it is absent from tools/list, from the server
 *    card and from tools/call. Not hidden — absent.
 *  - **Annotated**: readOnly/destructive/idempotent hints from the policy
 *    replace whatever the tool did or did not declare. The plugin's tools
 *    declare none, and a client cannot tell `fetch_order` from
 *    `complete_checkout` without them.
 *  - **Name conflicts**: the SDK registry keys tools by name, so two tools
 *    called `fetch_product` silently overwrite each other in whatever order
 *    the loaders happen to run. Here a tool from this package always wins, the
 *    decision is logged, and the order no longer matters.
 */
final class PolicyRegistry implements RegistryInterface
{
    private const OWN_NAMESPACE = 'Koh\\SyliusMcp\\';

    /** @var array<string, string> tool name => class of the handler that holds it */
    private array $owners = [];

    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly ToolPolicyProvider $policies,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $admitted = $this->admit($tool, $handler);
        if (null === $admitted) {
            return;
        }

        // A tool of ours must not lose against a manual registration the
        // inner registry would otherwise prefer.
        $this->inner->registerTool($admitted, $handler, $isManual || self::isOwn(self::ownerOf($handler)));
    }

    /**
     * The SDK's DiscoveryLoader — the one that registers the application's
     * own tools from mcp.discovery.scan_dirs — does not call registerTool().
     * It hands the registry a whole discovery state, which the SDK registry
     * writes over its tool list, over manual registrations of the same name
     * included. Without this method every discovered tool would bypass the
     * policy: no switch, no annotations, no conflict rule.
     *
     * Each discovered tool is admitted the same way registerTool() admits
     * one. A name that is already held — the builder runs the manual loaders
     * first — stays with its holder.
     */
    public function setDiscoveryState(DiscoveryState $state): void
    {
        $tools = [];
        foreach ($state->getTools() as $reference) {
            $name = $reference->tool->name;
            if (isset($this->owners[$name]) && $this->owners[$name] !== self::ownerOf($reference->handler)) {
                $this->logger->notice('Discovered MCP tool "{tool}" from {owner} ignored: {holder} provides it.', ['tool' => $name, 'owner' => self::ownerOf($reference->handler), 'holder' => $this->owners[$name]]);

                continue;
            }

            $admitted = $this->admit($reference->tool, $reference->handler);
            if (null !== $admitted) {
                $tools[$name] = new ToolReference($admitted, $reference->handler, $reference->isManual);
            }
        }

        $this->inner->setDiscoveryState(new DiscoveryState(
            $tools,
            $state->getResources(),
            $state->getPrompts(),
            $state->getResourceTemplates(),
        ));
    }

    /**
     * The policy decision for one tool on its way into the registry: null if
     * it stays out, otherwise the tool as it will be served.
     *
     * @param callable|array<mixed>|string $handler
     */
    private function admit(Tool $tool, callable|array|string $handler): ?Tool
    {
        $policy = $this->policies->get($tool->name);
        if (!$policy->enabled) {
            $this->logger->info('MCP tool "{tool}" is switched off by configuration and not registered.', ['tool' => $tool->name]);

            return null;
        }

        $owner = self::ownerOf($handler);
        $holder = $this->owners[$tool->name] ?? null;

        if (null !== $holder && $holder !== $owner) {
            if (self::isOwn($holder)) {
                $this->logger->notice('MCP tool "{tool}" from {owner} ignored: {holder} provides it.', ['tool' => $tool->name, 'owner' => $owner, 'holder' => $holder]);

                return null;
            }

            $this->logger->notice('MCP tool "{tool}" registered twice: {owner} replaces {holder}.', ['tool' => $tool->name, 'owner' => $owner, 'holder' => $holder]);
        }

        if ($policy->unknown) {
            $this->logger->warning('MCP tool "{tool}" has no policy; it is treated as {class}. Classify it under koh_sylius_mcp.tools.', ['tool' => $tool->name, 'class' => $policy->class->value]);
        }

        $this->owners[$tool->name] = $owner;

        return new Tool(
            name: $tool->name,
            inputSchema: $tool->inputSchema,
            description: $tool->description,
            annotations: $policy->annotations($tool->annotations),
            icons: $tool->icons,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $this->inner->registerResource($resource, $handler, $isManual);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = [], bool $isManual = false): void
    {
        $this->inner->registerResourceTemplate($template, $handler, $completionProviders, $isManual);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = [], bool $isManual = false): void
    {
        $this->inner->registerPrompt($prompt, $handler, $completionProviders, $isManual);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }

    public function getDiscoveryState(): DiscoveryState
    {
        return $this->inner->getDiscoveryState();
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getTools($limit, $cursor);
    }

    public function getTool(string $name): ToolReference
    {
        return $this->inner->getTool($name);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }

    /** @param callable|array<mixed>|string $handler */
    private static function ownerOf(callable|array|string $handler): string
    {
        if (\is_string($handler)) {
            return $handler;
        }
        if (\is_array($handler) && isset($handler[0])) {
            return \is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];
        }

        return $handler instanceof \Closure ? 'closure' : get_debug_type($handler);
    }

    private static function isOwn(string $owner): bool
    {
        return str_starts_with($owner, self::OWN_NAMESPACE);
    }
}
