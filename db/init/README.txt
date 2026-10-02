Filer i denne mappe (.sql, .sql.gz og .sh) køres i alfabetisk rækkefølge, første gang
databasen oprettes (dvs. når named volume "db_data" er tom):

  01-schema.sh   tabeller fra sql/schema.sql
  02-seed.sh     eksempeldata fra sql/seed.sql

Tilføj fx 03-mine-data.sql for egne testdata. Kør "docker compose down -v" for at
slette databasen, så scripts køres igen ved næste "docker compose up".
