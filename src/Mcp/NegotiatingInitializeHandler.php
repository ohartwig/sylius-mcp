<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * initialize with version negotiation.
 *
 * The specification says: if the server supports the version the client
 * requests, it must answer with that version; if not, with the latest it
 * supports. mcp/sdk 0.3 answered every client with one fixed version; this
 * handler was written to do it right, with 2025-11-25 as the latest. Since
 * mcp/sdk 0.8 the SDK's own handler negotiates as well, over the same
 * handshake-era revisions; this one stays so that what initialize announces
 * does not change (see below). 2026-07-28 and later have no initialize at
 * all — the SDK serves them on the same endpoint without a handshake.
 *
 * What 2025-11-25 adds over 2025-06-18 is optional and capability-gated
 * (icons, tasks, sampling with tools, URL-mode elicitation). Answering with it
 * promises the wire format, not features: the server announces none of the
 * new capabilities.
 *
 * Everything else is what the SDK's handler returns: the configured server
 * info and instructions, and capabilities derived from what the registry
 * holds. Like the SDK's handler it records the client's info, capabilities
 * and the negotiated version in the session; the SDK reads the latter two
 * later (RequestContext::getProtocolVersion(), ClientGateway).
 *
 * @implements RequestHandlerInterface<InitializeResult>
 */
final class NegotiatingInitializeHandler implements RequestHandlerInterface
{
    /** Newest first. */
    public const SUPPORTED = [
        ProtocolVersion::V2025_11_25,
        ProtocolVersion::V2025_06_18,
        ProtocolVersion::V2025_03_26,
        ProtocolVersion::V2024_11_05,
    ];

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly Implementation $serverInfo,
        private readonly ?string $instructions = null,
        private readonly bool $listChanged = true,
    ) {
    }

    public static function create(RegistryInterface $registry, string $name, string $version, ?string $description = null, ?string $websiteUrl = null, ?string $instructions = null): self
    {
        return new self($registry, new Implementation(trim($name), trim($version), $description, null, $websiteUrl), $instructions);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof InitializeRequest;
    }

    /** @return Response<InitializeResult> */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof InitializeRequest);
        $negotiated = self::negotiate($request->protocolVersion);
        $session->set('client_info', $request->clientInfo->jsonSerialize());
        $session->set('client_capabilities', $request->capabilities->jsonSerialize());
        $session->set('protocol_version', $negotiated->value);

        $tools = $this->registry->hasTools();
        $resources = $this->registry->hasResources() || $this->registry->hasResourceTemplates();
        $prompts = $this->registry->hasPrompts();

        // A listChanged flag on its own makes the SDK announce the capability;
        // announce only what is there.
        return new Response($request->getId(), new InitializeResult(
            new ServerCapabilities(
                tools: $tools,
                toolsListChanged: $tools && $this->listChanged,
                resources: $resources,
                resourcesSubscribe: false,
                resourcesListChanged: $resources && $this->listChanged,
                prompts: $prompts,
                promptsListChanged: $prompts && $this->listChanged,
                logging: true,
                completions: true,
            ),
            $this->serverInfo,
            $this->instructions,
            null,
            $negotiated,
        ));
    }

    public static function negotiate(string $requested): ProtocolVersion
    {
        foreach (self::SUPPORTED as $version) {
            if ($version->value === $requested) {
                return $version;
            }
        }

        return self::SUPPORTED[0];
    }
}
