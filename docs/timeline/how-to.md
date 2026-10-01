# Postupy

Návody zaměřené na konkrétní úkoly. Předpokládají, že se v galerii už vyznáte. Pokud ne,
začněte [návodem](tutorial.md).

**Pro všechny**

- [Sdílet odkaz na konkrétní měsíc](#sdílet-odkaz-na-konkrétní-měsíc)
- [Zobrazit fotky z dokumentace na časové ose sítě](#zobrazit-fotky-z-dokumentace-na-časové-ose-sítě)

**Pro správce galerií**

- [Nahrát fotky tak, aby se zařadily do správného měsíce](#nahrát-fotky-tak-aby-se-zařadily-do-správného-měsíce)
- [Opravit fotku, která je ve špatném měsíci](#opravit-fotku-která-je-ve-špatném-měsíci)

**Pro správce serveru**

- [Vytvořit index časové osy na existující instalaci](#vytvořit-index-časové-osy-na-existující-instalaci)
- [Udržovat index automaticky aktuální](#udržovat-index-automaticky-aktuální)
- [Zobrazit hned fotky nakopírované na server](#zobrazit-hned-fotky-nakopírované-na-server)
- [Změnit časové pásmo pro určování měsíců](#změnit-časové-pásmo-pro-určování-měsíců)

---

## Sdílet odkaz na konkrétní měsíc

1. Otevřete časovou osu (**Časová osa** u AP, nebo **Časová osa** v záhlaví).
2. Klikněte na měsíc v přehledu měsíců, nebo se k němu doposouvejte.
3. Zkopírujte adresu z adresního řádku. Končí na `?from=RRRR-MM`, například `?from=2025-03`.

Kdo odkaz otevře, začne na tomto měsíci a může odtud listovat dál do minulosti.

## Zobrazit fotky z dokumentace na časové ose sítě

Fotky z dokumentace vidí jen přihlášení uživatelé.

1. Přihlaste se tlačítkem **Přihlásit**.
2. V záhlaví otevřete **Časová osa**.
3. Klikněte na **Zobrazit i Dokumentaci**. Fotky z dokumentace se objeví se zámkem.

Volba zůstává zachovaná při posouvání i v odkazech, které zkopírujete (adresa obsahuje
`priv=1`). Kliknutím na **Včetně Dokumentace** se vrátíte jen k veřejným fotkám.

## Nahrát fotky tak, aby se zařadily do správného měsíce

Fotka se zařadí do měsíce, kdy byla **pořízena**. Galerie toto datum čte z EXIF údajů fotky.
Aby se zachovalo:

1. Nahrávejte **původní soubory** z fotoaparátu nebo telefonu: přetáhněte je do oblasti pro
   nahrávání, nebo na ni klikněte a vyberte je.
2. Nenahrávejte kopie uložené z chatovacích aplikací (WhatsApp, Messenger, Signal), snímky
   obrazovky ani soubory exportované z editorů, které metadata odstraňují. Datum pořízení
   obvykle nemají.

Pokud fotka datum pořízení nemá, galerie použije datum poslední změny souboru, jak ho
nahlásí váš prohlížeč, a když chybí i to, čas nahrání. Viz
[jak fotka získá datum](explanation.md#jak-fotka-získá-datum).

## Opravit fotku, která je ve špatném měsíci

Datum fotky v galerii upravit nejde. Načte se jednou, při zařazení fotky do indexu. Oprava:

1. Najděte původní soubor se správným datem v EXIF (z fotoaparátu nebo telefonu).
2. V galerii AP najeďte myší na chybnou fotku a ikonou koše ji přesuňte do koše.
3. Nahrajte původní soubor.

Pokud originál s datem pořízení neexistuje, nastavte před nahráním na svém počítači
souboru čas poslední změny na správné datum. Prohlížeč ho nahlásí a galerie ho použije jako
náhradní datum.

## Vytvořit index časové osy na existující instalaci

Když nasazujete časovou osu na server, kde už fotky jsou, zaindexujte je jednorázově:

1. Spusťte migrace:

   ```bash
   php artisan migrate --force
   ```

2. Udělejte zkušební běh a zkontrolujte rozložení po měsících, které vypíše:

   ```bash
   sudo -u www-data php artisan gallery:index --dry-run
   ```

   Pokud většina fotek připadne na jeden nedávný měsíc, jejich soubory při kopírování přišly
   o původní čas změny, například při `cp` bez `-p`. Fotky bez EXIF by pak všechny dostaly
   datum tohoto kopírování. Pokud máte zdroj, nakopírujte ho znovu se zachováním časů
   (`rsync -t` nebo `cp -p`) a teprve pak pokračujte.

3. Vytvořte index:

   ```bash
   sudo -u www-data php artisan gallery:index
   ```

Chcete-li to nejdřív vyzkoušet jen na části dat, přidejte `--area=<id>` nebo `--ap=<id>`.

## Udržovat index automaticky aktuální

Nahrání a smazání fotky aktualizuje index okamžitě. Aby se zachytily i změny provedené
přímo na disku, spusťte plánovač Laravelu, který jednou denně spustí `gallery:index`:

1. Otevřete crontab uživatele webového serveru:

   ```bash
   sudo crontab -u www-data -e
   ```

2. Přidejte:

   ```cron
   * * * * * cd /home/<user>/websites/hkfree-gallery && php artisan schedule:run >> /dev/null 2>&1
   ```

3. Ověřte, že je úloha zaregistrovaná:

   ```bash
   sudo -u www-data php artisan schedule:list
   ```

Plánovač spouštějte jako `www-data`, aby logy a databázové soubory, které vytvoří, zůstaly
zapisovatelné pro Apache.

## Zobrazit hned fotky nakopírované na server

Po nakopírování fotek do adresáře galerie nebo po obnovení souboru z koše přejmenováním:

- **Na časové ose daného AP** není potřeba nic dělat. Její otevření zaindexuje nové soubory,
  nejvýše 200 při jednom načtení stránky; žluté upozornění (**Probíhá indexace…**) dá vědět,
  když další čekají. Obnovujte stránku, dokud nezmizí.
- **Na časové ose sítě** spusťte:

  ```bash
  sudo -u www-data php artisan gallery:index --ap=<id AP>
  ```

  Jinak se fotky objeví po příštím denním běhu.

## Změnit časové pásmo pro určování měsíců

Fotky se do měsíců řadí podle místního času Europe/Prague. Pro jiné časové pásmo:

1. Nastavte ho v `.env`:

   ```dotenv
   GALLERY_TIMEZONE=Europe/Bratislava
   ```

2. Obnovte mezipaměť konfigurace: `php artisan config:cache`.

Změna platí pro fotky indexované od této chvíle. Existující záznamy si svá data ponechají.
Než budete vše indexovat znovu, přečtěte si,
[proč se data ukládají v místním čase](explanation.md#proč-se-data-ukládají-v-místním-čase).
