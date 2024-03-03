<?php

use Illuminate\Contracts\Translation\Translator;

if (! function_exists('lang')) {
    /**
     * Translate the given message.
     * https://laravel.com/docs/10.x/localization#retrieving-translation-strings
     */
    function lang(?string $key = null, array $replace = [], ?string $locale = null): string|Translator
    {
        return __($key, $replace, $locale);
    }
}

if (! function_exists('glob_recursive')) {
    /**
     * Find path names matching a pattern recursively
     * https://www.php.net/manual/en/function.glob.php#106595
     */
    function glob_recursive($pattern, int $flags = 0): array|false
    {
        $files = glob($pattern, $flags);

        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
        }

        return $files;
    }
}
