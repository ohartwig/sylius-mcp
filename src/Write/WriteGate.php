<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMcp\Write;

use Koh\SyliusMcp\Mcp\PolicyRegistry;
use Koh\SyliusMcp\Policy\ToolPolicy;
use Koh\SyliusMcp\Security\McpCaller;
use Koh\SyliusMcp\Write\Preview\ToolPreviewInterface;

/**
 * Decides, for a write tool, whether a call runs, is previewed, or is refused.
 *
 *  1. The control arguments `dryRun` and `confirm` are taken out; the tool
 *     never sees them.
 *  2. The draft rules apply ({@see DraftPolicy}): arguments may be forced,
 *     or the call refused.
 *  3. `dryRun: true` — or a tool that needs confirmation called without
 *     `confirm` — returns a preview instead of running the tool. For a tool
 *     that needs confirmation the preview carries the confirm token.
 *  4. With `confirm`, the token must belong to exactly this tool, these
 *     arguments and this caller ({@see ConfirmationTokens}).
 *
 * Read tools pass untouched.
 */
final class WriteGate
{
    /** @param iterable<ToolPreviewInterface> $previews */
    public function __construct(
        private readonly DraftPolicy $drafts,
        private readonly ConfirmationTokens $tokens,
        private readonly iterable $previews = [],
    ) {
    }

    /** @param array<string, mixed> $arguments */
    public function before(ToolPolicy $policy, McpCaller $caller, array $arguments): WriteDecision
    {
        if (!$policy->class->isWrite()) {
            return WriteDecision::execute($arguments);
        }

        $dryRun = true === ($arguments[PolicyRegistry::DRY_RUN] ?? false);
        $confirm = $arguments[PolicyRegistry::CONFIRM] ?? null;
        unset($arguments[PolicyRegistry::DRY_RUN], $arguments[PolicyRegistry::CONFIRM]);

        $draft = $this->drafts->before($policy->name, $arguments);
        if (null !== $draft->refusal) {
            return WriteDecision::refuse('draft_refused', 'Refused: '.$draft->refusal);
        }

        if ($dryRun || ($policy->confirm && !\is_string($confirm))) {
            return WriteDecision::preview($this->preview($policy, $caller, $arguments, $draft));
        }

        if ($policy->confirm) {
            $problem = $this->tokens->verify($confirm, $policy->name, $arguments, $caller);
            if (null !== $problem) {
                return WriteDecision::refuse('confirmation_invalid', $problem);
            }
        }

        return WriteDecision::execute($draft->arguments, $draft->notes, $policy->confirm);
    }

    /**
     * @param array<string, mixed>     $arguments
     * @param array<mixed, mixed>|null $structured
     */
    public function after(ToolPolicy $policy, array $arguments, ?array $structured): ?string
    {
        return $policy->class->isWrite() ? $this->drafts->after($policy->name, $arguments, $structured) : null;
    }

    /**
     * @param array<string, mixed> $arguments the call as the agent sent it, controls removed
     *
     * @return array<string, mixed>
     */
    private function preview(ToolPolicy $policy, McpCaller $caller, array $arguments, DraftOutcome $draft): array
    {
        $preview = null;
        foreach ($this->previews as $provider) {
            if ($provider->supports($policy->name)) {
                $preview = $provider->preview($policy->name, $draft->arguments);
                break;
            }
        }

        $payload = [
            'dry_run' => true,
            'tool' => $policy->name,
            'class' => $policy->class->value,
            'executed' => false,
            'mode' => $this->drafts->governs($policy->name) ? 'draft' : 'live',
            'arguments' => $draft->arguments,
            'preview' => $preview ?? ['summary' => 'No preview is available for this tool; it would be called with the arguments above.'],
        ];
        if ([] !== $draft->notes) {
            $payload['draft_notes'] = $draft->notes;
        }

        if ($policy->confirm) {
            $token = $this->tokens->issue($policy->name, $arguments, $caller);
            $payload['confirm'] = $token->value;
            $payload['confirm_expires_at'] = $token->expiresAt->format(\DATE_ATOM);
            $payload['next_step'] = \sprintf(
                'Show this preview to the user. Only if they agree, call %s again with exactly the same arguments plus "confirm": "%s".',
                $policy->name,
                $token->value,
            );
        }

        return $payload;
    }
}
