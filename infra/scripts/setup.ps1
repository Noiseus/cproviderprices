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
docker compose build
docker compose run --rm --no-deps api php artisan key:generate --force

Write-Output "Setup complete. Run 'make up' to start the local stack."
