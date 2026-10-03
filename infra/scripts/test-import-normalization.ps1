# Runs only synthetic tests against the disposable PostgreSQL test database.
# API/PostgreSQL/Redis/MinIO must already be running. No .env or secret is written.
$ErrorActionPreference = 'Stop'
$previousToken = $env:INGESTION_SERVICE_TOKEN
$integrationContainer = $null
$integrationName = 'cproviderprices-phase-d-' + [guid]::NewGuid().ToString('N')
$tokenBytes = New-Object byte[] 32
$random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$random.GetBytes($tokenBytes)
$random.Dispose()
$env:INGESTION_SERVICE_TOKEN = [Convert]::ToBase64String($tokenBytes)
try {
    $integrationContainer = docker compose run --rm -d --no-deps --name $integrationName -e INGESTION_SERVICE_TOKEN ingestion
    if ($LASTEXITCODE -ne 0 -or $integrationContainer -notmatch '^[a-f0-9]{64}$') {
        throw 'Could not start isolated ingestion service.'
    }
    $ready = $false
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        $ErrorActionPreference = 'Continue'
        docker compose exec -T api curl -fsS -o /dev/null "http://${integrationName}:8001/health" 2>$null
        $healthExit = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($healthExit -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Isolated ingestion service did not become healthy.' }
    docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test -e RUN_IMPORT_INFRASTRUCTURE=1 -e INGESTION_SERVICE_TOKEN -e "INGESTION_SERVICE_URL=http://${integrationName}:8001" api php artisan test --compact --filter=ImportInfrastructureTest
    if ($LASTEXITCODE -ne 0) { throw 'Import infrastructure validation failed.' }
} finally {
    if ($integrationContainer -match '^[a-f0-9]{64}$') {
        docker stop $integrationContainer | Out-Null
    }
    $env:INGESTION_SERVICE_TOKEN = $previousToken
    [Array]::Clear($tokenBytes, 0, $tokenBytes.Length)
}
