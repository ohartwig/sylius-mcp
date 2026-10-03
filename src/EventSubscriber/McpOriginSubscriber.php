<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Origin check and CORS for the MCP endpoint.
 *
 * The Streamable HTTP transport says servers MUST validate the Origin header
 * of every request, against DNS rebinding: a page that resolves its own name
 * to the shop's address can otherwise talk to the endpoint from a victim's
 * browser. And the SDK's transport answers every response with
 * `Access-Control-Allow-Origin: *`.
 *
 * Here, a request to the transport path that carries an Origin is let through
 * only if the origin is the shop itself or on `koh_sylius_mcp.allowed_origins`;
 * anything else gets a 403 before the firewall runs. A request without Origin
 * — every server-side client, Claude's connectors included — is not a browser
 * request and passes. On the way out the wildcard is replaced by the allowed
 * origin, or removed.
 */
final class McpOriginSubscriber implements EventSubscriberInterface
{
    /** @param list<string> $allowedOrigins exact origins, e.g. https://claude.ai */
    public function __construct(
        private readonly string $path = '/_mcp',
        private readonly array $allowedOrigins = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall (8) and the router (32): a refused origin
            // costs no token lookup.
            KernelEvents::REQUEST => ['onRequest', 64],
            KernelEvents::RESPONSE => ['onResponse', -64],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->applies($request)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && !$this->isAllowed($origin, $request)) {
            $event->setResponse(new JsonResponse(
                ['error' => 'forbidden_origin', 'error_description' => 'This origin may not use the MCP endpoint.'],
                Response::HTTP_FORBIDDEN,
            ));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->applies($request)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $origin = $request->headers->get('Origin');

        if (null !== $origin && $this->isAllowed($origin, $request)) {
            $headers->set('Access-Control-Allow-Origin', $origin);
            $headers->set('Vary', trim(($headers->get('Vary') ?? '').', Origin', ', '));
        } else {
            $headers->remove('Access-Control-Allow-Origin');
        }
    }

    private function applies(Request $request): bool
    {
        return rtrim($request->getPathInfo(), '/') === rtrim($this->path, '/');
    }

    private function isAllowed(string $origin, Request $request): bool
    {
        return $origin === $request->getSchemeAndHttpHost() || \in_array($origin, $this->allowedOrigins, true);
    }
}
