<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Koh\SyliusMcp\Policy\ToolPolicyProvider;
use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * tools/list shows the tools this caller may call, and no others.
 *
 * Listing a tool the caller will be refused invites the agent to plan around
 * it. The tool set is small (two dozen), so the list is answered in one page.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final class FilteredListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ToolPolicyProvider $policies,
        private readonly ToolAccessDecider $access,
        private readonly McpCallerResolver $callers,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /** @return Response<ListToolsResult> */
    public function handle(Request $request, SessionInterface $session): Response
    {
        $caller = $this->callers->resolve();

        $tools = array_values(array_filter(
            $this->registry->getTools()->references,
            fn (mixed $tool): bool => $tool instanceof Tool
                && $this->access->decide($this->policies->get($tool->name), $caller)->allowed,
        ));

        return new Response($request->getId(), new ListToolsResult($tools));
    }
}
