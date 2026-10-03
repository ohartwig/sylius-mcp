<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Security;

use Koh\SyliusMcp\Policy\ToolClass;
use Koh\SyliusMcp\Policy\ToolPolicy;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * May this caller run this tool?
 *
 * Three questions, all of which must be answered yes:
 *
 *  1. Is there a caller at all? (The firewall should already have said no;
 *     this is the second line for a host that forgot its access_control rule.)
 *  2. Does the token's scope cover the tool's class? read needs mcp:read,
 *     write and high_risk need mcp:write; the legacy `mcp` scope covers both.
 *  3. Does the administrator hold one of the roles the tool's class — or the
 *     tool itself — requires? Checked through the authorization checker, so
 *     the role hierarchy applies.
 *
 * Before this, a token identified an administrator and then every tool ran
 * the same way no matter whose token it was. Now the identity decides.
 */
final class ToolAccessDecider
{
    public const DEFAULT_ROLE = 'ROLE_ADMINISTRATION_ACCESS';

    /**
     * @param array<string, list<string>>                $classRoles roles per class value; any one suffices
     * @param array<string, array{roles?: list<string>}> $toolRoles  per-tool override of the class roles
     */
    public function __construct(
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
        private readonly array $classRoles = [],
        private readonly array $toolRoles = [],
    ) {
    }

    public function decide(ToolPolicy $policy, McpCaller $caller): AccessDecision
    {
        if (!$caller->isAuthenticated()) {
            return AccessDecision::deny('No authenticated MCP caller.');
        }

        if ($policy->class->isWrite() ? !$caller->mayWrite() : !$caller->mayRead()) {
            return AccessDecision::deny(\sprintf(
                'The access token lacks the scope for this tool (%s needs %s).',
                $policy->class->value,
                $policy->class->isWrite() ? McpCaller::SCOPE_WRITE : McpCaller::SCOPE_READ,
            ));
        }

        // The console operator holds no roles to check; see McpCallerResolver.
        if ($caller->console) {
            return AccessDecision::allow();
        }

        $roles = $this->rolesFor($policy->name, $policy->class);

        if (null === $this->authorizationChecker) {
            return AccessDecision::deny('No authorization checker to evaluate the required roles.');
        }

        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return AccessDecision::allow();
            }
        }

        return AccessDecision::deny(\sprintf('This tool requires one of the roles %s.', implode(', ', $roles)));
    }

    /**
     * Never empty. An empty list in the configuration reads as "not set" —
     * the configuration tree fills in [] for an omitted roles key, and
     * "no role required" is not something to get by leaving a key out.
     *
     * @return list<string>
     */
    public function rolesFor(string $tool, ToolClass $class): array
    {
        foreach ([$this->toolRoles[$tool]['roles'] ?? [], $this->classRoles[$class->value] ?? []] as $roles) {
            if ([] !== $roles) {
                return $roles;
            }
        }

        return [self::DEFAULT_ROLE];
    }
}
