<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Koh\SyliusMcp\Audit\ToolCallAuditor;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * resources/read in front of the SDK's handler: the caller needs a token with
 * read scope, like for a read tool, and the read is audited like a tool call
 * (as `resource:<uri>`).
 *
 * @implements RequestHandlerInterface<ReadResourceResult>
 */
final class GuardedReadResourceHandler implements RequestHandlerInterface
{
    /** @param RequestHandlerInterface<ReadResourceResult> $inner the SDK's ReadResourceHandler */
    public function __construct(
        private readonly RequestHandlerInterface $inner,
        private readonly McpCallerResolver $callers,
        private readonly ToolCallAuditor $auditor = new ToolCallAuditor(),
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ReadResourceRequest;
    }

    /** @return Response<ReadResourceResult>|Error */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ReadResourceRequest);
        $startedAt = microtime(true);
        $caller = $this->callers->resolve();

        if (!$caller->isAuthenticated() || !$caller->mayRead()) {
            $this->auditor->record('resource:'.$request->uri, null, $caller, [], 'denied', $startedAt, ['reason' => 'no read scope']);

            return new Error($request->getId(), Error::INVALID_REQUEST, 'Access denied: reading resources needs a token with the mcp:read scope.');
        }

        $response = $this->inner->handle($request, $session);
        $this->auditor->record('resource:'.$request->uri, null, $caller, [], $response instanceof Error ? 'error' : 'ok', $startedAt);

        return $response;
    }
}
