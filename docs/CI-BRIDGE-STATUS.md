# Arcanorum / Walker CI: Bridge-Pruefung

Status: Vorbereitung, keine Freigabe.

- Hauptprojekt: Gravarium/Arcanorum (privat).
- Walker: Gravarium/Arcanorum-worker (oeffentlich).
- Walker hat eine eigene, auf Walker-Code ausgerichtete CI in `.github/workflows/quality.yml`.
- Der bestehende Walker-Workflow validiert nicht den exakten privaten Arcanorum-Commit.
- Ein gemeinsames Projekt bedeutet nicht automatisch einen sicheren Checkout des privaten Quellcodes in oeffentlichen Jobs.
- Die GitHub-Actions-Minuten fuer das private Hauptprojekt duerfen nicht durch ein blosses anderes `uses:` oder einen anderen Workflow-Dateipfad als ersetzt gelten.
- Private Quelldaten, Quellcode, Testberichte, Tokens und Datenbankdumps bleiben ausserhalb der oeffentlichen Git-Historie sowie oeffentlicher Joblogs.
- Die verpflichtenden Pruefungen fuer Arcanorum bleiben aktiv und duerfen nicht als bestanden markiert werden, solange fuer den exakten Commit kein vollstaendiger Pruefnachweis vorliegt.

Naechster technischer Schritt: eine isolierte und berechtigte Testausfuehrung fuer den privaten SHA entwickeln, deren Risiko, Kosten und Regeln geprueft wurden. Keine Freigabe fuer einen automatischen privaten Checkout auf einem oeffentlichen Worker.
