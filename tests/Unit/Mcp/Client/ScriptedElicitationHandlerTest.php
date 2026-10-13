<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp\Client;

use App\Mcp\Client\ScriptedElicitationHandler;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\NumberSchemaDefinition;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Request\ElicitRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where an accepted answer's values come from: the script first, then the
 * defaults the server declared, then whatever the handler makes up.
 *
 * The middle step is the SDK's `ElicitationSchema::getDefaults()` since 0.9;
 * before that the handler read each definition's `default` itself. Falsey
 * defaults are the part worth pinning — `false` and `0` are answers, not gaps.
 */
final class ScriptedElicitationHandlerTest extends TestCase
{
    public function testTheScriptWinsOverADeclaredDefault()
    {
        $handler = new ScriptedElicitationHandler(new NullLogger());
        $handler->script(['room' => 'Studio A']);

        $result = $handler($this->request(['room' => new StringSchemaDefinition(default: 'Main Hall')]));

        $this->assertSame(['room' => 'Studio A'], $result->content);
    }

    public function testADeclaredDefaultWinsOverAnInventedValue()
    {
        $handler = new ScriptedElicitationHandler(new NullLogger());

        $result = $handler($this->request([
            'room' => new StringSchemaDefinition(default: 'Main Hall'),
            'newsletter' => new BooleanSchemaDefinition(default: false),
            'seats' => new NumberSchemaDefinition(integerOnly: true, default: 0),
        ]));

        $this->assertSame(['room' => 'Main Hall', 'newsletter' => false, 'seats' => 0], $result->content);
    }

    public function testAFieldWithoutADefaultIsInvented()
    {
        $handler = new ScriptedElicitationHandler(new NullLogger());

        $result = $handler($this->request(['email' => new StringSchemaDefinition(format: 'email')]));

        $this->assertSame(['email' => 'scripted@example.com'], $result->content);
    }

    /**
     * @param array<string, StringSchemaDefinition|BooleanSchemaDefinition|NumberSchemaDefinition> $properties
     */
    private function request(array $properties): ElicitRequest
    {
        return new ElicitRequest('Fill this in.', new ElicitationSchema($properties));
    }
}
