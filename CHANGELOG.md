# Changelog ##

## 1.7.4 - 24 Sep 2026
* **Nieuw: "Regelovergang toestaan".** Een tweede schakelaar in de
  veldinstellingen, naast "Vetgedrukt toestaan" en alleen zichtbaar als die aan
  staat. Staat hij aan, dan maakt **Enter** een regelovergang (`<br>`) in het
  veld. In een titel wil je dat soms wel, in een knoptekst of een naam niet —
  vandaar apart. Site-breed aan te zetten met de filter `db_acf_ui/allow_br`.
* **Fix: een `<br>` in de titel werd letterlijke tekst.** Zette je "Vetgedrukt
  toestaan" aan op een veld waarin je `<br>` gebruikt, dan schreef de editor bij
  de eerste bewerking `&lt;br&gt;` weg en toonde de voorkant de tag letterlijk.
  Een `<br>` die al in de waarde staat blijft nu gewoon een regelovergang, ook
  als regelovergangen voor dat veld uit staan — hem alsnog weggooien zou
  bestaande pagina's veranderen. Een waarde die eerder is platgeslagen wordt
  rechtgezet zodra je het veld bewerkt, mits het vinkje aan staat.
* Een losse regelovergang aan het eind van een veld wordt niet opgeslagen.

## 1.7.3 - 11 Sep 2026
* **Fix: titelveld met vetgedrukt soms niet bewerkbaar.** De vet-knop werd ook
  aan het verborgen sjabloon van flexible content gehangen. Bij "Layout
  toevoegen" kloont ACF dat sjabloon, en de nieuwe layout kreeg een dode kopie
  van de editor mee. Afhankelijk van welk script eerst laadde was die niet te
  bewerken, óf je kon wel typen maar werd de titel niet opgeslagen. Het
  sjabloon wordt nu overgeslagen en elke nieuwe layout krijgt een eigen editor.
* Ook bij **Dupliceer layout** kreeg de kopie zo'n dode editor; getypte tekst
  kwam niet in het veld. Die restanten worden nu opgeruimd en opnieuw opgebouwd.
* De editor volgt voortaan live of ACF het veld aan- of uitzet. Een titel die
  via conditionele logica pas later zichtbaar wordt, bleef eerder op slot.

## 1.7.2 - 31 Aug 2026
* **Fix: je hoefde de plugin twee keer te updaten.** De updater vergeleek de
  release met het versienummer uit het geheugen. Tijdens een update is dat nog
  de óude versie, waardoor er direct na het installeren opnieuw een
  "update beschikbaar" werd weggeschreven — en die bleef een uur staan.
  Er wordt nu vergeleken met de versie op schijf, die WordPress zelf al
  klaarzet. Een verouderde melding van een eerdere versie wordt actief
  opgeruimd.
* De GitHub-aanroep wordt zes uur gecachet. Voorheen belde de plugin bij elke
  update-check én elk detailvenster; ongeauthenticeerd staat GitHub 60
  verzoeken per uur toe, en daarboven verdween de update-melding zomaar.
* Er wordt alleen nog een update aangeboden als de release ook echt een
  `db-acf-extension.zip` bevat, zodat een vergeten bijlage geen mislukte
  installatie meer oplevert.
* `Update URI` in de plugin-header wees naar Bitbucket terwijl de updater
  GitHub gebruikt; die staat nu goed.
* De updater is verhuisd van het hoofdbestand naar `classes/updater.php`.

## 1.7.1 - 31 Aug 2026
* Fix: het vet-veld stak buiten de modal doordat padding en rand bovenop de
  breedte kwamen (`box-sizing` ontbrak).
* De veldinstelling toont alleen nog de schakelaar "Vetgedrukt toestaan",
  zonder toelichting eronder.
* Fix: het potlood-icoon op de bewerken-knop van afbeeldings- en
  bestandsvelden was verdwenen doordat het icoon globaal verborgen werd.

## 1.7.0 - 31 Aug 2026
* Vetgedrukte tekst is nu ook zichtbaar vet in het veld zelf. Het tekstveld
  wordt vervangen door een bewerkbaar element dat de opmaak toont; de
  `<strong>`-tags gaan onzichtbaar naar het echte veld, zodat ACF en het theme
  krijgen wat ze altijd al kregen. Plakken vanuit Word of een website levert
  platte tekst op.

## 1.6.0 - 31 Aug 2026
* Vet-knop op gewone ACF tekstvelden. Per veld aan te zetten via de nieuwe
  instelling "Vetgedrukt toestaan"; de knop pakt de selectie in `<strong>`,
  haalt de tags er weer af als de selectie al vet is, en luistert naar
  Cmd/Ctrl + B. Site-breed aanzetten kan met de filter `db_acf_ui/allow_bold`.

## 2.0.0 - 24 Aug 2022

#### Breaking Changes
* Requires PHP >= 8.0
* Requires WordPress >= 6.0

## 1.4.7 - 2 Mar 2026
* Bug fixes and fine tuning

## 1.2.1 - 2 Jan 2026
* Initial release
