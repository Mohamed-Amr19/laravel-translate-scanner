<?php

return [
    /**
     * Directories to scan for missing translation keys.
     */
    'scan_directories' => [
        /** if you want to scan your `app` directory, use the following line **/
        app_path(),

        /** if you want to scan your `resources` directory, use the following line **/
        resource_path('views'),

    /** if you are using modules, you can add them here **/
        //app_path('Modules'),

    ],

    /**
     * File extensions to scan from.
     */
    'file_extensions' => [
        'php',
        //'js',
        //'vue',
    ],

    /**
     * Directory where your JSON translation files are located.
     * This is where the missing translation keys will be added.
     * If you want to add the missing keys to a different directory,
     * you can change the value of this variable.
     *
     * https://laravel.com/docs/10.x/helpers#method-lang-path
     */
    'output_directory' => lang_path(),

    /**
     * Translation helper methods to scan
     * for in your application's code.
     */
    'translation_methods' => [
        '@lang',
        '__',
        'trans',
        'trans_choice',
    ],
];
