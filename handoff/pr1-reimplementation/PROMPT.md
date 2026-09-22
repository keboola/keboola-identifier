# Zadání: nová implementace Keboola Identifier (KID)

Implementuj znovu knihovny Keboola Identifier pro PHP, TypeScript, Python a Go. Dokonči implementaci, balíčky, dokumentaci a CI a ověř jejich funkčnost. Nezůstávej u návrhu.

## Zdroj zadání

- Repozitář: https://github.com/keboola/keboola-identifier
- Původní PR: https://github.com/keboola/keboola-identifier/pull/1
- Zafixovaný commit: `1f6eed74c198460f8160daa425b6e3e680a88a06`
- Toto je nové provedení původního zadání, nikoli oprava původního zdrojového kódu. Navrhni implementaci samostatně podle specifikace a testů.

Vedle tohoto promptu jsou přesné kopie podkladů z uvedeného commitu:

1. `SPEC.md` – normativní formát a jazykově neutrální API, včetně jazykových mapování.
2. `spec/test-vectors.json` – normativní sdílené testovací případy.
3. `spec/entities.json` – katalog entit.
4. `spec/generate_vectors.py` – generátor testovacích vektorů.
5. `README.md` – původní popis knihoven, rozsahu, použití a vývoje.

Nejdřív přečti tyto soubory celé. Normativním kontraktem je SPEC.md společně s testovacími vektory; README poskytuje kontext. Pokud najdeš skutečný rozpor, popiš ho a vyjasni před změnou kontraktu. Současný obsah pracovní větve může obsahovat pozdější změny: pro toto zadání použij uvedený snapshot. Zvláště zachovej krátké entity `m`, `o`, `p`, `b`.

## Původní zadání z popisu PR

- Implementovat KID 1.0 API v PHP, TypeScriptu, Pythonu a Go.
- Sdílet normativní testovací vektory mezi všemi čtyřmi balíčky.
- Pokrýt parsování, validaci, kanonizaci, vytváření, kódování, wildcardy, patterns, rovnost a typované accessory entit.
- Doplnit metadata balíčků, dokumentaci použití a CI pro každý jazyk.
- Opravit dvě chybné hraniční sady v generátoru: `ř` má v UTF-8 dva bajty a `b` je omezená entita větve.

Tyto dvě opravy už jsou v přiloženém snapshotu: tříbajtový znak pro hranice je `€` a sada obecných entit pro hranici délky nezahrnuje `b`. Zachovej je.

## Požadované výstupy

- `php/`: Composer balíček `keboola/identifier`, namespace `Keboola\Identifier`.
- `ts/`: npm balíček `@keboola/identifier`, JavaScript výstup a TypeScript deklarace; ověř také obsah distribuovaného balíčku a import jeho veřejného entrypointu.
- `python/`: Python balíček `keboola-identifier`, import `keboola_identifier`.
- `go/`: Go modul `github.com/keboola/keboola-identifier/go`, balíček `kid`.
- Veřejné API, chyby a entity podle §9 a §9.1 specifikace.
- Testy všech jazyků načítající tentýž `spec/test-vectors.json`.
- CI pro všechny čtyři implementace a kontrola deterministické regenerace vektorů.
- README s funkčními příklady, instalací, sestavením a spuštěním testů. Přiložené README použij jako obsahový podklad, příkazy musí odpovídat výsledné implementaci.

## Kritické části kontraktu

Tento seznam je orientační; nenahrazuje úplnou specifikaci:

- KID je offline syntaktický identifikátor. Neověřuj existenci objektů ani stacků.
- Přesný prefix `KID:`, stack 1–64 znaků, otevřený katalog entit, rozdělení segmentu na prvním spojovníku, žádné duplicitní entity.
- Kanonické pořadí `m`, `o`, `p`, `b`, poté ostatní entity podle ASCII bajtů. `b-default` z kanonického výstupu odstranit.
- `m`, `o`, `p` přijímají pouze ASCII číslice nebo `*`; `b` navíc `dev` a `default`.
- Vlastní bajtová implementace percent-encodingu podle §5. Nepoužívat URL encoding helpery s odlišnou tabulkou. Platné UTF-8, žádná Unicode normalizace ani odstraňování znaků.
- Horní limit 255 bajtů dekódované hodnoty a 2048 bajtů celého KID. Dodržet rozdíl kontroly vstupu v `fromString()` a kanonického výstupu v `create()`.
- Přesné pořadí validací, kódy chyb a offending segment podle §8.
- `parse()` plně validuje, ale vrací hodnoty tak, jak jsou napsané, včetně percent-encodingu a `b-default`.
- `create()` bere dekódované řetězcové hodnoty a neprovádí automatickou konverzi jiných typů.
- Rozlišovat bare wildcard `*` a literal `%2A`; `b-dev` je pattern, ale není wildcard.
- Chybějící entita se vrací podle jazykového kontraktu. Objekty jsou immutable. Rovnost je shoda kanonických řetězců; Python podporuje hashování.
- Neimplementuj pattern matching, převod URN, generování ID, striktní katalog ani kontrolu rodičovských vztahů.

## Povinné regresní případy z review původní implementace

### 1. Zachování U+FEFF na začátku hodnoty

Původní TypeScript implementace používala `TextDecoder` s výchozím odstraňováním BOM, takže měnila dekódovanou hodnotu a odmítala validní raw vstup.

Ověř ve všech jazycích:

- `fromString("KID:s:config-%EF%BB%BFx").get("config")` vrací přesně `"\uFEFFx"`, nikoli `"x"`.
- `create("s", {config: "\uFEFFx"})` uspěje a vrátí `KID:s:config-%EF%BB%BFx`.
- Samotný U+FEFF je neprázdná validní hodnota a musí se zachovat.

Pokud v TypeScriptu používáš `TextDecoder`, nastav `{fatal: true, ignoreBOM: true}`. Přidej tyto případy do sdíleného generátoru a vektorů.

### 2. Vlastnosti Object.prototype nesmějí vystupovat jako entity

Původní TypeScript `get()` četl klíče běžného objektu bez ověření vlastnictví. U chybějící entity `constructor` tak místo `undefined` vyhodil TypeError.

Ověř:

```ts
const kid = Kid.fromString("KID:s");
kid.has("constructor") === false;
kid.get("constructor") === undefined;
```

`constructor` je platný název otevřené entity; explicitně zadaný musí fungovat:

```ts
Kid.fromString("KID:s:constructor-x").get("constructor") === "x";
```

Používej bezpečnou reprezentaci mapy nebo kontroly vlastních klíčů. Přidej cílené testy accessorů.

## Pracovní postup a dokončení

1. Zkontroluj instrukce repozitáře a stav pracovního stromu. Případné rozpracované uživatelské změny zachovej; pro novou implementaci preferuj samostatnou větev/worktree.
2. Vytvoř krátký plán a implementuj všechny čtyři balíčky podle přiloženého kontraktu. Původní implementační soubory nepoužívej jako šablonu.
3. Zachovej původní normativní případy a rozšiř testy o výše uvedené regrese. U nových případů uprav generátor i generovaný JSON.
4. Spusť testy každého jazyka, TypeScript build, `go vet ./...` a kontrolu deterministické regenerace. Ověř instalovatelnost/importovatelnost balíčků.
5. Na závěr uveď hotové výstupy, skutečně spuštěné kontroly a jejich výsledky a případné zbývající blokery. Netvrď, že testy prošly, pokud nebyly spuštěny.

Původní snapshot měl 641 PHP kontrol, 146 TypeScript testů a 147 Python testů a procházel Go testy i vetem. Tyto počty jsou pouze kontext, ne cílová metrika; regresní testy je mohou navýšit.
