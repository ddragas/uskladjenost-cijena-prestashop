# Usklađenost cijena za PrestaShop

PrestaShop 8 (i 1.7.6+) modul `uskladjenostcijena` koji povezuje trgovinu sa servisom [Usklađenost cijena](https://uskladjenost-cijena.com): sidrene cijene i javni strojno čitljiv cjenik po NN 101/2026.

Što radi:

- **Sinkronizacija na spremanje.** Svaka promjena proizvoda, kombinacije, posebne cijene ili zalihe (hookovi `actionProductAdd/Update/Delete`, `actionObjectCombination*After`, `actionObjectSpecificPrice*After`, `actionUpdateQuantity`) odmah ide u servis kroz `PUT /items/{id}` i `POST /prices/by-external/{id}`. Proizvod je artikl s vanjskim id-om `12`; svaka kombinacija je zaseban artikl `12-34` s nazivom „Proizvod – Veličina - L, Boja - crna”. Cijena je s PDV-om; posebna cijena (sniženje) ispod redovne je akcija: redovna ostaje `price`, plaćena je snižena, s datumom isteka ako ga posebna cijena ima. Virtualni proizvodi su usluge. Obrisan proizvod ili kombinacija deaktivira se u servisu.
- **Sidrena cijena uz cijenu.** Hook `displayProductPriceBlock` (tip `after_price`) ispisuje tekst koji servis izračuna (`GET /compliance/by-external/{id}` na kanalu webshopa), za proizvod ili odabranu kombinaciju. Odgovor se kešira 6 sati u datotečnom kešu (`var/cache/.../uskladjenostcijena/`) i briše kod svake sinkronizacije tog artikla.
- **Cijeli katalog na klik** (Moduli → Usklađenost cijena → Konfiguriraj → *Pošalji sve proizvode i cijene*): serije od 200 artikala kroz `POST /items/bulk` i `POST /price-events/bulk`.

Bez Composera na produkciji: modul koristi curl. Zahtijeva PHP 7.4+.

## Postavljanje

1. U aplikaciji: trgovac → **Kanali** → dodajte kanal tipa *webshop* (šifra npr. `WEB`); **API pristup** → novi token s opsezima `catalog:write`, `prices:write`, `compliance:read`.
2. Instalirajte modul. Mapa modula **mora** se zvati `uskladjenostcijena`:

```bash
cd /putanja/do/prestashopa/modules
git clone https://github.com/ddragas/uskladjenost-cijena-prestashop.git uskladjenostcijena
# ili ZIP: mapa uskladjenostcijena/ s ovim datotekama, učitana kroz Moduli → Učitaj modul
```

3. Moduli → **Usklađenost cijena** → Instaliraj → Konfiguriraj: token, ID trgovca, šifra kanala. Spremite i kliknite **Pošalji sve proizvode i cijene**.

Javni cjenik za webshop servis od tada gradi i objavljuje sam; snippet gumba „Cjenik” za temu je u aplikaciji pod Objava cjenika.

Napomena za teme: `displayProductPriceBlock` s tipom `after_price` pozivaju classic tema i većina tema na njoj (stranica proizvoda i popisi). Ako vaša tema taj hook ne poziva, dodajte u `product-prices.tpl` iza cijene: `{hook h='displayProductPriceBlock' product=$product type='after_price'}`.

## Razvoj

```bash
composer install && vendor/bin/phpunit   # mapper je čisti PHP i testira se bez PrestaShopa
php -l uskladjenostcijena.php            # sintaksa modula
```

Licenca MIT, © Info Media d.o.o.

## Ostali SDK-ovi i dodaci

Ista obitelj za isti API, svaki u svom repozitoriju:

- [uskladjenost-cijena-php](https://github.com/ddragas/uskladjenost-cijena-php) – PHP SDK (Composer `infomedia/uskladjenost-cijena-php`)
- [uskladjenost-cijena-python](https://github.com/ddragas/uskladjenost-cijena-python) – Python SDK (`uskladjenost-cijena`)
- [uskladjenost-cijena-js](https://github.com/ddragas/uskladjenost-cijena-js) – JavaScript/TypeScript SDK (`@uskladjenost-cijena/sdk`)
- [uskladjenost-cijena-woocommerce](https://github.com/ddragas/uskladjenost-cijena-woocommerce) – WooCommerce dodatak
- [uskladjenost-cijena-shopify](https://github.com/ddragas/uskladjenost-cijena-shopify) – Shopify custom app
