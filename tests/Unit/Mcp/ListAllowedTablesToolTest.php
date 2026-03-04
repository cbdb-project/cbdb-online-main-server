<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\ListAllowedTablesTool;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListAllowedTablesToolTest extends TestCase {
    #[Test]
    public function it_exposes_object_input_schema_for_function_calling_compatibility(): void {
        $tool = new ListAllowedTablesTool();
        $toolArray = $tool->toArray();

        $this->assertIsArray($toolArray['inputSchema'] ?? null);
        $this->assertSame('object', $toolArray['inputSchema']['type'] ?? null);
        $this->assertIsArray($toolArray['inputSchema']['properties'] ?? null);
        $this->assertArrayHasKey('keyword', $toolArray['inputSchema']['properties'] ?? []);
        $this->assertSame('string', $toolArray['inputSchema']['properties']['keyword']['type'] ?? null);
    }
}
