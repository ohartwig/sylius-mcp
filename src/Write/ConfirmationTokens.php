<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

use Koh\SyliusMcp\Security\McpCaller;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The confirm token a preview hands out and the confirmed call hands back.
 *
 * MCP has a protocol answer for "ask the human before doing this":
 * elicitation. The PHP SDK this server runs on (mcp/sdk 0.3) does not
 * implement it, so the confirmation runs through the agent: the preview
 * returns a token, the agent shows the preview to the user, and only a second
 * call carrying the token executes.
 *
 * The token is bound to everything that made the preview true: the tool, the
 * exact arguments, the administrator and the access token. A token for
 * "update MUG's description to X" cannot confirm "… to Y", nor be used by a
 * second agent with another token. It expires after {@see self::$ttl}
 * seconds and, where a cache pool is wired, works once.
 *
 * Stateless otherwise: an HMAC over the binding with the application secret,
 * so there is no table to clean up.
 */
final class ConfirmationTokens
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        private readonly int $ttl = 300,
        private readonly ?CacheItemPoolInterface $used = null,
        private readonly ?\Closure $clock = null,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('A confirmation token needs a non-empty secret.');
        }
    }

    /** @param array<string, mixed> $arguments */
    public function issue(string $tool, array $arguments, McpCaller $caller): ConfirmationToken
    {
        $expires = $this->now() + $this->ttl;
        $nonce = bin2hex(random_bytes(8));
        $payload = $expires.'.'.$nonce;

        return new ConfirmationToken(
            $payload.'.'.$this->sign($payload, $tool, $arguments, $caller),
            (new \DateTimeImmutable('@'.$expires)),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return string|null null when valid, else why not
     */
    public function verify(string $token, string $tool, array $arguments, McpCaller $caller): ?string
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts) || !ctype_digit($parts[0])) {
            return 'The confirm token is malformed.';
        }
        [$expires, $nonce, $signature] = $parts;

        if (!hash_equals($this->sign($expires.'.'.$nonce, $tool, $arguments, $caller), $signature)) {
            return 'The confirm token does not belong to this call: tool, arguments or caller differ from the preview.';
        }
        if ((int) $expires < $this->now()) {
            return 'The confirm token has expired. Ask for a new preview.';
        }

        if (null !== $this->used) {
            $item = $this->used->getItem('koh_mcp_confirm_'.$nonce);
            if ($item->isHit()) {
                return 'The confirm token has already been used. Ask for a new preview.';
            }
            $item->set(true);
            $item->expiresAfter($this->ttl + 60);
            $this->used->save($item);
        }

        return null;
    }

    /** @param array<string, mixed> $arguments */
    private function sign(string $payload, string $tool, array $arguments, McpCaller $caller): string
    {
        $binding = json_encode([
            $payload,
            $tool,
            self::canonical($arguments),
            $caller->user,
            $caller->tokenId,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $binding, $this->secret, true)), '+/', '-_'), '=');
    }

    /**
     * Key order must not matter — a client may serialise the second call's
     * arguments in another order than the first.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    private function now(): int
    {
        return null !== $this->clock ? (int) ($this->clock)() : time();
    }
}
