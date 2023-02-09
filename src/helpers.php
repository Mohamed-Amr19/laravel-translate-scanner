<?php

if (! function_exists('lang')) {
    /**
     * Translate the given message.
     *
     * @param  string|null  $key
     * @param  array  $replace
     * @param  string|null  $locale
     * @return \Illuminate\Contracts\Translation\Translator|string
     */
    function lang(string $key = null, array $replace = [], string $locale = null)
    {
        return __($key, $replace, $locale);
    }
}

if (! function_exists('glob_recursive')) {
    /**
     * Find path names matching a pattern recursively
     *
     * @param $pattern
     * @param  int  $flags
     * @return array
     */
    function glob_recursive($pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);

        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
        }

        return $files;
    }
}
