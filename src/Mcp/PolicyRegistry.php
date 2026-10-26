<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Koh\SyliusMcp\Policy\ToolPolicy;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decorates the registry of the MCP server this package serves on (see
 * McpServerIds): every tool passes through here on its way in, whichever
 * source registered it — sylius/mcp-server-plugin's tools and this package's,
 * which the mcp-bundle registers from the container, a custom loader, or the
 * SDK's file-based discovery. Since mcp/sdk 0.6 all of them call
 * registerTool(). That makes it the one place where three things can be
 * decided for all of them alike:
 *
 *  - **Switched off** (`koh_sylius_mcp.tools.<name>.enabled: false`): the tool
 *    is never registered, so it is absent from tools/list, from the server
 *    card and from tools/call. Not hidden — absent.
 *  - **Annotated**: readOnly/destructive/idempotent hints from the policy
 *    replace whatever the tool did or did not declare. The plugin's tools
 *    declare none, and a client cannot tell `fetch_order` from
 *    `complete_checkout` without them.
 *  - **Name conflicts**: the SDK registry keys tools by name and lets the last
 *    registration win, so two tools called `fetch_product` silently overwrite
 *    each other in whatever order they happen to be registered. Here a tool
 *    from this package always wins, the decision is logged, and the order no
 *    longer matters.
 */
final class PolicyRegistry implements RegistryInterface
{
    private const OWN_NAMESPACE = 'Koh\\SyliusMcp\\';

    /** Argument names GuardedCallToolHandler consumes. */
    public const DRY_RUN = 'dryRun';

    public const CONFIRM = 'confirm';

    /** @var array<string, string> tool name => class of the handler that holds it */
    private array $owners = [];

    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly ToolPolicyProvider $policies,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * A tool the policy keeps out is not stored. The interface still asks for
     * a reference: the one returned is detached, so a caller that tracks
     * references by identity — the SDK's DiscoveryLoader does — never takes
     * the tool that holds the name for its own and never unregisters it.
     */
    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        $admitted = $this->admit($tool, $handler);
        if (null === $admitted) {
            return new ToolReference($tool, $handler);
        }

        return $this->inner->registerTool($admitted, $handler);
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

        [$inputSchema, $description] = self::withWriteControls($tool, $policy);

        return new Tool(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $inputSchema,
            description: $description,
            annotations: $policy->annotations($tool->annotations),
            icons: $tool->icons,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = []): ResourceTemplateReference
    {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = []): PromptReference
    {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    /** A tool that is gone no longer holds its name. */
    public function unregisterTool(string $name): void
    {
        unset($this->owners[$name]);
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
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

    /**
     * Write tools get a `dryRun` argument, and tools that need confirmation a
     * `confirm` argument, both handled by GuardedCallToolHandler and never
     * passed to the tool. They have to be in the schema: the SDK validates
     * arguments against it before any handler sees them, and an agent only
     * uses what the schema offers.
     *
     * @return array{array{type: 'object', properties: array<string, mixed>|\stdClass, required: array<string>|null}, string|null}
     */
    private static function withWriteControls(Tool $tool, ToolPolicy $policy): array
    {
        $schema = $tool->inputSchema;
        $description = $tool->description;
        if (!$policy->class->isWrite()) {
            return [$schema, $description];
        }

        // An empty property list arrives as a stdClass (the SDK keeps it so,
        // to serialise it as {} rather than []).
        $properties = $schema['properties'] instanceof \stdClass ? get_object_vars($schema['properties']) : $schema['properties'];
        if (isset($properties[self::DRY_RUN]) || isset($properties[self::CONFIRM])) {
            // The tool has an argument of that name itself; ours would shadow it.
            return [$schema, $description];
        }

        $properties[self::DRY_RUN] = [
            'type' => 'boolean',
            'default' => false,
            'description' => 'Preview only: report what the call would change, change nothing.',
        ];
        if ($policy->confirm) {
            $properties[self::CONFIRM] = [
                'type' => 'string',
                'description' => 'Confirmation token from the preview of this exact call. Without it the call is a preview.',
            ];
            $description = rtrim((string) $description).' Needs confirmation: call it first without `confirm` to get a preview and a confirm token, show the preview to the user, then call again with the same arguments plus `confirm`.';
        }
        $schema['properties'] = $properties;

        return [$schema, $description];
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
