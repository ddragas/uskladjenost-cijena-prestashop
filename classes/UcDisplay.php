<?php
/**
 * The anchor price under the price, from the API's decision for the
 * product on the webshop channel, cached six hours and forgotten on
 * every sync of that product.
 */
final class UcDisplay
{
    public static function label(string $externalId): ?string
    {
        $key = self::key($externalId);
        $cached = UcCache::get($key);
        if ($cached !== false) {
            return $cached === '' || $cached === null ? null : (string) $cached;
        }
        $client = UcClient::fromConfiguration();
        if ($client === null) {
            return null;
        }
        $decision = $client->complianceByExternal($externalId, ['channel_code' => (string) Configuration::get('UC_CHANNEL_CODE'), 'locale' => 'hr']);
        $label = isset($decision['error']) ? null : UcMapper::label($decision['data'] ?? []);
        UcCache::set($key, $label ?? '', isset($decision['error']) ? 5 * 60 : UcCache::TTL);

        return $label;
    }

    public static function forget(string $externalId): void
    {
        UcCache::forget(self::key($externalId));
    }

    private static function key(string $externalId): string
    {
        return 'label|'.(string) Configuration::get('UC_CHANNEL_CODE').'|'.$externalId;
    }
}
