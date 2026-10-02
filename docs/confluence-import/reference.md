# Referenční příručka

## Stránky a adresy

Všechny stránky jsou jen pro správce galerie (role z `GALLERY_ADMIN_ROLES`).

| Adresa | Metoda | Název routy | Účel |
| --- | --- | --- | --- |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import` | GET | `confluence.import.create` | formulář; s `?url=` náhled stránky |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import` | POST | `confluence.import.store` | spuštění importu (`url`) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/import/{import}` | GET | `confluence.import.show` | průběh a výsledek importu |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/description` | POST | `gallery.description` | směr (`filename`, `heading`, `source` = `manual` nebo `similarity`), typ scény (`scene`, `score`), zakrytí výhledu (`obstruction`, `obstruction_kind`) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/embedding` | POST | `gallery.embedding` | otisk fotky (`filename`, `model`, `vector` = base64 z float32) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/suggestions` | POST | `gallery.suggestions.confirm` | potvrzení všech návrhů směru galerie |
| `/pokryti` | GET | `coverage` | přehled pokrytí výhledů všech AP (veřejné galerie) |
| `/gal/area/{area}/ap/{ap}/{pub\|priv}/analysis-queue` | GET | `gallery.analysis-queue` | fotky, kterým něco chybí (JSON); `?import=` jen fotky importu, `?files[]=` jen dané fotky |

Omezení počtu požadavků: náhled 30 za minutu, spuštění importu 10 za minutu.

## Podporované adresy

Adresa musí vést na server z `CONFLUENCE_BASE_URL`. Jiné servery se nikdy nekontaktují.

| Tvar | Příklad |
| --- | --- |
| Stránka | `https://doc.hkfree.org/spaces/fotogalerie/pages/22020482/Foto+výhled` |
| Blogový příspěvek | `https://doc.hkfree.org/spaces/fotogalerie/blog/2012/08/12/36306945/…` |
| Klasický odkaz | `https://doc.hkfree.org/pages/viewpage.action?pageId=22020482` |
| Podle názvu | `https://doc.hkfree.org/display/fotogalerie/Foto+výhled` (dohledá se podle názvu) |

## Které fotky se importují

| Obsah stránky | Výsledek |
| --- | --- |
| Makro **Galerie** (`gallery`) | všechny obrázkové přílohy stránky, s ohledem na parametry `include`, `exclude`, `sort` (`name`, `date`) a `reverse` |
| Vložený obrázek z této stránky | ta příloha, v pořadí na stránce |
| Obrázek jen v přílohách (stránka bez makra Galerie) | přidá se za zobrazené, seřazený podle názvu |
| Obrázek z jiné stránky | přeskočí se („obrázek z jiné stránky“) |
| Obrázek z externí adresy | přeskočí se („externí obrázek“); nestahuje se |
| Příloha jiného formátu než JPEG, PNG, GIF, WebP | přeskočí se („nepodporovaný formát“); rozhoduje typ souboru, ne přípona |
| Příloha bez přípony (`Pohled směr Jih`) | uloží se s příponou podle typu (`Pohled směr Jih.jpg`) |
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
| Délka jednoho běhu úlohy | nové fotky začíná 20 s, pak pokračuje další běh (stažení jedné fotky nejvýše 50 s, timeout úlohy 85 s, opakování 3×) |
| Fotka, jejíž zpracování dvakrát nedoběhlo | označí se jako chybná a import pokračuje dalšími fotkami |
| Datum fotky | EXIF datum pořízení, jinak datum nahrání přílohy do Confluence, jinak čas stažení |

## Popis fotky

Popis se skládá při zobrazení stránky z uložených údajů:

> Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km). Výhled na zástavbu, stromy zakrývají asi 20 % výhledu (rozpoznáno automaticky).

| Část | Zdroj | Podmínka |
| --- | --- | --- |
| „Výhled z AP Brno“ | AP galerie | fotka pořízená z AP: bez polohy v EXIF, nebo do 500 m od AP |
| „Výhled“ | — | poloha z EXIF je dál než 500 m od AP |
| „na S (0°)“ | směr pohledu | směr je známý |
| „směrem AP …“ | souřadnice AP z Userdb | AP do ±25° od směru a do 15 km, nejbližší první, nejvýše 3 |
| „Výhled na zástavbu“ | typ scény | jistota modelu aspoň 0,6 |
| „bez překážek“ / „stromy zakrývají asi 20 % výhledu“ | zakrytí výhledu | fotka má aspoň 10 % oblohy; ne u typů antény, rozvaděč, střecha; pod 10 % = bez překážek; zaokrouhleno na 5 % |

Bez směru a bez dostatečně jistého typu scény fotka popis nemá. Popis je zároveň alternativním
textem obrázku.

### Odkud se bere směr

V pořadí přednosti (ručně nastavený směr se nikdy nepřepíše):

| Zdroj | `heading_source` |
| --- | --- |
| Ručně, kompasem na dlaždici | `manual` |
| Potvrzený návrh podle podobné fotky | `similarity` |
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

### Návrhy směru

| Pravidlo | Hodnota |
| --- | --- |
| Porovnávané fotky | všechny fotky téhož AP (veřejné i Dokumentace) se známým směrem |
| Podobnost | kosinová podobnost otisků (model DINOv2-small) |
| Nejmenší podobnost | 0,7 |
| Náskok před nejpodobnější fotkou s jiným směrem (víc než 25° jinak) | aspoň 0,05 |
| Zobrazení | jen správcům; do popisu se směr dostane až po potvrzení |

### Mapa u fotky

Fotka se známým směrem a známým místem pořízení (poloha z EXIF, jinak souřadnice AP) má vpravo
dole špendlík. Po kliknutí se otevře malá mapa (zhruba 1,2 × 1,2 km):

| Prvek | Význam |
| --- | --- |
| Tmavý bod | místo pořízení |
| Zelená výseč | směr pohledu (±25°) |
| Oranžové body | AP v zorném poli; AP mimo mapu jsou na jejím okraji se šipkou „→“ |

Mapové dlaždice jsou z OpenStreetMap (nebo ze serveru v `GALLERY_MAP_TILES`) a načítají se až
po otevření mapy. Prohlížeč při tom serveru dlaždic sdělí jen adresu galerie, ne konkrétní
stránku.

### Pokrytí směrů

| Pravidlo | Hodnota |
| --- | --- |
| Směry | 8 výsečí po 45° (S, SV, V, JV, J, JZ, Z, SZ); fotka patří do nejbližší |
| Počítané fotky | fotky galerie se známým směrem, které jsou v indexu časové osy (ne fotky v koši) |
| Aktuální | nejnovější fotka směru je mladší než 3 roky (podle data na časové ose) |
| Sousední AP | AP se souřadnicemi do 15 km, v dané výseči, nejbližší první |
| Zobrazení u galerie | správcům vždy, ostatním až když má aspoň jedna fotka směr |
| Přehled všech AP | jen správcům; řazeno podle počtu pokrytých, pak aktuálních směrů |

### Zakrytí výhledu

Model rozdělí fotku na oblasti (obloha, strom, budova, zeď, plot…). Pro každý sloupec obrázku
se najde, kde končí obloha; „horizont“ fotky je místo, kam dosahuje většina sloupců. Sloupec je
zakrytý, když nad horizont výrazně (o víc než 8 % výšky) vyčnívá strom, keř, zeď, plot nebo
sloup. Výsledek je podíl zakrytých sloupců; druh je „stromy“, když tvoří aspoň polovinu.

### Modely

| Účel | Model | Velikost |
| --- | --- | --- |
| Typ scény | `Xenova/clip-vit-base-patch32` (CLIP) | asi 90 MB |
| Otisk pro návrhy směru | `onnx-community/dinov2-small` (DINOv2, 8bitový) | asi 24 MB |
| Zakrytí výhledu | `Xenova/segformer-b0-finetuned-ade-512-512` (SegFormer, ADE20K, 8bitový) | asi 4 MB |

Modely běží v prohlížeči správce přes Transformers.js. Knihovna a modely se načtou až po
kliknutí na **Analyzovat fotky** (nebo po nahrání s volbou **Po nahrání fotky analyzovat**).

## Konfigurace

| Proměnná | Výchozí hodnota | Význam |
| --- | --- | --- |
| `CONFLUENCE_BASE_URL` | `https://doc.hkfree.org` | jediný server, se kterým import komunikuje |
| `CONFLUENCE_TOKEN` | prázdné | volitelný osobní přístupový token pro omezené prostory |
| `GALLERY_SCENE_MODEL_URL` | prázdné | volitelné vlastní zrcadlo souborů modelu |
| `GALLERY_MAP_TILES` | `https://tile.openstreetmap.org/{z}/{x}/{y}.png` | server mapových dlaždic pro mapy u fotek; při větším provozu použijte vlastní nebo kešující server (pravidla OpenStreetMap) |

Souřadnice AP se čtou z pole `gps` v odpovědi Userdb `/api/areas` (formát `50.22795,15.834133`);
chybějící nebo neplatné hodnoty se ignorují.

## Data

| Tabulka | Obsah |
| --- | --- |
| `confluence_imports` | jeden běh importu: cílová galerie, stránka, stav, chyba |
| `confluence_import_items` | jedna příloha: id a verze, název, velikost, uložený název, stav, důvod |
| `gallery_image_descriptions` | údaje pro popis: poloha z EXIF, směr a jeho zdroj, typ scény a jistota, zakrytí výhledu |
| `gallery_image_embeddings` | otisk fotky (vektor 384 čísel) a model, který ho spočítal |

Při přesunu fotky do koše se její údaje pro popis i otisk smažou.
