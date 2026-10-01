# Referenční příručka

## Stránky a adresy

| Adresa | Název routy | Přístup | Zobrazuje |
| --- | --- | --- | --- |
| `/gal/area/{area}/ap/{ap}/pub/timeline` | `gallery.public.timeline` | všichni | veřejné fotky AP |
| `/gal/area/{area}/ap/{ap}/priv/timeline` | `gallery.private.timeline` | přihlášení uživatelé | fotky z dokumentace AP |
| `/timeline` | `timeline` | všichni | fotky všech AP uvedených v Userdb |

Zobrazení mřížky (`…/pub`, `…/priv`) se nezměnila. Přepínač **Mřížka | Časová osa** propojuje
každou mřížku s její časovou osou a zpět.

### Parametry v adrese

| Parametr | Stránky | Hodnoty | Účinek |
| --- | --- | --- | --- |
| `from` | všechny časové osy | `RRRR-MM` | Začít nejnovější fotkou daného měsíce a pokračovat do minulosti. Neplatné hodnoty se ignorují. |
| `cursor` | všechny časové osy | neprůhledná hodnota | Další stránka; vytváří ji odkaz **Starší**. |
| `priv` | `/timeline` | `1` | Zahrnout i fotky z dokumentace. U nepřihlášených se ignoruje. |

Požadavky s hlavičkou `X-Requested-With: XMLHttpRequest` dostanou jen HTML další stránky
(měsíční sekce a další odkaz **Starší**), ne celou stránku. Skript stránky to využívá
k načítání starších fotek při posouvání.

### Chování stránky

| Položka | Hodnota |
| --- | --- |
| Fotek na stránku | 60 (`Timeline::PER_PAGE`) |
| Řazení | `sort_at` sestupně, pak `id` sestupně |
| Nadpis měsíce | český název měsíce a rok, např. **Květen 2024**, a celkový počet fotek v měsíci |
| Nových souborů zaindexovaných při jednom načtení časové osy AP | nejvýše 200 (`TimelineController::RECONCILE_LIMIT`) |
| Ovládání pro správce (nahrávání, mazání) | jen na časových osách AP, pro uživatele s rolí z `GALLERY_ADMIN_ROLES` |

## Určení data

Každá fotka se zařadí podle prvního dostupného z těchto zdrojů:

| # | Zdroj | Odkud se čte | Podmínky |
| --- | --- | --- | --- |
| 1 | Datum pořízení | EXIF `DateTimeOriginal`, jinak `DateTimeDigitized` | JPEG nebo TIFF; formát `YYYY:MM:DD HH:MM:SS`; věrohodné |
| 2 | Datum z prohlížeče | `File.lastModified`, odeslané při nahrání | jen nová nahrání; věrohodné |
| 3 | Datum souboru | čas poslední změny uloženého souboru | vždy k dispozici |

- **Věrohodné** znamená od 1. ledna 1990 nejvýše do jednoho dne po aktuálním čase.
- **Ignoruje se:** EXIF `DateTime` (čas poslední úpravy); zástupné hodnoty jako
  `0000:00:00 00:00:00`; hodnoty, které po převodu nesedí, například 13. měsíc.
- **PHP nepřečte:** PNG, GIF a WebP nenesou EXIF, který by PHP umělo přečíst, takže vždy
  použijí zdroj 2 nebo 3.

Všechna data se ukládají jako místní čas v pásmu `GALLERY_TIMEZONE`. Hodnoty z EXIF se
použijí beze změny, časové značky (zdroje 2 a 3) se převedou.

## Konfigurace

| Proměnná | Výchozí hodnota | Význam |
| --- | --- | --- |
| `GALLERY_TIMEZONE` | `Europe/Prague` | Časové pásmo pro data na časové ose a hranice měsíců (`services.gallery.timezone`). |

Je vyžadováno rozšíření PHP `exif` (`ext-exif` v `composer.json`).

## Příkaz `gallery:index`

```
php artisan gallery:index [--area=ID] [--ap=ID] [--dry-run]
```

Uvede index do souladu se soubory na disku, a to pro každý adresář galerie na disku a každou
galerii, která má záznamy v indexu:

- **Přidá** obrázky (`jpg`, `jpeg`, `png`, `gif`, `webp`), které nemají záznam.
- **Odebere** záznamy, jejichž soubor zmizel.
- **Přeskočí** `thumbs/`, soubory v koši (`_trashed_…`) a ostatní přípony.
- **Nechá beze změny** záznamy existujících souborů, takže se jejich data znovu nenačítají.

| Volba | Účinek |
| --- | --- |
| `--area=ID` | Jen galerie této oblasti. |
| `--ap=ID` | Jen galerie tohoto AP. |
| `--dry-run` | Nic nezapisovat. Navíc vypsat, jak se fotky, které by se přidaly, rozloží do měsíců. |

Výstup: počet přidaných a odebraných fotek a kolik z přidaných má datum pořízení z EXIF.
Návratový kód `0`.

**Plánování:** denně, bez souběžných běhů (`routes/console.php`). Vyžaduje záznam v cronu pro
`php artisan schedule:run`.

## Tabulka indexu `gallery_images`

| Sloupec | Typ | Poznámka |
| --- | --- | --- |
| `id` | bigint | |
| `area_id`, `ap_id` | unsigned int | |
| `visibility` | string(4) | `pub` nebo `priv` |
| `filename` | string | jak je uložen na disku |
| `taken_at` | datetime, nullable | zdroj 1 |
| `client_modified_at` | datetime, nullable | zdroj 2 |
| `uploaded_at` | datetime | zdroj 3 |
| `sort_at` | datetime | první vyplněná ze tří hodnot; nastavuje se při uložení |
| `sort_month` | char(7) | `RRRR-MM` z `sort_at`; nastavuje se při uložení |
| `created_at`, `updated_at` | timestamps | |

Indexy:

- unikátní `(visibility, area_id, ap_id, filename)`
- `(visibility, area_id, ap_id, sort_at, id)`
- `(visibility, sort_at, id)`

## Kdy se index mění

| Událost | Dopad na index |
| --- | --- |
| Dokončené nahrání | vytvoří se záznam; chyba indexování se zaloguje a nahrání přesto uspěje |
| Fotka přesunuta do koše | záznam se smaže |
| Otevření časové osy AP (první stránka) | galerie se srovná s diskem: přidá se až 200 nových souborů, odeberou se záznamy chybějících |
| `gallery:index` | srovnají se všechny (nebo vybrané) galerie |

## Mapa kódu

| Část | Umístění |
| --- | --- |
| Čtení dat | `app/Services/ImageDate.php` |
| Synchronizace indexu | `app/Services/GalleryIndex.php` |
| Stránkování a seskupení po měsících | `app/Services/Timeline.php` |
| Stránky | `app/Http/Controllers/TimelineController.php` |
| Šablony | `resources/views/gallery/timeline.blade.php`, `resources/views/timeline/`, `resources/views/components/timeline/` |
| Posouvání, sledování adresy, skok na měsíc | `resources/js/timeline.js` |
| Příkaz | `app/Console/Commands/GalleryIndexCommand.php` |
