---
name: docker-compose-php
description: Conventions for the docker-compose setup of service and platform-mock. Use when editing Dockerfiles, compose file, entrypoints, or debugging startup on a clean machine.
---

# docker-compose conventions

- `service`: `php:8.3-cli-alpine` + composer, run with `php -S 0.0.0.0:8080 -t public public/index.php`. No nginx/fpm for the demo — fewer moving parts.
- `platform-mock`: same base image, same `php -S`.
- `db`: `postgres:16-alpine`.
- `depends_on` with `condition: service_healthy`; healthchecks on db and both apps.
- `service` entrypoint:
  1. wait for db (retry loop, no `sleep 5` guess),
  2. run migrations from `service/migrations/*.sql` in lexical order,
  3. start `php -S`.
- No manual steps allowed: `docker compose up` must bring everything to a working state.
- `.env` for local secrets; `.env.example` committed; `.env` in `.gitignore`.
- Do not bake secrets into images.
- Ports: service `8080:8080`, mock `8081:8080`, db `5432` (host only if needed).
- `restart: unless-stopped`.