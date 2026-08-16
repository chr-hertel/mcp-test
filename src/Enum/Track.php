<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The conference tracks a talk can belong to.
 *
 * Used as a native enum type hint on MCP tool arguments, where the SDK's schema
 * generator turns it into a JSON Schema `enum` automatically — see
 * {@see \App\Mcp\Tool\TalkSearchTool}.
 */
enum Track: string
{
    case Backend = 'backend';
    case Frontend = 'frontend';
    case DevOps = 'devops';
    case Architecture = 'architecture';
    case Community = 'community';
    case AI = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Backend => 'Backend',
            self::Frontend => 'Frontend',
            self::DevOps => 'DevOps & Infrastructure',
            self::Architecture => 'Architecture',
            self::Community => 'Community & Career',
            self::AI => 'AI & Machine Learning',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
