# Vysvětlení

## Proč import běží na pozadí

Jedna stránka fotoarchivu může mít přes sto fotek a stovky megabajtů (stránka
`vyhledy_plotiste` má 133 fotek, 287 MB). Takové stahování se nevejde do jednoho webového
požadavku, proto ho dělá úloha na pozadí.

Úloha začíná nové fotky 20 sekund a pak předá zbytek dalšímu běhu. Krátké běhy znamenají, že
pád serveru přijde nanejvýš o jednu rozpracovanou fotku, a že se úloha nikdy nespustí
dvakrát souběžně. Po uložení každé fotky se hned zapíše, pod jakým názvem je uložená. Když
úloha spadne mezi uložením a dokončením, další běh fotku jen zaindexuje a nestahuje ji znovu
— jinak by v galerii vznikla kopie.

Úlohy spouští plánovač každou minutu, takže na serveru nemusí běžet žádná další služba.
Proto může import začít až s minutovým zpožděním.

## Proč popisy nevytváří jazykový model

Původní plán byl nechat malý model napsat ke každé fotce větu. Na dvaceti skutečných fotkách
z archivu se ukázalo, že to k ničemu není:

- modely, které se vejdou do omezené paměti, píší obecné věty („pohled na město se spoustou
  domů“) nebo si vymýšlejí („vrak vlaku v poli“),
- píšou anglicky a strojový překlad přidává chyby („poles“ → „póly“).

U výhledů z AP navíc nejde o to, *co* je na fotce, ale *odkud* a *kam* se díváme a jaké další
AP v tom směru leží — tedy o přímou viditelnost mezi AP. To žádný malý model z obrázku
nevyčte, ale dá se to spočítat: ze souřadnic AP v Userdb a ze směru pohledu.

Popis se proto skládá z faktů. Model se používá jen na jednu věc, kterou zvládá spolehlivě
a rychle: zařadit fotku do jednoho z několika typů scény. Texty typů jsou pevné a české,
takže se nic nepřekládá.

## Proč se směr nastavuje často ručně

Fotky v Confluence nemají údaje EXIF — ani staré fotky z fotoaparátů, ani nové fotky z
telefonů. Směr z kompasu telefonu se tedy k importovaným fotkám nedostane. Zbývají dva zdroje:
název souboru, který směr uvádí jen výjimečně (asi 20 z 10 539 příloh), a ruční nastavení.

Ruční nastavení je proto navržené jako rychlé: osm směrů na jedno kliknutí přímo na dlaždici.
Fotky nahrané do galerie přímo z telefonu směr obvykle mají v EXIF.

Ručně nastavený směr se nikdy nepřepíše. Údaje pro popis jsou uložené zvlášť od indexu časové
osy, který se může kdykoli přestavět, takže práce správců přežije i přeindexování.

## Jak galerie navrhuje směr

Z fotky samotné se světová strana vyčíst nedá. Archiv má ale jednu užitečnou vlastnost: z
každého AP se fotí pořád tytéž výhledy, rok co rok (například `vyhledy_karosarna_2014`, `2015`,
`2020`, `2022`, `2025`, `2026`). Stejný výhled vypadá podobně i v jiném roce nebo ročním období
— stejné střechy, stejná silnice, stejný dům s červenou střechou.

Galerie proto každé fotce spočítá „otisk“ (model DINOv2, navržený právě pro poznávání stejných
míst a předmětů) a fotku bez směru porovná s fotkami téhož AP, které směr mají. Pokud je
nejpodobnější fotka dost podobná a zřetelně podobnější než jakákoli fotka s jiným směrem,
galerie navrhne její směr.

Ověřeno na stránce **Výhledy z AP Kunčice**, kde jsou fotky pojmenované podle směru a
některé jsou focené v létě i v zimě: všechny tři zimní fotky dostaly správný návrh podle
letního protějšku. Je to malý vzorek; proto je návrh jen návrh a do popisu se dostane až po
potvrzení správcem.

Návrhy se počítají na serveru z uložených otisků. Když správce nastaví směr další fotce,
návrhy pro ostatní se hned přepočítají — není potřeba znovu nic analyzovat.

## Jak se měří zakrytí výhledu

U výhledu z AP nejde jen o to, kam se díváme, ale i jestli je vidět daleko. Model SegFormer
rozdělí fotku na oblasti — obloha, strom, budova, zeď, plot — a galerie pak hledá překážky,
které výhled zakrývají.

Prosté „kolik je na fotce stromů“ nestačí: vzdálený les na obzoru by se počítal stejně jako
smrk těsně před objektivem. Rozhoduje proto obrys oblohy. Vzdálená řada stromů leží na
obzoru, zatímco strom před objektivem vyčnívá vysoko do oblohy. Galerie najde obzor (kam
dosahuje obloha ve většině fotky) a za zakryté počítá ty části šířky, kde nad něj výrazně
vyčnívá strom, keř, zeď, plot nebo sloup.

Na vzorku z archivu to odpovídá tomu, co je na fotkách vidět: otevřené výhledy vycházejí
„bez překážek“, zarostlý výhled kolem 55 %, a tentýž výhled z Kunčic kolem 20 % v létě a
pod 10 % v zimě, kdy stromy nemají listí. Slabé místo: zalesněný kopec, který sám tvoří
obzor, se počítá jako překážka, i když jde o vzdálený les.

U fotek bez oblohy (například rozvaděč) a u fotek antén, rozvaděčů a střech se zakrytí
neuvádí — není tam žádný výhled, který by šlo hodnotit.

## Proč ukazovat chybějící směry

Výhledy z AP slouží hlavně k plánování spojů: z fotky je vidět, kam z AP dohlédneme a co
v cestě stojí. Proto je důležité vědět i to, co *nevíme* — kterým směrem fotka chybí nebo je
stará. Stromy rostou a staví se nové domy, takže výhled starý deset let už nemusí platit;
proto se směry starší než 3 roky ukazují zvlášť.

Chybějící směry jsou doplněné o AP, která v nich leží, protože výhled na sousední AP je
pro síť nejcennější. Přehled všech AP pak funguje jako seznam úkolů: nahoře jsou AP, kde
nejvíc chybí.

## Proč analýza běží v prohlížeči

Server má na modely jen 1–2 GB paměti a každá další služba je starost navíc. Prohlížeč správce
modely zvládne bez potíží: knihovna Transformers.js spustí tři malé modely (dohromady asi
120 MB, stáhnou se jednou a pak se drží v mezipaměti prohlížeče) a jedna fotka trvá zlomek
sekundy — při zkoušce 16 fotek za 21 sekund v Chromiu včetně stažení modelů.

Návštěvníci galerie knihovnu ani modely nestahují: načtou se teprve po kliknutí na
**Analyzovat fotky**, nebo po nahrání s volbou **Po nahrání fotky analyzovat**. Fotky při tom neopouštějí galerii; prohlížeč čte náhledy, které správce
stejně vidí.

Model se zhruba ve třetině případů splete, proto se typ scény ukazuje jen tehdy, když si je
model dost jistý, a vždy s poznámkou „rozpoznáno automaticky“.

## Proč import mluví jen s jedním serverem

Server galerie stahuje soubory podle odkazů, které dodá uživatel. Kdyby šlo stáhnout cokoli,
dal by se server zneužít ke čtení interních adres (útok SSRF). Proto:

- vložená adresa slouží jen k zjištění čísla stránky; server je vždy ten z konfigurace,
- odkazy na přílohy jsou relativní k tomuto serveru,
- přesměrování se nesledují,
- obrázky z externích adres se nestahují vůbec.

## Proč se kontroluje volné místo

Celý fotoarchiv má přes 10 000 fotek, desítky gigabajtů. Než se import spustí, ověří se, že na
disku zůstane po importu aspoň 1 GB volného místa. Plný disk by zastavil celou galerii, nejen
import.
