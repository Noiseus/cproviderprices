$ErrorActionPreference = "Stop"

function Copy-ExampleIfMissing {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    if (-not (Test-Path -LiteralPath $Destination)) {
        Copy-Item -LiteralPath $Source -Destination $Destination
    }
}

Copy-ExampleIfMissing -Source ".env.example" -Destination ".env"
Copy-ExampleIfMissing -Source "apps/web/.env.example" -Destination "apps/web/.env"
Copy-ExampleIfMissing -Source "apps/api/.env.example" -Destination "apps/api/.env"
Copy-ExampleIfMissing -Source "services/ingestion/.env.example" -Destination "services/ingestion/.env"

docker compose config --quiet
if ($LASTEXITCODE -ne 0) {
    throw "Docker Compose configuration validation failed with exit code $LASTEXITCODE."
}

docker compose build
if ($LASTEXITCODE -ne 0) {
    throw "Docker Compose build failed with exit code $LASTEXITCODE."
}

$appKeyConfigured = Select-String -LiteralPath "apps/api/.env" -Pattern '^APP_KEY=.+$' -Quiet
if ($appKeyConfigured) {
    Write-Output "Application key already configured."
} else {
    docker compose run --rm --no-deps api php artisan key:generate --force
    if ($LASTEXITCODE -ne 0) {
        throw "Laravel application key generation failed with exit code $LASTEXITCODE."
    }
}

Write-Output "Setup complete. Run 'make up' to start the local stack."
