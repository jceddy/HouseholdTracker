<?php

declare(strict_types=1);

namespace HouseholdTracker\Chat;

use InvalidArgumentException;

/**
 * OpenAI-style function-calling tools available to the chat agent -- the extension point for
 * letting the model call into whatever household-tracking domain logic this app ends up with
 * (e.g. "list this week's chores", "add an expense"), the same way MeadBotAPI exposes its
 * calculator operations as tools. Empty scaffold for now: definitions() returns no tools, so
 * ChatAgent runs as a plain chat model until real tools are registered here.
 */
final class Tools
{
    /**
     * call(name, arguments) - dispatch a tool call by name. Throws InvalidArgumentException for
     * an unknown tool name; parameter validation errors from the operation itself should also
     * surface as InvalidArgumentException (ChatAgent catches this and feeds the message back to
     * the model as the tool result, rather than failing the whole chat request over one bad tool
     * call).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function call(string $name, array $arguments): array
    {
        throw new InvalidArgumentException("Unknown tool: {$name}");
    }

    /**
     * definitions() - the OpenAI/Fireworks "tools" array to send with every chat completion.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [];
    }
}
