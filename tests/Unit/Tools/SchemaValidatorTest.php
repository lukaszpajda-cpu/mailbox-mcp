<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Unit\Tools;

use MailboxMcp\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private function validator(): SchemaValidator
    {
        return new SchemaValidator();
    }

    public function testRequiredMissing(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer']],
            'required' => ['limit'],
        ];

        $this->assertSame(['limit: required'], $this->validator()->validate($schema, []));
    }

    public function testTypeMismatch(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer']],
        ];

        $this->assertSame(
            ['limit: must be an integer'],
            $this->validator()->validate($schema, ['limit' => 'abc']),
        );
    }

    public function testEnum(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['folder' => ['type' => 'string', 'enum' => ['INBOX', 'Drafts']]],
        ];

        $this->assertSame(
            ['folder: must be one of INBOX, Drafts'],
            $this->validator()->validate($schema, ['folder' => 'Trash']),
        );
        $this->assertSame([], $this->validator()->validate($schema, ['folder' => 'INBOX']));
    }

    public function testIntegerBounds(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ];

        $this->assertSame(['limit: must be >= 1'], $this->validator()->validate($schema, ['limit' => 0]));
        $this->assertSame(['limit: must be <= 100'], $this->validator()->validate($schema, ['limit' => 101]));
        $this->assertSame([], $this->validator()->validate($schema, ['limit' => 50]));
    }

    public function testStringPattern(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'pattern' => '^[0-9]+$']],
        ];

        $this->assertSame(
            ['id: must match pattern ^[0-9]+$'],
            $this->validator()->validate($schema, ['id' => 'abc']),
        );
        $this->assertSame([], $this->validator()->validate($schema, ['id' => '123']));
    }

    public function testArrayOfStringsMaxItems(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'pattern' => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$'],
                    'maxItems' => 2,
                ],
            ],
        ];

        $errors = $this->validator()->validate($schema, [
            'to' => ['a@example.com', 'not-an-email', 'b@example.com'],
        ]);

        $this->assertContains('to: must have at most 2 items', $errors);
        $this->assertContains('to.1: must match pattern ^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$', $errors);
        $this->assertCount(2, $errors);
    }

    public function testAdditionalPropertiesRejected(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer']],
            'additionalProperties' => false,
        ];

        $this->assertSame(
            ['foo: unexpected property'],
            $this->validator()->validate($schema, ['limit' => 10, 'foo' => 'bar']),
        );
    }

    public function testApplyDefaults(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'default' => 50],
                'folder' => ['type' => 'string', 'default' => 'INBOX'],
            ],
        ];

        $this->assertSame(
            ['limit' => 50, 'folder' => 'INBOX'],
            $this->validator()->applyDefaults($schema, []),
        );
        $this->assertSame(
            ['limit' => 5, 'folder' => 'INBOX'],
            $this->validator()->applyDefaults($schema, ['limit' => 5]),
        );
    }

    public function testIntegerRejectsFloatAndNumericString(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer']],
        ];

        $this->assertSame(
            ['limit: must be an integer'],
            $this->validator()->validate($schema, ['limit' => 1.5]),
        );
        $this->assertSame(
            ['limit: must be an integer'],
            $this->validator()->validate($schema, ['limit' => '5']),
        );
    }

    public function testArrayRequiresList(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['to' => ['type' => 'array']],
        ];

        $this->assertSame(
            ['to: must be an array'],
            $this->validator()->validate($schema, ['to' => ['a' => 'x', 'b' => 'y']]),
        );
        $this->assertSame([], $this->validator()->validate($schema, ['to' => ['x', 'y']]));
        $this->assertSame([], $this->validator()->validate($schema, ['to' => []]));
    }

    public function testCollectsAllErrors(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer'],
                'folder' => ['type' => 'string'],
            ],
            'required' => ['limit', 'folder'],
        ];

        $errors = $this->validator()->validate($schema, []);

        $this->assertCount(2, $errors);
        $this->assertContains('limit: required', $errors);
        $this->assertContains('folder: required', $errors);
    }

    public function testRootTypeMismatchUsesDollarPath(): void
    {
        $schema = ['type' => 'array'];

        $this->assertSame(['$: must be an array'], $this->validator()->validate($schema, ['a' => 'b']));
    }

    public function testUnknownKeywordsIgnored(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'bogusKeyword' => 'whatever']],
            'bogusTopLevel' => true,
        ];

        $this->assertSame([], $this->validator()->validate($schema, ['limit' => 5]));
    }

    public function testRequiredWithoutProperties(): void
    {
        $schema = ['type' => 'object', 'required' => ['token']];

        $this->assertSame(['token: required'], $this->validator()->validate($schema, []));
        $this->assertSame([], $this->validator()->validate($schema, ['token' => 'x']));
    }

    public function testAdditionalPropertiesWithoutProperties(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false];

        $this->assertSame(
            ['sneaky: unexpected property'],
            $this->validator()->validate($schema, ['sneaky' => 'x']),
        );
        $this->assertSame([], $this->validator()->validate($schema, []));
    }

    public function testPatternContainingSlash(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'pattern' => '^a/b$']],
        ];

        $this->assertSame([], $this->validator()->validate($schema, ['id' => 'a/b']));
        $this->assertSame(
            ['id: must match pattern ^a/b$'],
            $this->validator()->validate($schema, ['id' => 'ab']),
        );
    }

    public function testInvalidPatternReportsErrorWithoutWarning(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'pattern' => '(unterminated']],
        ];

        $this->assertSame(
            ['id: invalid pattern'],
            $this->validator()->validate($schema, ['id' => 'anything']),
        );
    }
}
