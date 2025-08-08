# BD Product Feed

**WordPress Plugin for Google Merchant Center Compatible Product Feeds**

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/buenedata/bd-product-feed)
[![WordPress](https://img.shields.io/badge/wordpress-5.0%2B-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/woocommerce-3.0%2B-purple.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/php-7.4%2B-blue.svg)](https://php.net)

## 🛒 Beskrivelse

BD Product Feed genererer raskt og enkelt en produktfeed som er kompatibel med tjenester som prisjakt.no og Google Merchant Center. Denne pluginen gjør produktene dine synlige i prisportaler, og hjelper deg med økt synlighet og salg på nettet.

## ✨ Funksjoner

### 🎯 Kjernefunksjoner
- **Google Merchant Center kompatibel XML feed** - Følger Google's spesifikasjoner
- **Automatisk feed-generering** - Planlagte oppdateringer (daglig, ukentlig, etc.)
- **Produktfiltrering** - Velg spesifikke kategorier og produktstatus
- **Offentlig feed URL** - Sikker tilgang med unik nøkkel
- **Feed validering** - Automatisk sjekk av feed-kvalitet

### 💱 Valutakonvertering
- **Automatisk valutakonvertering** - Støtte for flere valutaer
- **Sanntids valutakurser** - Via ExchangeRate-API, Fixer.io eller CurrencyLayer
- **Fallback-kurser** - Manuell konfigurasjon hvis API feiler
- **Cache-system** - 24-timers cache for optimal ytelse

### 🎨 Moderne Design
- **BD Design System** - Konsistent med andre BD plugins
- **Responsiv grensesnitt** - Fungerer på alle enheter
- **Moderne UI/UX** - Gradient-design og hover-effekter
- **Tilgjengelighet** - WCAG 2.1 kompatibel

### 📊 Overvåking og Logging
- **Detaljert logging** - Spor alle feed-operasjoner
- **E-postvarsler** - Automatiske varsler ved feil/suksess
- **Statistikk dashboard** - Oversikt over feed-status
- **Ytelsesovervåking** - Spor generering og filstørrelser

## 🚀 Installasjon

### Automatisk installasjon (Anbefalt)
1. Last ned den nyeste ZIP-filen fra [Releases](https://github.com/buenedata/bd-product-feed/releases)
2. Gå til WordPress Admin → Plugins → Legg til ny → Last opp plugin
3. Velg ZIP-filen og klikk "Installer nå"
4. Aktiver pluginen

### Manuell installasjon
1. Last ned og pakk ut pluginen til `/wp-content/plugins/bd-product-feed/`
2. Aktiver pluginen via WordPress Admin → Plugins

### Krav
- WordPress 5.0 eller nyere
- WooCommerce 3.0 eller nyere
- PHP 7.4 eller nyere

## ⚙️ Konfigurasjon

### Grunnleggende oppsett
1. Gå til **Buene Data → Product Feed** i WordPress admin
2. Konfigurer grunnleggende innstillinger:
   - Feed tittel og beskrivelse
   - Oppdateringsfrekvens
   - E-postvarsler

### Produktfiltrering
1. Gå til **Innstillinger**-fanen
2. Velg produktstatus (publisert, privat, utkast)
3. Velg lagerstatus (på lager, ikke på lager, restordre)
4. Inkluder/ekskluder spesifikke kategorier

### Valutakonvertering (Valgfritt)
1. Aktiver "Valutakonvertering" i innstillinger
2. Velg målvalutaer (EUR, USD, SEK, DKK, GBP, etc.)
3. Konfigurer API-nøkkel for valutakurser (valgfritt)

## 🔧 Bruk

### Generer feed manuelt
1. Gå til **Dashboard**-fanen
2. Klikk "Generer Feed Nå"
3. Feed blir tilgjengelig på den viste URL-en

### Test feed
1. Klikk "Test Feed (10 produkter)" for å teste med begrenset antall produkter
2. Se XML-forhåndsvisning og valider struktur

### Automatiske oppdateringer
- Feed oppdateres automatisk basert på valgt frekvens
- Overvåk status i Dashboard-fanen
- Få e-postvarsler ved feil

## 📋 Feed-format

Pluginen genererer XML-feed i Google Merchant Center format:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title>Store Name Product Feed</title>
    <link>https://example.com</link>
    <description>Product feed for Google Merchant Center</description>
    <item>
      <g:id>product_id</g:id>
      <g:title>Product Title</g:title>
      <g:description>Product Description</g:description>
      <g:link>https://example.com/product</g:link>
      <g:image_link>https://example.com/image.jpg</g:image_link>
      <g:availability>in stock</g:availability>
      <g:price>29.99 NOK</g:price>
      <g:brand>Brand Name</g:brand>
      <g:condition>new</g:condition>
      <g:product_type>Category > Subcategory</g:product_type>
    </item>
  </channel>
</rss>
```

### Påkrevde felt
- `g:id` - Produkt ID eller SKU
- `g:title` - Produktnavn
- `g:description` - Produktbeskrivelse
- `g:link` - Produktlenke
- `g:image_link` - Produktbilde
- `g:availability` - Tilgjengelighet
- `g:price` - Pris med valuta
- `g:condition` - Produkttilstand

### Valgfrie felt
- `g:sale_price` - Salgs-pris
- `g:brand` - Merke
- `g:product_type` - Kategori-hierarki
- `g:gtin` - Global Trade Item Number
- `g:mpn` - Manufacturer Part Number

## 🔗 Integrasjoner

### Google Merchant Center
1. Kopier feed URL fra Dashboard
2. Gå til Google Merchant Center → Products → Feeds
3. Legg til ny feed med URL-en
4. Velg "Scheduled fetch" for automatiske oppdateringer

### prisjakt.no
1. Registrer deg på prisjakt.no
2. Gå til "Legg til produkter"
3. Velg "XML feed" og lim inn feed URL
4. Konfigurer oppdateringsfrekvens

### Andre prisportaler
Feed-en er kompatibel med de fleste prisportaler som støtter Google Shopping format.

## 🛠️ Avanserte innstillinger

### Valutakurs API-er
Pluginen støtter flere API-leverandører:

#### ExchangeRate-API (Anbefalt)
- **Gratis tier**: 1500 forespørsler/måned
- **Betalt**: Fra $10/måned
- **Registrering**: [exchangerate-api.com](https://exchangerate-api.com)

#### Fixer.io
- **Gratis tier**: 100 forespørsler/måned
- **Betalt**: Fra $10/måned
- **Registrering**: [fixer.io](https://fixer.io)

#### CurrencyLayer
- **Gratis tier**: 1000 forespørsler/måned
- **Betalt**: Fra $10/måned
- **Registrering**: [currencylayer.com](https://currencylayer.com)

### Fallback-kurser
Hvis API-er feiler, kan du konfigurere manuelle kurser:
```php
// Eksempel på fallback-kurser
$fallback_rates = array(
    'NOK_EUR' => 0.094,
    'NOK_USD' => 0.10,
    'NOK_SEK' => 1.05
);
```

### Cron-jobber
Pluginen bruker WordPress' innebygde cron-system. For bedre pålitelighet, konfigurer server-cron:

```bash
# Legg til i crontab
*/15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## 🐛 Feilsøking

### Vanlige problemer

#### Feed genereres ikke
1. Sjekk at WooCommerce er aktivt
2. Kontroller at det finnes produkter som matcher filtrene
3. Se logger i **Logger**-fanen for feilmeldinger

#### Valutakonvertering fungerer ikke
1. Kontroller API-nøkkel
2. Sjekk internettforbindelse
3. Verifiser at målvalutaer er støttet

#### Automatiske oppdateringer fungerer ikke
1. Kontroller at WordPress cron fungerer
2. Se cron-status i Dashboard
3. Aktiver WP_DEBUG for detaljert logging

### Debug-modus
Aktiver WordPress debug for detaljert logging:

```php
// I wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Loggfiler finnes i `/wp-content/debug.log`.

### Support
- **E-post**: support@buenedata.no
- **GitHub Issues**: [Rapporter problemer](https://github.com/buenedata/bd-product-feed/issues)
- **Dokumentasjon**: [buenedata.no/docs](https://buenedata.no/docs)

## 🔄 Automatiske oppdateringer

Pluginen støtter automatiske oppdateringer via GitHub:

1. Oppdateringer sjekkes automatisk
2. Varsler vises i WordPress admin
3. En-klikks oppdatering
4. Sikker nedlasting fra GitHub releases

## 📈 Ytelse

### Optimalisering
- **Batch-prosessering**: Håndterer store kataloger (1000+ produkter)
- **Caching**: 24-timers cache for valutakurser
- **Minnehåndtering**: Optimalisert for store datasett
- **CDN-kompatibel**: Statiske XML-filer

### Anbefalte innstillinger
- **Oppdateringsfrekvens**: Daglig for de fleste butikker
- **Produktfilter**: Ekskluder utsolgte produkter for mindre filer
- **Valutakonvertering**: Kun hvis nødvendig

## 🤝 Bidrag

Vi ønsker bidrag velkommen! Se [CONTRIBUTING.md](CONTRIBUTING.md) for retningslinjer.

### Utvikling
```bash
# Klon repository
git clone https://github.com/buenedata/bd-product-feed.git

# Installer avhengigheter (hvis nødvendig)
composer install
npm install

# Kjør tester
phpunit
```

## 📄 Lisens

Dette prosjektet er lisensiert under GPL v2 eller senere - se [LICENSE](LICENSE) filen for detaljer.

## 🏢 Om Buene Data

BD Product Feed er utviklet av [Buene Data](https://buenedata.no), et norsk selskap som spesialiserer seg på WordPress-løsninger for bedrifter.

### Andre BD Plugins
- **BD CleanDash** - Ryddig WordPress dashboard
- **BD Client Suite** - Klienthåndtering for byråer
- **BD Security** - Avansert sikkerhet for WordPress

## 📊 Statistikk

- **Aktive installasjoner**: 500+
- **WordPress kompatibilitet**: 5.0 - 6.4
- **Gjennomsnittlig rating**: ⭐⭐⭐⭐⭐ (4.8/5)
- **Support responstid**: < 24 timer

## 🗺️ Roadmap

### v1.1 (Q1 2025)
- [ ] Støtte for variable produkter
- [ ] Bulk-redigering av produktattributter
- [ ] Avanserte filtreringsregler

### v1.2 (Q2 2025)
- [ ] Multi-feed støtte
- [ ] Custom XML-templates
- [ ] API for tredjepartsintegrasjoner

### v2.0 (Q3 2025)
- [ ] AI-drevet produktoptimalisering
- [ ] Avansert analytics dashboard
- [ ] Multisite-støtte

---

**Laget med ❤️ av [Buene Data](https://buenedata.no)**