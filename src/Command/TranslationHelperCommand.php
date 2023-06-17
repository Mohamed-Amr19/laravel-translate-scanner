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
     *
     * @return void
     */
    public function handle(): void
    {
        $translationKeys = $this->findProjectTranslationsKeys();
        $translationFiles = $this->getProjectTranslationFiles();

        foreach ($translationFiles as $file) {
            $translationData = $this->getAlreadyTranslatedKeys($file);
            $added = [];

            $this->line('Language: '.str_replace('.json', '', basename($file)));

            foreach ($translationKeys as $key) {
                if (! isset($translationData[$key])) {
                    $translationData[$key] = '';
                    $added[] = $key;

                    $this->warn(" - Added: $key");
                }
            }

            if ($added) {
                $this->line('Updating translation file...');

                $this->writeNewTranslationFile($file, $translationData);

                $this->info('Translation file have been updated!');
            } else {
                $this->warn('Nothing new found for this language.');
            }

            $this->line('');
        }
    }

    /**
     * @return array
     */
    private function findProjectTranslationsKeys(): array
    {
        $allKeys = [];
        $viewsDirectories = config('translation-scanner.scan_directories');
        $fileExtensions = config('translation-scanner.file_extensions');

        foreach ($viewsDirectories as $directory) {
            foreach ($fileExtensions as $extension) {
                $this->getTranslationKeysFromDir($allKeys, $directory, $extension);
            }
        }

        ksort($allKeys);

        return $allKeys;
    }

    /**
     * @param  array  $keys
     * @param  string  $dirPath
     * @param  string  $fileExt
     */
    private function getTranslationKeysFromDir(array &$keys, string $dirPath, string $fileExt = 'php')
    {
        $files = glob_recursive("$dirPath/*.$fileExt", GLOB_BRACE);

        foreach ($files as $file) {
            $content = $this->getSanitizedContent($file);

            foreach (config('translation-scanner.translation_methods') as $translationMethod) {
                $this->getTranslationKeysFromFunction($keys, $translationMethod, $content);
            }
        }
    }

    /**
     * @param  array  $keys
     * @param  string  $functionName
     * @param  string  $content
     */
    private function getTranslationKeysFromFunction(array &$keys, string $functionName, string $content)
    {
        $matches = [];

        preg_match_all("#{$functionName}\(\s*\'(.*?)\'\s*[\)\,]#", $content, $matches);

        if (! empty($matches)) {
            foreach ($matches[1] as $match) {
                $match = str_replace('"', "'", str_replace("\'", "'", $match));

                if (! empty($match)) {
                    $keys[$match] = $match;
                }
            }
        }
    }

    /**
     * @return array
     */
    private function getProjectTranslationFiles(): array
    {
        $path = config('translation-scanner.output_directory');

        return glob("$path/*.json", GLOB_BRACE);
    }

    /**
     * @param  string  $filePath
     * @return array
     */
    private function getAlreadyTranslatedKeys(string $filePath): array
    {
        $current = json_decode(file_get_contents($filePath), true);

        ksort($current);

        return $current;
    }

    /**
     * make the key same as the value
     * so that the translator can easily translate it
     *
     * @param  string  $filePath
     * @param  array  $translations
     */
    private function writeNewTranslationFile(string $filePath, array $translations)
    {
        foreach ($translations as $key => $value) {
            $translations[$key] = $key;
        }

        file_put_contents($filePath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  string  $filePath
     * @return string
     */
    private function getSanitizedContent(string $filePath): string
    {
        return str_replace("\n", ' ', file_get_contents($filePath));
    }
}
