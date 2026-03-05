<?php

namespace NawrasBukhariTranslationScanner\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TranslationHelperCommand extends Command
{
    protected $signature = 'translation:scan';
    protected $description = 'Scans project for translation keys and adds missing ones to en.json (v4 Compatible)';

    private array $excludePatterns = [
        '/^https?:\/\//',
        '/^\{\$.*\}/',
        '/^filament-panels::/',
        '/^filament-actions::/',     // Added for v4
        '/^filament-forms::/',       // Added for v4
        '/^[^\s]+\.[^\s]+$/',
        '/:[a-zA-Z0-9_]+/'
    ];

    public function handle(): void
    {
        $this->info('Scanning for translation keys for Filament v4 compatibility...');
        $translationKeys = $this->findProjectTranslationsKeys();

        if (empty($translationKeys)) {
            $this->error('No translation keys found.');
            return;
        }

        $enJsonPath = lang_path('en.json');
        if (!file_exists($enJsonPath)) {
            $this->info('Creating new en.json file...');
            if (!is_dir(lang_path())) {
                mkdir(lang_path(), 0755, true);
            }
            file_put_contents($enJsonPath, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $translationData = $this->getAlreadyTranslatedKeys($enJsonPath);
        $added = [];

        foreach ($translationKeys as $key) {
            if (!isset($translationData[$key])) {
                $translationData[$key] = $key;
                $added[] = $key;
                $this->warn(" - Added: $key");
            }
        }

        if ($added) {
            $this->writeTranslationFile($enJsonPath, $translationData);
            $this->info('en.json has been updated with ' . count($added) . ' new translations!');
        } else {
            $this->info('No new translations to add.');
        }
    }

    private function shouldIncludeKey(string $key): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return false;
            }
        }
        return true;
    }

    private function findProjectTranslationsKeys(): array
    {
        $allKeys = [];
        $viewsDirectories = config('translation-scanner.scan_directories', []);
        $fileExtensions = config('translation-scanner.file_extensions', []);

        foreach ($viewsDirectories as $directory) {
            foreach ($fileExtensions as $extension) {
                $this->getTranslationKeysFromDir($allKeys, $directory, $extension);
            }
        }

        $this->scanFilamentPanels($allKeys);
        $this->scanModules($allKeys);

        // Scan for new Filament v4 Schemas specifically
        $this->scanFilamentSchemas($allKeys);

        $allKeys = array_filter($allKeys, fn($key) => $this->shouldIncludeKey($key), ARRAY_FILTER_USE_KEY);

        if (!empty($allKeys)) {
            ksort($allKeys);
        }

        return $allKeys;
    }

    /**
     * Filament v4 introduces a unified Schema system.
     */
    private function scanFilamentSchemas(array &$keys): void
    {
        $schemaPath = app_path('Filament/Schemas');
        if (!is_dir($schemaPath)) return;

        $files = glob_recursive("$schemaPath/**/*.php");
        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);
            $this->getTranslationKeysFromFilament($keys, $content);
        }
    }

    private function scanFilamentPanels(array &$keys): void
    {
        $filamentPath = app_path('Filament');
        if (!is_dir($filamentPath)) return;

        if (is_dir("$filamentPath/Resources") || is_dir("$filamentPath/Pages") || is_dir("$filamentPath/Clusters")) {
            $this->scanPanelResources("$filamentPath/Resources", $keys);
            $this->scanPanelPages("$filamentPath/Pages", $keys);
            // v4 Clusters support
            $this->scanPanelPages("$filamentPath/Clusters", $keys);
        }

        $panelDirs = array_filter(glob("$filamentPath/*"), 'is_dir');
        foreach ($panelDirs as $panel) {
            $panelName = basename($panel);
            if (in_array($panelName, ['Resources', 'Pages', 'Clusters', 'Schemas'])) continue;

            $formattedPanelName = preg_replace('/(?<!^)([A-Z])/', ' $1', $panelName);
            if ($this->shouldIncludeKey($formattedPanelName)) {
                $keys[ucfirst($formattedPanelName)] = ucfirst($formattedPanelName);
            }

            $this->scanPanelResources("$panel/Resources", $keys);
            $this->scanPanelPages("$panel/Pages", $keys);
        }
    }

    private function scanModules(array &$keys): void
    {
        $modulesPath = base_path('Modules');
        if (!is_dir($modulesPath)) return;

        $modules = array_filter(glob("$modulesPath/*"), 'is_dir');
        foreach ($modules as $modulePath) {
            $this->scanPanelResources("$modulePath/app/Filament/Resources", $keys);
            $this->scanPanelPages("$modulePath/app/Filament/Pages", $keys);
            $this->scanPanelPages("$modulePath/app/Filament/Schemas", $keys);
        }
    }

    private function scanPanelResources(string $resourcesPath, array &$keys): void
    {
        if (!is_dir($resourcesPath)) return;

        $resources = glob("$resourcesPath/*Resource.php");
        foreach ($resources as $resource) {
            $resourceName = basename($resource, 'Resource.php');
            $formattedName = preg_replace('/(?<!^)([A-Z])/', ' $1', $resourceName);

            $singularKey = ucfirst($formattedName);
            if ($this->shouldIncludeKey($singularKey)) $keys[$singularKey] = $singularKey;

            $pluralKey = ucfirst(Str::plural($formattedName));
            if ($this->shouldIncludeKey($pluralKey)) $keys[$pluralKey] = $pluralKey;

            $this->scanResourceFile($resource, $keys);
        }

        foreach (glob("$resourcesPath/*/", GLOB_ONLYDIR) as $directory) {
            $this->scanPanelResources($directory, $keys);
        }
    }

    private function scanPanelPages(string $pagesPath, array &$keys): void
    {
        if (!is_dir($pagesPath)) return;
        $files = glob_recursive("$pagesPath/**/*.php");
        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);
            $this->getTranslationKeysFromFilament($keys, $content);
        }
    }

    private function scanResourceFile(string $resourceFile, array &$keys): void
    {
        $content = $this->getSanitizedContent($resourceFile);
        $this->getTranslationKeysFromFilament($keys, $content);
    }

    private function getTranslationKeysFromFilament(array &$keys, string $content): void
    {
        // Capture standard ->label('Key') or ->placeholder('Key')
        preg_match_all("/->(?:label|placeholder|description|heading|subheading|hint)\(['\"](.*?)['\"]\)/", $content, $labelMatches);
        if (!empty($labelMatches[1])) {
            foreach ($labelMatches[1] as $match) {
                if ($this->shouldIncludeKey($match)) $keys[$match] = $match;
            }
        }

        // Capture Filament ::make('field_name') automatically
        preg_match_all("/::make\(['\"](.*?)['\"]\)/", $content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                // Ignore snake_case if it's likely a DB column, unless ->translateLabel() is used
                if (str_contains($content, "::make('{$match}')->translateLabel()")) {
                    $transformedKey = ucfirst(str_replace('_', ' ', $match));
                    if ($this->shouldIncludeKey($transformedKey)) {
                        $keys[$transformedKey] = $transformedKey;
                    }
                }
            }
        }
    }

    private function getTranslationKeysFromDir(array &$keys, string $dirPath, string $fileExt = 'php'): void
    {
        if (!is_dir($dirPath)) return;
        $files = glob_recursive("$dirPath/*.$fileExt");
        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);
            foreach (config('translation-scanner.translation_methods', ['__', 'trans', '@lang']) as $method) {
                $this->getTranslationKeysFromFunction($keys, $method, $content);
            }
        }
    }

    private function getTranslationKeysFromFunction(array &$keys, string $functionName, string $content): void
    {
        preg_match_all("#$functionName\(\s*(['\"])(.*?)\\1\s*[\),]#", $content, $matches);
        if (!empty($matches[2])) {
            foreach ($matches[2] as $match) {
                if (!empty($match) && $this->shouldIncludeKey($match)) {
                    $keys[$match] = $match;
                }
            }
        }
    }

    private function getAlreadyTranslatedKeys(string $filePath): array
    {
        $current = json_decode(file_get_contents($filePath), true) ?? [];
        return $current;
    }

    private function writeTranslationFile(string $filePath, array $translations): void
    {
        ksort($translations);
        file_put_contents($filePath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function getSanitizedContent(string $filePath): string
    {
        return str_replace("\n", ' ', file_get_contents($filePath));
    }
}
