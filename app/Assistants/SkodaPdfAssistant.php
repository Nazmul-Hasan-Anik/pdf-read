<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SkodaPdfAssistant extends PdfClient
{
    public static function validateFormat (array $lines) {
        return Str::startsWith($lines[0], 'DATUM: ')
            && $lines[2] == "NÁLOŽNÍ LIST / VERLADESCHEIN / LOADING LIST"
            && $lines[4] == "ODBĚRATEL / ABNEHMER / CUSTOMER NO."
            && Str::startsWith($lines[19], "Š k o d a");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        if (!static::validateFormat($lines)) {
            throw new \Exception("Invalid Skoda PDF");
        }

        $customer_number = $lines[5];

        $customer = [
            'side' => 'sender',
            'details' => [
                'company' => 'Škoda Auto, a.s.',
                'street_address' => 'tř. Václava Klementa 869',
                'city' => 'Mladá Boleslav II',
                'postal_code' => '293 01',
                'vat_code' => 'CZ00177041',
                'company_code' => '643408312',
            ],
        ];

        preg_match('/DATUM: ([0-9\.]+)/', $lines[0], $date_match);
        $loading_date = Carbon::createFromFormat('d.m.Y', $date_match[1])->addDays(1)->setTime(0,0,0,0)->toIsoString();

        $loading_locations = [[
            'company_address' => $customer['details'],
            'time' => [
                'datetime_from' => $loading_date,
            ],
        ]];

        $destination_locations = [[
            'company_address' => $this->processCompanyAddress($lines[7], $lines[9], $lines[10])
        ]];

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $order_reference = $lines[17];

        $cargos = $this->extractCargos($lines);

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'customer_number',
        );

        $this->createOrder($data);
    }

    public function processCompanyAddress (string $line1, string $line2, string $line3) {
        preg_match('/^(.*?),?\s*(([A-Z]{2})?[- ]*[0-9-]{4,}?)$/u', $line2, $street_post);
        if (!$street_post) {
            throw new \Exception("Invalid Skoda PDF: wrong company address [{$line1}], [{$line2}], [{$line3}]");
        }

        $street = $street_post[1];
        $postal_code = $street_post[2];
        
        preg_match('/^(.*?)\s+(\w+)$/u', $line3, $city_country);
        if (!$city_country) {
            throw new \Exception("Invalid Skoda PDF: wrong company address [{$line1}], [{$line2}], [{$line3}]");
        }
        $city = $city_country[1];
        $country_name = $city_country[2];

        $country = GeonamesCountry::getIso($country_name);

        return [
            'company' => $line1,
            'street_address' => $street,
            'city' => $city,
            'country' => $country,
            'postal_code' => $postal_code,
        ];
    }

    public function extractCargos (array $lines) : array {
        $divider_regex = '/^_+$/';
        $number_title_regex = '(\w+?)\s+(.*?)';
        $dims_regex = '([0-9,\.]+)[\sx]+([0-9,\.]+)[\sx]+([0-9,\.]+)';

        $start_index = array_find_key($lines, fn($l) => preg_match($divider_regex, $l));
        $end_index = array_find_key($lines, fn($l, $i) => $i > $start_index && preg_match($divider_regex, $l));

        if (!$start_index
            || $lines[$start_index - 1] !== "NETTO"
        ) {
            throw new \Exception("Invalid Skoda PDF: wrong cargo formatting");
        }

        $cargos = [];

        $i = $start_index + 1;
        while ($i < $end_index) {
            $add_lines = 0;
            
            $cargo = [];
            if (preg_match("/^({$number_title_regex})\s+({$dims_regex})$/u", $lines[$i], $joined)) {
                $number_title_line = $joined[1];
                $dims_line = $joined[4];
                $add_lines -= 1;
            } else {
                $number_title_line = $lines[$i];
                $dims_line = $lines[$i + 1];
                
            }

            preg_match("/^{$number_title_regex}$/u", $number_title_line, $number_title);
            if ($number_title) {
                $cargo['number'] = $number_title[1];
                $cargo['title']  = $number_title[2];
            }

            preg_match("/^{$dims_regex}$/u", $dims_line, $dims);
            if ($dims) {
                $cargo['pkg_width']  = uncomma($dims[1]) / 100;
                $cargo['pkg_length'] = uncomma($dims[2]) / 100;
                $cargo['pkg_height'] = uncomma($dims[3]) / 100;
            }

            $is_mat = $lines[$i + 2 + $add_lines] == 'X';
            $add_lines += $is_mat ? 1 : 0;

            $cargo['weight'] = uncomma($lines[$i + 2 + $add_lines]);

            $cargo['package_count'] = 1;
            $cargo['package_type'] = "other";

            $cargos[] = $cargo;

            $i += 4 + $add_lines;
        }

        return $cargos;
    }
}
