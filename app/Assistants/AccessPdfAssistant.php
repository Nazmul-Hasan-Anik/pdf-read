<?php

namespace App\Assistants;

use App\GeonamesCountry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AccessPdfAssistant extends PdfClient
{
    const PACKAGE_TYPE_MAP = [
        "EW-Paletten" => "PALLET_OTHER",
        "Ladung" => "CARTON",
        "Stück" => "OTHER",
    ];

    public static function validateFormat (array $lines) {
        return $lines[0] == "Access Logistic GmbH, Amerling 130, A-6233 Kramsach"
            && $lines[2] == "To:"
            && Str::startsWith($lines[4], "Contactperson: ");
    }

    public function processLines (array $lines, ?string $attachment_filename = null) {
        $tour_li = array_find_key($lines, fn($l) => $l == "Tournumber:");
        $order_reference = trim($lines[$tour_li + 2], '* ');

        $truck_li = array_find_key($lines, fn($l) => $l == "Truck, trailer:");
        $truck_number = $lines[$truck_li + 2];

        $vehicle_li = array_find_key($lines, fn($l) => $l == "Vehicle type:");
        if ($truck_li && $vehicle_li) {
            $trailer_li = array_find_key($lines, fn($l, $i) => $i>$truck_li && $i<$vehicle_li && preg_match('/^[A-Z]{2}[0-9]{3}( |$)/', $l));
            $trailer_number = explode(' ', $lines[$trailer_li], 2)[0] ?? null;
        }

        $transport_numbers = join(' / ', array_filter([$truck_number, $trailer_number ?? null]));

        $freight_li = array_find_key($lines, fn($l) => $l == "Freight rate in €:");
        $freight_price = $lines[$freight_li + 2];
        $freight_price = preg_replace('/[^0-9,\.]/', '', $freight_price);
        $freight_price = uncomma($freight_price);
        $freight_currency = 'EUR';

        $loading_li = array_find_key($lines, fn($l) => $l == "Loading sequence:");
        $unloading_li = array_find_key($lines, fn($l) => $l == "Unloading sequence:");
        $regards_li = array_find_key($lines, fn($l) => $l == "Best regards");

        $loading_locations = $this->extractLocations(
            array_slice($lines, $loading_li + 1, $unloading_li - 1 - $loading_li)
        );

        $destination_locations = $this->extractLocations(
            array_slice($lines, $unloading_li + 1, $regards_li - 1 - $unloading_li)
        );

        $contact_li = array_find_key($lines, fn($l) => Str::startsWith($l, 'Contactperson: '));
        $contact = explode(': ', $lines[$contact_li], 2)[1];

        $customer = [
            'side' => 'none',
            'details' => [
                'company' => 'Access Logistic GmbH',
                'street_address' => 'Amerling 130',
                'city' => 'Kramsach',
                'postal_code' => '6233',
                'country' => 'AT',
                'vat_code' => 'ATU74076812',
                'contact_person' => $contact,
            ],
        ];

        $cargos = $this->extractCargos($lines);

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

    public function extractLocations(array $lines) {
        $index = 0;
        $location_size = 6;
        $output = [];
        while ($index < count($lines)) {
            $location_lines = array_slice($lines, $index, $location_size);
            $output[] = $this->extractLocation($location_lines);
            $index += $location_size;
        }
        return $output;
    }

    public function extractLocation(array $lines) {
        $datetime = $lines[2];
        $location = $lines[4];

        return [
            'company_address' => $this->parseCompanyAddress($location),
            'time' => $this->parseDateTime($datetime),
        ];
    }

    public function parseDatetime(string $datetime) {
        preg_match('/^([0-9\.]+) ?([0-9:]+)?-?([0-9:]+)?$/', $datetime, $matches);
        if ($matches) {
            $date_start = $matches[1];
            if ($matches[2] ?? null) {
                $date_start .= " " . $matches[2];
            }
            $date_start = Carbon::parse($date_start)->toIsoString();

            $date_end = $matches[1];
            if ($matches[3] ?? null) {
                $date_end .= " " . $matches[3];
            }
            $date_end = Carbon::parse($date_end)->toIsoString();
        }

        $output = [
            'datetime_from' => $date_start ?? null,
            'datetime_to' => $date_end ?? null,
        ];

        if ($output['datetime_from'] == $output['datetime_to']) {
            unset($output['datetime_to']);
        }

        return $output;
    }

    public function parseCompanyAddress(string $location) {
        preg_match('/^(.+?)\s*, +(.+?)\s*, +([A-Z]{1,2}-?[0-9]{4,}) +(.+)$/ui', $location, $matches);
        $company = $matches[1];
        $street  = $matches[2];
        $postal  = $matches[3];
        $city    = $matches[4];

        $country = preg_replace('/[^A-Z]/ui', '', $postal);
        $country = GeonamesCountry::getIso($country);

        $postal_code = preg_replace('/[^0-9]/ui', '', $postal);

        return [
            'company' => $company,
            'title' => $company,
            'street_address' => $street,
            'city' => $city,
            'postal_code' => $postal_code,
            'country' => $country,
        ];
    }

    public function extractCargos(array $lines) {
        $load_li = array_find_key($lines, fn($l) => $l == "Load:");
        $title = $lines[$load_li + 1];

        $amount_li = array_find_key($lines, fn($l) => $l == "Amount:");
        $package_count = $lines[$amount_li + 1]
            ? uncomma($lines[$amount_li + 1])
            : null;

        $unit_li = array_find_key($lines, fn($l) => $l == "Unit:");
        $package_type = $this->mapPackageType($lines[$unit_li + 1]);

        $weight_li = array_find_key($lines, fn($l) => $l == "Weight:");
        $weight = $lines[$weight_li + 1]
            ? uncomma($lines[$weight_li + 1])
            : null;

        $ldm_li = array_find_key($lines, fn($l) => $l == "Loadingmeter:");
        $ldm = $lines[$ldm_li + 1]
            ? uncomma($lines[$ldm_li + 1])
            : null;

        $load_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Loading reference:"));
        $load_ref = $load_ref_li
            ? explode(': ', $lines[$load_ref_li], 2)[1] ?? null
            : null;

        $unload_ref_li = array_find_key($lines, fn($l) => Str::startsWith($l, "Unloading reference:"));
        $unload_ref = $unload_ref_li
            ? explode(': ', $lines[$unload_ref_li], 2)[1] ?? null
            : null;

        $number = join('; ', array_filter([$load_ref, $unload_ref]));

        return [[
            'title' => $title,
            'number' => $number,
            'package_count' => $package_count ?? 1,
            'package_type' => $package_type,
            'ldm' => $ldm,
            'weight' => $weight,
        ]];
    }

    public function mapPackageType(string $type) {
        $package_type = static::PACKAGE_TYPE_MAP[$type] ?? "PALLET_OTHER";
        return trans("package_type.{$package_type}");
    }
}
