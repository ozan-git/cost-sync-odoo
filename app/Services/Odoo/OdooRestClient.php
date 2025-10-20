<?php

namespace App\Services\Odoo;

use Illuminate\Support\Facades\Http;
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

    private function findProductBySku(string $sku): ?array
    {
        $result = $this->executeKw('product.product', 'search_read', [
            [[['default_code', '=', $sku]]],
        ], [
            'fields' => ['id', 'product_tmpl_id', 'name'],
            'limit' => 1,
        ]);

        if (empty($result)) {
            return null;
        }

        $record = $result[0];
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

        $this->userId = (int) $this->jsonRpc('common', 'login', [
            $this->getDatabase(),
            $this->getUsername(),
            $this->getSecret(),
        ]);

        if (! $this->userId) {
            throw new RuntimeException('Unable to authenticate with Odoo. Please verify credentials/API key.');
        }

        return $this->userId;
    }

    private function jsonRpc(string $service, string $method, array $args = []): mixed
    {
        $endpoint = rtrim($this->config['base_url'] ?? '', '/').'/jsonrpc';

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

            throw new RuntimeException(trim($message.($data ? ' - '.$data : '')));
        }

        return $json['result'] ?? null;
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
