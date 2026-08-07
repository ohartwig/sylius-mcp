<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusMcp\Agent\McpEndpointProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

final class McpEndpointProviderTest extends TestCase
{
    public function testGetAgentEndpointsAdvertisesTheServerCard(): void
    {
        $endpoints = iterator_to_array((new McpEndpointProvider())->getAgentEndpoints($this->context()));

        self::assertCount(1, $endpoints);

        $endpoint = $endpoints[0];
        self::assertSame('/.well-known/mcp/server-card.json', $endpoint->path);
        self::assertSame('MCP server card', $endpoint->title);
        self::assertSame('describedby', $endpoint->rel);
        self::assertSame('mcp', $endpoint->protocol);
        self::assertSame('Transport endpoint and tool surface of the MCP server.', $endpoint->description);
    }

    public function testGetApiCatalogEntriesAnchorsAtTheDefaultTransportPath(): void
    {
        $entry = iterator_to_array(
            (new McpEndpointProvider())->getApiCatalogEntries($this->context('https://shop.example.com')),
        )[0];

        self::assertSame('https://shop.example.com/_mcp', $entry->anchor);
        self::assertSame('MCP server', $entry->title);
        self::assertCount(1, $entry->serviceDesc);
        self::assertSame(
            'https://shop.example.com/.well-known/mcp/server-card.json',
            $entry->serviceDesc[0]->href,
        );
        self::assertSame('application/json', $entry->serviceDesc[0]->type);
    }

    /**
     * The transport path is configurable (the plugin registers its route
     * elsewhere), so the catalog entry's anchor has to track it -- while the
     * server-card location itself is fixed regardless.
     */
    public function testACustomTransportPathMovesTheAnchorButNotTheServerCard(): void
    {
        $provider = new McpEndpointProvider('/custom-mcp');

        $entry = iterator_to_array($provider->getApiCatalogEntries($this->context('https://shop.example.com')))[0];

        self::assertSame('https://shop.example.com/custom-mcp', $entry->anchor);
        self::assertSame(
            'https://shop.example.com/.well-known/mcp/server-card.json',
            $entry->serviceDesc[0]->href,
        );
    }

    private function context(string $baseUrl = 'https://shop.example.com'): AgentContext
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        return new AgentContext($channel, $baseUrl, 'de_DE', ['de_DE']);
    }
}
