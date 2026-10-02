#!/bin/bash
# Indlæser eksempeldata fra sql/seed.sql.
set -eo pipefail
mariadb --default-character-set=utf8mb4 -uroot -p"${MARIADB_ROOT_PASSWORD}" "${MARIADB_DATABASE}" < /sql/seed.sql
