# Vysvětlení

## Proč import běží na pozadí

Jedna stránka fotoarchivu může mít přes sto fotek a stovky megabajtů (stránka
`vyhledy_plotiste` má 133 fotek, 287 MB). Takové stahování se nevejde do jednoho webového
požadavku, proto ho dělá úloha na pozadí.

Úloha pracuje po 30 sekundách a pak předá zbytek dalšímu běhu. Krátké běhy znamenají, že
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

## Proč rozpoznávání scén běží v prohlížeči

Server má na model jen 1–2 GB paměti a každá další služba je starost navíc. Prohlížeč správce
model zvládne bez potíží: knihovna Transformers.js spustí model CLIP (asi 90 MB, stáhne se
jednou a pak se drží v mezipaměti prohlížeče) a jedna fotka trvá zlomek sekundy — při zkoušce
17 fotek za 15 sekund v Chromiu a 85 fotek za 33 sekund ve Firefoxu, včetně stažení modelu.

Návštěvníci galerie knihovnu ani model nestahují: načtou se teprve po kliknutí na
**Rozpoznat typ scény**. Fotky při tom neopouštějí galerii; prohlížeč čte náhledy, které správce
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
