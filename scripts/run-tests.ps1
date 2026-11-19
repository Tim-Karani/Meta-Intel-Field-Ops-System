# Runs the backend tests and saves the result to test-results.txt (send that file if anything fails).
# Run from the project folder:  powershell -ExecutionPolicy Bypass -File .\scripts\run-tests.ps1
# The backend folder is mounted into the container because the app image deliberately contains no tests.
# DB_DATABASE is forced to field_ops_test so the tests can never touch the real database (the tests also refuse to run otherwise).
# It also rebuilds the test database from scratch first (migrate:fresh) so a stale test database cannot cause false failures.
# The first run downloads the test tools into backend\vendor and creates backend\composer.lock (commit that file).
docker compose exec db psql -U field_ops -d postgres -c "CREATE DATABASE field_ops_test" 2>$null
docker compose run --rm --no-deps -u root -e APP_ENV=testing -e DB_DATABASE=field_ops_test -v "${PWD}/backend:/var/www/backend" app sh -c "composer install --no-interaction --no-progress && php artisan migrate:fresh --force --no-interaction && php artisan test" 2>&1 | Tee-Object -FilePath test-results.txt
Write-Host "`nSaved to test-results.txt"
