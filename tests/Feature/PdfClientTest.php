<?php

namespace Tests\Feature;

use App\Assistants\AutoPdfAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfClientTest extends TestCase
{
    /**
     * Tests that each PDF is valid for exactly one assistant.
     * 
     * @dataProvider assistantPdfLinesProvider
     */
    public function testValidateFormat(string $assistant_class, string $pdf_path, array $lines): void
    {
        $target_assistant = explode('_', basename($pdf_path))[0];
        $this_assistant = class_basename($assistant_class);
        $is_target = $this_assistant == $target_assistant;

        if ($is_target) {
            $this->assertTrue($assistant_class::validateFormat($lines));
        } else {
            $this->assertFalse($assistant_class::validateFormat($lines));
        }
    }

    public static function assistantClassesProvider() {
        return AutoPdfAssistant::getPdfAssistants();
    }

    public static function pdfPathsProvider() {
        $partials = Storage::files('pdf_client_test');

        $output = [];
        foreach ($partials as $partial) {
            $pdf = basename($partial);
            if (!preg_match('/^[A-Za-z]+_[0-9]+.pdf$/ui', $pdf)) {
                \trigger_error("Invalid filename: {$pdf}", \E_USER_WARNING);
                continue;
            }
            $output[] = Storage::path($partial);
        }

        return $output;
    }

    public static function pdfLinesProvider() {
        $output = [];

        foreach (static::pdfPathsProvider() as $pdf) {
            $lines = AutoPdfAssistant::extractLocalPdfLines($pdf);
            $output[] = [$pdf, $lines];
        }

        return $output;
    }

    public static function assistantPdfLinesProvider() {
        static::createApplicationStatic();

        $output = [];

        foreach (static::assistantClassesProvider() as $assistant) {
            foreach (static::pdfLinesProvider() as $pdf) {
                $output[] = [$assistant, $pdf[0], $pdf[1]];
            }
        }

        return $output;
    }
}
