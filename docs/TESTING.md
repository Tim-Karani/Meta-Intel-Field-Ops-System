# Running the tests safely

The tests wipe and rebuild their database every run. **They must never run against your real database.**
Since 1.7.2 the test suite refuses to start unless the database name ends in `_test`, and `phpunit.xml` forces
`DB_DATABASE=field_ops_test`, the array cache and the sync queue.

One-time: create the test database next to the real one.

    docker compose exec db psql -U field_ops -d postgres -c "CREATE DATABASE field_ops_test"

Each run (nothing is written to your project folder; the container is removed afterwards):

    docker compose run --rm --no-deps app sh -c "composer install --no-interaction && php artisan test"

The running app and its data are not touched.
