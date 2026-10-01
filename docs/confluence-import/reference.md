# Referenční příručka

## Stránky a adresy

Všechny stránky jsou jen pro správce galerie (role z `GALLERY_ADMIN_ROLES`).

| Adresa | Metoda | Název routy | Účel |
| --- | --- | --- | --- |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import` | GET | `confluence.import.create` | formulář; s `?url=` náhled stránky |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import` | POST | `confluence.import.store` | spuštění importu (`url`) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import/{import}` | GET | `confluence.import.show` | průběh a výsledek importu |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/description` | POST | `gallery.description` | ruční směr (`filename`, `heading`) nebo typ scény (`filename`, `scene`, `score`) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/scene-queue` | GET | `gallery.scene-queue` | fotky bez typu scény (JSON); `?import=` jen fotky daného importu |

Omezení počtu požadavků: náhled 30 za minutu, spuštění importu 10 za minutu.

## Podporované adresy

Adresa musí vést na server z `CONFLUENCE_BASE_URL`. Jiné servery se nikdy nekontaktují.

| Tvar | Příklad |
| --- | --- |
| Stránka | `https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+výhled` |
| Klasický odkaz | `https://doc.hkfree.org/pages/viewpage.action?pageId=22020482` |
| Podle názvu | `https://doc.hkfree.org/display/fotogalerie/Foto+výhled` (dohledá se podle názvu) |

## Které fotky se importují

| Obsah stránky | Výsledek |
| --- | --- |
| Makro **Galerie** (`gallery`) | všechny obrázkové přílohy stránky, s ohledem na parametry `include`, `exclude`, `sort` (`name`, `date`) a `reverse` |
| Vložený obrázek z této stránky | ta příloha, v pořadí na stránce |
| Obrázek z jiné stránky | přeskočí se („obrázek z jiné stránky“) |
| Obrázek z externí adresy | přeskočí se („externí obrázek“); nestahuje se |
| Příloha jiného formátu než JPEG, PNG, GIF, WebP | přeskočí se („nepodporovaný formát“) |
| Příloha větší než 50 MB | přeskočí se („soubor je větší než 50 MB“) |

Fotka se považuje za již naimportovanou, pokud stejná příloha **ve stejné verzi** už byla
úspěšně naimportovaná do stejné galerie.

## Průběh importu

| Stav | Význam |
| --- | --- |
| Čeká na spuštění | úloha je ve frontě; spustí se do minuty |
| Importuji N / M | fotky se stahují a ukládají |
| Hotovo / Hotovo, s chybami | konec; chybné fotky jsou vypsané s důvodem |
| Import se nezdařil | úloha selhala celá; spusťte import znovu |

| Pravidlo | Hodnota |
| --- | --- |
| Souběžné importy | nejvýše jeden na galerii |
| Kontrola místa | volné místo musí pokrýt velikost importu plus 1 GB |
| Délka jednoho běhu úlohy | 30 s, pak pokračuje další běh (timeout 45 s, opakování 3×) |
| Datum fotky | EXIF datum pořízení, jinak datum nahrání přílohy do Confluence, jinak čas stažení |

## Popis fotky

Popis se skládá při zobrazení stránky z uložených údajů:

> Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km). Výhled na zástavbu (rozpoznáno automaticky).

| Část | Zdroj | Podmínka |
| --- | --- | --- |
| „Výhled z AP Brno“ | AP galerie | fotka pořízená z AP: bez polohy v EXIF, nebo do 500 m od AP |
| „Výhled“ | — | poloha z EXIF je dál než 500 m od AP |
| „na S (0°)“ | směr pohledu | směr je známý |
| „směrem AP …“ | souřadnice AP z Userdb | AP do ±25° od směru a do 15 km, nejbližší první, nejvýše 3 |
| „Výhled na zástavbu (rozpoznáno automaticky)“ | typ scény | jistota modelu aspoň 0,6 |

Bez směru a bez dostatečně jistého typu scény fotka popis nemá. Popis je zároveň alternativním
textem obrázku.

### Odkud se bere směr

V pořadí přednosti (ručně nastavený směr se nikdy nepřepíše):

| Zdroj | `heading_source` |
| --- | --- |
| Ručně, kompasem na dlaždici | `manual` |
| EXIF `GPSImgDirection` | `exif` |
| Název souboru | `filename` |

### Směr z názvu souboru

| Zápis | Směr |
| --- | --- |
| Azimut se znakem stupně: `128°` | 128° |
| `sever`, `severni` | 0° |
| `severovychod`, `severo vychod`, `SV` | 45° |
| `vychod`, `vychodni` | 90° |
| `jihovychod`, `jiho vychod`, `JV` | 135° |
| `jih`, `jizni` | 180° |
| `jihozapad`, `JZ` | 225° |
| `zapad`, `zapadni` | 270° |
| `severozapad`, `SZ` | 315° |

Slova se rozpoznávají s diakritikou i bez ní a jen jako celá slova (`Jihlava` směr není).
Zkratky musí být velkými písmeny. Jiné tvary (`od jižního stožáru`) se nepočítají — popisují
místo, ne směr.

### Typy scén

| Klíč | Text |
| --- | --- |
| `zastavba` | Výhled na zástavbu |
| `sidliste` | Výhled na sídliště |
| `krajina` | Výhled do krajiny |
| `les` | Výhled na les |
| `anteny` | Antény na stožáru |
| `technika` | Rozvaděč / technika |
| `strecha` | Střecha |

Model: `Xenova/clip-vit-base-patch32` (CLIP), běží v prohlížeči správce přes Transformers.js.
Knihovna a model se načtou až po kliknutí na **Rozpoznat typ scény**.

## Konfigurace

| Proměnná | Výchozí hodnota | Význam |
| --- | --- | --- |
| `CONFLUENCE_BASE_URL` | `https://doc.hkfree.org` | jediný server, se kterým import komunikuje |
| `CONFLUENCE_TOKEN` | prázdné | volitelný osobní přístupový token pro omezené prostory |
| `GALLERY_SCENE_MODEL_URL` | prázdné | volitelné vlastní zrcadlo souborů modelu |

Souřadnice AP se čtou z pole `gps` v odpovědi Userdb `/api/areas` (formát `50.22795,15.834133`);
chybějící nebo neplatné hodnoty se ignorují.

## Data

| Tabulka | Obsah |
| --- | --- |
| `confluence_imports` | jeden běh importu: cílová galerie, stránka, stav, chyba |
| `confluence_import_items` | jedna příloha: id a verze, název, velikost, uložený název, stav, důvod |
| `gallery_image_descriptions` | údaje pro popis: poloha z EXIF, směr a jeho zdroj, typ scény a jistota |

Při přesunu fotky do koše se její údaje pro popis smažou.
