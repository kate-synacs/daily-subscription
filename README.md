# Daily Pass

## Run

```bash
docker compose up --build
```

Service: `http://localhost:8080`, platform-mock: `http://localhost:8081`.

## Test execution

`docker compose exec service ./vendor/bin/phpunit` runs inside the `service`
container. HTTP tests hit the already-running server at `http://service:8080`
(the Compose service DNS name) rather than starting a second PHP process; mock
control/log calls use `http://platform-mock:8080`. The PHPUnit process itself
opens a direct PDO connection to `db` only to reset per-test subscriber rows.

Full scenario documentation (`curl` recipes for every flow) is added in the
final documentation iteration.
