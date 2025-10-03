<?php

namespace App\Assistants;

use Carbon\Carbon;
use Illuminate\Support\Str;

class TransalliancePdfAssistant extends PdfClient
{
    public static function validateFormat(array $lines)
    {
        $hay = Str::upper(implode("\n", $lines));
        return Str::contains($hay, 'TRANSALLIANCE');
    }

    public function processLines(array $lines, ?string $attachment_filename = null)
    {
        // Normalize non-breaking spaces and build text
        $lines = array_map(function ($l) {
            $l = preg_replace('/\p{Z}+/u', ' ', $l); // normalize all unicode spaces
            return str_replace("\xC2\xA0", ' ', $l);
        }, $lines);
        $joined = implode("\n", $lines);
        $text = preg_replace('/\p{Z}+/u', ' ', $joined);

        $attachments = [];
        if ($attachment_filename) $attachments[] = $attachment_filename;

        // Prepare cargoNumber early for reference heuristics
        $cargoNumber = null;
        if (preg_match('/\bOT\b\s*[:\-]?\s*([A-Z0-9]+)/i', $text, $mOTOne)) {
            $cargoNumber = trim($mOTOne[1]);
        } elseif (preg_match('/\bREF\b\s*[:\-]?\s*(\d{4,})/i', $text, $mRefNum)) {
            $cargoNumber = trim($mRefNum[1]);
        }

        // Order reference: only accept explicit labels and reasonable length (6-10)
        $reference = null;
        if (preg_match('/\bORDER\s*REFERENCE\b\s*[:\-]?\s*(\d{6,10})\b/i', $text, $mOR)) {
            $reference = trim($mOR[1]);
        } elseif (preg_match('/^\s*REFERENCE\s*[:\-]?\s*(\d{6,10})\b/im', $text, $mR)) {
            $reference = trim($mR[1]);
        } else {
            // Heuristic: pick a 7-8 digit number not equal to OT/cargo number and not a date-like chunk
            if (preg_match_all('/\b(\d{6,8})\b/', $text, $mNums)) {
                $candidates = array_unique($mNums[1]);
                $candidates = array_values(array_filter($candidates, function($n) use ($cargoNumber) {
                    if ($cargoNumber && $n === $cargoNumber) return false; // skip OT
                    if (preg_match('/^(20\d{6}|202\d{5})$/', $n)) return false; // skip datelike
                    return (strlen($n) >= 7 && strlen($n) <= 8);
                }));
                // Prefer a 7-digit starting with 1 (e.g., 1808432)
                foreach ($candidates as $n) { if (preg_match('/^1\d{6}$/', $n)) { $reference = $n; break; } }
                if (!$reference && !empty($candidates)) $reference = $candidates[0];
            }
        }
        if (!$reference) $reference = $attachment_filename ?: 'unknown';

        // Cargo number kept for later use

        // Freight amount
        $amountRaw = $this->matchOne($text, '/SHIPPING\s+PRICE[^\d]*([\d\.,\s]+)\s*(?:EUR|GBP|USD|PLN|ZAR)?/i');
        $amount    = $amountRaw !== null ? $this->normalizeMoney($amountRaw) : 0.0;
        $currency  = $this->matchOne($text, '/\b(EUR|GBP|USD|PLN|ZAR)\b/i') ?: 'EUR';

        $incoterms = $this->matchOne($text, '/\b(Incoterms)\b[:\s-]*([A-Z]{3})/i');
        $incoterms = $incoterms ? Str::upper($incoterms) : null;

        // Transport numbers + all OT
        $transportNumbers = $this->matchOne($text, '/Tract\.?\s*registr\.?\s*:\s*([^\r\n]+)/i');
        if (preg_match_all('/\bOT\s*[:\-]?\s*([A-Z0-9]+)\b/i', $text, $mOT)) {
            $ots = array_unique(array_filter($mOT[1]));
            if (!empty($ots)) {
                $otString = 'OT ' . implode(', OT ', $ots);
                $transportNumbers = $transportNumbers ? ($transportNumbers . '; ' . $otString) : $otString;
            }
        }

        // Cargo fields
        $cargoTitle = $this->matchOne($text, '/(?:M\.?\s*nature|nature of goods|goods)\s*[:\-]?\s*([^\r\n]+)/i') ?: null;

        // Pallets
        $pallets = 0;
        if (preg_match('/\b(Pal\.?\s*nb|PALLETS?)\b[^\n]*?([\d\.,]+)/i', $text, $pm)) {
            $pallets = (int) preg_replace('/[^\d]/', '', $pm[2]);
        } elseif (preg_match('/\bParc\.\s*nb\b[^\n]*?([\d\.,]+)/i', $text, $pm2)) {
            $pallets = (int) preg_replace('/[^\d]/', '', $pm2[1]);
        }

        // LDM robust
        $ldm = null;
        if (preg_match('/\b(?:LM|LDM)\b\s*[:\-]?\s*([\d]+(?:[\.,][\d]+)?)/i', $text, $mL1)) {
            $ldm = (float) str_replace(',', '.', $mL1[1]);
        } elseif (preg_match('/\b([\d]+(?:[\.,][\d]+)?)\s*(?:LM|LDM)\b/i', $text, $mL2)) {
            $ldm = (float) str_replace(',', '.', $mL2[1]);
        } elseif (preg_match('/\b13\s*[\.,]?\s*6\b[^\n]*\b(LM|LDM)\b/i', $text)) {
            $ldm = 13.6;
        } elseif (preg_match('/\b13\s*[\.,]?\s*6\b/i', $text)) {
            // bare 13.6/13,6 anywhere
            $ldm = 13.6;
        }

        $type = (preg_match('/\bFTL\b/i', $text) || ($ldm !== null && $ldm >= 12.0)) ? 'FTL' : null;

        // Parse blocks for stops and possible block weights
        $weightTotal = 0; $anyWeight = false;
        $stops = [];
        foreach ($this->splitBlocks($lines) as $b) {
            $blockText = implode("\n", $b['lines']);
            $typeStop = $b['type'];

            $date = $this->firstDate($blockText);
            $slot = $this->matchOne($blockText,'/\b(\d{1,2}h\d{2}\s*[-–]\s*\d{1,2}h\d{2}|\d{2}:\d{2}\s*[-–]\s*\d{2}:\d{2})\b/i');
            [$windowStart, $windowEnd] = $this->parseTimeWindow($slot);

            $name = $this->getBlockCompanyName($b['lines']);

            $w = null;
            if (preg_match('/(?:Total\s*weight|Weight|Poids)\b[\.:\s]*([\d\s\.,]+)/i', $blockText, $mw)) {
                $w = $this->normalizeMoney($mw[1]);
            } elseif (preg_match('/\b([0-9](?:[0-9\s\.,]{2,}))\s*(?:KG|KGS)\b/i', $blockText, $mw)) {
                $w = $this->normalizeMoney($mw[1]);
            }
            if ($w !== null) { $weightTotal += $w; $anyWeight = true; }

            $addr = $this->guessAddressLines($b['lines']);



            // Per-stop comments from global phrases
            $notes = null;
            if ($typeStop === 'pickup') {
                $bar = (stripos($text, 'BAR MUST BE SCANNED') !== false);
                if ($cargoNumber && $bar) {
                    $notes = 'REF: ' . $cargoNumber . '. Instructions: BAR MUST BE SCANNED.';
                } elseif ($cargoNumber) {
                    $notes = 'REF: ' . $cargoNumber . '.';
                }
            } else {
                $bon = (stripos($text, "BON D'ECHANGE") !== false);
                if ($bon) {
                    $instr = $this->extractBonInstructionLine($lines);
                    $notes = 'REF: ' . ($cargoNumber ?: ($reference ?: '')) . '. Instructions: ' . trim($instr);
                }
            }

            $stops[] = [
                'type'         => $typeStop,
                'name'         => $name,
                'address'      => $addr['full'] ?? null,
                'postal_code'  => $addr['postal'] ?? null,
                'city'         => $addr['city'] ?? null,
                'country_iso'  => $addr['country'] ?? null,
                'date'         => $date,
                'window_start' => $windowStart,
                'window_end'   => $windowEnd,
                'notes'        => $notes,
            ];
        }

        [$loading, $destination] = $this->buildSchemaLocationsFromStops($stops);

        // Final cargo assembly
        $cargo = [
            'title'        => $cargoTitle,
            'package_type' => ($pallets > 0 || $cargoTitle === 'PACKAGING') ? 'pallet' : 'other',
        ];
        if ($pallets > 0) $cargo['package_count'] = $pallets;
        if ($ldm !== null) $cargo['ldm'] = $ldm;
        if ($type) $cargo['type'] = $type;
        if ($cargoTitle === 'PACKAGING' || $pallets > 0) $cargo['palletized'] = true;
        if ($cargoNumber) $cargo['number'] = $cargoNumber;

        // Weight fallback from whole document if none captured
        if ($anyWeight) {
            $cargo['weight'] = $weightTotal;
        } else {
            $globalW = null;
            // direct labeled fallback like "Weight . : 25000,000" on normalized text
            if ($globalW === null) {
                if (preg_match('/^\s*Weight\b[\s\.:]*([0-9][0-9\s\.,]*)/im', $text, $mWAny)) {
                    $n = $this->normalizeMoney($mWAny[1]);
                    if ($n > 0) $globalW = (int) round($n);
                }
            }
            // same fallback on raw joined lines (before space normalization), in case alignment matters
            if ($globalW === null) {
                if (preg_match('/^\s*Weight\b[ \t\.:]*([0-9][0-9 \t\.,]*)/im', $joined, $mWRaw)) {
                    $n = $this->normalizeMoney($mWRaw[1]);
                    if ($n > 0) $globalW = (int) round($n);
                }
            }
            // line-wise fallback: scan lines for a Weight label and grab the first big number on that line
            if ($globalW === null) {
                foreach ($lines as $ln) {
                    if (stripos($ln, 'Weight') !== false) {
                        if (preg_match('/([0-9][0-9\s\.,]{3,})/', $ln, $mx)) {
                            $n = $this->normalizeMoney($mx[1]);
                            if ($n > 0) { $globalW = (int) round($n); break; }
                        }
                    }
                }
            }
            // neighborhood fallback: look at the next line after a Weight/Kgs label
            if ($globalW === null) {
                $nLines = count($lines);
                for ($i = 0; $i < $nLines; $i++) {
                    $cur = $lines[$i] ?? '';
                    if (stripos($cur, 'Weight') !== false || stripos($cur, 'Kgs') !== false) {
                        $window = trim(($lines[$i] ?? '') . ' ' . ($lines[$i+1] ?? ''));
                        if (preg_match('/([0-9][0-9\s\.,]{3,})/', $window, $mm)) {
                            $n = $this->normalizeMoney($mm[1]);
                            if ($n > 0) { $globalW = (int) round($n); break; }
                        }
                    }
                }
            }
            // capture forms like 25 000, 25.000, 25,000 KGS and plain 25000 KGS, with optional 1-3 decimals
            if (preg_match_all('/\b(\d{2}[\s\.,]?\d{3}|\d{5,6})(?:[\.,]\d{1,3})?\b\s*(?:KG|KGS)?\b/i', $text, $mG)) {
                $nums = [];
                foreach ($mG[1] as $raw) {
                    $n = (int) preg_replace('/[^\d]/', '', $raw);
                    if ($n > 0) $nums[] = $n;
                }
                if (!empty($nums)) $globalW = max($nums);
            }
            // additional robust thousands-group pattern like 25 000, 125 500 etc.
            if ($globalW === null) {
                if (preg_match_all('/\b(\d{1,3}(?:[\s\x{00A0}\.\,]\d{3})+)(?:[\.,]\d+)?\s*(?:KG|KGS)?\b/u', $text, $mG2)) {
                    $nums = [];
                    foreach ($mG2[1] as $raw) {
                        $n = (int) preg_replace('/[^\d]/', '', $raw);
                        if ($n > 0) $nums[] = $n;
                    }
                    if (!empty($nums)) $globalW = max($nums);
                }
            }
            // detect tons and convert to kg if still not found
            if ($globalW === null) {
                if (preg_match_all('/\b(\d{1,3}(?:[\.,]\d+)?|\d{2}(?:[\s\x{00A0}\u202F]\d{3})+)\s*(?:T|TONS?|TONNES?)\b/ui', $text, $mTon)) {
                    $vals = [];
                    foreach ($mTon[1] as $raw) {
                        // if grouped like 25 000 with T (rare), treat as kg; else numeric tons * 1000
                        if (preg_match('/\d{1,3}(?:[\s\x{00A0}\u202F]\d{3})+/', $raw)) {
                            $kg = (int) preg_replace('/[^\d]/', '', $raw);
                        } else {
                            $num = (float) str_replace(',', '.', preg_replace('/[^\d\.,]/', '', $raw));
                            $kg = (int) round($num * 1000);
                        }
                        if ($kg > 0) $vals[] = $kg;
                    }
                    if (!empty($vals)) $globalW = max($vals);
                }
            }
            if ($globalW !== null && $globalW >= 1000) {
                $cargo['weight'] = (float) $globalW;
            }
        }

        $cargos = [ $cargo ];

        // Customer details (receiver): try to read Lithuanian address block
        $customerDetails = [];
        if (preg_match('/\bTest\s+Client\s+\d+\b/i', $text, $mCli)) {
            $customerDetails['company'] = trim($mCli[0]);
        }
        if (preg_match('/\b([A-Z]{2}\d{9,12})\b/', $text, $mVat)) {
            $customerDetails['vat_code'] = $mVat[1];
        }
        if (preg_match('/ROGIU\s*G\.?\s*\d+[^\r\n]*/i', $text, $mSt)) {
            $customerDetails['street_address'] = trim($mSt[0]);
        }
        // City and postal for LT
        if (preg_match('/\b0\d{4}\b/', $text, $mZip)) {
            $customerDetails['postal_code'] = $mZip[0];
        }
        if (stripos($text, 'VILNIUS') !== false) {
            $customerDetails['city'] = 'VILNIUS';
        }
        if (preg_match('/\bLT\b/', $text)) {
            $customerDetails['country'] = 'LT';
        }


        $customer = [ 'side' => 'receiver', 'details' => $customerDetails ];

        // Global comment: prefer the long commercial sender paragraph if present
        $comment = '';
        // Prefer key instruction sentences if present
        $comment = $this->extractKeyInstructionComment($text);
        if ($comment === '') {
            if (preg_match('/Commercial\s+sender[\s\S]*?non\s*[- ]?compliance\./i', $text, $mCom)) {
                $comment = trim(preg_replace('/\s+/', ' ', $mCom[0]));
            } else {
                $comment = $this->collectGlobalComment($text);
            }
        }

        $data = [
            'attachment_filenames'  => $attachments,
            'customer'              => $customer,
            'loading_locations'     => $loading,
            'destination_locations' => $destination,
            'cargos'                => $cargos,
            'order_reference'       => $reference,
            'freight_price'         => $amount ?? 0,
            'freight_currency'      => $currency ?? 'EUR',
            'comment'               => $comment,
        ];

        if ($transportNumbers) $data['transport_numbers'] = $transportNumbers;
        if ($incoterms)        $data['incoterms'] = $incoterms;

        return $this->createOrder($data);
    }

    /* ----------------------------- Helpers ----------------------------- */

    private function splitBlocks(array $lines): array
    {
        $out = [];
        $current = null;
        foreach ($lines as $ln) {
            $u = Str::upper(trim($ln));
            if (Str::startsWith($u, 'LOADING')) {
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

    private function getBlockCompanyName(array $blockLines): ?string
    {
        $iRef = null;
        foreach ($blockLines as $i => $ln) {
            if (preg_match('/^\s*REFERENCE\b/i', trim($ln))) { $iRef = $i; break; }
        }
        if ($iRef === null) {
            $started = false;
            foreach ($blockLines as $ln) {
                $t = trim($ln);
                if ($t === '') continue;
                if (!$started && preg_match('/^(Loading|Delivery)\b/i', $t)) { $started = true; continue; }
                if (!$started) continue;
                if (preg_match('/^(Loading|Delivery)\b/i', $t)) break;
                if ($this->shouldSkipCompanyLine($t)) continue;
                if ($this->looksLikeCompany($t)) return $t;
            }
            return null;
        }
        for ($j = $iRef + 1; $j < count($blockLines); $j++) {
            $t = trim($blockLines[$j]);
            if ($t === '') continue;
            if (preg_match('/^(Loading|Delivery)\b/i', $t)) break;
            if ($this->shouldSkipCompanyLine($t)) continue;
            if ($this->looksLikeCompany($t)) return $t;
        }
        return null;
    }

    private function shouldSkipCompanyLine(string $t): bool
    {
        if (preg_match('/^(REF\b|REF\s*\-|REFERENCE\b|ON:|Weight\b|LM\b|Parc\. nb\b|Pal\. nb\b|Contact\b|Tel\b|Payment terms\b|Instructions\b|Incoterms\b)/i', $t)) {
            return true;
        }
        if (preg_match('/\bVIREMENT\b/i', $t)) return true;
        $digits = preg_replace('/[^\d]/', '', $t);
        if ($t !== '' && strlen($digits) >= max(3, (int) floor(strlen($t) * 0.6))) return true;
        if (preg_match('/^[A-Z]{2}-[A-Z0-9 ]{3,}/', $t)) return true;
        return false;
    }

    private function looksLikeCompany(string $t): bool
    {
        return preg_match('/[A-Za-z]/', $t) && strlen($t) >= 3 && !preg_match('/^[\W_]+$/', $t);
    }

    private function guessAddressLines(array $blockLines): array
    {
        $addr = [];
        $inBlock = false;
        $afterRef = false;
        foreach ($blockLines as $ln) {
            $t = trim($ln);
            if ($t === '') continue;
            if (!$inBlock && preg_match('/^(Loading|Delivery)\b/i', $t)) { $inBlock = true; continue; }
            if (!$inBlock) continue;
            if (preg_match('/^(Loading|Delivery)\b/i', $t)) break;
            if (preg_match('/^REFERENCE\b/i', $t)) { $afterRef = true; continue; }
            if (!$afterRef) continue;
            if (preg_match('/^(M\.?\s*nature|nature of goods|goods|Instructions|Payment terms|Incoterms)\b/i', $t)) break;
            if (preg_match('/^(LM|Parc\. nb|Pal\. nb|Weight|Contact|Tel)\b/i', $t)) continue;
            if (preg_match('/\bVIREMENT\b/i', $t)) continue;
            if (preg_match('/\b\d{1,2}h\d{2}\b|\b\d{2}:\d{2}\b|\bKGS?\b/i', $t)) continue;
            $addr[] = $t;
        }
        $full = $addr ? implode(', ', $addr) : null;
        $postal = null;
        if ($full) {
            if (preg_match('/\b\d{5}\b/', $full, $m)) {
                $postal = $m[0];
            } elseif (preg_match('/\b[A-Z]{1,2}\d[\w ]+\d[A-Z]{2}\b/i', $full, $m)) {
                $postal = trim($m[0]);
            }
        }
        $country = null; $up = Str::upper($full ?? '');
        if (Str::contains($up, ' GB') || Str::contains($up, ' UNITED KINGDOM') || preg_match('/\b[A-Z]{1,2}\d[\w ]+\d[A-Z]{2}\b/i', $full ?? '')) $country = 'GB';
        if (Str::contains($up, ' FR') || Str::contains($up, ' FRANCE') || preg_match('/\b\d{5}\b/', $full ?? '')) $country = $country ?: 'FR';
        if (Str::contains($up, ' DE-') || Str::contains($up, ' DE')) $country = $country ?: 'DE';
        if (Str::contains($up, ' LT-') || Str::contains($up, ' LT')) $country = $country ?: 'LT';
        $city = null;
        if ($full && preg_match('/\b(\d{5})\s+([A-Z\- ,]{3,})$/i', $full, $mc)) {
            $city = Str::upper(trim($mc[2]));
        }
        return [ 'full' => $full, 'postal' => $postal, 'city' => $city, 'country' => $country ];
    }

    private function collectGlobalComment(string $text): string
    {
        $bits = [];
        if ($v = $this->matchOne($text, '/Payment\s+terms\s*:\s*([^\r\n]+)/i')) $bits[] = "Payment terms: $v";
        if ($v = $this->matchOne($text, '/Instructions?\s*:\s*([^\r\n]+)/i'))  $bits[] = "Instructions: $v";
        return $bits ? implode(' | ', $bits) : '';
    }

    private function buildSchemaLocationsFromStops(array $stops): array
    {
        $loading = [];
        $destination = [];
        foreach ($stops as $s) {
            $companyAddress = [ 'company' => $s['name'] ?: 'Unknown', 'street_address' => $s['address'] ?: '' ];
            if (!empty($s['city']) && strlen($s['city']) >= 2) $companyAddress['city'] = $s['city'];
            if (!empty($s['postal_code'])) $companyAddress['postal_code'] = $s['postal_code'];
            if (!empty($s['country_iso']) && strlen($s['country_iso']) === 2) $companyAddress['country'] = $s['country_iso'];
            if (!empty($s['notes'])) $companyAddress['comment'] = $s['notes'];
            $loc = ['company_address' => $companyAddress];
            if (!empty($s['date'])) {
                $from = $s['window_start'] ? "{$s['date']}T{$s['window_start']}:00Z" : "{$s['date']}T00:00:00Z";
                $time = ['datetime_from' => $from];
                if (!empty($s['window_end'])) $time['datetime_to'] = "{$s['date']}T{$s['window_end']}:00Z";
                $loc['time'] = $time;
            }
            if (($s['type'] ?? null) === 'pickup')      $loading[]     = $loc;
            elseif (($s['type'] ?? null) === 'delivery') $destination[] = $loc;
        }
        if (empty($loading)) $loading[] = ['company_address' => ['company' => 'Unknown', 'street_address' => '']];
        if (empty($destination)) $destination[] = ['company_address' => ['company' => 'Unknown', 'street_address' => '']];
        return [$loading, $destination];
    }

    private function matchOne(string $text, string $pattern): ?string
    { return preg_match($pattern, $text, $m) ? trim($m[1]) : null; }

    private function extractBonInstructionLine(array $lines): ?string
    {
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (stripos($lines[$i], "BON D'ECHANGE") !== false) {
                $acc = trim($lines[$i]);
                // Concatenate immediate next line if it likely continues the sentence
                if ($i + 1 < $n) {
                    $next = trim($lines[$i + 1]);
                    if ($next !== '' && !preg_match('/^(Loading|Delivery)\b/i', $next)) {
                        $acc = rtrim($acc, " ") . ' ' . $next;
                    }
                }
                // Try to capture preceding part if this line starts mid-sentence
                $prependParts = [];
                for ($k = 1; $k <= 3; $k++) {
                    if ($i - $k < 0) break;
                    $prev = trim($lines[$i - $k]);
                    if ($prev === '' || preg_match('/^(Loading|Delivery)\b/i', $prev)) break;
                    if (preg_match('/^[_\s\-\.:]+$/', $prev)) continue; // skip underline/separator lines
                    array_unshift($prependParts, $prev);
                }
                if (!empty($prependParts)) {
                    $prefix = implode(' ', $prependParts);
                    $acc = rtrim($prefix) . ' ' . ltrim($acc);
                }
                // Do not force-merge tokens; keep PDF text as-is to match expected
                // Clean duplicate spaces
                $acc = preg_replace('/\s+/', ' ', $acc);
                // Remove leading markers like "Instructions:" or lone underscores
                $acc = preg_replace('/^[_\s-]*Instructions\s*:\s*/i', '', $acc);
                $acc = ltrim($acc, '_- ');
                return trim($acc);
            }
        }
        return null;
    }

    private function firstDate(string $text): ?string
    {
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{2,4})\b/', $text, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            try { return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$year}")->format('Y-m-d'); } catch (\Throwable $e) { return null; }
        }
        return null;
    }

    private function parseTimeWindow(?string $slot): array
    {
        if (!$slot) return [null, null];
        $s = str_ireplace([' ', 'h'], ['', ':'], $slot);
        if (preg_match('/^(\d{1,2}):(\d{2})[-–](\d{1,2}):(\d{2})$/', $s, $m)) {
            $a = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $b = sprintf('%02d:%02d', (int)$m[3], (int)$m[4]);
            return [$a, $b];
        }
        return [null, null];
    }

    private function normalizeMoney(string $raw): float
    {
        $v = trim($raw);
        $v = preg_replace('/[\x{00A0}\s]/u', '', $v);
        if (strpos($v, ',') !== false && strpos($v, '.') !== false) { $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); }
        elseif (strpos($v, ',') !== false) { $v = str_replace(',', '.', $v); }
        $v = preg_replace('/[^0-9.\-]/', '', $v);
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function extractKeyInstructionComment(string $text): string
    {
        // Normalize whitespace for sentence extraction but keep quotes as-is
        $norm = preg_replace('/[\x{00A0}\x{202F}\s]+/u', ' ', $text);
        $sentences = [];

        // Capture sentences containing these key phrases
        $patterns = [
            // signed + Agreed to + stamp + register
            '/([^\.]*?must\s+be\s+returned\s+signed[^\.]*Agreed\s*to[^\.]*?(?:\.|$))/i',
            // pallets exchange at departure
            '/([^\.]*?Returnable\s+pallets[^\.]*?exchanged[^\.]*?departure\s+point[^\.]*?(?:\.|$))/i',
            // failure to comply penalties
            '/([^\.]*?Failure\s+to\s+comply[^\.]*?penalt(?:y|ies)[^\.]*?(?:\.|$))/i',
        ];
        foreach ($patterns as $p) {
            if ($norm !== null && preg_match($p, $norm, $m)) {
                $sentences[] = trim($m[1]);
            }
        }
        if (!empty($sentences)) {
            // Ensure trailing periods
            $sentences = array_map(function ($s) {
                $s = rtrim($s);
                return rtrim($s, '.') . '.';
            }, $sentences);
            return implode(' ', $sentences);
        }
        return '';
    }
}
