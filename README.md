<p align="center">
<a href="https://packagist.org/packages/subotkevic/laravel-json-translation-helper"><img src="https://poser.pugx.org/subotkevic/laravel-json-translation-helper/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/subotkevic/laravel-json-translation-helper"><img src="https://poser.pugx.org/subotkevic/laravel-json-translation-helper/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/subotkevic/laravel-json-translation-helper"><img src="https://poser.pugx.org/subotkevic/laravel-json-translation-helper/license.svg" alt="License"></a>
</p>

# JSON Translation Helper for Laravel
JSON Translation Helper scans your project for `__()`, `lang()` translation helper methods and `@lang` directives, then it creates keys in your JSON translation files. 

And yes, it avoids duplicates – helper creates only non-existing keys. Ready for your translation.

## Installation

First, install JSON Translation Helper using the Composer require command:

```
composer require subotkevic/laravel-json-translation-helper
```

That's it. Package registers service provider automatically.

## Usage

### Translation files

First, you have to create your translation files for languages you will translate your application to. 

For example, if you want your application to have a Spanish translation, you should create a `resources/lang/es.json` file.

Of course you can have multiple translation files:
```
resources/
    lang/
        es.json
        fr.json
```

Make sure that your translation files is valid JSON, otherwise our package will not work:

```json
{
    "I love programming.": "Me encanta programar."
}
```

If you don't have any translations for now, just **make sure your file is not empty**, but actually an empty JSON object:
```js
{}
```

### Scan your application

Finally, to scan your application for missing translation keys just run:

```
php artisan translation:scan
```


## Customization

### Publishing the configuration

You can change the default paths to scan your application from, the output directory where your JSON translation files are located, and even the file extensions you want to scan from.

First, publish the configuration file:

```
php artisan vendor:publish --provider="JsonTranslationHelper\TranslationHelperServiceProvider"
```

Then in the `config/translation-helper.php` you can change default values of `scan_directories`, `file_extensions`, `output_directory` and `translation_methods`.

### Extending file extensions

Our package scans only `.php` files out of the box.

You can add more file extensions to `file_extensions` array in the `config/translation-helper.php` configuration file to scan, let's say, `.vue` or `.js` files:
```php
/**
 * File extensions to scan from.
 */
'file_extensions' => [
    'php',
    'js',
    'vue',
],
```

### Modifying translation helper methods

By default our package looks for `lang` and `__` translation helper methods or mixins.

But you can extend, modify, or remove them in the config file by modifying `translation_methods` array:
```php
/**
 * Translation helper methods to scan
 * for in your application's code.
 */
'translation_methods' => [
    'lang',
    '__',
],
```