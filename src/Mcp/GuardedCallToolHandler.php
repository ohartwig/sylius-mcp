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
use Koh\SyliusMcp\Write\WriteDecision;
use Koh\SyliusMcp\Write\WriteGate;
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
 * Write tools pass the {@see WriteGate} in between: draft rules, dry run,
 * confirmation.
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
        private readonly ?WriteGate $writes = null,
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
            $gate = null !== $this->writes
                ? $this->writes->before($policy, $caller, $request->arguments)
                : WriteDecision::execute($request->arguments);
        } catch (\Throwable $e) {
            // A preview or draft check reads the database. Its failure is
            // reported like a failing tool: logged in full, answered without
            // the exception text, which can carry SQL and connection details.
            $this->logger->error('MCP write gate failed for {tool}', ['tool' => $request->name, 'exception' => $e]);
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'error', $startedAt, ['error' => $e::class]);

            return Error::forInternalError('Error while executing tool', $request->getId());
        }

        if (null !== $gate->refusal) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, (string) $gate->outcome, $startedAt, ['reason' => $gate->refusal]);

            return new Response($request->getId(), CallToolResult::error([new TextContent($gate->refusal)]));
        }

        if (null !== $gate->preview) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'preview', $startedAt);
            $result = new CallToolResult([new TextContent($gate->preview)], structuredContent: $gate->preview);

            // The preview quotes shop data — current descriptions, names.
            return new Response($request->getId(), $policy->untrusted ? $this->untrusted->mark($result) : $result);
        }

        $call = (new CallToolRequest($request->name, $gate->arguments))->withId($request->getId());

        try {
            $response = $this->inner->handle($call, $session);
        } catch (\Throwable $e) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'error', $startedAt, ['error' => $e::class]);

            throw $e;
        }

        if ($response instanceof Error) {
            $this->auditor->record($request->name, $policy, $caller, $request->arguments, 'error', $startedAt, ['error' => $response->message]);

            return $response;
        }

        $result = $response->result;
        $this->auditor->record($request->name, $policy, $caller, $request->arguments, $result->isError ? 'tool_error' : 'ok', $startedAt, $gate->confirmed ? ['confirmed' => true] : []);

        if ($result->isError) {
            return $response;
        }

        $notes = $gate->notes;
        $after = $this->writes?->after($policy, $gate->arguments, $result->structuredContent);
        if (null !== $after) {
            $notes[] = $after;
        }

        if ($policy->untrusted) {
            $result = $this->untrusted->mark($result, null !== $reference->tool->outputSchema);
        }

        // Our notes go after the fence: they are the server's words, not shop data.
        if ([] !== $notes) {
            $result = new CallToolResult(
                [...$result->content, new TextContent(implode("\n", $notes))],
                $result->isError,
                $result->structuredContent,
                [...($result->meta ?? []), 'koh/notes' => $notes],
            );
        }

        return new Response($response->getId(), $result);
    }
}
