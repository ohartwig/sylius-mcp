<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusMcp\Controller\McpServerCardController;
use Koh\SyliusMcp\Mcp\McpCatalogue;
use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Mcp\Capability\Registry;
use Mcp\Schema\Prompt;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

final class McpServerCardControllerTest extends TestCase
{
    public function testCardNamesTheServerAndItsTransport(): void
    {
        $card = $this->card(['search_products']);

        self::assertSame('Tabak Budde', $card['serverInfo']['name']);
        self::assertSame('1.0.0', $card['serverInfo']['version']);
        self::assertSame('https://shop.example.com/_mcp', $card['transport']['endpoint']);
        self::assertSame('streamable-http', $card['transport']['type']);
    }

    /**
     * The card lists what the MCP registry serves — the same registry tools/list
     * answers from — so the two cannot disagree.
     */
    public function testToolsComeFromTheMcpRegistry(): void
    {
        $card = $this->card(['search_products', 'fetch_product']);

        self::assertSame(['fetch_product', 'search_products'], array_column($card['tools'], 'name'));
    }

    public function testASwitchedOffToolIsNotOnTheCard(): void
    {
        $card = $this->card(['search_products', 'complete_checkout'], ['complete_checkout' => ['enabled' => false]]);

        self::assertSame(['search_products'], array_column($card['tools'], 'name'));
    }

    public function testToolsCarryTheirAnnotations(): void
    {
        $card = $this->card(['update_product']);

        self::assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            $card['tools'][0]['annotations'],
        );
    }

    /**
     * Announcing a tools capability with nothing behind it misleads an agent
     * into connecting and finding an empty list.
     */
    public function testToolsCapabilityIsAbsentWithoutTools(): void
    {
        $card = $this->card([]);

        self::assertNull($card['capabilities']['tools']);
        self::assertSame([], $card['tools']);
    }

    public function testToolsCapabilityAppearsOnceThereAreTools(): void
    {
        self::assertNotNull($this->card(['search_products'])['capabilities']['tools']);
    }

    public function testPromptsAndResourcesAreAnnouncedOnlyWhenRegistered(): void
    {
        $card = $this->card(['search_products']);
        self::assertNull($card['capabilities']['prompts']);
        self::assertNull($card['capabilities']['resources']);

        $card = $this->card(['search_products'], [], withPrompt: true);
        self::assertSame(['listChanged' => false], $card['capabilities']['prompts']);
    }

    /** So an agent can authenticate before being refused, rather than after. */
    public function testCardPointsAtTheProtectedResourceMetadata(): void
    {
        self::assertSame(
            'https://shop.example.com/.well-known/oauth-protected-resource',
            $this->card(['search_products'])['authorization']['protectedResourceMetadata'],
        );
    }

    /**
     * @param list<string>                        $tools
     * @param array<string, array<string, mixed>> $config
     *
     * @return array<string, mixed>
     */
    private function card(array $tools, array $config = [], bool $withPrompt = false): array
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        $factory = $this->createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        /** @var array<string, array{enabled?: bool, class?: string|null, idempotent?: bool|null, destructive?: bool|null, untrusted?: bool|null}> $config */
        $registry = new PolicyRegistry(new Registry(), new ToolPolicyProvider($config));
        foreach ($tools as $name) {
            $registry->registerTool(new Tool($name, null, ['type' => 'object', 'properties' => []], 'Tool '.$name, null), 'Vendor\\'.$name);
        }
        if ($withPrompt) {
            $registry->registerPrompt(new Prompt('catalogue_import'), 'Vendor\\Prompt', [], true);
        }

        $controller = new McpServerCardController(new McpCatalogue($registry), $factory, new AgentJsonResponder());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $controller(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
