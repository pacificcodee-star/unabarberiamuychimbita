#!/bin/sh
set -e

cd /evolution

rm -rf ./prisma/migrations
cp -r ./prisma/postgresql-migrations ./prisma/migrations
./node_modules/.bin/prisma migrate deploy --schema ./prisma/postgresql-schema.prisma

exec node dist/main
