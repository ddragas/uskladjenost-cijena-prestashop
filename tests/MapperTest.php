<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The mapping from a PrestaShop product or combination to the API's item and price, without PrestaShop. */
final class MapperTest extends TestCase
{
    public function test_a_product_becomes_an_item_and_a_price(): void
    {
        $product = ['id' => 12, 'name' => 'Deterdžent 3 kg', 'reference' => 'DET-3', 'ean13' => '3859 0000 00001', 'mpn' => 'M-1', 'category' => 'Kućanstvo', 'manufacturer' => 'Saponia', 'description' => '<p>Za&nbsp;bijelo  rublje</p>', 'active' => true, 'available' => true, 'price' => '12.500000', 'price_reduced' => '12.500000', 'reduction_to' => null, 'date_upd' => '2026-09-25 10:00:00'];
        $item = UcMapper::item($product, 'm1', 'WEB');
        $this->assertSame([
            'merchant_id' => 'm1', 'kind' => 'product', 'name' => 'Deterdžent 3 kg', 'sku' => 'DET-3', 'barcode' => '3859000000001', 'category' => 'Kućanstvo', 'brand' => 'Saponia',
            'description' => 'Za bijelo rublje', 'active' => true, 'is_available' => true, 'channel_code' => 'WEB',
            'metadata' => ['prestashop_id_product' => 12, 'prestashop_mpn' => 'M-1'],
        ], $item);
        $price = UcMapper::price($product, 'eur', 'WEB');
        $this->assertSame(1250, $price['regular_price_minor']);
        $this->assertSame(1250, $price['effective_price_minor']);
        $this->assertSame('EUR', $price['currency']);
        $this->assertTrue($price['tax_inclusive']);
        $this->assertFalse($price['special_sale']['active']);
        $this->assertSame('ps:12:1250:1250:2026-09-25 10:00:00', $price['source_event_id']);
        $this->assertSame('WEB', $price['channel_code']);
        $this->assertArrayNotHasKey('valid_to', $price);
    }

    public function test_a_combination_is_its_own_item_named_after_the_product_and_its_attributes(): void
    {
        $this->assertSame('12', UcMapper::externalId(12));
        $this->assertSame('12-34', UcMapper::externalId(12, 34));
        $item = UcMapper::item(['id' => 12, 'id_attribute' => 34, 'name' => 'Majica', 'attributes' => 'Veličina - L, Boja - crna', 'reference' => 'MAJ-L-C', 'upc' => '012345678905', 'available' => false], 'm1');
        $this->assertSame('Majica – Veličina - L, Boja - crna', $item['name']);
        $this->assertSame('MAJ-L-C', $item['sku']);
        $this->assertSame('012345678905', $item['barcode']);
        $this->assertFalse($item['is_available']);
        $this->assertArrayNotHasKey('channel_code', $item);
        $this->assertSame(['prestashop_id_product' => 12, 'prestashop_id_product_attribute' => 34], $item['metadata']);
        $this->assertSame('Majica – L, crna', UcMapper::item(['id' => 1, 'name' => 'Majica', 'attributes' => ['L', ' crna ']], 'm1')['name']);
        $this->assertSame('service', UcMapper::item(['id' => 1, 'name' => 'Pranje', 'virtual' => true], 'm1')['kind']);
        $this->assertFalse(UcMapper::item(['id' => 1, 'name' => 'X', 'active' => false], 'm1')['active']);
        $price = UcMapper::price(['id' => 12, 'id_attribute' => 34, 'price' => '20', 'date_upd' => 'd']);
        $this->assertSame('ps:12-34:2000:2000:d', $price['source_event_id']);
    }

    public function test_a_specific_price_below_the_regular_one_is_a_sale_ending_when_it_ends(): void
    {
        $price = UcMapper::price(['id' => 5, 'price' => '20.00', 'price_reduced' => '15,90', 'reduction_to' => '2026-10-31T23:59:59+01:00']);
        $this->assertSame(2000, $price['regular_price_minor']);
        $this->assertSame(1590, $price['effective_price_minor']);
        $this->assertTrue($price['special_sale']['active']);
        $this->assertSame('2026-10-31T23:59:59+01:00', $price['valid_to']);

        // no end date, or PrestaShop's "never" date, means no valid_to
        $this->assertArrayNotHasKey('valid_to', UcMapper::price(['id' => 5, 'price' => '20', 'price_reduced' => '15', 'reduction_to' => '0000-00-00 00:00:00']));
        $this->assertArrayNotHasKey('valid_to', UcMapper::price(['id' => 5, 'price' => '20', 'price_reduced' => '15']));

        // a reduced price at or above the regular one is no sale
        $same = UcMapper::price(['id' => 5, 'price' => '20', 'price_reduced' => '20', 'reduction_to' => '2026-12-31 00:00:00']);
        $this->assertSame(2000, $same['effective_price_minor']);
        $this->assertFalse($same['special_sale']['active']);
        $this->assertArrayNotHasKey('valid_to', $same);
        $this->assertSame(2000, UcMapper::price(['id' => 5, 'price' => '20', 'price_reduced' => '25'])['effective_price_minor']);
    }

    public function test_no_price_means_no_event_and_amounts_round_to_cents(): void
    {
        $this->assertNull(UcMapper::price(['id' => 1, 'price' => '']));
        $this->assertNull(UcMapper::price(['id' => 1, 'price' => 'abc']));
        $this->assertNull(UcMapper::price(['id' => 1]));
        $this->assertSame(1000, UcMapper::minor(10));
        $this->assertSame(999, UcMapper::minor('9.99'));
        $this->assertSame(1250, UcMapper::minor('12,50'));
        $this->assertSame(1, UcMapper::minor('0.005'));
        $this->assertSame(1250, UcMapper::minor(12.5));
        $this->assertNull(UcMapper::minor(null));
    }

    public function test_the_label_comes_only_when_an_anchor_is_owed_and_known(): void
    {
        $this->assertSame('Cijena na dan 10. 9. 2026.: 12,50 €', UcMapper::label(['anchor_display' => ['required' => true, 'label' => 'Cijena na dan 10. 9. 2026.: 12,50 €']]));
        $this->assertSame('x', UcMapper::label(['data' => ['anchor_display' => ['required' => true, 'label' => 'x']]]));
        $this->assertNull(UcMapper::label(['anchor_display' => ['required' => false, 'label' => 'x']]));
        $this->assertNull(UcMapper::label(['anchor_display' => ['required' => true, 'label' => null, 'needs_review' => true]]));
        $this->assertNull(UcMapper::label([]));
    }
}
