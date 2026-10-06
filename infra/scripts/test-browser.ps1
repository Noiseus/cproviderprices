param([string]$Spec = '')
$ErrorActionPreference = 'Stop'
$previousToken = $env:E2E_INGESTION_TOKEN
$env:E2E_INGESTION_TOKEN = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
$compose = @('compose', '-p', 'cproviderprices-e2e', '-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml')
try {
    & docker @compose up -d --wait
    if ($LASTEXITCODE -ne 0) { throw 'Isolated stack failed to start.' }
    & docker @compose exec -T api php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Isolated migration failed.' }
    & docker @compose exec -T api php artisan app:prepare-auth-e2e
    if ($LASTEXITCODE -ne 0) { throw 'Isolated fixture preparation failed.' }
    & docker @compose exec -T api php artisan app:verify-pilot-e2e
    if ($LASTEXITCODE -ne 0) { throw 'Pilot fixture verification failed.' }
    $testArgs = @('exec', '-T', 'web', 'npm', 'run', 'smoke', '--')
    if ($Spec) { $testArgs += $Spec }
    & docker @compose @testArgs
    if ($LASTEXITCODE -ne 0) { throw 'Browser validation failed.' }
    if (-not $Spec -or $Spec -eq 'imports.spec.ts') {
        & docker @compose exec -T api php artisan app:verify-import-e2e
        if ($LASTEXITCODE -ne 0) { throw 'Real import persistence verification failed.' }
    }
} finally {
    # Stop only the named isolated project. Preserve volumes for diagnostics; never down -v.
    & docker @compose stop
    $env:E2E_INGESTION_TOKEN = $previousToken
}
