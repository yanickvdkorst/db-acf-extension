# Changelog ##

## 1.9.4 - 28 Oct 2026
* **Fix: een stagingomgeving kon toch mailen.** De blokkade op
  `*.ontwikkelbazen.nl` gold alleen voor de overzichtsmail. Het formulier dat
  per run écht mocht mailen (tot 1.9.3) ging er gewoon uit, en een inzending die
  niet als healthcheck herkend werd, stuurde de mail van de klant — vanaf een
  kopie van de site. Tijdens een run op een testomgeving wordt nu élke
  formuliermail tegengehouden, ook een die we niet herkennen, en de SMTP-test
  wordt daar helemaal overgeslagen.
* De check kijkt nu ook naar de host van het verzoek, niet alleen naar
  `home_url()`. Een kopie die naar staging is gezet zonder de URL's bij te
  werken heeft daar nog het productiedomein staan en werd zo niet als
  testomgeving herkend.
* `.ontwikkelbazen.nl` dekt voortaan ook het kale `ontwikkelbazen.nl`, en een
  poortnummer in de hostnaam zit de herkenning niet meer in de weg.

## 1.9.3 - 27 Oct 2026
* **De formulieren zelf mailen niet meer bij de dagelijkse check.** Er ging per
  run één échte formuliermail uit om de SMTP-instellingen te testen; die kwam
  bovenop de overzichtsmail in de inbox terecht. Bij een formulier met een
  bevestigingsmail ("Mail (2)") waren het er zelfs twee, want die tweede mail
  ging buiten de omleiding om naar het testadres. Voortaan krijg je alleen nog
  het overzicht.
* De controle zelf verandert niet: elk formulier wordt nog steeds volledig
  ingediend en gevalideerd, en de overzichtsmail gaat zelf via `wp_mail` — blijft
  die uit, dan weet je dat de site niet kan mailen.
* Wil je die extra SMTP-test toch, zet dan de filter
  `db_cf7_healthcheck/smtp_test` op true; dan mailt er weer één willekeurig
  formulier per run.
* **De overzichtsmail is opgemaakt.** In plaats van een rij logregels krijg je
  een gekleurde kop (groen of rood), een telling, en daaronder de formulieren
  gegroepeerd op uitslag: problemen eerst, dan wat in orde is, dan wat is
  overgeslagen. Elke naam linkt naar dat formulier in de beheeromgeving. De
  platte tekst gaat als alternatief mee voor wie geen HTML krijgt, en het
  onderwerp begint nog steeds met `[CF7 healthcheck]`, dus bestaande mailregels
  blijven werken.
* **Fix: twee soorten storingen telden niet mee als probleem.** Een formulier
  dat niet geladen kon worden, en een formulier dat onbereikbaar was (timeout,
  SSL-fout), kwamen wel in de mail te staan maar niet in de telling — het
  onderwerp meldde dan "Alles OK". Beide gelden nu als FAILED.

## 1.9.2 - 27 Oct 2026
* **Fix: de updatecontrole van 1.9.1 wees elke release af.** De controle op het
  download-adres vergeleek hoofdlettergevoelig, terwijl GitHub eigenaar en repo
  kleingeschreven teruggeeft. Daardoor vond de plugin nooit een geldige zip en
  bood hij geen enkele update meer aan. Er wordt nu hoofdletterongevoelig
  vergeleken; het weigeren van vreemde domeinen werkt onveranderd.
* De release 1.9.1 is om die reden ingetrokken. Zit je er toch op, werk dan één
  keer handmatig bij via Plugins → Nieuwe plugin → Bestand uploaden.

## 1.9.1 - 27 Oct 2026
* **Fix: formulieren zonder e-mailveld gingen mis bij de dagelijkse check.** De
  healthcheck herkende zijn eigen inzending aan het testadres in de velden, maar
  dat adres wordt alleen in een `email`-veld ingevuld. Een formulier zonder zo'n
  veld werd daardoor als gewone bezoeker behandeld: de mail ging naar de klant in
  plaats van naar het monitoring-adres, de inzending kwam in Flamingo terecht en
  Akismet mocht erover oordelen — met een valse "FAILED ... status = spam" als
  meest zichtbare gevolg.
* Elke testinzending draagt nu een geheim mee in de header `X-DB-Healthcheck`,
  dat alleen geldig is tijdens een lopende run. Dat werkt ongeacht welke velden
  een formulier heeft. Het testadres blijft als terugval voor het geval een proxy
  of security-plugin de header wegpoetst.
* Omdat het geheim maar een paar minuten geldig is, wordt een bezoeker die dat
  monitoring-adres toevallig invult niet langer als healthcheck gezien; zijn
  bericht komt gewoon bij de klant aan.
* **Nieuw: knop "Nu testen"** onderaan Instellingen → DB ACF Extensie. Draait de
  controle meteen en toont de uitslag per formulier op het scherm; je hoeft dus
  geen URL meer in te typen. Er gaat bij zo'n handmatige run geen overzichtsmail
  uit. De knop is afgeschermd met een nonce en vereist beheerdersrechten.
* De updater accepteert alleen nog een download-adres dat bij de releases van de
  eigen repo hoort. WordPress installeert zo'n zip zonder handtekeningcontrole,
  dus een onverwacht antwoord moet niet ergens anders vandaan kunnen installeren.
* Alle PHP-bestanden weren nu directe aanroep (`ABSPATH`-check), en de twee
  ongebruikte bestanden `compat.php` en `classes/requirements.php` zijn
  weggehaald; die verwezen naar constanten die niet bestaan en zouden op PHP 8
  een fatal error geven als iemand ze ooit inschakelde.

## 1.9.0 - 26 Oct 2026
* **Nieuw: je kiest per site wat de plugin doet.** Onder **Instellingen → DB ACF
  Extensie** staan drie schakelaars:
  * *Aangepaste layout-interface* — layouts in een modal met potlood-knop,
    zichtbaarheidsknop en sluiten met Esc, plus de grote kaarten met
    voorbeeldafbeelding bij "Layout toevoegen". Uit = de standaardweergave van ACF.
  * *Vetgedrukt op tekstvelden* — de B-knop op velden waar dat per veld aan staat.
  * *Dagelijkse formulier-check* — de nachtelijke Contact Form 7-controle.
* **Alles staat standaard aan**, dus een site die alleen de update binnenhaalt
  merkt niets. Een onderdeel dat uit staat laadt ook niets meer: de bijbehorende
  CSS en JavaScript blijven weg.
* Zet je de formulier-check uit, dan wordt de geplande taak meteen opgeruimd.
* **Fix: de formulier-check liep na de klokwissel een uur uit de pas.** Een
  dagelijkse WP-cron telt 86400 seconden op, dus na het ingaan van de winter- of
  zomertijd draaide hij om 05:00 of 07:00. Hij zet zichzelf nu terug op 06:00,
  bij de eerste pageload na de wissel.
* In code kan het ook, bijvoorbeeld vanuit een mu-plugin, met de filter
  `db_acf_ui/module_enabled`. Dat wint van het vinkje; de instellingenpagina
  meldt het als een onderdeel via code wordt gestuurd.

## 1.8.0 - 6 Oct 2026
* **Nieuw: de dagelijkse CF7-healthcheck zit nu in de plugin.** Elke nacht om
  06:00 (WP-tijdzone) wordt elk gepubliceerd Contact Form 7-formulier ingediend,
  zodat je het merkt als er iets stuk is vóórdat een bezoeker het ontdekt. Eén
  willekeurig formulier mag daarbij écht mailen; dat test meteen de
  SMTP-instellingen zonder dat de inbox volloopt. De uitslag komt als
  overzichtsmail binnen. Formulieren met een quiz, reCAPTCHA of een onbekend
  file-type worden overgeslagen en in de mail benoemd als "handmatige check
  nodig". Handmatig draaien kan nog steeds via `/?db_cf7_test=1` of
  `/wp-admin/admin-post.php?action=db_cf7_test`.
* Deze code stond in `functions-root.php` van het thema en draaide dus alleen op
  sites met dat thema. **Staat de oude versie daar nog, dan houdt de plugin zich
  stil** en blijft het thema de healthcheck doen — zo lopen er nooit twee naast
  elkaar en krijg je geen dubbele mails. Haal je de code uit het thema, dan neemt
  de plugin het bij de eerstvolgende pageload over, inclusief de al geplande taak.
* Per site aan te passen met filters: `db_cf7_healthcheck/enabled` (helemaal uit),
  `/recipient` (ontvanger), `/test_email` (het adres waaraan een testinzending te
  herkennen is), `/notify_on_success` (op false: alleen mailen bij problemen) en
  `/staging_hosts` (domeinen die nooit mailen, standaard `.ontwikkelbazen.nl`).
* Bij het deactiveren van de plugin wordt de geplande taak opgeruimd.

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
