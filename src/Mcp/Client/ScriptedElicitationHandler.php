<?php

declare(strict_types=1);

namespace App\Mcp\Client;

use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\EnumSchemaDefinition;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Psr\Log\LoggerInterface;

/**
 * The client side of **elicitation**: a remote server asks this application's
 * MCP client to collect structured input from the user.
 *
 * A real host renders a form. Here the answers come from a script that a test —
 * or a console command — sets beforehand:
 *
 *     $handler->script(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
 *     $handler->scriptDecline();
 *
 * With nothing scripted the handler synthesises a plausible answer from the
 * requested schema, so the demo works out of the box; anything the schema
 * marks required but that neither source can fill is left out, which is exactly
 * the case a server has to survive.
 */
final class ScriptedElicitationHandler implements ElicitationCallbackInterface
{
    private ElicitAction $action = ElicitAction::Accept;

    /**
     * @var array<string, mixed>
     */
    private array $answers = [];

    /**
     * @var list<array{message: string, action: string, content: array<string, mixed>|null}>
     */
    private array $log = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $answers
     */
    public function script(array $answers): void
    {
        $this->action = ElicitAction::Accept;
        $this->answers = $answers;
    }

    public function scriptDecline(): void
    {
        $this->action = ElicitAction::Decline;
        $this->answers = [];
    }

    public function scriptCancel(): void
    {
        $this->action = ElicitAction::Cancel;
        $this->answers = [];
    }

    public function reset(): void
    {
        $this->action = ElicitAction::Accept;
        $this->answers = [];
        $this->log = [];
    }

    /**
     * Every elicitation this handler has answered, oldest first.
     *
     * @return list<array{message: string, action: string, content: array<string, mixed>|null}>
     */
    public function getLog(): array
    {
        return $this->log;
    }

    public function __invoke(ElicitRequest $request): ElicitResult
    {
        $this->logger->info('Answering an elicitation request from a remote MCP server.', [
            'message' => $request->message,
            'mode' => $request->mode->value,
            'action' => $this->action->value,
        ]);

        if (ElicitAction::Accept !== $this->action) {
            $this->log[] = ['message' => $request->message, 'action' => $this->action->value, 'content' => null];

            return new ElicitResult($this->action);
        }

        $content = $this->answers + $this->fromSchema($request);

        $this->log[] = ['message' => $request->message, 'action' => 'accept', 'content' => $content];

        return new ElicitResult(ElicitAction::Accept, $content);
    }

    /**
     * Fill whatever the script did not, using the schema's own defaults and hints.
     *
     * @return array<string, mixed>
     */
    private function fromSchema(ElicitRequest $request): array
    {
        $schema = $request->requestedSchema;

        if (null === $schema) {
            return [];
        }

        $filled = [];

        foreach ($schema->properties as $name => $definition) {
            $filled[$name] = match (true) {
                $definition instanceof EnumSchemaDefinition => $definition->default ?? $definition->enum[0] ?? null,
                $definition instanceof BooleanSchemaDefinition => $definition->default ?? true,
                $definition instanceof NumberSchemaDefinition => $definition->default ?? $definition->minimum ?? 1,
                $definition instanceof StringSchemaDefinition => $definition->default ?? $this->stringFor($name),
                default => $this->stringFor($name),
            };
        }

        return array_filter($filled, static fn (mixed $value): bool => null !== $value);
    }

    private function stringFor(string $name): string
    {
        return match (true) {
            str_contains($name, 'email') => 'scripted@example.com',
            str_contains($name, 'name') => 'Scripted Speaker',
            str_contains($name, 'date') => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            default => 'scripted',
        };
    }
}
