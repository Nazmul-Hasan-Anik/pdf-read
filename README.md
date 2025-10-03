# How to Run

1. install PHP (version >= 8.1)

2. install Composer (https://getcomposer.org)

3. copy file `.env.example` to `.env`
4. run this command  `composer dump-autoload -o `
5. run this command `php artisan optimize:clear`
6. start Tinker console: `php artisan tinker` then write the pdf file path as example: `process_pdf(storage_path('pdf_client_test/FUSM202509121122340001.pdf'));`

