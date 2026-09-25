<?php
/** The API over curl; no Composer needed at runtime. */
final class UcClient
{
    public const VERSION = '1.0.0';

    /** @var string */
    private $token;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    public function __construct(string $token, string $baseUrl = 'https://uskladjenost-cijena.com', int $timeout = 15)
    {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /** From the module's configuration; null until a token is set. */
    public static function fromConfiguration(): ?self
    {
        $token = (string) Configuration::get('UC_API_TOKEN');
        if ($token === '') {
            return null;
        }

        return new self($token, (string) (Configuration::get('UC_BASE_URL') ?: 'https://uskladjenost-cijena.com'));
    }

    /** @return array<string, mixed> */
    public function ping(): array
    {
        return $this->request('GET', '/api/v1/ping');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function upsertItem(string $externalId, array $data): array
    {
        return $this->request('PUT', '/api/v1/items/'.rawurlencode($externalId), $data);
    }

    /** @param list<array<string, mixed>> $items @return array<string, mixed> */
    public function bulkItems(array $items): array
    {
        return $this->request('POST', '/api/v1/items/bulk', ['items' => array_values($items)]);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function recordPriceByExternal(string $externalId, array $event, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/api/v1/prices/by-external/'.rawurlencode($externalId), $event, $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]);
    }

    /** @param list<array<string, mixed>> $events @return array<string, mixed> */
    public function bulkPrices(array $events): array
    {
        return $this->request('POST', '/api/v1/price-events/bulk', ['events' => array_values($events)]);
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    public function complianceByExternal(string $externalId, array $scope = []): array
    {
        return $this->request('GET', '/api/v1/compliance/by-external/'.rawurlencode($externalId).'?'.http_build_query(array_filter($scope)));
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string> $headers
     * @return array<string, mixed> the decoded body; on refusal ['error' => ['code', 'message', 'status', 'details']]
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $lines = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: uskladjenost-cijena-prestashop/'.self::VERSION,
        ];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        $curl = curl_init($this->baseUrl.$path);
        if ($curl === false) {
            return ['error' => ['code' => 'transport', 'message' => 'curl_init failed', 'status' => 0]];
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($json !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $failure = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            return ['error' => ['code' => 'transport', 'message' => $failure ?: 'no answer', 'status' => 0]];
        }
        $data = json_decode((string) $body, true);
        $data = is_array($data) ? $data : [];
        if ($status >= 400) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];

            return ['error' => [
                'code' => (string) ($error['code'] ?? 'http_'.$status),
                'message' => (string) ($error['message'] ?? 'HTTP '.$status),
                'status' => $status,
                'details' => $error['details'] ?? [],
            ]];
        }
        $data['_status'] = $status;

        return $data;
    }
}
