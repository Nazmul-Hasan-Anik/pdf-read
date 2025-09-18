<?php

namespace App\Assistants;

class AutoPdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return null;
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        foreach (static::getPdfAssistants() as $assistant_class) {
            if ($assistant_class::validateFormat($lines)) {
                $match = $assistant_class;
                break;
            }
        }

        if (!isset($match)) {
            throw new \Exception("AutoPdfAssistant: no match found for PDF: {$attachment_filename}");
        }
        
        $assistant = new $assistant_class;
        $assistant->processLines($lines, $attachment_filename);

        $this->output = $assistant->getOutput();
    }

    public static function getPdfAssistants() : array {
        return array_values(array_filter(
            getNamespaceClasses('App\Assistants'),
            function($c) {
                try {
                    return is_a($c, PdfClient::class, true)
                        && !is_a($c, self::class, true)
                        && !(new \ReflectionClass($c))->isAbstract();
                } catch (\ErrorException $e) {
                    return false;
                }
            }
        ));
    }
}
