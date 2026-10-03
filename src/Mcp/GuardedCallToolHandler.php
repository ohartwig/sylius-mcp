<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\Output\UntrustedContentMarker;
use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles tools/call in front of the SDK's own handler.
 *
 * Custom request handlers run before the built-in ones (the server builder
 * merges them first, and the first handler that supports a request takes it),
 * so every tool call — whoever registered the tool — passes through here
 * before the SDK resolves and runs it. That is the point where the caller's
 * rights are checked.
 *
 * A refusal is a tool result with isError, not a JSON-RPC error: the agent
 * should read why, not see a transport failure.
 *
 * On the way out, the result of a tool whose policy says `untrusted` is
 * marked as data ({@see UntrustedContentMarker}), and every call — allowed,
 * refused or failed — leaves one audit record ({@see ToolCallAuditor}).
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final class GuardedCallToolHandler implements RequestHandlerInterface
{
    /** @param RequestHandlerInterface<CallToolResult> $inner the SDK's CallToolHandler */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly RequestHandlerInterface $inner,
        private readonly ToolPolicyProvider $policies,
        private readonly ToolAccessDecider $access,
        private readonly McpCallerResolver $callers,
        private readonly UntrustedContentMarker $untrusted = new UntrustedContentMarker(),
        private readonly ToolCallAuditor $auditor = new ToolCallAuditor(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /** @return Response<CallToolResult>|Error */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);
        $startedAt = microtime(true);
        $caller = $this->callers->resolve();

        try {
            $reference = $this->registry->getTool($request->name);
        } catch (ToolNotFoundException) {
            // The SDK's answer for an unknown tool is the right one.
            $this->auditor->record($request->name, null, $caller, $request->arguments, 'unknown_tool', $startedAt);

            return $this->inner->handle($request, $session);
        }

        $policy = $this->policies->get($request->name);
        $decision = $this->access->decide($policy, $caller);

        if (!$decision->allowed) {
            $this->logger->warning('MCP tool call refused: {tool} for {user}: {reason}', [
                'tool' => $request->name,
                'user' => $caller->user ?? 'anonymous',
                'reason' => $decision->reason,
            ]);
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'denied', $startedAt, ['reason' => $decision->reason]);

            return new Response($request->getId(), CallToolResult::error([
                new TextContent('Access denied: '.$decision->reason),
            ]));
        }

        try {
            $response = $this->inner->handle($request, $session);
        } catch (\Throwable $e) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'error', $startedAt, ['error' => $e::class]);

            throw $e;
        }

        if ($response instanceof Error) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'error', $startedAt, ['error' => $response->message]);

            return $response;
        }

        $this->auditor->record($request->name, $policy, $caller, $request->arguments, $response->result->isError ? 'tool_error' : 'ok', $startedAt);

        if ($policy->untrusted && !$response->result->isError) {
            return new Response($response->getId(), $this->untrusted->mark($response->result, null !== $reference->tool->outputSchema));
        }

        return $response;
    }
}
