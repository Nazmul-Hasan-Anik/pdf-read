<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class ZieglerPdfAssistant extends PdfClient
{
    /**
     * Validate if this PDF matches Ziegler format.
     */
    public static function validateFormat(array $lines)
    {
        $hay = Str::upper(implode("\n", $lines));
        return Str::contains($hay, 'ZIEGLER UK LTD')
            || Str::contains($hay, 'ZIEGLER REF')
            || Str::contains($hay, 'PLEASE FIND BELOW THE BOOKING');
    }

    /**
     * Process the lines of a Ziegler PDF and extract order data.
     * Returns array suitable for createOrder().
     */
    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        $text = implode("\n", $lines);

        // ---------- Attachments (optional but useful)
        $attachments = [];
        if ($attachment_filename) $attachments[] = $attachment_filename;

        // ---------- Order ----------
        $reference = $this->matchOne($text, '/Ziegler\s*Ref\s*([^\r\n]+?)(?:\s+Rate\b|$)/i');
        if ($reference) $reference = trim(preg_replace('/\s+/', ' ', $reference));

        $orderReference = $reference ?: ($attachment_filename ?: 'unknown');

        $rateRaw  = $this->matchOne($text, '/\bRate\b\s*[€£$]?\s*([0-9\.,]+)/i');
        $amount   = $rateRaw !== null ? (float) str_replace(',', '', $rateRaw) : null;
        $currency = $this->detectCurrency($text) ?? 'EUR';

        // ---------- Optional: Incoterms & Transport numbers if present
        $incoterms = $this->matchOne($text, '/\b(Incoterms?)\b[:\s-]*([A-Z]{3})/i');
        $incoterms = $incoterms ? strtoupper($incoterms) : null;
        $transportNumbers = $this->matchOne($text, '/\b(?:Truck|Tract|Trailer)\s*[:\-]?\s*([^\r\n]+)/i');

        $stops = [];
        foreach ($this->splitBlocks($lines) as $b) {
            $blockText = implode("\n", $b['lines']);

            $name     = $this->matchOne($blockText, '/^(?:Collection|Delivery)\s+([^\r\n]+)/i') ?: null;
            $localRef = $this->matchOne($blockText, '/\bREF[:\s-]*([A-Z0-9\-\/]+)/i') ?: null;

            $date = $this->firstDate($blockText);
            $slot = $this->matchOne(
                $blockText,
                '/\b(BOOKED-?\s*\d{1,2}:\d{2}\s*(?:AM|PM)?|\d{1,2}h\d{2}\s*[-–]\s*\d{1,2}h\d{2}|\d{2}:\d{2}\s*[-–]\s*\d{2}:\d{2}|\d{4}\s*[-–]\s*\d{4})\b/i'
            );
            [$windowStart, $windowEnd] = $this->parseTimeWindow($slot);

            $pallets = $this->matchOneInt($blockText, '/(\d+)\s*PALLETS?/i');
            $weight  = $this->matchOneFloat($blockText, '/(\d{2,3}[.,]?\d{0,3})\s*(?:KG|KGS)\b/i');

            $addrGuess = $this->detectAddressLines($b['lines']);
            $notes     = $this->collectNotes($blockText);

            $stops[] = [
                'type'         => $b['type'],
                'name'         => $name,
                'local_ref'    => $localRef,
                'address'      => $addrGuess['full'] ?? null,
                'postal_code'  => $addrGuess['postal'] ?? null,
                'city'         => $addrGuess['city'] ?? null,
                'country_iso'  => $addrGuess['country'] ?? null,
                'date'         => $date,
                'window_start' => $windowStart,
                'window_end'   => $windowEnd,
                'pallets'      => $pallets,
                'weight_kg'    => $weight,
                'notes'        => $notes,
            ];
        }

        // ---------- Map to schema-required location arrays ----------
        [$loading, $destination] = $this->buildSchemaLocationsFromStops($stops);

        // ---------- Cargos ----------
        $descFromPdf = $this->matchOne($text, '/(?:nature of goods|goods|m\.?\s*nature)\s*[:\-]?\s*([^\r\n]+)/i');
        $totalPallets = 0; $totalWeight = 0.0; $anyWeight = false;
        foreach ($stops as $s) {
            if (!empty($s['pallets']))    $totalPallets += (int)$s['pallets'];
            if (!empty($s['weight_kg'])) { $totalWeight += (float)$s['weight_kg']; $anyWeight = true; }
        }
        $cargos = [[
            'title'         => $descFromPdf ?: 'General cargo',
            'package_count' => $totalPallets ?: 0,
            'package_type'  => $totalPallets ? 'pallet' : 'other',
            'weight'        => $anyWeight ? $totalWeight : 0,
        ]];


        // ---------- Customer ----------
        $customer = [
            'side'    => 'sender',
            'details' => [
                'company'       => 'Ziegler UK Ltd',
            ],
        ];

        // ---------- Terms ----------
        $terms = $this->extractZieglerTerms($text);

        // ---------- Compose final data (ALL required present) ----------
        $data = [
            'attachment_filenames'  => $attachments,
            'customer'              => $customer,
            'loading_locations'     => $loading,
            'destination_locations' => $destination,
            'cargos'                => $cargos,
            'order_reference'       => $orderReference,
            'freight_price'         => $amount ?? 0 ,
            'freight_currency'      => $currency ?? 'EUR',
            'comment'               => $terms ? implode(' ', $terms) : ''
        ];

        if ($incoterms)        $data['incoterms'] = $incoterms;
        if ($transportNumbers) $data['transport_numbers'] = $transportNumbers;

        return $this->createOrder($data);
    }

    /**
     * Build schema-compliant locations from parsed stops.
     */
    private function buildSchemaLocationsFromStops(array $stops): array
    {
        $loading = [];
        $destination = [];

        foreach ($stops as $s) {
            $companyAddress = [
                'company'        => $s['name'] ?: 'Unknown',
                'street_address' => $s['address'] ?? '',
            ];

            if (!empty($s['city']) && strlen($s['city']) >= 2) {
                $companyAddress['city'] = $s['city'];
            }

            if (!empty($s['postal_code'])) {
                $companyAddress['postal_code'] = $s['postal_code'];
            }

            if (!empty($s['country_iso']) && strlen($s['country_iso']) === 2) {
                $companyAddress['country'] = $s['country_iso'];
            }

            if (!empty($s['notes'])) {
                $companyAddress['comment'] = $s['notes'];
            }

            $time = null;
            if (!empty($s['date'])) {
                $from = $s['window_start'] ? "{$s['date']}T{$s['window_start']}:00" : "{$s['date']}T00:00:00";
                $time = ['datetime_from' => $from];
                if (!empty($s['window_end'])) {
                    $time['datetime_to'] = "{$s['date']}T{$s['window_end']}:00";
                }
            }

            $loc = ['company_address' => $companyAddress];
            if ($time) $loc['time'] = $time;

            if (($s['type'] ?? null) === 'pickup') {
                $loading[] = $loc;
            } elseif (($s['type'] ?? null) === 'delivery') {
                $destination[] = $loc;
            }
        }

        if (empty($loading)) {
            $loading[] = ['company_address' => ['company' => 'Unknown', 'street_address' => '']];
        }
        if (empty($destination)) {
            $destination[] = ['company_address' => ['company' => 'Unknown', 'street_address' => '']];
        }

        return [$loading, $destination];
    }


    /**
     * Split lines into blocks starting with "Collection" or "Delivery".
     */
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

    /**
     * Find the first date in d/m/Y format in the text.
     */
    private function firstDate(string $text): ?string
    {
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $text, $m)) {
            try {
                return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}")->format('Y-m-d');
            } catch (\Throwable $e) { return null; }
        }
        return null;
    }

    /**
     * Parse time window strings like "BOOKED-14:00-16:00" or "14h00-16h00".
     * Returns [start, end] where either may be null if not found.
     */
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

    /**
     * Detect currency symbol in text and return ISO code.
     */
    private function detectCurrency(string $text): ?string
    {
        if (Str::contains($text, '€')) return 'EUR';
        if (Str::contains($text, '£')) return 'GBP';
        if (Str::contains($text, '$')) return 'USD';
        return null;
    }

    /**
     * Regex match helper to get first capturing group or null.
     */
    private function matchOne(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    /**
     * Regex match helper to get first capturing group as int or null.
     */
    private function matchOneInt(string $text, string $pattern): ?int
    {
        return preg_match($pattern, $text, $m) ? (int) $m[1] : null;
    }

    /**
     * Regex match helper to get first capturing group as float or null.
     */
    private function matchOneFloat(string $text, string $pattern): ?float
    {
        if (!preg_match($pattern, $text, $m)) return null;
        $v = str_replace([','], [''], $m[1]);
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Detect address lines, postal code, city, country from block lines.
     */
    private function detectAddressLines(array $blockLines): array
    {
        $addrLines = [];
        $started = false;

        foreach ($blockLines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;

            // Start after "Collection" or "Delivery"
            if (!$started && preg_match('/^(Collection|Delivery)\b/i', $ln)) {
                $started = true;
                continue;
            }

            if ($started) {
                // Stop if we hit another header
                if (preg_match('/^(Collection|Delivery)\b/i', $ln)) break;

                $addrLines[] = $ln;
            }
        }

        $full = $addrLines ? implode(', ', $addrLines) : null;

        // --- Extract components ---
        $postal = null;
        $city   = null;
        $country = null;

        // Postal: UK codes (AB12 3CD) or 5-digit zip
        if ($full) {
            if (preg_match('/\b([A-Z]{1,2}\d[\w ]+\d[A-Z]{2})\b/i', $full, $m)) {
                $postal = $m[1];
            } elseif (preg_match('/\b\d{5}\b/', $full, $m)) {
                $postal = $m[0];
            }
        }

        // City: look for last word before postal
        if ($full && $postal) {
            $parts = explode(',', $full);
            foreach ($parts as $p) {
                if (stripos($p, $postal) !== false) {
                    $before = trim(prev($parts));
                    if ($before && strlen($before) >= 2) {
                        $city = $before;
                    }
                    break;
                }
            }
        }

        // Country: try explicit ISO codes or keywords
        if ($full) {
            $up = Str::upper($full);
            if (preg_match('/\bFRANCE\b/i', $full) || Str::contains($up, ' FR')) {
                $country = 'FR';
            } elseif (preg_match('/\bUNITED KINGDOM\b/i', $full) || Str::contains($up, ' GB')) {
                $country = 'GB';
            } elseif (preg_match('/\bGERMANY\b/i', $full) || Str::contains($up, ' DE')) {
                $country = 'DE';
            }
        }

        return [
            'full'       => $full,
            'postal'     => $postal,
            'city'       => $city,
            'country'    => $country,
        ];
    }

    /**
     * Collect special notes from block text.
     */
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

    /**
     * Extract standard Ziegler terms from the text.
     */
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
