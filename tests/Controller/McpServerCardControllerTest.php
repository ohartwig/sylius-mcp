<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Koh\SyliusMcp\Controller\McpServerCardController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

final class McpServerCardControllerTest extends TestCase
{
    public function testCardNamesTheServerAndItsTransport(): void
    {
        $card = $this->card();

        self::assertSame('Tabak Budde', $card['serverInfo']['name']);
        self::assertSame('1.0.0', $card['serverInfo']['version']);
        self::assertSame('https://shop.example.com/_mcp', $card['transport']['endpoint']);
        self::assertSame('streamable-http', $card['transport']['type']);
    }

    public function testToolsComeFromTheSkillRegistry(): void
    {
        $card = $this->card([
            $this->provider(new AgentSkill('product-search', 'Product Search', 'Search the catalogue.')),
            $this->provider(new AgentSkill('fetch-product', 'Fetch Product', 'Fetch one product.')),
        ]);

        self::assertSame(['fetch-product', 'product-search'], array_column($card['tools'], 'name'));
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
        self::assertNotNull($this->card()['capabilities']['tools']);
    }

    /** So an agent can authenticate before being refused, rather than after. */
    public function testCardPointsAtTheProtectedResourceMetadata(): void
    {
        self::assertSame(
            'https://shop.example.com/.well-known/oauth-protected-resource',
            $this->card()['authorization']['protectedResourceMetadata'],
        );
    }

    /**
     * @param list<AgentSkillProviderInterface>|null $providers
     *
     * @return array<string, mixed>
     */
    private function card(?array $providers = null): array
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        $controller = new McpServerCardController(
            new AgentSkillRegistry($providers ?? [$this->provider(
                new AgentSkill('product-search', 'Product Search', 'Search the catalogue.'),
            )]),
            $factory,
            new AgentJsonResponder(),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $controller(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function provider(AgentSkill $skill): AgentSkillProviderInterface
    {
        return new class($skill) implements AgentSkillProviderInterface {
            public function __construct(private AgentSkill $skill)
            {
            }

            public function getAgentSkills(AgentContext $context): iterable
            {
                yield $this->skill;
            }
        };
    }
}
