<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Parser for Ziegler UK booking PDFs.
 * - validateFormat() is static (required by PdfClient).
 * - processLines() parses the lines into schema-ready structure.
 */
class ZieglerPdfAssistant extends PdfClient
{
    /**
     * Decide if this assistant can handle the given PDF.
     */
    public static function validateFormat($lines)
    {
        $hay = Str::upper(implode("\n", (array) $lines));
        return Str::contains($hay, 'ZIEGLER UK LTD')
            || Str::contains($hay, 'ZIEGLER REF')
            || Str::contains($hay, 'PLEASE FIND BELOW THE BOOKING');
    }

    /**
     * Parse PDF lines into structured order data.
     */
    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $text = implode("\n", $lines);

        // ---------- Order ----------
        $reference = $this->matchOne($text, '/Ziegler\s*Ref\s*([A-Z0-9\/\-\s]+)/i');
        if ($reference) $reference = trim(preg_replace('/\s+/', ' ', $reference));

        $rateRaw  = $this->matchOne($text, '/\bRate\b\s*[€£$]?\s*([0-9\.,]+)/i');
        $amount   = $rateRaw !== null ? (float) str_replace(',', '', $rateRaw) : null;
        $currency = $this->detectCurrency($text) ?? 'EUR';

        // ---------- Stops ----------
        $stops = [];
        foreach ($this->splitBlocks((array) $lines) as $b) {
            $blockText = implode("\n", $b['lines']);

            $name     = $this->matchOne($blockText, '/^(?:Collection|Delivery)\s+([^\r\n]+)/i');
            $localRef = $this->matchOne($blockText, '/\bREF[:\s-]*([A-Z0-9\-\/]+)/i');

            $date = $this->firstDate($blockText);
            $slot = $this->matchOne($blockText, '/\b(BOOKED-?\s*\d{1,2}:\d{2}\s*(?:AM|PM)?|\d{1,2}h\d{2}\s*[-–]\s*\d{1,2}h\d{2}|\d{2}:\d{2}\s*[-–]\s*\d{2}:\d{2}|\d{4}\s*[-–]\s*\d{4})\b/i');
            [$windowStart, $windowEnd] = $this->parseTimeWindow($slot);

            $pallets = $this->matchOneInt($blockText, '/(\d+)\s*PALLETS?/i');
            $weight  = $this->matchOneFloat($blockText, '/(\d{2,3}[.,]?\d{0,3})\s*(?:KG|KGS)\b/i');

            $address = $this->guessAddressLines($b['lines']);
            $notes   = $this->collectNotes($blockText);

            $stops[] = [
                'type'         => $b['type'],
                'name'         => $name,
                'local_ref'    => $localRef,
                'address'      => $address['full'] ?? null,
                'postal_code'  => $address['postal'] ?? null,
                'city'         => $address['city'] ?? null,
                'country_iso'  => $address['country'] ?? null,
                'date'         => $date,
                'window_start' => $windowStart,
                'window_end'   => $windowEnd,
                'pallets'      => $pallets,
                'weight_kg'    => $weight,
                'notes'        => $notes,
            ];
        }

        // ---------- Terms ----------
        $terms = $this->extractZieglerTerms($text);

        // ---------- Final data ----------
        $data = [
            'order' => [
                'reference' => $reference,
                'price'     => [
                    'amount'   => $amount,
                    'currency' => $currency,
                ],
                'client'    => 'Ziegler UK Ltd',
            ],
            'stops' => $stops,
            'terms' => $terms,
        ];

        return $this->createOrder($data);
    }

    // ================= HELPER METHODS =================

    private function splitBlocks(array $lines): array
    {
        $out = [];
        $current = null;
        foreach ($lines as $ln) {
            $u = Str::upper(trim($ln));
            if (Str::startsWith($u, 'COLLECTION')) {
                if ($current) $out[] = $current;
                $current = ['type' => 'pickup', 'lines' => [trim($ln)]];
            } elseif (Str::startsWith($u, 'DELIVERY')) {
                if ($current) $out[] = $current;
                $current = ['type' => 'delivery', 'lines' => [trim($ln)]];
            } else {
                if ($current) $current['lines'][] = trim($ln);
            }
        }
        if ($current) $out[] = $current;
        return $out;
    }

    private function firstDate(string $text): ?string
    {
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $text, $m)) {
            try {
                return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}")->format('Y-m-d');
            } catch (\Exception $e) { return null; }
        }
        return null;
    }

    private function parseTimeWindow(?string $slot): array
    {
        if (!$slot) return [null, null];
        $s = Str::of($slot)->replace('BOOKED-', '')->trim()->toString();
        $s = str_ireplace([' ', 'h'], ['', ':'], $s);

        if (preg_match('/^(\d{1,2}:\d{2})[-–](\d{1,2}:\d{2})$/', $s, $m)) return [$m[1], $m[2]];
        if (preg_match('/^(\d{4})[-–](\d{4})$/', $s, $m)) {
            return [
                substr($m[1], 0, 2) . ':' . substr($m[1], 2, 2),
                substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2),
            ];
        }
        if (preg_match('/^(\d{1,2}:\d{2})(AM|PM)?$/i', $s, $m)) return [$m[1] . ($m[2] ?? ''), null];
        return [null, null];
    }

    private function detectCurrency(string $text): ?string
    {
        if (Str::contains($text, '€')) return 'EUR';
        if (Str::contains($text, '£')) return 'GBP';
        if (Str::contains($text, '$')) return 'USD';
        return null;
    }

    private function matchOne(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }
    private function matchOneInt(string $text, string $pattern): ?int
    {
        return preg_match($pattern, $text, $m) ? (int) $m[1] : null;
    }
    private function matchOneFloat(string $text, string $pattern): ?float
    {
        if (!preg_match($pattern, $text, $m)) return null;
        $v = str_replace([','], [''], $m[1]);
        return is_numeric($v) ? (float) $v : null;
    }

    private function guessAddressLines(array $blockLines): array
    {
        $startFound = false; $addr = [];
        foreach ($blockLines as $ln) {
            $ln = trim($ln);
            if ($startFound === false && preg_match('/^(Collection|Delivery)\b/i', $ln)) {
                $startFound = true; continue;
            }
            if ($startFound) {
                if ($ln === '' || Str::startsWith(Str::upper($ln), ['COLLECTION','DELIVERY'])) break;
                if (preg_match('/(ROAD|ST|AVE|RUE|DEPOT|LONDON|GB-|FR|[A-Z]{1,2}\d[\w ]+\d[A-Z]{2}|\b\d{5}\b)/i', $ln)) {
                    $addr[] = $ln;
                }
            }
        }
        $full = $addr ? implode(', ', $addr) : null;
        $postal = $this->matchOne($full ?? '', '/\b([A-Z]{1,2}\d[\w ]+\d[A-Z]{2}|\b\d{5}\b)\b/i');
        $city   = $this->matchOne($full ?? '', '/([A-Z][A-Z\-\s]+),\s*\d/i');
        $country = null; $up = Str::upper($full ?? '');
        if (Str::contains($up, ' FR')) $country = 'FR';
        if (Str::contains($up, ' GB')) $country = $country ?: 'GB';
        return compact('full','postal','city','country');
    }

    private function collectNotes(string $blockText): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $blockText)));
        $notes = [];
        foreach ($lines as $ln) {
            if (preg_match('/(BOOKED[^,.\n]*|slot will be provided soon|Sevington|Clearance|PICK UP T1)/i', $ln)) {
                $notes[] = $ln;
            }
        }
        return $notes ? implode('; ', $notes) : null;
    }

    private function extractZieglerTerms(string $text): array
    {
        $u = Str::upper($text); $terms = [];
        if (Str::contains($u, 'BIFA')) $terms[] = 'All business is conducted under BIFA terms.';
        if (Str::contains($u, 'PLEASE QUOTE OUR REFERENCE NUMBER')) $terms[] = 'Invoice must quote Ziegler reference number.';
        if (Str::contains($u, 'SIGNED POD')) $terms[] = 'Invoice must include signed POD/CMR.';
        if (Str::contains($u, 'DELIVERY TO ANY ADDRESS OTHER THAN THE ONE MENTIONED ABOVE IS STRICTLY PROHIBITED'))
            $terms[] = 'Deliver only to the stated address.';
        return $terms;
    }
}
