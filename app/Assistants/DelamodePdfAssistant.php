<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class DelamodePdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "Pcs" => "OTHER",
    ];

    const DIMS_PATTERN = '/([0-9,\.]+)\s*x\s*([0-9,\.]+)\s*x\s*([0-9,\.]+)/';

    public static function validateFormat (array $lines) {
        return Str::startsWith($lines[0], "Pervežimo užsakymas Nr.")
            && $lines[2] === "Vežėjas:"
            && $lines[3] === "Pastabos:"
            && array_find_key($lines, fn($l) => $l === "Delamode Baltics, UAB");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        $order_reference = str_replace("Pervežimo užsakymas Nr. ", "", $lines[0]);

        $freight_li = array_find_key($lines, fn($l) => $l === "Sutarta kaina be PVM:");
        $freight = $lines[$freight_li + 2];
        list($freight_price, $freight_currency) = explode(' ', $freight);
        $freight_price = uncomma($freight_price);

        $pay_term_li = array_find_key($lines, fn($l) => $l === "Apmokėjimo terminas:");
        $cargo_block = array_slice($lines, $freight_li + 4, $pay_term_li - 4 - $freight_li);

        list($cargos, $loading_locations, $destination_locations) = $this->extractLoads($cargo_block);

        $transport_li = array_find_key($lines, fn($l) => $l === "Priekaba: (none)");
        $transport_numbers = $lines[$transport_li + 2];

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'Delamode Baltics, UAB',
                'street_address' => 'Naugarduko g. 98',
                'postal_code' => '03160',
                'city' => 'Vilnius',
                'country' => 'LT',
                'company_code' => '300614485',
                'vat_code' => 'LT100002783011',
            ],
        ];

        $attachment_filenames = [mb_strtolower($attachment_filename ?? '')];

        $data = compact(
            'customer',
            'loading_locations',
            'destination_locations',
            'attachment_filenames',
            'cargos',
            'order_reference',
            'transport_numbers',
            'freight_price',
            'freight_currency',
        );

        $this->createOrder($data);
    }

    public function extractLoads(array $lines) {
        $cargos = [];
        $loading_locations = [];
        $destination_locations = [];

        $headings_li = array_keys(
            array_filter($lines, fn($l) => Str::startsWith($l, "Krovinio ID:"))
        );
        foreach ($headings_li as $i => $start) {
            $end = isset($headings_li[$i+1])
                ? $headings_li[$i+1]
                : count($lines) - 1;
            $part_block = array_slice($lines, $start, $end - $start);

            $cargos[$i] = $this->extractCargo($part_block);
            
            $origin = $this->extractLocation(
                $part_block,
                "Pasikrovimo adresas: ",
                "Pakrovimo data: "
            );
            $origin['company_address']['subcargo_indices'] = [$i];
            $loading_locations = $this->appendLocation($loading_locations, $origin);
            
            $destination = $this->extractLocation(
                $part_block,
                "Pristatymo adresas: ",
                "Iškrovimo data: "
            );
            $destination['company_address']['subcargo_indices'] = [$i];
            $destination_locations = $this->appendLocation($destination_locations, $destination);
        }

        return [$cargos, $loading_locations, $destination_locations];
    }

    public function appendLocation(array $locations, array $location) {
        $fields = ['company', 'street_address', 'postal_code', 'city', 'country'];
        $_location = Arr::only($location['company_address'], $fields);

        $i = array_find_key(
            $locations,
            fn($l) => Arr::only($l['company_address'], $fields) == $_location
        );
        
        if (isset($i) && $i !== false) {
            $locations[$i]['company_address']['subcargo_indices'] = array_merge(
                $locations[$i]['company_address']['subcargo_indices'] ?? [],
                $location['company_address']['subcargo_indices']
            );
        } else {
            $locations[] = $location;
        }

        return $locations;
    }

    public function extractCargo(array $lines) {
        $cargo = [];

        $id_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Krovinio ID: "));
        if ($id_li !== false) {
            $cargo['number'] = str_replace("Krovinio ID: ", "", $lines[$id_li]);
        }

        $title_parts = [];
        $load_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Pakrovimo nr.: "));
        if ($load_li) {
            $title_parts[] = str_replace("Pakrovimo nr.: ", "", $lines[$load_li]);
        }

        $unload_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Iškrovimo nr.: "));
        if ($unload_li) {
            $title_parts[] = str_replace("Iškrovimo nr.: ", "", $lines[$unload_li]);
        }
        $cargo['title'] = join('; ', array_filter($title_parts));

        $count_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Kiekis: "));
        if ($count_li) {
            $cargo['package_count'] = (int) str_replace("Kiekis: ", "", $lines[$count_li]);
        }

        $type_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Pakavimo vienetai: "));
        if ($type_li) {
            $cargo['package_type'] = $this->mapPackageType(
                str_replace("Pakavimo vienetai: ", "", $lines[$type_li])
            );
        }

        $weight_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Svoris: "));
        if ($weight_li) {
            $cargo['weight'] = uncomma(explode(' ', $lines[$weight_li])[1]);
        }

        $volume_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Tūris: "));
        if ($volume_li) {
            $cargo['volume'] = uncomma(explode(' ', $lines[$volume_li])[1]);
        }

        $dims_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Išmatavimai: "));
        if ($dims_li) {
            preg_match(static::DIMS_PATTERN, $lines[$dims_li], $matches);
            if ($matches) {
                $cargo['pkg_width']  = uncomma($matches[1]) / 100;
                $cargo['pkg_length'] = uncomma($matches[2]) / 100;
                $cargo['pkg_height'] = uncomma($matches[3]) / 100;
            }
        }

        return $cargo;
    }

    public function extractLocation(array $lines, string $address_prefix, string $date_prefix) {
        $address_li = array_find_key($lines, fn($l) => Str::startsWith($l, $address_prefix));
        $address_parts = [];
        while($lines[$address_li]) {
            $address_parts[] = str_replace($address_prefix, "", $lines[$address_li]);
            $address_li++;
        }
        $address_line = join(' ', $address_parts);

        $address = $this->extractAddress($address_line);

        $date_li = array_find_key($lines, fn($l) => Str::startsWith($l, $date_prefix));
        $date_str = str_replace($date_prefix, "", $lines[$date_li]);
        $datetime = $this->extractDatetime($date_str, $address['time'] ?? '');

        return [
            'company_address' => $address,
            'time' => $datetime,
        ];
    }

    public function extractAddress(string $address_line) {
        $address = [];
        $pattern = '/^((.+?),+ )?((.+?)?,+ )?(([A-Z]{1,2}[ \-]*)?([0-9 ]{4,})) ([^,]+?),+ (.+?)(,+ (.+?))?$/ui';
        preg_match($pattern, $address_line, $matches);
        if ($matches) {
            $address['company'] = $matches[2] ?? '';
            $address['title'] = $matches[2] ?? '';
            $address['street_address'] = $matches[4] ?? '';
            $address['postal_code'] = $matches[7];
            $address['city'] = $matches[8];
            $country_name = $matches[9];
            $address['country'] = GeonamesCountry::getIso($country_name);
            $address['time'] = $matches[11] ?? null;
        }
        return $address;
    }

    public function extractDatetime(string $date, string $time) {
        $time_pattern = '/([0-9]{1,2}:[0-9]{2})[^0-9]*([0-9]{1,2}:[0-9]{2})?/';

        $date_from = $date_to = $date;

        $date_has_time = preg_match($time_pattern, $date);

        if (!$date_has_time && $time) {
            preg_match($time_pattern, $time, $matches);
            if ($matches[1] ?? null) {
                $date_from .= " " . $matches[1];
            }
            if ($matches[2] ?? null) {
                $date_to .= " " . $matches[2];
            } else {
                $date_to = $date_from;
            }
        }

        if ($date_to == $date_from) {
            $date_to = null;
        }

        $output = [];

        if (isset($date_from)) {
            $output['datetime_from'] = Carbon::parse($date_from)->toIsoString();
        }

        if (isset($date_to)) {
            $output['datetime_to'] = Carbon::parse($date_to)->toIsoString();
        }

        return $output;
    }

    public function mapPackageType(string $type) {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "OTHER";
        return trans("package_type.{$package_type}");
    }
}
