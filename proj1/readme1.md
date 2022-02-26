Implementační dokumentace k 1. úloze do IPP 2021/2022  
Jméno a příjmení: Ondřej Mach  
Login: xmacho12  

## Parser

Skript parseru je implementován v jazyce PHP bez použití objektově orientovaného návrhu.
Řešeno je pouze základní zadání, bez rozšíření.
Byla použita knihovna XMLWriter pro jednodušší generování výstupního XML souboru.

Po spuštění skriptu jsou načteny jeho argumenty. 
V tomto případě se jedná pouze o `--help`, který vypíše nápovědu.

Dále je zavolána funkce `parseInput`, která čte ze standardního vstupu.
Každý přečtený řádek je zvlášť zpracován funkcí `parseLine`. 
Ta zkontroluje syntaktickou správnost instrukce a vygeneruje pro ni odpovídající výstup v XML.

Pro rozbor řádku jsou zde použity integrované funkce `explode` a `preg_split`. 
Obě rozdělí řádek na seznam více řetězců v místech, kde se vyskytuje stanovený klíč resp. regularní výraz.

V parseru je definována třída `Instruction`. 
V ní je uchován název instrukce a seznam typů jejích parametrů (to může být label, var, symb nebo type).
`Instruction` má metodu `generateXML`, která zkontroluje správný počet a typy argumentů a vygeneruje XML.

Rozbor každého argumentu dělá funkce `parseArgument`.
Ta jako vstup dostane řetězec argumentu a jeho očekávaný typ.
Očekávaný typ je zde z důvodu, že label a type od sebe nejde rozlišit podle pouhého řetězce.
Zjištění typu var nebo literálů je velmi snadné, protože jejich řetězec začíná označením rámce resp. datového typu.

U všech argumentu je zjištěno, zda jejich hodnota je validní pro daný datový typ. 
Tato kontrola je většinou provedena regulárním výrazem, funkcemi `preg_match` a `preg_match_all`.
Regulární výrazy velice zjednodušují práci oproti jazyku C, kde by bylo nutné místo každého implementovat stavový automat.
