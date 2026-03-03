<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Servers\CbdbReadOnlyServer;
use Illuminate\Support\Arr;
use Laravel\Mcp\Server\Tool;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class McpToolSchemaCompatibilityTest extends TestCase {
    /**
     * @return array<int, class-string<Tool>>
     */
    private function getServerToolClasses(): array {
        $ref = new ReflectionClass(CbdbReadOnlyServer::class);
        /** @var array<int, class-string<Tool>> $tools */
        $tools = $ref->getDefaultProperties()['tools'] ?? [];

        return $tools;
    }

    #[Test]
    public function all_cbdb_mcp_tools_use_object_input_schema(): void {
        foreach ($this->getServerToolClasses() as $toolClass) {
            $tool = app($toolClass);
            $toolArray = $tool->toArray();

            $this->assertSame('object', Arr::get($toolArray, 'inputSchema.type'), "{$toolClass} inputSchema.type should be object");

            $this->assertTrue(
                is_array(Arr::get($toolArray, 'inputSchema.properties')) || is_object(Arr::get($toolArray, 'inputSchema.properties')),
                "{$toolClass} inputSchema.properties should be array|object for function-calling compatibility"
            );
        }
    }
}
