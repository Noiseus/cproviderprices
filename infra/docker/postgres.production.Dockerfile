FROM postgres:17.6-alpine

COPY infra/docker/postgres-init/10-create-application-role.sh /docker-entrypoint-initdb.d/10-create-application-role.sh

RUN chmod 0555 /docker-entrypoint-initdb.d/10-create-application-role.sh
