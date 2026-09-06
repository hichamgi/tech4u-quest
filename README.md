# Tech4U-QUEST

Application web éducative gamifiée pour l'évaluation des connaissances.

## Stack
- PHP 8.1+
- Apache
- SQLite / PDO
- Bootstrap prévu pour l'interface

## Sécurité Git
Les vraies bases SQLite, archives, logs, uploads et fichiers `.env` sont exclus par `.gitignore`.

Ne jamais committer de vraies données d'élèves.

## Initialisation de la base
```bash
sqlite3 database/current.sqlite < database/schema.sql
```

`current.sqlite` est volontairement absent du dépôt et sera ignoré par Git.
