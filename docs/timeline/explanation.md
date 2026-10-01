# Vysvětlení: jak časová osa funguje

## Proč existuje index

Galerie ukládá fotky jako obyčejné soubory, pro každou galerii AP do jednoho adresáře,
a zobrazení mřížky prostě vypíše obsah tohoto adresáře. To stačí pro jednu galerii seřazenou
podle názvu, ale časová osa potřebuje víc:

- **Řadí podle data, ne podle názvu.** Zjistit datum znamená otevřít EXIF údaje každého
  souboru. U jedné fotky je to levné, u tisíců při každém zobrazení stránky pomalé.
- **Časová osa sítě slévá všechny galerie do jednoho seřazeného proudu** a stránkuje ho.
  Z adresářů by to znamenalo při každém požadavku projít všechny.
- **Přehled měsíců potřebuje počet fotek v každém měsíci.**

Časová osa proto čte z databázové tabulky `gallery_images`, která soubory indexuje. Data
každé fotky se načtou jednou, při jejím zaindexování, a uloží se.

Zdrojem pravdy zůstávají soubory. Index jen zaznamenává, co existuje a kdy to bylo
pořízeno; nikdy nerozhoduje o tom, zda fotka existuje. Zobrazení mřížky dál čtou přímo
adresáře. Kdyby byl index někdy chybný, mřížka stejně ukáže všechny fotky a `gallery:index`
index ze souborů znovu sestaví.

## Jak index zůstává správný

Většina změn probíhá přes galerii samotnou a ty index aktualizují okamžitě: nahrání přidá
záznam, přesun fotky do koše ho odebere.

Soubory se ale mohou měnit i mimo galerii: správce je nakopíruje, obnoví z koše
přejmenováním nebo smaže přímo na serveru. Ty zachytí tři mechanismy:

1. **Otevření časové osy AP** porovná adresář galerie s jejími záznamy, doplní chybějící
   a odebere zaniklé. Při jednom načtení stránky přidá nejvýše 200 fotek, takže první
   návštěva velké, dosud neindexované galerie zůstane rychlá.
2. **Denní běh `gallery:index`** udělá totéž pro všechny galerie.
3. **Ruční spuštění `gallery:index`** promítne změny okamžitě.

Časová osa sítě se při načtení stránky nesrovnává: procházet při každém požadavku všechny
adresáře je přesně ta zátěž, které má index zabránit. Změny provedené přímo na disku se do ní
proto dostanou nejpozději za den, pokud správce nespustí příkaz ručně.

Na konci nahrání se nejdřív zapíše soubor a teprve potom záznam. Pokud se zápis záznamu
nepovede, fotka je přesto bezpečně uložená a vidět v mřížce a příští srovnání ji doplní.
Opačné pořadí by mohlo nechat záznamy odkazující na neexistující soubory.

## Jak fotka získá datum

Nejužitečnější je datum, kdy byla fotka **pořízena**. Fotoaparáty a telefony ho zapisují do
EXIF údajů fotky jako `DateTimeOriginal`. Často ale chybí, protože chatovací aplikace, snímky
obrazovky a mnohé editory metadata odstraňují. Galerie proto postupuje krok za krokem:

1. **Datum pořízení z EXIF.** Pole `DateTime` v EXIF galerie ignoruje, protože navzdory
   názvu zaznamenává, kdy byl soubor naposledy upraven.
2. **Datum poslední změny souboru, jak ho nahlásí prohlížeč toho, kdo fotku nahrává.**
   U souborů zkopírovaných přímo z telefonu nebo fotoaparátu je to často čas pořízení.
   U stažených souborů je to čas stažení, což stále není horší než další možnost.
3. **Čas nahrání** (čas změny uloženého souboru).

Data, která nemohou být skutečná, se přeskočí a zkusí se další zdroj:

- cokoli před rokem 1990, typicky fotoaparát, kterému nikdo nenastavil hodiny, a hlásí rok 1970
  nebo 2000
- cokoli v budoucnosti
- zástupné hodnoty jako `0000:00:00 00:00:00`

Datum se načte jednou, při zaindexování fotky. Stránky jsou díky tomu rychlé, ale znamená to,
že chybné datum opětovné indexování neopraví: řešením je znovu nahrát soubor, který nese
správné datum (viz [postup](how-to.md#opravit-fotku-která-je-ve-špatném-měsíci)).

### Riziko u fotek nakopírovaných na server

U fotek, které byly na serveru už před zavedením časové osy, jsou k dispozici jen zdroje
1 a 3. Pokud se tyto soubory někdy kopírovaly bez zachování času změny, nese každá fotka bez
EXIF datum tohoto kopírování a všechny skončí ve stejném měsíci. Rozložení po měsících při
`--dry-run` slouží k tomu, abyste to odhalili dřív, než index vytvoříte.

## Proč se data ukládají v místním čase

Data v EXIF nemají časové pásmo: fotoaparát zapíše to, co ukazují jeho hodiny, například
`2024:05:17 10:20:30`. Časové značky jako časy souborů jsou naproti tomu absolutní okamžiky,
obvykle v UTC. Smíchání obojího by fotky seřadilo s chybou hodiny či dvou a fotku nahranou
těsně po půlnoci prvního dne v měsíci (pražského času) by zařadilo do předchozího měsíce.

Galerie proto ukládá všechna data časové osy jako **místní čas v jednom časovém pásmu**,
`GALLERY_TIMEZONE` (výchozí Europe/Prague). Data z EXIF zůstávají přesně tak, jak byla
zapsána, a časové značky se do tohoto pásma převedou. Měsíc je pak prostě rok a měsíc tohoto
místního času.

Aplikace jako celek dál běží v UTC. Místní čas používají jen data časové osy, takže nic
jiného v databázi nemění význam.

## Soukromí

Fotky z dokumentace jsou jen pro přihlášené uživatele. Na časové ose sítě platí:

- Nepřihlášení vždy dostanou jen veřejné fotky. Parametr `priv=1` se bez přihlášení ignoruje
  a filtruje se přímo v databázovém dotazu, takže nepřihlášený nikdy nedostane fotky
  z dokumentace, dokonce ani jejich názvy souborů.
- Přihlášení vidí fotky z dokumentace, jen když o ně požádají. Jsou to technické fotky,
  které by jinak zaplavily všechno ostatní.
- I kdyby adresa fotky z dokumentace unikla, její otevření stále vyžaduje přihlášení.

Fotky AP, které už v Userdb neexistují, se na časové ose sítě nezobrazují, protože stránky
jejich galerií by vrátily „nenalezeno“.

Časová osa usnadňuje hledání starších fotek. Veřejné originály se posílají beze změny,
včetně polohy GPS, kterou do nich případně uložil telefon. Platilo to už před zavedením
časové osy, ale při nahrávání veřejných fotek na to pamatujte.

## Posouvání a adresní řádek

Časová osa načítá 60 fotek najednou. Rušný měsíc, například den montážních prací, jich
snadno může mít víc, proto se stránky dělí podle počtu fotek, ne podle měsíců. Když další
stránka pokračuje měsícem, který už je na obrazovce, přidají se její fotky do existující
sekce, takže každý měsíc se zobrazí jako jeden blok s jedním nadpisem. Počet v nadpisu je
celkový počet fotek v měsíci, ne počet dosud načtených.

Při posouvání se do adresního řádku zapisuje `?from=` s měsícem, který je nahoře na
obrazovce, aniž by přibývaly položky historie. Po obnovení stránky nebo pozdějším návratu
na ni se tak vrátíte zhruba na stejné místo v čase.

Bez JavaScriptu vše funguje přes obyčejné odkazy: **Starší** načte další stránku, přehled
měsíců umožňuje skákat a **Novější** vrací k nejnovějším fotkám.
