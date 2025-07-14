<?php

namespace NawrasBukhariTranslationScanner\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TranslateLocalesCommand extends Command
{
    protected $signature = 'translation:localize';
    protected $description = 'Translate en.json keys into all available locales using Google Translate';

    public function handle(): void
    {
        $defaultLang = 'en';
        $locales = config('app.locales', ['ar']);
        $basePath = lang_path();

        $enPath = "$basePath/$defaultLang.json";
        if (!file_exists($enPath)) {
            $this->error("Missing file: $enPath");
            return;
        }

        $enTranslations = json_decode(file_get_contents($enPath), true);
        if (empty($enTranslations)) {
            $this->error("No keys found in $defaultLang.json");
            return;
        }

        foreach ($locales as $locale) {
            if ($locale === $defaultLang) continue;

            $this->line("🔁 Translating for locale: $locale");

            $localePath = "$basePath/$locale.json";
            $existingTranslations = file_exists($localePath)
                ? json_decode(file_get_contents($localePath), true)
                : [];

            foreach ($enTranslations as $key => $value) {
                if (!isset($existingTranslations[$key])) {
                    $translated = $this->translatePreservingPlaceholders($value, $locale);
                    $existingTranslations[$key] = $translated;
                    $this->warn(" → [$locale] $key => $translated");
                }
            }

            ksort($existingTranslations);
            file_put_contents($localePath, json_encode($existingTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("✅ Saved $locale.json");
        }

        $this->info('🎉 All translations complete!');
    }

    private function translatePreservingPlaceholders(string $text, string $targetLang): string
    {
        // Step 1: Extract Laravel-style placeholders (e.g., :name, :locale)
        preg_match_all('/(:\w+)/', $text, $matches);
        $placeholders = $matches[0] ?? [];

        // Step 2: Replace placeholders with tokens
        $tokenized = $text;
        $tokens = [];
        foreach (array_values($placeholders) as $i => $placeholder) {
            $token = '__PH_' . $i . '__';
            $tokens[$token] = $placeholder;
            $tokenized = str_replace($placeholder, $token, $tokenized);
        }

        // Step 3: Translate using Google Translate
        $translated = $this->translate($tokenized, $targetLang);

        // Step 4: Restore the original placeholders
        foreach ($tokens as $token => $placeholder) {
            $translated = str_replace($token, $placeholder, $translated);
        }

        return $translated;
    }


    private function translate(string $text, string $targetLang): string
    {
        $response = Http::retry(2, 500)->get("https://translate.googleapis.com/translate_a/single", [
            'client' => 'gtx',
            'sl'     => 'en',
            'tl'     => $targetLang,
            'dt'     => 't',
            'q'      => $text,
        ]);

        if ($response->successful()) {
            return $response->json()[0][0][0] ?? $text;
        }

        return $text; // fallback if translation fails
    }
}
