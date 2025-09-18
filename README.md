# SETUP

1. install PHP (version >= 8.1)

2. install Composer (https://getcomposer.org)

3. copy file `.env.example` to `.env`

3. install Poppler
    - see instructions at https://github.com/spatie/pdf-to-text?tab=readme-ov-file#requirements
    - for Windows, see https://github.com/oschwartz10612/poppler-windows
    - if using a custom installation of Poppler, edit the `.env` file and change the
      `PDFTOTEXT_PATH` variable to point to the `pdftotext` executable

4. run:
```shell
composer install
```

5. start Tinker console:
```shell
php artisan tinker
```


====================================================================================================

# THE GOAL

The goal is to create a class that turns the plain text extracted from a PDF into a structured array
that matches a JSON schema for a transport order. The new class must live in the `App\Assistants`
namespace and must extend the `App\Assistants\PdfClient` class. All logic related to processing a
single format of PDF must must be contained in its own class definition.

The class must call the method `createOrder()` from its implementation of `processLines()`. The
format for `createOrder()` parameter `$data` is defined as JSON schema: `storage/order_schema.json`.

If the class definition is set up correctly, calling `process_pdf('/path/to/some.pdf')` from the
Tinker console will return a properly structured associative array. Otherwise, some error with be
thrown.


====================================================================================================

# TIPS

 - see included examples of `AccessPdfAssistant`, `DelamodePdfAssistant` and `SkodaPdfAssistant` and
   their corresponding PDF format examples under `storage/pdf_client_test`

 - extract plain text from PDF by calling `extract_lines('/path/to/some.pdf')` from Tinker console
   to get started (see definition in `app/Helpers/Helper.php`)

 - use Carbon to deal with date-time strings: https://carbon.nesbot.com/docs/

 - use Laravel helper methods to work with arrays and strings:
    https://laravel.com/docs/10.x/helpers#available-methods
    https://laravel.com/docs/10.x/strings#available-methods

 - use `uncomma()` to deal with numbers that may use a decimal comma (see `app/Helpers/Helper.php`)

 - use `App\GeonamesCountry::getIso()` to convert country names to ISO codes
