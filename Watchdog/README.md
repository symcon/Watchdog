# Watchdog
Überprüft ob in einer Liste definierte Variablen überfällig sind.
Sind Variablen überfällig, wird ein Alarm gesetzt und eine Liste dieser im WebFront angezeigt.


### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Überwachen von gelisteten Variablen.
* Einstellbarkeit ob die Variablen auf Änderung oder Aktuallisierung geprüft werden sollen.
* Einstellbarkeit wie lange die gelisteten Variablen überfällig sein dürfen.
* Ein-/Ausschaltbarkeit via WebFront-Button oder Skript-Funktion.
* Anzeige wann die gelisteten Variablen zuletzt überprüft wurden.
* Darstellung des Originalpfades oder eines inndividuellen Namens.

### 2. Voraussetzungen

- IP-Symcon ab Version 5.1

### 3. Software-Installation

* Über den Module Store das Modul Watchdog installieren.
* Alternativ über das Module Control folgende URL hinzufügen:
`https://github.com/symcon/Watchdog`

### 4. Einrichten der Instanzen in IP-Symcon

- Unter "Instanz hinzufügen" ist das 'Watchdog'-Modul unter dem Hersteller '(Gerät)' aufgeführt.  
- Alle zu schaltenden Variablen müssen der Liste "Variablen" in der Instanzkonfiguration hinzugefügt werden.

__Konfigurationsseite__:

Name       | Beschreibung
---------- | ---------------------------------
Variablen  | Dieser Liste werden die zu beobachtenden Variablen hinzugefügt.
Zeit       | Dauer der Inaktivität bis die gelisteten Variablen den Alarm auslösen. 
Einheit    | Einheit der Zeit.

Sollten Variablen unterschiedlich lange erlaubt Inaktivität haben, ist es zu empfehlen mehrere Instanzen des Watchdog-Moduls zu verwenden.

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

Name               | Typ       | Beschreibung
------------------ | --------- | ----------------
Aktive Alarme      | String    | Beinhaltet die Tabelle für die Darstellung im WebFront.
Alarm              | Boolean   | Die Variable zeigt an ob ein Alarm vorhanden ist. True = Alarm; False = OK;
Letzte Überprüfung | Integer   | UnixTimestamp der den Zeitpunkt angibt zu dem zuletzt überprüft wurde.
Watchdog aktiv     | Boolean   | Zeigt an ob der Watchdog aktiviert ist oder nicht. True = Aktiviert; False = Deaktiviert;

Es werden keine zusätzlichen Profile benötigt.

### 6. WebFront

Über das WebFront kann der Watchdog de-/aktiviert werden.  
Es wird zusätzlich die Information angezeigt, zu welchem Zeitpunkt zuletzt überprüft wurde.  

### 7. PHP-Befehlsreferenz

`boolean WD_SetActive(integer $InstanzID, boolean $SetActive);`  
$SetActive aktiviert (true) oder deaktiviert (false) den Watchdog mit der InstanzID $InstanzID.  
Die Funktion liefert keinerlei Rückgabewert.  

Beispiel:  
`WD_SetActive(12345, true);`

`array WD_GetAlertTargets(integer $InstanzID);`  
Die Funktion liefert ein Array mit den aktiven Alarmen der Watchdoginstanz mit der InstanzID $InstanzID.  
Die Funktion liefert ein Array mit überfälligen Objekten. Es beinhaltet den optionalen Namen, VariablenID und den letzten Zeitpunkt (UnixTimestamp) des Updates oder der Änderung.

Beispiel:  
`WD_GetAlertTargets(12345);`
