<?php
/**
 * PrestaShop product data → API payloads. Pure PHP (no PrestaShop), so it
 * is unit-tested on its own. The external id of a product is its id as a
 * string ("12"); a combination's is "productId-attributeId" ("12-34").
 *
 * PHP 7.4+ (PrestaShop 8 runs on 7.2.5–8.1), so no PHP 8 syntax here.
 */
final class UcMapper
{
    public static function externalId(int $productId, int $attributeId = 0): string
    {
        return $attributeId > 0 ? $productId.'-'.$attributeId : (string) $productId;
    }

    /**
     * The item to PUT /items/{id}.
     *
     * @param array{id: int, id_attribute?: int, name: string, attributes?: string|array<int|string, string>, reference?: string, ean13?: string, upc?: string, isbn?: string, mpn?: string, category?: string, manufacturer?: string, virtual?: bool, active?: bool, description?: string, available?: bool} $product
     * @return array<string, mixed>
     */
    public static function item(array $product, string $merchantId, ?string $channelCode = null): array
    {
        $name = trim((string) $product['name']);
        $attributes = $product['attributes'] ?? null;
        if (is_array($attributes)) {
            $attributes = implode(', ', array_filter(array_map('trim', array_map('strval', array_values($attributes)))));
        }
        if (is_string($attributes) && trim($attributes) !== '') {
            $name .= ' – '.trim($attributes);
        }
        $barcode = '';
        foreach (['ean13', 'upc', 'isbn'] as $field) {
            if (! empty($product[$field])) {
                $barcode = preg_replace('/\s+/', '', (string) $product[$field]);
                break;
            }
        }
        $item = [
            'merchant_id' => $merchantId,
            'kind' => ! empty($product['virtual']) ? 'service' : 'product',
            'name' => mb_substr($name, 0, 255),
            'sku' => ! empty($product['reference']) ? (string) $product['reference'] : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => ! empty($product['category']) ? mb_substr((string) $product['category'], 0, 120) : null,
            'brand' => ! empty($product['manufacturer']) ? mb_substr((string) $product['manufacturer'], 0, 120) : null,
            'description' => ! empty($product['description']) ? mb_substr(self::plain((string) $product['description']), 0, 2000) : null,
            'active' => array_key_exists('active', $product) ? (bool) $product['active'] : true,
            'is_available' => array_key_exists('available', $product) ? (bool) $product['available'] : true,
            'channel_code' => $channelCode,
            'metadata' => array_filter([
                'prestashop_id_product' => (int) $product['id'],
                'prestashop_id_product_attribute' => (int) ($product['id_attribute'] ?? 0),
                'prestashop_mpn' => ! empty($product['mpn']) ? (string) $product['mpn'] : null,
            ], static function ($v) {
                return $v !== null && $v !== 0;
            }),
        ];

        return array_filter($item, static function ($v) {
            return $v !== null && $v !== '';
        });
    }

    /**
     * The price event to POST /prices/by-external/{id}, or null when the
     * product has no price. `price` is the regular price including tax,
     * `price_reduced` the price after specific prices (the one paid);
     * a reduction below the regular price is a sale, ending at `reduction_to`.
     *
     * @param array{id: int, id_attribute?: int, price?: string|float|int|null, price_reduced?: string|float|int|null, reduction_to?: string|null, date_upd?: string|null} $product
     * @return array<string, mixed>|null
     */
    public static function price(array $product, string $currency = 'EUR', ?string $channelCode = null): ?array
    {
        $regular = self::minor($product['price'] ?? null);
        if ($regular === null) {
            return null;
        }
        $reduced = self::minor($product['price_reduced'] ?? null);
        $onSale = $reduced !== null && $reduced < $regular;
        $effective = $onSale ? $reduced : $regular;
        $external = self::externalId((int) $product['id'], (int) ($product['id_attribute'] ?? 0));
        $event = [
            'regular_price_minor' => $regular,
            'effective_price_minor' => $effective,
            'currency' => strtoupper($currency ?: 'EUR'),
            'tax_inclusive' => true,
            'special_sale' => ['active' => $onSale],
            'source_event_id' => 'ps:'.$external.':'.$regular.':'.$effective.':'.(string) ($product['date_upd'] ?? ''),
            'channel_code' => $channelCode,
        ];
        $to = (string) ($product['reduction_to'] ?? '');
        if ($onSale && $to !== '' && strpos($to, '0000-00-00') !== 0) {
            $event['valid_to'] = $to;
        }

        return array_filter($event, static function ($v) {
            return $v !== null;
        });
    }

    /** "12.50" → 1250; "" / null / not a number → null. */
    public static function minor($amount): ?int
    {
        if ($amount === null || $amount === '' || $amount === false) {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $amount));
        if (! is_numeric($text)) {
            return null;
        }

        return (int) round(((float) $text) * 100);
    }

    /**
     * The one line printed next to the price, from the API's decision; null when nothing is owed.
     *
     * @param array<string, mixed> $decision
     */
    public static function label(array $decision): ?string
    {
        $display = $decision['anchor_display'] ?? ($decision['data']['anchor_display'] ?? null);
        if (! is_array($display) || empty($display['required'])) {
            return null;
        }
        $label = $display['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '', " \t\n\r\0\x0B\xC2\xA0");
    }
}
