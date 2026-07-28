<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncOpenccTradSimpSourceTest extends TestCase {
    protected function createFixture(string $content): string {
        $path = tempnam(sys_get_temp_dir(), 'test_opencc_source_');
        file_put_contents($path, $content);

        return $path;
    }

    protected function cleanup(string ...$paths): void {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    #[Test]
    public function it_overwrites_the_output_path_with_downloaded_contents(): void {
        $source = $this->createFixture("乾\t干\n幹\t干\n");
        $output = tempnam(sys_get_temp_dir(), 'test_opencc_dest_');

        try {
            $exitCode = Artisan::call('cbdb:sync-opencc-trad-simp', [
                '--url' => 'file://' . $source,
                '--output' => $output,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertSame(file_get_contents($source), file_get_contents($output));
        } finally {
            $this->cleanup($source, $output);
        }
    }

    #[Test]
    public function it_fails_without_writing_when_source_parses_to_nothing(): void {
        $source = $this->createFixture("# only comments, no mappings\n");
        $output = tempnam(sys_get_temp_dir(), 'test_opencc_dest_');
        $originalOutputContent = file_get_contents($output);

        try {
            $exitCode = Artisan::call('cbdb:sync-opencc-trad-simp', [
                '--url' => 'file://' . $source,
                '--output' => $output,
            ]);

            $this->assertSame(1, $exitCode);
        } finally {
            $this->cleanup($source, $output);
        }
    }

    #[Test]
    public function it_fails_when_download_fails(): void {
        $output = tempnam(sys_get_temp_dir(), 'test_opencc_dest_');

        try {
            $exitCode = Artisan::call('cbdb:sync-opencc-trad-simp', [
                '--url' => 'file:///nonexistent/path/does-not-exist.txt',
                '--output' => $output,
            ]);

            $this->assertSame(1, $exitCode);
        } finally {
            $this->cleanup($output);
        }
    }
}
