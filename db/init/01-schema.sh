#!/bin/bash
# Opretter tabellerne fra sql/schema.sql i databasen fra DB_NAME.
# CREATE DATABASE/USE fjernes, så DB_NAME i .env bestemmer databasenavnet.
set -eo pipefail
sed -e '/^CREATE DATABASE/d' -e '/^USE /d' /sql/schema.sql \
  | mariadb --default-character-set=utf8mb4 -uroot -p"${MARIADB_ROOT_PASSWORD}" "${MARIADB_DATABASE}"
