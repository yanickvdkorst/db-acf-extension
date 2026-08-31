# Changelog ##

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
