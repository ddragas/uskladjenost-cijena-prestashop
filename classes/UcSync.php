<?php
/**
 * Products and prices out of PrestaShop: on every save (one product with
 * every combination) and on demand (the whole catalogue, in bulk). A
 * product with combinations sends each combination as an item of its own
 * ("productId-attributeId") and the product itself without a price.
 */
final class UcSync
{
    /** One product and its combinations, after any save. */
    public static function onProductSaved(int $idProduct): void
    {
        $client = UcClient::fromConfiguration();
        $merchant = (string) Configuration::get('UC_MERCHANT_ID');
        if ($client === null || $merchant === '' || $idProduct <= 0) {
            return;
        }
        $product = new Product($idProduct, true, self::idLang());
        if (! Validate::isLoadedObject($product)) {
            return;
        }
        foreach (self::rows($product) as $row) {
            $error = self::syncOne($client, $merchant, $row);
            if ($error !== null) {
                Configuration::updateValue('UC_LAST_ERROR', $error);

                return;
            }
        }
        Configuration::updateValue('UC_LAST_ERROR', '');
        Configuration::updateValue('UC_LAST_SYNC', date('Y-m-d H:i:s'));
    }

    /** A deleted product: it and every combination go inactive. */
    public static function onProductDeleted(Product $product): void
    {
        $client = UcClient::fromConfiguration();
        $merchant = (string) Configuration::get('UC_MERCHANT_ID');
        if ($client === null || $merchant === '' || ! Validate::isLoadedObject($product)) {
            return;
        }
        foreach (self::rows($product) as $row) {
            self::deactivate($client, $merchant, $row['external_id'], $row['item']['name']);
        }
    }

    /** One combination gone (the product stays). */
    public static function onCombinationDeleted(int $idProduct, int $idAttribute): void
    {
        $client = UcClient::fromConfiguration();
        $merchant = (string) Configuration::get('UC_MERCHANT_ID');
        if ($client === null || $merchant === '' || $idProduct <= 0 || $idAttribute <= 0) {
            return;
        }
        $product = new Product($idProduct, false, self::idLang());
        $name = Validate::isLoadedObject($product) ? (string) $product->name : (string) $idProduct;
        self::deactivate($client, $merchant, UcMapper::externalId($idProduct, $idAttribute), $name);
    }

    private static function deactivate(UcClient $client, string $merchant, string $externalId, string $name): void
    {
        $channel = (string) Configuration::get('UC_CHANNEL_CODE') ?: null;
        $client->upsertItem($externalId, array_filter([
            'merchant_id' => $merchant,
            'kind' => 'product',
            'name' => mb_substr($name, 0, 255),
            'active' => false,
            'is_available' => false,
            'channel_code' => $channel,
        ], static function ($v) {
            return $v !== null;
        }));
        UcDisplay::forget($externalId);
    }

    /**
     * Every row a product yields: the product itself, then one per
     * combination. Each row is ['external_id', 'item', 'price'] with the
     * arrays UcMapper takes already applied.
     *
     * @return list<array{external_id: string, item: array<string, mixed>, price: array<string, mixed>|null}>
     */
    public static function rows(Product $product): array
    {
        $merchant = (string) Configuration::get('UC_MERCHANT_ID');
        $channel = (string) Configuration::get('UC_CHANNEL_CODE') ?: null;
        $currency = self::currency();
        $idLang = self::idLang();
        $combinations = $product->getAttributesResume($idLang);
        $hasCombinations = is_array($combinations) && $combinations !== [];
        $rows = [];

        $data = self::extract($product, 0, $idLang);
        $rows[] = [
            'external_id' => UcMapper::externalId((int) $product->id),
            'item' => UcMapper::item($data, $merchant, $channel),
            'price' => $hasCombinations ? null : UcMapper::price($data, $currency, $channel),
        ];
        if ($hasCombinations) {
            foreach ($combinations as $combination) {
                $idAttribute = (int) $combination['id_product_attribute'];
                $data = self::extract($product, $idAttribute, $idLang, $combination);
                $rows[] = [
                    'external_id' => UcMapper::externalId((int) $product->id, $idAttribute),
                    'item' => UcMapper::item($data, $merchant, $channel),
                    'price' => UcMapper::price($data, $currency, $channel),
                ];
            }
        }

        return $rows;
    }

    /**
     * The array UcMapper takes, for the product or one of its combinations.
     *
     * @param array<string, mixed>|null $combination a row of getAttributesResume
     * @return array<string, mixed>
     */
    public static function extract(Product $product, int $idAttribute = 0, ?int $idLang = null, ?array $combination = null): array
    {
        $idLang = $idLang ?? self::idLang();
        $id = (int) $product->id;
        $category = new Category((int) $product->id_category_default, $idLang);
        $manufacturer = $product->id_manufacturer ? Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        $quantity = (int) StockAvailable::getQuantityAvailableByProduct($id, $idAttribute ?: null);
        $available = $quantity > 0 || Product::isAvailableWhenOutOfStock((int) $product->out_of_stock);
        $reductionTo = self::reductionEnds($id, $idAttribute);

        return [
            'id' => $id,
            'id_attribute' => $idAttribute,
            'name' => is_array($product->name) ? (string) ($product->name[$idLang] ?? reset($product->name)) : (string) $product->name,
            'attributes' => $combination !== null ? (string) ($combination['attribute_designation'] ?? '') : '',
            'reference' => $combination !== null && ! empty($combination['reference']) ? (string) $combination['reference'] : (string) $product->reference,
            'ean13' => $combination !== null && ! empty($combination['ean13']) ? (string) $combination['ean13'] : (string) $product->ean13,
            'upc' => $combination !== null && ! empty($combination['upc']) ? (string) $combination['upc'] : (string) $product->upc,
            'isbn' => $combination !== null && ! empty($combination['isbn']) ? (string) $combination['isbn'] : (string) ($product->isbn ?? ''),
            'mpn' => $combination !== null && ! empty($combination['mpn']) ? (string) $combination['mpn'] : (string) ($product->mpn ?? ''),
            'category' => Validate::isLoadedObject($category) ? (is_array($category->name) ? (string) ($category->name[$idLang] ?? '') : (string) $category->name) : '',
            'manufacturer' => is_string($manufacturer) ? $manufacturer : '',
            'virtual' => (bool) $product->is_virtual,
            'active' => (bool) $product->active,
            'description' => is_array($product->description_short) ? (string) ($product->description_short[$idLang] ?? '') : (string) $product->description_short,
            'available' => $available,
            'price' => Product::getPriceStatic($id, true, $idAttribute ?: null, 6, null, false, false),
            'price_reduced' => Product::getPriceStatic($id, true, $idAttribute ?: null, 6, null, false, true),
            'reduction_to' => $reductionTo,
            'date_upd' => (string) $product->date_upd,
        ];
    }

    /** When the specific price in force ends, or null. */
    private static function reductionEnds(int $idProduct, int $idAttribute): ?string
    {
        try {
            $context = Context::getContext();
            $specific = SpecificPrice::getSpecificPrice($idProduct, (int) $context->shop->id, (int) $context->currency->id, (int) $context->country->id, (int) Group::getCurrent()->id, 1, $idAttribute ?: null);
            $to = is_array($specific) ? (string) ($specific['to'] ?? '') : '';

            return $to !== '' && strpos($to, '0000-00-00') !== 0 ? date('c', strtotime($to)) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** One row: the item, then its price. Returns the error text or null. */
    public static function syncOne(UcClient $client, string $merchant, array $row): ?string
    {
        $external = $row['external_id'];
        $item = $client->upsertItem($external, $row['item']);
        if (isset($item['error'])) {
            return 'artikl '.$external.': '.$item['error']['message'];
        }
        if ($row['price'] !== null) {
            $result = $client->recordPriceByExternal($external, $row['price']);
            if (isset($result['error'])) {
                return 'cijena '.$external.': '.$result['error']['message'];
            }
        }
        UcDisplay::forget($external);

        return null;
    }

    /** The whole catalogue in batches of 200 items and their prices. Returns a summary line. */
    public static function syncAll(): string
    {
        $client = UcClient::fromConfiguration();
        $merchant = (string) Configuration::get('UC_MERCHANT_ID');
        if ($client === null || $merchant === '') {
            return 'Upišite token i ID trgovca.';
        }
        $idLang = self::idLang();
        $sent = 0;
        $errors = 0;
        $start = 0;
        do {
            $products = Product::getProducts($idLang, $start, 100, 'id_product', 'ASC', false, false);
            $items = [];
            $prices = [];
            foreach ($products as $summary) {
                $product = new Product((int) $summary['id_product'], true, $idLang);
                if (! Validate::isLoadedObject($product)) {
                    continue;
                }
                foreach (self::rows($product) as $row) {
                    $items[] = $row['item'] + ['external_id' => $row['external_id']];
                    if ($row['price'] !== null) {
                        $price = $row['price'];
                        unset($price['channel_code']);
                        $prices[$row['external_id']] = $price;
                    }
                    UcDisplay::forget($row['external_id']);
                }
            }
            foreach (array_chunk($items, 200) as $chunk) {
                $result = $client->bulkItems($chunk);
                if (isset($result['error'])) {
                    return 'Greška: '.$result['error']['message'];
                }
                $events = [];
                foreach ($result['data'] ?? [] as $row) {
                    if (($row['status'] ?? '') === 'error') {
                        $errors++;

                        continue;
                    }
                    $sent++;
                    $external = (string) ($row['external_id'] ?? '');
                    $offerId = $row['data']['offer_id'] ?? ($row['offer_id'] ?? null);
                    if (isset($prices[$external]) && $offerId) {
                        $events[] = $prices[$external] + ['offer_id' => $offerId];
                    }
                }
                foreach (array_chunk($events, 1000) as $batch) {
                    $priced = $client->bulkPrices($batch);
                    $errors += (int) ($priced['summary']['errors'] ?? 0);
                }
            }
            $start += 100;
        } while (count($products) === 100);
        Configuration::updateValue('UC_LAST_SYNC', date('Y-m-d H:i:s'));
        Configuration::updateValue('UC_LAST_ERROR', '');

        return sprintf('poslano %d artikala, %d s greškom.', $sent, $errors);
    }

    private static function idLang(): int
    {
        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private static function currency(): string
    {
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        return Validate::isLoadedObject($currency) && $currency->iso_code ? (string) $currency->iso_code : 'EUR';
    }
}
