<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Support;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;

/**
 * Drives request handlers the way the protocol does: a JSON-RPC request with
 * an id, a session, and a response or error back.
 */
trait McpHarness
{
    /**
     * @param RequestHandlerInterface<mixed> $handler
     * @param array<string, mixed>           $arguments
     */
    private static function callTool(RequestHandlerInterface $handler, string $name, array $arguments = []): CallToolResult
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ]);

        self::assertTrue($handler->supports($request));
        $response = $handler->handle($request, new Session(new InMemorySessionStore()));
        if ($response instanceof Error) {
            self::fail('JSON-RPC error: '.$response->message);
        }
        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);

        return $response->result;
    }

    /**
     * @param RequestHandlerInterface<mixed> $handler
     *
     * @return list<string>
     */
    private static function listToolNames(RequestHandlerInterface $handler): array
    {
        $request = ListToolsRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []]);
        $response = $handler->handle($request, new Session(new InMemorySessionStore()));
        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(ListToolsResult::class, $response->result);

        return array_map(static fn ($tool): string => $tool->name, $response->result->tools);
    }

    private static function text(CallToolResult $result): string
    {
        $text = '';
        foreach ($result->content as $content) {
            $text .= $content->jsonSerialize()['text'] ?? '';
        }

        return $text;
    }
}
