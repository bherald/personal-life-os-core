<?php

namespace Tests\Unit\Services;

use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliPromptCounter;
use App\Services\UnifiedCli\CliStateStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UnifiedCliPromptCounterTest extends TestCase
{
    private string $directory;

    private CliStateStore $store;

    private CliArtifactStore $artifacts;

    private array $session;

    private array $paths = [];

    private array $route;

    protected function setUp(): void
    {
        if (! is_executable('/usr/bin/python3')) {
            $this->markTestSkipped('This process boundary test requires Python 3.');
        }
        $this->directory = sys_get_temp_dir().'/cli-counter-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->artifacts = new CliArtifactStore($this->directory.'/artifacts');
        $this->store = new CliStateStore(':memory:');
        $this->session = $this->store->createSession([]);
        // Deliberately synthetic library: verifies control flags and the process
        // boundary, and never represents a qualified model tokenizer.
        file_put_contents($this->directory.'/tokenizers.py', <<<'PY'
import types
__version__ = 'fixture'
class Tokenizer:
    @classmethod
    def from_str(cls, source):
        assert source == '{}'
        return cls()
    def no_truncation(self): self.unbounded = True
    def no_padding(self): self.unpadded = True
    def encode(self, prompt, add_special_tokens):
        assert self.unbounded and self.unpadded and add_special_tokens is False
        return types.SimpleNamespace(ids=list(prompt))
PY);
        $sources = ['tokenizer' => '{}', 'family_registry' => '# fixture family', 'v4_dsml' => '# fixture dependency',
            'renderer' => <<<'PY'
ARCH = 'glm'
def render_chat(messages, enable_thinking, reasoning_effort, tools, tool_choice):
    assert enable_thinking is False
    assert tool_choice == ('auto' if tools else None)
    return '<fixture>' + ''.join(m['content'] for m in messages) + '</fixture>'
PY];
        $this->route = ['instance_id' => 'fixture', 'model' => 'fixture-glm', 'agent_admitted' => true,
            'enable_thinking' => false, 'tokenizer' => ['driver' => 'colibri_glm', 'tokenizers_version' => 'fixture']];
        foreach ($sources as $name => $source) {
            $path = $this->directory.'/'.$name.'.asset';
            file_put_contents($path, $source);
            $hash = hash('sha256', $source);
            $this->paths[$hash] = $path;
            $this->route['tokenizer'][$name.'_sha256'] = $hash;
        }
    }

    protected function tearDown(): void
    {
        if (! isset($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_worker_preserves_full_rendered_prompt_and_records_selected_asset_identity(): void
    {
        $text = "  José\r\n\t  ";
        $messages = [['role' => 'user', 'content' => $text]];
        $tokens = $this->counter()->count($this->route, $messages, []);
        $this->assertGreaterThan(0, $tokens);
        $events = $this->store->events($this->session['id']);
        $this->assertCount(1, $events);
        $this->assertSame('prompt_count', $events[0]['type']);
        $event = $events[0]['document']['event'];
        $this->assertSame('<fixture>'.$text.'</fixture>', $this->artifacts->read($event['rendered_prompt']['sha256']));
        $this->assertSame($tokens, $event['tokens']);
        $this->assertSame('fixture-glm', $event['model']);
        $this->assertSame($this->route['tokenizer']['renderer_sha256'], $event['asset_sha256']['renderer']);
        $this->assertSame([['role' => 'user', 'content' => $text]], $messages);
    }

    public function test_optional_v41_dependency_requires_verified_bytes_and_is_recorded(): void
    {
        $source = "value = 'pinned-v41'\n";
        $hash = hash('sha256', $source);
        $this->paths[$hash] = $this->directory.'/v41.asset';
        file_put_contents($this->paths[$hash], $source);
        $this->route['tokenizer']['v41_dsml_sha256'] = $hash;
        $renderer = "import v41_dsml\nARCH = 'glm'\ndef render_chat(messages, **kwargs):\n    return v41_dsml.value + messages[0]['content']\n";
        $rendererHash = hash('sha256', $renderer);
        file_put_contents($this->directory.'/renderer.asset', $renderer);
        $this->paths[$rendererHash] = $this->directory.'/renderer.asset';
        $this->route['tokenizer']['renderer_sha256'] = $rendererHash;
        $this->assertGreaterThan(0, $this->counter()->count($this->route, [['role' => 'user', 'content' => 'exact']], []));
        $event = $this->store->events($this->session['id'])[0]['document']['event'];
        $this->assertSame($hash, $event['asset_sha256']['v41_dsml']);
        $this->assertSame('pinned-v41exact', $this->artifacts->read($event['rendered_prompt']['sha256']));
        file_put_contents($this->paths[$hash], "raise RuntimeError('unverified dependency')");
        $before = count($this->store->events($this->session['id']));
        try {
            $this->counter()->count($this->route, [['role' => 'user', 'content' => 'exact']], []);
            $this->fail('Changed dependency accepted');
        } catch (RuntimeException) {
            $this->assertCount($before, $this->store->events($this->session['id']));
        }
    }

    public function test_missing_v41_pin_cannot_import_an_ambient_dependency(): void
    {
        file_put_contents($this->directory.'/v41_dsml.py', "raise RuntimeError('ambient dependency must not run')\n");
        $renderer = "import v41_dsml\nARCH = 'glm'\ndef render_chat(*args, **kwargs): return 'unsafe'\n";
        $hash = hash('sha256', $renderer);
        file_put_contents($this->directory.'/renderer.asset', $renderer);
        $this->paths[$hash] = $this->directory.'/renderer.asset';
        $this->route['tokenizer']['renderer_sha256'] = $hash;
        $this->assertRefused($this->route);
    }

    public function test_current_gateway_dependency_uses_verified_bytes_in_the_managed_lossless_mode(): void
    {
        foreach (['v41_dsml' => '# fixture v41', 'image_engine' => "value = 'pinned-image'\n"] as $name => $source) {
            $hash = hash('sha256', $source);
            $this->paths[$hash] = $this->directory.'/'.$name.'.asset';
            file_put_contents($this->paths[$hash], $source);
            $this->route['tokenizer'][$name.'_sha256'] = $hash;
        }
        // Any ambient import is an error, even when its name matches the pin.
        file_put_contents($this->directory.'/image_engine.py', "raise RuntimeError('ambient module ran')\n");
        $renderer = <<<'PY'
import image_engine
import os
ARCH = 'glm'
def render_chat(messages, **kwargs):
    text = messages[0]['content']
    return image_engine.value + (text if os.environ.get('PLOS_CLI_RUNTIME_IDENTITY') == '1' else text.strip())
PY;
        $hash = hash('sha256', $renderer);
        file_put_contents($this->directory.'/renderer.asset', $renderer);
        $this->paths[$hash] = $this->directory.'/renderer.asset';
        $this->route['tokenizer']['renderer_sha256'] = $hash;
        $text = "  José\r\n\t  ";
        $this->counter()->count($this->route, [['role' => 'user', 'content' => $text]], []);
        $event = $this->store->events($this->session['id'])[0]['document']['event'];
        $this->assertSame('pinned-image'.$text, $this->artifacts->read($event['rendered_prompt']['sha256']));
        $this->assertSame($this->route['tokenizer']['image_engine_sha256'], $event['asset_sha256']['image_engine']);
        file_put_contents($this->paths[$this->route['tokenizer']['image_engine_sha256']], "raise RuntimeError('changed dependency')\n");
        try {
            $this->counter()->count($this->route, [['role' => 'user', 'content' => $text]], []);
            $this->fail('Changed image dependency accepted');
        } catch (RuntimeException) {
            $this->assertCount(1, $this->store->events($this->session['id']));
        }
    }

    public function test_unpinned_current_gateway_dependency_cannot_use_ambient_source(): void
    {
        file_put_contents($this->directory.'/image_engine.py', "raise RuntimeError('ambient module ran')\n");
        $renderer = "import image_engine\nARCH = 'glm'\ndef render_chat(*args, **kwargs): return 'unsafe'\n";
        $hash = hash('sha256', $renderer);
        file_put_contents($this->directory.'/renderer.asset', $renderer);
        $this->paths[$hash] = $this->directory.'/renderer.asset';
        $this->route['tokenizer']['renderer_sha256'] = $hash;
        $this->assertRefused($this->route);
    }

    public function test_changed_asset_or_library_version_is_rejected_without_count_or_prompt_event(): void
    {
        $route = $this->route;
        $route['tokenizer']['tokenizers_version'] = 'different';
        $this->assertRefused($route);
        file_put_contents($this->directory.'/renderer.asset', 'raise RuntimeError("private-fixture-content")');
        $this->assertRefused($this->route);
    }

    public function test_renderer_failure_does_not_expose_private_input_or_claim_a_count(): void
    {
        $source = "ARCH = 'glm'\ndef render_chat(*args, **kwargs):\n    raise RuntimeError('private-fixture-content')\n";
        file_put_contents($this->directory.'/renderer.asset', $source);
        $hash = hash('sha256', $source);
        $this->paths[$hash] = $this->directory.'/renderer.asset';
        $this->route['tokenizer']['renderer_sha256'] = $hash;
        $this->assertRefused($this->route);
    }

    public function test_no_approximation_when_runtime_identity_or_explicit_thinking_is_missing(): void
    {
        $route = $this->route;
        unset($route['enable_thinking']);
        $this->assertRefused($route);
        $route = $this->route;
        $route['tokenizer']['renderer_sha256'] = str_repeat('0', 64);
        $this->assertRefused($route);
    }

    private function counter(): CliPromptCounter
    {
        return new CliPromptCounter($this->store, $this->session['id'], $this->artifacts,
            '/usr/bin/python3', $this->paths, $this->directory);
    }

    public function test_private_location_manifest_binds_exact_assets_without_supplying_route_authority(): void
    {
        $path = $this->directory.'/tokenizer-bindings.json';
        file_put_contents($path, json_encode($this->manifest()));
        chmod($path, 0600);
        $counter = CliPromptCounter::fromBindingsFile($this->store, $this->session['id'], $this->artifacts, $path);
        $text = "  exact José\r\n\t  ";
        $this->assertGreaterThan(0, $counter->count($this->route, [['role' => 'user', 'content' => $text]], []));
        $event = $this->store->events($this->session['id'])[0]['document']['event'];
        $this->assertSame('<fixture>'.$text.'</fixture>', $this->artifacts->read($event['rendered_prompt']['sha256']));
        $this->assertArrayNotHasKey('agent_admitted', json_decode(file_get_contents($path), true));
        $route = $this->route;
        $route['agent_admitted'] = false;
        $this->expectExceptionMessage('Exact selected-model tokenizer');
        $counter->count($route, [], []);
    }

    public function test_binding_manifest_rejects_authority_fields_bad_locations_and_nonprivate_files(): void
    {
        $path = $this->directory.'/tokenizer-bindings.json';
        foreach ([['agent_admitted' => true], ['route' => $this->route], ['python' => 'python3'],
            ['asset_paths' => ['not-a-hash' => '/tmp/example']], ['python_library_path' => '../libraries']] as $changes) {
            file_put_contents($path, json_encode(array_replace($this->manifest(), $changes)));
            chmod($path, 0600);
            try {
                CliPromptCounter::fromBindingsFile($this->store, $this->session['id'], $this->artifacts, $path);
                $this->fail('Invalid local metadata was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('No runtime admission was granted', $error->getMessage());
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            file_put_contents($path, json_encode($this->manifest()));
            chmod($path, 0644);
            try {
                CliPromptCounter::fromBindingsFile($this->store, $this->session['id'], $this->artifacts, $path);
                $this->fail('Nonprivate manifest was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('No runtime admission was granted', $error->getMessage());
            }
        }
        $this->assertSame([], $this->store->events($this->session['id']));
    }

    private function manifest(): array
    {
        return ['schema' => 'plos.cli.tokenizer-bindings.v1', 'python' => '/usr/bin/python3',
            'python_library_path' => $this->directory, 'asset_paths' => $this->paths];
    }

    private function assertRefused(array $route): void
    {
        try {
            $this->counter()->count($route, [['role' => 'user', 'content' => 'private-fixture-content']], []);
            $this->fail('Invalid selected tokenizer was used.');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('private-fixture-content', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame([], $this->store->events($this->session['id']));
    }
}
