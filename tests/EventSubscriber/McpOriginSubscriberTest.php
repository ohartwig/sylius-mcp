<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\EventSubscriber;

use Koh\SyliusMcp\EventSubscriber\McpOriginSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class McpOriginSubscriberTest extends TestCase
{
    /** Server-side clients send no Origin; they are not what DNS rebinding abuses. */
    public function testARequestWithoutOriginPasses(): void
    {
        self::assertNull($this->request('/_mcp', null)->getResponse());
    }

    public function testAForeignOriginIsRefusedBeforeTheFirewall(): void
    {
        $response = $this->request('/_mcp', 'https://evil.example')->getResponse();

        self::assertSame(403, $response?->getStatusCode());
    }

    public function testTheShopItselfAndListedOriginsPass(): void
    {
        self::assertNull($this->request('/_mcp', 'https://shop.example.com')->getResponse());
        self::assertNull($this->request('/_mcp', 'https://claude.ai')->getResponse());
    }

    public function testOtherPathsAreNotTouched(): void
    {
        self::assertNull($this->request('/products', 'https://evil.example')->getResponse());
    }

    /** The SDK's wildcard never leaves the server. */
    public function testTheWildcardIsReplacedOrRemoved(): void
    {
        self::assertSame('https://claude.ai', $this->respond('https://claude.ai')->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Origin', (string) $this->respond('https://claude.ai')->headers->get('Vary'));
        self::assertFalse($this->respond(null)->headers->has('Access-Control-Allow-Origin'));
        self::assertFalse($this->respond('https://evil.example')->headers->has('Access-Control-Allow-Origin'));
    }

    private function subscriber(): McpOriginSubscriber
    {
        return new McpOriginSubscriber('/_mcp', ['https://claude.ai']);
    }

    private function request(string $path, ?string $origin): RequestEvent
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $this->make($path, $origin), HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber()->onRequest($event);

        return $event;
    }

    private function respond(?string $origin): Response
    {
        $response = new Response('', 200, ['Access-Control-Allow-Origin' => '*']);
        $this->subscriber()->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $this->make('/_mcp', $origin), HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    private function make(string $path, ?string $origin): Request
    {
        $request = Request::create('https://shop.example.com'.$path, 'POST');
        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }
}
