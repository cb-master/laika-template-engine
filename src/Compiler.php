<?php

namespace Laika\Template;

use RuntimeException;
use Throwable;

class Compiler
{
    protected array $filters = [];
    protected string $basePath;
    protected array $dependencies;
    protected array $processingStack;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?: getcwd();
        $this->dependencies = [];
        $this->processingStack = [];
    }

    /**
     * Compile entry. Returns compiled PHP code + list of absolute dependency paths.
     *
     * @param string $template Raw template contents
     * @param string|null $templatePath Absolute or relative path to the template file (helps resolves includes)
     * @return array{code:string, deps:array}
     */
    public function compile(string $template, ?string $templatePath = null): array
    {
        try {
            $this->dependencies = [];
            $this->processingStack = [];
            $compiled = $this->processTemplate($template, $templatePath);
            // unique deps, normalized
            $deps = array_values(array_unique(array_filter($this->dependencies, fn ($p) => is_string($p) && $p !== '')));
            return [
                'code' => $compiled,
                'deps' => $deps
            ];
        } catch (Throwable $e) {
            $info = $templatePath ? " (template: {$templatePath})" : '';
            throw new RuntimeException("Template compilation failed{$info}: " . $e->getMessage(), 0, $e);
        }
    }

    protected function processTemplate(string $template, ?string $templatePath): string
    {
        // If we have a templatePath and it resolves, push to processing stack for circular detection
        $resolvedPath = $templatePath ? $this->resolvePath($templatePath) : null;
        if ($resolvedPath !== null) {
            if (in_array($resolvedPath, $this->processingStack, true)) {
                throw new RuntimeException("Circular template dependency detected: {$resolvedPath}");
            }
            array_push($this->processingStack, $resolvedPath);
            // record as dependency (source itself is a dependency)
            $this->dependencies[] = $resolvedPath;
        }

        // Remove raw PHP tags
        $template = preg_replace('/<\?(php|=)?(?!xml)[\s\S]*?\?>/i', '', $template);

        $template = $this->handleExtends($template, $templatePath);
        $template = $this->handleIncludes($template, $templatePath);


        $replacements = [
            '/\{\%\s*if\s+(.+?)\s*\%\}/i'       =>  '<?php if ($1): ?>',
            '/\{\%\s*elseif\s+(.+?)\s*\%\}/i'   =>  '<?php elseif ($1): ?>',
            '/\{\%\s*else\s*\%\}/i'             =>  '<?php else: ?>',
            '/\{\%\s*endif\s*\%\}/i'            =>  '<?php endif; ?>',
            '/\{\%\s*foreach\s+(.+?)\s*\%\}/i'  =>  '<?php foreach ($1): ?>',
            '/\{\%\s*endforeach\s*\%\}/i'       =>  '<?php endforeach; ?>',
            '/\{\%\s*for\s+(.+?)\s*\%\}/i'      =>  '<?php for ($1): ?>',
            '/\{\%\s*endfor\s*\%\}/i'           =>  '<?php endfor; ?>'
        ];



        $out = preg_replace(array_keys($replacements), array_values($replacements), $template);


        $out = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function ($m) {
            $expr   =   trim($m[1]);
            $parts  =   preg_split('/\|/', $expr);
            $varExpr =   array_shift($parts);
            $filters =   array_map('trim', $parts ?: []);
            $phpExpr =   $this->applyFilters($varExpr, $filters);
            return '<?php echo ' . $phpExpr . '; ?>';
        }, $out);

        // Pop processing stack (if pushed earlier)
        if ($resolvedPath !== null) {
            array_pop($this->processingStack);
        }

        return $out;
    }

    ##################################################################
    /*------------------------ INTERNAL API ------------------------*/
    ##################################################################

    /**
     * @param string $templatePath Resolve given templatePath to absolute path if possible.
     * @return ?string Accepts either absolute path or relative path. If path points to a file - return its realpath.
     */
    protected function resolvePath(string $templatePath): ?string
    {
        // If templatePath already points to a real file, return realpath
        if (file_exists($templatePath)) {
            return realpath($templatePath) ?: $templatePath;
        }
        // Otherwise try resolving relative to basePath
        $candidate = rtrim($this->basePath, '/') . '/' . ltrim($templatePath, '/');
        if (file_exists($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
        return null;
    }

    protected function ensureDollar(string $expr): string
    {
        if (preg_match('/^\$|^[\'\"\(0-9]/', $expr)) {
            return $expr;
        }
        return '$' . trim($expr);
    }


    /**
     * Convert an expression and chain of filters to PHP code.
     * @param string $expr Filter Name. Ensures bare variables get $ prefix
     * @param array $filters Applies Filters::resolve
     * @return string
     */
    protected function applyFilters(string $expr, array $filters): string
    {
        $php = $this->ensureDollar($expr);
        $escaped = true;

        foreach ($filters as $f) {
            if ($f === 'raw') {
                $escaped = false;
                continue;
            }
            $fn = Filter::resolve($f);
            $php = $fn ? sprintf('%s(%s)', is_string($fn) ? $fn : $f, $php) : sprintf('%s(%s)', $f, $php);
        }

        return $escaped ? "htmlspecialchars($php, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')" : $php;
    }

    /**
     * @param string $template Handle include directives by inlining the compiled include.
     * @param ?string $templatePath Adds included files to $this->dependencies and prevents circular includes.
     * @return string
     */
    protected function handleIncludes(string $template, ?string $templatePath): string
    {
        return preg_replace_callback('/\{\%\s*include\s+[\'\"](.+?)[\'\"]\s*\%\}/', function ($m) use ($templatePath) {
            $inc = $m[1];
            $dir = $templatePath ? dirname($templatePath) : $this->basePath;
            $file = rtrim($dir, '/') . "/{$inc}.tpl";
            if (!file_exists($file)) {
                throw new RuntimeException("Included template not found: {$inc}");
            }
            $this->dependencies[] = realpath($file);
            $content = file_get_contents($file);
            return $this->processTemplate($content, $file);
        }, $template);
    }

    /**
     * @param string $template Handle extends directive. Returns merged parent+child template text.
     * @param ?string $templatePath Tracks parent as a dependency and prevents circular extends.
     * @return string
     */
    protected function handleExtends(string $template, ?string $templatePath): string
    {
        if (!preg_match('/\{\%\s*extends\s+[\'\"](.+?)[\'\"]\s*\%\}/', $template, $m)) {
            return $template;
        }

        $parent = $m[1];
        $dir = $templatePath ? dirname($templatePath) : $this->basePath;
        $parentFile = rtrim($dir, '/') . "/{$parent}.tpl";
        if (!file_exists($parentFile)) {
            throw new RuntimeException("Parent template not found: {$parent}");
        }

        $this->dependencies[] = realpath($parentFile);
        $parentContent = file_get_contents($parentFile);
        $childBlocks = $this->extractBlocks($template);
        return $this->mergeBlocks($parentContent, $childBlocks);
    }

    /**
     * @param string $template Template Html Content
     * Extract block content map: ['blockName' => 'block html']
     * @return array Blocks Array
     */
    protected function extractBlocks(string $template): array
    {
        $blocks = [];
        if (preg_match_all('/\{\%\s*block\s+(\w+)\s*\%\}(.*?)\{\%\s*endblock\s*\%\}/s', $template, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $blocks[$m[1]] = $m[2];
            }
        }
        return $blocks;
    }

    /**
     * Merge blocks: for each parent block, if child has override, apply it. Supports `{% parent %}` placeholder
     * @param string $parent Parent Block Name
     * @param array $childBlocks Child Blocks name
     * @return string Returns parent content with blocks replaced by child overrides (or left as-is when absent).
     */
    protected function mergeBlocks(string $parent, array $childBlocks): string
    {
        $merged = preg_replace_callback(
            '/\{\%\s*block\s+(\w+)\s*\%\}(.*?)\{\%\s*endblock\s*\%\}/s',
            function ($m) use ($childBlocks) {
                $blockName = $m[1];
                $content = $m[2];
                if (isset($childBlocks[$blockName])) {
                    $childContent = $childBlocks[$blockName];
                    return preg_replace('/\{\%\s*parent\s*\%\}/i', $content, $childContent);
                }
                return $content;
            },
            $parent
        );

        // handle nested parent/child relations recursively
        if (preg_match('/\{\%\s*extends\s+[\'\"](.+?)[\'\"]\s*\%\}/i', $merged)) {
            $merged = $this->handleExtends($merged, null);
        }

        return $merged;
    }
}
