<?php


namespace Laika\Template;

use RuntimeException;
use Throwable;

class Template
{
    protected string $path;
    protected string $cachePath;
    protected array $vars;
    protected Compiler $compiler;


    public function __construct(string $path, ?string $cachePath = null)
    {
        $this->path = rtrim($path, '/');
        $this->cachePath = $cachePath ?: $this->path . '/../cache';
        $this->vars = [];

        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            if (!@mkdir($this->cachePath, 0775, true) && !is_dir($this->cachePath)) {
                throw new \RuntimeException("Unable to create cache directory: {$this->cachePath}");
            }
        }

        $this->compiler = new Compiler($this->path);
    }

    protected function templateFile(string $file): string
    {
        $file = preg_replace('/\\.tpl$/', '', $file);
        return "{$this->path}/{$file}.tpl";
    }

    protected function cacheFilePathFor(string $templateFile, array $deps): string
    {
        $parts = [];
        $real = realpath($templateFile) ?: $templateFile;
        $parts[] = $real . ':' . (file_exists($real) ? filemtime($real) : 0);

        foreach ($deps as $d) {
            $r = realpath($d) ?: $d;
            $parts[] = $r . ':' . (file_exists($r) ? filemtime($r) : 0);
        }

        $hash = md5(implode('-', $parts));
        return "{$this->cachePath}/{$hash}.cache.php";
    }

    protected function metaFilePathForCache(string $cacheFile): string
    {
        return "{$cacheFile}.meta.json";
    }

    /**
     * Assign Vars
     * @param string $key Data Varable Key Name. Example: 'name'
     * @param mixed $value Data Varable Key Value. Example: 'Cloud Bill Master'
     * @return void
     */
    public function assign(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
        return;
    }

    /**
     * @param string $file Render a template by name (without extension).
     * @return string
     */
    public function render(string $file): string
    {
        $tpl = $this->templateFile($file);
        if (! file_exists($tpl)) {
            throw new \RuntimeException("Template file not found: $tpl");
        }

        $source = file_get_contents($tpl);

        // compile -> get code and deps
        $compiled = $this->compiler->compile($source, $tpl);
        $deps = $compiled['deps'] ?? [];
        $code = $compiled['code'] ?? '';

        $cacheFile = $this->cacheFilePathFor($tpl, $deps);
        $metaFile = $this->metaFilePathForCache($cacheFile);

        // If cache missing, write compiled code and meta
        if (!file_exists($cacheFile)) {
            // Create Cache File With Contents
            $php = "<?php\n// Compiled template — do not modify\n?>\n{$code}";
            file_put_contents($cacheFile, $php);

            $meta = [
                'source' => realpath($tpl) ?: $tpl,
                'deps' => $deps,
                'compiled_at' => time(),
            ];
            file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
        } else {
            // if meta exists, verify none of the deps changed; if changed, force recompile by unlinking cache
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true) ?: [];
                $recompile = false;
                foreach (($meta['deps'] ?? []) as $d) {
                    if (!file_exists($d) || filemtime($d) > ($meta['compiled_at'] ?? 0)) {
                        $recompile = true;
                        break;
                    }
                }
                if ($recompile) {
                    @unlink($cacheFile);
                    @unlink($metaFile);
                    // write newly compiled file now (recursive safe)
                    return $this->render($file);
                }
            }
        }

        // Render compiled PHP in isolated scope
        ob_start();
        try {
            extract($this->vars, EXTR_SKIP);
            include $cacheFile;
        } catch (Throwable $e) {
            throw new RuntimeException("Error rendering template {$file}: {$e->getMessage()}", 0, $e);
        }

        return (string) ob_get_clean();
    }
}
