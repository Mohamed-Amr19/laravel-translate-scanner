<?php

namespace NawrasBukhariTranslationScanner\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TranslationHelperCommand extends Command
{
    protected $signature = 'translation:scan';
    protected $description = 'Scans project for translation keys and adds missing ones to en.json';

    private array $excludePatterns = [
        '/^https?:\/\//', // URLs
        '/^\{\$.*\}/', // Variables with {$prefix} pattern
        '/^filament-panels::/', // Filament panel resources
        '/[^.]\./' // Any key containing a dot that isn't at the end
    ];

    public function handle(): void
    {
        $this->info('Scanning for translation keys...');
        $translationKeys = $this->findProjectTranslationsKeys();

        if (empty($translationKeys)) {
            $this->error('No translation keys found.');
            return;
        }

        $this->info('Translation keys found! Processing...');

        // Get or create en.json file
        $enJsonPath = lang_path('en.json');
        if (!file_exists($enJsonPath)) {
            $this->info('Creating new en.json file...');
            file_put_contents($enJsonPath, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Process translations
        $translationData = $this->getAlreadyTranslatedKeys($enJsonPath);
        $added = [];

        foreach ($translationKeys as $key) {
            if (!isset($translationData[$key])) {
                $translationData[$key] = $key; // Use key as default translation
                $added[] = $key;
                $this->warn(" - Added: $key");
            }
        }

        if ($added) {
            $this->line('Updating en.json file...');
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

        if (empty($viewsDirectories) || empty($fileExtensions)) {
            $this->error('Configuration for scan directories or file extensions is missing.');
            return $allKeys;
        }

        // Scan regular translation keys
        foreach ($viewsDirectories as $directory) {
            foreach ($fileExtensions as $extension) {
                $this->getTranslationKeysFromDir($allKeys, $directory, $extension);
            }
        }

        // Scan Filament panels
        $this->scanFilamentPanels($allKeys);

        // Filter out unwanted keys
        $allKeys = array_filter($allKeys, fn($key) => $this->shouldIncludeKey($key), ARRAY_FILTER_USE_KEY);

        if (!empty($allKeys)) {
            ksort($allKeys);
        }

        return $allKeys;
    }

    private function scanFilamentPanels(array &$keys): void
    {
        $filamentPath = app_path('Filament');
        if (!is_dir($filamentPath)) return;

        // Get all panel directories (Admin, Store, etc.)
        $panels = array_filter(glob("$filamentPath/*"), 'is_dir');

        foreach ($panels as $panel) {
            $panelName = basename($panel);

            // Skip if it's not a proper panel directory
            if ($panelName === '.' || $panelName === '..') continue;

            $this->info("Scanning panel: $panelName");

            // Process panel name for translation
            $formattedPanelName = preg_replace('/(?<!^)([A-Z])/', ' $1', $panelName);
            if ($this->shouldIncludeKey($formattedPanelName)) {
                $keys[ucfirst($formattedPanelName)] = ucfirst($formattedPanelName);
                $keys[ucfirst(Str::plural($formattedPanelName))] = ucfirst(Str::plural($formattedPanelName));
            }

            // Scan Resources directory in the panel
            $this->scanPanelResources("$panel/Resources", $keys);

            // Scan Pages directory in the panel
            $this->scanPanelPages("$panel/Pages", $keys);
        }
    }

    private function scanPanelResources(string $resourcesPath, array &$keys): void
    {
        if (!is_dir($resourcesPath)) return;

        // Scan resource files
        $resources = glob("$resourcesPath/*Resource.php");
        foreach ($resources as $resource) {
            $resourceName = basename($resource, 'Resource.php');
            $formattedName = preg_replace('/(?<!^)([A-Z])/', ' $1', $resourceName);

            // Add singular form
            $singularKey = ucfirst($formattedName);
            if ($this->shouldIncludeKey($singularKey)) {
                $keys[$singularKey] = $singularKey;
            }

            // Add plural form
            $pluralKey = ucfirst(Str::plural($formattedName));
            if ($this->shouldIncludeKey($pluralKey)) {
                $keys[$pluralKey] = $pluralKey;
            }

            // Scan resource file content
            $this->scanResourceFile($resource, $keys);
        }

        // Scan nested directories
        $directories = glob("$resourcesPath/*/");
        foreach ($directories as $directory) {
            $this->scanPanelResources($directory, $keys);
        }
    }

    private function scanPanelPages(string $pagesPath, array &$keys): void
    {
        if (!is_dir($pagesPath)) return;

        // Scan all PHP files in the Pages directory and its subdirectories
        $files = glob_recursive("$pagesPath/**/*.php");
        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);

            // Scan for Filament-specific translations
            $this->getTranslationKeysFromFilament($keys, $content);

            // Scan for regular translation methods
            foreach (config('translation-scanner.translation_methods', []) as $method) {
                $this->getTranslationKeysFromFunction($keys, $method, $content);
            }
        }
    }

    private function scanResourceFile(string $resourceFile, array &$keys): void
    {
        $content = $this->getSanitizedContent($resourceFile);

        // Scan for form labels, table headers, and other Filament-specific content
        $this->getTranslationKeysFromFilament($keys, $content);

        // Scan for regular translation methods
        foreach (config('translation-scanner.translation_methods', []) as $method) {
            $this->getTranslationKeysFromFunction($keys, $method, $content);
        }
    }

    private function getTranslationKeysFromFilament(array &$keys, string $content): void
    {
        // Match Filament component labels
        preg_match_all("/::make\(['\"](.*?)['\"]\)/", $content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                $transformedKey = ucfirst(str_replace('_', ' ', $match));
                if (!empty($transformedKey) && $this->shouldIncludeKey($transformedKey)) {
                    $keys[$transformedKey] = $transformedKey;
                    $pluralKey = ucfirst(Str::plural(str_replace('_', ' ', $match)));
                    if ($this->shouldIncludeKey($pluralKey)) {
                        $keys[$pluralKey] = $pluralKey;
                    }
                }
            }
        }

        // Match translateLabel() usage
        preg_match_all("/->translateLabel\(\)/", $content, $labelMatches);
        if (!empty($labelMatches[0])) {
            preg_match_all("/::make\(['\"](.*?)['\"]\).*?->translateLabel\(\)/", $content, $componentMatches);
            if (!empty($componentMatches[1])) {
                foreach ($componentMatches[1] as $match) {
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

            foreach (config('translation-scanner.translation_methods', []) as $method) {
                $this->getTranslationKeysFromFunction($keys, $method, $content);
            }
        }
    }

    private function getTranslationKeysFromFunction(array &$keys, string $functionName, string $content): void
    {
        preg_match_all("#$functionName\(\s*(['\"])(.*?)\\1\s*[\),]#", $content, $matches);
        if (!empty($matches[2])) {
            foreach ($matches[2] as $match) {
                $match = str_replace('"', "'", $match);
                if (!empty($match) && $this->shouldIncludeKey($match)) {
                    $keys[$match] = $match;
                }
            }
        }
    }

    private function getAlreadyTranslatedKeys(string $filePath): array
    {
        $current = json_decode(file_get_contents($filePath), true) ?? [];
        if (!empty($current)) {
            ksort($current);
        }
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