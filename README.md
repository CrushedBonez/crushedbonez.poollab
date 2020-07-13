# Modul für Water-I.D. Poollab 1.0
Dieses Modul für IP-Symcon ab Version 5.3 ermöglicht das Einlesen der Messergebnisse über die Labcom-Cloud. Hierfür wird die GraphiQL API über einen API-Key abgefragt.

Für jeden Labcom-Cloud Account wird eine Instanz vom Typ PoolLab benötigt, unterhalb dieser Instanz werden pro erkanntem Account (Pool) Instanzen vom Typ PoolLabAccount erstellt. Unterhalb dieser Instanzen werden die einzelnen Messwerte als Float-Variablen angelegt und über den Archive-Control zeitlich korrekt eingetragen.