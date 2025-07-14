<?php

namespace NawrasBukhariTranslationScanner\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TranslationHelperCommand extends Command
{
    protected $signature = 'translation:scan';
    protected $description = 'Scans project for translation keys and adds missing ones to en.json';

    private array $excludePatterns = [
        '/^https?:\/\//',            // URLs
        '/^\{\$.*\}/',               // Variables like {$var}
        '/^filament-panels::/',      // Filament namespace keys
        '/[^.]\./',                  // Dot notation (excluding leading dot)
        '/:[a-zA-Z0-9_]+/'           // Placeholders like :locale, :name
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

        $enJsonPath = lang_path('en.json');
        if (!file_exists($enJsonPath)) {
            $this->info('Creating new en.json file...');
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

        foreach ($viewsDirectories as $directory) {
            foreach ($fileExtensions as $extension) {
                $this->getTranslationKeysFromDir($allKeys, $directory, $extension);
            }
        }

        $this->scanFilamentPanels($allKeys);
        $this->scanModules($allKeys);

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

        // Check if it's a single-panel structure (Filament/Resources exists directly)
        if (is_dir("$filamentPath/Resources") || is_dir("$filamentPath/Pages")) {
            $this->info("Scanning default Filament panel structure...");

            $this->scanPanelResources("$filamentPath/Resources", $keys);
            $this->scanPanelPages("$filamentPath/Pages", $keys);
        }

        // Also scan named panel folders (e.g., Admin, Store)
        $panelDirs = array_filter(glob("$filamentPath/*"), 'is_dir');
        foreach ($panelDirs as $panel) {
            $panelName = basename($panel);

            // Skip if it's not a proper panel folder or already scanned as default
            if (in_array($panelName, ['Resources', 'Pages'])) continue;

            $this->info("Scanning panel: $panelName");

            // Process panel name for translation
            $formattedPanelName = preg_replace('/(?<!^)([A-Z])/', ' $1', $panelName);
            if ($this->shouldIncludeKey($formattedPanelName)) {
                $keys[ucfirst($formattedPanelName)] = ucfirst($formattedPanelName);
                $keys[ucfirst(Str::plural($formattedPanelName))] = ucfirst(Str::plural($formattedPanelName));
            }

            // Scan Resources and Pages inside this panel
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

            $moduleName = basename($modulePath);
            $this->info("Scanning module: $moduleName");

            $directories = config('translation-scanner.scan_directories', []);
            $fileExtensions = config('translation-scanner.file_extensions', []);

            foreach ($directories as $directory) {
                $fullPath = $modulePath . '/' . $directory;
//                $this->info($fullPath);
                foreach ($fileExtensions as $ext) {
                    $this->getTranslationKeysFromDir($keys, $fullPath, $ext);
                }
            }
//            $this->info($modulePath);
            $this->scanPanelResources("$modulePath/app/Filament/Resources", $keys);
            $this->scanPanelPages("$modulePath/app/Filament/Pages", $keys);
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
            if ($this->shouldIncludeKey($singularKey)) {
                $keys[$singularKey] = $singularKey;
            }

            $pluralKey = ucfirst(Str::plural($formattedName));
            if ($this->shouldIncludeKey($pluralKey)) {
                $keys[$pluralKey] = $pluralKey;
            }

            $this->scanResourceFile($resource, $keys);
        }

        $directories = glob("$resourcesPath/*/", GLOB_ONLYDIR);
        foreach ($directories as $directory) {
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

            foreach (config('translation-scanner.translation_methods', []) as $method) {
                $this->getTranslationKeysFromFunction($keys, $method, $content);
            }
        }
    }

    private function scanResourceFile(string $resourceFile, array &$keys): void
    {
        $content = $this->getSanitizedContent($resourceFile);
        $this->getTranslationKeysFromFilament($keys, $content);

        foreach (config('translation-scanner.translation_methods', []) as $method) {
            $this->getTranslationKeysFromFunction($keys, $method, $content);
        }
    }

    private function getTranslationKeysFromFilament(array &$keys, string $content): void
    {
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
