<?php

if (! function_exists('array_find_key')) {
    /**
     * Polyfill for PHP versions <8.4.
     * See: https://www.php.net/manual/en/function.array-find-key.php
     */
    function array_find_key (array $array, callable $callback) {
        foreach ($array as $key => $value) {
            if (call_user_func($callback, $value, $key)) {
                return $key;
            }
        }

        return null;
    }
}

/**
 * List all autoloaded classes within a namespace.
 * 
 * @param string $namespace
 * @return array
 */
function getNamespaceClasses (string $namespace) : array {
    $loader = require __DIR__ . '/../../vendor/autoload.php';
    $classes = array_keys($loader->getClassMap());
    return array_filter(
        $classes,
        fn($c) => \Illuminate\Support\Str::startsWith($c, $namespace)
    );
}

/**
 * Turn a string that might contain a decimal comma into a float.
 * 
 * @param string|null $input
 * @return float
 */
function uncomma (?string $input) {
    if (!$input) return null;

    $comma_pos = strpos($input, ',');
    $point_pos = strpos($input, '.');
    if ($comma_pos === false || $comma_pos < $point_pos) {
        // Probably uses decimal point and thousands sep comma
        $output = str_replace(',', '', $input);
        $output = str_replace(' ', '', $output);
    } else {
        // Probably uses decimal comma and thousands sep point
        $output = str_replace('.', '', $input);
        $output = str_replace(' ', '', $output);
        $output = str_replace(',', '.', $output); 
    }
    return (float) $output;
}

/**
 * Extract plain text lines from a PDF file.
 * 
 * @param string $filename  path to a PDF file
 * @param bool $numbered  whether to add line numbers to output
 * @param string|null $output_file  path to write the output to
 * @return mixed  string output if $output_file is unspecified, otherwise null
 */
function extract_lines (string $filename, bool $numbered = false, ?string $output_file = null) {
    $lines = (new \App\Assistants\AutoPdfAssistant)
        ->extractLocalPdfLines($filename);

    $collection = collect($lines);

    if ($numbered) {
        $collection = $collection->map(fn($l, $i) => "[{$i}] $l");
    }
    
    $output = $collection->join("\n");

    if ($output_file) {
        file_put_contents($output_file, $output);
    } else {
        return $output;
    }
}

/**
 * Extract structured order data from a PDF file.
 *
 * @param string $filename  path to a PDF file
 * @return array
 */
function process_pdf (string $filename) : array {
    return (new \App\Assistants\AutoPdfAssistant)
        ->processPath($filename);
}
