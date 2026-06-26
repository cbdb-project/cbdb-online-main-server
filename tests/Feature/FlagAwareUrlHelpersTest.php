<?php

namespace Tests\Feature;

use Tests\TestCase;

class FlagAwareUrlHelpersTest extends TestCase {
    public function test_person_page_url_respects_separate_show_and_editor_flags(): void {
        config([
            'migration_flags.pages.basicinformation.show' => 'old',
            'migration_flags.pages.basicinformation.editor' => 'old',
        ]);

        $this->assertSame('/basicinformation/123', person_page_url(123, 'show'));
        $this->assertSame('/basicinformation/123/edit', person_page_url(123, 'edit'));

        config([
            'migration_flags.pages.basicinformation.show' => 'new',
            'migration_flags.pages.basicinformation.editor' => 'new',
        ]);

        $this->assertSame('/app/basicinformation/123', person_page_url(123, 'show'));
        $this->assertSame('/app/basicinformation/123/edit', person_page_url(123, 'edit'));
    }

    public function test_person_index_helpers_respect_index_flag(): void {
        config(['migration_flags.pages.basicinformation.index' => 'old']);

        $this->assertSame('/basicinformation', person_index_base_url());
        $this->assertSame('/basicinformation?q=%E8%98%87%E8%BB%BE', person_index_url(['q' => '蘇軾']));

        config(['migration_flags.pages.basicinformation.index' => 'new']);

        $this->assertSame('/app/basicinformation', person_index_base_url());
        $this->assertSame('/app/basicinformation?q=42', person_index_url(['q' => 42]));
    }
}
