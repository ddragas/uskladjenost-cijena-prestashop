<?php
/**
 * Usklađenost cijena for PrestaShop 8: anchor prices and the public price
 * list under NN 101/2026. Products and prices go to uskladjenost-cijena.com
 * on every save; the product page prints the anchor price under the price.
 *
 * @author  Info Media d.o.o.
 * @license MIT
 */
if (! defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/classes/UcMapper.php';
require_once __DIR__.'/classes/UcClient.php';
require_once __DIR__.'/classes/UcCache.php';
require_once __DIR__.'/classes/UcDisplay.php';
require_once __DIR__.'/classes/UcSync.php';

class Uskladjenostcijena extends Module
{
    public const HOOKS = [
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionUpdateQuantity',
        'displayProductPriceBlock',
    ];

    public const DEFAULTS = [
        'UC_API_TOKEN' => '',
        'UC_BASE_URL' => 'https://uskladjenost-cijena.com',
        'UC_MERCHANT_ID' => '',
        'UC_CHANNEL_CODE' => 'WEB',
        'UC_SHOW_LABEL' => 1,
        'UC_AUTO_SYNC' => 1,
        'UC_LAST_SYNC' => '',
        'UC_LAST_ERROR' => '',
    ];

    public function __construct()
    {
        $this->name = 'uskladjenostcijena';
        $this->tab = 'pricing_promotion';
        $this->version = '1.0.0';
        $this->author = 'Info Media d.o.o.';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'Usklađenost cijena';
        $this->description = 'Sidrene cijene i javni cjenik po NN 101/2026: artikli i cijene idu u Usklađenost cijena, a uz cijenu proizvoda ispisuje se propisana sidrena cijena.';
        $this->confirmUninstall = 'Ukloniti modul? Podaci u servisu Usklađenost cijena ostaju.';
    }

    public function install(): bool
    {
        if (! parent::install()) {
            return false;
        }
        foreach (self::HOOKS as $hook) {
            if (! $this->registerHook($hook)) {
                return false;
            }
        }
        foreach (self::DEFAULTS as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }
        UcCache::clear();

        return parent::uninstall();
    }

    // ---- configuration page -------------------------------------------------

    public function getContent(): string
    {
        $output = '';
        if (Tools::isSubmit('submitUskladjenostcijena')) {
            foreach (['UC_API_TOKEN', 'UC_BASE_URL', 'UC_MERCHANT_ID', 'UC_CHANNEL_CODE'] as $key) {
                $value = trim((string) Tools::getValue($key));
                if ($key === 'UC_API_TOKEN' && $value === '') {
                    continue; // an empty password field keeps the token
                }
                Configuration::updateValue($key, $value);
            }
            Configuration::updateValue('UC_SHOW_LABEL', (int) Tools::getValue('UC_SHOW_LABEL'));
            Configuration::updateValue('UC_AUTO_SYNC', (int) Tools::getValue('UC_AUTO_SYNC'));
            Configuration::updateValue('UC_LAST_ERROR', '');
            UcCache::clear();
            $output .= $this->displayConfirmation('Postavke spremljene.');
        }
        if (Tools::isSubmit('submitUskladjenostcijenaSyncAll')) {
            $summary = UcSync::syncAll();
            $output .= $this->displayConfirmation('Sinkronizacija: '.$summary);
        }

        return $output.$this->renderStatus().$this->renderForm();
    }

    private function renderStatus(): string
    {
        $client = UcClient::fromConfiguration();
        if ($client === null) {
            $connection = 'Upišite token.';
        } else {
            $ping = $client->ping();
            $connection = isset($ping['error'])
                ? '<span style="color:#932F30">Greška: '.htmlspecialchars((string) $ping['error']['message']).'</span>'
                : '<span style="color:#27614A">Povezano: '.htmlspecialchars((string) ($ping['data']['tenant'] ?? '')).' (opsezi: '.htmlspecialchars(implode(', ', (array) ($ping['data']['scopes'] ?? []))).')</span>';
        }
        $lastSync = (string) Configuration::get('UC_LAST_SYNC') ?: '—';
        $lastError = (string) Configuration::get('UC_LAST_ERROR');
        $syncUrl = htmlspecialchars($this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]));

        return '<div class="panel"><div class="panel-heading">Stanje</div>'
            .'<table class="table"><tr><th style="width:220px">Veza</th><td>'.$connection.'</td></tr>'
            .'<tr><th>Zadnja sinkronizacija</th><td>'.htmlspecialchars($lastSync).'</td></tr>'
            .($lastError !== '' ? '<tr><th>Zadnja greška</th><td><span style="color:#932F30">'.htmlspecialchars($lastError).'</span></td></tr>' : '')
            .'<tr><th>Sve proizvode sada</th><td><form method="post" action="'.$syncUrl.'"><button type="submit" name="submitUskladjenostcijenaSyncAll" class="btn btn-default">Pošalji sve proizvode i cijene</button> '
            .'<span class="help-block" style="display:inline">Serije od 200 artikala; na velikim trgovinama traje minutu-dvije.</span></form></td></tr></table></div>';
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = 0;
        $helper->submit_action = 'submitUskladjenostcijena';
        $helper->fields_value = [
            'UC_API_TOKEN' => '',
            'UC_BASE_URL' => Configuration::get('UC_BASE_URL'),
            'UC_MERCHANT_ID' => Configuration::get('UC_MERCHANT_ID'),
            'UC_CHANNEL_CODE' => Configuration::get('UC_CHANNEL_CODE'),
            'UC_SHOW_LABEL' => (int) Configuration::get('UC_SHOW_LABEL'),
            'UC_AUTO_SYNC' => (int) Configuration::get('UC_AUTO_SYNC'),
        ];
        $switch = static function (string $name): array {
            return ['type' => 'switch', 'name' => $name, 'is_bool' => true, 'values' => [['id' => $name.'_on', 'value' => 1, 'label' => 'Da'], ['id' => $name.'_off', 'value' => 0, 'label' => 'Ne']]];
        };
        $hasToken = (string) Configuration::get('UC_API_TOKEN') !== '';
        $form = ['form' => [
            'legend' => ['title' => 'Usklađenost cijena', 'icon' => 'icon-cogs'],
            'description' => 'Token izdajete u aplikaciji Usklađenost cijena pod <em>API pristup</em>, s opsezima catalog:write, prices:write i compliance:read. Šifra kanala je kod vašeg webshopa (Trgovac → Kanali), npr. WEB.',
            'input' => [
                ['type' => 'password', 'label' => 'API token', 'name' => 'UC_API_TOKEN', 'desc' => $hasToken ? 'Token je spremljen; ostavite prazno da ga zadržite.' : 'pc_live_… ili pc_test_…'],
                ['type' => 'text', 'label' => 'ID trgovca', 'name' => 'UC_MERCHANT_ID', 'desc' => 'Iz aplikacije: Trgovci → vaš trgovac → ID.'],
                ['type' => 'text', 'label' => 'Šifra kanala (webshop)', 'name' => 'UC_CHANNEL_CODE', 'desc' => 'Kod prodajnog kanala tipa webshop, npr. WEB. Cijene idu na taj kanal i javni cjenik se gradi za njega.'],
                ['type' => 'text', 'label' => 'Adresa servisa', 'name' => 'UC_BASE_URL', 'desc' => 'Ostavite kako jest; mijenja se samo za testni poslužitelj.'],
                ['label' => 'Automatska sinkronizacija', 'desc' => 'Svaka spremljena promjena proizvoda, kombinacije, posebne cijene ili zalihe odmah ide u Usklađenost cijena.'] + $switch('UC_AUTO_SYNC'),
                ['label' => 'Sidrena cijena uz cijenu', 'desc' => 'Ispod cijene ispiši propisani tekst (npr. „Cijena na dan 10. 9. 2026.: 12,50 €”).'] + $switch('UC_SHOW_LABEL'),
            ],
            'submit' => ['title' => 'Spremi'],
        ]];

        return $helper->generateForm([$form]);
    }

    // ---- hooks: catalogue out -----------------------------------------------

    public function hookActionProductAdd(array $params): void
    {
        $this->resync((int) ($params['id_product'] ?? 0));
    }

    public function hookActionProductUpdate(array $params): void
    {
        $this->resync((int) ($params['id_product'] ?? 0));
    }

    public function hookActionProductDelete(array $params): void
    {
        if (! $this->autoSync()) {
            return;
        }
        $product = $params['product'] ?? null;
        if (! $product instanceof Product) {
            $product = new Product((int) ($params['id_product'] ?? 0), true, (int) Configuration::get('PS_LANG_DEFAULT'));
        }
        UcSync::onProductDeleted($product);
    }

    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        $this->resync((int) ($params['object']->id_product ?? 0));
    }

    public function hookActionObjectCombinationUpdateAfter(array $params): void
    {
        $this->resync((int) ($params['object']->id_product ?? 0));
    }

    public function hookActionObjectCombinationDeleteAfter(array $params): void
    {
        if ($this->autoSync() && isset($params['object'])) {
            UcSync::onCombinationDeleted((int) $params['object']->id_product, (int) $params['object']->id);
        }
    }

    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->resync((int) ($params['object']->id_product ?? 0));
    }

    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->resync((int) ($params['object']->id_product ?? 0));
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->resync((int) ($params['object']->id_product ?? 0));
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        $this->resync((int) ($params['id_product'] ?? 0));
    }

    private function resync(int $idProduct): void
    {
        if ($idProduct > 0 && $this->autoSync()) {
            UcSync::onProductSaved($idProduct);
        }
    }

    private function autoSync(): bool
    {
        return (bool) Configuration::get('UC_AUTO_SYNC');
    }

    // ---- hook: the label under the price ------------------------------------

    /** @param array{product: mixed, type?: string, hook_origin?: string} $params */
    public function hookDisplayProductPriceBlock(array $params): string
    {
        if (($params['type'] ?? '') !== 'after_price' || ! (bool) Configuration::get('UC_SHOW_LABEL')) {
            return '';
        }
        $product = $params['product'] ?? null;
        $idProduct = (int) self::field($product, 'id_product');
        if ($idProduct <= 0) {
            return '';
        }
        $idAttribute = (int) self::field($product, 'id_product_attribute');
        $label = UcDisplay::label(UcMapper::externalId($idProduct, $idAttribute));
        if ($label === null && $idAttribute > 0) {
            $label = UcDisplay::label(UcMapper::externalId($idProduct));
        }
        if ($label === null) {
            return '';
        }
        $this->context->smarty->assign(['uc_label' => $label]);

        return $this->display(__FILE__, 'label.tpl');
    }

    /** A field of the product the hook hands over: an array, a ProductLazyArray or an object. */
    private static function field($product, string $name)
    {
        if (is_array($product) || $product instanceof ArrayAccess) {
            return isset($product[$name]) ? $product[$name] : null;
        }
        if (is_object($product)) {
            return $product->$name ?? null;
        }

        return null;
    }
}
