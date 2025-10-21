<?php

namespace App\Services\Odoo;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OdooRestClient implements OdooClientInterface
{
    private ?int $userId = null;

    public function __construct(
        private readonly array $config
    ) {}

    public function updateProductCost(string $sku, float $cost, float $salePrice, string $currency): OdooResponse
    {
        $cost = round($cost, 2);
        $salePrice = round($salePrice, 2);

        $lookup = $this->findProductBySku($sku);

        if (! $lookup) {
            return new OdooResponse(
                ok: false,
                payload: [
                    'sku' => $sku,
                    'cost_price' => $cost,
                    'sale_price' => $salePrice,
                    'currency' => $currency,
                ],
                response: [],
                message: sprintf('Product with SKU %s not found in Odoo.', $sku),
            );
        }

        $updateData = [
            'standard_price' => $cost,
        ];

        if ($salePrice > 0) {
            $updateData['list_price'] = $salePrice;
        }

        $templateUpdated = $this->executeKw('product.template', 'write', [
            [$lookup['product_template_id']],
            $updateData,
        ]);

        if (! $templateUpdated) {
            throw new RuntimeException('Odoo template write operation failed.');
        }

        // Keep variant in sync as well.
        $this->executeKw('product.product', 'write', [
            [$lookup['product_id']],
            ['standard_price' => $cost],
        ]);

        $confirmation = $this->executeKw('product.template', 'read', [
            [$lookup['product_template_id']],
        ], [
            'fields' => ['standard_price', 'list_price'],
        ]);

        $confirmed = $confirmation[0] ?? [];

        return new OdooResponse(
            ok: true,
            payload: [
                'product_id' => $lookup['product_id'],
                'product_template_id' => $lookup['product_template_id'],
                'updated' => $updateData,
            ],
            response: [
                'status' => 'success',
                'product_id' => $lookup['product_id'],
                'product_template_id' => $lookup['product_template_id'],
                'confirmed_standard_price' => $confirmed['standard_price'] ?? null,
                'confirmed_list_price' => $confirmed['list_price'] ?? null,
            ],
            message: 'Odoo product cost updated.',
        );
    }

    public function fetchProducts(array $filters = [], array $options = []): array
    {
        $domain = $this->buildProductSearchDomain($filters);

        $fields = $options['fields'] ?? [
            'id',
            'product_tmpl_id',
            'default_code',
            'name',
            'standard_price',
            'list_price',
            'qty_available',
            'currency_id',
            'write_date',
        ];

        $kwargs = ['fields' => $fields];

        if (isset($options['limit'])) {
            $kwargs['limit'] = (int) $options['limit'];
        }

        if (isset($options['offset'])) {
            $kwargs['offset'] = (int) $options['offset'];
        }

        $kwargs['order'] = $options['order'] ?? 'write_date desc';

        $records = $this->executeKw('product.product', 'search_read', [
            $domain,
        ], $kwargs);

        if (! is_array($records)) {
            return [];
        }

        return array_map(fn (array $record) => $this->mapProductRecord($record), $records);
    }

    private function buildProductSearchDomain(array $filters): array
    {
        $domain = [];

        $skus = $this->normalizeSkuFilter($filters['skus'] ?? null);

        if (! empty($skus)) {
            $domain[] = ['default_code', 'in', $skus];
        }

        if (array_key_exists('updated_after', $filters)) {
            $timestamp = $this->normalizeTimestamp($filters['updated_after']);

            if ($timestamp) {
                $domain[] = ['write_date', '>=', $timestamp];
            }
        }

        if (array_key_exists('updated_before', $filters)) {
            $timestamp = $this->normalizeTimestamp($filters['updated_before']);

            if ($timestamp) {
                $domain[] = ['write_date', '<=', $timestamp];
            }
        }

        return $domain;
    }

    private function mapProductRecord(array $record): array
    {
        $template = $record['product_tmpl_id'] ?? null;
        $templateId = is_array($template) ? (int) ($template[0] ?? 0) : (int) $template;

        $currencyField = $record['currency_id'] ?? null;
        $currency = $this->extractCurrencyCode(is_array($currencyField) ? (string) ($currencyField[1] ?? '') : (string) $currencyField);
        $currency = $currency ? strtoupper($currency) : $this->defaultCurrency();

        return [
            'product_id' => (int) ($record['id'] ?? 0),
            'product_template_id' => $templateId,
            'sku' => (string) ($record['default_code'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'cost_price' => (float) ($record['standard_price'] ?? 0),
            'sale_price' => (float) ($record['list_price'] ?? 0),
            'qty_available' => array_key_exists('qty_available', $record) ? (float) ($record['qty_available'] ?? 0) : null,
            'currency' => $currency,
            'write_date' => $record['write_date'] ?? null,
            'raw' => $record,
        ];
    }

    private function normalizeSkuFilter(mixed $skus): array
    {
        if (is_string($skus)) {
            $skus = preg_split('/[\s,]+/', $skus, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($skus)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($sku) => trim((string) $sku),
            $skus
        ), static fn ($sku) => $sku !== ''));
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value)->format('Y-m-d H:i:s');
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractCurrencyCode(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = strtoupper(trim($value));

        if (preg_match('/([A-Z]{3})/', $value, $matches)) {
            return $matches[1];
        }

        return strlen($value) === 3 ? $value : null;
    }

    private function defaultCurrency(): string
    {
        $fromConfig = $this->config['currency'] ?? null;

        if (is_string($fromConfig) && $fromConfig !== '') {
            return strtoupper($fromConfig);
        }

        $fallback = config('services.odoo.currency', 'USD');

        return strtoupper(is_string($fallback) ? $fallback : 'USD');
    }

    private function findProductBySku(string $sku): ?array
    {
        $ids = $this->executeKw('product.product', 'search', [
            [['default_code', '=', $sku]],
        ], [
            'limit' => 1,
        ]);

        if (empty($ids)) {
            return null;
        }

        $records = $this->executeKw('product.product', 'read', [
            $ids,
        ], [
            'fields' => ['id', 'product_tmpl_id', 'name'],
        ]);

        if (empty($records)) {
            return null;
        }

        $record = $records[0];
        $template = $record['product_tmpl_id'] ?? null;
        $templateId = is_array($template) ? (int) ($template[0] ?? 0) : (int) $template;

        if (! $templateId) {
            return null;
        }

        return [
            'product_id' => (int) ($record['id'] ?? 0),
            'product_template_id' => $templateId,
            'name' => $record['name'] ?? null,
        ];
    }

    private function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $arguments = [
            $this->getDatabase(),
            $this->getUserId(),
            $this->getSecret(),
            $model,
            $method,
            $args,
        ];

        if (! empty($kwargs)) {
            $arguments[] = $kwargs;
        }

        return $this->jsonRpc('object', 'execute_kw', $arguments);
    }

    private function getUserId(): int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }

        $userId = $this->attemptAuthentication();

        if (! $userId) {
            throw new RuntimeException('Unable to authenticate with Odoo. Please verify credentials/API key.');
        }

        return $this->userId = $userId;
    }

    private function jsonRpc(string $service, string $method, array $args = []): mixed
    {
        $endpoint = $this->buildJsonRpcEndpoint();

        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => Str::uuid()->toString(),
        ];

        $response = Http::timeout(15)
            ->acceptJson()
            ->post($endpoint, $payload);

        if ($response->failed()) {
            $body = $response->body();

            Log::error('Odoo RPC HTTP failure.', [
                'service' => $service,
                'method' => $method,
                'args' => $this->sanitizeRpcArgs($service, $method, $args),
                'status' => $response->status(),
                'body' => mb_strimwidth($body ?? '', 0, 500, '…'),
            ]);

            throw new RuntimeException(sprintf(
                'Odoo request failed with status %s (%s): %s',
                $response->status(),
                $endpoint,
                mb_strimwidth($body ?? '', 0, 500, '…')
            ));
        }

        $json = $response->json();

        if (! empty($json['error'])) {
            $message = $json['error']['message'] ?? 'Unknown Odoo error';
            $data = $json['error']['data']['message'] ?? null;

            Log::error('Odoo RPC returned application error.', [
                'service' => $service,
                'method' => $method,
                'args' => $this->sanitizeRpcArgs($service, $method, $args),
                'error' => $json['error'],
            ]);

            throw new RuntimeException(trim($message.($data ? ' - '.$data : '')));
        }

        return $json['result'] ?? null;
    }

    private function buildJsonRpcEndpoint(): string
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (empty($baseUrl)) {
            throw new RuntimeException('Odoo base URL (services.odoo.base_url) is not configured.');
        }

        $path = $this->config['jsonrpc_path'] ?? '/jsonrpc';
        $path = $path === '' ? '/jsonrpc' : $path;
        $path = '/'.ltrim($path, '/');

        return rtrim($baseUrl, '/').$path;
    }

    private function attemptAuthentication(): ?int
    {
        $database = $this->getDatabase();
        $username = $this->getUsername();
        $secret = $this->getSecret();

        try {
            $login = $this->jsonRpc('common', 'login', [
                $database,
                $username,
                $secret,
            ]);

            if (! empty($login)) {
                Log::debug('Odoo login RPC succeeded.', ['user_id' => $login]);
                return (int) $login;
            }

            Log::debug('Odoo login RPC returned empty result.', ['response' => $login]);
        } catch (RuntimeException $exception) {
            // Some Odoo versions deprecate the `login` RPC; fall back to `authenticate`.
            Log::debug('Odoo login RPC threw exception, falling back to authenticate.', [
                'message' => $exception->getMessage(),
            ]);
        }

        $authenticate = $this->jsonRpc('common', 'authenticate', [
            $database,
            $username,
            $secret,
            [],
        ]);

        if (! empty($authenticate)) {
            Log::debug('Odoo authenticate RPC succeeded.', ['user_id' => $authenticate]);
            return (int) $authenticate;
        }

        Log::warning('Odoo authentication failed.', [
            'login_response' => $login ?? null,
            'authenticate_response' => $authenticate,
        ]);

        return null;
    }

    private function sanitizeRpcArgs(string $service, string $method, array $args): array
    {
        $sanitized = $args;

        if ($service === 'common') {
            if (isset($sanitized[2])) {
                $sanitized[2] = '***';
            }
        }

        if ($service === 'object' && isset($sanitized[2])) {
            $sanitized[2] = '***';
        }

        return $sanitized;
    }

    private function getDatabase(): string
    {
        return $this->config['db'] ?? throw new RuntimeException('Odoo database (services.odoo.db) is not configured.');
    }

    private function getUsername(): string
    {
        return $this->config['username'] ?? throw new RuntimeException('Odoo username (services.odoo.username) is not configured.');
    }

    private function getSecret(): string
    {
        $apiKey = $this->config['api_key'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! empty($apiKey)) {
            return $apiKey;
        }

        if (! empty($password)) {
            return $password;
        }

        throw new RuntimeException('Either Odoo API key or password must be configured.');
    }
}
