<?php

namespace App\Assistants;

use Spatie\PdfToText\Pdf;
use Opis\JsonSchema\{
    Helper,
    Validator,
    ValidationResult,
    Errors\ErrorFormatter,
};

abstract class PdfClient
{
    protected $output;

    /**
     * Checks if the given file contents match the format of this class.
     * 
     * @param array $lines  plain text lines extracted from PDF
     */
    abstract public static function validateFormat (array $lines);

    
    /**
     * Generates a structured output from PDF file contents.
     * 
     * @param array $lines  plain text lines extracted from PDF
     * @param string|null $attachment_filename  filename of the PDF
     */
    abstract public function processLines (array $lines, ?string $attachment_filename = null);

    public function createOrder (array $data) {
        $json = Helper::toJSON($data);

        $result = $this->getValidator()
            ->validate($json, 'http://localhost/order.json');

        if ($result->isValid()) {
            $this->output = $data;
        } else {
            echo json_encode($json);
            $errors = json_encode((new ErrorFormatter())->format($result->error()));
            throw new \Exception($errors);
        }
    }

    public function processPath (string $filename) {
        $lines = $this->extractLocalPdfLines($filename);

        $this->processLines($lines, basename($filename));

        return $this->getOutput();
    }

    public function getOutput() {
        return $this->output;
    }

    public static function extractPdfLines ($file_content) : array {
        $temp_file = tempnam(sys_get_temp_dir(), 'pdf-to-text');
        $file = fopen($temp_file, 'w');
        fwrite($file, $file_content);
        fclose($file);
        $lines = static::extractLocalPdfLines($temp_file);
        unlink($temp_file);

        return $lines;
    }

    public static function extractLocalPdfLines (string $filename) : array {
        $text = (new Pdf(env('PDFTOTEXT_PATH', 'pdftotext')))
            ->setPdf($filename)
            ->text();

        $text = str_replace("\f", "", $text);

        return explode("\n", $text);
    }

    protected function getValidator() : Validator {
        $validator = new Validator();

        $validator->resolver()->registerFile(
            'http://localhost/order.json', 
            storage_path('order_schema.json')
        );

        return $validator;
    }
}
