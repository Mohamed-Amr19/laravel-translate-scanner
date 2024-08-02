<?php

namespace NawrasBukhariTranslationScanner\Command;

use Illuminate\Console\Command;

class TranslationHelperCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'translation:scan';

    /**
     * @var string
     */
    protected $description = 'Searches for translation keys – inserts into JSON translation files.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Searching for translation keys...');
        $translationKeys = $this->findProjectTranslationsKeys();

        if (empty($translationKeys)) {
            $this->error('No translation keys found.');
            return;
        }

        $this->info('Translation keys have been found!');
        $translationFiles = $this->getProjectTranslationFiles();

        if (empty($translationFiles)) {
            $this->warn('No translation files found. Generating a new translation file.');
            $translationFiles[] = $this->createNewTranslationFile();
        }

        foreach ($translationFiles as $file) {
            $this->info('Checking translation file: ' . basename($file));
            $translationData = $this->getAlreadyTranslatedKeys($file);
            $added = [];

            $this->line('Language: ' . str_replace('.json', '', basename($file)));

            foreach ($translationKeys as $key) {
                if (!isset($translationData[$key])) {
                    $translationData[$key] = '';
                    $added[] = $key;

                    $this->warn(" - Added: $key");
                }
            }

            if ($added) {
                $this->line('Updating translation file...');

                $this->writeNewTranslationFile($file, $translationData);

                $this->info('Translation file has been updated!');
            } else {
                $this->warn('Nothing new found for this language.');
            }

            $this->line('');
        }

        $this->info('All done!');
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

        if (!empty($allKeys)) {
            ksort($allKeys);
        }

        return $allKeys;
    }

    private function getTranslationKeysFromDir(array &$keys, string $dirPath, string $fileExt = 'php'): void
    {
        $files = glob_recursive("$dirPath/*.$fileExt", GLOB_BRACE);

        if (empty($files)) {
            $this->warn("No files found in directory: $dirPath with extension: $fileExt");
            return;
        }

        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);

            if (is_array(config('translation-scanner.translation_methods'))) {
                foreach (config('translation-scanner.translation_methods') as $translationMethod) {
                    $this->getTranslationKeysFromFunction($keys, $translationMethod, $content);
                }
            }
        }
    }

    private function getTranslationKeysFromFunction(array &$keys, string $functionName, string $content): void
    {
        $matches = [];

        preg_match_all("#$functionName\(\s*(['\"])(.*?)\\1\s*[\),]#", $content, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $match) {
                $match = str_replace('"', "'", $match);

                if (!empty($match)) {
                    $keys[$match] = $match;
                }
            }
        }
    }

    private function getProjectTranslationFiles(): array
    {
        $path = config('translation-scanner.output_directory');

        if (empty($path)) {
            $this->error('Output directory configuration is missing.');
            return [];
        }

        $files = glob("$path/*.json");

        if (empty($files)) {
            $this->warn("No JSON translation files found in directory: $path");
        }

        return $files;
    }

    private function createNewTranslationFile(): string
    {
        $path = config('translation-scanner.output_directory');

        if (empty($path)) {
            $this->error('Output directory configuration is missing.');
            return '';
        }

        $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
        $fileName = "$path/translation_$timestamp.json";
        file_put_contents($fileName, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Created new translation file: ' . basename($fileName));

        return $fileName;
    }

    private function getAlreadyTranslatedKeys(string $filePath): array
    {
        $current = json_decode(file_get_contents($filePath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Error decoding JSON from file: ' . $filePath);
            return [];
        }

        if (!empty($current)) {
            ksort($current);
        }

        return $current;
    }

    private function writeNewTranslationFile(string $filePath, array $translations): void
    {
        foreach ($translations as $key => $value) {
            $translations[$key] = $key;
        }

        file_put_contents($filePath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function getSanitizedContent(string $filePath): string
    {
        return str_replace("\n", ' ', file_get_contents($filePath));
    }
}
