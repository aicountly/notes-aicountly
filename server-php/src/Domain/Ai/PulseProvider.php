<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain\Ai;

/**
 * The one thing this API asks of a language model.
 *
 * The interface is this small on purpose. Every Pulse feature — the sixteen
 * selection actions, ask-this-note, ask-notebook, ask-my-notes — is the same
 * call with a different {@see PromptBundle}, so there is exactly one place
 * where content leaves this server, and exactly one thing to stub in a test.
 *
 * An implementation must not log the prompt or the answer, and must not
 * flatten the bundle: instructions and note text travel in separate roles.
 */
interface PulseProvider
{
    /**
     * @throws \Aicountly\Api\Http\ApiException 503 when the provider is not
     *         configured, 502 when it is configured but did not answer. Never
     *         an empty result dressed up as a successful one.
     */
    public function complete(PromptBundle $bundle): AiResult;
}
