<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Tests\Support;

use Koh\SyliusMcp\Security\McpCallerResolver;
use Koh\SyliusMcp\Security\McpTokenAttributes;
use Koh\SyliusMcp\Security\ToolAccessDecider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * A caller behind a bearer token, the way the firewall leaves it in the token
 * storage: user, roles, and the token attributes koh/sylius-mcp-oauth sets.
 */
trait McpSecurityFixtures
{
    /**
     * @param list<string>      $roles
     * @param list<string>|null $scopes null: the authenticator set no scope attribute
     */
    private static function callers(?string $user = 'admin@example.com', array $roles = ['ROLE_ADMINISTRATION_ACCESS'], ?array $scopes = ['mcp'], string $ip = '203.0.113.7'): McpCallerResolver
    {
        [$storage, $requests] = self::securityState($user, $roles, $scopes, $ip);

        return new McpCallerResolver($storage, $requests, consoleIsOperator: false);
    }

    /**
     * @param list<string>      $roles
     * @param list<string>|null $scopes
     *
     * @return array{TokenStorage, RequestStack}
     */
    private static function securityState(?string $user = 'admin@example.com', array $roles = ['ROLE_ADMINISTRATION_ACCESS'], ?array $scopes = ['mcp'], string $ip = '203.0.113.7'): array
    {
        $storage = new TokenStorage();
        if (null !== $user) {
            $token = new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'mcp', $roles);
            if (null !== $scopes) {
                $token->setAttribute(McpTokenAttributes::SCOPES, $scopes);
            }
            $token->setAttribute(McpTokenAttributes::TOKEN_ID, 42);
            $token->setAttribute(McpTokenAttributes::CLIENT_ID, 'mcpc_test');
            $storage->setToken($token);
        }

        $requests = new RequestStack();
        $requests->push(Request::create('https://shop.example.com/_mcp', 'POST', server: ['REMOTE_ADDR' => $ip]));

        return [$storage, $requests];
    }

    /** @param list<string> $granted */
    private static function checker(array $granted): AuthorizationCheckerInterface
    {
        return new class($granted) implements AuthorizationCheckerInterface {
            /** @param list<string> $granted */
            public function __construct(private readonly array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return \in_array($attribute, $this->granted, true);
            }
        };
    }

    /**
     * @param list<string>                               $granted
     * @param array<string, list<string>>                $classRoles
     * @param array<string, array{roles?: list<string>}> $toolRoles
     */
    private static function decider(array $granted = ['ROLE_ADMINISTRATION_ACCESS'], array $classRoles = [], array $toolRoles = []): ToolAccessDecider
    {
        return new ToolAccessDecider(self::checker($granted), $classRoles, $toolRoles);
    }
}
