# Testbranch Verbundindex

Das ist ein Testbranch des SISIS ILS Drivers mit dem Verbundindex.
Er befindet sich in einem frühen Entwicklungszustand.
Basiert auf VuFind 10.1.

## Umfang

Im großen besteht er aus:
* dem SISIS Modul, hauptsächlich bestehend aus dem SISIS ILS Driver
* einem local_theme mit minimal notwendigen Anpassungen (benötig bvb_theme)

## Benötigt
* eine /usr/local/vufind/local/config/vufind/SISISNCIP.ini config Datei bestehend aus folgenden Sektionen
    * [Catalog] mit den folgenden keys
        * url = "ncip-url"
        * katkey = "katkey_aus_dem_SOLR"
        * passwordReset = "siehe_NCIP2SLNP-modul_release_notes"
    * [Authentication]
        * minimum_password_length = int (4)
        * maximum_password_length = int (12)
    * [LocationCode]
        * Name_der_Zweigstelle = "Zweigstellennummer"
        * bspw: Zentralbibliothek = "00"
    * [RequestType]
        * mapping von NCIP Statuscode-Strings (welche Bestellungen oder Vormerkungen erlauben) auf "hold" oder "recall"
        * bspw: LSEntliehen = "recall"
    * [RequestCode]
        * mapping von NCIP Statuscode-Strings auf True oder False, abhängig davon ob sie eine Bestellung/Vormerkung erlauben
        * LSEntliehen = True
    * [IsAvailable]
        * mapping von NCIP Statuscode-Strings auf 0,1,2, abhängig davon ob der Status nicht verfügbar, verfügbar oder unsicher bedeutet
        * LSEntliehen = 0
    * [StatusString]
        * mapping von NCIP Statuscode-Strings auf einen String zur Anzeige in VuFind
        * LSEntliehen = "entliehen"
* folgende Anpassungen in /usr/local/vufind/local/config/vufind/config.ini 
    * [Catalog] driver = "SISISNCIP"
* Das SISIS Modul muss in /usr/local/vufind/config/application.config.php hinzugefügt werden
* Folgende Dateien aus dem Verbund Modul
    * View/Helper/Root/BvbILLHelper.php
    * View/Helper/Root/BvbIncludeHelper.php