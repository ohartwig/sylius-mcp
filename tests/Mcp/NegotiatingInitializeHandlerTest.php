<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Mcp;

use Koh\SyliusMcp\Mcp\NegotiatingInitializeHandler;
use Mcp\Capability\Registry;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

final class NegotiatingInitializeHandlerTest extends TestCase
{
    /** The specification: a supported requested version is answered with itself. */
    public function testASupportedVersionIsEchoed(): void
    {
        self::assertSame('2025-06-18', $this->initialize('2025-06-18')['protocolVersion']);
        self::assertSame('2025-11-25', $this->initialize('2025-11-25')['protocolVersion']);
        self::assertSame('2024-11-05', $this->initialize('2024-11-05')['protocolVersion']);
    }

    public function testAnUnknownVersionGetsTheLatest(): void
    {
        self::assertSame('2025-11-25', $this->initialize('2030-01-01')['protocolVersion']);
    }

    /**
     * What the SDK's own handler records and the SDK reads later: the
     * negotiated revision (RequestContext) and the client's capabilities
     * (ClientGateway).
     */
    public function testTheSessionKeepsTheNegotiatedVersionAndTheClient(): void
    {
        $session = new Session(new InMemorySessionStore());
        $this->initialize('2025-06-18', session: $session);

        self::assertSame('2025-06-18', $session->get('protocol_version'));
        self::assertSame(['name' => 'client', 'version' => '1'], $session->get('client_info'));
        self::assertNotNull($session->get('client_capabilities'));
    }

    public function testInstructionsAndCapabilitiesAreSent(): void
    {
        $result = $this->initialize('2025-11-25', 'Use dryRun.');

        self::assertSame('Use dryRun.', $result['instructions']);
        self::assertArrayHasKey('tools', $result['capabilities']);
        self::assertArrayNotHasKey('prompts', $result['capabilities']);
        self::assertSame('shop', $result['serverInfo']['name']);
    }

    /** @return array<string, mixed> */
    private function initialize(string $version, ?string $instructions = null, ?Session $session = null): array
    {
        $registry = new Registry();
        $registry->registerTool(new Tool('t', null, ['type' => 'object', 'properties' => []], null, null), 'X');
        $handler = NegotiatingInitializeHandler::create($registry, 'shop', '1.0', instructions: $instructions);

        $request = InitializeRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $version,
            'capabilities' => [],
            'clientInfo' => ['name' => 'client', 'version' => '1'],
        ]]);
        self::assertTrue($handler->supports($request));
        self::assertSame('2025-11-25', NegotiatingInitializeHandler::negotiate('garbage')->value);
        $response = $handler->handle($request, $session ?? new Session(new InMemorySessionStore()));
        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(InitializeResult::class, $response->result);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) json_encode($response->result), true);

        return $json;
    }
}
