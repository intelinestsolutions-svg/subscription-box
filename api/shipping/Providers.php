<?php
/**
 * Shipping carrier adapters — one interface, three providers.
 *   local_csv : zero config, exports a print-ready label CSV (perfect for day 1 + local couriers).
 *   dhl       : DHL Unified API (/shipments) — needs api_key + secret in config.
 *   fedex     : FedEx Ship API (/ship/v1/shipments) — needs api_key + secret in config.
 */
declare(strict_types=1);

interface ShippingProvider
{
    /** @return array{success:bool, carrier:string, tracking_number:string, label_url:string, cost:float, message?:string} */
    public function createLabel(array $shipment, array $config): array;
    public function name(): string;
}

/** CSV/print-ready labels for local couriers — works with zero keys. */
final class LocalCsvProvider implements ShippingProvider
{
    public function name(): string { return 'local_csv'; }

    public function createLabel(array $shipment, array $config): array
    {
        $dir = rtrim((string)($config['storage_dir'] ?? __DIR__ . '/../../storage'), '/') . '/labels';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        $tracking = 'TRK-' . strtoupper(substr(md5($shipment['to']['email'] . $shipment['cycle']), 0, 10));

        // Append one row to the cycle's label CSV (bulk = one file per cycle).
        $file = $dir . '/' . str_replace('-', '', $shipment['cycle']) . '-labels.csv';
        $isNew = !is_file($file);
        $fh = fopen($file, 'a');
        if ($isNew) {
            fputcsv($fh, ['tracking', 'name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'items']);
        }
        fputcsv($fh, [
            $tracking,
            $shipment['to']['name'],
            $shipment['to']['line1'],
            $shipment['to']['line2'] ?? '',
            $shipment['to']['city'],
            $shipment['to']['state'] ?? '',
            $shipment['to']['postal_code'],
            $shipment['to']['country'] ?? 'MY',
            implode(' | ', $shipment['items']),
        ]);
        fclose($fh);

        return ['success' => true, 'carrier' => 'local_csv', 'tracking_number' => $tracking,
                'label_url' => 'storage/labels/' . basename($file), 'cost' => 4.80];
    }
}

/** DHL Unified API. Contract: POST https://api-eu.dhl.com/shipments */
final class DhlProvider implements ShippingProvider
{
    public function name(): string { return 'dhl'; }

    public function createLabel(array $shipment, array $config): array
    {
        $key    = (string)($config['dhl_api_key'] ?? '');
        $secret = (string)($config['dhl_secret'] ?? '');
        if ($key === '' || $secret === '') {
            return ['success' => false, 'carrier' => 'dhl', 'tracking_number' => '',
                    'label_url' => '', 'cost' => 0, 'message' => 'DHL credentials missing — add dhl_api_key/dhl_secret to config.'];
        }
        $base = rtrim((string)($config['dhl_api_url'] ?? 'https://api-eu.dhl.com'), '/');

        $payload = [
            'plannedShippingDateAndTime' => $shipment['cycle'] . 'T10:00:00GMT+08:00',
            'pickup' => ['isRequested' => false],
            'productCode' => 'P',
            'accounts' => [['number' => $key, 'typeCode' => 'shipper']],
            'customerDetails' => [
                'shipperDetails' => ['postalAddress' => ['postalCode' => '88000', 'cityName' => 'Kota Kinabalu',
                    'countryCode' => 'MY', 'addressLine1' => 'SBC HQ', 'provinceCode' => 'SBH', 'provinceName' => 'Sabah']],
                'receiverDetails' => ['postalAddress' => [
                    'postalCode' => $shipment['to']['postal_code'],
                    'cityName'   => $shipment['to']['city'],
                    'countryCode'=> $shipment['to']['country'] ?? 'MY',
                    'addressLine1' => $shipment['to']['line1'],
                    'addressLine2' => $shipment['to']['line2'] ?? '',
                ]],
            ],
            'content' => ['packages' => [[
                'weight' => 1.0, 'dimensions' => ['length' => 25, 'width' => 20, 'height' => 12],
                'description' => implode(', ', array_slice($shipment['items'], 0, 5)),
            ]]],
        ];

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Basic " . base64_encode("$key:$secret") . "\r\nContent-Type: application/json\r\n",
                'content' => json_encode($payload),
                'timeout' => 25,
                'ignore_errors' => true,
            ],
        ]);
        $raw  = @file_get_contents("$base/shipments", false, $ctx);
        $data = json_decode((string)$raw, true);
        $c    = $data['shipmentConfirmation'][0] ?? null;
        if (!$c) {
            return ['success' => false, 'carrier' => 'dhl', 'tracking_number' => '', 'label_url' => '',
                    'cost' => 0, 'message' => 'DHL: ' . ($data['title'] ?? 'request failed') . ' ' . json_encode($data['detail'] ?? $data) ];
        }
        return ['success' => true, 'carrier' => 'dhl',
                'tracking_number' => $c['shipmentTrackingNumber'] ?? 'N/A',
                'label_url' => $data['documents'][0]['url'] ?? '',
                'cost' => (float)($data['productContent'][0]['price'                    ] ?? 0) > 0
                    ? (float)$data['productContent'][0]['price'] : 12.50];
    }
}

/** FedEx Ship API — OAuth token then POST /ship/v1/shipments. */
final class FedExProvider implements ShippingProvider
{
    public function name(): string { return 'fedex'; }

    public function createLabel(array $shipment, array $config): array
    {
        $key    = (string)($config['fedex_api_key'] ?? '');
        $secret = (string)($config['fedex_secret'] ?? '');
        if ($key === '' || $secret === '') {
            return ['success' => false, 'carrier' => 'fedex', 'tracking_number' => '',
                    'label_url' => '', 'cost' => 0, 'message' => 'FedEx credentials missing — add fedex_api_key/fedex_secret to config.'];
        }
        $base = rtrim((string)($config['fedex_api_url'] ?? 'https://apis.fedex.com'), '/');

        // 1) OAuth token
        $tokCtx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['grant_type' => 'client_credentials', 'client_id' => $key, 'client_secret' => $secret]),
            'timeout' => 20, 'ignore_errors' => true,
        ]]);
        $tok = json_decode((string)@file_get_contents("$base/oauth/token", false, $tokCtx), true);
        $access = $tok['access_token'] ?? '';
        if ($access === '') {
            return ['success' => false, 'carrier' => 'fedex', 'tracking_number' => '', 'label_url' => '',
                    'cost' => 0, 'message' => 'FedEx OAuth failed.'];
        }

        // 2) Ship request (FedEx ground, smartpost-style basic contract)
        $payload = [
            'requestedShipment' => [
                'shipTimestamp' => $shipment['cycle'] . 'T10:00:00-05:00',
                'dropoffType' => 'REGULAR_PICKUP',
                'serviceType' => 'FEDEX_GROUND',
                'packagingType' => 'YOUR_PACKAGING',
                'totalWeight' => ['units' => 'KG', 'value' => 1.0],
                'shipper' => ['contact' => ['personName' => 'SBC Distribution'], 'address' => [
                    'streetLines' => ['SBC HQ, 1 Jln Sederhana'], 'city' => 'Kota Kinabalu',
                    'stateOrProvinceCode' => 'SBH', 'postalCode' => '88000', 'countryCode' => 'MY']],
                'recipients' => [['contact' => ['personName' => $shipment['to']['name']], 'address' => [
                    'streetLines' => array_filter([$shipment['to']['line1'], $shipment['to']['line2'] ?? '']),
                    'city' => $shipment['to']['city'], 'stateOrProvinceCode' => $shipment['to']['state'] ?? '',
                    'postalCode' => $shipment['to']['postal_code'], 'countryCode' => $shipment['to']['country'] ?? 'MY']]],
                'labelSpecification' => ['imageType' => 'PDF', 'labelStockType' => 'PAPER_4X6'],
            ],
        ];
        $shipCtx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer $access\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload), 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents("$base/ship/v1/shipments", false, $shipCtx);
        $data = json_decode((string)$raw, true);
        $out  = $data['output'] ?? [];
        $trk  = $out['transactionShipments'][0]['masterTrackingNumber'] ?? null;
        if (!$trk) {
            return ['success' => false, 'carrier' => 'fedex', 'tracking_number' => '', 'label_url' => '',
                    'cost' => 0, 'message' => 'FedEx: ' . json_encode($data['errors'] ?? 'shipment failed')];
        }
        return ['success' => true, 'carrier' => 'fedex', 'tracking_number' => $trk,
                'label_url' => $out['transactionShipments'][0]['pieceResponses'][0]['packageDocuments'][0]['url'] ?? '',
                'cost' => $data['output']['transactionShipments'][0]['shipmentCharges']['totalShipmentCharges']['amount'] ?? 11.00];
    }
}

final class ShippingProviderRegistry
{
    public static function make(string $name): ShippingProvider
    {
        return match ($name) {
            'dhl'       => new DhlProvider(),
            'fedex'     => new FedExProvider(),
            default     => new LocalCsvProvider(),
        };
    }

    /** What's configured right now (for the UI). */
    public static function status(array $config): array
    {
        return [
            ['id' => 'local_csv', 'name' => 'Local courier (CSV)', 'configured' => true],
            ['id' => 'dhl',       'name' => 'DHL',                 'configured' => !empty($config['dhl_api_key']) && !empty($config['dhl_secret'])],
            ['id' => 'fedex',     'name' => 'FedEx',               'configured' => !empty($config['fedex_api_key']) && !empty($config['fedex_secret'])],
        ];
    }
}